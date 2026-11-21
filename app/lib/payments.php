<?php
/**
 * Formas de pagamento cadastradas pelo usuário (Cadastros → Formas de pagamento).
 * Vendas, orçamentos, financeiro e ordens de compra guardam o código (`code`); o nome pode mudar à vontade.
 * Forma já usada não é apagada, só desativada (some das listas, mas o histórico continua mostrando o nome).
 */

class PaymentMethods
{
    /** As formas que já existiam no sistema (entram na instalação e na atualização). */
    const DEFAULTS = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'PIX',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'boleto' => 'Boleto',
        'transferencia' => 'Transferência',
        'cheque' => 'Cheque',
        'outro' => 'Outro',
    ];

    /** Parcelamento que já vem nas formas padrão (dá para mudar no cadastro). */
    const DEFAULT_INSTALLMENTS = ['credito' => [12, 'fixo'], 'boleto' => [12, 'livre']];
    const MAX_INSTALLMENTS = 48;

    private static ?array $cache = null;

    public static function reset(): void { self::$cache = null; }

    /** Todas, na ordem escolhida. */
    public static function rows(): array
    {
        if (self::$cache === null) self::$cache = DB::all('SELECT * FROM payment_methods WHERE company_id = ? ORDER BY sort, id', [Tenant::id() ?? 0]);
        return self::$cache;
    }

    /** Código → nome de todas (inclusive desativadas), para mostrar o histórico. */
    public static function labels(): array
    {
        $out = [];
        foreach (self::rows() as $r) $out[$r['code']] = $r['name'];
        return $out;
    }

    /** Código → nome só das ativas, para escolher em vendas, orçamentos etc. */
    public static function active(): array
    {
        $out = [];
        foreach (self::rows() as $r) if ((int)$r['active']) $out[$r['code']] = $r['name'];
        return $out;
    }

    /** Código → ['max' => parcelas, 'mode' => 'fixo' (30 em 30 dias) | 'livre' (intervalo escolhido na venda/orçamento)]. */
    public static function rules(): array
    {
        $out = [];
        foreach (self::rows() as $r) $out[$r['code']] = ['max' => max(1, (int)$r['max_installments']), 'mode' => $r['interval_mode'] === 'livre' ? 'livre' : 'fixo'];
        return $out;
    }

    /**
     * Confere parcelas e intervalo para as formas escolhidas (vale a regra mais generosa entre elas).
     * Devolve [parcelas, dias]. Intervalo fixo é sempre 30 dias.
     */
    public static function installments($codes, $n, $days, bool $entry = false): array
    {
        $codes = array_filter(is_array($codes) ? $codes : [$codes]);
        $rules = self::rules();
        $max = 1; $free = false;
        foreach ($codes as $c) {
            if (!isset($rules[$c])) continue;
            $max = max($max, $rules[$c]['max']);
            if ($rules[$c]['mode'] === 'livre' && $rules[$c]['max'] > 1) $free = true;
        }
        $n = (int)$n ?: 1;
        if ($n < 1) throw new AppError('Número de parcelas inválido');
        // Com entrada, a entrada conta como um dos pagamentos (entrada + 3x = 4 pagamentos)
        if ($n + ($entry ? 1 : 0) > $max) {
            if ($max === 1) throw new AppError($entry ? 'Esta forma de pagamento não aceita entrada + parcelas' : 'Esta forma de pagamento não aceita parcelamento');
            throw new AppError($entry ? "Esta forma de pagamento aceita no máximo {$max} pagamentos (entrada + " . ($max - 1) . 'x)' : "Esta forma de pagamento aceita no máximo {$max}x");
        }
        if ($n === 1 && !$entry) return [1, 30];
        if (!$free) return [$n, 30];
        $days = (int)$days ?: 30;
        if ($days < 1 || $days > 365) throw new AppError('Intervalo entre parcelas inválido (de 1 a 365 dias)');
        return [$n, $days];
    }

    /** Intervalo livre para alguma das formas? (aí os vencimentos podem ser escolhidos à mão) */
    public static function freeInterval($codes): bool
    {
        $rules = self::rules();
        foreach (array_filter(is_array($codes) ? $codes : [$codes]) as $c) {
            if (isset($rules[$c]) && $rules[$c]['mode'] === 'livre' && $rules[$c]['max'] > 1) return true;
        }
        return false;
    }

    /**
     * Plano de parcelas: [['due_date' => Y-m-d, 'amount' => valor], ...] ou null se for à vista.
     * Sem plano informado: divide o total (centavos na última) e vence a cada $days dias a partir de $start.
     * Com plano informado: os valores precisam somar o total; as datas só valem com intervalo livre
     * (intervalo fixo recalcula de 30 em 30 dias).
     */
    public static function plan(int $n, int $days, float $total, string $start, $given, bool $free, bool $entry = false, ?float $entryPct = null): ?array
    {
        if ($n <= 1 && !$entry) return null;
        $total = round($total, 2);
        if (is_string($given)) $given = json_decode($given, true);
        $given = is_array($given) ? array_values($given) : [];
        // Com entrada: a 1ª linha é a entrada (vence no dia), depois as $n parcelas a cada $days dias
        $rows = $n + ($entry ? 1 : 0);
        $entryAmount = $entry && $entryPct !== null && $entryPct > 0 ? round($total * min($entryPct, 99) / 100, 2) : null;
        $restCount = $entryAmount !== null ? $n : $rows;
        $restTotal = $entryAmount !== null ? $total - $entryAmount : $total;
        $each = floor($restTotal / $restCount * 100) / 100;
        $out = [];
        $useGiven = count($given) === $rows;
        for ($i = 0; $i < $rows; $i++) {
            $isEntry = $entry && $i === 0;
            $k = $entry ? $i : $i + 1; // dias depois do início: entrada = 0
            if ($isEntry && $entryAmount !== null) $amount = $entryAmount;
            else $amount = $i === $rows - 1 ? round($restTotal - $each * ($restCount - 1), 2) : $each;
            $due = date('Y-m-d', strtotime($start . ' +' . ($days * $k) . ' days'));
            if ($useGiven) {
                $amount = round(Inventory::num($given[$i]['amount'] ?? 0), 2);
                if ($amount <= 0) throw new AppError('Parcela ' . ($i + 1) . ': informe o valor');
                if ($free) {
                    $d = Finance::date($given[$i]['due_date'] ?? null);
                    if (!$d) throw new AppError('Parcela ' . ($i + 1) . ': informe o vencimento');
                    $due = $d;
                }
            }
            $out[] = ['due_date' => $due, 'amount' => $amount] + ($isEntry ? ['entry' => true] : []);
        }
        $sum = round(array_sum(array_column($out, 'amount')), 2);
        if (abs($sum - $total) > 0.009) {
            throw new AppError('As parcelas somam R$ ' . number_format($sum, 2, ',', '.') . ', mas o total é R$ ' . number_format($total, 2, ',', '.') . '. Ajuste os valores.');
        }
        return $out;
    }

    public static function applyDefaultInstallments(): void
    {
        foreach (self::DEFAULT_INSTALLMENTS as $code => [$max, $mode]) {
            DB::run('UPDATE payment_methods SET max_installments = ?, interval_mode = ? WHERE code = ? AND max_installments = 1', [$max, $mode, $code]);
        }
        self::reset();
    }

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') return '';
        return self::labels()[$code] ?? $code;
    }

    public static function exists(?string $code): bool
    {
        return $code !== null && $code !== '' && isset(self::labels()[$code]);
    }

    /**
     * Confere a forma escolhida: precisa estar ativa, exceto se já era a do registro que está sendo editado.
     * Devolve o código ou null (vazio).
     */
    public static function check(?string $code, $old = null, bool $required = false): ?string
    {
        $code = trim((string)$code);
        if ($code === '') {
            if ($required) throw new AppError('Escolha a forma de pagamento');
            return null;
        }
        $olds = is_array($old) ? $old : ($old !== null && $old !== '' ? [(string)$old] : []);
        if (isset(self::active()[$code]) || (in_array($code, $olds, true) && self::exists($code))) return $code;
        throw new AppError(self::exists($code) ? 'A forma de pagamento "' . self::label($code) . '" está desativada' : 'Forma de pagamento inválida');
    }

    /** Lista para a tela de cadastro, com quantas vezes cada uma foi usada. */
    public static function listWithUsage(): array
    {
        $rows = self::rows();
        foreach ($rows as &$r) $r['uses'] = self::uses($r['code']);
        return $rows;
    }

    public static function uses(string $code): int
    {
        $cid = Tenant::need();
        return (int)DB::val('SELECT COUNT(*) FROM sales WHERE company_id = ? AND payment_method = ?', [$cid, $code])
            + (int)DB::val('SELECT COUNT(*) FROM financial_entries WHERE company_id = ? AND payment_method = ?', [$cid, $code])
            + (int)DB::val('SELECT COUNT(*) FROM purchase_orders WHERE company_id = ? AND payment_method = ?', [$cid, $code])
            + (int)DB::val('SELECT COUNT(*) FROM quotes WHERE company_id = ? AND (payment_methods = ? OR payment_methods LIKE ? OR payment_methods LIKE ? OR payment_methods LIKE ?)',
                [$cid, $code, $code . ',%', '%,' . $code, '%,' . $code . ',%']);
    }

    public static function save(array $in, ?int $id = null): int
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
        if ($name === '') throw new AppError('Informe o nome da forma de pagamento');
        if (mb_strlen($name) > 60) throw new AppError('Nome muito longo (máximo 60 caracteres)');
        $dup = DB::val('SELECT id FROM payment_methods WHERE company_id = ? AND LOWER(name) = ? AND id <> ?', [Tenant::need(), mb_strtolower($name), (int)$id]);
        if ($dup) throw new AppError('Já existe uma forma de pagamento chamada "' . $name . '"');
        self::reset();
        if ($id) {
            $old = DB::one('SELECT * FROM payment_methods WHERE id = ? AND company_id = ?', [$id, Tenant::need()]);
            if (!$old) throw new AppError('Forma de pagamento não encontrada', 404);
            $data = ['name' => $name, 'updated_at' => now()] + self::installmentFields($in);
            if (array_key_exists('active', $in)) {
                $active = $in['active'] ? 1 : 0;
                if (!$active && (int)$old['active'] && count(self::active()) <= 1) throw new AppError('Deixe pelo menos uma forma de pagamento ativa');
                $data['active'] = $active;
            }
            DB::update('payment_methods', $id, $data);
            self::reset();
            return $id;
        }
        $id = DB::insert('payment_methods', self::installmentFields($in) + [
            'code' => self::newCode($name), 'name' => $name, 'active' => 1,
            'max_installments' => 1, 'interval_mode' => 'fixo',
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort), 0) + 1 FROM payment_methods WHERE company_id = ?', [Tenant::need()]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::reset();
        return $id;
    }

    private static function installmentFields(array $in): array
    {
        $out = [];
        if (array_key_exists('max_installments', $in)) {
            $n = (int)$in['max_installments'];
            if ($n < 1 || $n > self::MAX_INSTALLMENTS) throw new AppError('Parcelas: escolha de 1 a ' . self::MAX_INSTALLMENTS);
            $out['max_installments'] = $n;
        }
        if (array_key_exists('interval_mode', $in)) $out['interval_mode'] = $in['interval_mode'] === 'livre' ? 'livre' : 'fixo';
        return $out;
    }

    /** Apaga se nunca foi usada; se já foi, pede para desativar (o histórico precisa do nome). */
    public static function delete(int $id): void
    {
        $r = DB::one('SELECT * FROM payment_methods WHERE id = ? AND company_id = ?', [$id, Tenant::need()]);
        if (!$r) throw new AppError('Forma de pagamento não encontrada', 404);
        if (self::uses($r['code'])) throw new AppError('"' . $r['name'] . '" já foi usada em vendas, orçamentos ou no financeiro. Desative em vez de excluir: ela some das listas e o histórico continua.');
        if ((int)$r['active'] && count(self::active()) <= 1) throw new AppError('Deixe pelo menos uma forma de pagamento ativa');
        DB::run('DELETE FROM payment_methods WHERE id = ? AND company_id = ?', [$id, Tenant::need()]);
        self::reset();
    }

    /** Nova ordem: lista de ids na ordem desejada. */
    public static function reorder(array $ids): void
    {
        DB::tx(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) DB::run('UPDATE payment_methods SET sort = ? WHERE id = ? AND company_id = ?', [$i + 1, (int)$id, Tenant::need()]);
        });
        self::reset();
    }

    /** Cria as formas padrão se a tabela estiver vazia. */
    public static function seed(): void
    {
        if ((int)DB::val('SELECT COUNT(*) FROM payment_methods WHERE company_id = ?', [Tenant::need()])) return;
        $i = 0;
        foreach (self::DEFAULTS as $code => $name) {
            [$max, $mode] = self::DEFAULT_INSTALLMENTS[$code] ?? [1, 'fixo'];
            DB::insert('payment_methods', ['code' => $code, 'name' => $name, 'active' => 1, 'max_installments' => $max, 'interval_mode' => $mode,
                'sort' => ++$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        self::reset();
    }

    private static function newCode(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: ''), '_'));
        $base = substr($base !== '' ? $base : 'forma', 0, 20);
        $code = $base; $n = 2;
        while (DB::val('SELECT id FROM payment_methods WHERE code = ? AND company_id = ?', [$code, Tenant::need()])) $code = substr($base, 0, 17) . '_' . $n++;
        return $code;
    }
}
