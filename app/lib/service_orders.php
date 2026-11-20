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
            self::get($id);
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
        self::get($id);
        DB::update('equipments', $id, ['active' => $active ? 1 : 0, 'updated_at' => now()]);
    }
}

class ServiceOrders
{
    const STATUSES = [
        'aberta' => 'Aberta', 'agendada' => 'Agendada', 'em_execucao' => 'Em execução',
        'pausada' => 'Pausada', 'concluida' => 'Concluída',
    ];
    /** Andamento permitido: de → para. "concluida" é o fim do fluxo (a OS não fatura; quem fatura é o orçamento). */
    const TRANSITIONS = [
        'aberta' => ['agendada'],
        'agendada' => ['aberta', 'em_execucao'],
        'em_execucao' => ['pausada', 'concluida'],
        'pausada' => ['em_execucao'],
        'concluida' => [],
    ];
    const ORIGINS = ['chamado' => 'Chamado avulso', 'orcamento' => 'Orçamento'];
    /** Abas da lista: "abertas" são as que ainda não foram concluídas. */
    const OPEN = ['aberta', 'agendada', 'em_execucao', 'pausada'];

    public static function label(string $code): string
    {
        return self::STATUSES[$code] ?? $code;
    }

    public static function get(int $id): array
    {
        $o = DB::one('SELECT o.*, c.name customer_name, c.trade_name customer_trade_name, c.phone customer_phone, c.email customer_email, c.document customer_document,
                e.name equipment_name, e.brand equipment_brand, e.model equipment_model, e.serial equipment_serial,
                t.name technician_name, u.name user_name, q.id quote_ref, tt.name task_type_name
            FROM service_orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN equipments e ON e.id = o.equipment_id
            LEFT JOIN users t ON t.id = o.technician_id
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN quotes q ON q.id = o.quote_id
            LEFT JOIN task_types tt ON tt.id = o.task_type_id
            WHERE o.id = ?', [$id]);
        if (!$o) throw new AppError('Ordem de serviço não encontrada', 404);
        $o['status_label'] = self::label($o['status']);
        $o['origin_label'] = self::ORIGINS[$o['origin']] ?? $o['origin'];
        $o['transitions'] = self::TRANSITIONS[$o['status']] ?? [];
        return $o;
    }

    public static function list(array $f): array
    {
        $w = ['1=1']; $p = [];
        $status = (string)($f['status'] ?? 'abertas');
        if ($status === 'abertas') { $w[] = 'o.status IN (' . implode(',', array_fill(0, count(self::OPEN), '?')) . ')'; array_push($p, ...self::OPEN); }
        elseif ($status !== '' && $status !== 'todas') {
            if (!isset(self::STATUSES[$status])) throw new AppError('Status inválido');
            $w[] = 'o.status = ?'; $p[] = $status;
        }
        if (!empty($f['customer_id'])) { $w[] = 'o.customer_id = ?'; $p[] = (int)$f['customer_id']; }
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
            'technicians' => DB::all("SELECT id, name FROM users WHERE active = 1 ORDER BY name"),
            'task_types' => TaskTypes::list(),
            'statuses' => self::STATUSES,
            'origins' => self::ORIGINS,
        ];
    }

    public static function save(array $in, int $userId, ?int $id = null): int
    {
        $old = $id ? self::get($id) : null;
        $customerId = (int)($in['customer_id'] ?? 0);
        if (!$customerId) throw new AppError('Selecione o cliente');
        Customers::get($customerId);
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
        }

        // Tipo de tarefa: só tipos ativos (um tipo já usado pela OS continua aceito ao editar)
        $taskTypeId = (int)($in['task_type_id'] ?? 0) ?: null;
        if ($taskTypeId && (!$old || (int)$old['task_type_id'] !== $taskTypeId)) {
            $tt = TaskTypes::get($taskTypeId);
            if (!(int)$tt['active']) throw new AppError('Este tipo de tarefa está inativo');
        }

        $technicianId = (int)($in['technician_id'] ?? 0) ?: null;
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
        $count = fn(string $sql, array $p = []) => (int)DB::val("SELECT COUNT(*) FROM service_orders o WHERE $sql", $p);
        $counts = [
            'atrasadas' => $count("o.status = 'agendada' AND o.scheduled_at < ?", [now()]),
            'hoje' => $count("o.status IN ('agendada','em_execucao','pausada') AND o.scheduled_at >= ? AND o.scheduled_at < ?", [$today . ' 00:00:00', $tomorrow . ' 00:00:00']),
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
            'technicians' => DB::all('SELECT id, name FROM users WHERE active = 1 ORDER BY name'),
            'task_types' => DB::all('SELECT id, name FROM task_types WHERE active = 1 ORDER BY name'),
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
        if ($tech && !DB::val('SELECT id FROM users WHERE id = ? AND active = 1', [$tech])) throw new AppError('Técnico não encontrado');

        $data = ['scheduled_at' => $sched, 'technician_id' => $tech, 'updated_at' => now()];
        if ($o['status'] === 'aberta' && $sched) $data['status'] = 'agendada';
        if ($o['status'] === 'agendada' && !$sched) $data['status'] = 'aberta';
        DB::update('service_orders', $id, $data);
        if (isset($data['status'])) OrderExec::logEvent($id, $o['status'], $data['status'], $userId);
    }
}
