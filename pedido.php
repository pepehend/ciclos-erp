<?php
/**
 * Página pública da ordem de compra (link enviado ao fornecedor).
 * O fornecedor vê, imprime/salva em PDF e confirma o recebimento do pedido.
 */

require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$money = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$price = function ($v) {
    $v = (float)$v;
    $dec = abs($v * 100 - round($v * 100)) > 0.0001 ? 4 : 2;
    return 'R$ ' . number_format($v, $dec, ',', '.');
};
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
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pedido de compra</title>'
        . '<body style="font-family:system-ui;padding:40px;text-align:center;color:#555"><h2>Pedido não encontrado</h2>'
        . '<p>O link pode estar incompleto. Peça um novo link a quem enviou.</p></body>';
    exit;
};
if (!app_connect()) $notFound(503);
try { $o = PurchaseOrders::byToken($token); } catch (AppError $ex) { $notFound(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        PurchaseOrders::supplierConfirm($token, (string)($_POST['comment'] ?? ''));
        $message = 'Pedido confirmado. Obrigado!';
    } catch (AppError $ex) {
        $error = $ex->getMessage();
    }
    $o = PurchaseOrders::byToken($token);
}
$co = Settings::all();
$company = $co['company_name'] ?: 'Pedido de compra';
$supplier = $o['supplier_trade_name'] ?: $o['supplier_name'];
$showPrices = (float)$o['items_total'] > 0;
$hasCodes = (bool)array_filter($o['items'], fn($i) => $i['supplier_code'] || $i['sku']);
$canConfirm = in_array($o['status'], ['rascunho', 'enviada'], true) && !$message && $co['po_supplier_confirm'] !== '0';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pedido de compra #<?= (int)$o['id'] ?> · <?= $e($company) ?></title>
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
  .btn { font: inherit; font-weight: 600; padding: 11px 20px; border-radius: 8px; border: 1px solid var(--line); background: #fff; cursor: pointer; color: var(--ink); }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .btn.danger { color: #b42318; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: var(--soft); color: var(--accent); font-weight: 500; }
  .alert.err { background: #fdecea; color: #b42318; }
  textarea { width: 100%; font: inherit; padding: 10px; border: 1px solid var(--line); border-radius: 8px; min-height: 70px; margin-top: 8px; }
  details { margin-top: 10px; text-align: center; }
  footer { text-align: center; color: var(--muted); font-size: .8rem; margin-top: 26px; }
  @media (max-width: 640px) { .co-wrap { flex-direction: column; align-items: flex-start; gap: 10px; } .logo { max-width: 150px; } .page { margin: 0; border-radius: 0; padding: 22px 16px; border: 0; } .meta { text-align: left; } table.items th.code, table.items td.code { display: none; } }
  @page { size: A4; margin: 14mm 12mm; }
  @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } body { background: #fff; font-size: 12.5px; }
    header.top { flex-wrap: nowrap; } .meta { text-align: right; flex: none; } .co-wrap { min-width: 0; } .logo { max-width: 150px; max-height: 70px; }
    table, .box, .totals { break-inside: avoid; } .page { border: 0; margin: 0; padding: 0; max-width: none; } .no-print { display: none !important; } }
</style>
</head>
<body>
<main class="page">
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
      <div class="num-q">Pedido de compra #<?= (int)$o['id'] ?></div>
      <div>Data do pedido <?= $date($o['order_date']) ?></div>
      <?php if ($o['expected_date']): ?><div>Entrega desejada até <?= $date($o['expected_date']) ?></div><?php endif; ?>
      <div style="margin-top:6px">
        <?php if ($o['status'] === 'confirmada' || $o['status'] === 'recebida'): ?><span class="badge">Confirmado</span>
        <?php elseif ($o['status'] === 'cancelada'): ?><span class="badge red">Cancelado</span><?php endif; ?>
      </div>
    </div>
  </header>

  <h2>Fornecedor</h2>
  <div class="box">
    <strong><?= $e($supplier) ?></strong>
    <?php if ($o['supplier_trade_name']): ?><div><?= $e($o['supplier_name']) ?></div><?php endif; ?>
    <div style="color:var(--muted);font-size:.9rem">
      <?= $e(implode(' · ', array_filter([$o['supplier_document'] ? $doc($o['supplier_document']) : '', $o['supplier_phone'], $o['supplier_email']]))) ?>
    </div>
  </div>

  <h2>Itens</h2>
  <table class="items"><thead><tr>
    <?php if ($hasCodes): ?><th class="code">Código</th><?php endif; ?>
    <th>Descrição</th><th class="num">Qtd.</th>
    <?php if ($showPrices): ?><th class="num">Valor unit.</th><th class="num">Total</th><?php endif; ?>
  </tr></thead><tbody>
  <?php foreach ($o['items'] as $i): ?>
    <tr>
      <?php if ($hasCodes): ?><td class="code"><?= $e($i['supplier_code'] ?: $i['sku']) ?></td><?php endif; ?>
      <td><?= $e($i['description']) ?></td>
      <td class="num"><?= $qty($i['quantity']) ?> <?= $e($i['unit']) ?></td>
      <?php if ($showPrices): ?><td class="num"><?= $price($i['unit_cost']) ?></td><td class="num"><?= $money($i['total']) ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody></table>

  <?php if ($showPrices): ?>
  <table class="totals"><tbody>
    <tr><td>Itens</td><td class="num"><?= $money($o['items_total']) ?></td></tr>
    <?php if ((float)$o['freight']): ?><tr><td>Frete</td><td class="num"><?= $money($o['freight']) ?></td></tr><?php endif; ?>
    <?php if ((float)$o['discount']): ?><tr><td>Desconto</td><td class="num">− <?= $money($o['discount']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="num"><?= $money($o['total']) ?></td></tr>
  </tbody></table>
  <?php endif; ?>

  <?php if ($o['payment_method'] || $o['payment_terms']): ?>
    <h2>Pagamento</h2>
    <div class="terms">
      <?php if ($o['payment_method']): ?><div class="box"><div style="color:var(--muted);font-size:.8rem">Forma de pagamento</div><?= $e(PaymentMethods::label($o['payment_method'])) ?></div><?php endif; ?>
      <?php if ($o['payment_terms']): ?><div class="box"><div style="color:var(--muted);font-size:.8rem">Condição</div><?= $e($o['payment_terms']) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($o['notes']): ?><h2>Observações</h2><div class="box notes"><?= $e($o['notes']) ?></div><?php endif; ?>
  <?php if ($o['supplier_comment']): ?><h2>Comentário do fornecedor</h2><div class="box notes"><?= $e($o['supplier_comment']) ?></div><?php endif; ?>

  <div class="actions no-print">
    <button class="btn" onclick="window.print()">Imprimir / salvar PDF</button>
    <?php if ($canConfirm): ?>
      <button class="btn primary" type="button" onclick="document.getElementById('cf').hidden=false;this.hidden=true">Confirmar pedido</button>
    <?php endif; ?>
  </div>
  <?php if ($canConfirm): ?>
    <form id="cf" method="post" hidden class="no-print" style="max-width:480px;margin:10px auto 0">
      <textarea name="comment" maxlength="500" placeholder="Comentário (opcional): prazo de entrega, número do seu pedido, item em falta…"></textarea>
      <div style="text-align:right;margin-top:8px"><button class="btn primary">Enviar confirmação</button></div>
    </form>
  <?php endif; ?>
  <footer>Solicitante: <?= $e($o['user_name']) ?> · Pedido de compra #<?= (int)$o['id'] ?></footer>
</main>
</body>
</html>
