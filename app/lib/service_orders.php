<?php
/**
 * Ordens de serviço (OS): atendimento técnico ao cliente, com equipamento, modelo de checklist e técnico.
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

class ChecklistModels
{
    const SERVICE_TYPES = [
        'corretiva' => 'Corretiva', 'preventiva' => 'Preventiva', 'instalacao' => 'Instalação',
        'inspecao' => 'Inspeção', 'visita' => 'Visita técnica',
    ];

    public static function get(int $id): array
    {
        $m = DB::one('SELECT * FROM checklist_models WHERE id = ?', [$id]);
        if (!$m) throw new AppError('Modelo de checklist não encontrado', 404);
        $m['items'] = json_decode((string)$m['items'], true) ?: [];
        return $m;
    }

    public static function list(bool $all = false): array
    {
        $rows = DB::all('SELECT * FROM checklist_models' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY name');
        foreach ($rows as &$m) $m['items'] = json_decode((string)$m['items'], true) ?: [];
        unset($m);
        return $rows;
    }

    /** $in['items'] aceita uma lista ou um texto com um item por linha. */
    public static function save(array $in, ?int $id = null): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new AppError('Informe o nome do modelo de checklist');
        $type = (string)($in['service_type'] ?? '');
        if ($type !== '' && !isset(self::SERVICE_TYPES[$type])) throw new AppError('Tipo de serviço inválido');
        $raw = $in['items'] ?? [];
        if (is_string($raw)) $raw = preg_split('/\R/', $raw);
        $items = [];
        foreach ((array)$raw as $line) {
            $label = trim((string)(is_array($line) ? ($line['label'] ?? '') : $line));
            if ($label !== '') $items[] = ['label' => mb_substr($label, 0, 200), 'required' => !is_array($line) || !empty($line['required'])];
        }
        if (!$items) throw new AppError('Inclua pelo menos um item no checklist');
        $data = [
            'name' => mb_substr($name, 0, 120),
            'service_type' => $type ?: null,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];
        if ($id) {
            self::get($id);
            DB::update('checklist_models', $id, $data);
            return $id;
        }
        $data += ['active' => 1, 'created_at' => now()];
        return DB::insert('checklist_models', $data);
    }

    public static function setActive(int $id, bool $active): void
    {
        self::get($id);
        DB::update('checklist_models', $id, ['active' => $active ? 1 : 0, 'updated_at' => now()]);
    }
}

class ServiceOrders
{
    const STATUSES = [
        'aberta' => 'Aberta', 'agendada' => 'Agendada', 'em_execucao' => 'Em execução',
        'pausada' => 'Pausada', 'concluida' => 'Concluída', 'faturada' => 'Faturada',
    ];
    /** Andamento permitido: de → para. "faturada" é o fim do fluxo. */
    const TRANSITIONS = [
        'aberta' => ['agendada'],
        'agendada' => ['aberta', 'em_execucao'],
        'em_execucao' => ['pausada', 'concluida'],
        'pausada' => ['em_execucao'],
        'concluida' => ['faturada'],
        'faturada' => [],
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
        $o = DB::one('SELECT o.*, c.name customer_name, c.trade_name customer_trade_name, c.phone customer_phone,
                e.name equipment_name, e.brand equipment_brand, e.model equipment_model, e.serial equipment_serial,
                t.name technician_name, u.name user_name, q.id quote_ref
            FROM service_orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN equipments e ON e.id = o.equipment_id
            LEFT JOIN users t ON t.id = o.technician_id
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN quotes q ON q.id = o.quote_id
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
        if (($s = trim((string)($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($s) . '%';
            $w[] = '(LOWER(c.name) LIKE ? OR LOWER(e.name) LIKE ? OR LOWER(o.description) LIKE ? OR o.id = ?)';
            array_push($p, $like, $like, $like, (int)$s);
        }
        return DB::all("SELECT o.id, o.status, o.origin, o.service_type, o.scheduled_at, o.due_date, o.created_at,
                c.name customer_name, c.trade_name customer_trade_name, e.name equipment_name, t.name technician_name
            FROM service_orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN equipments e ON e.id = o.equipment_id
            LEFT JOIN users t ON t.id = o.technician_id
            WHERE " . implode(' AND ', $w) . ' ORDER BY (o.scheduled_at IS NULL), o.scheduled_at, o.id DESC LIMIT 300', $p);
    }

    /** Dados para montar o formulário, filtrados pelo cliente escolhido. */
    public static function formData(int $customerId): array
    {
        return [
            'equipments' => $customerId ? Equipments::list(['customer_id' => $customerId]) : [],
            'quotes' => $customerId ? DB::all('SELECT id, status, lead_name, created_at FROM quotes WHERE customer_id = ? ORDER BY id DESC LIMIT 100', [$customerId]) : [],
            'technicians' => DB::all("SELECT id, name FROM users WHERE active = 1 ORDER BY name"),
            'checklists' => ChecklistModels::list(),
            'service_types' => ChecklistModels::SERVICE_TYPES,
            'statuses' => self::STATUSES,
            'origins' => self::ORIGINS,
        ];
    }

    public static function save(array $in, int $userId, ?int $id = null): int
    {
        $old = $id ? self::get($id) : null;
        if ($old && $old['status'] === 'faturada') throw new AppError('Ordem faturada não pode ser alterada');
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

        $type = (string)($in['service_type'] ?? '');
        if ($type !== '' && !isset(ChecklistModels::SERVICE_TYPES[$type])) throw new AppError('Tipo de serviço inválido');

        $checklistId = (int)($in['checklist_model_id'] ?? 0) ?: null;
        if ($checklistId) ChecklistModels::get($checklistId);

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
            'service_type' => $type ?: null,
            'checklist_model_id' => $checklistId,
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
            return $id;
        }
        $data += ['status' => $scheduled ? 'agendada' : 'aberta', 'user_id' => $userId, 'created_at' => now()];
        return DB::insert('service_orders', $data);
    }

    public static function setStatus(int $id, string $to): void
    {
        $o = self::get($id);
        if (!isset(self::STATUSES[$to])) throw new AppError('Status inválido');
        if ($o['status'] === $to) return;
        if (!in_array($to, self::TRANSITIONS[$o['status']] ?? [], true)) {
            throw new AppError('Não é possível mudar de "' . $o['status_label'] . '" para "' . self::label($to) . '"');
        }
        $data = ['status' => $to, 'updated_at' => now()];
        if ($to === 'concluida') $data['finished_at'] = now();
        DB::update('service_orders', $id, $data);
    }
}
