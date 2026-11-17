<?php
/**
 * Financeiro: contas a pagar e a receber (com parcelas) e fluxo de caixa.
 * As vendas entram sozinhas como recebimento; cancelar a venda cancela o lançamento.
 */

class Finance
{
    const TYPES = ['receber' => 'A receber', 'pagar' => 'A pagar'];
    const STATUSES = ['aberto' => 'Em aberto', 'vencido' => 'Vencido', 'pago' => 'Pago', 'cancelado' => 'Cancelado'];
    const CATEGORIES = [
        'receber' => ['Vendas', 'Serviços', 'Outras receitas'],
        'pagar' => ['Fornecedores', 'Aluguel', 'Salários', 'Impostos', 'Energia, água e internet', 'Manutenção', 'Combustível', 'Outras despesas'],
    ];

    public static function get(int $id): array
    {
        $e = DB::one('SELECT f.*, c.name customer_name, s.name supplier_name, u.name user_name
            FROM financial_entries f LEFT JOIN customers c ON c.id = f.customer_id
            LEFT JOIN suppliers s ON s.id = f.supplier_id LEFT JOIN users u ON u.id = f.user_id
            WHERE f.id = ?', [$id]);
        if (!$e) throw new AppError('Lançamento não encontrado', 404);
        return self::decorate($e);
    }

    private static function decorate(array $e): array
    {
        $st = $e['status'];
        if ($st === 'aberto' && $e['due_date'] < date('Y-m-d')) $st = 'vencido';
        $e['display_status'] = $st;
        $e['status_label'] = self::STATUSES[$st] ?? $st;
        $e['party_name'] = $e['type'] === 'receber' ? ($e['customer_name'] ?? null) : ($e['supplier_name'] ?? null);
        return $e;
    }

    public static function categories(): array
    {
        $out = self::CATEGORIES;
        foreach (DB::all("SELECT DISTINCT type, category FROM financial_entries WHERE category IS NOT NULL AND category <> ''") as $r) {
            if (isset($out[$r['type']]) && !in_array($r['category'], $out[$r['type']], true)) $out[$r['type']][] = $r['category'];
        }
        return $out;
    }

    /**
     * Cria (com parcelas) ou edita um lançamento.
     * Parcelas: "dividir" reparte o valor; "repetir" lança o mesmo valor todo mês.
     * Retorna os ids criados/alterados.
     */
    public static function save(array $in, int $userId, ?int $id = null): array
    {
        $old = $id ? self::get($id) : null;
        if ($old && $old['sale_id']) throw new AppError('Este lançamento veio de uma venda. Para mudar, cancele a venda.');
        if ($old && $old['status'] === 'cancelado') throw new AppError('Lançamento cancelado não pode ser editado');
        $type = $old['type'] ?? ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) throw new AppError('Escolha se é conta a pagar ou a receber');
        $desc = trim((string)($in['description'] ?? ''));
        if ($desc === '') throw new AppError('Informe a descrição');
        $amount = round(Inventory::num($in['amount'] ?? 0), 2);
        if ($amount <= 0) throw new AppError('Informe um valor maior que zero');
        $due = self::date($in['due_date'] ?? null);
        if (!$due) throw new AppError('Informe o vencimento');
        $method = (string)PaymentMethods::check($in['payment_method'] ?? '', $old['payment_method'] ?? null);

        $customerId = $supplierId = null;
        if ($type === 'receber' && !empty($in['customer_id'])) { Customers::get((int)$in['customer_id']); $customerId = (int)$in['customer_id']; }
        if ($type === 'pagar' && !empty($in['supplier_id'])) { Customers::get((int)$in['supplier_id'], 'suppliers'); $supplierId = (int)$in['supplier_id']; }

        $base = [
            'description' => mb_substr($desc, 0, 200),
            'category' => Inventory::nullable(mb_substr(trim((string)($in['category'] ?? '')), 0, 80)),
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'payment_method' => $method ?: null,
            'notes' => Inventory::nullable(mb_substr(trim((string)($in['notes'] ?? '')), 0, 500)),
            'updated_at' => now(),
        ];

        if ($old) {
            DB::update('financial_entries', $id, $base + ['amount' => $amount, 'due_date' => $due]
                + ($old['status'] === 'pago' ? ['paid_amount' => $amount] : []));
            return [$id];
        }

        $n = max(1, min(120, (int)($in['installments'] ?? 1)));
        $mode = ($in['installment_mode'] ?? 'dividir') === 'repetir' ? 'repetir' : 'dividir';
        $values = [];
        if ($mode === 'dividir' && $n > 1) {
            $each = floor($amount / $n * 100) / 100;
            for ($i = 0; $i < $n; $i++) $values[] = $each;
            $values[$n - 1] = round($amount - $each * ($n - 1), 2);
        } else {
            $values = array_fill(0, $n, $amount);
        }
        $paidNow = !empty($in['paid']);
        $group = $n > 1 ? bin2hex(random_bytes(8)) : null;
        $now = now();
        return DB::tx(function () use ($values, $n, $due, $base, $type, $group, $userId, $now, $paidNow, $in) {
            $ids = [];
            foreach ($values as $i => $v) {
                $d = self::addMonths($due, $i);
                $paid = $paidNow && $i === 0;
                $ids[] = DB::insert('financial_entries', $base + [
                    'type' => $type,
                    'status' => $paid ? 'pago' : 'aberto',
                    'amount' => $v,
                    'due_date' => $d,
                    'paid_at' => $paid ? (self::date($in['paid_at'] ?? null) ?? date('Y-m-d')) : null,
                    'paid_amount' => $paid ? $v : null,
                    'installment' => $i + 1,
                    'installments' => $n,
                    'group_key' => $group,
                    'user_id' => $userId,
                    'created_at' => $now,
                ]);
            }
            return $ids;
        });
    }

