<?php
/**
 * Condições de pagamento (Cadastros → Condições de pagamento).
 * São sugestões para o campo "Condição de pagamento" do orçamento, da venda e da ordem de compra:
 * o registro guarda o texto, então renomear ou excluir uma condição não muda o que já foi feito.
 * Opcionalmente a condição define o parcelamento (quantidade e intervalo), aplicado quando a forma de pagamento permite.
 */

class PaymentTerms
{
    const DEFAULTS = [
        ['À vista', null, null], ['Na entrega', null, null], ['50% de entrada e 50% na entrega', null, null],
        ['28 dias', null, null], ['30 dias', null, null], ['30/60 dias', 2, 30], ['30/60/90 dias', 3, 30], ['28/56/84 dias', 3, 28],
        ['Parcelado no cartão em até 3x', 3, 30], ['Parcelado no cartão em até 10x', 10, 30],
        ['Entrada + 28/56/84 dias', 3, 28, true], ['Entrada + 30/60/90 dias', 3, 30, true],
    ];

    public static function rows(bool $onlyActive = false): array
    {
        return DB::all('SELECT * FROM payment_terms' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort, id');
    }

    /** Lista enxuta para os formulários. */
    public static function forForms(): array
    {
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'],
            'installments' => $r['installments'] !== null ? (int)$r['installments'] : null,
            'interval_days' => $r['interval_days'] !== null ? (int)$r['interval_days'] : null,
            'entry' => (bool)(int)($r['entry'] ?? 0), 'entry_percent' => isset($r['entry_percent']) && $r['entry_percent'] !== null ? (float)$r['entry_percent'] : null], self::rows(true));
    }

    public static function save(array $in, ?int $id = null): int
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
        if ($name === '') throw new AppError('Informe a condição de pagamento');
        if (mb_strlen($name) > 160) throw new AppError('Texto muito longo (máximo 160 caracteres)');
        $dup = DB::one('SELECT id, active FROM payment_terms WHERE LOWER(name) = ? AND id <> ?', [mb_strtolower($name), (int)$id]);
        if ($dup) {
            // Cadastrar de novo uma que estava desativada só reativa
            if (!$id && !(int)$dup['active']) { DB::update('payment_terms', (int)$dup['id'], ['active' => 1, 'updated_at' => now()]); return (int)$dup['id']; }
            throw new AppError('A condição "' . $name . '" já está cadastrada');
        }
        $n = isset($in['installments']) && $in['installments'] !== '' && $in['installments'] !== null ? (int)$in['installments'] : null;
        if ($n !== null && ($n < 1 || $n > PaymentMethods::MAX_INSTALLMENTS)) throw new AppError('Parcelas: de 1 a ' . PaymentMethods::MAX_INSTALLMENTS);
        $entry = $n !== null && !empty($in['entry']);
        $days = $n !== null && ($n > 1 || $entry) ? (int)($in['interval_days'] ?? 30) : null;
        if ($days !== null && ($days < 1 || $days > 365)) throw new AppError('Intervalo entre parcelas: de 1 a 365 dias');
        $pct = $entry && isset($in['entry_percent']) && $in['entry_percent'] !== '' && $in['entry_percent'] !== null ? round(Inventory::num($in['entry_percent']), 2) : null;
        if ($pct !== null && ($pct <= 0 || $pct >= 100)) throw new AppError('Percentual da entrada: maior que 0 e menor que 100');
        $data = ['name' => $name, 'installments' => $n, 'interval_days' => $days, 'entry' => $entry ? 1 : 0, 'entry_percent' => $pct, 'updated_at' => now()];
        if (array_key_exists('active', $in)) $data['active'] = $in['active'] ? 1 : 0;
        if ($id) {
            if (!DB::val('SELECT id FROM payment_terms WHERE id = ?', [$id])) throw new AppError('Condição não encontrada', 404);
            DB::update('payment_terms', $id, $data);
            return $id;
        }
        return DB::insert('payment_terms', $data + ['active' => 1, 'sort' => (int)DB::val('SELECT COALESCE(MAX(sort), 0) + 1 FROM payment_terms'), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        DB::run('DELETE FROM payment_terms WHERE id = ?', [$id]);
    }

    public static function reorder(array $ids): void
    {
        DB::tx(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) DB::run('UPDATE payment_terms SET sort = ? WHERE id = ?', [$i + 1, (int)$id]);
        });
    }

    public static function seed(): void
    {
        if ((int)DB::val('SELECT COUNT(*) FROM payment_terms')) return;
        foreach (self::DEFAULTS as $i => $d) {
            DB::insert('payment_terms', ['name' => $d[0], 'installments' => $d[1], 'interval_days' => $d[2], 'entry' => !empty($d[3]) ? 1 : 0,
                'active' => 1, 'sort' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
