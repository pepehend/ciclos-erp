<?php
/**
 * Regras de negócio: estoque, custo médio e vendas.
 * (Produção, ficha técnica e insumos foram removidos na v1.10; o histórico antigo continua nas movimentações.)
 */

class AppError extends RuntimeException
{
    public int $status;
    public function __construct(string $msg, int $status = 400)
    {
        parent::__construct($msg);
        $this->status = $status;
    }
}

class Inventory
{
    const EPS = 0.00005;

    const MOVEMENT_LABELS = [
        'entrada' => 'Entrada',
        'nota' => 'Entrada por nota',
        'saida' => 'Saída / uso',
        'perda' => 'Perda',
        'ajuste' => 'Ajuste de inventário',
        'producao' => 'Produção',                  // histórico (recurso removido)
        'consumo_producao' => 'Consumo na produção',  // histórico (recurso removido)
        'venda' => 'Venda',
        'consumo_venda' => 'Consumo na venda',     // histórico (recurso removido)
        'estorno_venda' => 'Estorno de venda',
        'estorno_nota' => 'Estorno de nota',
        'ajuste_custo' => 'Ajuste de custo',
    ];


    // ---------- Produtos ----------

    public static function product(int $id, bool $lock = false): array
    {
        $p = DB::one('SELECT * FROM products WHERE id = ?' . ($lock ? DB::lockSuffix() : ''), [$id]);
        if (!$p) throw new AppError('Produto não encontrado', 404);
        return $p;
    }

    /**
     * Exclui um produto ou serviço.
     * Se nunca foi usado (vendas, orçamentos, notas, movimentações, ordens de compra), é apagado de vez.
     * Se já foi usado, sai de todas as listas mas fica guardado: vendas, orçamentos e notas antigas
     * continuam mostrando o item como era.
     */
    public static function deleteProduct(int $id): string
    {
        return DB::tx(function () use ($id) {
            $p = self::product($id, true);
            if ($p['deleted_at']) throw new AppError('Este item já foi excluído');
            $label = $p['type'] === 'servico' ? 'serviço' : 'produto';
            if ($p['type'] !== 'servico' && (float)$p['stock'] > self::EPS) {
                throw new AppError('"' . $p['name'] . '" ainda tem ' . self::fmtQty((float)$p['stock']) . ' ' . $p['unit']
                    . ' em estoque. Zere o estoque (lance uma perda ou a contagem) antes de excluir.');
            }
            $refs = 0;
            foreach (['sale_items', 'quote_items', 'invoice_items', 'purchase_order_items', 'movements'] as $t) {
                $refs += (int)DB::val("SELECT COUNT(*) FROM $t WHERE product_id = ?", [$id]);
            }
            DB::run('DELETE FROM supplier_map WHERE product_id = ?', [$id]);
            if (!$refs) {
                DB::run('DELETE FROM recipe_items WHERE product_id = ? OR ingredient_id = ?', [$id, $id]); // fichas antigas
                DB::run('DELETE FROM products WHERE id = ?', [$id]);
                return 'apagado';
            }
            // Já usado: guarda para o histórico, fora das listas. Libera código de barras e SKU para outro cadastro.
            DB::update('products', $id, ['deleted_at' => now(), 'active' => 0, 'barcode' => null, 'sku' => null, 'min_stock' => 0, 'updated_at' => now()]);
            return 'arquivado';
        });
    }

    /** Custo usado para calcular lucro de uma venda do produto. */
    public static function currentUnitCost(array $p): float
    {
        return (float)$p['avg_cost'];
    }

