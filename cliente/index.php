<?php
/**
 * Portal do cliente: <sistema>/cliente/
 * Entrada com CPF/CNPJ e senha (a senha é criada pelo link de convite gerado no cadastro do cliente).
 * Abas: Orçamentos, Compras, Parcelas e Meus dados (com pedido de correção e troca de senha).
 */

require __DIR__ . '/../app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$money = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
$date = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';
$doc = function ($d) {
    $d = preg_replace('/\D/', '', (string)$d);
    if (strlen($d) === 14) return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
    if (strlen($d) === 11) return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d);
    return $d;
};

if (!app_connect()) { http_response_code(503); echo 'Sistema indisponível no momento.'; exit; }
Portal::start();

$page = (string)($_GET['p'] ?? '');
$tab = (string)($_GET['aba'] ?? 'orcamentos');
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$error = null;
$go = function (string $qs = '', ?string $msg = null) {
    if ($msg) $_SESSION['flash'] = $msg;
    header('Location: ./' . ($qs !== '' ? '?' . $qs : ''));
    exit;
};

// ---------------------------------------------------------------- ações (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    try {
        Portal::checkCsrf((string)($_POST['csrf'] ?? ''));
        switch ($act) {
            case 'login':
                Portal::login((string)($_POST['documento'] ?? ''), (string)($_POST['senha'] ?? ''));
                $go();
            case 'empresa':
                Portal::pickCompany((int)($_POST['cliente'] ?? 0));
                $go();
            case 'convite':
                Portal::acceptInvite((string)($_POST['t'] ?? ''), (string)($_POST['senha'] ?? ''), (string)($_POST['senha2'] ?? ''));
                $go('', 'Senha criada. Bem-vindo!');
            case 'sair':
                Portal::logout();
                $go();
            case 'senha':
                $c = Portal::customer() ?? $go();
                Portal::changePassword((int)$c['id'], (string)($_POST['atual'] ?? ''), (string)($_POST['senha'] ?? ''), (string)($_POST['senha2'] ?? ''));
                $go('aba=dados', 'Senha alterada.');
            case 'correcao':
                $c = Portal::customer() ?? $go();
                Portal::requestChange((int)$c['id'], (string)($_POST['mensagem'] ?? ''));
                $go('aba=dados', 'Pedido de correção enviado. Vamos atualizar seus dados e retornar.');
        }
    } catch (AppError $ex) {
        $error = $ex->getMessage();
        if ($act === 'convite') $page = 'convite';
        if (in_array($act, ['senha', 'correcao'], true)) $tab = 'dados';
    }
}

