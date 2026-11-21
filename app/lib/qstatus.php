<?php
/**
 * Status dos orçamentos (Configurações → Orçamento).
 * Os status do sistema (rascunho, aprovado, vendido…) têm regras próprias: podem ser renomeados,
 * recoloridos e reordenados, mas não excluídos. Os criados pelo usuário são livres
 * (o orçamento guarda o código, então renomear não muda nada nos registros).
 */

class QuoteStatuses
{
    /** code => [nome, cor] na ordem padrão */
    const SYSTEM = [
        'rascunho' => ['Rascunho', '#65a30d'],
        'em_elaboracao' => ['Em elaboração', '#0284c7'],
        'aguardando_envio' => ['Aguardando envio', '#7c3aed'],
        'enviado' => ['Aguardando aprovação', '#d97706'],
        'aprovado' => ['Aprovado', '#15803d'],
        'recusado' => ['Recusado', '#dc2626'],
        'vendido' => ['Vendido', '#475569'],
    ];
    /** Fechados: o cliente já respondeu pelo link ou virou venda. */
    const CLOSED = ['aprovado', 'recusado', 'vendido'];

    private static ?array $cache = null;

    public static function reset(): void { self::$cache = null; }

    public static function rows(): array
    {
        if (self::$cache === null) {
            self::$cache = array_map(function ($r) {
                $r['system'] = isset(self::SYSTEM[$r['code']]);
                $r['used'] = (int)DB::val('SELECT COUNT(*) FROM quotes WHERE company_id = ? AND status = ?', [Tenant::need(), $r['code']]);
                return $r;
            }, DB::all('SELECT * FROM quote_statuses WHERE company_id = ? ORDER BY sort, id', [Tenant::need()]));
        }
        return self::$cache;
    }

    /** code => nome (todos, inclusive desativados, para mostrar registros antigos). */
    public static function map(): array
    {
        $m = array_map(fn($s) => $s[0], self::SYSTEM);
        foreach (self::rows() as $r) $m[$r['code']] = $r['name'];
        return $m;
    }

    public static function label(?string $code): string
    {
        return self::map()[$code] ?? (string)$code;
    }

    /** Status que podem ser escolhidos à mão (ativos; vendido só pela conversão). */
    public static function selectable(): array
    {
        $out = [];
        foreach (self::rows() as $r) if ((int)$r['active'] && $r['code'] !== 'vendido') $out[] = $r['code'];
        return $out;
    }

    /** Valida o status escolhido; aceita o atual do orçamento mesmo que tenha sido desativado. */
    public static function check(string $code, ?string $current = null): void
    {
        if ($code === $current && $code !== 'vendido' && isset(self::map()[$code])) return;
        if (!in_array($code, self::selectable(), true)) throw new AppError('Status inválido');
    }

    /** Lista para o front (GET meta): ordem, nome, cor e se está ativo. */
    public static function forForms(): array
    {
        return array_map(fn($r) => ['code' => $r['code'], 'label' => $r['name'], 'color' => $r['color'],
            'active' => (bool)(int)$r['active'], 'system' => $r['system']], self::rows());
    }

    public static function save(array $in, ?int $id = null): int
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
        if ($name === '') throw new AppError('Informe o nome do status');
        if (mb_strlen($name) > 60) throw new AppError('Nome muito longo (máximo 60 caracteres)');
        $color = strtolower(trim((string)($in['color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) throw new AppError('Escolha uma cor válida');
        if (DB::val('SELECT id FROM quote_statuses WHERE company_id = ? AND LOWER(name) = ? AND id <> ?', [Tenant::need(), mb_strtolower($name), (int)$id])) {
            throw new AppError('Já existe um status chamado "' . $name . '"');
        }
        $data = ['name' => $name, 'color' => $color, 'updated_at' => now()];
        self::$cache = null;
        if ($id) {
            $old = DB::one('SELECT * FROM quote_statuses WHERE id = ? AND company_id = ?', [$id, Tenant::need()]);
            if (!$old) throw new AppError('Status não encontrado', 404);
            if (array_key_exists('active', $in)) {
                $active = $in['active'] ? 1 : 0;
                if (!$active && isset(self::SYSTEM[$old['code']])) throw new AppError('Os status do sistema não podem ser desativados');
                if (!$active && in_array($old['code'], [Settings::get('quote_on_sent'), Settings::get('quote_on_approved'), Settings::get('quote_on_refused')], true)) {
                    throw new AppError('Este status está em uso em Configurações → Orçamento. Troque lá antes de desativar.');
                }
                $data['active'] = $active;
            }
            DB::update('quote_statuses', $id, $data);
            return $id;
        }
        $newId = DB::insert('quote_statuses', $data + ['code' => 'tmp' . bin2hex(random_bytes(6)), 'active' => 1,
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort), 0) + 1 FROM quote_statuses WHERE company_id = ? AND code <> ?', [Tenant::need(), 'vendido']), 'created_at' => now()]);
        DB::update('quote_statuses', $newId, ['code' => 'st_' . $newId]);
        // "Vendido" fica sempre por último
        DB::run('UPDATE quote_statuses SET sort = ? WHERE code = ? AND company_id = ?', [(int)DB::val('SELECT MAX(sort) FROM quote_statuses WHERE company_id = ?', [Tenant::need()]) + 1, 'vendido', Tenant::need()]);
        return $newId;
    }

    public static function delete(int $id): void
    {
        $r = DB::one('SELECT * FROM quote_statuses WHERE id = ? AND company_id = ?', [$id, Tenant::need()]);
        if (!$r) throw new AppError('Status não encontrado', 404);
        if (isset(self::SYSTEM[$r['code']])) throw new AppError('Os status do sistema não podem ser excluídos (dá para renomear e trocar a cor)');
        if (DB::val('SELECT COUNT(*) FROM quotes WHERE company_id = ? AND status = ?', [Tenant::need(), $r['code']])) {
            throw new AppError('Há orçamentos com este status. Mude o status deles ou desative este status.');
        }
        if (in_array($r['code'], [Settings::get('quote_on_sent'), Settings::get('quote_on_approved'), Settings::get('quote_on_refused')], true)) {
            throw new AppError('Este status está em uso em Configurações → Orçamento. Troque lá antes de excluir.');
        }
        DB::run('DELETE FROM quote_statuses WHERE id = ? AND company_id = ?', [$id, Tenant::need()]);
        self::$cache = null;
    }

    public static function reorder(array $ids): void
    {
        DB::tx(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) DB::run('UPDATE quote_statuses SET sort = ? WHERE id = ? AND company_id = ?', [$i + 1, (int)$id, Tenant::need()]);
        });
        self::$cache = null;
    }

    public static function seed(): void
    {
        $have = array_column(DB::all('SELECT code FROM quote_statuses WHERE company_id = ?', [Tenant::need()]), 'code');
        $i = (int)DB::val('SELECT COALESCE(MAX(sort), 0) FROM quote_statuses WHERE company_id = ?', [Tenant::need()]);
        foreach (self::SYSTEM as $code => [$name, $color]) {
            if (in_array($code, $have, true)) continue;
            DB::insert('quote_statuses', ['code' => $code, 'name' => $name, 'color' => $color, 'active' => 1, 'sort' => ++$i,
                'created_at' => now(), 'updated_at' => now()]);
        }
        self::$cache = null;
    }

    /** Para testes / troca de banco. */
    public static function flush(): void { self::$cache = null; }
}
