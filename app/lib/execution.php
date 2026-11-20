<?php
/**
 * Execução da ordem de serviço: respostas dos questionários, relato, fotos, assinatura, km,
 * itens (valores), histórico de status (controle de horas), pendências para concluir,
 * link público (OS digital) e envios por e-mail (OS e pesquisa de satisfação).
 */

/** Envio de e-mail pelo servidor (função mail() do PHP, disponível na hospedagem cPanel). */
class Mailer
{
    /** Troca o envio nos testes: fn(string $to, string $subject, string $html): bool */
    public static $transport = null;

    public static function send(string $to, string $subject, string $html): void
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new AppError('E-mail do destinatário inválido');
        if (self::$transport) {
            if (!(self::$transport)($to, $subject, $html)) throw new AppError('Não foi possível enviar o e-mail');
            return;
        }
        if (!function_exists('mail')) throw new AppError('Este servidor não envia e-mails');
        $co = Settings::all();
        $name = $co['company_name'] ?: 'Sistema de Gestão de Serviços';
        $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'From: ' . $enc($name) . ' <noreply@' . $host . '>',
        ];
        if (filter_var($co['company_email'] ?? '', FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $co['company_email'];
        if (!@mail($to, $enc($subject), chunk_split(base64_encode($html)), implode("\r\n", $headers))) {
            throw new AppError('O servidor não conseguiu enviar o e-mail. Tente de novo mais tarde.');
        }
    }
}

class OrderExec
{
    const KINDS = ['produto' => 'Produto', 'servico' => 'Serviço', 'custo' => 'Custo adicional'];
    const MAX_PHOTOS = 30;
    const MAX_IMAGE = 1200000; // tamanho máximo de uma imagem em data URL (~900 KB)

    // ---------------- Auxiliares ----------------

    private static function editable(array $o): void
    {
        if ($o['status'] === 'faturada') throw new AppError('Ordem faturada não pode ser alterada');
    }

