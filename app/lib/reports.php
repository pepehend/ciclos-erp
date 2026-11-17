<?php
/**
 * Relatórios: faturamento, custo, lucro, produtos, formas de pagamento, perdas e estoque.
 */

class Reports
{
    public static function range(?string $from, ?string $to): array
    {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) ? $from : date('Y-m-01');
        $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to) ? $to : date('Y-m-d');
        if ($f > $t) [$f, $t] = [$t, $f];
        return [$f . ' 00:00:00', $t . ' 23:59:59', $f, $t];
    }

    /**
     * Filtros comuns das listas de orçamentos e vendas: período de criação (from/to), cliente (texto),
     * valor mínimo/máximo e responsável. $cols = colunas da consulta (created_at, total, user_id e nomes do cliente).
     */
    public static function listFilters(array $in, array &$w, array &$p, array $cols): void
    {
        $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : null;
        $from = $d(($in['from'] ?? null)); $to = $d(($in['to'] ?? null));
        if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
        if ($from) { $w[] = $cols['created'] . ' >= ?'; $p[] = $from . ' 00:00:00'; }
        if ($to) { $w[] = $cols['created'] . ' <= ?'; $p[] = $to . ' 23:59:59'; }
        if (($c = trim((string)($in['customer'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($c) . '%';
            $or = array_map(fn($col) => "LOWER($col) LIKE ?", $cols['names']);
            $digits = preg_replace('/\D/', '', $c);
            if (strlen($digits) >= 5 && !empty($cols['doc'])) { $or[] = $cols['doc'] . ' LIKE ?'; }
            $w[] = '(' . implode(' OR ', $or) . ')';
            foreach ($cols['names'] as $_) $p[] = $like;
            if (strlen($digits) >= 5 && !empty($cols['doc'])) $p[] = '%' . $digits . '%';
        }
        if (trim((string)($in['min'] ?? '')) !== '') { $w[] = $cols['total'] . ' >= ?'; $p[] = Inventory::num($in['min']); }
        if (trim((string)($in['max'] ?? '')) !== '') { $w[] = $cols['total'] . ' <= ?'; $p[] = Inventory::num($in['max']); }
        if ($uid = (int)($in['user_id'] ?? 0)) { $w[] = $cols['user'] . ' = ?'; $p[] = $uid; }
    }

    public static function summary(?string $from, ?string $to): array
    {
        [$a, $b, $f, $t] = self::range($from, $to);
        $ok = "status = 'ok' AND created_at BETWEEN ? AND ?";

        $tot = DB::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) revenue, COALESCE(SUM(cost_total),0) cost,
            COALESCE(SUM(discount),0) discount FROM sales WHERE $ok", [$a, $b]);
        $revenue = (float)$tot['revenue'];
        $cost = (float)$tot['cost'];
        $n = (int)$tot['n'];

        $losses = (float)DB::val("SELECT COALESCE(SUM(-quantity * unit_cost),0) FROM movements
            WHERE type = 'perda' AND created_at BETWEEN ? AND ?", [$a, $b]);
        $purchases = (float)DB::val("SELECT COALESCE(SUM(total),0) FROM invoices WHERE created_at BETWEEN ? AND ?", [$a, $b])
            + (float)DB::val("SELECT COALESCE(SUM(quantity * unit_cost),0) FROM movements WHERE type = 'entrada' AND created_at BETWEEN ? AND ?", [$a, $b]);

        // Por produto: o desconto da venda é rateado proporcionalmente entre os itens
        $byProduct = DB::all("SELECT p.id, p.name, p.unit, SUM(i.quantity) qty,
                SUM(CASE WHEN s.subtotal > 0 THEN i.total * s.total / s.subtotal ELSE 0 END) revenue,
                SUM(i.quantity * i.unit_cost) cost
            FROM sale_items i JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
            WHERE s.status = 'ok' AND s.created_at BETWEEN ? AND ?
            GROUP BY p.id, p.name, p.unit ORDER BY revenue DESC", [$a, $b]);
        foreach ($byProduct as &$r) {
            $r['qty'] = (float)$r['qty'];
            $r['revenue'] = round((float)$r['revenue'], 2);
            $r['cost'] = round((float)$r['cost'], 2);
            $r['profit'] = round($r['revenue'] - $r['cost'], 2);
            $r['margin'] = $r['revenue'] > 0 ? round($r['profit'] / $r['revenue'] * 100, 1) : 0;
        }
        unset($r);

        $byPayment = DB::all("SELECT payment_method method, COUNT(*) n, SUM(total) total FROM sales
            WHERE $ok GROUP BY payment_method ORDER BY total DESC", [$a, $b]);
        foreach ($byPayment as &$r) {
            $r['label'] = PaymentMethods::label($r['method']);
            $r['n'] = (int)$r['n'];
            $r['total'] = round((float)$r['total'], 2);
        }
        unset($r);

        $byCustomer = DB::all("SELECT COALESCE(c.id, 0) id, COALESCE(c.trade_name, c.name, s.customer, 'Sem cliente') name,
                COUNT(*) n, SUM(s.total) total, SUM(s.total - s.cost_total) profit
            FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
            WHERE s.status = 'ok' AND s.created_at BETWEEN ? AND ?
            GROUP BY COALESCE(c.id, 0), COALESCE(c.trade_name, c.name, s.customer, 'Sem cliente')
            ORDER BY total DESC LIMIT 15", [$a, $b]);
        foreach ($byCustomer as &$r) {
            $r['id'] = (int)$r['id']; $r['n'] = (int)$r['n'];
            $r['total'] = round((float)$r['total'], 2); $r['profit'] = round((float)$r['profit'], 2);
        }
        unset($r);

        $daysRaw = DB::all("SELECT SUBSTR(created_at, 1, 10) d, SUM(total) revenue, SUM(cost_total) cost, COUNT(*) n
            FROM sales WHERE $ok GROUP BY SUBSTR(created_at, 1, 10)", [$a, $b]);
        $idx = [];
        foreach ($daysRaw as $r) $idx[$r['d']] = $r;
        $days = [];
        $span = (strtotime($t) - strtotime($f)) / 86400;
        if ($span <= 92) {
            for ($d = $f; $d <= $t; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $r = $idx[$d] ?? ['revenue' => 0, 'cost' => 0, 'n' => 0];
                $days[] = ['date' => $d, 'revenue' => round((float)$r['revenue'], 2),
                    'profit' => round((float)$r['revenue'] - (float)$r['cost'], 2), 'n' => (int)$r['n']];
            }
        }

        $profit = $revenue - $cost;
        return [
            'from' => $f, 'to' => $t,
            'sales_count' => $n,
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'gross_profit' => round($profit, 2),
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
            'avg_ticket' => $n ? round($revenue / $n, 2) : 0,
            'discounts' => round((float)$tot['discount'], 2),
            'losses' => round($losses, 2),
            'profit_after_losses' => round($profit - $losses, 2),
            'purchases' => round($purchases, 2),
            'by_product' => $byProduct,
            'by_payment' => $byPayment,
            'by_customer' => $byCustomer,
            'by_day' => $days,
            'stock' => self::stockValue(),
        ];
    }

    public static function stockValue(): array
    {
        $r = DB::one("SELECT COALESCE(SUM(CASE WHEN stock > 0 THEN stock * avg_cost ELSE 0 END),0) v,
            COUNT(*) n FROM products WHERE active = 1 AND type <> 'servico'");
        // Serviços não têm estoque: ficam fora do valor, da contagem e do mínimo
        $low = (int)DB::val("SELECT COUNT(*) FROM products WHERE active = 1 AND type <> 'servico' AND min_stock > 0 AND stock <= min_stock");
        return ['value' => round((float)$r['v'], 2), 'products' => (int)$r['n'], 'low' => $low];
    }

    public static function dashboard(): array
    {
        $today = date('Y-m-d');
        $d = self::summary($today, $today);
        $m = self::summary(date('Y-m-01'), $today);
        $low = DB::all("SELECT id, name, unit, stock, min_stock FROM products
            WHERE active = 1 AND type <> 'servico' AND min_stock > 0 AND stock <= min_stock ORDER BY (stock / min_stock), name LIMIT 20");
        $recent = DB::all('SELECT m.*, p.name product_name, p.unit, u.name user_name FROM movements m
            JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.user_id
            ORDER BY m.id DESC LIMIT 10');
        return [
            'today' => ['revenue' => $d['revenue'], 'profit' => $d['gross_profit'], 'sales' => $d['sales_count']],
            'month' => ['revenue' => $m['revenue'], 'profit' => $m['gross_profit'], 'sales' => $m['sales_count'], 'margin' => $m['margin']],
            'stock' => $m['stock'],
            'low_stock' => $low,
            'recent' => $recent,
            'month_days' => self::fullMonth($m['by_day']),
        ];
    }

    /** Completa os dias do mês até o último (os dias que ainda não chegaram ficam zerados). */
    private static function fullMonth(array $days): array
    {
        $have = array_column($days, null, 'date');
        $out = [];
        $end = date('Y-m-t');
        for ($d = date('Y-m-01'); $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[] = $have[$d] ?? ['date' => $d, 'revenue' => 0, 'profit' => 0, 'n' => 0];
        }
        return $out;
    }
}
