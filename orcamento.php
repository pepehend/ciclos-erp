<?php
/**
 * Página pública do orçamento (link enviado ao cliente).
 * O cliente vê, imprime/salva em PDF e pode aprovar ou recusar.
 */

require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$money = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
$date = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '';
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
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Orçamento</title>'
        . '<body style="font-family:system-ui;padding:40px;text-align:center;color:#555"><h2>Orçamento não encontrado</h2>'
        . '<p>O link pode estar incompleto. Peça um novo link a quem enviou.</p></body>';
    exit;
};
if (!app_connect()) $notFound(503);
try { $q = Quotes::byToken($token); } catch (AppError $ex) { $notFound(); }
// Baixar o PDF pelo link (o mesmo PDF do sistema)
if (isset($_GET['download'])) {
    $pdf = QuotePdf::render($q, Settings::all());
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . QuotePdf::fileName($q) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ($_POST['action'] ?? '') === 'aprovar' ? 'aprovar' : 'recusar';
        Quotes::clientRespond($token, $action, (string)($_POST['comment'] ?? ''));
        $message = $action === 'aprovar' ? 'Orçamento aprovado. Obrigado! Entraremos em contato.' : 'Recebemos sua resposta. Obrigado pelo retorno!';
    } catch (AppError $ex) {
        $error = $ex->getMessage();
    }
    $q = Quotes::byToken($token);
}
$co = Settings::all();

$client = $q['customer_id'] ? ($q['customer_trade_name'] ?: $q['customer_name']) : $q['lead_name'];
$addr = implode(' · ', array_filter([
    implode(', ', array_filter([$q['customer_street'], $q['customer_number']])), $q['customer_complement'], $q['customer_district'],
    implode('/', array_filter([$q['customer_city'], $q['customer_state']])),
]));
$products = array_filter($q['items'], fn($i) => $i['kind'] === 'produto');
$services = array_filter($q['items'], fn($i) => $i['kind'] === 'servico');
$methods = $q['payment_methods'] ? array_map(fn($m) => PaymentMethods::label($m), explode(',', $q['payment_methods'])) : [];
$expired = Quotes::isExpired($q);
$canRespond = !in_array($q['status'], QuoteStatuses::CLOSED, true) && !$expired && !$message;
$company = $co['company_name'] ?: 'Orçamento';
$hasLineDisc = (bool)array_filter($q['items'], fn($i) => (float)$i['discount'] > 0);

