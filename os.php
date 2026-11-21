<?php
/**
 * Página pública da ordem de serviço (OS digital, link enviado ao cliente).
 * Mostra a OS, o relato, as respostas dos questionários, fotos, valores e assinatura,
 * e recebe a pesquisa de satisfação (&pesquisa=1 abre direto na avaliação).
 */

require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$money = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
$date = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '';
$dtm = fn($v) => $v ? date('d/m/Y H:i', strtotime($v)) : '';
$doc = function ($d) {
    $d = preg_replace('/\D/', '', (string)$d);
    if (strlen($d) === 14) return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
    if (strlen($d) === 11) return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d);
    return $d;
};

$token = (string)($_GET['t'] ?? '');
$message = null; $error = null;
$notFound = function (int $code = 404) {
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ordem de serviço</title>'
        . '<body style="font-family:system-ui;padding:40px;text-align:center;color:#555"><h2>Ordem de serviço não encontrada</h2>'
        . '<p>O link pode estar incompleto. Peça um novo link a quem enviou.</p></body>';
    exit;
};
if (!app_connect()) $notFound(503);
try { $d = OrderExec::byToken($token); } catch (AppError $ex) { $notFound(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        OrderExec::surveyRespond($token, $_POST['rating'] ?? 0, (string)($_POST['comment'] ?? ''));
        $message = 'Avaliação enviada. Obrigado!';
    } catch (AppError $ex) {
        $error = $ex->getMessage();
    }
    $d = OrderExec::byToken($token);
}
$o = $d['order'];
$co = Settings::all();
$company = $co['company_name'] ?: 'Ordem de serviço';
$cust = $o['customer_trade_name'] ?: $o['customer_name'];
$done = in_array($o['status'], ['concluida', 'faturada'], true);
$canRate = $done && !$o['survey_at'];
$askRate = $canRate && isset($_GET['pesquisa']);

