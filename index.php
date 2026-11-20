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
<meta name="theme-color" content="#b02a37">
<title>CiclosERP</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23b02a37'/><path d='M8 12l8-4 8 4v9l-8 4-8-4z' fill='none' stroke='white' stroke-width='2' stroke-linejoin='round'/><path d='M8 12l8 4 8-4M16 16v9' fill='none' stroke='white' stroke-width='2'/></svg>">
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
<style id="accent-css"></style>
<script>(function(){try{var c=localStorage.getItem('accentCss');if(c)document.getElementById('accent-css').textContent=c;}catch(e){}})();</script>
<script>(function(){try{var l=document.querySelector('link[rel=icon]');if(l){l.setAttribute('data-default',l.getAttribute('href'));var i=localStorage.getItem('brandIcon');if(i)l.setAttribute('href',i);}var t=localStorage.getItem('brandTitle');if(t)document.title=t;}catch(e){}})();</script>
</head>
<body>
<div id="app"><div class="boot">Carregando…</div></div>
<div id="modal-root"></div>
<div id="toasts"></div>
<script src="assets/jsQR.js?v=<?= $v ?>" defer></script>
<script src="assets/app.js?v=<?= $v ?>" defer></script>
</body>
</html>
