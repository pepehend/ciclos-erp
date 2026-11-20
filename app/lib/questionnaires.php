<?php
/**
 * Questionários e tipos de tarefa (como no Auvo).
 * Questionário = perguntas de vários tipos (texto, check, foto, assinatura…) com regras de exibição.
 * Tipo de tarefa = nome, tempos, quais questionários entram e o que é obrigatório no atendimento.
 * Substituem os antigos "modelos de checklist" (que viram questionários na atualização).
 */

class Questionnaires
{
    const TYPES = [
        'texto' => 'Texto', 'numerica' => 'Numérica', 'check' => 'Check', 'monetaria' => 'Monetária',
        'data' => 'Data', 'itens' => 'Itens', 'documento' => 'CPF/CNPJ', 'hora' => 'Hora',
        'multipla' => 'Itens com múltipla escolha', 'area' => 'Área de texto', 'assinatura' => 'Assinatura', 'foto' => 'Foto',
    ];
    /** Tipos que pedem a lista de opções. */
    const OPTION_TYPES = ['itens', 'multipla'];
    const MAX_QUESTIONS = 100;

    public static function get(int $id): array
    {
        $q = DB::one('SELECT * FROM questionnaires WHERE id = ?', [$id]);
        if (!$q) throw new AppError('Questionário não encontrado', 404);
        return self::decode($q);
    }

    public static function list(bool $all = false): array
    {
        $rows = DB::all('SELECT * FROM questionnaires' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY name');
        return array_map([self::class, 'decode'], $rows);
    }

    /** Questionários ativos com id e nome (para escolher nos tipos de tarefa). */
    public static function options(): array
    {
        return array_map(fn($q) => ['id' => (int)$q['id'], 'name' => $q['name']], DB::all('SELECT id, name FROM questionnaires WHERE active = 1 ORDER BY name'));
    }

    private static function decode(array $q): array
    {
        $q['questions'] = json_decode((string)$q['questions'], true) ?: [];
        $q['questions_count'] = count($q['questions']);
        foreach (['show_os', 'pairs', 'show_unanswered', 'allow_equipment', 'equipment_required', 'active'] as $k) $q[$k] = (int)$q[$k];
        $q['per_line'] = (int)$q['per_line'];
        return $q;
    }

    /**
     * Valida e arruma as perguntas. Cada pergunta: id, label, type, required, description,
     * options (só itens/múltipla), geo (só foto) e cond = {question, value} (condicional a uma pergunta anterior).
     */
    public static function normalizeQuestions($raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
        $out = [];
        $ids = [];
        foreach ((array)$raw as $i => $q) {
            if (!is_array($q)) continue;
            $label = trim((string)($q['label'] ?? ''));
            if ($label === '') throw new AppError('Informe o texto de todas as perguntas');
            $where = 'da pergunta "' . mb_substr($label, 0, 40) . '"';
            $type = (string)($q['type'] ?? '');
            if (!isset(self::TYPES[$type])) throw new AppError('Tipo de resposta inválido ' . $where);
            $id = preg_replace('/[^a-z0-9_]/i', '', (string)($q['id'] ?? '')) ?: 'p' . ($i + 1);
            while (isset($ids[$id])) $id .= 'x';
            $ids[$id] = true;
            $item = [
                'id' => $id,
                'label' => mb_substr($label, 0, 200),
                'type' => $type,
                'required' => !empty($q['required']),
                'description' => mb_substr(trim((string)($q['description'] ?? '')), 0, 300) ?: null,
            ];
            if (in_array($type, self::OPTION_TYPES, true)) {
                $opts = $q['options'] ?? [];
                if (is_string($opts)) $opts = preg_split('/\R/', $opts);
                $clean = [];
                foreach ((array)$opts as $o) {
                    $o = trim((string)$o);
                    if ($o !== '') $clean[] = mb_substr($o, 0, 120);
                }
                $clean = array_values(array_unique($clean));
                if (!$clean) throw new AppError('Inclua as opções ' . $where);
                $item['options'] = $clean;
            }
            if ($type === 'foto') $item['geo'] = !empty($q['geo']);
            if (!empty($q['cond']) && is_array($q['cond'])) {
                $cq = (string)($q['cond']['question'] ?? '');
                $cv = trim((string)($q['cond']['value'] ?? ''));
                $prev = array_column($out, 'id');
                if (!in_array($cq, $prev, true) || $cv === '') throw new AppError('A condição ' . $where . ' precisa de uma pergunta anterior e de uma resposta');
                $item['cond'] = ['question' => $cq, 'value' => mb_substr($cv, 0, 120)];
            }
            $out[] = $item;
            if (count($out) > self::MAX_QUESTIONS) throw new AppError('Máximo de ' . self::MAX_QUESTIONS . ' perguntas por questionário');
        }
        if (!$out) throw new AppError('Inclua pelo menos uma pergunta');
        return $out;
    }

    public static function save(array $in, ?int $id = null): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new AppError('Informe o nome do questionário');
        $perLine = (int)($in['per_line'] ?? 1);
        if (!in_array($perLine, [1, 2, 3, 4], true)) throw new AppError('Perguntas por linha deve ser de 1 a 4');
        $allowEq = !empty($in['allow_equipment']);
        $data = [
            'name' => mb_substr($name, 0, 120),
            'questions' => json_encode(self::normalizeQuestions($in['questions'] ?? []), JSON_UNESCAPED_UNICODE),
            'show_os' => !empty($in['show_os']) ? 1 : 0,
            'per_line' => $perLine,
            'pairs' => !empty($in['pairs']) ? 1 : 0,
            'show_unanswered' => !empty($in['show_unanswered']) ? 1 : 0,
            'allow_equipment' => $allowEq ? 1 : 0,
            'equipment_required' => $allowEq && !empty($in['equipment_required']) ? 1 : 0,
            'updated_at' => now(),
        ];
        if ($id) {
            self::get($id);
            DB::update('questionnaires', $id, $data);
            return $id;
        }
        $data += ['active' => 1, 'created_at' => now()];
        return DB::insert('questionnaires', $data);
    }