// Resposta formatada para leitura
$answer = function (array $q, $v) use ($e, $date, $money, $doc) {
    if ($v === null || $v === '' || $v === []) return '<span class="muted">Sem resposta</span>';
    switch ($q['type']) {
        case 'multipla': return $e(implode(', ', (array)$v));
        case 'data': return $e($date($v));
        case 'monetaria': return $e($money($v));
        case 'documento': return $e($doc($v));
        case 'numerica': return $e(str_replace('.', ',', (string)$v));
        case 'assinatura': return '<img class="sig" src="' . $e($v) . '" alt="Assinatura">';
        case 'foto':
            return '<div class="ph">' . implode('', array_map(fn($p) => '<figure><img src="' . $e($p['src']) . '" alt="Foto">'
                . (isset($p['lat']) ? '<figcaption><a href="https://www.google.com/maps?q=' . $e($p['lat'] . ',' . $p['lng']) . '" target="_blank" rel="noopener">Ver local</a></figcaption>' : '')
                . '</figure>', (array)$v)) . '</div>';
        case 'area': return '<span class="notes">' . $e($v) . '</span>';
        default: return $e($v);
    }
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ordem de serviço #<?= (int)$o['number'] ?> · <?= $e($company) ?></title>
<style>
  :root { --ink: #212529; --muted: #6c757d; --line: #dee2e6; --accent: #b02a37; --soft: #f8d7da; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #eef0f2; }
  .page { max-width: 860px; margin: 24px auto; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 36px 40px; }
  header.top { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid var(--accent); padding-bottom: 16px; margin-bottom: 20px; flex-wrap: wrap; }
  .co-wrap { display: flex; gap: 18px; align-items: center; }
  .logo { max-width: 180px; max-height: 90px; object-fit: contain; }
  .co h1 { margin: 0 0 4px; font-size: 1.35rem; }
  .co div, .meta div { color: var(--muted); font-size: .88rem; }
  .meta { text-align: right; }
  .meta .num-q { font-size: 1.25rem; font-weight: 700; color: var(--accent); }
  h2 { font-size: .95rem; margin: 22px 0 8px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
  h3 { font-size: 1rem; margin: 14px 0 6px; }
  .box { background: #f8f9fa; border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
  .lbl { color: var(--muted); font-size: .8rem; }
  .muted { color: var(--muted); }
  .notes { white-space: pre-wrap; }
  .qa { display: grid; gap: 10px 18px; }
  .qa .q { break-inside: avoid; border-bottom: 1px dashed var(--line); padding-bottom: 6px; }
  .qa .q b { display: block; font-size: .88rem; }
  .ph { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
  .ph figure { margin: 0; }
  .ph img { width: 150px; height: 150px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); }
  .ph figcaption { font-size: .75rem; }
  .sig { max-width: 280px; max-height: 120px; border-bottom: 1px solid var(--ink); }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: .75rem; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--line); padding: 6px 8px; }
  td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .totals { margin-left: auto; width: min(340px, 100%); margin-top: 14px; }
  .totals td { border: 0; padding: 4px 8px; }
  .totals .grand td { border-top: 2px solid var(--ink); font-size: 1.2rem; font-weight: 700; padding-top: 8px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: .8rem; font-weight: 600; background: var(--soft); color: var(--accent); }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin: 26px 0 4px; }
  .btn { font: inherit; font-weight: 600; padding: 11px 20px; border-radius: 8px; border: 1px solid var(--line); background: #fff; cursor: pointer; color: var(--ink); }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: var(--soft); color: var(--accent); font-weight: 500; }
  .alert.err { background: #fdecea; color: #b42318; }
  .rate { border: 2px solid var(--accent); border-radius: 12px; padding: 18px; margin-top: 22px; text-align: center; }
  .stars { display: flex; justify-content: center; gap: 6px; flex-direction: row-reverse; margin: 10px 0; }
  .stars input { display: none; }
  .stars label { font-size: 2.2rem; color: #ced4da; cursor: pointer; line-height: 1; }
  .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color: #f5a524; }
  textarea { width: 100%; font: inherit; padding: 10px; border: 1px solid var(--line); border-radius: 8px; min-height: 70px; margin-top: 8px; }
  footer { text-align: center; color: var(--muted); font-size: .8rem; margin-top: 26px; }
  @media (max-width: 640px) { .co-wrap { flex-direction: column; align-items: flex-start; gap: 10px; } .page { margin: 0; border-radius: 0; padding: 22px 16px; border: 0; } .meta { text-align: left; } .qa { grid-template-columns: 1fr !important; } }
  @page { size: A4; margin: 14mm 12mm; }
  @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } body { background: #fff; font-size: 12.5px; }
    .page { border: 0; margin: 0; padding: 0; max-width: none; } .no-print { display: none !important; } .box, table, .q, figure { break-inside: avoid; } }
</style>
</head>
<body>
<main class="page">
  <?php if ($message): ?><div class="alert no-print"><?= $e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert err no-print"><?= $e($error) ?></div><?php endif; ?>

  <?php if ($askRate): ?>
  <form class="rate no-print" method="post">
    <h2 style="margin-top:0">Como foi o atendimento?</h2>
    <div>Dê uma nota de 1 a 5 para a ordem de serviço #<?= (int)$o['number'] ?>.</div>
    <div class="stars"><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>" required><label for="r<?= $i ?>" title="<?= $i ?>">★</label><?php endfor; ?></div>
    <textarea name="comment" maxlength="1000" placeholder="Comentário (opcional)"></textarea>
    <div style="margin-top:10px"><button class="btn primary">Enviar avaliação</button></div>
  </form>
  <?php endif; ?>

  <header class="top">
    <div class="co-wrap">
    <?php if ($co['company_logo']): ?><img class="logo" src="<?= $e($co['company_logo']) ?>" alt="<?= $e($company) ?>"><?php endif; ?>
    <div class="co">
      <h1><?= $e($company) ?></h1>
      <?php if ($co['company_document']): ?><div>CNPJ <?= $e($doc($co['company_document'])) ?></div><?php endif; ?>
      <?php if ($co['company_address']): ?><div><?= $e($co['company_address']) ?></div><?php endif; ?>
      <div><?= $e(implode(' · ', array_filter([$co['company_phone'], $co['company_email'], $co['company_site']]))) ?></div>
    </div>
    </div>
    <div class="meta">
      <div class="num-q">Ordem de serviço #<?= (int)$o['number'] ?></div>
      <div>Aberta em <?= $date($o['created_at']) ?></div>
      <?php if ($o['finished_at']): ?><div>Concluída em <?= $dtm($o['finished_at']) ?></div><?php endif; ?>
      <div style="margin-top:6px"><span class="badge"><?= $e($o['status_label']) ?></span></div>
    </div>
  </header>

  <div class="grid">
    <div class="box"><div class="lbl">Cliente</div><strong><?= $e($cust) ?></strong>
      <?php if ($o['customer_document']): ?><div class="muted"><?= $e($doc($o['customer_document'])) ?></div><?php endif; ?></div>
    <?php if ($o['equipment_name']): ?><div class="box"><div class="lbl">Equipamento</div><strong><?= $e($o['equipment_name']) ?></strong>
      <div class="muted"><?= $e(implode(' · ', array_filter([$o['equipment_brand'], $o['equipment_model'], $o['equipment_serial']]))) ?></div></div><?php endif; ?>
    <div class="box"><div class="lbl">Tipo de tarefa</div><?= $e($o['task_type_name'] ?: '—') ?>
      <?php if ($o['technician_name']): ?><div class="lbl" style="margin-top:6px">Técnico</div><?= $e($o['technician_name']) ?><?php endif; ?></div>
  </div>

  <h2>Serviço solicitado</h2>
  <div class="box notes"><?= $e($o['description']) ?></div>

  <?php if ($o['report']): ?><h2>O que foi feito</h2><div class="box notes"><?= $e($o['report']) ?></div><?php endif; ?>

  <?php foreach ($d['questionnaires'] as $qn): if (!$qn['show_os'] || !$qn['answered']) continue;
      $vis = OrderExec::visibleMap($qn['questions'], $qn['answers']); ?>
    <h2><?= $e($qn['name']) ?></h2>
    <div class="qa" style="grid-template-columns:repeat(<?= max(1, (int)$qn['per_line']) ?>, minmax(0, 1fr))">
    <?php foreach ($qn['questions'] as $i => $q): $v = $qn['answers'][$q['id']] ?? null;
        if (!$vis[$q['id']] || (!$qn['show_unanswered'] && ($v === null || $v === '' || $v === []))) continue; ?>
      <div class="q"><b><?= $i + 1 ?>) <?= $e($q['label']) ?></b><?= $answer($q, $v) ?></div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($d['photos']): ?>
    <h2>Fotos</h2>
    <div class="ph"><?php foreach ($d['photos'] as $p): ?><figure><img src="<?= $e($p['image']) ?>" alt="Foto"><?php if ($p['caption']): ?><figcaption><?= $e($p['caption']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($d['items']['rows']): ?>
    <h2>Valores</h2>
    <table><thead><tr><th>Descrição</th><th>Tipo</th><th class="num">Qtd.</th><th class="num">Valor unit.</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($d['items']['rows'] as $i): ?>
      <tr><td><?= $e($i['description']) ?></td><td><?= $e(OrderExec::KINDS[$i['kind']] ?? $i['kind']) ?></td><td class="num"><?= $qty($i['quantity']) ?></td>
        <td class="num"><?= $money($i['unit_price']) ?></td><td class="num"><?= $money($i['total']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <table class="totals"><tbody><tr class="grand"><td>Total</td><td class="num"><?= $money($d['items']['totals']['total']) ?></td></tr></tbody></table>
  <?php endif; ?>

  <?php if ($o['signature'] || $o['signer_name']): ?>
    <h2>Assinatura do cliente</h2>
    <div class="box">
      <?php if ($o['signature']): ?><img class="sig" src="<?= $e($o['signature']) ?>" alt="Assinatura"><?php endif; ?>
      <div><?= $e(implode(' · ', array_filter([$o['signer_name'], $o['signer_doc'] ? $doc($o['signer_doc']) : '']))) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($o['survey_at']): ?>
    <h2>Avaliação do cliente</h2>
    <div class="box"><span style="color:#f5a524;font-size:1.2rem"><?= str_repeat('★', (int)$o['survey_rating']) ?></span><span style="color:#ced4da;font-size:1.2rem"><?= str_repeat('★', 5 - (int)$o['survey_rating']) ?></span>
      <?php if ($o['survey_comment']): ?><div class="notes"><?= $e($o['survey_comment']) ?></div><?php endif; ?></div>
  <?php endif; ?>

  <div class="actions no-print">
    <button class="btn" onclick="window.print()">Imprimir / salvar PDF</button>
    <?php if ($canRate && !$askRate): ?><a class="btn primary" href="?t=<?= $e($token) ?>&amp;pesquisa=1" style="text-decoration:none">Avaliar o atendimento</a><?php endif; ?>
  </div>
  <footer>Ordem de serviço #<?= (int)$o['number'] ?> · <?= $e($company) ?></footer>
</main>
</body>
</html>
