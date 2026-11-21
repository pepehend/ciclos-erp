<?php
/**
 * Login, sessão, proteção CSRF e permissões.
 */

class Auth
{
    /** Áreas que podem ser liberadas ou bloqueadas para quem não é administrador. */
    /**
     * Permissões por módulo: a chave do módulo é "acessar/visualizar"; cada ação vira "modulo.acao".
     * Ex.: ordens (ver as OS) + ordens.add, ordens.edit, ordens.execute, ordens.hours…
     * Perfis e usuários gravados no formato novo levam "_v2" na lista (ver normalizePerms/upgradePerms).
     */
    const MODULES = [
        'ordens' => ['Ordens de serviço', 'Visualizar ordens de serviço e agenda', [
            'add' => 'Adicionar OS',
            'edit' => 'Editar OS (cliente, equipamento, tipo, técnico, data e orientação)',
            'execute' => 'Executar OS (a caminho, check-in, pausar, check-out e preencher relatório, fotos, questionários, peças e serviços)',
            'reschedule' => 'Reagendar OS (agenda)',
            'hours' => 'Editar controle de horas',
            'after_checkout' => 'Editar OS depois do check-out',
            'send' => 'Enviar OS digital e pesquisa de satisfação',
            'all' => 'Ver as OS de todos os técnicos (sem isso, só as OS em que é o técnico)',
        ]],
        'app' => ['Aplicativo do técnico', null, [
            'switch' => 'Alternar entre o app do técnico e a versão completa (só no celular)',
        ]],
        'equipamentos' => ['Equipamentos', 'Visualizar equipamentos', ['add' => 'Cadastrar equipamento', 'edit' => 'Editar equipamento', 'delete' => 'Excluir equipamento']],
        'tipos_tarefa' => ['Tipos de tarefa', 'Visualizar tipos de tarefa', ['add' => 'Cadastrar tipo de tarefa', 'edit' => 'Editar tipo de tarefa', 'delete' => 'Excluir tipo de tarefa']],
        'questionarios' => ['Questionários', 'Visualizar questionários', ['add' => 'Cadastrar questionário', 'edit' => 'Editar questionário', 'delete' => 'Excluir questionário']],
        'orcamentos' => ['Orçamentos', 'Visualizar orçamentos', [
            'add' => 'Adicionar orçamento (e duplicar)', 'edit' => 'Editar orçamento', 'delete' => 'Excluir orçamento',
            'status' => 'Alterar status (enviar, aprovar, recusar)', 'convert' => 'Gerar venda a partir do orçamento',
        ]],
        'vendas' => ['Vendas', 'Visualizar vendas', ['add' => 'Nova venda', 'cancel' => 'Cancelar venda']],
        'clientes' => ['Clientes', 'Visualizar clientes', ['add' => 'Cadastrar cliente', 'edit' => 'Editar cliente', 'delete' => 'Excluir cliente', 'portal' => 'Liberar e remover acesso ao portal do cliente']],
        'fornecedores' => ['Fornecedores', 'Visualizar fornecedores', ['add' => 'Cadastrar fornecedor', 'edit' => 'Editar fornecedor', 'delete' => 'Excluir fornecedor']],
        'produtos' => ['Produtos (peças)', 'Visualizar produtos', [
            'add' => 'Cadastrar produto (e importar planilha)', 'edit' => 'Editar produto', 'delete' => 'Excluir produto',
            'review' => 'Revisar peças novas lançadas nas OS',
        ]],
        'servicos' => ['Serviços', 'Visualizar serviços', ['add' => 'Cadastrar serviço', 'edit' => 'Editar serviço', 'delete' => 'Excluir serviço', 'review' => 'Revisar serviços novos lançados nas OS']],
        'movimentacoes' => ['Movimentações de estoque', 'Visualizar movimentações', ['add' => 'Lançar entradas, saídas, perdas e contagem']],
        'notas' => ['Notas fiscais', 'Visualizar notas', ['import' => 'Importar nota fiscal', 'revert' => 'Desfazer nota importada']],
        'compras' => ['Ordens de compra', 'Visualizar ordens de compra', ['add' => 'Adicionar ordem de compra (e duplicar)', 'edit' => 'Editar, enviar e mudar status', 'delete' => 'Excluir ordem de compra']],
        'financeiro' => ['Financeiro', 'Visualizar pagamentos, recebimentos e fluxo de caixa', ['add' => 'Lançar conta a pagar ou a receber', 'edit' => 'Editar, dar baixa e reabrir', 'delete' => 'Excluir lançamento']],
        'formas_pagamento' => ['Formas de pagamento', 'Cadastrar e editar formas de pagamento', []],
        'relatorios' => ['Relatórios', 'Relatórios de vendas e valores do painel (faturamento, lucro)', []],
    ];

