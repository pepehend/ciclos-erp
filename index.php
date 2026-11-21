<?php
require __DIR__ . '/app/bootstrap.php';
if (!app_config()) { header('Location: install.php'); exit; }
$v = APP_VERSION;
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>(function(){try{var t=localStorage.getItem('theme')||'auto';var d=t==='dark'||(t==='auto'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');}catch(e){}})();</script>
<meta name="theme-color" content="#0B2A58">
<title>CiclosERP</title>
<link rel="icon" type="image/svg+xml" href="assets/brand/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/brand/favicon-32.png">
<link rel="alternate icon" href="assets/brand/favicon.ico">
<link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png">
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
<style id="accent-css"></style>
<script>(function(){try{var c=localStorage.getItem('accentCss');if(c)document.getElementById('accent-css').textContent=c;}catch(e){}})();</script>
</head>
<body>
<div id="app"><div class="boot">Carregando…</div></div>
<div id="modal-root"></div>
<div id="toasts"></div>
<script src="assets/jsQR.js?v=<?= $v ?>" defer></script>
<script src="assets/app.js?v=<?= $v ?>" defer></script>
</body>
</html>
