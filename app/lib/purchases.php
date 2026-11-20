<?php
/**
 * Ordens de compra: pedido formal de peças e produtos ao fornecedor.
 * Não mexe no estoque nem no financeiro: a entrada acontece ao importar a nota da compra.
 * O fornecedor recebe um link público (pedido.php) para ver, imprimir e confirmar o pedido.
 */

class PurchaseOrders
{
    const STATUSES = [
        'rascunho' => 'Rascunho',
        'enviada' => 'Enviada',
        'confirmada' => 'Confirmada pelo fornecedor',
        'recebida' => 'Recebida',
        'cancelada' => 'Cancelada',
    ];
    /** Status que podem ser escolhidos à mão. */
    const MANUAL = ['rascunho', 'enviada', 'confirmada', 'recebida', 'cancelada'];

    /** Nome do status, com o nome trocado em Configurações → Ordens de compra. */
    public static function label(string $code): string
    {
        $custom = json_decode(Settings::get('po_statuses'), true) ?: [];
        return $custom[$code]['label'] ?? (self::STATUSES[$code] ?? $code);
    }
    const EDITABLE = ['rascunho', 'enviada'];

    public static function get(int $id): array
    {
        $o = DB::one('SELECT o.*, u.name user_name, s.name supplier_name, s.trade_name supplier_trade_name,
                s.document supplier_document, s.phone supplier_phone, s.email supplier_email
            FROM purchase_orders o LEFT JOIN users u ON u.id = o.user_id LEFT JOIN suppliers s ON s.id = o.supplier_id
            WHERE o.id = ?', [$id]);
        if (!$o) throw new AppError('Ordem de compra não encontrada', 404);
        $o['items'] = DB::all('SELECT i.*, p.sku, p.barcode, p.stock, p.min_stock, p.avg_cost, p.type product_type, p.active product_active, p.deleted_at product_deleted
            FROM purchase_order_items i JOIN products p ON p.id = i.product_id
            WHERE i.purchase_order_id = ? ORDER BY i.sort, i.id', [$id]);
        // Código do item no fornecedor (aprendido nas notas importadas)
        $doc = preg_replace('/\D/', '', (string)$o['supplier_document']);
        $codes = [];
        if ($doc !== '') {
            foreach (DB::all('SELECT product_id, supplier_code FROM supplier_map WHERE supplier_cnpj = ? ORDER BY id DESC', [$doc]) as $m) {
                $codes[(int)$m['product_id']] ??= $m['supplier_code'];
            }
        }
        foreach ($o['items'] as &$i) $i['supplier_code'] = $codes[(int)$i['product_id']] ?? null;
        unset($i);
        $o['status_label'] = self::label($o['status']);
        return $o;
    }

    public static function byToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new AppError('Pedido não encontrado', 404);
        $id = DB::val('SELECT id FROM purchase_orders WHERE public_token = ?', [$token]);
        if (!$id) throw new AppError('Pedido não encontrado', 404);
        return self::get((int)$id);
    }

    public static function list(array $f): array
    {
        $where = ['1 = 1']; $p = [];
        if (isset(self::STATUSES[$f['status'] ?? ''])) { $where[] = 'o.status = ?'; $p[] = $f['status']; }
        elseif (($f['status'] ?? '') === 'abertas') $where[] = "o.status IN ('rascunho','enviada','confirmada')";
        if (!empty($f['supplier_id'])) { $where[] = 'o.supplier_id = ?'; $p[] = (int)$f['supplier_id']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.name LIKE ? OR s.trade_name LIKE ? OR o.notes LIKE ? OR o.id = ?
                OR EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = o.id AND i.description LIKE ?))';
            array_push($p, "%$q%", "%$q%", "%$q%", (int)preg_replace('/\D/', '', $q), "%$q%");
        }
        $w = implode(' AND ', $where);
        $join = 'FROM purchase_orders o LEFT JOIN suppliers s ON s.id = o.supplier_id LEFT JOIN users u ON u.id = o.user_id';
        $n = (int)DB::val("SELECT COUNT(*) $join WHERE $w", $p);
        $per = 30; $page = max(1, (int)($f['page'] ?? 1));
        $rows = DB::all("SELECT o.*, s.name supplier_name, s.trade_name supplier_trade_name, u.name user_name,
                (SELECT COUNT(*) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) item_count
            $join WHERE $w ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        foreach ($rows as &$r) $r['status_label'] = self::label($r['status']);
        $counts = [];
        foreach (DB::all('SELECT status, COUNT(*) n, COALESCE(SUM(total), 0) v FROM purchase_orders GROUP BY status') as $c) {
            $counts[$c['status']] = ['n' => (int)$c['n'], 'total' => round((float)$c['v'], 2)];
        }
        return ['rows' => $rows, 'total' => $n, 'page' => $page, 'per_page' => $per, 'counts' => $counts];
    }