    /** Módulos que nasceram de "ordens" (v3.1.0): quem tinha ordens antes ganha estes também. */
    const LEGACY_FROM_ORDENS = ['equipamentos', 'tipos_tarefa', 'questionarios', 'app'];

    /** Todas as permissões: código => descrição. */
    public static function perms(): array
    {
        $out = [];
        foreach (self::MODULES as $m => [$label, $view, $actions]) {
            if ($view !== null) $out[$m] = $label . ': ' . $view;
            foreach ($actions as $a => $l) $out["$m.$a"] = $label . ': ' . $l;
        }
        return $out;
    }

    /** Estrutura para a tela de permissões. */
    public static function modules(): array
    {
        $out = [];
        foreach (self::MODULES as $m => [$label, $view, $actions]) {
            $items = [];
            if ($view !== null) $items[] = ['code' => $m, 'label' => $view, 'view' => true];
            foreach ($actions as $a => $l) $items[] = ['code' => "$m.$a", 'label' => $l, 'view' => false];
            $out[] = ['code' => $m, 'label' => $label, 'items' => $items];
        }
        return $out;
    }

    /**
     * Lista antiga (só módulos, sem "_v2") para a nova: cada módulo ganha todas as ações dele,
     * menos "ver as OS de todos os técnicos" (fica só para quem tinha acesso a tudo).
     */
    public static function expandLegacy(array $list): array
    {
        $all = array_keys(self::perms());
        $out = [];
        foreach ($list as $m) {
            if (!isset(self::MODULES[$m])) continue;
            $mods = $m === 'ordens' ? array_merge(['ordens'], self::LEGACY_FROM_ORDENS) : [$m];
            foreach ($mods as $mm) foreach ($all as $code) if ($code === $mm || str_starts_with($code, "$mm.")) $out[$code] = true;
        }
        unset($out['ordens.all']);
        return array_keys($out);
    }

    const MAX_ATTEMPTS = 8;      // tentativas erradas por IP...
    const WINDOW_MIN = 15;       // ...a cada 15 minutos

