<?php
/**
 * Portal do cliente (pasta cliente/): o cliente entra com CPF/CNPJ e senha e vê os próprios orçamentos,
 * compras, parcelas e dados cadastrais, e pode pedir correção dos dados.
 *
 * O acesso nasce de um link de convite gerado no cadastro do cliente (válido por 7 dias): o cliente abre
 * o link e cria a própria senha. Gerar um link novo também serve para "esqueci a senha".
 * O acesso fica na tabela customer_portal (fora de customers, que é lida inteira em vários lugares).
 * A sessão do portal é separada da sessão do sistema (outro cookie, só na pasta cliente/).
 */

class Portal
{
    const INVITE_DAYS = 7;
    const MIN_PASSWORD = 8;
    const IDLE_HOURS = 2;
    /** Status internos do orçamento: o cliente só vê os que já foram enviados a ele. */
    const HIDDEN_QUOTE_STATUS = ['rascunho', 'em_elaboracao', 'aguardando_envio'];

    // ---------- Lado do sistema (equipe) ----------

    /** Situação do acesso de um cliente, para a tela de cadastro. */
    public static function status(int $customerId): array
    {
        $a = DB::one('SELECT * FROM customer_portal WHERE customer_id = ?', [$customerId]);
        $pending = $a && $a['invite_hash'] && $a['invite_expires'] && strtotime($a['invite_expires']) > time();
        return [
            'active' => (bool)($a && $a['password_hash']),
            'invite_pending' => (bool)$pending,
            'invite_expires' => $pending ? $a['invite_expires'] : null,
            'last_login' => $a['last_login'] ?? null,
            'requests' => DB::all('SELECT r.*, u.name resolved_by_name FROM portal_requests r LEFT JOIN users u ON u.id = r.resolved_by
                WHERE r.customer_id = ? ORDER BY r.resolved_at IS NOT NULL, r.id DESC LIMIT 20', [$customerId]),
        ];
    }

    /** Gera o link de convite (substitui o anterior). Devolve o token puro, que só aparece agora. */
    public static function invite(int $customerId): array
    {
        $c = Customers::get($customerId);
        if (!$c['active']) throw new AppError('Cliente inativo: ative o cadastro antes de liberar o portal.');
        if (!preg_match('/^(\d{11}|\d{14})$/', (string)$c['document'])) {
            throw new AppError('Cadastre o CPF ou CNPJ do cliente: é com ele que o cliente entra no portal.');
        }
        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + self::INVITE_DAYS * 86400);
        $id = DB::val('SELECT id FROM customer_portal WHERE customer_id = ?', [$customerId]);
        $data = ['invite_hash' => hash('sha256', $token), 'invite_expires' => $expires];
        if ($id) DB::update('customer_portal', (int)$id, $data);
        else DB::insert('customer_portal', $data + ['customer_id' => $customerId, 'created_at' => now()]);
        return ['token' => $token, 'expires' => $expires];
    }

    /** Remove o acesso (senha e convite). Os pedidos de correção continuam no histórico. */
    public static function revoke(int $customerId): void
    {
        DB::run('UPDATE customer_portal SET password_hash = NULL, invite_hash = NULL, invite_expires = NULL WHERE customer_id = ?', [$customerId]);
    }

    public static function resolveRequest(int $id, int $userId): void
    {
        if (!DB::val('SELECT id FROM portal_requests WHERE id = ?', [$id])) throw new AppError('Pedido não encontrado', 404);
        DB::run('UPDATE portal_requests SET resolved_at = ?, resolved_by = ? WHERE id = ? AND resolved_at IS NULL', [now(), $userId, $id]);
    }

    /** Pedidos de correção em aberto, por cliente (para a lista de clientes). */
    public static function openRequests(): array
    {
        $out = [];
        foreach (DB::all('SELECT customer_id, COUNT(*) n FROM portal_requests WHERE resolved_at IS NULL GROUP BY customer_id') as $r) {
            $out[(int)$r['customer_id']] = (int)$r['n'];
        }
        return $out;
    }

    // ---------- Lado do cliente ----------