    /** Cria ou atualiza a ordem inteira (os itens são regravados). */
    public static function save(array $in, int $userId, ?int $id = null): int
    {
        if ($id) {
            $old = self::get($id);
            if (!in_array($old['status'], self::EDITABLE, true)) {
                throw new AppError('Ordem ' . mb_strtolower(self::STATUSES[$old['status']]) . ' não pode ser editada. Volte para rascunho ou duplique.');
            }
        }
        $status = $in['status'] ?? ($old['status'] ?? 'rascunho');
        if (!in_array($status, self::EDITABLE, true)) throw new AppError('Status inválido');
        $supplierId = (int)($in['supplier_id'] ?? 0);
        if (!$supplierId) throw new AppError('Selecione o fornecedor');
        $sup = Customers::get($supplierId, 'suppliers');
        // Fornecedor inativo ou excluído só continua numa ordem que já era dele
        if (!$sup['active'] && (empty($old) || (int)$old['supplier_id'] !== $supplierId)) {
            throw new AppError('O fornecedor "' . $sup['name'] . '" está ' . ($sup['deleted_at'] ? 'excluído' : 'inativo'));
        }

        $orderDate = Finance::date($in['order_date'] ?? null) ?? date('Y-m-d');
        $expected = Finance::date($in['expected_date'] ?? null);
        if ($expected && $expected < $orderDate) throw new AppError('A previsão de entrega é anterior à data do pedido');
        $method = (string)($in['payment_method'] ?? '');
        $method = (string)PaymentMethods::check($method, $old['payment_method'] ?? null);

        $items = []; $itemsTotal = 0.0; $seen = [];
        $kept = $id ? array_map('intval', array_column(DB::all('SELECT product_id FROM purchase_order_items WHERE purchase_order_id = ?', [$id]), 'product_id')) : [];
        foreach (array_values($in['items'] ?? []) as $n => $it) {
            $p = Inventory::product((int)($it['product_id'] ?? 0));
            if ($p['type'] === 'servico') throw new AppError('"' . $p['name'] . '" é serviço e não entra em ordem de compra');
            if ($p['deleted_at'] && !in_array((int)$p['id'], $kept, true)) throw new AppError('"' . $p['name'] . '" foi excluído do cadastro');
            if (isset($seen[$p['id']])) throw new AppError('"' . $p['name'] . '" está repetido no pedido');
            $seen[$p['id']] = true;
            $q = Inventory::num($it['quantity'] ?? 0);
            if ($q <= 0) throw new AppError('Quantidade inválida em "' . $p['name'] . '"');
            $c = isset($it['unit_cost']) && $it['unit_cost'] !== '' ? Inventory::num($it['unit_cost']) : (float)$p['avg_cost'];
            if ($c < 0) throw new AppError('Custo inválido em "' . $p['name'] . '"');
            $total = round($q * $c, 2);
            $itemsTotal += $total;
            $items[] = [
                'product_id' => (int)$p['id'],
                'description' => mb_substr(trim((string)($it['description'] ?? '')) ?: $p['name'], 0, 255),
                'unit' => $p['unit'],
                'quantity' => round($q, 4),
                'unit_cost' => round($c, 6),
                'total' => $total,
                'sort' => $n,
            ];
        }
        if (!$items) throw new AppError('Adicione pelo menos um item ao pedido');
        $freight = round(max(0, Inventory::num($in['freight'] ?? 0)), 2);
        $discount = round(max(0, Inventory::num($in['discount'] ?? 0)), 2);
        if ($discount > $itemsTotal + $freight + 0.001) throw new AppError('O desconto é maior que o valor do pedido');

        $data = [
            'status' => $status,
            'supplier_id' => $supplierId,
            'order_date' => $orderDate,
            'expected_date' => $expected,
            'payment_method' => $method ?: null,
            'payment_terms' => Inventory::nullable(mb_substr(trim((string)($in['payment_terms'] ?? '')), 0, 60)),
            'items_total' => round($itemsTotal, 2),
            'freight' => $freight,
            'discount' => $discount,
            'total' => round($itemsTotal + $freight - $discount, 2),
            'notes' => Inventory::nullable(mb_substr(trim((string)($in['notes'] ?? '')), 0, 4000)),
            'updated_at' => now(),
        ];
        if ($status === 'enviada' && empty($old['sent_at'])) $data['sent_at'] = now();
        return DB::tx(function () use ($id, $data, $items, $userId) {
            if ($id) {
                DB::update('purchase_orders', $id, $data);
                DB::run('DELETE FROM purchase_order_items WHERE purchase_order_id = ?', [$id]);
            } else {
                $id = DB::insert('purchase_orders', $data + [
                    'user_id' => $userId, 'public_token' => bin2hex(random_bytes(16)), 'created_at' => now(),
                ]);
            }
            foreach ($items as $it) DB::insert('purchase_order_items', $it + ['purchase_order_id' => $id]);
            return $id;
        });
    }

    public static function setStatus(int $id, string $status, ?string $date = null): void
    {
        if (!in_array($status, self::MANUAL, true)) throw new AppError('Status inválido');
        $o = self::get($id);
        $upd = ['status' => $status, 'updated_at' => now()];
        if ($status === 'enviada' && !$o['sent_at']) $upd['sent_at'] = now();
        if ($status === 'confirmada' && !$o['confirmed_at']) $upd['confirmed_at'] = now();
        $upd['received_at'] = $status === 'recebida' ? (Finance::date($date) ?? date('Y-m-d')) : null;
        DB::update('purchase_orders', $id, $upd);
    }

