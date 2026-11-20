<?php
/**
 * Login, sessão, proteção CSRF e permissões.
 */

class Auth
{
    /** Áreas que podem ser liberadas ou bloqueadas para quem não é administrador. */
    const PERMS = [
        'vendas' => 'Vendas (nova venda, lista, cancelar)',
        'orcamentos' => 'Orçamentos',
        'notas' => 'Notas fiscais (importar)',
        'produtos' => 'Cadastro de produtos',
        'servicos' => 'Cadastro de serviços',
        'clientes' => 'Cadastro de clientes',
        'fornecedores' => 'Cadastro de fornecedores',
        'relatorios' => 'Relatórios de vendas e valores do painel (faturamento, lucro)',
        'movimentacoes' => 'Movimentações de estoque (ver e lançar entradas, saídas, perdas, contagem)',
        'compras' => 'Ordens de compra (pedidos aos fornecedores)',
        'ordens' => 'Ordens de serviço (cadastro de equipamentos e checklists, atendimento e agenda)',
        'financeiro' => 'Financeiro (pagamentos, recebimentos e fluxo de caixa)',
        'formas_pagamento' => 'Formas de pagamento (cadastrar e editar)',
    ];

    const MAX_ATTEMPTS = 8;      // tentativas erradas por IP...
    const WINDOW_MIN = 15;       // ...a cada 15 minutos

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('estoque_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string)(60 * 60 * 12));
        session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        // Expira após 12h sem uso
        if (!empty($_SESSION['uid']) && time() - ($_SESSION['seen'] ?? 0) > 60 * 60 * 12) {
            self::logout();
            session_start();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $_SESSION['seen'] = time();
    }

    public static function csrf(): string { return $_SESSION['csrf'] ?? ''; }

    public static function checkCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$sent || !hash_equals(self::csrf(), $sent)) {
            throw new AppError('Sessão expirada. Recarregue a página.', 419);
        }
    }

    public static function user(): ?array
    {
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) return null;
        static $cache = null;
        if ($cache && $cache['id'] == $uid) return $cache;
        $u = DB::one('SELECT u.id, u.name, u.email, u.phone, u.accent_color, u.role, u.active, u.permissions, u.profile_id, p.name profile_name, p.permissions profile_permissions
            FROM users u LEFT JOIN permission_profiles p ON p.id = u.profile_id WHERE u.id = ?', [$uid]);
        if (!$u || !$u['active']) { self::logout(); return null; }
        $u['id'] = (int)$u['id'];
        $u['permissions'] = self::permsOf($u);
        return $cache = $u;
    }

    /** Lista de permissões do usuário (administrador e usuários antigos sem configuração têm todas). */
    public static function permsOf(array $u): array
    {
        if (($u['role'] ?? '') === 'admin') return array_keys(self::PERMS);
        // Com perfil: vale o perfil. Sem perfil: lista própria do usuário (vazia = acesso total, para não travar quem já usava)
        $raw = !empty($u['profile_id']) ? ($u['profile_permissions'] ?? '') : ($u['permissions'] ?? null);
        if ($raw === null) return array_keys(self::PERMS);
        return array_values(array_intersect(array_keys(self::PERMS), explode(',', (string)$raw)));
    }

    public static function normalizePerms($list): string
    {
        return implode(',', array_values(array_intersect(array_keys(self::PERMS), is_array($list) ? $list : [])));
    }

    // ---------- Perfis de permissão ----------

    public static function profiles(): array
    {
        $rows = DB::all('SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.profile_id = p.id AND u.role <> \'admin\') users
            FROM permission_profiles p ORDER BY p.name');
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['users'] = (int)$r['users'];
            $r['permissions'] = $r['permissions'] === null || $r['permissions'] === '' ? [] : explode(',', $r['permissions']);
        }
        return $rows;
    }

    public static function saveProfile(array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new AppError('Dê um nome ao perfil');
        $name = mb_substr($name, 0, 80);
        $id = (int)($in['id'] ?? 0);
        $dup = DB::val('SELECT id FROM permission_profiles WHERE LOWER(name) = LOWER(?)' . ($id ? ' AND id <> ?' : ''), $id ? [$name, $id] : [$name]);
        if ($dup) throw new AppError('Já existe um perfil com este nome');
        $data = ['name' => $name, 'permissions' => self::normalizePerms($in['permissions'] ?? [])];
        if ($id) {
            if (!DB::val('SELECT id FROM permission_profiles WHERE id = ?', [$id])) throw new AppError('Perfil não encontrado', 404);
            DB::update('permission_profiles', $id, $data);
            return $id;
        }
        return DB::insert('permission_profiles', $data + ['created_at' => now()]);
    }

    public static function deleteProfile(int $id): void
    {
        $n = (int)DB::val("SELECT COUNT(*) FROM users WHERE profile_id = ? AND role <> 'admin'", [$id]);
        if ($n) throw new AppError("Este perfil está em uso por $n usuário(s). Troque o perfil deles antes de excluir.");
        DB::run('UPDATE users SET profile_id = NULL WHERE profile_id = ?', [$id]);
        DB::run('DELETE FROM permission_profiles WHERE id = ?', [$id]);
    }

    /** Perfis de exemplo criados na primeira vez (podem ser editados ou excluídos). */
    public static function seedProfiles(): void
    {
        $base = [
            'Acesso total' => array_keys(self::PERMS),
            'Vendedor' => ['vendas', 'orcamentos', 'clientes', 'servicos'],
            'Estoque' => ['produtos', 'notas', 'movimentacoes', 'fornecedores'],
        ];
        foreach ($base as $name => $perms) {
            if (!DB::val('SELECT id FROM permission_profiles WHERE name = ?', [$name])) {
                DB::insert('permission_profiles', ['name' => $name, 'permissions' => implode(',', $perms), 'created_at' => now()]);
            }
        }
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        return $u && in_array($perm, $u['permissions'], true);
    }

    /** Exige login e pelo menos uma das permissões informadas. */
    public static function requirePerm(string ...$perms): array
    {
        $u = self::require();
        foreach ($perms as $p) if (in_array($p, $u['permissions'], true)) return $u;
        $labels = array_map(fn($p) => self::PERMS[$p] ?? $p, $perms);
        throw new AppError('Você não tem permissão para isso (' . implode(' / ', $labels) . '). Peça a um administrador.', 403);
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) throw new AppError('Faça login para continuar', 401);
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') throw new AppError('Apenas administradores podem fazer isso', 403);
        return $u;
    }

    public static function login(string $email, string $password, string $ip): array
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        DB::run('DELETE FROM login_attempts WHERE created_at < ?', [$since]);
        $n = (int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?', [$ip, $since]);
        if ($n >= self::MAX_ATTEMPTS) {
            throw new AppError('Muitas tentativas. Aguarde alguns minutos e tente de novo.', 429);
        }
        $u = DB::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        if (!$u || !$u['active'] || !password_verify($password, $u['password_hash'])) {
            DB::insert('login_attempts', ['ip' => $ip, 'created_at' => now()]);
            throw new AppError('E-mail ou senha incorretos', 401);
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', (int)$u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return self::user();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
            session_destroy();
        }
    }

    /** Onde o usuário aparece no histórico: com algum registro, ele não é apagado, só desativado. */
    const USER_REFS = [
        ['sales', 'user_id'], ['sales', 'canceled_by'], ['invoices', 'user_id'], ['quotes', 'user_id'], ['quotes', 'created_by'],
        ['financial_entries', 'user_id'], ['purchase_orders', 'user_id'], ['movements', 'user_id'], ['portal_requests', 'resolved_by'],
        ['service_orders', 'user_id'], ['service_orders', 'technician_id'],
    ];
    const ADMIN_LOCKED = 'Administradores só podem ser rebaixados, desativados ou excluídos pelo console do servidor.';

    const ADMIN_OTHER = 'Os dados de outro administrador só podem ser alterados pelo console do servidor.';

    /** Pela tela/API, um administrador só altera membros e a si mesmo; outros administradores, só pelo console. */
    public static function assertCanEdit(array $target, array $me): void
    {
        if ($target['role'] === 'admin' && (int)$target['id'] !== (int)$me['id']) throw new AppError(self::ADMIN_OTHER, 403);
    }

    public static function userUsed(int $id): bool
    {
        foreach (self::USER_REFS as [$t, $c]) {
            if (DB::val("SELECT 1 FROM $t WHERE $c = ? LIMIT 1", [$id])) return true;
        }
        return false;
    }

    /**
     * Exclusão pela tela: apaga de vez quem nunca usou o sistema; quem já tem registros só é desativado.
     * Administradores não podem ser excluídos nem desativados pela tela (só pelo console: app/users-cli.php).
     * Devolve 'deleted' ou 'deactivated'.
     */
    public static function deleteUser(int $id, int $byId, bool $console = false): string
    {
        $u = DB::one('SELECT id, role, active FROM users WHERE id = ?', [$id]);
        if (!$u) throw new AppError('Usuário não encontrado', 404);
        if (!$console) {
            if ((int)$u['id'] === $byId) throw new AppError('Você não pode excluir a si mesmo');
            if ($u['role'] === 'admin') throw new AppError(self::ADMIN_LOCKED, 403);
        } elseif ($u['role'] === 'admin' && $u['active'] && self::activeAdmins() <= 1) {
            throw new AppError('Este é o único administrador ativo: crie ou ative outro antes.');
        }
        if (self::userUsed($id)) {
            DB::update('users', $id, ['active' => 0]);
            return 'deactivated';
        }
        DB::run('DELETE FROM users WHERE id = ?', [$id]);
        return 'deleted';
    }

    public static function activeAdmins(): int
    {
        return (int)DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
    }

    /** E-mail (login) em minúsculas, válido e sem outro usuário com ele ($exceptId = o próprio usuário, na edição). */
    public static function cleanEmail(string $email, int $exceptId = 0): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) throw new AppError('E-mail inválido');
        if (DB::val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId])) throw new AppError('Já existe um usuário com este e-mail');
        return $email;
    }

    public static function createUser(array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $pass = (string)($in['password'] ?? '');
        $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'membro';
        if ($name === '') throw new AppError('Informe o nome');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('E-mail inválido');
        self::checkPassword($pass);
        if (DB::val('SELECT id FROM users WHERE email = ?', [$email])) throw new AppError('Já existe um usuário com este e-mail');
        $perms = isset($in['permissions']) && is_array($in['permissions']) ? self::normalizePerms($in['permissions']) : null;
        $profile = !empty($in['profile_id']) ? (int)$in['profile_id'] : null;
        if ($profile && !DB::val('SELECT id FROM permission_profiles WHERE id = ?', [$profile])) throw new AppError('Perfil de permissão não encontrado');
        return DB::insert('users', [
            'name' => mb_substr($name, 0, 120), 'email' => $email, 'phone' => self::phone($in['phone'] ?? null), 'permissions' => $perms, 'profile_id' => $profile,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'role' => $role, 'active' => 1, 'created_at' => now(),
        ]);
    }

    /** Telefone do usuário: só confere o tamanho (aceita qualquer formato). */
    public static function phone($v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string)$v));
        if ($v === '') return null;
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) < 8 || strlen($digits) > 15) throw new AppError('Telefone inválido');
        return mb_substr(Customers::phoneMask($v), 0, 30);
    }

    public static function checkPassword(string $p): void
    {
        if (mb_strlen($p) < 8) throw new AppError('A senha precisa ter pelo menos 8 caracteres');
    }

    /** Cor de destaque do usuário (#rrggbb); vazio volta à cor padrão do sistema. */
    const AVATAR_MAX_BYTES = 200 * 1024;

    /** Foto de perfil: PNG, JPEG ou WebP em "data:image/...;base64,", até 200 KB. Vazio remove. */
    public static function setAvatar(int $userId, string $data): ?string
    {
        $data = trim($data);
        if ($data !== '') {
            if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) throw new AppError('Envie a foto em PNG, JPG ou WebP');
            $bin = base64_decode($m[2], true);
            if ($bin === false || strlen($bin) > self::AVATAR_MAX_BYTES) throw new AppError('A foto é grande demais (máx. 200 KB)');
            $info = @getimagesizefromstring($bin);
            if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) throw new AppError('Arquivo de imagem inválido');
        }
        DB::update('users', $userId, ['avatar' => $data === '' ? null : $data]);
        return $data === '' ? null : $data;
    }

    /** Usuário logado com a foto (a foto não vai no Auth::user(), que roda em toda requisição). */
    public static function withAvatar(?array $u): ?array
    {
        if ($u) $u['avatar'] = DB::val('SELECT avatar FROM users WHERE id = ?', [$u['id']]);
        return $u;
    }

    public static function setAccent(int $userId, ?string $hex): ?string
    {
        $c = strtolower(trim((string)$hex));
        if ($c !== '' && !preg_match('/^#[0-9a-f]{6}$/', $c)) throw new AppError('Cor inválida: use o formato #RRGGBB');
        $c = $c === '' || $c === '#b02a37' ? null : $c;
        DB::update('users', $userId, ['accent_color' => $c]);
        return $c;
    }
}