    /** Sessão própria do portal (cookie só na pasta do portal). */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
        session_name('estoque_cliente');
        session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if (!empty($_SESSION['cid']) && time() - ($_SESSION['seen'] ?? 0) > self::IDLE_HOURS * 3600) {
            self::logout();
            session_start();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $_SESSION['seen'] = time();
    }

    public static function csrf(): string { return $_SESSION['csrf'] ?? ''; }

    public static function checkCsrf(string $t): void
    {
        if (!hash_equals(self::csrf(), $t)) throw new AppError('A página expirou. Recarregue e tente de novo.', 419);
    }

    /** Cliente logado (ativo e com acesso), ou null. */
    public static function customer(): ?array
    {
        $cid = (int)($_SESSION['cid'] ?? 0);
        if (!$cid) return null;
        $c = DB::one('SELECT c.* FROM customers c JOIN customer_portal a ON a.customer_id = c.id
            WHERE c.id = ? AND c.active = 1 AND a.password_hash IS NOT NULL', [$cid]);
        if (!$c) { unset($_SESSION['cid']); return null; }
        return $c;
    }

    private static function ipKey(): string
    {
        return 'portal:' . mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 50);
    }

    public static function login(string $document, string $password): array
    {
        $since = date('Y-m-d H:i:s', time() - Auth::WINDOW_MIN * 60);
        $ip = self::ipKey();
        DB::run('DELETE FROM login_attempts WHERE created_at < ?', [$since]);
        if ((int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?', [$ip, $since]) >= Auth::MAX_ATTEMPTS) {
            throw new AppError('Muitas tentativas. Aguarde alguns minutos e tente de novo.', 429);
        }
        $doc = preg_replace('/\D/', '', $document);
        $row = $doc === '' ? null : DB::one('SELECT c.id, c.active, a.password_hash FROM customers c JOIN customer_portal a ON a.customer_id = c.id
            WHERE c.document = ?', [$doc]);
        if (!$row || !$row['active'] || !$row['password_hash'] || !password_verify($password, $row['password_hash'])) {
            DB::insert('login_attempts', ['ip' => $ip, 'created_at' => now()]);
            throw new AppError('CPF/CNPJ ou senha incorretos.', 401);
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            DB::run('UPDATE customer_portal SET password_hash = ? WHERE customer_id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        DB::run('UPDATE customer_portal SET last_login = ? WHERE customer_id = ?', [now(), $row['id']]);
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['cid'] = (int)$row['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return self::customer();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Cliente do convite (válido e não vencido), ou null. */
    public static function inviteCustomer(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
        $c = DB::one('SELECT c.* FROM customer_portal a JOIN customers c ON c.id = a.customer_id
            WHERE a.invite_hash = ? AND a.invite_expires > ? AND c.active = 1', [hash('sha256', $token), now()]);
        return $c ?: null;
    }

    /** Aceita o convite: grava a senha, apaga o convite e já entra. */
    public static function acceptInvite(string $token, string $password, string $confirm): array
    {
        $c = self::inviteCustomer($token);
        if (!$c) throw new AppError('Este link de acesso venceu ou já foi usado. Peça um novo a quem atende você.');
        self::checkPassword($password, $confirm);
        DB::run('UPDATE customer_portal SET password_hash = ?, invite_hash = NULL, invite_expires = NULL WHERE customer_id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $c['id']]);
        return self::login((string)$c['document'], $password);
    }

    public static function changePassword(int $customerId, string $current, string $new, string $confirm): void
    {
        $hash = DB::val('SELECT password_hash FROM customer_portal WHERE customer_id = ?', [$customerId]);
        if (!$hash || !password_verify($current, (string)$hash)) throw new AppError('Senha atual incorreta.');
        self::checkPassword($new, $confirm);
        DB::run('UPDATE customer_portal SET password_hash = ? WHERE customer_id = ?', [password_hash($new, PASSWORD_DEFAULT), $customerId]);
    }

    private static function checkPassword(string $p, string $confirm): void
    {
        if (mb_strlen($p) < self::MIN_PASSWORD) throw new AppError('A senha precisa ter pelo menos ' . self::MIN_PASSWORD . ' caracteres.');
        if ($p !== $confirm) throw new AppError('As duas senhas não são iguais.');
    }

    public static function requestChange(int $customerId, string $message): void
    {
        $message = trim($message);
        if (mb_strlen($message) < 5) throw new AppError('Escreva o que precisa ser corrigido.');
        $open = (int)DB::val('SELECT COUNT(*) FROM portal_requests WHERE customer_id = ? AND resolved_at IS NULL', [$customerId]);
        if ($open >= 5) throw new AppError('Você já tem pedidos em aberto. Aguarde o retorno antes de enviar outro.');
        DB::insert('portal_requests', ['customer_id' => $customerId, 'message' => mb_substr($message, 0, 2000), 'created_at' => now()]);
    }

    // ---------- Dados mostrados ao cliente ----------

    public static function quotes(int $customerId): array
    {
        $hidden = implode(',', array_fill(0, count(self::HIDDEN_QUOTE_STATUS), '?'));
        $rows = DB::all("SELECT id, status, request_date, expires_at, total, public_token, external_code FROM quotes
            WHERE customer_id = ? AND (status NOT IN ($hidden) OR sent_at IS NOT NULL) ORDER BY id DESC LIMIT 200",
            array_merge([$customerId], self::HIDDEN_QUOTE_STATUS));
        $today = date('Y-m-d');
        foreach ($rows as &$q) {
            $expired = !in_array($q['status'], QuoteStatuses::CLOSED, true) && $q['expires_at'] && substr($q['expires_at'], 0, 10) < $today;
            [$q['label'], $q['tone']] = match (true) {
                $q['status'] === 'aprovado' => ['Aprovado', 'ok'],
                $q['status'] === 'vendido' => ['Aprovado (pedido gerado)', 'ok'],
                $q['status'] === 'recusado' => ['Recusado', 'err'],
                $expired => ['Vencido', 'muted'],
                in_array($q['status'], self::HIDDEN_QUOTE_STATUS, true), $q['status'] === 'enviado' => ['Aguardando sua aprovação', 'warn'],
                default => [QuoteStatuses::label($q['status']), 'info'],
            };
        }
        return $rows;
    }

    public static function sales(int $customerId): array
    {
        return DB::all("SELECT s.id, s.created_at, s.total, s.status, s.payment_method, s.installments, s.payment_terms,
            (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.id) items FROM sales s WHERE s.customer_id = ? ORDER BY s.id DESC LIMIT 200", [$customerId]);
    }

    /** Uma compra do cliente (só se for dele). */
    public static function sale(int $customerId, int $saleId): ?array
    {
        $s = DB::one('SELECT * FROM sales WHERE id = ? AND customer_id = ?', [$saleId, $customerId]);
        if (!$s) return null;
        $s['items'] = DB::all('SELECT i.quantity, i.unit_price, i.total, p.name, p.unit FROM sale_items i JOIN products p ON p.id = i.product_id
            WHERE i.sale_id = ? ORDER BY i.id', [$saleId]);
        $s['entries'] = DB::all("SELECT description, amount, due_date, status, paid_at, paid_amount, installment, installments
            FROM financial_entries WHERE sale_id = ? AND type = 'receber' AND status <> 'cancelado' ORDER BY due_date, id", [$saleId]);
        return $s;
    }

    /** Parcelas (contas a receber) do cliente, com a situação calculada. */
    public static function entries(int $customerId): array
    {
        $rows = DB::all("SELECT id, description, amount, due_date, status, paid_at, paid_amount, installment, installments, sale_id
            FROM financial_entries WHERE customer_id = ? AND type = 'receber' AND status <> 'cancelado'
            ORDER BY CASE WHEN status = 'pago' THEN 1 ELSE 0 END, due_date, id LIMIT 500", [$customerId]);
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['situation'] = $r['status'] === 'pago' ? 'pago' : (substr($r['due_date'], 0, 10) < $today ? 'vencido' : 'aberto');
        }
        return $rows;
    }
}