    /** Questionário usado por um tipo de tarefa não se apaga, só se desativa. */
    public static function setActive(int $id, bool $active): void
    {
        self::get($id);
        DB::update('questionnaires', $id, ['active' => $active ? 1 : 0, 'updated_at' => now()]);
    }
}

class TaskTypes
{
    /** Obrigatoriedades do tipo (chave => texto). "questionario" exige responder os questionários escolhidos. */
    const REQUIRES = [
        'relato' => 'Preencher o relato', 'questionario' => 'Responder os questionários',
        'assinatura' => 'Colher assinatura', 'assinante_nome' => 'Exigir nome do assinante',
        'assinante_doc' => 'Exigir CPF/CNPJ do assinante', 'fotos' => 'Colher fotos',
        'km' => 'Preencher km rodado', 'os_email' => 'Enviar OS digital por e-mail',
        'produto' => 'Incluir produto', 'servico' => 'Incluir serviço', 'custo' => 'Incluir custo adicional',
    ];
    /** Tipos criados na atualização, a partir dos antigos tipos fixos de OS. */
    const DEFAULT_TYPES = ['corretiva' => 'Corretiva', 'preventiva' => 'Preventiva', 'instalacao' => 'Instalação', 'inspecao' => 'Inspeção', 'visita' => 'Visita técnica'];

    public static function get(int $id): array
    {
        $t = DB::one('SELECT * FROM task_types WHERE id = ?', [$id]);
        if (!$t) throw new AppError('Tipo de tarefa não encontrado', 404);
        return self::decode($t);
    }

    /** id => nome dos tipos ativos (usado nas listas de OS). */
    public static function names(): array
    {
        $out = [];
        foreach (DB::all('SELECT id, name FROM task_types') as $t) $out[(int)$t['id']] = $t['name'];
        return $out;
    }

