<?php
/**
 * API do sistema. Todas as telas conversam com este arquivo: api.php?r=<rota>
 */

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function input(): array
{
    static $data = null;
    if ($data !== null) return $data;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
    } else {
        $data = $_POST;
    }
    return $data;
}

function out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function q(string $k, $default = null) { return $_GET[$k] ?? $default; }

function page(): array
{
    $limit = min(200, max(1, (int)q('limit', 50)));
    $p = max(1, (int)q('page', 1));
    return [$limit, ($p - 1) * $limit];
}

try {
    if (!app_connect()) out(['error' => 'Sistema não instalado. Abra install.php.', 'install' => true], 503);
    Auth::start();

    $r = (string)q('r', '');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST') Auth::checkCsrf();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $routes = [
        // ---- Sessão ----
        'GET me' => function () {
            $u = Auth::withAvatar(Auth::user());
            $r = ['user' => $u, 'csrf' => Auth::csrf(), 'version' => APP_VERSION, 'perms' => Auth::PERMS];
            // Tela de login: nome e logo da empresa (só quando ninguém está logado)
            if (!$u) $r['brand'] = ['name' => Settings::get('company_name'), 'logo' => Settings::get('company_logo')];
            return $r;
        },
        'POST login' => function () use ($ip) {
            $u = Auth::login((string)(input()['email'] ?? ''), (string)(input()['password'] ?? ''), $ip);
            return ['user' => Auth::withAvatar($u), 'csrf' => Auth::csrf()];
        },
        'POST logout' => function () { Auth::logout(); return ['ok' => true]; },
        'POST password' => function () {
            $u = Auth::require();
            $in = input();
            $row = DB::one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
            if (!password_verify((string)($in['current'] ?? ''), $row['password_hash'])) throw new AppError('Senha atual incorreta');
            Auth::checkPassword((string)($in['new'] ?? ''));
            DB::update('users', $u['id'], ['password_hash' => password_hash((string)$in['new'], PASSWORD_DEFAULT)]);
            return ['ok' => true];
        },

        'POST account' => function () {
            $u = Auth::require();
            $in = input();
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') throw new AppError('Informe o nome');
            DB::update('users', $u['id'], ['name' => mb_substr($name, 0, 120), 'phone' => Auth::phone($in['phone'] ?? null)]);
            return ['ok' => true];
        },

        'POST account_avatar' => function () {
            $u = Auth::require();
            return ['avatar' => Auth::setAvatar($u['id'], (string)(input()['avatar'] ?? ''))];
        },
        'POST user_avatar' => function () {
            $me = Auth::requireAdmin();
            $in = input();
            $u = DB::one('SELECT * FROM users WHERE id = ?', [(int)($in['id'] ?? 0)]);
            if (!$u) throw new AppError('Usuário não encontrado', 404);
            Auth::assertCanEdit($u, $me);
            return ['avatar' => Auth::setAvatar((int)$u['id'], (string)($in['avatar'] ?? ''))];
        },
        'POST account_accent' => function () {
            $u = Auth::require();
            return ['accent_color' => Auth::setAccent($u['id'], (string)(input()['accent_color'] ?? ''))];
        },

        // ---- Painel e dados auxiliares ----
        'GET dashboard' => function () {
            Auth::require();
            $d = Reports::dashboard();
            if (!Auth::can('relatorios')) { unset($d['today'], $d['month'], $d['month_days']); $d['stock']['value'] = null; }
            if (Auth::can('financeiro')) $d['finance'] = Finance::summary();
            $me = Auth::user();
            if (Auth::can('ordens')) $d['orders'] = ServiceOrders::dashboard((int)$me['id']);
            if (Auth::can('orcamentos')) {
                $d['quotes'] = [
                    'approved' => (int)DB::val("SELECT COUNT(*) FROM quotes WHERE status = 'aprovado'"),
                    'approved_total' => (float)DB::val("SELECT COALESCE(SUM(total),0) FROM quotes WHERE status = 'aprovado'"),
                    'from_os' => (int)DB::val("SELECT COUNT(*) FROM quotes WHERE service_order_id IS NOT NULL AND created_at >= ?", [date('Y-m-01') . ' 00:00:00']),
                ];
            }
            if (Auth::can('compras')) {
                $d['purchases'] = [
                    'open' => (int)DB::val("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('rascunho','enviada','confirmada')"),
                    'late' => (int)DB::val("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('enviada','confirmada') AND expected_date < ?", [date('Y-m-d')]),
                ];
            }
            return $d;
        },
        'GET meta' => function () {
            Auth::require();
            return [
                'categories' => array_column(DB::all('SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category'), 'category'),
                'fiscal' => Fiscal::options(),
                'brands' => array_column(DB::all('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND deleted_at IS NULL ORDER BY brand'), 'brand'),
                'company' => ['name' => Settings::get('company_name'), 'logo' => Settings::get('company_logo')],
                'units' => ['un', 'kg', 'g', 'L', 'ml', 'cx', 'pct', 'dz', 'm', 'fardo'],
                'payment_methods' => PaymentMethods::active(),
                'payment_labels' => PaymentMethods::labels(),
                'payment_rules' => PaymentMethods::rules(),
                'payment_terms' => PaymentTerms::forForms(),
                'quote_statuses' => QuoteStatuses::forForms(),
                'movement_labels' => Inventory::MOVEMENT_LABELS,
                'behavior' => Settings::behavior(),
            ];
        },

        // ---- Produtos ----
        'GET products' => function () {
            Auth::require();
            $w = ['1=1']; $p = [];
            if (($s = trim((string)q('q', ''))) !== '') {
                [$sql, $sp] = Inventory::searchSql($s);
                $w[] = $sql; array_push($p, ...$sp);
            }
            if (in_array(q('type'), ['produto', 'servico'], true)) { $w[] = 'type = ?'; $p[] = q('type'); }
            $w[] = 'deleted_at IS NULL';
            if (q('active', '1') !== 'all') { $w[] = 'active = ?'; $p[] = q('active', '1') === '0' ? 0 : 1; }
            if (q('low') === '1') $w[] = "type <> 'servico' AND min_stock > 0 AND stock <= min_stock";
            if (q('no_services') === '1') $w[] = "type <> 'servico'";
            if (($c = q('category')) !== null && $c !== '') { $w[] = 'category = ?'; $p[] = $c; }
            // Lista da tela: até 1000 (use a busca). all=1: todos, para as buscas de produto no navegador (sem os campos longos)
            $all = q('all') === '1';
            $rows = DB::all('SELECT p.* FROM products p WHERE ' . implode(' AND ', $w) . ' ORDER BY name' . ($all ? '' : ' LIMIT 1000'), $p);
            if ($all) foreach ($rows as &$r0) { unset($r0['description'], $r0['fiscal']); } unset($r0);
            // Códigos das referências cruzadas (para as buscas feitas no navegador)
            $map = [];
            foreach (DB::all('SELECT product_id, code, code_norm FROM product_refs') as $r) $map[$r['product_id']][] = $r;
            foreach ($rows as &$row) {
                $rs = $map[$row['id']] ?? [];
                $row['refs'] = implode(' ', array_column($rs, 'code'));
                $row['refs_norm'] = implode(' ', array_column($rs, 'code_norm'));
            }
            return $rows;
        },
        'GET product' => function () {
            Auth::require();
            $id = (int)q('id');
            $p = Inventory::product($id);
            $p['unit_cost'] = round(Inventory::currentUnitCost($p), 6);
            $p['fiscal'] = Fiscal::decode($p['fiscal'] ?? null) ?: new stdClass();
            $p['refs'] = Inventory::refs($id);
            $p['movements'] = DB::all('SELECT m.*, u.name user_name FROM movements m LEFT JOIN users u ON u.id = m.user_id
                WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 30', [$id]);
            return $p;
        },
        // Importação de produtos por planilha: 1) prévia (lê o arquivo e confere) 2) cadastro em lotes
        'POST products_import_preview' => function () {
            Auth::requirePerm('produtos');
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $err = $f['error'] ?? null;
                throw new AppError($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'Planilha grande demais para a hospedagem' : 'Envie a planilha (.xlsx ou .csv)');
            }
            if ($f['size'] > 20 * 1024 * 1024) throw new AppError('Planilha grande demais (máx. 20 MB)');
            @set_time_limit(120);
            return ProductImport::preview(ProductImport::read((string)file_get_contents($f['tmp_name']), (string)$f['name']));
        },
        'POST products_import' => function () {
            Auth::requirePerm('produtos');
            @set_time_limit(120);
            $rows = input()['rows'] ?? [];
            if (!is_array($rows)) throw new AppError('Linhas inválidas');
            return ProductImport::import($rows, Auth::user()['id'] ?? null);
        },
        'GET products_imports' => function () {
            Auth::requireAdmin();
            return ProductImport::history();
        },
        'POST products_import_undo' => function () {
            Auth::requireAdmin();
            @set_time_limit(120);
            return ProductImport::undo((string)(input()['day'] ?? ''));
        },
        'POST product_delete' => function () {
            Auth::requireAdmin();
            return ['result' => Inventory::deleteProduct((int)(input()['id'] ?? 0))];
        },
        'POST product_save' => function () {
            $in = input();
            $isService = ($in['type'] ?? '') === 'servico'
                || (!empty($in['id']) && DB::val('SELECT type FROM products WHERE id = ?', [(int)$in['id']]) === 'servico');
            Auth::requirePerm($isService ? 'servicos' : 'produtos');
            $id = !empty($in['id']) ? (int)$in['id'] : null;
            if (isset($in['active']) && !$in['active']) Auth::requireAdmin();
            return ['id' => Inventory::saveProduct($in, $id, Auth::user()['id'] ?? null)];
        },

        // ---- Movimentações ----
        'GET movements' => function () {
            Auth::requirePerm('movimentacoes', 'produtos');
            [$limit, $off] = page();
            $w = ['1=1']; $p = [];
            if ($pid = (int)q('product_id', 0)) { $w[] = 'm.product_id = ?'; $p[] = $pid; }
            if (($t = q('type')) && isset(Inventory::MOVEMENT_LABELS[$t])) { $w[] = 'm.type = ?'; $p[] = $t; }
            [$a, $b] = Reports::range(q('from'), q('to'));
            if (q('from') || q('to')) { $w[] = 'm.created_at BETWEEN ? AND ?'; array_push($p, $a, $b); }
            $where = implode(' AND ', $w);
            $total = (int)DB::val("SELECT COUNT(*) FROM movements m WHERE $where", $p);
            $rows = DB::all("SELECT m.*, p.name product_name, p.unit, u.name user_name FROM movements m
                JOIN products p ON p.id = m.product_id LEFT JOIN users u ON u.id = m.user_id
                WHERE $where ORDER BY m.id DESC LIMIT $limit OFFSET $off", $p);
            return ['rows' => $rows, 'total' => $total];
        },
        'POST movement' => function () {
            $u = Auth::requirePerm('movimentacoes');
            return Inventory::manualMove(input(), $u['id']);
        },

        // ---- Vendas ----
        'GET sales' => function () {
            Auth::requirePerm('vendas');
            [$limit, $off] = page();
            $w = ['1=1']; $p = [];
            Reports::listFilters($_GET, $w, $p, ['created' => 's.created_at', 'total' => 's.total', 'user' => 's.user_id',
                'names' => ['s.customer', 'k.name', 'k.trade_name'], 'doc' => 'k.document']);
            if (in_array(q('status'), ['ok', 'cancelada'], true)) { $w[] = 's.status = ?'; $p[] = q('status'); }
            if ($cid = (int)q('customer_id', 0)) { $w[] = 's.customer_id = ?'; $p[] = $cid; }
            $where = implode(' AND ', $w);
            $from = "FROM sales s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN customers k ON k.id = s.customer_id WHERE $where";
            $total = (int)DB::val("SELECT COUNT(*) $from", $p);
            $sum = (float)DB::val("SELECT COALESCE(SUM(CASE WHEN s.status = 'ok' THEN s.total ELSE 0 END), 0) $from", $p);
            $rows = DB::all("SELECT s.*, u.name user_name,
                (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.id) items
                $from ORDER BY s.id DESC LIMIT $limit OFFSET $off", $p);
            return ['rows' => $rows, 'total' => $total, 'sum' => round($sum, 2)];
        },
        'GET sale' => function () {
            Auth::requirePerm('vendas', 'clientes', 'orcamentos');
            $s = DB::one('SELECT s.*, u.name user_name, c.name canceled_by_name, k.name customer_name, k.trade_name customer_trade_name,
                k.document customer_document, k.phone customer_phone, k.city customer_city, k.state customer_state FROM sales s
                LEFT JOIN users u ON u.id = s.user_id LEFT JOIN users c ON c.id = s.canceled_by
                LEFT JOIN customers k ON k.id = s.customer_id WHERE s.id = ?', [(int)q('id')]);
            if (!$s) throw new AppError('Venda não encontrada', 404);
            $s['items'] = DB::all('SELECT i.*, p.name, p.unit, p.type FROM sale_items i JOIN products p ON p.id = i.product_id WHERE i.sale_id = ?', [$s['id']]);
            $s['extras'] = json_decode((string)($s['extras'] ?? ''), true) ?: [];
            return $s;
        },
        'POST sale_create' => function () {
            $u = Auth::requirePerm('vendas');
            return ['id' => Inventory::createSale(input(), $u['id'])];
        },
        'POST sale_cancel' => function () {
            $u = Auth::requirePerm('vendas');
            Inventory::cancelSale((int)(input()['id'] ?? 0), $u['id']);
            return ['ok' => true];
        },

        // ---- Notas fiscais ----
        'POST invoice_parse' => function () {
            Auth::requirePerm('notas');
            $in = input();
            if (!empty($in['qr'])) {
                $inv = Invoices::fetchNfce((string)$in['qr']);
            } else {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                    $err = $f['error'] ?? null;
                    throw new AppError($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                        ? 'Arquivo grande demais para a hospedagem' : 'Envie o arquivo da nota');
                }
                if ($f['size'] > 15 * 1024 * 1024) throw new AppError('Arquivo grande demais (máx. 15 MB)');
                $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 1024);
                if (str_starts_with($head, '%PDF')) {
                    $inv = Invoices::parseDanfePdf($f['tmp_name']);
                } elseif (str_contains($head, '<')) {
                    $inv = Invoices::parseXml((string)file_get_contents($f['tmp_name']));
                } else {
                    throw new AppError('Formato não reconhecido. Envie o XML da NF-e ou o PDF do DANFE.');
                }
            }
            return Invoices::suggest($inv);
        },
        'POST invoice_confirm' => function () {
            $u = Auth::requirePerm('notas');
            return ['id' => Invoices::confirm(input(), $u['id'])];
        },
        'GET invoices' => function () {
            Auth::requirePerm('notas', 'fornecedores');
            [$limit, $off] = page();
            $total = (int)DB::val('SELECT COUNT(*) FROM invoices');
            $rows = DB::all("SELECT i.*, u.name user_name, (SELECT COUNT(*) FROM invoice_items x WHERE x.invoice_id = i.id) items
                FROM invoices i LEFT JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT $limit OFFSET $off");
            return ['rows' => $rows, 'total' => $total];
        },
        'GET invoice' => function () {
            Auth::requirePerm('notas', 'fornecedores');
            $i = DB::one('SELECT i.*, u.name user_name FROM invoices i LEFT JOIN users u ON u.id = i.user_id WHERE i.id = ?', [(int)q('id')]);
            if (!$i) throw new AppError('Nota não encontrada', 404);
            $i['items'] = DB::all('SELECT x.*, p.name product_name, p.unit FROM invoice_items x JOIN products p ON p.id = x.product_id WHERE x.invoice_id = ?', [$i['id']]);
            $i['payables'] = DB::all('SELECT id, description, amount, due_date, status, paid_at, paid_amount, installment, installments FROM financial_entries WHERE invoice_id = ? ORDER BY due_date, id', [$i['id']]);
            $today = date('Y-m-d');
            foreach ($i['payables'] as &$pp) $pp['display_status'] = $pp['status'] === 'aberto' && $pp['due_date'] < $today ? 'vencido' : $pp['status'];
            unset($pp);
            return $i;
        },
        'POST invoice_revert' => function () {
            $u = Auth::requireAdmin();
            Invoices::revert((int)(input()['id'] ?? 0), $u['id']);
            return ['ok' => true];
        },

        // ---- Clientes ----
        'GET customers' => function () {
            Auth::require();
            [$limit, $off] = page();
            $w = ['deleted_at IS NULL']; $p = [];
            if (($s = trim((string)q('q', ''))) !== '') {
                $digits = preg_replace('/\D/', '', $s);
                $like = '%' . mb_strtolower($s) . '%';
                $cond = '(LOWER(name) LIKE ? OR LOWER(trade_name) LIKE ? OR LOWER(city) LIKE ? OR LOWER(email) LIKE ?';
                array_push($p, $like, $like, $like, $like);
                if (strlen($digits) >= 3) { $cond .= ' OR document LIKE ? OR phone LIKE ?'; array_push($p, '%' . $digits . '%', '%' . $s . '%'); }
                $w[] = $cond . ')';
            }
            if (q('active', '1') !== 'all') { $w[] = 'active = ?'; $p[] = q('active', '1') === '0' ? 0 : 1; }
            $where = implode(' AND ', $w);
            $total = (int)DB::val("SELECT COUNT(*) FROM customers WHERE $where", $p);
            $order = q('sort') === 'desc' ? 'DESC' : 'ASC';
            $rows = DB::all("SELECT c.*,
                (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id AND s.status = 'ok') sales_count,
                (SELECT MAX(created_at) FROM sales s WHERE s.customer_id = c.id AND s.status = 'ok') last_sale,
                (SELECT COUNT(*) FROM portal_requests r WHERE r.customer_id = c.id AND r.resolved_at IS NULL) portal_open
                FROM customers c WHERE $where ORDER BY (name = 'Consumidor final') DESC, name $order LIMIT $limit OFFSET $off", $p);
            return ['rows' => $rows, 'total' => $total];
        },
        'GET customer' => function () {
            Auth::requirePerm('clientes', 'vendas', 'orcamentos', 'financeiro');
            $c = Customers::get((int)q('id'));
            $c['stats'] = DB::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(total - cost_total),0) profit,
                MAX(created_at) last_sale, MIN(created_at) first_sale FROM sales WHERE customer_id = ? AND status = 'ok'", [$c['id']]);
            $c['sales'] = DB::all('SELECT s.*, u.name user_name, (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.id) items
                FROM sales s LEFT JOIN users u ON u.id = s.user_id WHERE s.customer_id = ? ORDER BY s.id DESC LIMIT 50', [$c['id']]);
            $c['top_products'] = DB::all("SELECT p.id, p.name, p.unit, SUM(i.quantity) qty, SUM(i.total) total FROM sale_items i
                JOIN sales s ON s.id = i.sale_id JOIN products p ON p.id = i.product_id
                WHERE s.customer_id = ? AND s.status = 'ok' GROUP BY p.id, p.name, p.unit ORDER BY total DESC LIMIT 5", [$c['id']]);
            if (Auth::can('clientes')) $c['portal'] = Portal::status((int)$c['id']);
            return $c;
        },
        // ---- Portal do cliente (cliente/) ----
        'POST customer_portal_invite' => function () {
            Auth::requirePerm('clientes');
            return Portal::invite((int)(input()['id'] ?? 0));
        },
        'POST customer_portal_revoke' => function () {
            Auth::requirePerm('clientes');
            Portal::revoke((int)(input()['id'] ?? 0));
            return ['ok' => true];
        },
        'POST portal_request_done' => function () {
            $me = Auth::requirePerm('clientes');
            Portal::resolveRequest((int)(input()['id'] ?? 0), (int)$me['id']);
            return ['ok' => true];
        },
        'POST customer_save' => function () {
            Auth::requirePerm('clientes');
            $in = input();
            $id = !empty($in['id']) ? (int)$in['id'] : null;
            if (isset($in['active']) && !$in['active']) Auth::requireAdmin();
            return ['id' => Customers::save($in, $id)];
        },
        'POST customer_delete' => function () {
            Auth::requireAdmin();
            return ['result' => Removals::delete('customers', (int)(input()['id'] ?? 0))];
        },
        'GET cnpj' => function () { Auth::require(); return Customers::lookupCnpj((string)q('cnpj', '')); },
        'GET cep' => function () { Auth::require(); return Customers::lookupCep((string)q('cep', '')); },

        // ---- Fornecedores ----
        'GET suppliers' => function () {
            Auth::requirePerm('fornecedores', 'notas', 'compras', 'financeiro');
            [$limit, $off] = page();
            $w = ['deleted_at IS NULL']; $p = [];
            if (($s = trim((string)q('q', ''))) !== '') {
                $digits = preg_replace('/\D/', '', $s);
                $like = '%' . mb_strtolower($s) . '%';
                $cond = '(LOWER(name) LIKE ? OR LOWER(trade_name) LIKE ? OR LOWER(city) LIKE ? OR LOWER(email) LIKE ?';
                array_push($p, $like, $like, $like, $like);
                if (strlen($digits) >= 3) { $cond .= ' OR document LIKE ?'; $p[] = '%' . $digits . '%'; }
                $w[] = $cond . ')';
            }
            if (q('active', '1') !== 'all') { $w[] = 'active = ?'; $p[] = q('active', '1') === '0' ? 0 : 1; }
            $where = implode(' AND ', $w);
            $total = (int)DB::val("SELECT COUNT(*) FROM suppliers WHERE $where", $p);
            $rows = DB::all("SELECT s.*,
                (SELECT COUNT(*) FROM invoices i WHERE i.supplier_id = s.id) invoices_count,
                (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.supplier_id = s.id) purchased,
                (SELECT MAX(created_at) FROM invoices i WHERE i.supplier_id = s.id) last_purchase
                FROM suppliers s WHERE $where ORDER BY name LIMIT $limit OFFSET $off", $p);
            return ['rows' => $rows, 'total' => $total];
        },
        'GET supplier' => function () {
            Auth::requirePerm('fornecedores', 'compras');
            $c = Customers::get((int)q('id'), 'suppliers');
            $c['stats'] = DB::one('SELECT COUNT(*) n, COALESCE(SUM(total),0) total, MAX(created_at) last_purchase, MIN(created_at) first_purchase
                FROM invoices WHERE supplier_id = ?', [$c['id']]);
            $c['invoices'] = DB::all('SELECT i.*, u.name user_name, (SELECT COUNT(*) FROM invoice_items x WHERE x.invoice_id = i.id) items
                FROM invoices i LEFT JOIN users u ON u.id = i.user_id WHERE i.supplier_id = ? ORDER BY i.id DESC LIMIT 50', [$c['id']]);
            $c['products'] = DB::all('SELECT p.id, p.name, p.unit, SUM(x.stock_qty) qty, SUM(x.total) total, MAX(i.created_at) last_at,
                (SELECT x2.unit_cost FROM invoice_items x2 JOIN invoices i2 ON i2.id = x2.invoice_id WHERE x2.product_id = p.id AND i2.supplier_id = ? ORDER BY x2.id DESC LIMIT 1) last_cost
                FROM invoice_items x JOIN invoices i ON i.id = x.invoice_id JOIN products p ON p.id = x.product_id
                WHERE i.supplier_id = ? GROUP BY p.id, p.name, p.unit ORDER BY total DESC LIMIT 20', [$c['id'], $c['id']]);
            return $c;
        },
        'POST supplier_save' => function () {
            Auth::requirePerm('fornecedores');
            $in = input();
            $id = !empty($in['id']) ? (int)$in['id'] : null;
            if (isset($in['active']) && !$in['active']) Auth::requireAdmin();
            return ['id' => Customers::save($in, $id, 'suppliers')];
        },

        'POST supplier_delete' => function () {
            Auth::requireAdmin();
            return ['result' => Removals::delete('suppliers', (int)(input()['id'] ?? 0))];
        },

        // ---- Orçamentos ----
        'GET quotes' => function () {
            Auth::requirePerm('orcamentos');
            $limit = min(500, max(1, (int)q('limit', 200))); $off = (max(1, (int)q('page', 1)) - 1) * $limit;
            $w = ['1=1']; $p = [];
            if (($s = trim((string)q('q', ''))) !== '') {
                $like = '%' . mb_strtolower($s) . '%';
                $w[] = '(LOWER(c.name) LIKE ? OR LOWER(c.trade_name) LIKE ? OR LOWER(q.lead_name) LIKE ? OR LOWER(q.external_code) LIKE ? OR q.id = ?)';
                array_push($p, $like, $like, $like, $like, (int)ltrim($s, '#'));
            }
            if (($st = q('status')) && isset(QuoteStatuses::map()[$st])) { $w[] = 'q.status = ?'; $p[] = $st; }
            Reports::listFilters($_GET, $w, $p, ['created' => 'q.created_at', 'total' => 'q.total', 'user' => 'q.user_id',
                'names' => ['c.name', 'c.trade_name', 'q.lead_name'], 'doc' => 'c.document']);
            // Atalhos dos indicadores do topo (mês atual)
            [$m1, $m2] = Quotes::monthRange();
            if (q('expiring') === '1') { $w[] = Quotes::openSql('q.status') . ' AND q.expires_at BETWEEN ? AND ?'; array_push($p, $m1, $m2); }
            $mk = ['refused' => "q.status = 'recusado' AND q.refused_at", 'approved' => "q.status IN ('aprovado','vendido') AND q.approved_at", 'sold' => "q.status = 'vendido' AND q.status_changed_at"][q('month', '')] ?? null;
            if ($mk) { $w[] = "$mk BETWEEN ? AND ?"; array_push($p, $m1 . ' 00:00:00', $m2 . ' 23:59:59'); }
            if (q('pending') === '1') { $w[] = "q.status IN ('rascunho','em_elaboracao','aguardando_envio')"; }
            if (q('hide_old') === '1') { $w[] = "(q.status NOT IN ('recusado','vendido') OR q.status_changed_at >= ?)"; $p[] = date('Y-m-d H:i:s', strtotime('-60 days')); }
            $where = implode(' AND ', $w);
            $from = "FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN users u ON u.id = q.user_id WHERE $where";
            $total = (int)DB::val("SELECT COUNT(*) $from", $p);
            $sum = (float)DB::val("SELECT COALESCE(SUM(q.total),0) $from", $p);
            $rows = DB::all("SELECT q.id, q.status, q.customer_id, q.lead_name, q.total, q.request_date, q.expires_at, q.external_code,
                q.sale_id, q.user_id, q.updated_at, u.name user_name, c.name customer_name, c.trade_name customer_trade_name,
                (SELECT COUNT(*) FROM quote_items i WHERE i.quote_id = q.id) items
                $from ORDER BY q.id DESC LIMIT $limit OFFSET $off", $p);
            return ['rows' => $rows, 'total' => $total, 'sum' => round($sum, 2), 'summary' => Quotes::summary(),
                'statuses' => QuoteStatuses::map(), 'today' => date('Y-m-d')];
        },
        'GET quote' => function () {
            Auth::requirePerm('orcamentos');
            $q = Quotes::get((int)q('id'));
            $q['public_url'] = app_base_url() . 'orcamento.php?t=' . $q['public_token'];
            return $q;
        },
        'GET quote_defaults' => function () {
            $u = Auth::requirePerm('orcamentos');
            $days = (int)Settings::get('quote_validity_days');
            return ['request_date' => date('Y-m-d'), 'expires_at' => $days ? date('Y-m-d', strtotime("+$days days")) : null,
                'user_id' => $u['id'], 'notes' => Settings::get('quote_default_notes'), 'statuses' => QuoteStatuses::map()];
        },
        'POST quote_save' => function () {
            $u = Auth::requirePerm('orcamentos');
            $in = input();
            return ['id' => Quotes::save($in, $u['id'], !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST quote_status' => function () {
            Auth::requirePerm('orcamentos');
            $in = input();
            Quotes::setStatus((int)($in['id'] ?? 0), (string)($in['status'] ?? ''));
            return ['ok' => true];
        },
        'POST quote_convert' => function () {
            Auth::requirePerm('orcamentos');
            $u = Auth::requirePerm('vendas');
            $in = input();
            return ['sale_id' => Quotes::convert((int)($in['id'] ?? 0), (string)($in['payment_method'] ?? ''), $u['id'],
                isset($in['installments']) ? (int)$in['installments'] : null, isset($in['installment_days']) ? (int)$in['installment_days'] : null,
                $in['installment_plan'] ?? null, isset($in['installment_entry']) ? !empty($in['installment_entry']) : null)];
        },
        'POST quotes_bulk' => function () {
            Auth::requirePerm('orcamentos');
            $in = input();
            return Quotes::bulk((array)($in['ids'] ?? []), (string)($in['action'] ?? ''), isset($in['status']) ? (string)$in['status'] : null);
        },
        'GET quote_pdf' => function () {
            Auth::requirePerm('orcamentos');
            $q = Quotes::get((int)q('id', 0));
            $pdf = QuotePdf::render($q, Settings::all());
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . QuotePdf::fileName($q) . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        },
        // Vários orçamentos em PDF, um arquivo por orçamento, num .zip
        'GET quotes_pdf_zip' => function () {
            Auth::requirePerm('orcamentos');
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)q('ids', ''))))));
            if (!$ids) throw new AppError('Selecione pelo menos um orçamento');
            if (count($ids) > Quotes::BULK_MAX) throw new AppError('Selecione no máximo ' . Quotes::BULK_MAX . ' orçamentos por vez');
            @set_time_limit(120);
            $co = Settings::all(); $zip = new ZipWriter(); $used = [];
            foreach ($ids as $id) {
                $q = Quotes::get($id);
                $name = QuotePdf::fileName($q);
                if (isset($used[$name])) $name = preg_replace('/\.pdf$/', '-' . (++$used[$name]) . '.pdf', $name); else $used[$name] = 1;
                $zip->add($name, QuotePdf::render($q, $co));
            }
            $data = $zip->output();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="orcamentos-' . date('Y-m-d-His') . '.zip"');
            header('Content-Length: ' . strlen($data));
            echo $data;
            exit;
        },
        'POST quote_mark' => function () {
            Auth::requirePerm('orcamentos');
            $in = input();
            return ['status' => Quotes::mark((int)($in['id'] ?? 0), (string)($in['action'] ?? ''))];
        },
        'POST quote_delete' => function () {
            Auth::requirePerm('orcamentos');
            Quotes::delete((int)(input()['id'] ?? 0));
            return ['ok' => true];
        },
        'GET quote_behavior' => function () {
            Auth::requireAdmin();
            return ['quote_validity_days' => Settings::get('quote_validity_days'), 'quote_default_notes' => Settings::get('quote_default_notes'),
                'quote_on_sent' => Settings::get('quote_on_sent'), 'quote_on_approved' => Settings::get('quote_on_approved'), 'quote_on_refused' => Settings::get('quote_on_refused'),
                'statuses' => array_intersect_key(QuoteStatuses::map(), array_flip(QuoteStatuses::selectable()))];
        },
        'POST quote_behavior' => function () {
            Auth::requireAdmin();
            $in = input();
            $ok = QuoteStatuses::selectable();
            $data = [];
            foreach (['quote_on_sent', 'quote_on_approved', 'quote_on_refused'] as $k) {
                if (isset($in[$k])) {
                    if (!in_array($in[$k], $ok, true)) throw new AppError('Status inválido');
                    $data[$k] = $in[$k];
                }
            }
            foreach (['quote_validity_days', 'quote_default_notes'] as $k) if (array_key_exists($k, $in)) $data[$k] = (string)$in[$k];
            Settings::save($data);
            return ['ok' => true];
        },
        'POST quote_duplicate' => function () {
            $u = Auth::requirePerm('orcamentos');
            return ['id' => Quotes::duplicate((int)(input()['id'] ?? 0), $u['id'])];
        },
        // ---- Ordens de serviço ----
        'GET agenda' => function () {
            Auth::requirePerm('ordens');
            return ServiceOrders::agenda(['from' => q('from'), 'to' => q('to'), 'tech' => q('tech'), 'task_type_id' => q('task_type_id'), 'status' => q('status'), 'q' => q('q'), 'late' => q('late') === '1']);
        },
        'POST agenda_move' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            ServiceOrders::reschedule((int)($in['id'] ?? 0), (string)($in['scheduled_at'] ?? ''), $in['technician_id'] ?? null, (int)$u['id']);
            return ['ok' => true];
        },
        'GET service_orders' => function () {
            Auth::requirePerm('ordens');
            return ['rows' => ServiceOrders::list($_GET), 'statuses' => ServiceOrders::STATUSES, 'types' => TaskTypes::names()];
        },
        'GET service_order' => function () {
            Auth::requirePerm('ordens');
            return ServiceOrders::get((int)q('id'));
        },
        'GET service_order_form' => function () {
            Auth::requirePerm('ordens');
            return ServiceOrders::formData((int)q('customer_id', 0));
        },
        'POST service_order_save' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            return ['id' => ServiceOrders::save($in, $u['id'], !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST service_order_status' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            ServiceOrders::setStatus((int)($in['id'] ?? 0), (string)($in['status'] ?? ''), (int)$u['id'], isset($in['reason']) ? (string)$in['reason'] : null);
            return ['ok' => true];
        },
        // ---- App do técnico (celular) ----
        'GET tech_home' => function () {
            $u = Auth::requirePerm('ordens');
            return ServiceOrders::techHome((int)$u['id'], q('all') !== '1') + ['can_quotes' => Auth::can('orcamentos')];
        },
        'GET tech_tasks' => function () {
            $u = Auth::requirePerm('ordens');
            return ['rows' => ServiceOrders::techTasks((string)q('from'), (string)q('to'), q('all') === '1' ? null : (int)$u['id']), 'statuses' => ServiceOrders::STATUSES];
        },
        'GET service_order_detail' => function () {
            Auth::requirePerm('ordens');
            return OrderExec::detail((int)q('id'));
        },
        'POST so_answers_save' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            return OrderExec::saveAnswers((int)($in['id'] ?? 0), (int)($in['questionnaire_id'] ?? 0), $in['answers'] ?? [], $in['equipment_id'] ?? null, (int)$u['id']);
        },
        'POST so_report_save' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            OrderExec::saveReport((int)($in['id'] ?? 0), $in);
            return ['ok' => true];
        },
        'POST so_photo_add' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            return ['id' => OrderExec::addPhoto((int)($in['id'] ?? 0), $in['image'] ?? '', (string)($in['caption'] ?? ''), (int)$u['id'])];
        },
        'POST so_photo_delete' => function () {
            Auth::requirePerm('ordens');
            OrderExec::deletePhoto((int)(input()['photo_id'] ?? 0));
            return ['ok' => true];
        },
        'POST so_item_save' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            return ['id' => OrderExec::saveItem((int)($in['id'] ?? 0), $in, (int)$u['id'])];
        },
        'POST so_item_delete' => function () {
            Auth::requirePerm('ordens');
            OrderExec::deleteItem((int)(input()['item_id'] ?? 0));
            return ['ok' => true];
        },
        'POST so_hours_save' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            return ['changed' => OrderExec::saveHours((int)($in['id'] ?? 0), $in, (int)$u['id'])];
        },
        'POST so_send' => function () {
            $u = Auth::requirePerm('ordens');
            $in = input();
            OrderExec::send((int)($in['id'] ?? 0), (string)($in['kind'] ?? ''), (string)($in['to'] ?? ''), (int)$u['id']);
            return ['ok' => true];
        },
        'GET equipments' => function () {
            Auth::requirePerm('ordens');
            return Equipments::list($_GET);
        },
        'POST equipment_save' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            return ['id' => Equipments::save($in, !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST equipment_active' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            Equipments::setActive((int)($in['id'] ?? 0), !empty($in['active']));
            return ['ok' => true];
        },
        'POST equipment_delete' => function () {
            Auth::requireAdmin();
            return ['result' => Removals::delete('equipments', (int)(input()['id'] ?? 0))];
        },
        'POST questionnaire_delete' => function () {
            Auth::requireAdmin();
            return ['result' => Removals::delete('questionnaires', (int)(input()['id'] ?? 0))];
        },
        'POST task_type_delete' => function () {
            Auth::requireAdmin();
            return ['result' => Removals::delete('task_types', (int)(input()['id'] ?? 0))];
        },
        'GET questionnaires' => function () {
            Auth::requirePerm('ordens');
            return ['rows' => Questionnaires::list(q('all') === '1'), 'types' => Questionnaires::TYPES];
        },
        'GET questionnaire' => function () {
            Auth::requirePerm('ordens');
            return Questionnaires::get((int)q('id'));
        },
        'POST questionnaire_save' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            return ['id' => Questionnaires::save($in, !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST questionnaire_active' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            Questionnaires::setActive((int)($in['id'] ?? 0), !empty($in['active']));
            return ['ok' => true];
        },
        'GET task_types' => function () {
            Auth::requirePerm('ordens');
            return ['rows' => TaskTypes::list(q('all') === '1'), 'requires' => TaskTypes::REQUIRES, 'questionnaires' => Questionnaires::options()];
        },
        'POST task_type_save' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            return ['id' => TaskTypes::save($in, !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST task_type_active' => function () {
            Auth::requirePerm('ordens');
            $in = input();
            TaskTypes::setActive((int)($in['id'] ?? 0), !empty($in['active']));
            return ['ok' => true];
        },
        // ---- Ordens de compra ----
        'GET purchases' => function () {
            Auth::requirePerm('compras');
            return PurchaseOrders::list($_GET) + ['statuses' => PurchaseOrders::STATUSES, 'today' => date('Y-m-d')];
        },
        'GET purchase' => function () {
            Auth::requirePerm('compras');
            $o = PurchaseOrders::get((int)q('id'));
            $o['public_url'] = app_base_url() . 'pedido.php?t=' . $o['public_token'];
            return $o;
        },
        'GET purchase_defaults' => function () {
            Auth::requirePerm('compras');
            $days = (int)Settings::get('po_delivery_days');
            return ['order_date' => date('Y-m-d'), 'notes' => Settings::get('po_default_notes'),
                'expected_date' => $days > 0 ? date('Y-m-d', strtotime("+$days days")) : null,
                'payment_method' => Settings::get('po_default_method'), 'payment_terms' => Settings::get('po_default_terms'),
                'statuses' => PurchaseOrders::STATUSES, 'methods' => PaymentMethods::active()];
        },
        'GET purchase_low_stock' => function () { Auth::requirePerm('compras'); return PurchaseOrders::lowStock(); },
        'POST purchase_save' => function () {
            $u = Auth::requirePerm('compras');
            $in = input();
            return ['id' => PurchaseOrders::save($in, $u['id'], !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST purchase_status' => function () {
            Auth::requirePerm('compras');
            $in = input();
            PurchaseOrders::setStatus((int)($in['id'] ?? 0), (string)($in['status'] ?? ''), $in['date'] ?? null);
            return ['ok' => true];
        },
        'POST purchases_bulk' => function () {
            Auth::requirePerm('compras');
            $in = input();
            return PurchaseOrders::bulk((array)($in['ids'] ?? []), (string)($in['action'] ?? ''), isset($in['status']) ? (string)$in['status'] : null, $in['date'] ?? null);
        },
        'POST purchase_duplicate' => function () {
            $u = Auth::requirePerm('compras');
            return ['id' => PurchaseOrders::duplicate((int)(input()['id'] ?? 0), $u['id'])];
        },
        'POST purchase_delete' => function () {
            Auth::requirePerm('compras');
            PurchaseOrders::delete((int)(input()['id'] ?? 0));
            return ['ok' => true];
        },
        'POST purchase_notes_default' => function () {
            Auth::requireAdmin(); // configuração da empresa: só administrador (os outros só mudam a própria aparência)
            Settings::save(['po_default_notes' => (string)(input()['notes'] ?? '')]);
            return ['ok' => true];
        },

        // ---- Formas de pagamento ----
        'GET payment_methods' => function () { Auth::requirePerm('formas_pagamento'); return PaymentMethods::listWithUsage(); },
        'POST payment_method_save' => function () {
            Auth::requirePerm('formas_pagamento');
            $in = input();
            return ['id' => PaymentMethods::save($in, !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST payment_method_delete' => function () { Auth::requirePerm('formas_pagamento'); PaymentMethods::delete((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'POST payment_method_order' => function () { Auth::requirePerm('formas_pagamento'); PaymentMethods::reorder((array)(input()['ids'] ?? [])); return ['ok' => true]; },

        // ---- Condições de pagamento ----
        'GET payment_terms' => function () { Auth::requireAdmin(); return PaymentTerms::rows(); },
        'POST payment_term_save' => function () {
            $in = input();
            // Cadastrar uma nova direto no orçamento/venda/ordem: quem usa essas telas pode; alterar as existentes: só administrador
            if (!empty($in['id'])) Auth::requireAdmin(); else Auth::requirePerm('orcamentos', 'vendas', 'compras');
            $id = PaymentTerms::save($in, !empty($in['id']) ? (int)$in['id'] : null);
            return ['id' => $id, 'terms' => PaymentTerms::forForms()];
        },
        'POST payment_term_delete' => function () { Auth::requireAdmin(); PaymentTerms::delete((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'GET quote_statuses' => function () { Auth::requireAdmin(); return QuoteStatuses::rows(); },
        'POST quote_status_save' => function () {
            Auth::requireAdmin();
            $in = input();
            return ['id' => QuoteStatuses::save($in, (int)($in['id'] ?? 0) ?: null)];
        },
        'POST quote_status_delete' => function () { Auth::requireAdmin(); QuoteStatuses::delete((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'POST quote_status_order' => function () { Auth::requireAdmin(); QuoteStatuses::reorder((array)(input()['ids'] ?? [])); return ['ok' => true]; },
        'POST payment_term_order' => function () { Auth::requireAdmin(); PaymentTerms::reorder((array)(input()['ids'] ?? [])); return ['ok' => true]; },

        // ---- Financeiro ----
        'GET finance' => function () {
            Auth::requirePerm('financeiro');
            return Finance::list($_GET) + ['summary' => Finance::summary(), 'today' => date('Y-m-d')];
        },
        'GET finance_meta' => function () {
            Auth::requirePerm('financeiro');
            return ['types' => Finance::TYPES, 'statuses' => Finance::STATUSES, 'methods' => PaymentMethods::active(),
                'categories' => Finance::categories(), 'today' => date('Y-m-d'), 'opening' => Finance::opening()];
        },
        'GET finance_entry' => function () { Auth::requirePerm('financeiro'); return Finance::get((int)q('id')); },
        'POST finance_save' => function () {
            $u = Auth::requirePerm('financeiro');
            $in = input();
            return ['ids' => Finance::save($in, $u['id'], !empty($in['id']) ? (int)$in['id'] : null)];
        },
        'POST finance_pay' => function () {
            Auth::requirePerm('financeiro');
            $in = input();
            Finance::pay((int)($in['id'] ?? 0), $in);
            return ['ok' => true];
        },
        'POST finance_reopen' => function () { Auth::requirePerm('financeiro'); Finance::reopen((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'POST finance_cancel' => function () { Auth::requirePerm('financeiro'); Finance::cancel((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'POST finance_delete' => function () { Auth::requirePerm('financeiro'); Finance::delete((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'GET cashflow' => function () { Auth::requirePerm('financeiro'); return Finance::cashflow($_GET); },
        'POST cash_opening' => function () {
            Auth::requireAdmin(); // saldo inicial do caixa é configuração: só administrador
            return ['opening' => Finance::setOpening(input()['value'] ?? 0)];
        },

        'GET team' => function () {
            Auth::require();
            return DB::all('SELECT id, name FROM users WHERE active = 1 ORDER BY name');
        },
        'GET settings' => function () { Auth::require(); return Settings::all(); },
        'POST settings' => function () { Auth::requireAdmin(); return Settings::save(input()); },

        // ---- Relatórios ----
        'GET report' => function () { Auth::requirePerm('relatorios'); return Reports::summary(q('from'), q('to')); },

        // ---- Usuários (admin) ----
        'GET users' => function () {
            Auth::requireAdmin();
            $rows = DB::all('SELECT u.id, u.name, u.email, u.phone, u.avatar, u.role, u.active, u.permissions, u.profile_id, u.created_at,
                p.name profile_name, p.permissions profile_permissions
                FROM users u LEFT JOIN permission_profiles p ON p.id = u.profile_id ORDER BY u.name');
            foreach ($rows as &$r) { $r['permissions'] = Auth::permsOf($r); unset($r['profile_permissions']); }
            return $rows;
        },
        'POST user_save' => function () {
            $me = Auth::requireAdmin();
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if (!$id) return ['id' => Auth::createUser($in)];
            $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$u) throw new AppError('Usuário não encontrado', 404);
            Auth::assertCanEdit($u, $me);
            $data = [];
            if (isset($in['name']) && trim($in['name']) !== '') $data['name'] = mb_substr(trim($in['name']), 0, 120);
            if (array_key_exists('phone', $in)) $data['phone'] = Auth::phone($in['phone']);
            if (isset($in['email'])) {
                $email = Auth::cleanEmail((string)$in['email'], $id);
                if ($email !== $u['email']) $data['email'] = $email;
            }
            if (isset($in['role'])) $data['role'] = $in['role'] === 'admin' ? 'admin' : 'membro';
            if (isset($in['active'])) $data['active'] = $in['active'] ? 1 : 0;
            if (isset($in['permissions']) && is_array($in['permissions'])) {
                $data['permissions'] = Auth::normalizePerms($in['permissions']);
            }
            if (array_key_exists('profile_id', $in)) {
                $pid = (int)$in['profile_id'] ?: null;
                if ($pid && !DB::val('SELECT id FROM permission_profiles WHERE id = ?', [$pid])) throw new AppError('Perfil de permissão não encontrado');
                $data['profile_id'] = $pid;
                if (!$pid && !isset($in['permissions'])) $data['permissions'] = null; // sem perfil = acesso total
            }
            if (!empty($in['password'])) {
                Auth::checkPassword((string)$in['password']);
                $data['password_hash'] = password_hash((string)$in['password'], PASSWORD_DEFAULT);
            }
            if ($id === $me['id'] && ((isset($data['role']) && $data['role'] !== 'admin') || (isset($data['active']) && !$data['active']))) {
                throw new AppError('Você não pode remover seu próprio acesso de administrador');
            }
            // Administrador não é rebaixado nem desativado pela tela (só pelo console do servidor)
            if ($u['role'] === 'admin' && ((isset($data['role']) && $data['role'] !== 'admin') || (isset($data['active']) && !$data['active'] && $u['active']))) {
                throw new AppError(Auth::ADMIN_LOCKED, 403);
            }
            if ($data) DB::update('users', $id, $data);
            return ['id' => $id];
        },
        'POST user_delete' => function () {
            $me = Auth::requireAdmin();
            return ['result' => Auth::deleteUser((int)(input()['id'] ?? 0), (int)$me['id'])];
        },
        'GET profiles' => function () { Auth::requireAdmin(); return ['profiles' => Auth::profiles(), 'perms' => Auth::PERMS]; },
        'POST profile_save' => function () { Auth::requireAdmin(); return ['id' => Auth::saveProfile(input())]; },
        'POST profile_delete' => function () { Auth::requireAdmin(); Auth::deleteProfile((int)(input()['id'] ?? 0)); return ['ok' => true]; },
        'GET backup' => function () {
            Auth::requireAdmin();
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="backup-estoque-' . date('Y-m-d-His') . '.sql"');
            echo Backup::sql();
            exit;
        },
        // ---- Backup automático no Google Drive (Configurações → Sistema → Backup) ----
        'GET gdrive' => function () { Auth::requireAdmin(); return GDrive::status(); },
        'POST gdrive_config' => function () { Auth::requireAdmin(); return GDrive::saveConfig(input()); },
        'POST gdrive_connect' => function () { Auth::requireAdmin(); return GDrive::connect((string)(input()['url'] ?? ''), (string)(input()['folder'] ?? '')); },
        'POST gdrive_disconnect' => function () { Auth::requireAdmin(); GDrive::disconnect(); return GDrive::status(); },
        'POST gdrive_run' => function () { Auth::requireAdmin(); @set_time_limit(600); $r = GDrive::run(); return $r + ['status' => GDrive::status()]; },
    ];

    $key = "$method $r";
    if (!isset($routes[$key])) out(['error' => 'Rota não encontrada'], 404);
    out($routes[$key]());
} catch (AppError $e) {
    out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[estoque] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    $cfg = app_config();
    $msg = !empty($cfg['debug']) ? $e->getMessage() : 'Erro interno. Tente novamente; se continuar, avise o administrador.';
    out(['error' => $msg], 500);
}