    public static function saveProduct(array $in, ?int $id = null, ?int $userId = null): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new AppError('Informe o nome do produto');
        $type = ($in['type'] ?? '') === 'servico' ? 'servico' : 'produto';
        $data = [
            'name' => mb_substr($name, 0, 200),
            'sku' => self::nullable($in['sku'] ?? null),
            'barcode' => self::nullable($in['barcode'] ?? null),
            'category' => self::nullable($in['category'] ?? null),
            'brand' => (($b = self::nullable($in['brand'] ?? null)) === null ? null : mb_substr($b, 0, 80)),
            'description' => (($dsc = trim(str_replace("\r", '', (string)($in['description'] ?? '')))) === '' ? null : mb_substr($dsc, 0, 5000)),
            'mfr_code' => (($m = self::nullable($in['mfr_code'] ?? null)) === null ? null : mb_substr($m, 0, 80)),
            'location' => $type === 'servico' ? null : (($l = self::nullable($in['location'] ?? null)) === null ? null : mb_substr($l, 0, 80)),
            'type' => $type,
            'unit' => mb_substr(trim((string)($in['unit'] ?? 'un')) ?: 'un', 0, 10),
            'sale_price' => round(max(0, self::num($in['sale_price'] ?? 0)), 2),
            'markup' => isset($in['markup']) && trim((string)$in['markup']) !== '' ? round(max(0, min(99999, self::num($in['markup']))), 2) : null,
            'min_stock' => max(0, self::num($in['min_stock'] ?? 0)),
            'updated_at' => now(),
        ];
        if (isset($in['active'])) $data['active'] = $in['active'] ? 1 : 0;
        if ($id && $type !== 'servico' && !array_key_exists('location', $in)) unset($data['location']); // telas que não mandam o campo não apagam a localização
        foreach (['brand', 'mfr_code', 'description'] as $k) if ($id && !array_key_exists($k, $in)) unset($data[$k]);
        $refs = array_key_exists('refs', $in) ? self::cleanRefs($in['refs']) : null;
        // Tributação: só produtos; sem o campo na requisição, mantém o que já existe
        if ($type === 'servico') $data['fiscal'] = null;
        elseif (array_key_exists('fiscal', $in)) $data['fiscal'] = Fiscal::encode(Fiscal::normalize($in['fiscal']));
        if ($id && DB::val('SELECT deleted_at FROM products WHERE id = ?', [$id])) throw new AppError('Este item foi excluído e não pode mais ser alterado');