    /** Valida uma imagem em data URL (PNG, JPEG ou WebP). */
    public static function image($v, string $campo): string
    {
        $v = (string)$v;
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $v, $m)) throw new AppError("$campo: imagem inválida");
        if (strlen($v) > self::MAX_IMAGE) throw new AppError("$campo: imagem grande demais");
        if (base64_decode($m[2], true) === false) throw new AppError("$campo: imagem inválida");
        return $v;
    }

    private static function num($v): ?float
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace(' ', '', $v);
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
        return is_numeric($v) ? (float)$v : throw new AppError('Número inválido: ' . $v);
    }

    // ---------------- Questionários ----------------

    /** A pergunta aparece? Depende da resposta da pergunta da condição (que também precisa aparecer). */
    public static function visibleMap(array $questions, array $ans): array
    {
        $vis = [];
        foreach ($questions as $q) {
            $ok = true;
            if (!empty($q['cond'])) {
                $pid = $q['cond']['question'];
                $want = mb_strtolower(trim((string)$q['cond']['value']));
                $v = $ans[$pid] ?? null;
                if (empty($vis[$pid])) $ok = false;
                elseif (is_array($v)) $ok = in_array($want, array_map(fn($x) => mb_strtolower(trim((string)$x)), $v), true);
                else $ok = mb_strtolower(trim((string)$v)) === $want;
            }
            $vis[$q['id']] = $ok;
        }
        return $vis;
    }

    public static function filled(array $q, $v): bool
    {
        if (is_array($v)) return count($v) > 0;
        if ($q['type'] === 'check') return $v === 'Sim';
        return trim((string)$v) !== '';
    }

    /** Perguntas obrigatórias (visíveis) sem resposta. */
    public static function missing(array $qn, array $ans): array
    {
        $vis = self::visibleMap($qn['questions'], $ans);
        $out = [];
        foreach ($qn['questions'] as $q) {
            if (!empty($q['required']) && $vis[$q['id']] && !self::filled($q, $ans[$q['id']] ?? null)) $out[] = $q['label'];
        }
        return $out;
    }

    /** Confere cada resposta conforme o tipo da pergunta. Respostas de perguntas escondidas são descartadas. */
    public static function normalizeAnswers(array $qn, $raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
        $raw = (array)$raw;
        $out = [];
        foreach ($qn['questions'] as $q) {
            $id = $q['id'];
            if (!array_key_exists($id, $raw)) continue;
            $v = $raw[$id];
            $w = 'Resposta inválida em "' . mb_substr($q['label'], 0, 50) . '"';
            switch ($q['type']) {
                case 'multipla':
                    $v = array_values(array_filter(array_map('strval', (array)$v), fn($x) => $x !== ''));
                    foreach ($v as $x) if (!in_array($x, $q['options'], true)) throw new AppError($w);
                    $v = array_values(array_unique($v));
                    break;
                case 'foto':
                    $list = [];
                    foreach ((array)$v as $ph) {
                        if (!is_array($ph)) continue;
                        if (count($list) >= 10) throw new AppError('Máximo de 10 fotos por pergunta');
                        $item = ['src' => self::image($ph['src'] ?? '', 'Foto de "' . mb_substr($q['label'], 0, 40) . '"'), 'at' => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($ph['at'] ?? '')) ? $ph['at'] : now()];
                        if (!empty($q['geo']) && isset($ph['lat'], $ph['lng']) && is_numeric($ph['lat']) && is_numeric($ph['lng'])
                            && abs((float)$ph['lat']) <= 90 && abs((float)$ph['lng']) <= 180) {
                            $item['lat'] = round((float)$ph['lat'], 6); $item['lng'] = round((float)$ph['lng'], 6);
                        }
                        $list[] = $item;
                    }
                    $v = $list;
                    break;
                case 'assinatura':
                    $v = (string)$v === '' ? '' : self::image($v, 'Assinatura de "' . mb_substr($q['label'], 0, 40) . '"');
                    break;
                case 'check':
                    $v = in_array($v, [true, 1, '1', 'Sim', 'sim', 'on', 'true'], true) ? 'Sim' : '';
                    break;
                default:
                    if (is_array($v)) throw new AppError($w);
                    $v = trim((string)$v);
                    if ($v === '') break;
                    if ($q['type'] === 'itens' && !in_array($v, $q['options'], true)) throw new AppError($w);
                    if ($q['type'] === 'numerica') { try { $v = rtrim(rtrim(number_format(self::num($v), 4, '.', ''), '0'), '.'); } catch (AppError $e) { throw new AppError($w); } }
                    if ($q['type'] === 'monetaria') { try { $v = number_format(self::num($v), 2, '.', ''); } catch (AppError $e) { throw new AppError($w); } }
                    if ($q['type'] === 'data' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) throw new AppError($w);
                    if ($q['type'] === 'hora' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) throw new AppError($w);
                    if ($q['type'] === 'documento') { $v = preg_replace('/\D/', '', $v); if (!in_array(strlen($v), [11, 14], true)) throw new AppError($w . ' (CPF ou CNPJ)'); }
                    $v = mb_substr($v, 0, $q['type'] === 'area' ? 5000 : 1000);
            }
            $out[$id] = $v;
        }
        $vis = self::visibleMap($qn['questions'], $out);
        return array_filter($out, fn($k) => !empty($vis[$k]), ARRAY_FILTER_USE_KEY);
    }

    /** Questionários da OS: os do tipo de tarefa e os que já têm respostas (mesmo que o tipo tenha mudado). */
    public static function questionnaires(array $o): array
    {
        $linked = [];
        if ($o['task_type_id']) {
            try { $linked = TaskTypes::get((int)$o['task_type_id'])['questionnaire_ids']; } catch (AppError $e) {}
        }
        $rows = [];
        foreach (DB::all('SELECT * FROM so_answers WHERE order_id = ?', [$o['id']]) as $r) $rows[(int)$r['questionnaire_id']] = $r;
        $ids = array_values(array_unique(array_merge($linked, array_keys($rows))));
        $out = [];
        foreach ($ids as $qid) {
            try { $qn = Questionnaires::get((int)$qid); } catch (AppError $e) { continue; }
            $r = $rows[$qid] ?? null;
            $ans = $r ? (json_decode($r['answers'], true) ?: []) : [];
            $qn['linked'] = in_array($qid, $linked, true);
            $qn['answered'] = (bool)$r;
            $qn['answers'] = $ans;
            $qn['answer_equipment_id'] = $r && $r['equipment_id'] ? (int)$r['equipment_id'] : null;
            $qn['answered_at'] = $r['updated_at'] ?? null;
            $qn['missing'] = self::missing($qn, $ans);
            $out[] = $qn;
        }
        return $out;
    }

    public static function saveAnswers(int $orderId, int $qid, $answers, $equipmentId, int $userId): array
    {
        $o = ServiceOrders::get($orderId);
        self::editable($o);
        $qn = null;
        foreach (self::questionnaires($o) as $x) if ((int)$x['id'] === $qid) $qn = $x;
        if (!$qn) throw new AppError('Este questionário não faz parte da ordem de serviço');
        $ans = self::normalizeAnswers($qn, $answers);
        $eq = null;
        if ($qn['allow_equipment'] && (int)$equipmentId) {
            $e = Equipments::get((int)$equipmentId);
            if ((int)$e['customer_id'] !== (int)$o['customer_id']) throw new AppError('O equipamento não pertence a este cliente');
            $eq = (int)$equipmentId;
        }
        $data = ['answers' => json_encode($ans, JSON_UNESCAPED_UNICODE), 'equipment_id' => $eq, 'user_id' => $userId ?: null, 'updated_at' => now()];
        $id = DB::val('SELECT id FROM so_answers WHERE order_id = ? AND questionnaire_id = ?', [$orderId, $qid]);
        if ($id) DB::update('so_answers', (int)$id, $data);
        else DB::insert('so_answers', $data + ['order_id' => $orderId, 'questionnaire_id' => $qid]);
        return ['missing' => self::missing($qn, $ans)];
    }

    // ---------------- Relato, assinatura e km ----------------

    public static function saveReport(int $id, array $in): void
    {
        $o = ServiceOrders::get($id);
        self::editable($o);
        $doc = preg_replace('/\D/', '', (string)($in['signer_doc'] ?? ''));
        if ($doc !== '' && !in_array(strlen($doc), [11, 14], true)) throw new AppError('CPF/CNPJ de quem assinou inválido');
        $km = self::num($in['km'] ?? '');
        if ($km !== null && ($km < 0 || $km > 100000)) throw new AppError('Km rodado inválido');
        $data = [
            'report' => mb_substr(trim((string)($in['report'] ?? '')), 0, 10000) ?: null,
            'signer_name' => mb_substr(trim((string)($in['signer_name'] ?? '')), 0, 120) ?: null,
            'signer_doc' => $doc ?: null,
            'km' => $km,
            'updated_at' => now(),
        ];
        // Sem a chave "signature", a assinatura atual fica; vazia, é removida
        if (array_key_exists('signature', $in)) $data['signature'] = (string)$in['signature'] === '' ? null : self::image($in['signature'], 'Assinatura');
        DB::update('service_orders', $id, $data);
    }

    // ---------------- Fotos ----------------

    public static function addPhoto(int $id, $image, string $caption, int $userId): int
    {
        $o = ServiceOrders::get($id);
        self::editable($o);
        if ((int)DB::val('SELECT COUNT(*) FROM so_photos WHERE order_id = ?', [$id]) >= self::MAX_PHOTOS) throw new AppError('Máximo de ' . self::MAX_PHOTOS . ' fotos por ordem');
        return DB::insert('so_photos', ['order_id' => $id, 'image' => self::image($image, 'Foto'), 'caption' => mb_substr(trim($caption), 0, 200) ?: null,
            'user_id' => $userId ?: null, 'created_at' => now()]);
    }

    public static function deletePhoto(int $photoId): void
    {
        $p = DB::one('SELECT id, order_id FROM so_photos WHERE id = ?', [$photoId]);
        if (!$p) throw new AppError('Foto não encontrada', 404);
        self::editable(ServiceOrders::get((int)$p['order_id']));
        DB::run('DELETE FROM so_photos WHERE id = ?', [$photoId]);
    }

    // ---------------- Itens (valores) ----------------

    public static function saveItem(int $orderId, array $in, int $userId): int
    {
        $o = ServiceOrders::get($orderId);
        self::editable($o);
        $kind = (string)($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) throw new AppError('Tipo de item inválido');
        $desc = trim((string)($in['description'] ?? ''));
        $pid = null;
        if ($kind !== 'custo' && (int)($in['product_id'] ?? 0)) {
            $p = DB::one('SELECT id, name, type, deleted_at FROM products WHERE id = ?', [(int)$in['product_id']]);
            if (!$p || $p['deleted_at']) throw new AppError('Item do cadastro não encontrado');
            if (($p['type'] === 'servico') !== ($kind === 'servico')) throw new AppError($kind === 'servico' ? 'Escolha um serviço' : 'Escolha um produto');
            $pid = (int)$p['id'];
            if ($desc === '') $desc = $p['name'];
        }
        if ($desc === '') throw new AppError('Informe a descrição do item');
        $qty = self::num($in['quantity'] ?? '1') ?? 1.0;
        if ($qty <= 0) throw new AppError('A quantidade precisa ser maior que zero');
        $price = self::num($in['unit_price'] ?? '0') ?? 0.0;
        if ($price < 0) throw new AppError('O valor não pode ser negativo');
        return DB::insert('so_items', ['order_id' => $orderId, 'kind' => $kind, 'product_id' => $pid, 'description' => mb_substr($desc, 0, 200),
            'quantity' => round($qty, 3), 'unit_price' => round($price, 2), 'user_id' => $userId ?: null, 'created_at' => now()]);
    }

    public static function deleteItem(int $itemId): void
    {
        $i = DB::one('SELECT id, order_id FROM so_items WHERE id = ?', [$itemId]);
        if (!$i) throw new AppError('Item não encontrado', 404);
        self::editable(ServiceOrders::get((int)$i['order_id']));
        DB::run('DELETE FROM so_items WHERE id = ?', [$itemId]);
    }

    public static function items(int $orderId): array
    {
        $rows = DB::all('SELECT * FROM so_items WHERE order_id = ? ORDER BY id', [$orderId]);
        $tot = ['produto' => 0.0, 'servico' => 0.0, 'custo' => 0.0];
        foreach ($rows as &$r) {
            $r['total'] = round((float)$r['quantity'] * (float)$r['unit_price'], 2);
            $tot[$r['kind']] += $r['total'];
        }
        unset($r);
        return ['rows' => $rows, 'totals' => $tot + ['total' => round(array_sum($tot), 2)]];
    }

    // ---------------- Histórico de status / controle de horas ----------------

    public static function logEvent(int $orderId, ?string $from, string $to, int $userId = 0, ?string $reason = null): void
    {
        $reason = $reason !== null ? (mb_substr(trim($reason), 0, 120) ?: null) : null;
        DB::insert('so_events', ['order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'user_id' => $userId ?: null,
            'reason' => $to === 'pausada' ? $reason : null, 'created_at' => now()]);
    }

    /** Nome de cada registro na sequência: o primeiro em execução é o check-in, os seguintes são fim de pausa. */
    private static function labels(array $ev): array
    {
        $out = []; $started = false;
        foreach ($ev as $e) {
            $s = $e['to_status'];
            $out[] = match ($s) { 'em_execucao' => $started ? 'Fim da pausa' : 'Check-in', 'pausada' => 'Início da pausa', 'concluida' => 'Check-out', default => ServiceOrders::label($s) };
            if ($s === 'em_execucao') $started = true;
        }
        return $out;
    }

    /** Linhas do controle de horas (check-in, pausas, check-out) e tempos totais em segundos. */
    public static function hours(int $orderId, ?string $nowAt = null): array
    {
        $ev = DB::all('SELECT e.*, u.name user_name, x.name edited_by_name FROM so_events e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN users x ON x.id = e.edited_by
            WHERE e.order_id = ? AND e.deleted_at IS NULL ORDER BY e.created_at, e.id', [$orderId]);
        $labels = self::labels($ev);
        $rows = []; $work = 0; $pause = 0; $open = null;
        $close = function ($at) use (&$open, &$work, &$pause) {
            if (!$open) return 0;
            $d = max(0, strtotime($at) - strtotime($open['at']));
            if ($open['kind'] === 'work') $work += $d; else $pause += $d;
            $open = null;
            return $d;
        };
        foreach ($ev as $i => $e) {
            $elapsed = $close($e['created_at']);
            if ($e['to_status'] === 'em_execucao') $open = ['kind' => 'work', 'at' => $e['created_at']];
            if ($e['to_status'] === 'pausada') $open = ['kind' => 'pause', 'at' => $e['created_at']];
            $rows[] = ['id' => (int)$e['id'], 'label' => $labels[$i], 'status' => $e['to_status'], 'at' => $e['created_at'], 'user_name' => $e['user_name'],
                'elapsed' => in_array($e['from_status'], ['em_execucao', 'pausada'], true) ? $elapsed : null,
                'reason' => $e['reason'], 'manual' => (int)$e['manual'],
                'original_at' => $e['original_at'], 'note' => $e['note'], 'edited_by_name' => $e['edited_by_name'], 'edited_at' => $e['edited_at']];
        }
        $running = $open['kind'] ?? null;
        $close($nowAt ?? now());
        $deleted = (int)DB::val('SELECT COUNT(*) FROM so_events WHERE order_id = ? AND deleted_at IS NOT NULL', [$orderId]);
        return ['rows' => $rows, 'work' => $work, 'pause' => $pause, 'running' => $running, 'deleted' => $deleted];
    }

    private static function moment($v, string $what): string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $v, $m)) throw new AppError("$what: data e horário inválidos");
        $at = $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
        if (!strtotime($at) || date('Y-m-d H:i:s', strtotime($at)) !== $at) throw new AppError("$what: data e horário inválidos");
        if (strtotime($at) > time() + 300) throw new AppError("$what: o horário não pode estar no futuro");
        return $at;
    }

    /**
     * Ajuste manual do controle de horas, como no Auvo. Em $in:
     *  rows   = [{id, at, reason, note}]  horário/motivo de registros existentes (justificativa obrigatória ao mudar o horário)
     *  delete = [{id, note}]              exclui uma pausa (o início e o fim saem juntos; justificativa obrigatória)
     *  add    = [{start, end, reason, note}] inclui uma pausa dentro de um período de atendimento
     * Os registros existentes mantêm a ordem e a sequência precisa continuar válida (check-in → pausas → check-out).
     * Guarda o horário original, quem alterou e quando; exclusão é marcada (não apaga).
     */
    public static function saveHours(int $orderId, array $in, int $userId): int
    {
        $o = ServiceOrders::get($orderId);
        self::editable($o);
        $ev = DB::all('SELECT * FROM so_events WHERE order_id = ? AND deleted_at IS NULL ORDER BY created_at, id', [$orderId]);
        $labels = self::labels($ev);
        $idx = [];
        foreach ($ev as $i => $e) $idx[(int)$e['id']] = $i;
        $note = fn($v) => mb_substr(trim((string)$v), 0, 300);
        $what = fn($i) => '"' . $labels[$i] . '"';

        // Alterações de horário e motivo
        $upd = [];
        foreach ((array)($in['rows'] ?? []) as $r) {
            $id = (int)($r['id'] ?? 0);
            if (!isset($idx[$id])) throw new AppError('Registro do controle de horas não encontrado');
            $i = $idx[$id]; $e = $ev[$i];
            $at = isset($r['at']) && trim((string)$r['at']) !== '' ? self::moment($r['at'], $labels[$i]) : $e['created_at'];
            $reason = $e['to_status'] === 'pausada' && array_key_exists('reason', $r) ? (mb_substr(trim((string)$r['reason']), 0, 120) ?: null) : $e['reason'];
            if ($at === $e['created_at'] && $reason === $e['reason']) continue;
            $n = $note($r['note'] ?? '');
            if ($at !== $e['created_at'] && mb_strlen($n) < 3) throw new AppError('Escreva a justificativa da alteração de ' . $what($i));
            $upd[$i] = ['at' => $at, 'reason' => $reason, 'note' => $n ?: $e['note'], 'timeChanged' => $at !== $e['created_at']];
        }

        // Exclusão de pausas (início + fim)
        $del = [];
        foreach ((array)($in['delete'] ?? []) as $d) {
            $id = (int)($d['id'] ?? 0);
            if (!isset($idx[$id])) throw new AppError('Registro do controle de horas não encontrado');
            $i = $idx[$id];
            if ($ev[$i]['to_status'] !== 'pausada') throw new AppError('Só pausas podem ser excluídas; check-in e check-out marcam o início e o fim do atendimento');
            $end = $i + 1;
            if (!isset($ev[$end]) || $ev[$end]['from_status'] !== 'pausada') throw new AppError('Esta pausa ainda está em andamento: retome o atendimento antes de excluí-la');
            $n = $note($d['note'] ?? '');
            if (mb_strlen($n) < 3) throw new AppError('Escreva a justificativa da exclusão da pausa');
            $del[$i] = $n; $del[$end] = $n;
        }

        foreach (array_keys($del) as $i) unset($upd[$i]); // registro excluído não conta como alterado

        // Inclusão de pausas
        $add = [];
        foreach ((array)($in['add'] ?? []) as $k => $a) {
            $start = self::moment($a['start'] ?? '', 'Início da pausa');
            $end = self::moment($a['end'] ?? '', 'Fim da pausa');
            if ($end <= $start) throw new AppError('O fim da pausa precisa ser depois do início');
            $n = $note($a['note'] ?? '');
            if (mb_strlen($n) < 3) throw new AppError('Escreva a justificativa da pausa incluída');
            $add[] = ['start' => $start, 'end' => $end, 'reason' => mb_substr(trim((string)($a['reason'] ?? '')), 0, 120) ?: null, 'note' => $n];
        }
        if (!$upd && !$del && !$add) return 0;

        // Sequência final: registros existentes (na mesma ordem) + pausas incluídas, por horário
        $list = [];
        foreach ($ev as $i => $e) {
            if (isset($del[$i])) continue;
            $list[] = ['i' => $i, 'at' => $upd[$i]['at'] ?? $e['created_at'], 'from' => $e['from_status'], 'to' => $e['to_status'], 'ord' => $i];
        }
        foreach ($list as $k => $x) {
            if ($k && $x['at'] < $list[$k - 1]['at']) {
                throw new AppError('Os horários precisam seguir a ordem dos registros: ' . $what($x['i']) . ' (' . date('d/m/Y H:i', strtotime($x['at'])) . ') ficou antes de '
                    . $what($list[$k - 1]['i']) . ' (' . date('d/m/Y H:i', strtotime($list[$k - 1]['at'])) . ')');
            }
        }
        foreach ($add as $k => $a) {
            $list[] = ['new' => $k, 'part' => 'start', 'at' => $a['start'], 'from' => 'em_execucao', 'to' => 'pausada', 'ord' => count($ev) + $k * 2];
            $list[] = ['new' => $k, 'part' => 'end', 'at' => $a['end'], 'from' => 'pausada', 'to' => 'em_execucao', 'ord' => count($ev) + $k * 2 + 1];
        }
        // Mesmo horário: o registro existente vem antes da pausa incluída
        usort($list, fn($x, $y) => [$x['at'], isset($x['new']) ? 1 : 0, $x['ord']] <=> [$y['at'], isset($y['new']) ? 1 : 0, $y['ord']]);
        $prev = null; $lastNew = null;
        $outside = fn($k) => new AppError('A pausa de ' . date('d/m/Y H:i', strtotime($add[$k]['start'])) . ' precisa ficar dentro de um período de atendimento (depois do check-in e sem cruzar outra pausa ou o check-out)');
        foreach ($list as $x) {
            if ($x['from'] !== $prev) {
                if (isset($x['new'])) throw $outside($x['new']);
                if ($lastNew !== null) throw $outside($lastNew); // o registro existente foi "atravessado" por uma pausa incluída
                throw new AppError('Essa alteração deixa a sequência do atendimento inválida perto de ' . $what($x['i']));
            }
            $prev = $x['to'];
            $lastNew = isset($x['new']) ? $x['new'] : null;
        }

        DB::tx(function () use ($ev, $upd, $del, $add, $orderId, $userId) {
            foreach ($upd as $i => $u) {
                $e = $ev[$i];
                $data = ['reason' => $u['reason'], 'edited_by' => $userId ?: null, 'edited_at' => now(), 'note' => $u['note'] ?: null];
                if ($u['timeChanged']) $data += ['created_at' => $u['at'], 'original_at' => $e['original_at'] ?: $e['created_at']];
                DB::update('so_events', (int)$e['id'], $data);
            }
            foreach ($del as $i => $n) DB::update('so_events', (int)$ev[$i]['id'], ['deleted_at' => now(), 'deleted_by' => $userId ?: null, 'note' => $n]);
            foreach ($add as $a) {
                foreach ([['em_execucao', 'pausada', $a['start'], $a['reason']], ['pausada', 'em_execucao', $a['end'], null]] as [$from, $to, $at, $reason]) {
                    DB::insert('so_events', ['order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'user_id' => $userId ?: null, 'created_at' => $at,
                        'reason' => $reason, 'manual' => 1, 'note' => $a['note'], 'edited_by' => $userId ?: null, 'edited_at' => now()]);
                }
            }
        });

        // Início = primeiro check-in; fim = último check-out (se a OS está concluída)
        $start = DB::val("SELECT MIN(created_at) FROM so_events WHERE order_id = ? AND to_status = 'em_execucao' AND deleted_at IS NULL", [$orderId]);
        $end = DB::val("SELECT MAX(created_at) FROM so_events WHERE order_id = ? AND to_status = 'concluida' AND deleted_at IS NULL", [$orderId]);
        $data = ['updated_at' => now()];
        if ($start) $data['started_at'] = $start;
        if ($end && in_array($o['status'], ['concluida', 'faturada'], true)) $data['finished_at'] = $end;
        DB::update('service_orders', $orderId, $data);
        return count($upd) + intdiv(count($del), 2) + count($add);
    }

    // ---------------- Pendências para concluir ----------------

    public static function pending(array $o): array
    {
        $req = [];
        if ($o['task_type_id']) {
            try { $req = TaskTypes::get((int)$o['task_type_id'])['requires']; } catch (AppError $e) {}
        }
        $has = fn($k) => in_array($k, $req, true);
        $out = [];
        if ($has('relato') && trim((string)$o['report']) === '') $out[] = 'Preencher o relato do que foi feito';
        foreach (self::questionnaires($o) as $qn) {
            if (!$qn['answered']) {
                if ($has('questionario') && $qn['linked']) $out[] = 'Responder o questionário "' . $qn['name'] . '"';
                continue;
            }
            if ($qn['missing']) $out[] = 'Questionário "' . $qn['name'] . '": falta responder ' . implode(', ', array_map(fn($l) => '"' . $l . '"', $qn['missing']));
            if ($qn['allow_equipment'] && $qn['equipment_required'] && !$qn['answer_equipment_id']) $out[] = 'Questionário "' . $qn['name'] . '": vincular o equipamento';
        }
        if ($has('assinatura') && !$o['signature']) $out[] = 'Colher a assinatura do cliente';
        if ($has('assinante_nome') && !$o['signer_name']) $out[] = 'Informar o nome de quem assinou';
        if ($has('assinante_doc') && !$o['signer_doc']) $out[] = 'Informar o CPF/CNPJ de quem assinou';
        if ($has('fotos') && !(int)DB::val('SELECT COUNT(*) FROM so_photos WHERE order_id = ?', [$o['id']])) $out[] = 'Incluir pelo menos uma foto';
        if ($has('km') && $o['km'] === null) $out[] = 'Informar o km rodado';
        foreach (['produto' => 'um produto', 'servico' => 'um serviço', 'custo' => 'um custo adicional'] as $k => $l) {
            if ($has($k) && !(int)DB::val('SELECT COUNT(*) FROM so_items WHERE order_id = ? AND kind = ?', [$o['id'], $k])) $out[] = "Incluir pelo menos $l em Valores";
        }
        if ($has('os_email') && !filter_var((string)$o['customer_email'], FILTER_VALIDATE_EMAIL)) $out[] = 'Cadastrar o e-mail do cliente (a OS digital é enviada por e-mail)';
        return $out;
    }

    // ---------------- Link público, envios e pesquisa ----------------

    public static function token(int $id): string
    {
        $t = (string)DB::val('SELECT public_token FROM service_orders WHERE id = ?', [$id]);
        if ($t === '') {
            $t = bin2hex(random_bytes(16));
            DB::update('service_orders', $id, ['public_token' => $t]);
        }
        return $t;
    }

    public static function publicUrl(int $id, bool $survey = false): string
    {
        return app_base_url() . 'os.php?t=' . self::token($id) . ($survey ? '&pesquisa=1' : '');
    }

    public static function byToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new AppError('Ordem não encontrada', 404);
        $id = DB::val('SELECT id FROM service_orders WHERE public_token = ?', [$token]);
        if (!$id) throw new AppError('Ordem não encontrada', 404);
        return self::detail((int)$id);
    }

    /** Envia a OS digital ou a pesquisa. Em envio automático ($quiet) a falha é registrada e não interrompe. */
    public static function send(int $id, string $kind, string $to, int $userId, bool $quiet = false): bool
    {
        if (!in_array($kind, ['os', 'pesquisa'], true)) throw new AppError('Envio inválido');
        $o = ServiceOrders::get($id);
        $to = trim($to) ?: (string)$o['customer_email'];
        if ($kind === 'pesquisa' && !in_array($o['status'], ['concluida', 'faturada'], true)) {
            if ($quiet) return false;
            throw new AppError('A pesquisa de satisfação só é enviada depois que a ordem é concluída');
        }
        $co = Settings::all();
        $company = $co['company_name'] ?: 'Sistema de Gestão de Serviços';
        $cust = $o['customer_trade_name'] ?: $o['customer_name'];
        $url = self::publicUrl($id, $kind === 'pesquisa');
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $subject = $kind === 'os' ? "Ordem de serviço #{$o['id']} - $company" : "Como foi o atendimento? OS #{$o['id']} - $company";
        $text = $kind === 'os'
            ? "Segue a ordem de serviço #{$o['id']}" . ($o['status'] === 'concluida' ? ', concluída' : '') . '. Você pode ver, imprimir ou salvar em PDF pelo link abaixo.'
            : "Concluímos a ordem de serviço #{$o['id']}. Conte para nós como foi o atendimento: leva menos de um minuto.";
        $btn = $kind === 'os' ? 'Ver a ordem de serviço' : 'Avaliar o atendimento';
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#212529">'
            . '<h2 style="color:#b02a37;margin:0 0 12px">' . $e($company) . '</h2>'
            . '<p>Olá' . ($cust ? ', ' . $e(explode(' ', trim($cust))[0]) : '') . '!</p><p>' . $e($text) . '</p>'
            . '<p style="margin:24px 0"><a href="' . $e($url) . '" style="background:#b02a37;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">' . $e($btn) . '</a></p>'
            . '<p style="color:#6c757d;font-size:13px">Se o botão não abrir, copie o endereço: ' . $e($url) . '</p></div>';
        $err = null;
        try {
            if ($to === '') throw new AppError('O cliente não tem e-mail cadastrado');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new AppError('E-mail do destinatário inválido');
            Mailer::send($to, $subject, $html);
        } catch (AppError $ex) {
            $err = $ex->getMessage();
        }
        DB::insert('so_sends', ['order_id' => $id, 'kind' => $kind, 'recipient' => mb_substr($to ?: '—', 0, 200), 'ok' => $err ? 0 : 1,
            'error' => $err ? mb_substr($err, 0, 300) : null, 'user_id' => $userId ?: null, 'created_at' => now()]);
        if ($err && !$quiet) throw new AppError($err);
        return !$err;
    }

    /** Ao concluir: envia a OS digital e/ou a pesquisa conforme o tipo de tarefa. */
    public static function onConcluded(int $id, int $userId): void
    {
        $o = ServiceOrders::get($id);
        if (!$o['task_type_id']) return;
        try { $tt = TaskTypes::get((int)$o['task_type_id']); } catch (AppError $e) { return; }
        if ($tt['send_os_email'] || in_array('os_email', $tt['requires'], true)) self::send($id, 'os', '', $userId, true);
        if ($tt['send_survey']) self::send($id, 'pesquisa', '', $userId, true);
    }

    public static function surveyRespond(string $token, $rating, string $comment): void
    {
        $o = self::byToken($token)['order'];
        if (!in_array($o['status'], ['concluida', 'faturada'], true)) throw new AppError('A avaliação fica disponível depois que o serviço é concluído');
        if ($o['survey_at']) throw new AppError('Esta ordem já foi avaliada. Obrigado!');
        $r = (int)$rating;
        if ($r < 1 || $r > 5) throw new AppError('Escolha uma nota de 1 a 5');
        DB::update('service_orders', (int)$o['id'], ['survey_rating' => $r, 'survey_comment' => mb_substr(trim($comment), 0, 1000) ?: null, 'survey_at' => now()]);
    }

    /** Orçamentos ligados à OS: o de origem e os abertos a partir dela. */
    public static function quotes(array $o): array
    {
        $rows = DB::all('SELECT id, status, total, created_at, service_order_id FROM quotes WHERE id = ? OR service_order_id = ? ORDER BY id', [(int)$o['quote_id'], (int)$o['id']]);
        foreach ($rows as &$r) {
            $r['status_label'] = QuoteStatuses::label($r['status']);
            $r['relation'] = (int)$r['service_order_id'] === (int)$o['id'] ? 'aberto pela OS' : 'origem da OS';
        }
        unset($r);
        return $rows;
    }

    // ---------------- Tudo da OS (ficha) ----------------

    public static function detail(int $id): array
    {
        $o = ServiceOrders::get($id);
        $o['public_url'] = self::publicUrl($id);
        $o['survey_url'] = self::publicUrl($id, true);
        return [
            'order' => $o,
            'questionnaires' => self::questionnaires($o),
            'photos' => DB::all('SELECT p.id, p.image, p.caption, p.created_at, u.name user_name FROM so_photos p LEFT JOIN users u ON u.id = p.user_id WHERE p.order_id = ? ORDER BY p.id', [$id]),
            'items' => self::items($id),
            'hours' => self::hours($id),
            'sends' => DB::all('SELECT s.*, u.name user_name FROM so_sends s LEFT JOIN users u ON u.id = s.user_id WHERE s.order_id = ? ORDER BY s.id DESC', [$id]),
            'pending' => self::pending($o),
            'quotes' => self::quotes($o),
            'equipments' => DB::all('SELECT id, name, serial FROM equipments WHERE customer_id = ? ORDER BY name', [$o['customer_id']]),
            'kinds' => self::KINDS,
            'types' => Questionnaires::TYPES,
        ];
    }
}