    const BULK_MAX = 200;
    /** De quais status cada mudança pode partir (os mesmos botões da ordem). */
    const TRANSITIONS = [
        'enviada' => ['rascunho'],
        'confirmada' => ['rascunho', 'enviada'],
        'recebida' => ['enviada', 'confirmada'],
        'rascunho' => ['enviada', 'confirmada', 'recebida', 'cancelada'],
        'cancelada' => ['rascunho', 'enviada', 'confirmada'],
    ];

    /**
     * Ações em massa da lista: status | delete. Cada ordem é tratada à parte;
     * as que não podem (ex.: recebida não volta a "enviada") voltam em "errors".
     */
    public static function bulk(array $ids, string $action, ?string $status = null, ?string $date = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw new AppError('Selecione pelo menos uma ordem de compra');
        if (count($ids) > self::BULK_MAX) throw new AppError('Selecione no máximo ' . self::BULK_MAX . ' ordens por vez');
        if (!in_array($action, ['status', 'delete'], true)) throw new AppError('Ação inválida');
        if ($action === 'status' && !isset(self::TRANSITIONS[(string)$status])) throw new AppError('Status inválido');
        $ok = 0; $errors = [];
        foreach ($ids as $id) {
            try {
                $cur = DB::val('SELECT status FROM purchase_orders WHERE id = ?', [$id]);
                if (!$cur) throw new AppError('Ordem não encontrada');
                if ($action === 'delete') self::delete($id);
                else {
                    if ($cur === $status) throw new AppError('Já está como "' . self::label($status) . '"');
                    if (!in_array($cur, self::TRANSITIONS[$status], true)) throw new AppError('Uma ordem "' . self::label($cur) . '" não pode ir para "' . self::label($status) . '"');
                    self::setStatus($id, (string)$status, $date);
                }
                $ok++;
            } catch (AppError $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    /** Resposta do fornecedor pelo link público. */
    public static function supplierConfirm(string $token, string $comment = ''): void
    {
        $o = self::byToken($token);
        if (Settings::get('po_supplier_confirm') === '0') throw new AppError('A confirmação pelo link está desligada. Responda pelo WhatsApp ou e-mail.');
        if (!in_array($o['status'], ['rascunho', 'enviada'], true)) throw new AppError('Este pedido não pode mais ser confirmado');
        DB::update('purchase_orders', (int)$o['id'], [
            'status' => 'confirmada', 'confirmed_at' => now(), 'updated_at' => now(),
            'supplier_comment' => Inventory::nullable(mb_substr(trim($comment), 0, 500)),
        ]);
    }

    public static function duplicate(int $id, int $userId): int
    {
        $o = self::get($id);
        return self::save([
            'status' => 'rascunho',
            'supplier_id' => $o['supplier_id'],
            'order_date' => date('Y-m-d'),
            'payment_method' => $o['payment_method'],
            'payment_terms' => $o['payment_terms'],
            'freight' => $o['freight'],
            'discount' => $o['discount'],
            'notes' => $o['notes'],
            'items' => array_map(fn($i) => [
                'product_id' => $i['product_id'], 'description' => $i['description'],
                'quantity' => $i['quantity'], 'unit_cost' => $i['unit_cost'],
            ], array_values(array_filter($o['items'], fn($i) => $i['product_active'] && !$i['product_deleted'] && $i['product_type'] !== 'servico'))),
        ], $userId);
    }

    public static function delete(int $id): void
    {
        $o = self::get($id);
        if (!in_array($o['status'], ['rascunho', 'cancelada'], true)) throw new AppError('Só é possível excluir ordens em rascunho ou canceladas');
        DB::run('DELETE FROM purchase_order_items WHERE purchase_order_id = ?', [$id]);
        DB::run('DELETE FROM purchase_orders WHERE id = ?', [$id]);
    }

    /** Produtos abaixo do estoque mínimo, com a quantidade que falta e o que já está pedido. */
    public static function lowStock(): array
    {
        $rows = DB::all("SELECT p.id, p.name, p.sku, p.unit, p.type, p.stock, p.min_stock, p.avg_cost,
                COALESCE((SELECT SUM(i.quantity) FROM purchase_order_items i JOIN purchase_orders o ON o.id = i.purchase_order_id
                    WHERE i.product_id = p.id AND o.status IN ('rascunho','enviada','confirmada')), 0) on_order
            FROM products p
            WHERE p.active = 1 AND p.deleted_at IS NULL AND p.type <> 'servico' AND p.min_stock > 0 AND p.stock < p.min_stock
            ORDER BY p.name");
        foreach ($rows as &$r) {
            $r['missing'] = round(max(0, (float)$r['min_stock'] - (float)$r['stock'] - (float)$r['on_order']), 4);
        }
        return $rows;
    }
}