        return DB::tx(function () use ($data, $id, $in, $refs, $userId) {
            if ($id) {
                self::product($id);
                DB::update('products', $id, $data);
                // Valor de compra alterado à mão na edição (produto com estoque)
                if ($data['type'] !== 'servico' && isset($in['avg_cost']) && trim((string)$in['avg_cost']) !== '') {
                    self::setCost($id, self::num($in['avg_cost']), $userId);
                }
            } else {
                $data['created_at'] = now();
                $data['stock'] = 0;
                $data['avg_cost'] = 0;
                $id = DB::insert('products', $data);
                // custo inicial informado manualmente (ex.: produto de revenda sem nota ainda)
                if (isset($in['avg_cost']) && self::num($in['avg_cost']) > 0) {
                    DB::update('products', $id, ['avg_cost' => self::num($in['avg_cost'])]);
                }
                // estoque inicial: entra como uma entrada no histórico, pelo custo inicial
                $initial = isset($in['initial_stock']) && trim((string)$in['initial_stock']) !== '' ? self::num($in['initial_stock']) : 0.0;
                if ($initial < 0) throw new AppError('O estoque inicial não pode ser negativo');
                if ($initial > 0 && $data['type'] !== 'servico') {
                    self::move($id, 'entrada', $initial, isset($in['avg_cost']) && trim((string)$in['avg_cost']) !== '' ? max(0, self::num($in['avg_cost'])) : 0.0,
                        ['note' => 'Estoque inicial', 'user_id' => $userId]);
                }
            }
            // Serviço não tem estoque: o custo é informado direto no cadastro
            if ($data['type'] === 'servico' && isset($in['avg_cost']) && trim((string)$in['avg_cost']) !== '') {
                DB::update('products', $id, ['avg_cost' => max(0, self::num($in['avg_cost'])), 'stock' => 0, 'min_stock' => 0]);
            }
            if ($refs !== null) self::saveRefs($id, $refs);
            self::repriceFrom($id);
            return $id;
        });
    }

    // ---------- Códigos: SKU, código do fabricante e referências cruzadas ----------

    /** Código só com letras e números, em maiúsculas (6205-2RS → 62052RS), para comparar códigos digitados de jeitos diferentes. */
    public static function codeNorm(?string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)@iconv('UTF-8', 'ASCII//TRANSLIT', (string)$s)));
    }

    /** SQL que normaliza uma coluna do mesmo jeito (funciona no MySQL e no SQLite). */
    public static function codeNormSql(string $col): string
    {
        $e = "UPPER(COALESCE($col, ''))";
        foreach (['-', '.', ' ', '/', '_', ',', '(', ')'] as $ch) $e = "REPLACE($e, '$ch', '')";
        return $e;
    }

    /**
     * Filtro da busca de produtos (tabela com alias p): nome, marca, localização, código de barras e,
     * ignorando traços/pontos/espaços, SKU, código do fabricante e referências cruzadas.
     */
    public static function searchSql(string $s): array
    {
        $like = '%' . mb_strtolower($s) . '%';
        $or = ['LOWER(p.name) LIKE ?', 'p.barcode = ?', 'LOWER(p.location) LIKE ?', 'LOWER(p.brand) LIKE ?'];
        $params = [$like, $s, $like, $like];
        if (($cn = self::codeNorm($s)) !== '') {
            $or[] = self::codeNormSql('p.sku') . ' LIKE ?';
            $or[] = self::codeNormSql('p.mfr_code') . ' LIKE ?';
            $or[] = 'EXISTS (SELECT 1 FROM product_refs r WHERE r.product_id = p.id AND r.code_norm LIKE ?)';
            array_push($params, "%$cn%", "%$cn%", "%$cn%");
        }
        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /** Valida a lista de referências cruzadas [{manufacturer, code}] vinda da tela. */
    public static function cleanRefs($refs): array
    {
        if (!is_array($refs)) throw new AppError('Referências cruzadas inválidas');
        $out = []; $seen = [];
        foreach ($refs as $r) {
            $code = trim(preg_replace('/\s+/', ' ', (string)($r['code'] ?? '')));
            $man = trim(preg_replace('/\s+/', ' ', (string)($r['manufacturer'] ?? '')));
            if ($code === '') { if ($man !== '') throw new AppError("Informe o código da referência de $man"); continue; }
            $norm = self::codeNorm($code);
            if ($norm === '') throw new AppError("Código de referência inválido: $code");
            $key = mb_strtolower($man) . '|' . $norm;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['manufacturer' => $man !== '' ? mb_substr($man, 0, 80) : null, 'code' => mb_substr($code, 0, 80), 'code_norm' => substr($norm, 0, 80)];
        }
        if (count($out) > 200) throw new AppError('Máximo de 200 referências cruzadas por produto');
        return $out;
    }

    private static function saveRefs(int $id, array $refs): void
    {
        DB::run('DELETE FROM product_refs WHERE product_id = ?', [$id]);
        foreach ($refs as $r) DB::insert('product_refs', ['product_id' => $id] + $r);
    }

    public static function refs(int $id): array
    {
        return DB::all('SELECT manufacturer, code FROM product_refs WHERE product_id = ? ORDER BY id', [$id]);
    }

    /**
     * Troca o custo (valor de compra) do produto à mão. Fica no histórico como "Ajuste de custo"
     * (quantidade 0, não mexe no estoque). As próximas compras voltam a fazer a média a partir dele.
     */
    public static function setCost(int $id, float $cost, ?int $userId = null): bool
    {
        if ($cost < 0) throw new AppError('O valor de compra não pode ser negativo');
        return DB::tx(function () use ($id, $cost, $userId) {
            $p = self::product($id, true);
            if ($p['type'] === 'servico') throw new AppError('Serviço não tem valor de compra: use o custo do serviço');
            $old = (float)$p['avg_cost']; $cost = round($cost, 6);
            if (abs($cost - $old) < 0.0000005) return false;
            DB::update('products', $id, ['avg_cost' => $cost, 'updated_at' => now()]);
            $fmt = fn($v) => 'R$ ' . number_format($v, abs($v * 100 - round($v * 100)) > 0.0001 ? 4 : 2, ',', '.');
            DB::insert('movements', [
                'product_id' => $id, 'type' => 'ajuste_custo', 'quantity' => 0, 'unit_cost' => $cost,
                'balance_after' => round((float)$p['stock'], 4), 'note' => 'Valor de compra alterado de ' . $fmt($old) . ' para ' . $fmt($cost),
                'user_id' => $userId, 'created_at' => now(),
            ]);
            self::repriceFrom($id);
            return true;
        });
    }

    // ---------- Preço automático (markup) ----------

    /** Preço de venda = custo x (1 + markup/100), arredondado em centavos. */
    public static function priceFromMarkup(float $cost, float $markup): float
    {
        return round($cost * (1 + $markup / 100), 2);
    }

    /** Recalcula o preço do produto quando ele tem markup. */
    public static function repriceFrom(int $productId): void
    {
        $p = DB::one('SELECT * FROM products WHERE id = ?', [$productId]);
        if (!$p) return;
        if ($p['markup'] !== null) {
            $cost = self::currentUnitCost($p);
            if ($cost > 0) {
                $price = self::priceFromMarkup($cost, (float)$p['markup']);
                if (abs($price - (float)$p['sale_price']) >= 0.005) {
                    DB::update('products', $productId, ['sale_price' => $price, 'updated_at' => now()]);
                }
            }
        }
    }

    // ---------- Movimentações ----------

    /**
     * Aplica uma movimentação. $qty positivo = entra, negativo = sai.
     * Entradas com custo recalculam o custo médio ponderado.
     */
    public static function move(int $productId, string $type, float $qty, ?float $unitCost, array $ctx = []): array
    {
        if (abs($qty) < self::EPS) throw new AppError('Quantidade deve ser diferente de zero');
        return DB::tx(function () use ($productId, $type, $qty, $unitCost, $ctx) {
            $p = self::product($productId, true);
            if (!$p['active'] && empty($ctx['allow_inactive'])) {
                throw new AppError('O produto "' . $p['name'] . '" está inativo');
            }
            $stock = (float)$p['stock'];
            $avg = (float)$p['avg_cost'];
            $new = $stock + $qty;
            if ($qty < 0 && $new < -self::EPS && empty($ctx['allow_negative'])) {
                throw new AppError(sprintf('Estoque insuficiente de "%s": disponível %s %s, necessário %s',
                    $p['name'], self::fmtQty($stock), $p['unit'], self::fmtQty(-$qty)));
            }
            if ($qty < 0 && isset($ctx['reverse_cost'])) {
                // Desfazendo uma entrada: retira o custo dela da média
                $rest = $stock + $qty;
                $avg = $rest > self::EPS ? max(0, (($stock * $avg) + ($qty * $ctx['reverse_cost'])) / $rest) : $avg;
                $moveCost = (float)$ctx['reverse_cost'];
            } elseif ($qty > 0 && $unitCost !== null && $unitCost >= 0) {
                $base = max($stock, 0);
                $avg = ($base + $qty) > 0 ? (($base * $avg) + ($qty * $unitCost)) / ($base + $qty) : $unitCost;
                $moveCost = $unitCost;
            } else {
                $moveCost = $unitCost ?? $avg;
            }
            if (abs($new) < self::EPS) $new = 0.0;
            DB::update('products', $productId, [
                'stock' => round($new, 4),
                'avg_cost' => round($avg, 6),
                'updated_at' => now(),
            ]);
            $id = DB::insert('movements', [
                'product_id' => $productId,
                'type' => $type,
                'quantity' => round($qty, 4),
                'unit_cost' => round($moveCost, 6),
                'balance_after' => round($new, 4),
                'note' => isset($ctx['note']) ? mb_substr((string)$ctx['note'], 0, 255) : null,
                'sale_id' => $ctx['sale_id'] ?? null,
                'invoice_id' => $ctx['invoice_id'] ?? null,
                'user_id' => $ctx['user_id'] ?? null,
                'created_at' => $ctx['at'] ?? now(),
            ]);
            if (abs($avg - (float)$p['avg_cost']) > 0.0000005) self::repriceFrom($productId);
            return ['id' => $id, 'stock' => $new, 'avg_cost' => $avg, 'unit_cost' => $moveCost];
        });
    }

    /** Movimentação manual feita pela tela de estoque. */
    public static function manualMove(array $in, int $userId): array
    {
        $pid = (int)($in['product_id'] ?? 0);
        $kind = $in['kind'] ?? '';
        $note = trim((string)($in['note'] ?? '')) ?: null;
        $ctx = ['user_id' => $userId, 'note' => $note];
        switch ($kind) {
            case 'entrada':
                $q = self::num($in['quantity'] ?? 0);
                if ($q <= 0) throw new AppError('Informe uma quantidade maior que zero');
                $cost = self::num($in['unit_cost'] ?? 0);
                if (!empty($in['total_cost'])) $cost = self::num($in['total_cost']) / $q;
                return self::move($pid, 'entrada', $q, $cost, $ctx);
            case 'saida':
            case 'perda':
                $q = self::num($in['quantity'] ?? 0);
                if ($q <= 0) throw new AppError('Informe uma quantidade maior que zero');
                return self::move($pid, $kind, -$q, null, $ctx);
            case 'ajuste':
                if (!isset($in['counted']) || $in['counted'] === '') throw new AppError('Informe a quantidade contada');
                $counted = self::num($in['counted']);
                if ($counted < 0) throw new AppError('A contagem não pode ser negativa');
                return DB::tx(function () use ($pid, $counted, $ctx) {
                    $p = self::product($pid, true);
                    $delta = $counted - (float)$p['stock'];
                    if (abs($delta) < self::EPS) throw new AppError('A contagem é igual ao estoque atual');
                    $ctx['allow_negative'] = true;
                    $ctx['note'] = $ctx['note'] ?? 'Contagem: ' . self::fmtQty($counted);
                    return self::move($pid, 'ajuste', $delta, $delta > 0 ? (float)$p['avg_cost'] : null, $ctx);
                });
            default:
                throw new AppError('Tipo de movimentação inválido');
        }
    }

    // ---------- Vendas ----------

    const SALE_NOTE_MAX = 2000;

    public static function createSale(array $in, int $userId): int
    {
        $items = $in['items'] ?? [];
        if (!is_array($items) || !$items) throw new AppError('Adicione pelo menos um item à venda');
        $method = $in['payment_method'] ?? '';
        $method = PaymentMethods::check($method, null, true);
        $instEntry = !empty($in['installment_entry']);
        [$instN, $instDays] = PaymentMethods::installments($method, $in['installments'] ?? 1, $in['installment_days'] ?? 30, $instEntry);
        $discount = round(max(0, self::num($in['discount'] ?? 0)), 2);
        $surcharge = round(max(0, self::num($in['surcharge'] ?? 0)), 2);
        // Custos adicionais descritos (frete, deslocamento…): o total vira o acréscimo da venda
        $extras = [];
        if (isset($in['extras']) && is_array($in['extras'])) {
            foreach ($in['extras'] as $e) {
                $d = trim((string)($e['description'] ?? ''));
                $v = round(self::num($e['amount'] ?? 0), 2);
                if ($d === '' && !$v) continue;
                if ($d === '') throw new AppError('Descreva o custo adicional de R$ ' . number_format($v, 2, ',', '.'));
                if ($v < 0) throw new AppError('Custo adicional não pode ser negativo');
                $extras[] = ['description' => mb_substr($d, 0, 200), 'amount' => $v];
            }
            $surcharge = round(array_sum(array_column($extras, 'amount')), 2);
        }
        $customerId = (int)($in['customer_id'] ?? 0);
        if (!$customerId) throw new AppError('Selecione o cliente da venda');
        $customer = Customers::get($customerId);
        if (!$customer['active']) throw new AppError('O cliente "' . $customer['name'] . '" está inativo');
        $at = now();

        return DB::tx(function () use ($items, $method, $discount, $surcharge, $extras, $in, $userId, $at, $customer, $instN, $instDays, $instEntry) {
            $lines = [];
            $subtotal = 0.0;
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $q = self::num($it['quantity'] ?? 0);
                if ($q <= 0) throw new AppError('Quantidade inválida em um dos itens');
                $p = self::product($pid);
                if ($p['deleted_at']) throw new AppError('"' . $p['name'] . '" foi excluído do cadastro. Tire este item para continuar.');
                if (!$p['active']) throw new AppError('"' . $p['name'] . '" está inativo');
                $price = isset($it['unit_price']) && $it['unit_price'] !== '' ? self::num($it['unit_price']) : (float)$p['sale_price'];
                if ($price < 0) throw new AppError('Preço inválido');
                $total = round($price * $q, 2);
                $subtotal += $total;
                $lines[] = [$p, $q, round($price, 2), $total];
            }
            if ($discount > $subtotal + $surcharge + 0.001) throw new AppError('O desconto é maior que o valor da venda');
            // Configurações → Vendas: desconto máximo para quem não é administrador (venda direta; o orçamento já foi aprovado)
            $maxDisc = (float)Settings::get('sale_max_discount');
            if ($maxDisc > 0 && $discount > 0 && empty($in['quote_id']) && !self::isAdminUser($userId)) {
                $pct = $discount / max(0.01, $subtotal + $surcharge) * 100;
                if ($pct > $maxDisc + 0.005) {
                    throw new AppError(sprintf('O desconto máximo permitido é de %s%% (R$ %s nesta venda). Peça a um administrador.',
                        rtrim(rtrim(number_format($maxDisc, 2, ',', ''), '0'), ','), number_format(($subtotal + $surcharge) * $maxDisc / 100, 2, ',', '.')));
                }
            }
            $plan = PaymentMethods::plan($instN, $instDays, $subtotal + $surcharge - $discount, substr($at, 0, 10),
                $in['installment_plan'] ?? null, PaymentMethods::freeInterval($method), $instEntry);
            $saleId = DB::insert('sales', [
                'customer_id' => (int)$customer['id'],
                'customer' => mb_substr($customer['trade_name'] ?: $customer['name'], 0, 160),
                'payment_method' => $method,
                'installments' => $instN,
                'installment_days' => $instDays,
                'installment_plan' => $plan ? json_encode($plan) : null,
                'installment_entry' => $plan && $instEntry ? 1 : 0,
                'payment_terms' => Inventory::nullable(mb_substr(trim((string)($in['payment_terms'] ?? '')), 0, 160)),
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'surcharge' => $surcharge,
                'extras' => $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null,
                'total' => round($subtotal - $discount + $surcharge, 2),
                'quote_id' => !empty($in['quote_id']) ? (int)$in['quote_id'] : null,
                'cost_total' => 0,
                'note' => ($n = trim((string)($in['note'] ?? ''))) === '' ? null : mb_substr($n, 0, self::SALE_NOTE_MAX),
                'status' => 'ok',
                'user_id' => $userId,
                'created_at' => $at,
            ]);
            $costTotal = 0.0;
            $ctx = ['user_id' => $userId, 'sale_id' => $saleId, 'note' => 'Venda #' . $saleId, 'at' => $at];
            // Configurações → Vendas: "Deixar vender sem estoque" (o saldo fica negativo)
            if (Settings::get('sale_negative_stock') === '1') $ctx['allow_negative'] = true;
            foreach ($lines as [$p, $q, $price, $total]) {
                $pid = (int)$p['id'];
                if ($p['type'] === 'servico') {
                    // Serviço: não mexe no estoque; custo informado no cadastro (se houver)
                    $unitCost = (float)$p['avg_cost'];
                } else {
                    $r = self::move($pid, 'venda', -$q, null, $ctx);
                    $unitCost = $r['unit_cost'];
                }
                $costTotal += $unitCost * $q;
                DB::insert('sale_items', [
                    'sale_id' => $saleId, 'product_id' => $pid, 'quantity' => $q,
                    'unit_price' => $price, 'unit_cost' => round($unitCost, 6), 'total' => $total,
                ]);
            }
            DB::update('sales', $saleId, ['cost_total' => round($costTotal, 6)]);
            Finance::fromSale($saleId);
            return $saleId;
        });
    }

    private static function isAdminUser(int $userId): bool
    {
        return DB::val('SELECT role FROM users WHERE id = ?', [$userId]) === 'admin';
    }

    /** Configurações → Vendas: quem pode cancelar e até quantos dias depois da venda (administrador sempre pode). */
    public static function checkCancelRules(array $sale, int $userId): void
    {
        if (self::isAdminUser($userId)) return;
        if (Settings::get('sale_cancel_admin') === '1') throw new AppError('Só administradores podem cancelar vendas', 403);
        $days = (int)Settings::get('sale_cancel_days');
        if ($days > 0 && substr($sale['created_at'], 0, 10) < date('Y-m-d', strtotime("-$days days"))) {
            throw new AppError(sprintf('Vendas só podem ser canceladas até %d dia%s depois. Peça a um administrador.', $days, $days > 1 ? 's' : ''), 403);
        }
    }

    public static function cancelSale(int $saleId, int $userId): void
    {
        DB::tx(function () use ($saleId, $userId) {
            $s = DB::one('SELECT * FROM sales WHERE id = ?' . DB::lockSuffix(), [$saleId]);
            if (!$s) throw new AppError('Venda não encontrada', 404);
            if ($s['status'] === 'cancelada') throw new AppError('Esta venda já foi cancelada');
            self::checkCancelRules($s, $userId);
            // 'consumo_venda' = vendas antigas de produto feito na hora (recurso removido)
            $movs = DB::all("SELECT * FROM movements WHERE sale_id = ? AND type IN ('venda','consumo_venda')", [$saleId]);
            foreach ($movs as $m) {
                self::move((int)$m['product_id'], 'estorno_venda', -(float)$m['quantity'], (float)$m['unit_cost'], [
                    'user_id' => $userId, 'sale_id' => $saleId, 'note' => 'Cancelamento da venda #' . $saleId,
                    'allow_inactive' => true,
                ]);
            }
            DB::update('sales', $saleId, ['status' => 'cancelada', 'canceled_by' => $userId, 'canceled_at' => now()]);
            Finance::cancelSale($saleId);
        });
    }

    // ---------- Utilidades ----------

    /** Aceita "1.234,56", "1234,56", "1234.56" e números. */
    public static function num($v): float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = trim((string)$v);
        if ($s === '') return 0.0;
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        // "2.000" ou "1.250.000" sem vírgula: ponto é separador de milhar
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float)$s : 0.0;
    }

    public static function nullable($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    public static function fmtQty(float $q): string
    {
        $s = number_format($q, 3, ',', '.');
        return rtrim(rtrim($s, '0'), ',');
    }
}