    /** Token do pedido atual (['id', 'user_id']) quando o acesso é pelo aplicativo; null = sessão do navegador. */
    public static ?array $api = null;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || self::$api) return;
        // Aplicativo: token no header, sem cookie de sessão nem CSRF
        $tok = ApiTokens::fromRequest($_SERVER);
        if ($tok !== null) {
            $t = ApiTokens::resolve($tok);
            if (!$t) throw new AppError('Acesso do aplicativo expirado ou removido. Entre de novo.', 401);
            self::$api = $t;
            $_SESSION = ['uid' => $t['user_id'], 'csrf' => '', 'seen' => time()];
            return;
        }
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
        if (self::$api) return; // token no header não é enviado sozinho pelo navegador: CSRF não se aplica
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
        $u = self::load((int)$uid);
        if (!$u) { self::logout(); return null; }
        return $cache = $u;
    }

    /** Usuário ativo com as permissões calculadas (null se não existe ou está desativado). */
    private static function load(int $uid): ?array
    {
        $u = DB::one('SELECT u.id, u.name, u.email, u.phone, u.accent_color, u.role, u.active, u.permissions, u.profile_id, p.name profile_name, p.permissions profile_permissions
            FROM users u LEFT JOIN permission_profiles p ON p.id = u.profile_id WHERE u.id = ?', [$uid]);
        if (!$u || !$u['active']) return null;
        $u['id'] = (int)$u['id'];
        $u['permissions'] = self::permsOf($u);
        return $u;
    }

    /** Lista de permissões do usuário (administrador e usuários antigos sem configuração têm todas). */
    public static function permsOf(array $u): array
    {
        $all = array_keys(self::perms());
        if (($u['role'] ?? '') === 'admin') return $all;
        // Com perfil: vale o perfil. Sem perfil: lista própria do usuário (vazia = acesso total, para não travar quem já usava)
        $raw = !empty($u['profile_id']) ? ($u['profile_permissions'] ?? '') : ($u['permissions'] ?? null);
        if ($raw === null) return $all;
        $list = explode(',', (string)$raw);
        if (!in_array('_v2', $list, true)) $list = self::expandLegacy($list);
        return array_values(array_intersect($all, $list));
    }

    /** Lista para gravar: só códigos válidos; ação marcada leva junto o "visualizar" do módulo; marca "_v2". */
    public static function normalizePerms($list): string
    {
        $all = self::perms();
        $set = [];
        foreach (is_array($list) ? $list : [] as $c) {
            $c = (string)$c;
            if (!isset($all[$c])) continue;
            $set[$c] = true;
            $m = explode('.', $c)[0];
            if (isset($all[$m])) $set[$m] = true;
        }
        return implode(',', array_merge(['_v2'], array_values(array_intersect(array_keys($all), array_keys($set)))));
    }

    /** v3.1.0: perfis e usuários gravados no formato antigo passam para o novo (com as ações de cada módulo). */
    public static function upgradePerms(): void
    {
        foreach (['permission_profiles', 'users'] as $t) {
            foreach (DB::all("SELECT id, permissions FROM $t WHERE permissions IS NOT NULL") as $r) {
                $list = $r['permissions'] === '' ? [] : explode(',', $r['permissions']);
                if (in_array('_v2', $list, true)) continue;
                $new = self::expandLegacy($list);
                // "Acesso total" continua com tudo (inclusive ver as OS de todos)
                if ($t === 'permission_profiles' && DB::val('SELECT name FROM permission_profiles WHERE id = ?', [$r['id']]) === 'Acesso total') $new = array_keys(self::perms());
                DB::update($t, (int)$r['id'], ['permissions' => self::normalizePerms($new)]);
            }
        }
    }

    // ---------- Perfis de permissão ----------

    public static function profiles(): array
    {
        $rows = DB::all('SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.profile_id = p.id AND u.role <> \'admin\') users
            FROM permission_profiles p ORDER BY p.name');
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['users'] = (int)$r['users'];
            $r['permissions'] = $r['permissions'] === null || $r['permissions'] === '' ? [] : array_values(array_diff(explode(',', $r['permissions']), ['_v2']));
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
            'Acesso total' => array_keys(self::perms()),
            'Vendedor' => self::expandLegacy(['vendas', 'orcamentos', 'clientes', 'servicos']),
            'Estoque' => self::expandLegacy(['produtos', 'notas', 'movimentacoes', 'fornecedores']),
            'Técnico' => ['ordens', 'ordens.execute', 'ordens.send', 'equipamentos', 'equipamentos.add', 'clientes'],
        ];
        foreach ($base as $name => $perms) {
            if (!DB::val('SELECT id FROM permission_profiles WHERE name = ?', [$name])) {
                DB::insert('permission_profiles', ['name' => $name, 'permissions' => self::normalizePerms($perms), 'created_at' => now()]);
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
        $all = self::perms();
        $labels = array_map(fn($p) => $all[$p] ?? $p, $perms);
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
        $u = self::verifyLogin($email, $password, $ip);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return self::user();
    }

    /**
     * Entrada do aplicativo: confere e-mail e senha (com o mesmo limite de tentativas do login)
     * e devolve um token novo para o aparelho. Não abre sessão de navegador.
     */
    public static function tokenLogin(string $email, string $password, string $ip, string $device): array
    {
        $u = self::verifyLogin($email, $password, $ip);
        $token = ApiTokens::issue((int)$u['id'], $device, $ip);
        return ['token' => $token, 'user' => self::withAvatar(self::load((int)$u['id']))];
    }

    /** Confere e-mail e senha, com limite de tentativas por IP. Devolve a linha do usuário. */
    private static function verifyLogin(string $email, string $password, string $ip): array
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
        return $u;
    }

    public static function logout(): void
    {
        if (self::$api) {
            // Aplicativo: sair apaga o token deste aparelho
            ApiTokens::revokeById(self::$api['id']);
            self::$api = null;
            $_SESSION = [];
            return;
        }
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
        ['so_answers', 'user_id'], ['so_photos', 'user_id'], ['so_items', 'user_id'], ['so_events', 'user_id'], ['so_events', 'edited_by'], ['so_events', 'deleted_by'], ['so_sends', 'user_id'],
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
        ApiTokens::revokeAll($id);
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
