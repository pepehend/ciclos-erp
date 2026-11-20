<?php
/**
 * Ordens de serviço (OS): atendimento técnico ao cliente, com equipamento, tipo de tarefa e técnico.
 * Origem: chamado avulso ou orçamento do mesmo cliente. Ainda não mexe no estoque nem no financeiro
 * (isso entra nas fases de execução e faturamento).
 */

class Equipments
{
    public static function get(int $id): array
    {
        $e = DB::one('SELECT e.*, c.name customer_name, c.trade_name customer_trade_name FROM equipments e
            JOIN customers c ON c.id = e.customer_id WHERE e.id = ?', [$id]);
        if (!$e) throw new AppError('Equipamento não encontrado', 404);
        $e['specs'] = self::specsList($e);
        return $e;
    }

    public static function list(array $f): array
    {
        $w = ['1=1']; $p = [];
        $w[] = 'e.deleted_at IS NULL';
        if (empty($f['all'])) { $w[] = 'e.active = 1'; }
        if (!empty($f['customer_id'])) { $w[] = 'e.customer_id = ?'; $p[] = (int)$f['customer_id']; }
        if (($s = trim((string)($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($s) . '%';
            $w[] = '(LOWER(e.name) LIKE ? OR LOWER(e.brand) LIKE ? OR LOWER(e.model) LIKE ? OR LOWER(e.serial) LIKE ? OR LOWER(c.name) LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like);
        }
        $rows = DB::all("SELECT e.*, c.name customer_name, c.trade_name customer_trade_name FROM equipments e
            JOIN customers c ON c.id = e.customer_id WHERE " . implode(' AND ', $w) . ' ORDER BY c.name, e.name LIMIT 500', $p);
        foreach ($rows as &$e) { $e['specs'] = self::specsList($e); }
        unset($e);
        return $rows;
    }

    public static function save(array $in, ?int $id = null): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new AppError('Informe o nome do equipamento');
        $customerId = (int)($in['customer_id'] ?? 0);
        if (!$customerId) throw new AppError('Selecione o cliente do equipamento');
        Customers::get($customerId);
        Removals::check('customers', $customerId, $id ? (int)DB::val('SELECT customer_id FROM equipments WHERE id = ?', [$id]) : null);
        $data = [
            'customer_id' => $customerId,
            'name' => mb_substr($name, 0, 120),
            'brand' => mb_substr(trim((string)($in['brand'] ?? '')), 0, 80) ?: null,
            'model' => mb_substr(trim((string)($in['model'] ?? '')), 0, 80) ?: null,
            'serial' => mb_substr(trim((string)($in['serial'] ?? '')), 0, 80) ?: null,
            'category' => mb_substr(trim((string)($in['category'] ?? '')), 0, 80) ?: null,
            'valid_until' => self::date($in['valid_until'] ?? null, 'Data de validade'),
            'warranty_until' => self::date($in['warranty_until'] ?? null, 'Garantia'),
            'specs' => self::specs($in['specs'] ?? []),
            'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null,
            'updated_at' => now(),
        ];
        if ($id) {
            if (self::get($id)['deleted_at']) throw new AppError('Equipamento excluído não pode ser alterado');
            DB::update('equipments', $id, $data);
            return $id;
        }
        $data += ['active' => 1, 'created_at' => now()];
        return DB::insert('equipments', $data);
    }

    /** Data no formato AAAA-MM-DD (ou vazia). */
    private static function date($v, string $campo): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) {
            throw new AppError("$campo inválida");
        }
        return $v;
    }

    /** Especificações: lista de {label, value}. Aceita lista ou JSON; descarta linhas vazias. */
    public static function specs($raw): ?string
    {
        if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
        $out = [];
        foreach ((array)$raw as $row) {
            $label = trim((string)(is_array($row) ? ($row['label'] ?? '') : ''));
            $value = trim((string)(is_array($row) ? ($row['value'] ?? '') : ''));
            if ($label === '' && $value === '') continue;
            if ($label === '') throw new AppError('Informe o nome de cada especificação');
            $out[] = ['label' => mb_substr($label, 0, 80), 'value' => mb_substr($value, 0, 200)];
            if (count($out) > 50) throw new AppError('Máximo de 50 especificações por equipamento');
        }
        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
    }

    /** Converte o campo specs guardado em lista. */
    public static function specsList(array $e): array
    {
        return json_decode((string)($e['specs'] ?? ''), true) ?: [];
    }

    public static function setActive(int $id, bool $active): void
    {
        if (self::get($id)['deleted_at']) throw new AppError('Equipamento excluído não pode ser alterado');
        DB::update('equipments', $id, ['active' => $active ? 1 : 0, 'updated_at' => now()]);
    }
}

class ServiceOrders
{
    const STATUSES = [
        'aberta' => 'Aberta', 'agendada' => 'Agendada', 'a_caminho' => 'A caminho', 'em_execucao' => 'Em execução',
        'pausada' => 'Pausada', 'concluida' => 'Concluída',
    ];
    /** Andamento permitido: de → para. "concluida" é o fim do fluxo (a OS não fatura; quem fatura é o orçamento). */
    const TRANSITIONS = [
        'aberta' => ['agendada'],
        'agendada' => ['aberta', 'a_caminho', 'em_execucao'],
        'a_caminho' => ['agendada', 'em_execucao'],
        'em_execucao' => ['pausada', 'concluida'],
        'pausada' => ['em_execucao'],
        'concluida' => [],
    ];
    const ORIGINS = ['chamado' => 'Chamado avulso', 'orcamento' => 'Orçamento'];
    /** Quem não tem a permissão "ordens.all" só vê e mexe nas OS em que é o técnico (null = todas). O api.php define. */
    public static ?int $onlyTech = null;
    /** Pode alterar OS concluída (permissão "ordens.after_checkout"). O api.php define. */
    public static bool $afterCheckout = true;

    /** Filtro SQL do técnico (vazio quando vê todas). */
    private static function techSql(string $col = 'technician_id'): array
    {
        return self::$onlyTech ? [" AND $col = ?", [self::$onlyTech]] : ['', []];
    }

    /** Bloqueia alteração de OS concluída sem a permissão "Editar OS depois do check-out". */
    public static function assertChangeable(array $o): void
    {
        if ($o['status'] === 'concluida' && !self::$afterCheckout) {
            throw new AppError('A OS já foi concluída (check-out). Você não tem permissão para alterá-la depois do check-out.', 403);
        }
    }
    /**
     * Resumo das ordens de serviço para o painel: em aberto por status, agenda de hoje, atrasadas,
     * concluídas no mês, pesquisa de satisfação e concluídas sem orçamento.
     */
    public static function dashboard(?int $userId = null): array
    {
        $today = date('Y-m-d');
        $d0 = "$today 00:00:00"; $d1 = "$today 23:59:59";
        $month = date('Y-m-01') . ' 00:00:00';
        $in = implode(',', array_fill(0, count(self::OPEN), '?'));
        [$ts, $tp] = self::techSql();
        [$tso, $tpo] = self::techSql('o.technician_id');
        $byStatus = array_fill_keys(self::OPEN, 0);
        foreach (DB::all("SELECT status, COUNT(*) n FROM service_orders WHERE status IN ($in)$ts GROUP BY status", array_merge(self::OPEN, $tp)) as $r) $byStatus[$r['status']] = (int)$r['n'];
        // Atrasada: prazo vencido, ou agendada para um dia que já passou sem o atendimento ter começado
        $lateSql = "status IN ($in) AND ((due_date IS NOT NULL AND due_date < ?) OR (status IN ('aberta','agendada') AND scheduled_at IS NOT NULL AND scheduled_at < ?))";
        $lateP = array_merge(self::OPEN, [$today, $d0], $tp);
        $lateSql .= $ts;
        $cols = 'o.id, o.status, o.scheduled_at, o.due_date, o.description, c.name customer_name, c.trade_name customer_trade_name,
            t.name technician_name, tt.name task_type_name, o.technician_id';
        $from = 'FROM service_orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN users t ON t.id = o.technician_id LEFT JOIN task_types tt ON tt.id = o.task_type_id';
        $survey = DB::one("SELECT COUNT(*) n, AVG(survey_rating) avg FROM service_orders WHERE survey_rating IS NOT NULL AND survey_at >= ?$ts", array_merge([$month], $tp));
        return [
            'open' => array_sum($byStatus),
            'by_status' => $byStatus,
            'unscheduled' => (int)DB::val("SELECT COUNT(*) FROM service_orders WHERE status IN ($in) AND scheduled_at IS NULL$ts", array_merge(self::OPEN, $tp)),
            'late_count' => (int)DB::val("SELECT COUNT(*) FROM service_orders WHERE $lateSql", $lateP),
            'late' => DB::all("SELECT $cols $from WHERE " . str_replace(['status', 'due_date', 'scheduled_at', 'technician_id'], ['o.status', 'o.due_date', 'o.scheduled_at', 'o.technician_id'], $lateSql) . ' ORDER BY COALESCE(o.due_date, o.scheduled_at) LIMIT 5', $lateP),
            'today' => DB::all("SELECT $cols $from WHERE o.scheduled_at BETWEEN ? AND ?$tso ORDER BY o.scheduled_at, o.id LIMIT 20", array_merge([$d0, $d1], $tpo)),
            'mine_today' => $userId ? (int)DB::val('SELECT COUNT(*) FROM service_orders WHERE technician_id = ? AND scheduled_at BETWEEN ? AND ?', [$userId, $d0, $d1]) : 0,
            'done_month' => (int)DB::val("SELECT COUNT(*) FROM service_orders WHERE status = 'concluida' AND finished_at >= ?$ts", array_merge([$month], $tp)),
            'done_today' => (int)DB::val("SELECT COUNT(*) FROM service_orders WHERE status = 'concluida' AND finished_at BETWEEN ? AND ?$ts", array_merge([$d0, $d1], $tp)),
            'survey' => ['count' => (int)$survey['n'], 'avg' => $survey['avg'] !== null ? round((float)$survey['avg'], 1) : null],
            // Concluídas nos últimos 30 dias que ainda não viraram orçamento (OS → "Abrir orçamento")
            'no_quote' => (int)DB::val("SELECT COUNT(*) FROM service_orders o WHERE o.status = 'concluida' AND o.finished_at >= ?
                AND o.quote_id IS NULL AND NOT EXISTS (SELECT 1 FROM quotes q WHERE q.service_order_id = o.id)$tso", array_merge([date('Y-m-d', strtotime('-30 days')) . ' 00:00:00'], $tpo)),
            'scoped' => self::$onlyTech !== null,
            'statuses' => self::STATUSES,
        ];
    }

    /**
     * Tarefas do app do técnico: OS agendadas no período (Y-m-d), do técnico ou de todos, com o número de pendências.
     */
    public static function techTasks(string $from, string $to, ?int $techId): array
    {
        $from = self::agendaDay($from, 'Período inicial');
        $to = self::agendaDay($to, 'Período final');
        if ($to < $from || (strtotime($to) - strtotime($from)) > 62 * 86400) throw new AppError('Período inválido');
        $next = (new DateTime($to))->modify('+1 day')->format('Y-m-d');
        $w = 'o.scheduled_at >= ? AND o.scheduled_at < ?'; $p = [$from . ' 00:00:00', $next . ' 00:00:00'];
        if (self::$onlyTech) $techId = self::$onlyTech;
        if ($techId) { $w .= ' AND o.technician_id = ?'; $p[] = $techId; }
        $rows = DB::all("SELECT o.id, o.status, o.scheduled_at, o.due_date, o.description, o.technician_id,
                c.name customer_name, c.trade_name customer_trade_name, t.name technician_name, tt.name task_type_name
            FROM service_orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN users t ON t.id = o.technician_id
            LEFT JOIN task_types tt ON tt.id = o.task_type_id WHERE $w ORDER BY o.scheduled_at, o.id LIMIT 300", $p);
        foreach ($rows as &$r) {
            $r['status_label'] = self::label($r['status']);
            $r['pending'] = $r['status'] === 'concluida' ? 0 : count(OrderExec::pending(self::get((int)$r['id'])));
        }
        unset($r);
        return $rows;
    }

    /** Início do app do técnico: OS de hoje, atrasadas, em execução e orçamentos lançados hoje. */
    public static function techHome(int $userId, bool $mine): array
    {
        $today = date('Y-m-d');
        $tech = $mine || self::$onlyTech ? $userId : null;
        $rows = self::techTasks($today, $today, $tech);
        $in = implode(',', array_fill(0, count(self::OPEN), '?'));
        $tw = $tech ? ' AND technician_id = ?' : '';
        $tp = $tech ? [$tech] : [];
        $late = (int)DB::val("SELECT COUNT(*) FROM service_orders WHERE status IN ($in) AND ((due_date IS NOT NULL AND due_date < ?)
            OR (status IN ('aberta','agendada') AND scheduled_at IS NOT NULL AND scheduled_at < ?))$tw", array_merge(self::OPEN, [$today, now()], $tp));
        $exec = (int)DB::val("SELECT COUNT(*) FROM service_orders WHERE status IN ('a_caminho','em_execucao')$tw", $tp);
        $q = DB::one('SELECT COUNT(*) n, COALESCE(SUM(total),0) total FROM quotes WHERE created_at >= ? AND created_at <= ?' . ($tech ? ' AND user_id = ?' : ''),
            array_merge([$today . ' 00:00:00', $today . ' 23:59:59'], $tp));
        return [
            'today' => $rows,
            'done' => count(array_filter($rows, fn($r) => $r['status'] === 'concluida')),
            'late' => $late,
            'running' => $exec,
            'quotes' => ['count' => (int)$q['n'], 'total' => (float)$q['total']],
        ];
    }

    /** Abas da lista: "abertas" são as que ainda não foram concluídas. */
    const OPEN = ['aberta', 'agendada', 'a_caminho', 'em_execucao', 'pausada'];

    public static function label(string $code): string
    {
        return self::STATUSES[$code] ?? $code;
    }

    public static function get(int $id): array
    {
        $o = DB::one('SELECT o.*, c.name customer_name, c.trade_name customer_trade_name, c.phone customer_phone, c.email customer_email, c.document customer_document,
                e.name equipment_name, e.brand equipment_brand, e.model equipment_model, e.serial equipment_serial,
                t.name technician_name, u.name user_name, q.id quote_ref, tt.name task_type_name, tt.duration_min task_type_duration,
                c.street customer_street, c.number customer_number, c.complement customer_complement, c.district customer_district,
                c.city customer_city, c.state customer_state, c.cep customer_cep
            FROM service_orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN equipments e ON e.id = o.equipment_id
            LEFT JOIN users t ON t.id = o.technician_id
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN quotes q ON q.id = o.quote_id
            LEFT JOIN task_types tt ON tt.id = o.task_type_id
            WHERE o.id = ?', [$id]);
        if (!$o || (self::$onlyTech && (int)$o['technician_id'] !== self::$onlyTech)) throw new AppError('Ordem de serviço não encontrada', 404);
        $o['status_label'] = self::label($o['status']);
        $o['origin_label'] = self::ORIGINS[$o['origin']] ?? $o['origin'];
        $o['transitions'] = self::TRANSITIONS[$o['status']] ?? [];
        return $o;
    }

    public static function list(array $f): array
    {
        $w = ['1=1']; $p = [];
        $status = (string)($f['status'] ?? 'todas');
        if ($status === 'abertas') { $w[] = 'o.status IN (' . implode(',', array_fill(0, count(self::OPEN), '?')) . ')'; array_push($p, ...self::OPEN); }
        elseif ($status !== '' && $status !== 'todas') {
            if (!isset(self::STATUSES[$status])) throw new AppError('Status inválido');
            $w[] = 'o.status = ?'; $p[] = $status;
        }
        if (!empty($f['customer_id'])) { $w[] = 'o.customer_id = ?'; $p[] = (int)$f['customer_id']; }
        if (self::$onlyTech) { $w[] = 'o.technician_id = ?'; $p[] = self::$onlyTech; }
        if (!empty($f['equipment_id'])) { $w[] = 'o.equipment_id = ?'; $p[] = (int)$f['equipment_id']; }
        if (($s = trim((string)($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($s) . '%';
            $w[] = '(LOWER(c.name) LIKE ? OR LOWER(e.name) LIKE ? OR LOWER(o.description) LIKE ? OR o.id = ?)';
            array_push($p, $like, $like, $like, (int)$s);
        }
        return DB::all("SELECT o.id, o.status, o.origin, o.task_type_id, o.scheduled_at, o.due_date, o.created_at, o.description, o.customer_id, o.equipment_id,
                c.name customer_name, c.trade_name customer_trade_name, e.name equipment_name, t.name technician_name, tt.name task_type_name
            FROM service_orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN equipments e ON e.id = o.equipment_id
            LEFT JOIN users t ON t.id = o.technician_id
            LEFT JOIN task_types tt ON tt.id = o.task_type_id
            WHERE " . implode(' AND ', $w) . ' ORDER BY (o.scheduled_at IS NULL), o.scheduled_at, o.id DESC LIMIT 300', $p);
    }

    /** Dados para montar o formulário, filtrados pelo cliente escolhido. */
    public static function formData(int $customerId): array
    {
        return [
            'equipments' => $customerId ? Equipments::list(['customer_id' => $customerId]) : [],
            'quotes' => $customerId ? DB::all('SELECT id, status, lead_name, created_at FROM quotes WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$customerId]) : [],
            'technicians' => self::$onlyTech ? DB::all('SELECT id, name FROM users WHERE id = ?', [self::$onlyTech]) : DB::all("SELECT id, name FROM users WHERE active = 1 ORDER BY name"),
            'task_types' => TaskTypes::list(),
            'statuses' => self::STATUSES,
            'origins' => self::ORIGINS,
        ];
    }

    public static function save(array $in, int $userId, ?int $id = null): int
    {
        $old = $id ? self::get($id) : null;
        if ($old) self::assertChangeable($old);
        $customerId = (int)($in['customer_id'] ?? 0);
        if (!$customerId) throw new AppError('Selecione o cliente');
        Customers::get($customerId);
        Removals::check('customers', $customerId, $old ? (int)$old['customer_id'] : null);
        $description = trim((string)($in['description'] ?? ''));
        if ($description === '') throw new AppError('Descreva o serviço solicitado');

        $origin = (string)($in['origin'] ?? 'chamado');
        if (!isset(self::ORIGINS[$origin])) throw new AppError('Origem inválida');
        $quoteId = null;
        if ($origin === 'orcamento') {
            $quoteId = (int)($in['quote_id'] ?? 0);
            if (!$quoteId || !DB::val('SELECT id FROM quotes WHERE id = ? AND customer_id = ?', [$quoteId, $customerId])) {
                throw new AppError('Selecione um orçamento deste cliente');
            }
        }

        $equipmentId = (int)($in['equipment_id'] ?? 0) ?: null;
        if ($equipmentId) {
            $eq = Equipments::get($equipmentId);
            if ((int)$eq['customer_id'] !== $customerId) throw new AppError('O equipamento não pertence a este cliente');
            Removals::check('equipments', $equipmentId, $old ? (int)$old['equipment_id'] : null);
        }

        // Tipo de tarefa: só tipos ativos (um tipo já usado pela OS continua aceito ao editar)
        $taskTypeId = (int)($in['task_type_id'] ?? 0) ?: null;
        if ($taskTypeId && (!$old || (int)$old['task_type_id'] !== $taskTypeId)) {
            $tt = TaskTypes::get($taskTypeId);
            if (!(int)$tt['active']) throw new AppError('Este tipo de tarefa está ' . ($tt['deleted_at'] ? 'excluído' : 'inativo'));
        }

        $technicianId = (int)($in['technician_id'] ?? 0) ?: null;
        if (self::$onlyTech) $technicianId = self::$onlyTech; // quem só vê as próprias OS não passa a OS para outro técnico
        if ($technicianId && !DB::val('SELECT id FROM users WHERE id = ? AND active = 1', [$technicianId])) {
            throw new AppError('Técnico não encontrado');
        }

        $scheduled = trim((string)($in['scheduled_at'] ?? ''));
        $scheduled = $scheduled !== '' ? str_replace('T', ' ', $scheduled) . (strlen($scheduled) === 16 ? ':00' : '') : null;
        $due = trim((string)($in['due_date'] ?? '')) ?: null;

        $data = [
            'customer_id' => $customerId,
            'equipment_id' => $equipmentId,
            'origin' => $origin,
            'quote_id' => $quoteId,
            'task_type_id' => $taskTypeId,
            'technician_id' => $technicianId,
            'scheduled_at' => $scheduled,
            'due_date' => $due,
            'description' => mb_substr($description, 0, 2000),
            'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null,
            'updated_at' => now(),
        ];
        if ($old) {
            // Agendar muda o status para "agendada" (ou volta para "aberta" ao tirar a data)
            if ($old['status'] === 'aberta' && $scheduled) $data['status'] = 'agendada';
            if ($old['status'] === 'agendada' && !$scheduled) $data['status'] = 'aberta';
            DB::update('service_orders', $id, $data);
            if (isset($data['status'])) OrderExec::logEvent($id, $old['status'], $data['status'], $userId);
            return $id;
        }
        $data += ['status' => $scheduled ? 'agendada' : 'aberta', 'user_id' => $userId, 'public_token' => bin2hex(random_bytes(16)), 'created_at' => now()];
        $newId = DB::insert('service_orders', $data);
        OrderExec::logEvent($newId, null, $data['status'], $userId);
        return $newId;
    }

    public static function setStatus(int $id, string $to, int $userId = 0, ?string $reason = null): void
    {
        $o = self::get($id);
        if (!isset(self::STATUSES[$to])) throw new AppError('Status inválido');
        if ($o['status'] === $to) return;
        if (!in_array($to, self::TRANSITIONS[$o['status']] ?? [], true)) {
            throw new AppError('Não é possível mudar de "' . $o['status_label'] . '" para "' . self::label($to) . '"');
        }
        // Concluir exige as obrigatoriedades do tipo de tarefa e as perguntas obrigatórias respondidas
        if ($to === 'concluida' && ($pend = OrderExec::pending($o))) {
            throw new AppError('Para concluir falta: ' . implode('; ', $pend) . '.');
        }
        $data = ['status' => $to, 'updated_at' => now()];
        if ($to === 'concluida') $data['finished_at'] = now();
        if ($to === 'em_execucao' && !$o['started_at']) $data['started_at'] = now();
        DB::update('service_orders', $id, $data);
        OrderExec::logEvent($id, $o['status'], $to, $userId, $reason);
        if ($to === 'concluida') OrderExec::onConcluded($id, $userId);
    }

    /**
     * Agenda: OS com data no período (from..to, Y-m-d), filtros opcionais, OS sem data
     * e contagens por situação (no sistema inteiro, sem filtro).
     */
    public static function agenda(array $f): array
    {
        $from = self::agendaDay($f['from'] ?? '', 'Período inicial');
        $to = self::agendaDay($f['to'] ?? '', 'Período final');
        if ($to < $from) throw new AppError('O período final é anterior ao inicial');
        if ((strtotime($to) - strtotime($from)) > 62 * 86400) throw new AppError('Período longo demais (máximo de 2 meses)');

        $filter = ['1=1']; $fp = [];
        if (self::$onlyTech) $f['tech'] = self::$onlyTech;
        if (!empty($f['tech'])) { $filter[] = 'o.technician_id = ?'; $fp[] = (int)$f['tech']; }
        if (!empty($f['task_type_id'])) { $filter[] = 'o.task_type_id = ?'; $fp[] = (int)$f['task_type_id']; }
        $status = (string)($f['status'] ?? '');
        if ($status !== '') {
            if (!isset(self::STATUSES[$status])) throw new AppError('Status inválido');
            $filter[] = 'o.status = ?'; $fp[] = $status;
        }
        if (($s = trim((string)($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($s) . '%';
            $filter[] = '(LOWER(c.name) LIKE ? OR LOWER(c.trade_name) LIKE ? OR LOWER(e.name) LIKE ? OR LOWER(o.description) LIKE ? OR o.id = ?)';
            array_push($fp, $like, $like, $like, $like, (int)$s);
        }
        $where = implode(' AND ', $filter);
        $select = "SELECT o.id, o.status, o.scheduled_at, o.due_date, o.description, o.customer_id, o.technician_id, o.task_type_id,
                c.name customer_name, c.trade_name customer_trade_name, e.name equipment_name, t.name technician_name,
                tt.name task_type_name, tt.duration_min
            FROM service_orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN equipments e ON e.id = o.equipment_id
            LEFT JOIN users t ON t.id = o.technician_id
            LEFT JOIN task_types tt ON tt.id = o.task_type_id ";

        $next = (new DateTime($to))->modify('+1 day')->format('Y-m-d');
        // Atrasadas: agendadas com horário já passado, de qualquer data (ignora o período)
        $rows = !empty($f['late'])
            ? DB::all($select . "WHERE o.status = 'agendada' AND o.scheduled_at < ? AND $where ORDER BY o.scheduled_at, o.id LIMIT 300", array_merge([now()], $fp))
            : DB::all($select . "WHERE o.scheduled_at >= ? AND o.scheduled_at < ? AND $where ORDER BY o.scheduled_at, o.id",
                array_merge([$from . ' 00:00:00', $next . ' 00:00:00'], $fp));
        $unscheduled = DB::all($select . "WHERE o.scheduled_at IS NULL AND o.status = 'aberta' AND $where ORDER BY o.id DESC LIMIT 200", $fp);

        $today = date('Y-m-d');
        $tomorrow = (new DateTime($today))->modify('+1 day')->format('Y-m-d');
        [$tso, $tpo] = self::techSql('o.technician_id');
        $count = fn(string $sql, array $p = []) => (int)DB::val("SELECT COUNT(*) FROM service_orders o WHERE $sql$tso", array_merge($p, $tpo));
        $counts = [
            'atrasadas' => $count("o.status = 'agendada' AND o.scheduled_at < ?", [now()]),
            'hoje' => $count("o.status IN ('agendada','a_caminho','em_execucao','pausada') AND o.scheduled_at >= ? AND o.scheduled_at < ?", [$today . ' 00:00:00', $tomorrow . ' 00:00:00']),
            'em_execucao' => $count("o.status = 'em_execucao'"),
            'pausada' => $count("o.status = 'pausada'"),
            'sem_agendamento' => $count("o.status = 'aberta' AND o.scheduled_at IS NULL"),
            'concluida' => $count("o.status = 'concluida'"),
        ];

        foreach ([&$rows, &$unscheduled] as &$list) {
            foreach ($list as &$r) $r['status_label'] = self::label($r['status']);
            unset($r);
        }
        unset($list);

        return [
            'from' => $from, 'to' => $to,
            'rows' => $rows,
            'unscheduled' => $unscheduled,
            'counts' => $counts,
            'statuses' => self::STATUSES,
            'technicians' => self::$onlyTech ? DB::all('SELECT id, name FROM users WHERE id = ?', [self::$onlyTech]) : DB::all('SELECT id, name FROM users WHERE active = 1 ORDER BY name'),
            'task_types' => DB::all('SELECT id, name FROM task_types WHERE active = 1 ORDER BY name'),
            'scoped' => self::$onlyTech !== null,
        ];
    }

    private static function agendaDay($v, string $campo): string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new AppError("$campo inválido");
        }
        return $v;
    }

    /**
     * Reagenda pela agenda (arrastar ou escolher data): só data, horário e técnico.
     * Sem data volta a OS para "aberta". Só ordens abertas ou agendadas.
     */
    public static function reschedule(int $id, string $when, $technicianId, int $userId = 0): void
    {
        $o = self::get($id);
        if (!in_array($o['status'], ['aberta', 'agendada'], true)) {
            throw new AppError('Só é possível reagendar ordens abertas ou agendadas');
        }
        $sched = null;
        $when = trim($when);
        if ($when !== '') {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $when, $m)) {
                throw new AppError('Data e horário do agendamento inválidos');
            }
            $sched = $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
        }
        $tech = (int)$technicianId ?: null;
        if (self::$onlyTech) $tech = self::$onlyTech;
        if ($tech && !DB::val('SELECT id FROM users WHERE id = ? AND active = 1', [$tech])) throw new AppError('Técnico não encontrado');

        $data = ['scheduled_at' => $sched, 'technician_id' => $tech, 'updated_at' => now()];
        if ($o['status'] === 'aberta' && $sched) $data['status'] = 'agendada';
        if ($o['status'] === 'agendada' && !$sched) $data['status'] = 'aberta';
        DB::update('service_orders', $id, $data);
        if (isset($data['status'])) OrderExec::logEvent($id, $o['status'], $data['status'], $userId);
    }
}