    public static function list(bool $all = false): array
    {
        $rows = DB::all('SELECT * FROM task_types' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY name');
        return array_map([self::class, 'decode'], $rows);
    }

    private static function decode(array $t): array
    {
        $t['requires'] = array_values(array_filter(array_map('trim', explode(',', (string)$t['requires'])), fn($k) => isset(self::REQUIRES[$k])));
        $t['questionnaire_ids'] = array_map('intval', json_decode((string)$t['questionnaire_ids'], true) ?: []);
        $t['tolerance'] = self::hm($t['tolerance_min']);
        $t['duration'] = self::hm($t['duration_min']);
        foreach (['send_survey', 'send_os_email', 'active'] as $k) $t[$k] = (int)$t[$k];
        return $t;
    }

    /** Minutos → "HH:MM" (ou vazio). */
    public static function hm($min): string
    {
        if ($min === null || $min === '') return '';
        return sprintf('%02d:%02d', intdiv((int)$min, 60), (int)$min % 60);
    }

    /** Aceita "HH:MM", "H:MM" ou minutos. Vazio vira null. */
    public static function minutes($v, string $campo): ?int
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '00:00') return null;
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $v, $m)) return (int)$m[1] * 60 + (int)$m[2];
        if (ctype_digit($v)) return (int)$v;
        throw new AppError("$campo deve estar no formato HH:MM");
    }

    public static function save(array $in, ?int $id = null): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new AppError('Informe a descrição do tipo de tarefa');
        $reqs = [];
        foreach ((array)($in['requires'] ?? []) as $k) {
            if (!isset(self::REQUIRES[$k])) throw new AppError('Obrigatoriedade inválida');
            $reqs[$k] = $k;
        }
        $qids = [];
        foreach ((array)($in['questionnaire_ids'] ?? []) as $qid) {
            $qid = (int)$qid;
            if (!$qid) continue;
            if (!DB::val('SELECT id FROM questionnaires WHERE id = ? AND active = 1', [$qid])) throw new AppError('Questionário inválido ou inativo');
            $qids[$qid] = $qid;
        }
        if (isset($reqs['questionario']) && !$qids) throw new AppError('Escolha pelo menos um questionário para exigir a resposta');
        $data = [
            'name' => mb_substr($name, 0, 120),
            'tolerance_min' => self::minutes($in['tolerance'] ?? '', 'Tempo de tolerância'),
            'duration_min' => self::minutes($in['duration'] ?? '', 'Tempo estimado de duração'),
            'questionnaire_ids' => json_encode(array_values($qids)),
            'requires' => implode(',', array_keys($reqs)),
            'send_survey' => !empty($in['send_survey']) ? 1 : 0,
            'send_os_email' => !empty($in['send_os_email']) ? 1 : 0,
            'updated_at' => now(),
        ];
        if ($id) {
            self::get($id);
            DB::update('task_types', $id, $data);
            return $id;
        }
        $data += ['active' => 1, 'created_at' => now()];
        return DB::insert('task_types', $data);
    }

    public static function setActive(int $id, bool $active): void
    {
        self::get($id);
        DB::update('task_types', $id, ['active' => $active ? 1 : 0, 'updated_at' => now()]);
    }

    /**
     * Atualização: os modelos de checklist viram questionários (cada item vira uma pergunta "check"),
     * os tipos fixos de OS viram tipos de tarefa, e as ordens antigas recebem o tipo correspondente.
     */
    public static function importLegacy(): void
    {
        $qByCode = [];
        foreach (DB::all('SELECT * FROM checklist_models ORDER BY id') as $m) {
            $items = json_decode((string)$m['items'], true) ?: [];
            $questions = [];
            foreach ($items as $i => $it) {
                $questions[] = ['id' => 'p' . ($i + 1), 'label' => mb_substr((string)$it['label'], 0, 200), 'type' => 'check', 'required' => !empty($it['required'])];
            }
            if (!$questions) continue;
            $qid = DB::insert('questionnaires', [
                'name' => $m['name'], 'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
                'show_os' => 1, 'per_line' => 1, 'pairs' => 0, 'show_unanswered' => 1,
                'allow_equipment' => 0, 'equipment_required' => 0, 'active' => (int)$m['active'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($m['service_type'] && !isset($qByCode[$m['service_type']])) $qByCode[$m['service_type']] = $qid;
        }
        $ttByCode = [];
        foreach (self::DEFAULT_TYPES as $code => $label) {
            $ttByCode[$code] = DB::insert('task_types', [
                'name' => $label, 'tolerance_min' => null, 'duration_min' => null,
                'questionnaire_ids' => json_encode(isset($qByCode[$code]) ? [$qByCode[$code]] : []),
                'requires' => '', 'send_survey' => 0, 'send_os_email' => 0, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($ttByCode as $code => $tid) {
            DB::run('UPDATE service_orders SET task_type_id = ? WHERE service_type = ? AND task_type_id IS NULL', [$tid, $code]);
        }
    }
}
