<?php
/**
 * Orçamentos: produtos, serviços, custos adicionais, desconto, status,
 * link público (cliente vê, imprime e aprova) e conversão em venda.
 */

class Quotes
{
    /** Status: tabela quote_statuses (QuoteStatuses). Os de sistema têm regras; vendido só pela conversão. */

    public static function get(int $id): array
    {
        $q = DB::one('SELECT q.*, u.name user_name, u.phone user_phone, u.email user_email, c.name customer_name, c.trade_name customer_trade_name,
                c.document customer_document, c.phone customer_phone, c.email customer_email,
                c.street customer_street, c.number customer_number, c.complement customer_complement,
                c.district customer_district, c.city customer_city, c.state customer_state, c.cep customer_cep
            FROM quotes q LEFT JOIN users u ON u.id = q.user_id LEFT JOIN customers c ON c.id = q.customer_id
            WHERE q.id = ?', [$id]);
        if (!$q) throw new AppError('Orçamento não encontrado', 404);
        $q['items'] = DB::all('SELECT i.*, p.stock, p.unit product_unit, p.type product_type, p.active product_active, p.deleted_at product_deleted
            FROM quote_items i JOIN products p ON p.id = i.product_id WHERE i.quote_id = ? ORDER BY i.sort, i.id', [$id]);
        $q['extras'] = DB::all('SELECT * FROM quote_extras WHERE quote_id = ? ORDER BY id', [$id]);
        $q['status_label'] = QuoteStatuses::label($q['status']);
        // Ordens de serviço ligadas: geradas a partir deste orçamento e a OS de onde ele nasceu
        $q['service_orders'] = DB::all('SELECT o.id, o.status, o.created_at, o.quote_id, tt.name task_type_name FROM service_orders o
            LEFT JOIN task_types tt ON tt.id = o.task_type_id WHERE o.quote_id = ? OR o.id = ? ORDER BY o.id', [$id, (int)$q['service_order_id']]);
        foreach ($q['service_orders'] as &$so) $so['status_label'] = ServiceOrders::label($so['status']);
        unset($so);
        return $q;
    }

    public static function byToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new AppError('Orçamento não encontrado', 404);
        $id = DB::val('SELECT id FROM quotes WHERE public_token = ?', [$token]);
        if (!$id) throw new AppError('Orçamento não encontrado', 404);
        return self::get((int)$id);
    }

    /** Cria ou atualiza o orçamento inteiro (itens e custos são regravados). */
    public static function save(array $in, int $userId, ?int $id = null): int
    {
        $status = $in['status'] ?? 'rascunho';
        QuoteStatuses::check($status, $id ? DB::val('SELECT status FROM quotes WHERE id = ?', [$id]) : null);

        // Para quem: cliente cadastrado ou lead (contato ainda sem cadastro)
        $customerId = null; $lead = [null, null, null];
        if (($in['target'] ?? 'cliente') === 'lead') {
            $name = trim((string)($in['lead_name'] ?? ''));
            if ($name === '') throw new AppError('Informe o nome do lead');
            $email = trim((string)($in['lead_email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('E-mail do lead inválido');
            $lead = [mb_substr($name, 0, 200), Inventory::nullable(Customers::phoneMask($in['lead_phone'] ?? null)), $email ?: null];
        } else {
            $customerId = (int)($in['customer_id'] ?? 0);
            if (!$customerId) throw new AppError('Selecione o cliente do orçamento (ou informe o lead)');
            Customers::get($customerId);
            Removals::check('customers', $customerId, $id ? (int)DB::val('SELECT customer_id FROM quotes WHERE id = ?', [$id]) : null);
        }
        // Aprovar só para cliente cadastrado (lead precisa ser cadastrado antes)
        if ($status === 'aprovado' && !$customerId) {
            throw new AppError('Só é possível aprovar orçamento de cliente cadastrado. Cadastre o lead como cliente e selecione-o no orçamento.');
        }

        $req = self::date($in['request_date'] ?? null) ?? date('Y-m-d');
        $exp = self::date($in['expires_at'] ?? null);
        if ($exp && $exp < $req) throw new AppError('A data de expiração é anterior à data de solicitação');
        $resp = (int)($in['user_id'] ?? 0) ?: $userId;
        if (!DB::val('SELECT id FROM users WHERE id = ? AND active = 1', [$resp])) throw new AppError('Escolha o colaborador responsável');

        // Itens excluídos do cadastro só continuam se já estavam neste orçamento
        $kept = $id ? array_map('intval', array_column(DB::all('SELECT product_id FROM quote_items WHERE quote_id = ?', [$id]), 'product_id')) : [];
        // Itens
        $items = [];
        $prodTotal = $servTotal = 0.0;
        foreach (array_values($in['items'] ?? []) as $n => $it) {
            $p = Inventory::product((int)($it['product_id'] ?? 0));
            if ($p['deleted_at'] && !in_array((int)$p['id'], $kept, true)) throw new AppError('"' . $p['name'] . '" foi excluído do cadastro');
            $q = Inventory::num($it['quantity'] ?? 0);
            if ($q <= 0) throw new AppError('Quantidade inválida em "' . $p['name'] . '"');
            $price = round(max(0, Inventory::num($it['unit_price'] ?? $p['sale_price'])), 2);
            $gross = round($price * $q, 2);
            // Desconto do item em R$ ou em % do valor do item
            $dType = ($it['discount_type'] ?? 'valor') === 'percent' ? 'percent' : 'valor';
            $dVal = round(max(0, Inventory::num($it['discount_value'] ?? ($it['discount'] ?? 0))), 2);
            if ($dType === 'percent' && $dVal > 100) throw new AppError('O desconto de "' . $p['name'] . '" passa de 100%');
            $disc = $dType === 'percent' ? round($gross * $dVal / 100, 2) : $dVal;
            if ($disc > $gross + 0.001) throw new AppError('O desconto de "' . $p['name'] . '" é maior que o valor do item');
            $total = round($gross - $disc, 2);
            $kind = $p['type'] === 'servico' ? 'servico' : 'produto';
            if ($kind === 'servico') $servTotal += $total; else $prodTotal += $total;
            $desc = trim((string)($it['description'] ?? '')) ?: $p['name'];
            $items[] = ['product_id' => (int)$p['id'], 'kind' => $kind, 'description' => mb_substr($desc, 0, 255),
                'unit' => $p['unit'], 'quantity' => $q, 'unit_price' => $price, 'discount' => $disc,
                'discount_type' => $dType, 'discount_value' => $dVal, 'total' => $total, 'sort' => $n];
        }
        if (!$items && !in_array($status, ['rascunho', 'em_elaboracao'], true)) {
            throw new AppError('Inclua pelo menos um produto ou serviço antes de mudar o status');
        }
        $extras = [];
        $extTotal = 0.0;
        foreach ($in['extras'] ?? [] as $e) {
            $d = trim((string)($e['description'] ?? ''));
            $v = round(Inventory::num($e['amount'] ?? 0), 2);
            if ($d === '' && !$v) continue;
            if ($d === '') throw new AppError('Descreva o custo adicional de R$ ' . number_format($v, 2, ',', '.'));
            if ($v < 0) throw new AppError('Custo adicional não pode ser negativo');
            $extras[] = ['description' => mb_substr($d, 0, 200), 'amount' => $v];
            $extTotal += $v;
        }
        $base = $prodTotal + $servTotal + $extTotal;
        $dType = ($in['discount_type'] ?? 'valor') === 'percent' ? 'percent' : 'valor';
        $dValue = round(max(0, Inventory::num($in['discount_value'] ?? 0)), 2);
        if ($dType === 'percent' && $dValue > 100) throw new AppError('Desconto não pode passar de 100%');
        $dTotal = $dType === 'percent' ? round($base * $dValue / 100, 2) : $dValue;
        if ($dTotal > $base + 0.001) throw new AppError('O desconto é maior que o valor do orçamento');

        // Formas ativas, mais as que o orçamento já tinha (mesmo que tenham sido desativadas depois)
        $oldMethods = $id ? array_filter(explode(',', (string)DB::val('SELECT payment_methods FROM quotes WHERE id = ?', [$id]))) : [];
        $methods = [];
        foreach ((array)($in['payment_methods'] ?? []) as $m) {
            $m = (string)$m;
            if (!in_array($m, $methods, true) && (isset(PaymentMethods::active()[$m]) || (in_array($m, $oldMethods, true) && PaymentMethods::exists($m)))) $methods[] = $m;
        }

        $instEntry = !empty($in['installment_entry']);
        $inst = PaymentMethods::installments($methods, $in['installments'] ?? 1, $in['installment_days'] ?? 30, $instEntry);
        $plan = PaymentMethods::plan($inst[0], $inst[1], $base - $dTotal, date('Y-m-d'), $in['installment_plan'] ?? null, PaymentMethods::freeInterval($methods), $instEntry);
        $data = [
            'status' => $status,
            'customer_id' => $customerId,
            'lead_name' => $lead[0], 'lead_phone' => $lead[1], 'lead_email' => $lead[2],
            'user_id' => $resp,
            'request_date' => $req,
            'expires_at' => $exp,
            'external_code' => Inventory::nullable($in['external_code'] ?? null),
            'payment_methods' => $methods ? implode(',', $methods) : null,
            'installments' => $inst[0],
            'installment_days' => $inst[1],
            'installment_plan' => $plan ? json_encode($plan) : null,
            'installment_entry' => $plan && $instEntry ? 1 : 0,
            'payment_terms' => Inventory::nullable($in['payment_terms'] ?? null),
            'discount_value' => $dValue,
            'discount_type' => $dType,
            'products_total' => round($prodTotal, 2),
            'services_total' => round($servTotal, 2),
            'extras_total' => round($extTotal, 2),
            'discount_total' => $dTotal,
            'total' => round($base - $dTotal, 2),
            'notes' => self::text($in['notes'] ?? null),
            'internal_notes' => self::text($in['internal_notes'] ?? null),
            'updated_at' => now(),
        ];

        // Orçamento aberto a partir de uma OS (só ao criar): a OS precisa ser do mesmo cliente
        $soId = null;
        if (!$id && (int)($in['service_order_id'] ?? 0)) {
            $so = ServiceOrders::get((int)$in['service_order_id']);
            if (!$customerId || (int)$so['customer_id'] !== $customerId) throw new AppError('A ordem de serviço #' . $so['id'] . ' é de outro cliente');
            $soId = (int)$so['id'];
        }

        return DB::tx(function () use ($data, $items, $extras, $id, $userId, $soId) {
            if ($id) {
                $old = DB::one('SELECT * FROM quotes WHERE id = ?' . DB::lockSuffix(), [$id]);
                if (!$old) throw new AppError('Orçamento não encontrado', 404);
                if ($old['status'] === 'vendido') throw new AppError('Este orçamento já virou venda e não pode mais ser alterado');
                self::stamp($data, $old['status']);
                DB::update('quotes', $id, $data);
                DB::run('DELETE FROM quote_items WHERE quote_id = ?', [$id]);
                DB::run('DELETE FROM quote_extras WHERE quote_id = ?', [$id]);
            } else {
                self::stamp($data, null);
                $data['created_by'] = $userId;
                $data['service_order_id'] = $soId;
                $data['public_token'] = bin2hex(random_bytes(16));
                $data['created_at'] = now();
                $id = DB::insert('quotes', $data);
            }
            foreach ($items as $it) DB::insert('quote_items', ['quote_id' => $id] + $it);
            foreach ($extras as $e) DB::insert('quote_extras', ['quote_id' => $id] + $e);
            return $id;
        });
    }

    /** Muda só o status (arrastar no kanban, enviar, cliente aprovar/recusar). */
    public static function setStatus(int $id, string $status, ?string $response = null): void
    {
        QuoteStatuses::check($status);
        DB::tx(function () use ($id, $status, $response) {
            $q = DB::one('SELECT * FROM quotes WHERE id = ?' . DB::lockSuffix(), [$id]);
            if (!$q) throw new AppError('Orçamento não encontrado', 404);
            if ($q['status'] === 'vendido') throw new AppError('Este orçamento já virou venda');
            if ($q['status'] === $status) return;
            if ($status === 'aprovado' && !$q['customer_id']) {
                throw new AppError('Só é possível aprovar orçamento de cliente cadastrado. Cadastre o lead como cliente e selecione-o no orçamento.');
            }
            if (!in_array($status, ['rascunho', 'em_elaboracao'], true) && !DB::val('SELECT COUNT(*) FROM quote_items WHERE quote_id = ?', [$id])) {
                throw new AppError('Inclua pelo menos um produto ou serviço antes de mudar o status');
            }
            $data = ['status' => $status, 'updated_at' => now()];
            if ($response !== null) $data['client_response'] = mb_substr(trim($response), 0, 500) ?: null;
            self::stamp($data, $q['status']);
            DB::update('quotes', $id, $data);
        });
    }

    /** Aprovação ou recusa feita pelo cliente no link público. */
    public static function clientRespond(string $token, string $action, ?string $comment): array
    {
        $q = self::byToken($token);
        if (in_array($q['status'], QuoteStatuses::CLOSED, true)) {
            throw new AppError('Este orçamento já foi ' . mb_strtolower(QuoteStatuses::label($q['status'])) . '.');
        }
        if (self::isExpired($q)) throw new AppError('Este orçamento venceu em ' . date('d/m/Y', strtotime($q['expires_at'])) . '. Peça uma nova proposta.');
        if ($action === 'aprovar' && !$q['customer_id']) {
            throw new AppError('Para aprovar este orçamento, entre em contato com o responsável.');
        }
        self::setStatus((int)$q['id'], $action === 'aprovar' ? 'aprovado' : 'recusado', $comment);
        return self::get((int)$q['id']);
    }

    /** Gera a venda a partir do orçamento aprovado (baixa o estoque). */
    public static function convert(int $id, string $paymentMethod, int $userId, ?int $installments = null, ?int $installmentDays = null, $plan = null, ?bool $entry = null): int
    {
        return DB::tx(function () use ($id, $paymentMethod, $userId, $installments, $installmentDays, $plan, $entry) {
            $q = self::get($id);
            if ($q['status'] === 'vendido') throw new AppError('Este orçamento já virou a venda #' . $q['sale_id']);
            if ($q['status'] !== 'aprovado') throw new AppError('Só orçamentos aprovados podem virar venda. Mude o status para "Aprovado" primeiro.');
            if (!$q['customer_id']) throw new AppError('Este orçamento é para um lead. Cadastre o cliente antes de gerar a venda.');
            if (!$q['items']) throw new AppError('O orçamento não tem itens');
            // Descontos dos itens + desconto geral viram o desconto da venda; custos adicionais viram acréscimo
            $lineDisc = array_sum(array_map(fn($i) => (float)$i['discount'], $q['items']));
            // Sem plano vindo da tela: os vencimentos do orçamento andam junto com a data da venda
            if ($plan === null) $plan = self::shiftPlan($q['installment_plan'], (string)$q['request_date'], date('Y-m-d'));
            $saleId = Inventory::createSale([
                'customer_id' => $q['customer_id'],
                'payment_method' => $paymentMethod,
                'installments' => $installments ?? (int)$q['installments'],
                'installment_days' => $installmentDays ?? (int)$q['installment_days'],
                'installment_plan' => $plan ?? $q['installment_plan'],
                'installment_entry' => $entry ?? (int)$q['installment_entry'],
                'discount' => round($lineDisc + (float)$q['discount_total'], 2),
                'surcharge' => (float)$q['extras_total'],
                'extras' => array_map(fn($e) => ['description' => $e['description'], 'amount' => $e['amount']], $q['extras']),
                'quote_id' => $id,
                'note' => 'Orçamento #' . $id,
                'payment_terms' => $q['payment_terms'],
                'items' => array_map(fn($i) => ['product_id' => $i['product_id'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price']], $q['items']),
            ], $userId);
            DB::update('quotes', $id, ['status' => 'vendido', 'sale_id' => $saleId, 'status_changed_at' => now(), 'updated_at' => now()]);
            return $saleId;
        });
    }

    /**
     * Move os vencimentos do plano de parcelas pelos dias entre a data do orçamento e a da venda
     * (ex.: orçamento de 05/11 com 1ª parcela em 03/12, convertido em 16/11 → 1ª parcela em 14/12).
     * Devolve o plano em JSON (ou o original, se não houver o que mudar).
     */
    public static function shiftPlan($plan, string $from, string $to)
    {
        $rows = is_string($plan) ? json_decode($plan, true) : $plan;
        $a = strtotime(substr($from, 0, 10)); $b = strtotime(substr($to, 0, 10));
        if (!is_array($rows) || !$rows || !$a || !$b) return $plan;
        $days = (int)round(($b - $a) / 86400);
        if ($days <= 0) return $plan;
        foreach ($rows as &$r) {
            if (!empty($r['due_date']) && ($t = strtotime($r['due_date']))) $r['due_date'] = date('Y-m-d', strtotime("+$days days", $t));
        }
        unset($r);
        return json_encode(array_values($rows));
    }

    /** Copia o orçamento como um novo rascunho, com datas de hoje. */
    /** "Marcar como enviado / aprovado": o status de destino é escolhido em Configurações → Orçamento. */
    public static function mark(int $id, string $action): string
    {
        $key = ['enviado' => 'quote_on_sent', 'aprovado' => 'quote_on_approved', 'recusado' => 'quote_on_refused'][$action] ?? null;
        if (!$key) throw new AppError('Ação inválida');
        $status = Settings::get($key) ?: $action;
        if (!in_array($status, QuoteStatuses::selectable(), true)) $status = $action;
        if ($action === 'aprovado' && !DB::val('SELECT customer_id FROM quotes WHERE id = ?', [$id])) {
            throw new AppError('Só é possível aprovar orçamento de cliente cadastrado. Cadastre o lead como cliente e selecione-o no orçamento.');
        }
        self::setStatus($id, $status);
        return $status;
    }

    const BULK_MAX = 200;

    /**
     * Ações em massa (lista/kanban). Cada orçamento é tratado à parte: os que não podem
     * (ex.: lead não aprova, vendido não muda) voltam em "errors" sem impedir os outros.
     * $action: status | enviado | aprovado | recusado | delete
     */
    public static function bulk(array $ids, string $action, ?string $status = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw new AppError('Selecione pelo menos um orçamento');
        if (count($ids) > self::BULK_MAX) throw new AppError('Selecione no máximo ' . self::BULK_MAX . ' orçamentos por vez');
        if (!in_array($action, ['status', 'enviado', 'aprovado', 'recusado', 'delete'], true)) throw new AppError('Ação inválida');
        if ($action === 'status') QuoteStatuses::check((string)$status);
        $ok = 0; $errors = [];
        foreach ($ids as $id) {
            try {
                if (!DB::val('SELECT id FROM quotes WHERE id = ?', [$id])) throw new AppError('Orçamento não encontrado');
                if ($action === 'delete') self::delete($id);
                elseif ($action === 'status') self::setStatus($id, (string)$status);
                else self::mark($id, $action);
                $ok++;
            } catch (AppError $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    /** Exclui o orçamento (o que já virou venda não pode ser excluído). */
    public static function delete(int $id): void
    {
        DB::tx(function () use ($id) {
            $q = DB::one('SELECT * FROM quotes WHERE id = ?' . DB::lockSuffix(), [$id]);
            if (!$q) throw new AppError('Orçamento não encontrado', 404);
            if ($q['status'] === 'vendido' || $q['sale_id']) throw new AppError('Este orçamento virou a venda #' . $q['sale_id'] . ' e não pode ser excluído');
            DB::run('DELETE FROM quote_items WHERE quote_id = ?', [$id]);
            DB::run('DELETE FROM quote_extras WHERE quote_id = ?', [$id]);
            DB::run('DELETE FROM quotes WHERE id = ?', [$id]);
        });
    }

    /** Cópia em rascunho, com o mesmo cliente. (Na tela, "Duplicar" abre um orçamento novo não salvo, com o cliente em branco.) */
    public static function duplicate(int $id, int $userId): int
    {
        $q = self::get($id);
        $days = (int)Settings::get('quote_validity_days');
        return self::save([
            'status' => 'rascunho',
            'target' => $q['customer_id'] ? 'cliente' : 'lead',
            'customer_id' => $q['customer_id'], 'lead_name' => $q['lead_name'], 'lead_phone' => $q['lead_phone'], 'lead_email' => $q['lead_email'],
            'user_id' => $userId, 'request_date' => date('Y-m-d'), 'expires_at' => $days ? date('Y-m-d', strtotime("+$days days")) : null,
            'external_code' => $q['external_code'], 'payment_methods' => $q['payment_methods'] ? explode(',', $q['payment_methods']) : [],
            'installments' => $q['installments'], 'installment_days' => $q['installment_days'], 'installment_entry' => $q['installment_entry'],
            'payment_terms' => $q['payment_terms'], 'discount_value' => $q['discount_value'], 'discount_type' => $q['discount_type'],
            'notes' => $q['notes'], 'internal_notes' => $q['internal_notes'],
            'items' => array_map(fn($i) => ['product_id' => $i['product_id'], 'description' => $i['description'], 'quantity' => $i['quantity'],
                'unit_price' => $i['unit_price'], 'discount_type' => $i['discount_type'], 'discount_value' => $i['discount_value']], array_values(array_filter($q['items'], fn($i) => $i['product_active']))),
            'extras' => $q['extras'],
        ], $userId);
    }

    /** Indicadores do topo da tela. */
    public static function summary(): array
    {
        [$d1, $d2] = self::monthRange();
        $a = $d1 . ' 00:00:00'; $b = $d2 . ' 23:59:59';
        $count = fn(string $where, array $p = []) => (int)DB::val("SELECT COUNT(*) FROM quotes WHERE $where", $p);
        return [
            'pendentes_envio' => $count("status IN ('rascunho','em_elaboracao','aguardando_envio')"),
            'aguardando_aprovacao' => $count("status = 'enviado'"),
            'expiram_mes' => $count(self::openSql() . " AND expires_at BETWEEN ? AND ?", [$d1, $d2]),
            'recusados_mes' => $count("status = 'recusado' AND refused_at BETWEEN ? AND ?", [$a, $b]),
            'aprovados_mes' => $count("status IN ('aprovado','vendido') AND approved_at BETWEEN ? AND ?", [$a, $b]),
            'aguardando_venda' => $count("status = 'aprovado'"),
            'vendidos_mes' => $count("status = 'vendido' AND status_changed_at BETWEEN ? AND ?", [$a, $b]),
        ];
    }

    /** Primeiro e último dia do mês atual (Y-m-d). */
    public static function monthRange(): array
    {
        return [date('Y-m-01'), date('Y-m-t')];
    }

    /** Orçamentos ainda em aberto (sem resposta e sem venda), incluindo status criados pelo usuário. */
    public static function openSql(string $col = 'status'): string
    {
        return "$col NOT IN ('" . implode("','", QuoteStatuses::CLOSED) . "')";
    }

    public static function isExpired(array $q): bool
    {
        return !empty($q['expires_at']) && $q['expires_at'] < date('Y-m-d');
    }

    // ---------- Utilidades ----------

    private static function stamp(array &$data, ?string $old): void
    {
        $new = $data['status'];
        if ($old === $new) return;
        $data['status_changed_at'] = now();
        if ($new === 'enviado') $data['sent_at'] = now();
        if ($new === 'aprovado') $data['approved_at'] = now();
        if ($new === 'recusado') $data['refused_at'] = now();
    }

    private static function date($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]";
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) throw new AppError('Data inválida: ' . $v);
        return $v;
    }

    private static function text($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, 5000);
    }
}
