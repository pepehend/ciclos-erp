<?php
/**
 * Acesso à API por token (aplicativo Android e outras integrações).
 *
 * O aplicativo entra por POST token_login (e-mail, senha e nome do aparelho) e recebe um token
 * "cer_…" que vai em todo pedido no header "Authorization: Bearer <token>" (ou "X-Api-Token").
 * Com token não há cookie de sessão nem CSRF. O banco guarda só o SHA-256 do token.
 * O token vale enquanto for usado: expira depois de IDLE_DAYS dias sem uso, ao sair (logout),
 * ao trocar a senha, ao ser revogado em Minha conta → Aparelhos ou quando o usuário é desativado.
 */

class ApiTokens
{
    const PREFIX = 'cer_';
    const IDLE_DAYS = 60;
    /** Aparelhos por usuário: acima disso, o token usado há mais tempo é apagado. */
    const MAX_PER_USER = 10;
    /** Só grava o último uso se o anterior tiver mais que isso (evita um UPDATE por pedido). */
    const TOUCH_SECONDS = 300;

    /** Token lido do pedido (sem conferir no banco), ou null se o pedido não usa token. */
    public static function fromRequest(array $server): ?string
    {
        $h = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h !== '' && preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) return $m[1];
        $x = trim((string)($server['HTTP_X_API_TOKEN'] ?? ''));
        return $x !== '' ? $x : null;
    }

    public static function hash(string $token): string { return hash('sha256', $token); }

    /** Cria um token para o usuário. Devolve o token em texto (só aparece aqui, uma vez). */
    public static function issue(int $userId, string $device, string $ip = ''): string
    {
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $device = trim((string)preg_replace('/\s+/', ' ', $device)) ?: 'Aplicativo';
        DB::insert('api_tokens', [
            'user_id' => $userId,
            'token_hash' => self::hash($token),
            'device' => mb_substr($device, 0, 120),
            'ip' => mb_substr($ip, 0, 64) ?: null,
            'created_at' => now(),
            'last_used_at' => now(),
        ]);
        $ids = array_column(DB::all('SELECT id FROM api_tokens WHERE user_id = ? ORDER BY last_used_at DESC, id DESC', [$userId]), 'id');
        foreach (array_slice($ids, self::MAX_PER_USER) as $old) DB::run('DELETE FROM api_tokens WHERE id = ?', [(int)$old]);
        return $token;
    }

    /**
     * Confere o token. Devolve ['id' => id do token, 'user_id' => …] ou null (inexistente, expirado
     * ou de usuário desativado). Token expirado é apagado.
     */
    public static function resolve(string $token, string $now = ''): ?array
    {
        $now = $now ?: now();
        if (!str_starts_with($token, self::PREFIX) || strlen($token) > 200) return null;
        $row = DB::one('SELECT t.id, t.user_id, t.last_used_at, u.active FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?', [self::hash($token)]);
        if (!$row) return null;
        $last = strtotime((string)$row['last_used_at']);
        if ($last < strtotime($now) - self::IDLE_DAYS * 86400) {
            DB::run('DELETE FROM api_tokens WHERE id = ?', [(int)$row['id']]);
            return null;
        }
        if (!(int)$row['active']) return null;
        if (strtotime($now) - $last > self::TOUCH_SECONDS) {
            DB::run('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [$now, (int)$row['id']]);
        }
        return ['id' => (int)$row['id'], 'user_id' => (int)$row['user_id']];
    }

    /** Aparelhos conectados do usuário ($currentId = token do pedido atual, marcado como "este aparelho"). */
    public static function listFor(int $userId, ?int $currentId = null): array
    {
        $rows = DB::all('SELECT id, device, ip, created_at, last_used_at FROM api_tokens WHERE user_id = ? ORDER BY last_used_at DESC, id DESC', [$userId]);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['current'] = $currentId !== null && $r['id'] === $currentId; }
        return $rows;
    }

    /** Revoga um token do próprio usuário. */
    public static function revoke(int $id, int $userId): void
    {
        if (!DB::run('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$id, $userId])) {
            throw new AppError('Aparelho não encontrado', 404);
        }
    }

    public static function revokeById(int $id): void { DB::run('DELETE FROM api_tokens WHERE id = ?', [$id]); }

    /** Revoga todos os tokens do usuário, menos o $exceptId (ex.: o aparelho que trocou a senha). */
    public static function revokeAll(int $userId, ?int $exceptId = null): int
    {
        if ($exceptId) return DB::run('DELETE FROM api_tokens WHERE user_id = ? AND id <> ?', [$userId, $exceptId]);
        return DB::run('DELETE FROM api_tokens WHERE user_id = ?', [$userId]);
    }
}