    /** Baixa (pagamento ou recebimento). Valor menor que o total deixa o restante em aberto. */
    public static function pay(int $id, array $in): void
    {
        DB::tx(function () use ($id, $in) {
            $e = self::get($id);
            if ($e['status'] !== 'aberto') throw new AppError('Só é possível dar baixa em lançamentos em aberto');
            $paidAt = self::date($in['paid_at'] ?? null) ?? date('Y-m-d');
            $value = isset($in['paid_amount']) && $in['paid_amount'] !== '' ? round(Inventory::num($in['paid_amount']), 2) : (float)$e['amount'];
            if ($value <= 0) throw new AppError('Informe o valor pago');
            $method = (string)PaymentMethods::check($in['payment_method'] ?? $e['payment_method'] ?? '', $e['payment_method']);
            $amount = (float)$e['amount'];
            $rest = round($amount - $value, 2);
            $upd = ['status' => 'pago', 'paid_at' => $paidAt, 'paid_amount' => $value, 'payment_method' => $method ?: null, 'updated_at' => now()];
            if ($rest > 0.004 && !empty($in['keep_rest'])) {
                // Pagamento parcial: o lançamento fica com o valor pago e o restante vira um novo em aberto
                $upd['amount'] = $value;
                $copy = $e;
                foreach (['id', 'customer_name', 'supplier_name', 'user_name', 'display_status', 'status_label', 'party_name'] as $k) unset($copy[$k]);
                DB::insert('financial_entries', array_merge($copy, [
                    'amount' => $rest, 'status' => 'aberto', 'paid_at' => null, 'paid_amount' => null,
                    'description' => mb_substr($e['description'] . ' (restante)', 0, 200),
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            DB::update('financial_entries', $id, $upd);
        });
    }

    public static function reopen(int $id): void
    {
        $e = self::get($id);
        if ($e['sale_id'] && (int)$e['installments'] <= 1) throw new AppError('Este recebimento veio de uma venda. Para desfazer, cancele a venda.');
        if ($e['status'] !== 'pago') throw new AppError('Este lançamento não está pago');
        DB::update('financial_entries', $id, ['status' => 'aberto', 'paid_at' => null, 'paid_amount' => null, 'updated_at' => now()]);
    }

    public static function cancel(int $id): void
    {
        $e = self::get($id);
        if ($e['sale_id']) throw new AppError('Este recebimento veio de uma venda. Para desfazer, cancele a venda.');
        if ($e['status'] === 'pago') throw new AppError('Reabra o lançamento antes de cancelar');
        DB::update('financial_entries', $id, ['status' => 'cancelado', 'updated_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $e = self::get($id);
        if ($e['sale_id']) throw new AppError('Este recebimento veio de uma venda. Para desfazer, cancele a venda.');
        if ($e['status'] === 'pago') throw new AppError('Reabra o lançamento antes de excluir');
        DB::run('DELETE FROM financial_entries WHERE id = ?', [$id]);
    }

    /**
     * Lista com filtros. A data considerada é a do pagamento (se pago) ou a do vencimento.
     * status: aberto | vencido | pago | cancelado | todos (padrão: tudo menos cancelado).
     */
    public static function list(array $f): array
    {
        $where = ['1 = 1']; $p = [];
        if (isset(self::TYPES[$f['type'] ?? ''])) { $where[] = 'f.type = ?'; $p[] = $f['type']; }
        $today = date('Y-m-d');
        switch ($f['status'] ?? '') {
            case 'aberto': $where[] = "f.status = 'aberto'"; break;
            case 'vencido': $where[] = "f.status = 'aberto' AND f.due_date < ?"; $p[] = $today; break;
            case 'avencer': $where[] = "f.status = 'aberto' AND f.due_date >= ?"; $p[] = $today; break;
            case 'pago': $where[] = "f.status = 'pago'"; break;
            case 'cancelado': $where[] = "f.status = 'cancelado'"; break;
            case 'todos': break;
            default: $where[] = "f.status <> 'cancelado'";
        }
        $from = self::date($f['from'] ?? null); $to = self::date($f['to'] ?? null);
        $dateExpr = "CASE WHEN f.status = 'pago' THEN f.paid_at ELSE f.due_date END";
        if ($from) { $where[] = "$dateExpr >= ?"; $p[] = $from; }
        if ($to) { $where[] = "$dateExpr <= ?"; $p[] = $to; }
        if (!empty($f['category'])) { $where[] = 'f.category = ?'; $p[] = $f['category']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(f.description LIKE ? OR c.name LIKE ? OR s.name LIKE ? OR f.notes LIKE ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $w = implode(' AND ', $where);
        $join = 'FROM financial_entries f LEFT JOIN customers c ON c.id = f.customer_id LEFT JOIN suppliers s ON s.id = f.supplier_id';
        $tot = DB::one("SELECT COUNT(*) n,
                COALESCE(SUM(CASE WHEN f.type = 'receber' AND f.status <> 'cancelado' THEN (CASE WHEN f.status = 'pago' THEN f.paid_amount ELSE f.amount END) ELSE 0 END), 0) receber,
                COALESCE(SUM(CASE WHEN f.type = 'pagar' AND f.status <> 'cancelado' THEN (CASE WHEN f.status = 'pago' THEN f.paid_amount ELSE f.amount END) ELSE 0 END), 0) pagar
            $join WHERE $w", $p);
        $per = 50; $page = max(1, (int)($f['page'] ?? 1));
        $order = ($f['status'] ?? '') === 'pago' ? 'f.paid_at DESC, f.id DESC' : "$dateExpr ASC, f.id ASC";
        $rows = DB::all("SELECT f.*, c.name customer_name, s.name supplier_name $join WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        return [
            'rows' => array_map([self::class, 'decorate'], $rows),
            'total' => (int)$tot['n'], 'page' => $page, 'per_page' => $per,
            'sum_receber' => round((float)$tot['receber'], 2), 'sum_pagar' => round((float)$tot['pagar'], 2),
        ];
    }

    /** Números rápidos para o topo da tela e o painel. */
    public static function summary(): array
    {
        $today = date('Y-m-d');
        $week = date('Y-m-d', strtotime('+7 days'));
        $m1 = date('Y-m-01'); $m2 = date('Y-m-t');
        $out = [];
        foreach (array_keys(self::TYPES) as $t) {
            $r = DB::one("SELECT
                    COALESCE(SUM(CASE WHEN status = 'aberto' THEN amount ELSE 0 END), 0) open_total,
                    COALESCE(SUM(CASE WHEN status = 'aberto' AND due_date < ? THEN amount ELSE 0 END), 0) overdue,
                    SUM(CASE WHEN status = 'aberto' AND due_date < ? THEN 1 ELSE 0 END) overdue_count,
                    COALESCE(SUM(CASE WHEN status = 'aberto' AND due_date >= ? AND due_date <= ? THEN amount ELSE 0 END), 0) next7,
                    COALESCE(SUM(CASE WHEN status = 'pago' AND paid_at >= ? AND paid_at <= ? THEN paid_amount ELSE 0 END), 0) paid_month
                FROM financial_entries WHERE type = ?", [$today, $today, $today, $week, $m1, $m2, $t]);
            $out[$t] = array_map(fn($v) => round((float)$v, 2), $r);
            $out[$t]['overdue_count'] = (int)$r['overdue_count'];
        }
        $out['balance'] = self::balanceUntil(date('Y-m-d', strtotime('+1 day')));
        return $out;
    }

    /** Saldo realizado antes de $date (saldo inicial + recebido - pago). */
    public static function balanceUntil(string $date): float
    {
        $r = DB::one("SELECT
                COALESCE(SUM(CASE WHEN type = 'receber' THEN paid_amount ELSE 0 END), 0) i,
                COALESCE(SUM(CASE WHEN type = 'pagar' THEN paid_amount ELSE 0 END), 0) o
            FROM financial_entries WHERE status = 'pago' AND paid_at < ?", [$date]);
        return round(self::opening() + (float)$r['i'] - (float)$r['o'], 2);
    }

    public static function opening(): float
    {
        return (float)Settings::get('cash_opening_balance');
    }

    public static function setOpening($v): float
    {
        $v = round(Inventory::num($v), 2);
        Settings::save(['cash_opening_balance' => (string)$v]);
        return $v;
    }

    /**
     * Fluxo de caixa do período, por dia ou por mês.
     * Realizado: o que foi pago/recebido (pela data da baixa).
     * Previsto: o que está em aberto (pelo vencimento). Os atrasados entram no primeiro dia a partir de hoje.
     */
    public static function cashflow(array $f): array
    {
        $from = self::date($f['from'] ?? null) ?? date('Y-m-01');
        $to = self::date($f['to'] ?? null) ?? date('Y-m-t');
        if ($to < $from) throw new AppError('A data final é anterior à inicial');
        if ((strtotime($to) - strtotime($from)) / 86400 > 731) throw new AppError('Escolha um período de até 2 anos');
        $group = ($f['group'] ?? '') === 'mes' ? 'mes' : 'dia';
        $today = date('Y-m-d');
        $key = fn(string $d) => $group === 'mes' ? substr($d, 0, 7) : $d;

        $buckets = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $k = $key(date('Y-m-d', $t));
            $buckets[$k] ??= ['key' => $k, 'in' => 0.0, 'out' => 0.0, 'in_proj' => 0.0, 'out_proj' => 0.0];
        }
        $real = DB::all("SELECT type, paid_at d, SUM(paid_amount) v FROM financial_entries
            WHERE status = 'pago' AND paid_at >= ? AND paid_at <= ? GROUP BY type, paid_at", [$from, $to]);
        foreach ($real as $r) $buckets[$key($r['d'])][$r['type'] === 'receber' ? 'in' : 'out'] += (float)$r['v'];

        // Previsto (em aberto) só faz sentido de hoje em diante. O que vence antes do
        // início do previsto (atrasados, ou o intervalo até um período futuro) entra
        // no primeiro dia de hoje em diante ou no saldo inicial previsto.
        $projFrom = max($from, $today);
        $overdue = ['receber' => 0.0, 'pagar' => 0.0];
        $pre = 0.0;
        foreach (DB::all("SELECT type, due_date d, SUM(amount) v FROM financial_entries
                WHERE status = 'aberto' AND due_date <= ? GROUP BY type, due_date", [$to]) as $r) {
            $v = (float)$r['v']; $sign = $r['type'] === 'receber' ? 1 : -1;
            if ($r['d'] < $today) $overdue[$r['type']] += $v;
            if ($projFrom > $to) continue;
            if ($r['d'] >= $projFrom) $buckets[$key($r['d'])][$r['type'] === 'receber' ? 'in_proj' : 'out_proj'] += $v;
            elseif ($projFrom === $today) $buckets[$key($today)][$r['type'] === 'receber' ? 'in_proj' : 'out_proj'] += $v;
            else $pre += $sign * $v;
        }
        $opening = self::balanceUntil($from);
        $openingProj = $opening + $pre;
        $bal = $opening; $balProj = $openingProj;
        $rows = [];
        $tot = ['in' => 0.0, 'out' => 0.0, 'in_proj' => 0.0, 'out_proj' => 0.0];
        foreach ($buckets as $b) {
            $bal += $b['in'] - $b['out'];
            $balProj += $b['in'] - $b['out'] + $b['in_proj'] - $b['out_proj'];
            foreach ($tot as $k => $_) $tot[$k] += $b[$k];
            $b['balance'] = round($bal, 2);
            $b['balance_proj'] = round($balProj, 2);
            foreach (['in', 'out', 'in_proj', 'out_proj'] as $k) $b[$k] = round($b[$k], 2);
            $rows[] = $b;
        }
        $cats = DB::all("SELECT type, COALESCE(NULLIF(category, ''), 'Sem categoria') category, SUM(paid_amount) v
            FROM financial_entries WHERE status = 'pago' AND paid_at >= ? AND paid_at <= ?
            GROUP BY type, COALESCE(NULLIF(category, ''), 'Sem categoria') ORDER BY v DESC", [$from, $to]);
        return [
            'from' => $from, 'to' => $to, 'group' => $group, 'today' => $today,
            'opening' => $opening, 'opening_proj' => round($openingProj, 2), 'opening_setting' => self::opening(),
            'rows' => $rows,
            'totals' => array_map(fn($v) => round($v, 2), $tot),
            'closing' => round($bal, 2), 'closing_proj' => round($balProj, 2),
            'overdue' => array_map(fn($v) => round($v, 2), $overdue),
            'by_category' => array_map(fn($r) => ['type' => $r['type'], 'category' => $r['category'], 'value' => round((float)$r['v'], 2)], $cats),
        ];
    }

    // ---------- Integração com vendas ----------

    /**
     * Venda → financeiro. À vista (1x): entra como recebida na data da venda.
     * Parcelada: uma conta a receber por parcela, vencendo a cada N dias depois da venda.
     */
    public static function fromSale(int $saleId): void
    {
        if (DB::val('SELECT id FROM financial_entries WHERE sale_id = ?', [$saleId])) return;
        $s = DB::one('SELECT * FROM sales WHERE id = ?', [$saleId]);
        if (!$s || (float)$s['total'] <= 0) return;
        $d = substr($s['created_at'], 0, 10);
        $total = round((float)$s['total'], 2);
        $n = max(1, (int)($s['installments'] ?? 1));
        $hasEntry = !empty($s['installment_entry']);
        $days = max(1, (int)($s['installment_days'] ?? 30));
        $canceled = $s['status'] === 'cancelada';
        $base = [
            'type' => 'receber',
            'category' => 'Vendas',
            'customer_id' => $s['customer_id'] ?: null,
            'payment_method' => PaymentMethods::exists($s['payment_method']) ? $s['payment_method'] : null,
            'sale_id' => $saleId,
            'user_id' => $s['user_id'] ?: null,
            'created_at' => $s['created_at'],
            'updated_at' => now(),
        ];
        $desc = 'Venda #' . $saleId . ($s['customer'] ? ' · ' . $s['customer'] : '');
        if ($n === 1 && !$hasEntry) {
            // Configurações → Vendas: à vista entra como recebida (padrão) ou em aberto, para dar baixa depois
            $open = !$canceled && Settings::get('sale_cash_open') === '1';
            DB::insert('financial_entries', $base + [
                'status' => $canceled ? 'cancelado' : ($open ? 'aberto' : 'pago'), 'description' => $desc,
                'amount' => $total, 'due_date' => $d,
            ] + ($open ? [] : ['paid_at' => $d, 'paid_amount' => $total]));
            return;
        }
        // Plano gravado na venda (valores e vencimentos escolhidos); sem plano, divide igual a cada N dias
        $rows = $n + ($hasEntry ? 1 : 0);
        $plan = json_decode((string)($s['installment_plan'] ?? ''), true);
        if (!is_array($plan) || count($plan) !== $rows) $plan = PaymentMethods::plan($n, $days, $total, $d, null, false, $hasEntry);
        $group = bin2hex(random_bytes(8));
        for ($i = 1; $i <= $rows; $i++) {
            $isEntry = $hasEntry && $i === 1;
            $k = $hasEntry ? $i - 1 : $i;
            DB::insert('financial_entries', $base + [
                'status' => $canceled ? 'cancelado' : 'aberto',
                'description' => mb_substr($desc, 0, 180) . ($isEntry ? ' · entrada' : " · parcela $k/$n"),
                'amount' => round((float)$plan[$i - 1]['amount'], 2),
                'due_date' => $plan[$i - 1]['due_date'],
                'installment' => $i, 'installments' => $rows, 'group_key' => $group,
            ]);
        }
    }

    /**
     * Contas a pagar a partir das duplicatas da nota de compra.
     * Cada parcela: due_date, amount, number (opcional), paid (opcional: já paga no vencimento).
     */
    public static function fromInvoice(int $invoiceId, ?int $supplierId, array $parcels, string $method, int $userId, string $number = '', string $supplierName = ''): array
    {
        $rows = [];
        foreach (array_values($parcels) as $i => $p) {
            $due = self::date($p['due_date'] ?? null);
            $amount = round(Inventory::num($p['amount'] ?? 0), 2);
            if (!$due) throw new AppError('Parcela ' . ($i + 1) . ' da nota: informe o vencimento');
            if ($amount <= 0) throw new AppError('Parcela ' . ($i + 1) . ' da nota: informe o valor');
            $rows[] = [$due, $amount, trim((string)($p['number'] ?? '')), !empty($p['paid'])];
        }
        if (!$rows) return [];
        if (!PaymentMethods::exists($method)) $method = '';
        $n = count($rows);
        $group = $n > 1 ? bin2hex(random_bytes(8)) : null;
        $base = 'NF ' . ($number !== '' ? $number : 's/n') . ($supplierName !== '' ? ' · ' . $supplierName : '');
        $ids = [];
        foreach ($rows as $i => [$due, $amount, $dupNo, $paid]) {
            $ids[] = DB::insert('financial_entries', [
                'type' => 'pagar',
                'status' => $paid ? 'pago' : 'aberto',
                'description' => mb_substr($base . ($n > 1 || $dupNo !== '' ? ' · parcela ' . ($dupNo !== '' ? $dupNo : ($i + 1)) : ''), 0, 200),
                'category' => 'Fornecedores',
                'supplier_id' => $supplierId,
                'amount' => $amount,
                'due_date' => $due,
                'paid_at' => $paid ? $due : null,
                'paid_amount' => $paid ? $amount : null,
                'payment_method' => $method ?: null,
                'installment' => $i + 1,
                'installments' => $n,
                'group_key' => $group,
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $ids;
    }

    public static function cancelSale(int $saleId): void
    {
        DB::run("UPDATE financial_entries SET status = 'cancelado', updated_at = ? WHERE sale_id = ?", [now(), $saleId]);
    }

    // ---------- Utilidades ----------

    public static function date($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]";
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) throw new AppError('Data inválida: ' . $v);
        return $v;
    }

    /** Soma meses mantendo o dia (31/01 + 1 mês = 28 ou 29/02). */
    public static function addMonths(string $date, int $n): string
    {
        if ($n === 0) return $date;
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $m += $n;
        $y += intdiv($m - 1, 12); $m = ($m - 1) % 12 + 1;
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min($d, $last));
    }
}