function itemsTable(array $rows, string $title, bool $disc, callable $e, callable $money, callable $qty): string
{
    if (!$rows) return '';
    $h = '<h2>' . $e($title) . '</h2><table class="items"><thead><tr><th>Descrição</th><th class="num">Qtd.</th><th class="num">Valor unit.</th>'
        . ($disc ? '<th class="num">Desconto</th>' : '') . '<th class="num">Total</th></tr></thead><tbody>';
    foreach ($rows as $i) {
        $h .= '<tr><td>' . $e($i['description']) . '</td><td class="num">' . $qty($i['quantity']) . ' ' . $e($i['unit']) . '</td><td class="num">'
            . $money($i['unit_price']) . '</td>' . ($disc ? '<td class="num">' . ((float)$i['discount'] ? '− ' . $money($i['discount']) : '—') . '</td>' : '')
            . '<td class="num">' . $money($i['total']) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Orçamento #<?= (int)$q['number'] ?> · <?= $e($company) ?></title>
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
  .meta .resp { margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--line); display: grid; gap: 1px; }
  .meta .resp span { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
  .meta .resp strong { color: var(--ink); font-size: .95rem; }
  .meta .resp a { color: var(--muted); text-decoration: none; font-size: .88rem; }
  @media (max-width: 640px) { .meta { text-align: left; } }
  h2 { font-size: .95rem; margin: 22px 0 8px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
  .box { background: #f8f9fa; border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; }
  .box strong { font-size: 1.02rem; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: .75rem; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--line); padding: 6px 8px; }
  td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .totals { margin-left: auto; width: min(340px, 100%); margin-top: 14px; }
  .totals td { border: 0; padding: 4px 8px; }
  .totals .grand td { border-top: 2px solid var(--ink); font-size: 1.2rem; font-weight: 700; padding-top: 8px; }
  .terms { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
  .notes { white-space: pre-wrap; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: .8rem; font-weight: 600; background: var(--soft); color: var(--accent); }
  .badge.red { background: #fdecea; color: #b42318; }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin: 26px 0 4px; }
  .btn { font: inherit; font-weight: 600; padding: 11px 20px; border-radius: 8px; border: 1px solid var(--line); background: #fff; cursor: pointer; color: var(--ink); text-decoration: none; display: inline-block; }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .btn.danger { color: #b42318; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: var(--soft); color: var(--accent); font-weight: 500; }
  .alert.err { background: #fdecea; color: #b42318; }
  textarea { width: 100%; font: inherit; padding: 10px; border: 1px solid var(--line); border-radius: 8px; min-height: 70px; margin-top: 8px; }
  details { margin-top: 10px; text-align: center; }
  footer { text-align: center; color: var(--muted); font-size: .8rem; margin-top: 26px; }
  @media (max-width: 640px) { .co-wrap { flex-direction: column; align-items: flex-start; gap: 10px; } .logo { max-width: 150px; } .page { margin: 0; border-radius: 0; padding: 22px 16px; border: 0; } .meta { text-align: left; } table.items th:nth-child(3), table.items td:nth-child(3) { display: none; } }
  @page { size: A4; margin: 14mm 12mm; }
  @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } body { background: #fff; font-size: 12.5px; }
    header.top { flex-wrap: nowrap; } .meta { text-align: right; flex: none; } .co-wrap { min-width: 0; } .logo { max-width: 150px; max-height: 70px; }
    table, .box, .totals { break-inside: avoid; } .page { border: 0; margin: 0; padding: 0; max-width: none; } .no-print { display: none !important; } }
</style>
</head>
<body>
<main class="page">
  <?php if (isset($_GET['portal'])): ?><p class="no-print" style="margin:0 0 14px"><a href="cliente/" style="color:var(--accent)">← Voltar ao portal</a></p><?php endif; ?>
  <?php if ($message): ?><div class="alert no-print"><?= $e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert err no-print"><?= $e($error) ?></div><?php endif; ?>
  <header class="top">
    <div class="co-wrap">
    <?php if ($co['company_logo']): ?><img class="logo" src="<?= $e($co['company_logo']) ?>" alt="<?= $e($company) ?>"><?php endif; ?>
    <div class="co">
      <h1><?= $e($company) ?></h1>
      <?php $legal = trim((string)$co['company_legal_name']); $legal = $legal !== '' && mb_strtolower($legal) !== mb_strtolower(trim((string)$co['company_name'])) ? $legal : ''; ?>
      <?php if ($legal || $co['company_document']): ?><div><?= $e(implode(' · ', array_filter([$legal, $co['company_document'] ? 'CNPJ ' . $doc($co['company_document']) : '']))) ?></div><?php endif; ?>
      <?php if ($co['company_address']): ?><div><?= $e($co['company_address']) ?></div><?php endif; ?>
      <div><?= $e(implode(' · ', array_filter([$co['company_phone'], $co['company_email'], $co['company_site']]))) ?></div>
    </div>
    </div>
    <div class="meta">
      <div class="num-q">Orçamento #<?= (int)$q['number'] ?></div>
      <div>Emitido em <?= $date($q['request_date']) ?></div>
      <?php if ($q['expires_at']): ?><div>Válido até <?= $date($q['expires_at']) ?></div><?php endif; ?>
      <?php if ($q['external_code']): ?><div>Ref.: <?= $e($q['external_code']) ?></div><?php endif; ?>
      <?php if ($q['user_name']): ?>
        <div class="resp"><span>Responsável</span><strong><?= $e($q['user_name']) ?></strong>
          <?php if ($q['user_phone']): ?><a href="tel:<?= $e(preg_replace('/[^\d+]/', '', $q['user_phone'])) ?>"><?= $e($q['user_phone']) ?></a><?php endif; ?>
          <?php if ($q['user_email']): ?><a href="mailto:<?= $e($q['user_email']) ?>"><?= $e($q['user_email']) ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
      <div style="margin-top:6px">
        <?php if ($q['status'] === 'aprovado' || $q['status'] === 'vendido'): ?><span class="badge">Aprovado</span>
        <?php elseif ($q['status'] === 'recusado'): ?><span class="badge red">Recusado</span>
        <?php elseif ($expired): ?><span class="badge red">Vencido</span><?php endif; ?>
      </div>
    </div>
  </header>

  <h2>Cliente</h2>
  <div class="box">
    <strong><?= $e($client ?: '—') ?></strong>
    <?php if ($q['customer_id'] && $q['customer_trade_name']): ?><div><?= $e($q['customer_name']) ?></div><?php endif; ?>
    <div style="color:var(--muted);font-size:.9rem">
      <?= $e(implode(' · ', array_filter([$q['customer_document'] ? $doc($q['customer_document']) : '', $addr,
          $q['customer_id'] ? $q['customer_phone'] : $q['lead_phone'], $q['customer_id'] ? $q['customer_email'] : $q['lead_email']]))) ?>
    </div>
  </div>

  <?= itemsTable($products, 'Produtos', $hasLineDisc, $e, $money, $qty) ?>
  <?= itemsTable($services, 'Serviços', $hasLineDisc, $e, $money, $qty) ?>
  <?php if ($q['extras']): ?>
    <h2>Custos adicionais</h2>
    <table><tbody><?php foreach ($q['extras'] as $x): ?><tr><td><?= $e($x['description']) ?></td><td class="num"><?= $money($x['amount']) ?></td></tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>

  <table class="totals"><tbody>
    <?php if ((float)$q['products_total']): ?><tr><td>Produtos</td><td class="num"><?= $money($q['products_total']) ?></td></tr><?php endif; ?>
    <?php if ((float)$q['services_total']): ?><tr><td>Serviços</td><td class="num"><?= $money($q['services_total']) ?></td></tr><?php endif; ?>
    <?php if ((float)$q['extras_total']): ?><tr><td>Custos adicionais</td><td class="num"><?= $money($q['extras_total']) ?></td></tr><?php endif; ?>
    <?php if ((float)$q['discount_total']): ?><tr><td>Desconto<?= $q['discount_type'] === 'percent' ? ' (' . $qty($q['discount_value']) . '%)' : '' ?></td><td class="num">− <?= $money($q['discount_total']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="num"><?= $money($q['total']) ?></td></tr>
  </tbody></table>

  <?php if ($methods || $q['payment_terms']): ?>
    <h2>Pagamento</h2>
    <div class="terms">
      <?php if ($methods): ?><div class="box"><div style="color:var(--muted);font-size:.8rem">Forma de pagamento</div><?= $e(implode(', ', $methods)) ?></div><?php endif; ?>
      <?php if ((int)$q['installments'] > 1 || (int)$q['installment_entry']): ?><div class="box"><div style="color:var(--muted);font-size:.8rem">Parcelamento</div><?= (int)$q['installment_entry'] ? 'Entrada + ' : '' ?><?= (int)$q['installments'] ?>x</div><?php endif; ?>
      <?php if ($q['payment_terms']): ?><div class="box"><div style="color:var(--muted);font-size:.8rem">Condição</div><?= $e($q['payment_terms']) ?></div><?php endif; ?>
    </div>
    <?php $plan = json_decode((string)($q['installment_plan'] ?? ''), true); if (((int)$q['installments'] > 1 || (int)$q['installment_entry']) && is_array($plan) && $plan): $hasE = !empty($plan[0]['entry']); $np = count($plan) - ($hasE ? 1 : 0); ?>
      <table class="plan" style="margin-top:12px"><thead><tr><th>Parcela</th><th class="num">Valor</th><th class="num">Vencimento</th></tr></thead><tbody>
      <?php foreach ($plan as $k => $pp): ?><tr><td><?= !empty($pp['entry']) ? '<strong>Entrada</strong>' : ($k + ($hasE ? 0 : 1)) . '/' . $np ?></td><td class="num"><?= $money($pp['amount']) ?></td><td class="num"><?= $date($pp['due_date']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <div style="color:var(--muted);font-size:.8rem;margin-top:4px">Os vencimentos podem mudar conforme a data da compra.</div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($q['notes']): ?><h2>Observações</h2><div class="box notes"><?= $e($q['notes']) ?></div><?php endif; ?>

  <div class="actions no-print">
    <a class="btn" href="?t=<?= $e(urlencode($token)) ?>&amp;download=1">Baixar PDF</a>
    <button class="btn" onclick="window.print()">Imprimir</button>
    <?php if ($canRespond && !$q['customer_id']): ?>
      <div style="color:var(--muted);margin-top:10px">Para aprovar, entre em contato com <?= $e($q['user_name'] ?: 'o responsável') ?><?= $q['user_phone'] ? ' pelo ' . $e($q['user_phone']) : '' ?>.</div>
    <?php elseif ($canRespond): ?>
      <form method="post" style="display:inline"><input type="hidden" name="action" value="aprovar"><button class="btn primary" onclick="return confirm('Confirmar a aprovação deste orçamento?')">Aprovar orçamento</button></form>
    <?php endif; ?>
  </div>
  <?php if ($canRespond): ?>
    <details class="no-print"><summary style="cursor:pointer;color:var(--muted)">Não vai aprovar? Conte para nós</summary>
      <form method="post" style="max-width:480px;margin:10px auto 0;text-align:left">
        <input type="hidden" name="action" value="recusar">
        <textarea name="comment" maxlength="500" placeholder="Motivo (opcional): preço, prazo, outra proposta…"></textarea>
        <div style="text-align:right;margin-top:8px"><button class="btn danger">Recusar orçamento</button></div>
      </form>
    </details>
  <?php endif; ?>
  <footer>Responsável: <?= $e($q['user_name']) ?> · Orçamento #<?= (int)$q['number'] ?></footer>
</main>
<?php if (isset($_GET['pdf'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script><?php endif; ?>
</body>
</html>