$me = Portal::customer();   // define a empresa pela sessão do cliente
$choices = $me ? [] : Portal::choices();   // cliente de mais de uma empresa: escolhe depois do login
// ---------------------------------------------------------------- dados da página
$invite = null;
if ($page === 'convite') {
    $token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
    $invite = Portal::inviteCustomer($token);   // define a empresa pelo convite
} elseif ($me) {
    $quotes = Portal::quotes((int)$me['id']);
    $sales = Portal::sales((int)$me['id']);
    $entries = Portal::entries((int)$me['id']);
    $open = array_filter($entries, fn($r) => $r['situation'] !== 'pago');
    $late = array_filter($entries, fn($r) => $r['situation'] === 'vencido');
    $waiting = array_filter($quotes, fn($q) => $q['tone'] === 'warn');
    $sale = null;
    if ($page === 'compra') {
        $sale = Portal::sale((int)$me['id'], (int)($_GET['id'] ?? 0));
        if (!$sale) $go('aba=compras');
    }
    if (!in_array($tab, ['orcamentos', 'compras', 'parcelas', 'dados'], true)) $tab = 'orcamentos';
    $requests = DB::all('SELECT message, created_at, resolved_at FROM portal_requests WHERE customer_id = ? AND company_id = ? ORDER BY id DESC LIMIT 10', [$me['id'], Tenant::need()]);
}
// Marca e dados da empresa (a empresa já foi definida pela sessão ou pelo convite; sem ela vale o padrão)
$co = Settings::all();
$company = $co['company_name'] ?: 'Portal do cliente';
// Tela de entrada (login e convite): mesmo visual da entrada do sistema
$hour = (int)date('G');
$hello = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
$brandName = $co['company_name'] ?: 'CiclosERP';
$loginIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>';
$sitLabel = ['pago' => ['Pago', 'ok'], 'aberto' => ['Em aberto', 'warn'], 'vencido' => ['Vencido', 'err']];
$csrf = $e(Portal::csrf());
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script>(function(){try{var t=localStorage.getItem('portalTheme')||'auto';var d=t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');}catch(e){}})();</script>
<title>Portal do cliente · <?= $e($company) ?></title>
<?php if ($co['company_logo']): ?><link rel="icon" href="<?= $e($co['company_logo']) ?>"><?php endif; ?>
<style>
  :root { --ink: #212529; --muted: #6c757d; --line: #dee2e6; --accent: #b02a37; --soft: #f8d7da; --bg: #eef0f2; --card: #fff; --field: #ced4da;
    --ok-bg: #e8f5ee; --ok: #146c43; --err-bg: #fdecea; --err: #b42318; --warn-bg: #fff4e5; --warn: #b54708; --info-bg: #e7f1ff; --info: #1d4ed8; --mute-bg: #f1f3f5; color-scheme: light; }
  html[data-theme="dark"] { --ink: #e7e7ea; --muted: #a1a1aa; --line: #2e2e33; --accent: #e5707b; --soft: #3a2427; --bg: #121214; --card: #1c1c1f; --field: #3a3a40;
    --ok-bg: #16301f; --ok: #6fd39a; --err-bg: #3a1d1d; --err: #ff8f8a; --warn-bg: #3a2c14; --warn: #f5b25c; --info-bg: #17243d; --info: #8fb4ff; --mute-bg: #26262b; color-scheme: dark; }
  html[data-theme="dark"] .btn.primary { color: #1c1c1f; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); }
  a { color: var(--accent); }
  .wrap { max-width: 980px; margin: 0 auto; padding: 0 16px; }
  header.bar { background: var(--card); border-bottom: 1px solid var(--line); }
  header.bar .wrap { display: flex; align-items: center; gap: 14px; min-height: 64px; flex-wrap: wrap; padding-top: 8px; padding-bottom: 8px; }
  .brand { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 1.1rem; color: var(--ink); text-decoration: none; margin-right: auto; }
  .brand img { max-height: 40px; max-width: 120px; object-fit: contain; }
  .who { color: var(--muted); font-size: .9rem; text-align: right; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 22px; margin: 18px 0; }
  .login { max-width: 420px; margin: 8vh auto; }
  .login h1 { font-size: 1.3rem; margin: 0 0 4px; }
  label { display: block; font-weight: 600; font-size: .9rem; margin: 14px 0 4px; }
  input, textarea { width: 100%; font: inherit; padding: 10px 12px; border: 1px solid var(--field); border-radius: 8px; background: var(--card); color: var(--ink); }
  textarea { min-height: 90px; }
  .btn { font: inherit; font-weight: 600; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); cursor: pointer; color: var(--ink); text-decoration: none; display: inline-block; }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .btn.sm { padding: 6px 12px; font-size: .88rem; }
  .full { width: 100%; margin-top: 18px; }
  .muted { color: var(--muted); }
  .small { font-size: .88rem; }
  .alert { padding: 12px 16px; border-radius: 8px; margin: 14px 0; background: var(--ok-bg); color: var(--ok); font-weight: 500; }
  .alert.err { background: var(--err-bg); color: var(--err); }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 18px; }
  .stat { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
  .stat span { display: block; color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
  .stat strong { font-size: 1.3rem; }
  .stat.err strong { color: var(--err); }
  nav.tabs { display: flex; gap: 4px; margin-top: 18px; border-bottom: 1px solid var(--line); overflow-x: auto; }
  nav.tabs a { padding: 10px 14px; text-decoration: none; color: var(--muted); font-weight: 600; border-bottom: 2px solid transparent; white-space: nowrap; }
  nav.tabs a.on { color: var(--accent); border-color: var(--accent); }
  nav.tabs a .n { background: var(--soft); color: var(--accent); border-radius: 99px; padding: 0 7px; font-size: .78rem; margin-left: 4px; }
  .scroll { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: .75rem; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--line); padding: 8px; white-space: nowrap; }
  td { padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; }
  tr:last-child td { border-bottom: 0; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: .8rem; font-weight: 600; white-space: nowrap; }
  .badge.ok { background: var(--ok-bg); color: var(--ok); } .badge.err { background: var(--err-bg); color: var(--err); }
  .badge.warn { background: var(--warn-bg); color: var(--warn); } .badge.info { background: var(--info-bg); color: var(--info); } .badge.muted { background: var(--mute-bg); color: var(--muted); }
  .btn.theme { width: 38px; height: 38px; padding: 0; display: inline-flex; align-items: center; justify-content: center; line-height: 0; }
  .btn.theme svg { width: 18px; height: 18px; display: block; flex: none; }
  html[data-theme="dark"] .theme .moon, html:not([data-theme="dark"]) .theme .sun { display: none; }
  .empty { text-align: center; color: var(--muted); padding: 30px 10px; }
  dl.data { display: grid; grid-template-columns: max-content 1fr; gap: 6px 18px; margin: 0; }
  dl.data dt { color: var(--muted); } dl.data dd { margin: 0; font-weight: 500; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  @media (max-width: 720px) { .grid2 { grid-template-columns: 1fr; } dl.data { grid-template-columns: 1fr; gap: 0 0; } dl.data dd { margin-bottom: 8px; } .who { text-align: left; } }
  footer { text-align: center; color: var(--muted); font-size: .8rem; margin: 28px 0; }
/* ============ Tela de login (sempre escura, com céu estrelado) ============ */
.lg-page { --lg-bg: #1f1633; --lg-card: #1a1229; --lg-line: #352a4f; --lg-lime: #c6f04a; --lg-text: #f4f1fb; --lg-muted: #b9b0cf;
  min-height: 100vh; display: flex; flex-direction: column; color: var(--lg-text); padding: 20px clamp(16px, 4vw, 48px) 24px;
  background-color: var(--lg-bg);
  background-image:
    radial-gradient(circle at 20px 30px, #ffffffb0 0 1.1px, transparent 1.6px), radial-gradient(circle at 150px 90px, #ffffff80 0 1px, transparent 1.5px),
    radial-gradient(circle at 80px 170px, #ffffff99 0 1.3px, transparent 1.8px), radial-gradient(circle at 210px 200px, #ffffff70 0 .9px, transparent 1.4px),
    radial-gradient(circle at 40px 60px, #ffffff60 0 .9px, transparent 1.4px), radial-gradient(circle at 120px 20px, #ffffff90 0 1.2px, transparent 1.7px);
  background-size: 260px 230px, 260px 230px, 260px 230px, 260px 230px, 170px 150px, 170px 150px; }
.lg-top, .lg-main, .lg-foot { width: 100%; max-width: 1100px; margin: 0 auto; }
.lg-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.lg-brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 1.25rem; letter-spacing: -.01em; }
.lg-logo { width: 38px; height: 38px; border-radius: 9px; display: grid; place-items: center; background: #b02a37; color: #fff; overflow: hidden; flex: none; }
.lg-logo.img { background: #fff; padding: 3px; }
.lg-logo img { width: 100%; height: 100%; object-fit: contain; }
.lg-logo svg { width: 20px; height: 20px; }
.lg-client { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 8px; background: #ffffff1f; color: var(--lg-text); text-decoration: none; font-weight: 800; font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
.lg-client:hover { background: #ffffff30; }
.lg-client svg { width: 16px; height: 16px; }
.lg-main { flex: 1; display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(300px, 420px); gap: clamp(24px, 6vw, 96px); align-items: center; padding: 40px 0; }
.lg-hero { position: relative; }
.lg-ver { display: inline-block; background: #120c1e; color: var(--lg-text); font-weight: 700; font-size: .85rem; padding: 5px 9px; border-radius: 5px; }
.lg-hero h1 { font-size: clamp(2.6rem, 6.4vw, 5.2rem); line-height: 1.04; font-weight: 800; letter-spacing: -.035em; margin: 22px 0 18px; color: #fff; }
.lg-hero mark { background: var(--lg-lime); color: var(--lg-bg); padding: 0 .12em; border-radius: 2px; white-space: nowrap; }
.lg-hero p { color: var(--lg-muted); font-weight: 600; font-size: 1.05rem; margin: 0; }
.lg-st { position: absolute; width: clamp(70px, 9vw, 110px); filter: drop-shadow(3px 0 0 #fff) drop-shadow(-3px 0 0 #fff) drop-shadow(0 3px 0 #fff) drop-shadow(0 -3px 0 #fff) drop-shadow(0 6px 12px #0006); animation: lg-float 6s ease-in-out infinite; }
.lg-st.st1 { right: 4%; top: -18%; transform: rotate(10deg); }
.lg-st.st2 { right: 24%; bottom: -62%; transform: rotate(-8deg); animation-delay: -3s; }
@keyframes lg-float { 50% { translate: 0 -10px; } }
@media (prefers-reduced-motion: reduce) { .lg-st { animation: none; } }
.lg-card { background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 16px; padding: 30px 32px; display: grid; gap: 16px; box-shadow: 0 20px 50px #0005; }
.lg-card h2 { margin: 0 0 4px; font-size: 1.4rem; font-weight: 700; color: #fff; letter-spacing: -.01em; }
.lg-card label { color: #fff; font-weight: 700; display: grid; gap: 8px; }
.lg-card label i { color: #ef4444; font-style: normal; margin-left: -4px; }
.lg-card label { grid-template-columns: auto 1fr; } .lg-card label input { grid-column: 1 / -1; }
.lg-card input { background: #e8eef9; color: #111; border: 2px solid transparent; border-radius: 8px; padding: 11px 12px; font-size: .95rem; }
.lg-card input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px #3b82f633; }
.lg-btn { margin-top: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 8px; padding: 14px; background: #b02a37; color: #fff; font-weight: 800; font-size: .95rem; text-transform: uppercase; letter-spacing: .04em; cursor: pointer; transition: filter .15s, transform .15s; }
.lg-btn:hover { filter: brightness(1.08); } .lg-btn:active { transform: translateY(1px); }
.lg-btn svg { width: 18px; height: 18px; } .lg-btn:disabled { opacity: .7; cursor: default; }
.lg-help { margin: 0; color: var(--lg-muted); font-size: .8rem; }
.lg-foot { display: grid; gap: 14px; justify-items: center; color: var(--lg-muted); font-weight: 600; font-size: .9rem; }
.lg-foot svg { width: 100%; height: 12px; }
@media (max-width: 860px) {
  .lg-main { grid-template-columns: 1fr; padding: 28px 0; }
  .lg-st.st1 { top: -6%; right: 0; } .lg-st.st2 { display: none; }
  .lg-card { padding: 24px 20px; }
}

  .lg-page { margin: 0; } .lg-card form { display: grid; gap: 16px; } .lg-card label { margin: 0; font-size: 1rem; }
  .lg-card .alert { margin: 0; } .lg-hint { grid-column: 1 / -1; color: var(--lg-muted); font-weight: 500; font-size: .8rem; margin-top: -4px; }
  a.lg-btn { text-decoration: none; }

/* Mensagem de campo inválido (substitui o balão do navegador) */
.field-err { grid-column: 1 / -1; display: flex; align-items: center; gap: 6px; margin-top: 4px; color: var(--err); font-size: .8rem; font-weight: 600; line-height: 1.3; animation: ferr-in .15s ease-out; }
.field-err::before { content: "!"; flex: none; display: inline-grid; place-items: center; width: 16px; height: 16px; border-radius: 50%; background: var(--err); color: #fff; font-size: .7rem; font-weight: 800; }
:is(input, select, textarea).is-invalid { border-color: var(--err) !important; box-shadow: 0 0 0 3px color-mix(in srgb, var(--err) 22%, transparent) !important; }
@keyframes ferr-in { from { opacity: 0; transform: translateY(-3px); } }
.lg-card .field-err { color: #ff9b9b; margin-top: -2px; }
.lg-card .field-err::before { background: #ef4444; }
.lg-card input.is-invalid { border-color: #ef4444 !important; box-shadow: 0 0 0 3px #ef444440 !important; }
</style>
</head>
<body>
<?php if (!$me || $page === 'convite'): ?>
<div class="lg-page">
  <header class="lg-top"><div class="lg-brand"><span class="lg-logo<?= $co['company_logo'] ? ' img' : '' ?>"><?php if ($co['company_logo']): ?><img src="<?= $e($co['company_logo']) ?>" alt=""><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg><?php endif; ?></span><?= $e($brandName) ?></div><a class="lg-client" href="../"><?= $loginIcon ?>Acesso da equipe</a></header>
  <main class="lg-main">
    <section class="lg-hero">
      <span class="lg-ver">Portal do cliente</span>
      <h1>Suas ordens<br><mark>em dia</mark>.</h1>
      <p>Acompanhe suas ordens de serviço, orçamentos e parcelas</p>
      <svg viewBox="0 0 120 120" class="lg-st st1" aria-hidden="true"><g stroke="#1f1633" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"><path d="M20 42 60 24l40 18v44L60 104 20 86z" fill="#f4a259"/><path d="M20 42l40 18 40-18M60 60v44" fill="none"/><path d="M60 60 100 42 100 86 60 104z" fill="#e0823a"/><path d="M40 33l40 18v14l-9-4-9 4V47L22 41" fill="#c6f04a"/><path d="M86 14l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#f78fb3"/><path d="M16 96l2 5 5 2-5 2-2 5-2-5-5-2 5-2z" fill="#fff"/></g></svg>
      <svg viewBox="0 0 120 120" class="lg-st st2" aria-hidden="true"><g stroke="#1f1633" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"><rect x="24" y="18" width="66" height="86" rx="10" fill="#f78fb3"/><rect x="33" y="30" width="48" height="64" rx="5" fill="#fff"/><rect x="44" y="12" width="26" height="12" rx="4" fill="#c6f04a"/><path d="M42 82V68M54 82V56M66 82V62M78 82V48" stroke-width="6"/><path d="M40 45h20" stroke="#a78bfa"/><path d="M96 70l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#c6f04a"/></g></svg>
    </section>
    <div class="lg-card">
    <?php if ($page === 'convite' && !$invite): ?>
      <h2>Link de acesso inválido</h2>
      <p class="lg-help" style="font-size:.9rem">Este link venceu ou já foi usado. Peça um novo a quem atende você<?= $co['company_phone'] ? ' (' . $e($co['company_phone']) . ')' : '' ?>.</p>
      <a class="lg-btn" href="./">Ir para a entrada</a>
    <?php elseif ($page === 'convite'): ?>
      <h2>Crie sua senha</h2>
      <p class="lg-help" style="font-size:.9rem">Olá, <strong><?= $e($invite['trade_name'] ?: $invite['name']) ?></strong>! Para entrar depois, use o seu <?= strlen((string)$invite['document']) === 14 ? 'CNPJ' : 'CPF' ?> (<?= $e($doc($invite['document'])) ?>) e esta senha.</p>
      <?php if ($error): ?><div class="alert err"><?= $e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="convite"><input type="hidden" name="t" value="<?= $e($_GET['t'] ?? $_POST['t'] ?? '') ?>">
        <label>Senha <i>*</i><span class="lg-hint">mínimo <?= Portal::MIN_PASSWORD ?> caracteres</span><input type="password" name="senha" minlength="<?= Portal::MIN_PASSWORD ?>" autocomplete="new-password" required autofocus></label>
        <label>Repita a senha <i>*</i><input type="password" name="senha2" minlength="<?= Portal::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
        <button class="lg-btn"><?= $loginIcon ?>Criar senha e entrar</button>
      </form>
    <?php elseif ($choices): ?>
      <h2>Escolha a empresa</h2>
      <p class="lg-help" style="font-size:.9rem">Seu CPF/CNPJ está cadastrado em mais de uma empresa. Em qual delas você quer entrar?</p>
      <?php if ($error): ?><div class="alert err"><?= $e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="empresa">
        <?php foreach ($choices as $ch): ?>
          <button class="lg-btn" name="cliente" value="<?= (int)$ch['id'] ?>"><?= $loginIcon ?><?= $e($ch['company']) ?></button>
        <?php endforeach; ?>
      </form>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="sair"><p class="lg-help"><button class="lg-help" style="background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;color:inherit">Entrar com outro CPF/CNPJ</button></p></form>
    <?php else: ?>
      <h2><?= $hello ?>! Entre na sua conta</h2>
      <?php if ($flash): ?><div class="alert"><?= $e($flash) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert err"><?= $e($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="login">
        <label>CPF ou CNPJ <i>*</i><input name="documento" inputmode="numeric" autocomplete="username" value="<?= $e($_POST['documento'] ?? '') ?>" required autofocus></label>
        <label>Senha <i>*</i><input type="password" name="senha" autocomplete="current-password" required></label>
        <button class="lg-btn"><?= $loginIcon ?>Entrar</button>
      </form>
      <p class="lg-help">Primeiro acesso ou esqueceu a senha? Peça o link de acesso a quem atende você<?= $co['company_phone'] ? ': ' . $e($co['company_phone']) : '' ?>.</p>
    <?php endif; ?>
    </div>
  </main>
  <footer class="lg-foot"><svg viewBox="0 0 600 12" preserveAspectRatio="none" aria-hidden="true"><path d="M0 6 Q 15 0 30 6 T 60 6 T 90 6 T 120 6 T 150 6 T 180 6 T 210 6 T 240 6 T 270 6 T 300 6 T 330 6 T 360 6 T 390 6 T 420 6 T 450 6 T 480 6 T 510 6 T 540 6 T 570 6 T 600 6" fill="none" stroke="#c6f04a" stroke-width="2"/></svg>
    <span><?= date('Y') ?> © <?= $e($co['company_name'] ?: 'CiclosERP') ?></span></footer>
</div>
<?php else: ?>
<header class="bar"><div class="wrap">
  <a class="brand" href="./"><?php if ($co['company_logo']): ?><img src="<?= $e($co['company_logo']) ?>" alt=""><?php endif; ?><span><?= $e($company) ?></span></a>
  <button type="button" class="btn sm theme" id="theme" title="Tema claro ou escuro" aria-label="Alternar tema claro ou escuro">
    <svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
  </button>
  <?php if ($me && $page !== 'convite'): ?>
    <div class="who"><strong><?= $e($me['trade_name'] ?: $me['name']) ?></strong><br><span class="small"><?= $e($doc($me['document'])) ?></span></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="sair"><button class="btn sm">Sair</button></form>
  <?php endif; ?>
</div></header>

<main class="wrap">
<?php if ($page === 'compra'): ?>
  <p style="margin-top:18px"><a href="./?aba=compras">← Minhas compras</a></p>
  <div class="card">
    <h2 style="margin:0 0 4px">Compra #<?= (int)$sale['id'] ?> <?= $sale['status'] === 'cancelada' ? '<span class="badge err">Cancelada</span>' : '' ?></h2>
    <p class="muted" style="margin:0 0 14px"><?= $date($sale['created_at']) ?> · <?= $e(PaymentMethods::label($sale['payment_method'])) ?><?= (int)$sale['installments'] > 1 ? ' em ' . (int)$sale['installments'] . 'x' : '' ?><?= $sale['payment_terms'] ? ' · ' . $e($sale['payment_terms']) : '' ?></p>
    <div class="scroll"><table>
      <thead><tr><th>Item</th><th class="num">Qtd.</th><th class="num">Valor unit.</th><th class="num">Total</th></tr></thead>
      <tbody><?php foreach ($sale['items'] as $i): ?><tr><td><?= $e($i['name']) ?></td><td class="num"><?= $qty($i['quantity']) ?> <?= $e($i['unit']) ?></td><td class="num"><?= $money($i['unit_price']) ?></td><td class="num"><?= $money($i['total']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <table style="width:min(320px,100%);margin:12px 0 0 auto"><tbody>
      <tr><td>Subtotal</td><td class="num"><?= $money($sale['subtotal']) ?></td></tr>
      <?php if ((float)$sale['surcharge']): ?><tr><td>Custos adicionais</td><td class="num"><?= $money($sale['surcharge']) ?></td></tr><?php endif; ?>
      <?php if ((float)$sale['discount']): ?><tr><td>Desconto</td><td class="num">− <?= $money($sale['discount']) ?></td></tr><?php endif; ?>
      <tr><td><strong>Total</strong></td><td class="num"><strong><?= $money($sale['total']) ?></strong></td></tr>
    </tbody></table>
    <?php if ($sale['entries']): ?>
      <h3 style="margin:22px 0 6px;font-size:1rem">Parcelas</h3>
      <div class="scroll"><table>
        <thead><tr><th>Parcela</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th></tr></thead>
        <tbody><?php foreach ($sale['entries'] as $r): $s = $r['status'] === 'pago' ? 'pago' : (substr($r['due_date'], 0, 10) < date('Y-m-d') ? 'vencido' : 'aberto'); ?>
          <tr><td><?= (int)$r['installment'] ?>/<?= (int)$r['installments'] ?></td><td><?= $date($r['due_date']) ?></td><td class="num"><?= $money($r['amount']) ?></td>
          <td><span class="badge <?= $sitLabel[$s][1] ?>"><?= $sitLabel[$s][0] ?></span><?= $s === 'pago' && $r['paid_at'] ? ' <span class="small muted">em ' . $date($r['paid_at']) . '</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php if ($flash): ?><div class="alert"><?= $e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert err"><?= $e($error) ?></div><?php endif; ?>
  <div class="stats">
    <div class="stat"><span>Orçamentos aguardando você</span><strong><?= count($waiting) ?></strong></div>
    <div class="stat"><span>Em aberto</span><strong><?= $money(array_sum(array_column($open, 'amount'))) ?></strong></div>
    <div class="stat <?= $late ? 'err' : '' ?>"><span>Vencido</span><strong><?= $money(array_sum(array_column($late, 'amount'))) ?></strong></div>
  </div>
  <nav class="tabs">
    <a href="./?aba=orcamentos" class="<?= $tab === 'orcamentos' ? 'on' : '' ?>">Orçamentos<?= $waiting ? '<span class="n">' . count($waiting) . '</span>' : '' ?></a>
    <a href="./?aba=compras" class="<?= $tab === 'compras' ? 'on' : '' ?>">Compras</a>
    <a href="./?aba=parcelas" class="<?= $tab === 'parcelas' ? 'on' : '' ?>">Parcelas<?= $late ? '<span class="n">' . count($late) . '</span>' : '' ?></a>
    <a href="./?aba=dados" class="<?= $tab === 'dados' ? 'on' : '' ?>">Meus dados</a>
  </nav>

  <?php if ($tab === 'orcamentos'): ?>
    <div class="card">
      <?php if (!$quotes): ?><div class="empty">Nenhum orçamento por aqui ainda.</div><?php else: ?>
      <div class="scroll"><table>
        <thead><tr><th>Orçamento</th><th>Data</th><th>Validade</th><th class="num">Total</th><th>Situação</th><th></th></tr></thead>
        <tbody><?php foreach ($quotes as $q): ?>
          <tr><td><strong>#<?= (int)$q['id'] ?></strong><?= $q['external_code'] ? ' <span class="small muted">' . $e($q['external_code']) . '</span>' : '' ?></td>
          <td><?= $date($q['request_date']) ?></td><td><?= $date($q['expires_at']) ?: '—' ?></td><td class="num"><?= $money($q['total']) ?></td>
          <td><span class="badge <?= $e($q['tone']) ?>"><?= $e($q['label']) ?></span></td>
          <td class="num"><?php if ($q['public_token']): ?><a class="btn sm<?= $q['tone'] === 'warn' ? ' primary' : '' ?>" href="../orcamento.php?t=<?= $e($q['public_token']) ?>&amp;portal=1"><?= $q['tone'] === 'warn' ? 'Ver e responder' : 'Ver' ?></a><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>

  <?php elseif ($tab === 'compras'): ?>
    <div class="card">
      <?php if (!$sales): ?><div class="empty">Nenhuma compra registrada.</div><?php else: ?>
      <div class="scroll"><table>
        <thead><tr><th>Compra</th><th>Data</th><th>Pagamento</th><th class="num">Itens</th><th class="num">Total</th><th></th></tr></thead>
        <tbody><?php foreach ($sales as $s): ?>
          <tr><td><strong>#<?= (int)$s['id'] ?></strong> <?= $s['status'] === 'cancelada' ? '<span class="badge err">Cancelada</span>' : '' ?></td><td><?= $date($s['created_at']) ?></td>
          <td><?= $e(PaymentMethods::label($s['payment_method'])) ?><?= (int)$s['installments'] > 1 ? ' · ' . (int)$s['installments'] . 'x' : '' ?></td>
          <td class="num"><?= (int)$s['items'] ?></td><td class="num"><?= $money($s['total']) ?></td>
          <td class="num"><a class="btn sm" href="./?p=compra&amp;id=<?= (int)$s['id'] ?>">Ver</a></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>

  <?php elseif ($tab === 'parcelas'): ?>
    <div class="card">
      <?php if (!$entries): ?><div class="empty">Nenhuma parcela.</div><?php else: ?>
      <div class="scroll"><table>
        <thead><tr><th>Descrição</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th></tr></thead>
        <tbody><?php foreach ($entries as $r): ?>
          <tr><td><?= $e($r['description']) ?><?= (int)$r['installments'] > 1 ? ' <span class="small muted">(' . (int)$r['installment'] . '/' . (int)$r['installments'] . ')</span>' : '' ?>
            <?= $r['sale_id'] ? '<div class="small"><a href="./?p=compra&amp;id=' . (int)$r['sale_id'] . '">Compra #' . (int)$r['sale_id'] . '</a></div>' : '' ?></td>
          <td><?= $date($r['due_date']) ?></td><td class="num"><?= $money($r['situation'] === 'pago' && $r['paid_amount'] !== null ? $r['paid_amount'] : $r['amount']) ?></td>
          <td><span class="badge <?= $sitLabel[$r['situation']][1] ?>"><?= $sitLabel[$r['situation']][0] ?></span><?= $r['situation'] === 'pago' && $r['paid_at'] ? ' <span class="small muted">em ' . $date($r['paid_at']) . '</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>

  <?php else: ?>
    <div class="grid2">
      <div class="card">
        <h3 style="margin-top:0">Meus dados</h3>
        <?php $addr = implode(', ', array_filter([trim(($me['street'] ?? '') . ' ' . ($me['number'] ?? '')), $me['complement'] ?? '', $me['district'] ?? '', trim(($me['city'] ?? '') . ($me['state'] ? '/' . $me['state'] : '')), $me['cep'] ? 'CEP ' . $me['cep'] : ''])); ?>
        <dl class="data">
          <dt>Nome</dt><dd><?= $e($me['name']) ?></dd>
          <?php if ($me['trade_name']): ?><dt>Nome fantasia</dt><dd><?= $e($me['trade_name']) ?></dd><?php endif; ?>
          <dt><?= strlen((string)$me['document']) === 14 ? 'CNPJ' : 'CPF' ?></dt><dd><?= $e($doc($me['document'])) ?></dd>
          <?php if ($me['state_registration']): ?><dt>Inscrição estadual</dt><dd><?= $e($me['state_registration']) ?></dd><?php endif; ?>
          <dt>E-mail</dt><dd><?= $e($me['email'] ?: '—') ?></dd>
          <dt>Telefone</dt><dd><?= $e($me['phone'] ?: '—') ?></dd>
          <dt>Endereço</dt><dd><?= $e($addr ?: '—') ?></dd>
        </dl>
        <h3>Algo errado?</h3>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="correcao">
          <textarea name="mensagem" maxlength="2000" placeholder="Ex.: meu telefone mudou para (11) 99999-0000" required></textarea>
          <button class="btn primary" style="margin-top:10px">Pedir correção</button>
        </form>
        <?php if ($requests): ?><div class="small muted" style="margin-top:14px">
          <?php foreach ($requests as $r): ?><div style="margin-top:6px"><span class="badge <?= $r['resolved_at'] ? 'ok' : 'warn' ?>"><?= $r['resolved_at'] ? 'Resolvido' : 'Em análise' ?></span> <?= $date($r['created_at']) ?>: <?= $e(mb_strimwidth($r['message'], 0, 120, '…')) ?></div><?php endforeach; ?>
        </div><?php endif; ?>
      </div>
      <div class="card">
        <h3 style="margin-top:0">Trocar senha</h3>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="senha">
          <label>Senha atual<input type="password" name="atual" autocomplete="current-password" required></label>
          <label>Nova senha (mínimo <?= Portal::MIN_PASSWORD ?> caracteres)<input type="password" name="senha" minlength="<?= Portal::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
          <label>Repita a nova senha<input type="password" name="senha2" minlength="<?= Portal::MIN_PASSWORD ?>" autocomplete="new-password" required></label>
          <button class="btn primary full">Trocar senha</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
<footer><?= $e(implode(' · ', array_filter([$company, $co['company_phone'], $co['company_email']]))) ?></footer>
</main>
<?php endif; ?>
<script>
/* ---- Mensagens de campo inválido no estilo do sistema (no lugar do balão do navegador) ---- */
(function () {
  const msgOf = (el) => {
    const v = el.validity;
    if (v.valueMissing) return el.type === 'checkbox' ? 'Marque esta opção.' : el.tagName === 'SELECT' ? 'Escolha uma opção.' : 'Preencha este campo.';
    if (v.typeMismatch) return el.type === 'email' ? 'Digite um e-mail válido (ex.: nome@empresa.com).' : 'Valor inválido.';
    if (v.tooShort) return `Use pelo menos ${el.minLength} caracteres (agora tem ${el.value.length}).`;
    if (v.tooLong) return `Use no máximo ${el.maxLength} caracteres.`;
    if (v.rangeUnderflow) return `O valor mínimo é ${el.min}.`;
    if (v.rangeOverflow) return `O valor máximo é ${el.max}.`;
    if (v.stepMismatch) return 'Valor fora do intervalo permitido.';
    if (v.patternMismatch) return el.title || 'Formato inválido.';
    if (v.badInput) return 'Valor inválido.';
    return el.validationMessage || 'Valor inválido.';
  };
  let seq = 0, focusLock = null;
  const clear = (el) => {
    if (!el || !el.classList || !el.classList.contains('is-invalid')) return;
    el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid');
    const id = el.getAttribute('data-err'); if (id) { document.getElementById(id)?.remove(); el.removeAttribute('data-err'); el.removeAttribute('aria-describedby'); }
  };
  document.addEventListener('invalid', (e) => {
    const el = e.target; if (!(el instanceof HTMLElement) || !el.matches('input, select, textarea')) return;
    e.preventDefault();
    clear(el);
    const id = 'ferr' + (++seq), m = document.createElement('span');
    m.className = 'field-err'; m.id = id; m.setAttribute('role', 'alert'); m.textContent = msgOf(el);
    el.classList.add('is-invalid'); el.setAttribute('aria-invalid', 'true'); el.setAttribute('data-err', id); el.setAttribute('aria-describedby', id);
    (el.type === 'checkbox' || el.type === 'radio' ? (el.closest('label') || el) : el).insertAdjacentElement('afterend', m);
    // Foca só o primeiro campo inválido de cada envio
    if (focusLock !== (el.form || document)) { focusLock = el.form || document; el.focus({ preventScroll: false }); setTimeout(() => (focusLock = null), 0); }
  }, true);
  const fix = (e) => { if (e.target.classList?.contains('is-invalid') && e.target.checkValidity?.()) clear(e.target); };
  document.addEventListener('input', fix, true);
  document.addEventListener('change', fix, true);
})();
if (document.getElementById('theme')) document.getElementById('theme').onclick = function () {
  var dark = document.documentElement.getAttribute('data-theme') !== 'dark';
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  try { localStorage.setItem('portalTheme', dark ? 'dark' : 'light'); } catch (e) {}
};
</script>
</body>
</html>
