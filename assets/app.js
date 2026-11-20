/* Sistema de Gestão de Serviços — interface (JavaScript puro, sem dependências) */
'use strict';

// ============ Utilidades ============
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const money = (v) => BRL.format(Number(v) || 0);
const cost = (v) => { const n = Number(v) || 0; return Math.abs(n) > 0 && Math.abs(n) < 0.1 ? 'R$ ' + n.toLocaleString('pt-BR', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) : money(n); };
const qty = (v, d = 3) => (Number(v) || 0).toLocaleString('pt-BR', { maximumFractionDigits: d });
const pct = (v) => (Number(v) || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
const num = (v) => { if (typeof v === 'number') return v; let s = String(v ?? '').trim().replace(/[^\d,.\-]/g, ''); if (/^-?\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, ''); if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); const n = parseFloat(s); return isNaN(n) ? 0 : n; };
const today = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const shiftDate = (iso, days) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };
const dt = (s) => { if (!s) return ''; const [d, t] = String(s).split(' '); const [y, m, dd] = d.split('-'); return `${dd}/${m}/${y}` + (t ? ' ' + t.slice(0, 5) : ''); };
const dOnly = (s) => dt(s).slice(0, 10);
const debounce = (fn, ms = 250) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const docFmt = (d) => { d = String(d || '').replace(/\D/g, ''); return d.length === 14 ? d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5') : d.length === 11 ? d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4') : d; };
const cepFmt = (c) => { c = String(c || '').replace(/\D/g, ''); return c.length === 8 ? c.slice(0, 5) + '-' + c.slice(5) : c; };
const validCnpj = (c) => { c = String(c || '').replace(/\D/g, ''); if (c.length !== 14 || /^(\d)\1+$/.test(c)) return false; const calc = (n) => { let s = 0, w = n - 7; for (let i = 0; i < n; i++) { s += +c[i] * w--; if (w < 2) w = 9; } const r = s % 11; return r < 2 ? 0 : 11 - r; }; return calc(12) === +c[12] && calc(13) === +c[13]; };
const validCpf = (c) => { c = String(c || '').replace(/\D/g, ''); if (c.length !== 11 || /^(\d)\1+$/.test(c)) return false; for (const n of [9, 10]) { let s = 0; for (let i = 0; i < n; i++) s += +c[i] * (n + 1 - i); if ((s * 10) % 11 % 10 !== +c[n]) return false; } return true; };
const custName = (c) => c.trade_name || c.name;
const cnpjFmt = (c) => { c = String(c || '').replace(/\D/g, ''); return c.length === 14 ? c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5') : c; };

const ICONS = {
  home: '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  box: '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
  swap: '<path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
  receipt: '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
  cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h11.5l2-8H6.2"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c.8-3.5 3.6-5.5 7-5.5s6.2 2 7 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18 14.6c2 .7 3.4 2.6 4 5.4"/>',
  quote: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M12.5 11.5c-.4-.6-1.1-1-2-1-1.2 0-2 .6-2 1.5 0 2 4.2 1.1 4.2 3.2 0 .9-.9 1.6-2.2 1.6-.9 0-1.7-.4-2.1-1M10.4 9.5v1M10.4 16.8v1"/>',
  palette: '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.9 1.8-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4c0-4.4-4-8-9-8z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="14.5" cy="7" r="1"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.6 14H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9.7 3.6V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1.3z"/>',
  tool: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3.5 17.3a1.7 1.7 0 0 0 2.4 2.4l5.8-5.8a4 4 0 0 0 5.2-5.4l-2.5 2.5-2.1-.4-.4-2.1z"/>',
  truck: '<path d="M2 6h11v10H2zM13 9h4l3 3.5V16h-7z"/><circle cx="6" cy="17.5" r="1.8"/><circle cx="16.5" cy="17.5" r="1.8"/>',
  lock: '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
  chevron: '<path d="M9 6l6 6-6 6"/>',
  monitor: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
  minus: '<path d="M5 12h14"/>',
  check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  minusc: '<circle cx="12" cy="12" r="9" fill="currentColor" stroke="none"/><path d="M7.5 12h9" stroke="#fff" stroke-width="2.4"/>',
  image: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M20 16l-5-5-8.5 8.5"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  login: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
  moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
  send: '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
  copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
  id: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.5 16c.6-1.6 2-2.4 3.5-2.4s2.9.8 3.5 2.4M15 10h3M15 13h3"/>',
  search: '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4.2-6 8-6s7 2 8 6"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  x: '<path d="M6 6l12 12M18 6L6 18"/>',
  filter: '<path d="M3.5 5h17l-6.5 8v5.5l-4 2V13z"/>',
  warn: '<path d="M12 3.5 2.5 20h19z" fill="currentColor" stroke="currentColor" stroke-linejoin="round"/><path d="M12 9.5v5" stroke="#fff" stroke-width="2.4"/><circle cx="12" cy="17.3" r="1.3" fill="#fff" stroke="none"/>',
  swap: '<path d="M7 7h12l-3-3M17 17H5l3 3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  camera: '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
  upload: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  pencil: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
  link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
  undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
  download: '<path d="M12 4v11M7 10l5 5 5-5M4 20h16"/>',
  database: '<ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v13c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-13M4.5 12c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
  whats: '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7L4 20z"/><path d="M9 9.5c.3 1.7 1.8 3.7 4 4.5l1-1.2 2 .8c-.2 1-1 1.7-2 1.7-3.2 0-6.5-3.3-6.5-6.5 0-1 .7-1.8 1.7-2l.8 2L9 9.5z"/>',
  wallet: '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><rect x="4" y="8" width="17" height="11" rx="2"/><path d="M16.5 13.5h1"/>',
  flow: '<path d="M3 17l5-5 4 4 8-9"/><path d="M15 7h5v5"/>',
  order: '<path d="M8 4h8l1 2h2v15H5V6h2z"/><path d="M8 4v3h8V4M9 11h6M9 15h4"/>',
  dollar: '<path d="M12 3v18"/><path d="M16.5 7.5c-.8-1.3-2.4-2-4.3-2-2.6 0-4.2 1.3-4.2 3.1 0 4.2 8.6 2.4 8.6 6.6 0 1.9-1.8 3.3-4.5 3.3-2 0-3.7-.8-4.5-2.2"/>',
  tag: '<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.4 1.4 0 0 1 0 2l-6.6 6.6a1.4 1.4 0 0 1-2 0z"/><circle cx="8" cy="8" r="1.4"/>',
  calendar: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
};
const icon = (n) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${ICONS[n] || ''}</svg>`;

// ============ Tema (claro / escuro / automático) ============
const THEMES = { light: 'Claro', dark: 'Escuro', auto: 'Automático' };
/** Ordem do botão de tema: claro → escuro → automático → claro. */
const THEME_NEXT = { light: 'dark', dark: 'auto', auto: 'light' };
const getTheme = () => { try { return localStorage.getItem('theme') || 'auto'; } catch (_) { return 'auto'; } };
const systemDark = () => !!(window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
// ---- Cor de destaque (Minha conta → Aparência; cada usuário escolhe a sua) ----
const ACCENT_DEFAULT = '#b02a37';
const ACCENT_PRESETS = [['#b02a37', 'Vermelho (padrão)'], ['#1d4ed8', 'Azul'], ['#0369a1', 'Azul petróleo'], ['#0f766e', 'Verde-água'],
  ['#15803d', 'Verde'], ['#6d28d9', 'Roxo'], ['#be185d', 'Rosa'], ['#c2410c', 'Laranja'], ['#374151', 'Grafite']];
/** Mesma conta de Settings::accentCss() no servidor: variações da cor para o tema claro e o escuro. */
function accentCss(hex) {
  hex = String(hex || '').toLowerCase();
  if (!/^#[0-9a-f]{6}$/.test(hex) || hex === ACCENT_DEFAULT) return '';
  const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
  const toHex = (c) => '#' + c.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
  const mix = (a, b, t) => { const x = rgb(a), y = rgb(b); return toHex(x.map((v, i) => v * t + y[i] * (1 - t))); };
  const ink = (h) => { const l = rgb(h).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2] > 0.4 ? '#212529' : '#ffffff'; };
  const dark = mix(hex, '#ffffff', 0.78);
  return `:root{--accent:${hex};--accent-hover:${mix(hex, '#000000', 0.78)};--accent-ink:${ink(hex)};--accent-soft:${mix(hex, '#ffffff', 0.18)}}`
    + `html[data-theme="dark"]{--accent:${dark};--accent-hover:${mix(hex, '#ffffff', 0.62)};--accent-ink:${ink(dark)};--accent-soft:${mix(hex, '#1c1c1f', 0.28)}}`;
}
/** Aplica a cor do usuário e guarda o CSS no aparelho (o index.php aplica antes de desenhar, sem piscar). */
function applyAccent(hex) {
  let el = document.getElementById('accent-css');
  if (!el) { el = document.createElement('style'); el.id = 'accent-css'; document.head.appendChild(el); }
  const css = accentCss(hex);
  el.textContent = css;
  try { if (css) localStorage.setItem('accentCss', css); else localStorage.removeItem('accentCss'); } catch (_) {}
  applyTheme();
}
const accentNow = () => { const h = String(state.me?.accent_color || '').toLowerCase(); return /^#[0-9a-f]{6}$/.test(h) ? h : ACCENT_DEFAULT; };
function applyTheme(t = getTheme()) {
  const dark = t === 'dark' || (t === 'auto' && systemDark());
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  const meta = document.querySelector('meta[name=theme-color]'); if (meta) meta.content = dark ? '#1c1c1f' : (state.me ? accentNow() : ACCENT_DEFAULT);
  // O botão mostra o tema escolhido: sol (claro), lua (escuro) ou o do aparelho com um "A" (automático)
  const b = document.getElementById('themebtn');
  if (b) {
    b.innerHTML = t === 'auto' ? `<span class="theme-auto">${icon(dark ? 'moon' : 'sun')}<b>A</b></span>` : icon(t === 'dark' ? 'moon' : 'sun');
    const next = THEME_NEXT[t] || 'light';
    b.title = `Tema: ${THEMES[t] || THEMES.auto}${t === 'auto' ? ` (agora ${dark ? 'escuro' : 'claro'}, como o aparelho)` : ''}. Clique para ${THEMES[next].toLowerCase()}.`;
    b.setAttribute('aria-label', b.title);
  }
}
function setTheme(t) { try { localStorage.setItem('theme', t); } catch (_) {} applyTheme(t); }
if (window.matchMedia) matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => { if (getTheme() === 'auto') applyTheme(); });

// ============ API ============
const state = { me: null, csrf: '', meta: null, products: null };

async function api(route, { method = 'GET', body, params, form } = {}) {
  let url = 'api.php?r=' + encodeURIComponent(route);
  if (params) for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
  const opt = { method, headers: { 'X-CSRF-Token': state.csrf }, credentials: 'same-origin' };
  if (form) { opt.method = 'POST'; opt.body = form; }
  else if (body !== undefined) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(url, opt); } catch (e) { throw new Error('Sem conexão com o servidor'); }
  let data = null;
  try { data = await res.json(); } catch (e) { /* resposta não-JSON */ }
  if (!res.ok) {
    if (res.status === 401 && route !== 'login') { state.me = null; renderLogin(); }
    if (res.status === 419) { await loadMe(); }
    if (data && data.install) location.href = 'install.php';
    throw new Error((data && data.error) || 'Erro ' + res.status);
  }
  return data;
}

/** Foto de perfil (ou as iniciais do nome, quando não há foto). size: sm | md | lg */
function avatarHtml(u, size = 'md') {
  const src = u && /^data:image\/(png|jpe?g|webp);base64,/.test(u.avatar || '') ? u.avatar : '';
  const ini = String(u?.name || '?').trim().split(/\s+/).filter(Boolean).map((w) => w[0]).filter((c, i, a) => i === 0 || i === a.length - 1).join('').toUpperCase() || '?';
  return `<span class="avatar ${size}" aria-hidden="true">${src ? `<img src="${esc(src)}" alt="">` : esc(ini.slice(0, 2))}</span>`;
}
/** Escolhe uma imagem no aparelho e devolve a foto recortada em quadrado de 256 px (JPEG). */
function pickAvatar() {
  return new Promise((resolve, reject) => {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/png,image/jpeg,image/webp';
    inp.onchange = () => {
      const file = inp.files[0]; if (!file) return resolve(null);
      if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return reject(new Error('Envie a foto em PNG, JPG ou WebP'));
      if (file.size > 20 * 1024 * 1024) return reject(new Error('Imagem grande demais (máx. 20 MB)'));
      const img = new Image(), url = URL.createObjectURL(file);
      img.onload = () => {
        const S = 256, c = document.createElement('canvas'); c.width = c.height = S;
        const side = Math.min(img.width, img.height), sx = (img.width - side) / 2, sy = (img.height - side) / 2;
        const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, S, S);
        ctx.drawImage(img, sx, sy, side, side, 0, 0, S, S);
        URL.revokeObjectURL(url); resolve(c.toDataURL('image/jpeg', 0.85));
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Não consegui abrir esta imagem')); };
      img.src = url;
    };
    inp.click();
  });
}

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

async function loadMe() {
  const d = await api('me');
  state.me = d.user; state.csrf = d.csrf; state.version = d.version; if (d.brand) state.brand = d.brand;
  applyAccent(state.me?.accent_color);
  return d;
}

async function products(force = false) {
  if (!state.products || force) state.products = await api('products', { params: { active: '1', all: '1' } });
  return state.products;
}
const invalidateProducts = () => { state.products = null; };

// ============ UI helpers ============
function toast(msg, type = '') {
  const el = document.createElement('div');
  el.className = 'toast ' + type; el.textContent = msg;
  $('#toasts').appendChild(el);
  while ($('#toasts').children.length > 3) $('#toasts').firstChild.remove();
  setTimeout(() => el.remove(), type === 'err' ? 6000 : 3500);
}
const fail = (e) => toast(e.message || String(e), 'err');

function modal({ title, body, foot = '', wide = false, onOpen }) {
  const root = $('#modal-root');
  const bg = document.createElement('div');
  bg.className = 'modal-bg';
  bg.innerHTML = `<div class="modal ${wide ? 'wide' : ''}" role="dialog" aria-modal="true">
    <div class="modal-head"><h2>${esc(title)}</h2><button class="icon-btn" data-close aria-label="Fechar">${icon('x')}</button></div>
    <div class="modal-body">${body}</div>${foot ? `<div class="modal-foot">${foot}</div>` : ''}</div>`;
  const close = () => { bg.remove(); document.removeEventListener('keydown', onKey); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  bg.addEventListener('mousedown', (e) => { if (e.target === bg) close(); });
  bg.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) close(); });
  document.addEventListener('keydown', onKey);
  root.appendChild(bg);
  const m = { el: bg, close };
  if (onOpen) onOpen(m);
  const first = $('input:not([type=hidden]),select,textarea', bg);
  if (first) setTimeout(() => first.focus(), 30);
  return m;
}

function confirmBox(text, { ok = 'Confirmar', danger = false } = {}) {
  return new Promise((resolve) => {
    const m = modal({
      title: 'Confirmação', body: `<p>${esc(text)}</p>`,
      foot: `<button class="btn" data-close>Voltar</button><button class="btn ${danger ? 'danger solid' : 'primary'}" data-ok>${esc(ok)}</button>`,
    });
    let done = false;
    $('[data-ok]', m.el).onclick = () => { done = true; m.close(); resolve(true); };
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); if (!done) resolve(false); } });
    obs.observe($('#modal-root'), { childList: true });
  });
}

/** Confirmação para ações em massa: a pessoa digita a palavra (ex.: "alterar") para liberar o botão. */
function typeConfirm({ text, word = 'alterar', ok = 'Alterar', danger = false }) {
  return new Promise((resolve) => {
    const root = $('#modal-root');
    const bg = document.createElement('div');
    bg.className = 'modal-bg tc-bg';
    bg.innerHTML = `<div class="modal tc" role="alertdialog" aria-modal="true" aria-labelledby="tc-t">
      <div class="tc-icon">${icon('warn')}</div><h2 id="tc-t">Atenção!</h2><p class="tc-text">${esc(text)}</p>
      <label class="tc-label"><span>Digite <strong>${esc(word)}</strong> para completar a ação</span><input autocomplete="off" spellcheck="false" placeholder="${esc(word)}"></label>
      <div class="tc-foot"><button class="btn" data-no>Cancelar</button><button class="btn ${danger ? 'danger solid' : 'primary'}" data-ok disabled>${esc(ok)}</button></div></div>`;
    root.appendChild(bg);
    const inp = $('input', bg), okb = $('[data-ok]', bg);
    const done = (v) => { bg.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); done(false); } };
    document.addEventListener('keydown', onKey, true);
    inp.oninput = () => { okb.disabled = inp.value.trim().toLowerCase() !== word.toLowerCase(); };
    inp.onkeydown = (e) => { if (e.key === 'Enter' && !okb.disabled) done(true); };
    okb.onclick = () => done(true);
    $('[data-no]', bg).onclick = () => done(false);
    bg.addEventListener('mousedown', (e) => { if (e.target === bg) done(false); });
    setTimeout(() => inp.focus(), 30);
  });
}

/** Baixa um arquivo gerado pela API (PDF, ZIP…) mostrando o erro em português se falhar. */
async function downloadFile(url, fallbackName) {
  try {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) { let msg = 'Erro ' + res.status; try { msg = (await res.json()).error || msg; } catch (_) {} throw new Error(msg); }
    const blob = await res.blob();
    const name = (/filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '') || [])[1] || fallbackName;
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    toast('Download pronto: ' + name);
  } catch (err) { fail(err); }
}

/** Trava o botão enquanto a ação roda. */
async function busy(btn, fn) {
  if (btn.disabled) return;
  const old = btn.innerHTML; btn.disabled = true; btn.textContent = 'Aguarde…';
  try { return await fn(); } catch (e) { fail(e); } finally { btn.disabled = false; btn.innerHTML = old; }
}

function formData(form) {
  const o = {};
  for (const el of form.elements) {
    if (!el.name) continue;
    if (el.type === 'checkbox') o[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; }
    else o[el.name] = el.value;
  }
  return o;
}

/** Código só com letras e números, em maiúsculas (igual a Inventory::codeNorm no servidor). */
const codeNorm = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
/** O produto bate com o código digitado? (SKU, código do fabricante, código de barras ou referência cruzada) */
function productCodeMatch(p, typed) {
  const c = codeNorm(typed);
  if (!c) return false;
  if (p.barcode && String(p.barcode) === String(typed).trim()) return true;
  return [p.sku, p.mfr_code].some((x) => codeNorm(x).includes(c)) || String(p.refs_norm || '').split(' ').some((x) => x && x.includes(c));
}

/** Campo de busca de produto com lista suspensa. */
function productPicker(input, { filter = () => true, onPick, list } = {}) {
  const wrap = document.createElement('div');
  wrap.className = 'combo';
  input.parentNode.insertBefore(wrap, input); wrap.appendChild(input);
  input.autocomplete = 'off';
  const box = document.createElement('div'); box.className = 'combo-list'; box.hidden = true; wrap.appendChild(box);
  let items = [], hl = 0;
  const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const show = async () => {
    const all = (list ? list() : await products()).filter(filter);
    const t = norm(input.value);
    items = all.filter((p) => !t || norm(p.name).includes(t) || norm(p.brand).includes(t) || productCodeMatch(p, input.value)).slice(0, 30);
    hl = 0;
    box.innerHTML = items.length ? items.map((p, i) => `<div data-i="${i}" class="${i === 0 ? 'hl' : ''}"><span>${esc(p.name)}${p.brand || p.mfr_code ? ` <span class="muted small">${esc([p.brand, p.mfr_code].filter(Boolean).join(' '))}</span>` : ''}</span><span class="meta">${qty(p.stock)} ${esc(p.unit)}${p.type === 'produto' && +p.sale_price ? ' · ' + money(p.sale_price) : ''}</span></div>`).join('')
      : '<div class="muted">Nenhum produto encontrado</div>';
    box.hidden = false;
  };
  const pick = (i) => { const p = items[i]; if (!p) return; input.value = p.name; input.dataset.id = p.id; box.hidden = true; onPick && onPick(p); };
  input.addEventListener('focus', show);
  input.addEventListener('input', () => { delete input.dataset.id; show(); });
  input.addEventListener('keydown', (e) => {
    if (box.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); hl = Math.max(0, Math.min(items.length - 1, hl + (e.key === 'ArrowDown' ? 1 : -1))); $$('[data-i]', box).forEach((d, i) => d.classList.toggle('hl', i === hl)); }
    else if (e.key === 'Enter') { e.preventDefault(); pick(hl); }
    else if (e.key === 'Escape') box.hidden = true;
  });
  box.addEventListener('mousedown', (e) => { const d = e.target.closest('[data-i]'); if (d) { e.preventDefault(); pick(+d.dataset.i); } });
  input.addEventListener('blur', () => setTimeout(() => (box.hidden = true), 150));
  return { set(p) { input.value = p ? p.name : ''; if (p) input.dataset.id = p.id; else delete input.dataset.id; } };
}

// Gráficos são desenhados na largura real do quadro (sem esticar letras) e redesenhados quando a tela muda
const CHARTS = new Map(); let chartSeq = 0;
const chartRO = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((e) => {
  const el = e.target, draw = CHARTS.get(el.dataset.chart);
  if (!draw || !document.body.contains(el)) { chartRO.unobserve(el); CHARTS.delete(el.dataset.chart); return; }
  const w = Math.round(el.clientWidth);
  if (w > 0 && w !== +el.dataset.w) { el.dataset.w = w; el.innerHTML = draw(w); }
})) : null;
function chartBox(draw, height) {
  const id = 'c' + (++chartSeq);
  CHARTS.set(id, draw);
  requestAnimationFrame(() => $$('.chart-box:not([data-obs])').forEach((el) => {
    el.dataset.obs = '1';
    if (chartRO) chartRO.observe(el); else el.innerHTML = (CHARTS.get(el.dataset.chart) || (() => ''))(el.clientWidth || 720);
  }));
  return `<div class="chart-box" data-chart="${id}" style="height:${height}px"></div>`;
}

function barChart(days, { height = 200 } = {}) {
  if (!days || !days.length) return '<p class="muted small">Sem dados no período.</p>';
  return chartBox((w) => barSvg(days, height, w), height) + `
    <div class="legend"><span><i style="background:var(--accent)"></i>Faturamento</span><span><i style="background:var(--ok);opacity:.55"></i>Lucro bruto</span></div>`;
}
function barSvg(days, height, W) {
  const H = height, pad = { l: 52, r: 6, t: 12, b: 24 };
  const max = Math.max(1, ...days.map((d) => Math.max(d.revenue, d.profit)));
  const nice = (() => { const p = Math.pow(10, Math.floor(Math.log10(max))); const f = max / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; })();
  const iw = W - pad.l - pad.r, ih = H - pad.t - pad.b, bw = iw / days.length;
  const y = (v) => pad.t + ih - (Math.max(0, v) / nice) * ih;
  let g = '';
  for (let i = 0; i <= 4; i++) { const v = nice * i / 4, yy = y(v); g += `<line class="axis" x1="${pad.l}" x2="${W - pad.r}" y1="${yy}" y2="${yy}"/><text x="${pad.l - 6}" y="${yy + 3}" text-anchor="end">${v >= 1000 ? (v / 1000).toLocaleString('pt-BR') + 'k' : v.toLocaleString('pt-BR')}</text>`; }
  // Um rótulo por dia quando cabe (≥ 34 px por dia); senão, pula de forma regular
  const step = (W - pad.l - pad.r) / days.length >= 34 ? 1 : Math.ceil(days.length / Math.max(4, Math.floor(W / 46)));
  const todayIso = today(), todayIdx = days.findIndex((d) => d.date === todayIso);
  days.forEach((d, i) => {
    const w = Math.max(1, Math.min(bw * 0.38, 26)), x = pad.l + i * bw + bw / 2 - w - bw * 0.1;
    g += `<rect class="bar" x="${x + bw * 0.1}" y="${y(d.revenue)}" width="${w}" height="${pad.t + ih - y(d.revenue)}"><title>${dOnly(d.date)} — faturamento ${money(d.revenue)}</title></rect>`;
    g += `<rect class="bar2" x="${x + bw * 0.1 + w}" y="${y(d.profit)}" width="${w}" height="${pad.t + ih - y(d.profit)}"><title>${dOnly(d.date)} — lucro ${money(d.profit)}</title></rect>`;
    if (i === todayIdx || (i % step === 0 && (todayIdx < 0 || step === 1 || Math.abs(i - todayIdx) * 2 >= step))) g += `<text x="${pad.l + i * bw + bw / 2}" y="${H - 6}" text-anchor="middle"${d.date === todayIso ? ' class="today"' : ''}>${d.date.slice(8, 10)}/${d.date.slice(5, 7)}</text>`;
  });
  return `<svg class="chart" viewBox="0 0 ${W} ${H}" style="height:${H}px" role="img" aria-label="Faturamento e lucro por dia">${g}</svg>`;
}

function pager(total, page, limit, onGo) {
  const pages = Math.max(1, Math.ceil(total / limit));
  const el = document.createElement('div'); el.className = 'pager';
  el.innerHTML = `<span>${total} registro${total === 1 ? '' : 's'}</span><span class="actions">
    <button class="btn sm" ${page <= 1 ? 'disabled' : ''} data-p="${page - 1}">Anterior</button>
    <span>Página ${page} de ${pages}</span>
    <button class="btn sm" ${page >= pages ? 'disabled' : ''} data-p="${page + 1}">Próxima</button></span>`;
  el.addEventListener('click', (e) => { const b = e.target.closest('[data-p]'); if (b && !b.disabled) onGo(+b.dataset.p); });
  return el;
}

const movLabel = (t) => (state.meta?.movement_labels || {})[t] || t;
const payLabel = (m) => (state.meta?.payment_labels || state.meta?.payment_methods || {})[m] || m;
/** Formas de pagamento ativas (na ordem cadastrada), mais as já escolhidas no registro mesmo que desativadas depois. */
const pmEntries = (selected = []) => {
  const act = Object.entries(state.meta?.payment_methods || {});
  const sel = (Array.isArray(selected) ? selected : [selected]).filter((m) => m && !act.some(([k]) => k === m));
  return [...act, ...sel.map((m) => [m, payLabel(m) + ' (desativada)'])];
};
/** Regra de parcelamento das formas escolhidas (vale a mais generosa). */
const instRules = (codes) => {
  const r = state.meta?.payment_rules || {}; let max = 1, free = false;
  for (const c of codes) if (r[c]) { max = Math.max(max, r[c].max); if (r[c].mode === 'livre' && r[c].max > 1) free = true; }
  return { max, free };
};
/**
 * Parcelas: escolhe a quantidade (até o máximo da forma) e mostra a tabela Parcela / Valor / Vencimento.
 * Os valores podem ser ajustados; com intervalo livre, também o intervalo e cada vencimento.
 * Some quando a forma não parcela. get() devolve { installments, installment_days, installment_plan }.
 */
function installmentBox(el, { codes = [], total = 0, n = 1, days = 30, plan = null, disabled = false, start = null, entry = false } = {}) {
  const uid = Math.random().toString(36).slice(2, 8);
  const addDays = (iso, k) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + k); return d.toISOString().slice(0, 10); };
  const cents = (x) => Math.round(x * 100) / 100;
  let saved = []; try { saved = typeof plan === 'string' ? JSON.parse(plan || '[]') || [] : plan || []; } catch (_) { saved = []; }
  // entryAmt: valor da entrada digitado no orçamento/venda (vazio = entrada igual às parcelas)
  const st = { codes, total: cents(total), n: +n || 1, days: +days || 30, rows: [], start: start || today(), entry: !!+entry, entryAmt: null, entryMode: 'valor', entryPct: null };
  const count = () => st.n + (st.entry ? 1 : 0);
  const active = () => st.n > 1 || st.entry;
  const entryOk = () => st.entry && st.entryAmt > 0 && st.entryAmt < st.total;
  // Divide o total: com valor de entrada, a entrada leva esse valor e o resto é dividido igual; sem ele, todos iguais
  const split = () => {
    const rows = st.rows.length; if (!rows) return;
    let from = 0, rest = st.total;
    if (entryOk()) { st.rows[0].amount = st.entryAmt; rest = cents(st.total - st.entryAmt); from = 1; }
    const k = rows - from, each = Math.floor(rest / k * 100) / 100;
    for (let i = from; i < rows; i++) st.rows[i].amount = i === rows - 1 ? cents(rest - each * (k - 1)) : each;
  };
  const dates = () => { const { free } = instRules(st.codes); const d = free ? st.days : 30; st.rows.forEach((r, i) => { r.due_date = addDays(st.start, d * (st.entry ? i : i + 1)); }); };
  const rebuild = () => { st.rows = active() ? Array.from({ length: count() }, () => ({ amount: 0, due_date: '' })) : []; split(); dates(); };
  if (active() && saved.length === count()) {
    st.rows = saved.map((r) => ({ amount: +r.amount, due_date: r.due_date }));
    // Entrada diferente das parcelas: mostra o valor no campo
    if (st.entry && st.rows.length > 1 && Math.abs(st.rows[0].amount - st.rows[1].amount) >= 0.01) st.entryAmt = st.rows[0].amount;
  } else rebuild();
  const fmt2 = (x) => (Number(x) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtP = (x) => (Math.round((Number(x) || 0) * 100) / 100).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  // Entrada em R$ ou em % do total
  const pctMode = () => st.entryMode === 'percent';
  const entryField = () => (pctMode() ? (st.entryPct ? fmtP(st.entryPct) : '') : (st.entryAmt ? fmt2(st.entryAmt) : ''));
  const entryHolder = () => (pctMode() ? fmtP(100 / count()) : fmt2(cents(st.total / count())));
  const entryCalc = () => {
    if (!st.entryAmt) return '';
    return pctMode() ? `= ${money(st.entryAmt)}` : `= ${fmtP(st.entryAmt / st.total * 100)}% do total`;
  };
  /** Lê o que foi digitado no campo da entrada e recalcula o valor. */
  const readEntry = (txt) => {
    const v = num(txt);
    if (pctMode()) { st.entryPct = v > 0 && v < 100 ? v : null; st.entryAmt = st.entryPct ? cents(st.total * st.entryPct / 100) : null; }
    else { const a = cents(v); st.entryAmt = a > 0 && a < st.total ? a : null; st.entryPct = null; }
  };
  const entryBad = () => { const ev = $('input[data-k=entryval]', el); return !!(st.entry && ev && ev.value.trim() && !entryOk()); };
  const paintEntry = () => {
    $$('input[data-k=amount]', el).forEach((x, i) => (x.value = fmt2(st.rows[i].amount)));
    const c = $('.entry-calc', el); if (c) c.textContent = entryCalc();
    info();
  };
  const paint = () => {
    const { max, free } = instRules(st.codes);
    if (max <= 1) { st.n = 1; st.entry = false; st.rows = []; el.innerHTML = ''; return; }
    const maxN = max - (st.entry ? 1 : 0);
    if (st.n > maxN) { st.n = Math.max(1, maxN); rebuild(); }
    if (!active()) { el.innerHTML = ''; return; }
    // Parcelas, entrada e intervalo vêm da condição de pagamento; aqui só confere e ajusta valores/vencimentos
    const equal = cents(st.total / count());
    el.innerHTML = `<div class="inst-sum"><span class="qlabel-f" style="margin:0">Parcelamento</span> <strong>${esc(instText(st.n, free ? st.days : 30, st.entry) || st.n + 'x')}</strong>${free ? '' : ' <span class="help">(esta forma de pagamento é sempre de 30 em 30 dias)</span>'}</div>
      ${st.entry ? `<div class="inst-entry"><label for="entryval-${uid}">Valor da entrada</label>
        <div class="inline" style="flex-wrap:nowrap;gap:10px"><span class="entry-group"><input id="entryval-${uid}" data-k="entryval" inputmode="decimal" value="${entryField()}" placeholder="${entryHolder()}" ${disabled ? 'disabled' : ''}>
          <select data-k="entrymode" aria-label="Entrada em reais ou percentual" ${disabled ? 'disabled' : ''}><option value="valor" ${pctMode() ? '' : 'selected'}>R$</option><option value="percent" ${pctMode() ? 'selected' : ''}>%</option></select></span>
          <span class="entry-calc muted small">${entryCalc()}</span></div>
        <span class="help">Deixe em branco para a entrada ficar igual às parcelas. O restante é dividido igualmente em ${st.n} parcela${st.n > 1 ? 's' : ''}.</span></div>` : ''}
      ${active() ? `<div class="table-wrap"><table class="inst-table"><thead><tr><th>Parcela</th><th class="num">Valor (R$)</th><th>Vencimento</th></tr></thead><tbody>
        ${st.rows.map((r, i) => `<tr data-r="${i}"><td><strong>${st.entry && i === 0 ? 'Entrada' : `${st.entry ? i : i + 1}/${st.n}`}</strong></td>
          <td class="num"><input data-k="amount" inputmode="decimal" value="${fmt2(r.amount)}" ${disabled ? 'disabled' : ''}></td>
          <td><input data-k="due" type="date" value="${esc(r.due_date)}" ${disabled || !free ? 'disabled' : ''} title="${free ? '' : 'Esta forma de pagamento é sempre de 30 em 30 dias'}"></td></tr>`).join('')}
        </tbody></table></div><p class="help inst-info"></p>` : ''}`;
    info();
  };
  const info = () => {
    const box = $('.inst-info', el); if (!box) return;
    const sum = cents(st.rows.reduce((a, r) => a + (+r.amount || 0), 0)), diff = cents(sum - st.total);
    if (entryBad()) { box.innerHTML = `<span class="stock-low">${pctMode() ? 'A entrada precisa ser maior que 0% e menor que 100%.' : `A entrada precisa ser maior que zero e menor que o total (${money(st.total)}).`}</span>`; return; }
    box.innerHTML = Math.abs(diff) >= 0.01
      ? `<span class="stock-low">As parcelas somam ${money(sum)}, mas o total é ${money(st.total)} (diferença ${money(diff)}).</span> <a href="#" data-split>Dividir igualmente</a>`
      : `${st.entry ? 'A entrada vence no dia da venda. ' : ''}Na venda, as parcelas entram em Contas a receber.`;
  };
  el.addEventListener('change', (e) => {
    const k = e.target.dataset.k;
    if (k === 'n') { st.n = +e.target.value; rebuild(); paint(); }
    if (k === 'entry') { st.entry = e.target.checked; st.entryAmt = null; st.entryPct = null; rebuild(); paint(); }
    if (k === 'entrymode') {
      // Troca R$ <-> % mantendo a mesma entrada
      st.entryMode = e.target.value;
      st.entryPct = pctMode() && st.entryAmt ? Math.round(st.entryAmt / st.total * 10000) / 100 : null;
      const ev = $('input[data-k=entryval]', el); ev.value = entryField(); ev.placeholder = entryHolder();
      paintEntry(); ev.focus();
    }
  });
  el.addEventListener('input', (e) => {
    const k = e.target.dataset.k;
    if (k === 'days') { st.days = Math.max(1, Math.min(365, parseInt(e.target.value) || 30)); dates(); $$('input[data-k=due]', el).forEach((x, i) => (x.value = st.rows[i].due_date)); }
    if (k === 'entryval') { readEntry(e.target.value); split(); paintEntry(); return; }
    const tr = e.target.closest('tr[data-r]'); if (!tr) return;
    const r = st.rows[+tr.dataset.r];
    if (k === 'amount') {
      r.amount = cents(num(e.target.value));
      // Mexeu no valor da entrada pela tabela: atualiza o campo
      if (st.entry && +tr.dataset.r === 0) {
        st.entryAmt = r.amount; if (pctMode()) st.entryPct = Math.round(r.amount / st.total * 10000) / 100;
        const ev = $('input[data-k=entryval]', el); if (ev) ev.value = entryField();
        const c = $('.entry-calc', el); if (c) c.textContent = entryCalc();
      }
      info();
    }
    if (k === 'due') r.due_date = e.target.value;
  });
  el.addEventListener('focusout', (e) => {
    if (e.target.dataset.k === 'amount') e.target.value = fmt2(num(e.target.value));
    if (e.target.dataset.k === 'entryval' && st.entryAmt) e.target.value = entryField();
  });
  el.addEventListener('click', (e) => { if (e.target.closest('[data-split]')) { e.preventDefault(); split(); paint(); } });
  paint();
  return {
    update(o) {
      // Só refaz a tabela quando algo mudou de verdade (não perde valores ajustados à mão)
      const changed = (o.codes && o.codes.join() !== st.codes.join())
        || ('n' in o && +o.n !== st.n) || ('days' in o && +o.days !== st.days)
        || ('entry' in o && !!o.entry !== st.entry);
      if (!changed) return; // nada mudou: não redesenha (não tira o foco de quem está digitando)
      Object.assign(st, o);
      if ('entry' in o) { st.entry = !!o.entry; if (!st.entry) { st.entryAmt = null; st.entryPct = null; } }
      rebuild();
      paint();
    },
    setTotal(t) {
      t = cents(t); if (t === st.total) return; st.total = t;
      if (!active()) return;
      // Entrada em %: acompanha o novo total; em R$: continua o mesmo valor (se ainda couber)
      if (st.entry && pctMode() && st.entryPct) st.entryAmt = cents(st.total * st.entryPct / 100);
      split();
      const ev = $('input[data-k=entryval]', el); if (ev) ev.placeholder = entryHolder();
      paintEntry();
    },
    get() {
      const { max, free } = instRules(st.codes);
      if (max <= 1 || !active()) return { installments: 1, installment_days: 30, installment_plan: null, installment_entry: 0 };
      if (entryBad()) throw new Error(pctMode() ? 'O percentual da entrada precisa ser maior que 0% e menor que 100%' : `O valor da entrada precisa ser maior que zero e menor que o total (${money(st.total)})`);
      if (st.rows.some((r) => !(r.amount > 0) || !r.due_date)) throw new Error('Confira as parcelas: todas precisam de valor e vencimento');
      const sum = cents(st.rows.reduce((a, r) => a + r.amount, 0));
      if (Math.abs(sum - st.total) >= 0.01) throw new Error(`As parcelas somam ${money(sum)}, mas o total é ${money(st.total)}`);
      return { installments: st.n, installment_days: free ? st.days : 30, installment_entry: st.entry ? 1 : 0,
        installment_plan: st.rows.map((r, i) => ({ amount: r.amount, due_date: r.due_date, ...(st.entry && i === 0 ? { entry: true } : {}) })) };
    },
  };
}
const instText = (n, days, entry) => (+n > 1 || +entry) ? `${+entry ? 'entrada + ' : ''}${n}x ${+days === 30 ? 'de 30 em 30 dias' : `a cada ${days} dias`}` : '';
const pmOptions = (selected, blank = true) => (blank ? '<option value="">—</option>' : '') + pmEntries(selected).map(([k, l]) => `<option value="${esc(k)}" ${k === selected ? 'selected' : ''}>${esc(l)}</option>`).join('');
const isAdmin = () => state.me && state.me.role === 'admin';

// ============ Máscara de telefone (vale para todo campo de telefone, enquanto digita) ============
const PHONE_SEL = 'input[type=tel], input[name$="phone"], input[id$="phone"], input[data-mask=phone]';
/** Formata os dígitos aos poucos: (31) 9, (31) 9876-5, (31) 3355-6953, (31) 99876-5432, 0800 123 4567. */
function phoneMask(value) {
  const raw = String(value || '');
  if (raw.trim().startsWith('+') && !raw.replace(/\D/g, '').startsWith('55')) return raw.replace(/[^\d+\s()-]/g, ''); // estrangeiro: livre
  let d = raw.replace(/\D/g, '');
  if (raw.trim().startsWith('+') && d.startsWith('55')) d = d.slice(2);
  if (/^0[3589]00/.test(d)) { d = d.slice(0, 11); return [d.slice(0, 4), d.slice(4, 7), d.slice(7)].filter(Boolean).join(' '); }
  if (d.startsWith('0')) d = d.slice(1); // 0 + DDD
  d = d.slice(0, 11);
  if (!d) return '';
  if (d.length <= 2) return `(${d}`;
  const ddd = d.slice(0, 2), n = d.slice(2);
  if (n.length <= 4) return `(${ddd}) ${n}`;
  if (d.length <= 10) return `(${ddd}) ${n.slice(0, 4)}-${n.slice(4)}`;
  return `(${ddd}) ${n.slice(0, 5)}-${n.slice(5)}`;
}
// ============ Máscara de CPF/CNPJ (mesmo comportamento da de telefone) ============
// data-mask="cnpj" | "cpf" fixa o tipo; sem ele, até 11 dígitos é CPF e acima disso CNPJ.
const DOC_SEL = 'input[name="document"], input[name$="_document"], input[name$="cnpj"], input[data-k="cnpj"], input[data-mask=cnpj], input[data-mask=cpf], input[data-mask=doc]';
/** Formata aos poucos: 123.456.789-01 e 12.345.678/0001-90. */
function docMask(value, mode) {
  let d = String(value || '').replace(/\D/g, '');
  const cnpj = mode === 'cnpj' || (mode !== 'cpf' && d.length > 11);
  d = d.slice(0, cnpj ? 14 : 11);
  if (cnpj) {
    return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
  }
  return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2');
}

// Os dois tipos de campo usam o mesmo mecanismo: formata enquanto digita e mantém o cursor no lugar certo
const MASKS = [
  { sel: PHONE_SEL, fmt: (v) => phoneMask(v), free: (el) => el.value.trim().startsWith('+') && !el.value.replace(/\D/g, '').startsWith('55'), im: 'tel', ph: () => '(31) 99999-9999' },
  { sel: DOC_SEL, fmt: (v, el) => docMask(v, el.dataset.mask), free: () => false, im: 'numeric',
    ph: (el) => el.dataset.mask === 'cpf' ? '000.000.000-00' : el.dataset.mask === 'cnpj' || /cnpj|company_document/.test((el.name || '') + (el.dataset.k || '')) ? '00.000.000/0000-00' : 'CPF ou CNPJ' },
];
const maskOf = (el) => (el && el.matches ? MASKS.find((m) => el.matches(m.sel)) : null);
const maskFmtEl = (el, m = maskOf(el)) => { if (!m) return; if (el.value && !m.free(el)) el.value = m.fmt(el.value, el); el.dataset.pd = el.value.replace(/\D/g, ''); };
const phoneFmtEl = (el) => maskFmtEl(el);
document.addEventListener('input', (e) => {
  const el = e.target, m = maskOf(el);
  if (!m) return;
  if (m.free(el)) { el.dataset.pd = el.value.replace(/\D/g, ''); return; }
  const caret = el.selectionStart ?? el.value.length;
  let digitsBefore = el.value.slice(0, caret).replace(/\D/g, '').length;
  let digits = el.value.replace(/\D/g, '');
  // Apagar em cima de um separador (".", "/", "-", "(", ")", " ") apaga o número de antes
  if (e.inputType === 'deleteContentBackward' && digits === (el.dataset.pd || '') && digitsBefore > 0) {
    digits = digits.slice(0, digitsBefore - 1) + digits.slice(digitsBefore); digitsBefore--;
  }
  const out = m.fmt(m === MASKS[0] && el.value.trim().startsWith('+') ? '+' + digits : digits, el);
  el.value = out; el.dataset.pd = out.replace(/\D/g, '');
  // Coloca o cursor depois do mesmo número de dígitos
  let pos = 0, seen = 0;
  while (pos < out.length && seen < digitsBefore) { if (/\d/.test(out[pos])) seen++; pos++; }
  try { el.setSelectionRange(pos, pos); } catch (_) { /* tipo sem seleção */ }
}, true);
document.addEventListener('focusout', (e) => { if (maskOf(e.target)) maskFmtEl(e.target); }, true);
function maskUpgrade(root) {
  if (!root.querySelectorAll) return;
  MASKS.forEach((m) => {
    const list = root.matches(m.sel) ? [root] : [...root.querySelectorAll(m.sel)];
    list.forEach((el) => { el.setAttribute('inputmode', m.im); if (!el.getAttribute('placeholder')) el.setAttribute('placeholder', m.ph(el)); maskFmtEl(el, m); });
  });
}
new MutationObserver((ms) => ms.forEach((mu) => mu.addedNodes.forEach((n) => n.nodeType === 1 && maskUpgrade(n)))).observe(document.documentElement, { childList: true, subtree: true });
// Valores preenchidos pelo sistema (busca por CNPJ etc.) também ganham a máscara
const _phoneDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
Object.defineProperty(HTMLInputElement.prototype, 'value', {
  get() { return _phoneDesc.get.call(this); },
  set(v) {
    if (v && typeof v === 'string' && !this._maskSetting && this.isConnected && document.activeElement !== this) {
      const m = maskOf(this);
      if (m && !(m === MASKS[0] && v.trim().startsWith('+') && !v.replace(/\D/g, '').startsWith('55'))) {
        this._maskSetting = true; try { _phoneDesc.set.call(this, m.fmt(v, this)); } finally { this._maskSetting = false; } return;
      }
    }
    _phoneDesc.set.call(this, v);
  },
  configurable: true,
});

// ============ Filtros das listas (orçamentos e vendas) ============
/**
 * Painel com período de criação, cliente, status, valor mínimo/máximo e responsável.
 * statuses: [[valor, nome], ...]; team: usuários; f: objeto de filtros (from, to, customer, status, min, max, user_id).
 */
function filterPanelHtml({ statuses, team, f, userLabel = 'Responsável', customerHint = 'Nome, fantasia, lead ou CNPJ/CPF' }) {
  return `<div class="adv-filters">
    <label>Criado de <input type="date" data-af="from" value="${esc(f.from || '')}"></label>
    <label>Até <input type="date" data-af="to" value="${esc(f.to || '')}"></label>
    <label class="af-wide">Cliente <input data-af="customer" value="${esc(f.customer || '')}" placeholder="${esc(customerHint)}" autocomplete="off"></label>
    <label>Status <select data-af="status"><option value="">Todos</option>${statuses.map(([k, l]) => `<option value="${esc(k)}" ${f.status === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
    <label>Valor mínimo (R$) <input data-af="min" inputmode="decimal" value="${esc(f.min || '')}" placeholder="0,00"></label>
    <label>Valor máximo (R$) <input data-af="max" inputmode="decimal" value="${esc(f.max || '')}" placeholder="sem limite"></label>
    <label>${esc(userLabel)} <select data-af="user_id"><option value="">Todos</option>${team.map((u) => `<option value="${u.id}" ${String(f.user_id) === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
    <div class="af-actions"><button type="button" class="btn ghost sm" data-af-clear>Limpar filtros</button></div>
  </div>`;
}
const AF_KEYS = ['from', 'to', 'customer', 'status', 'min', 'max', 'user_id'];
/** Quantos filtros estão em uso (para mostrar no botão). */
const filterCount = (f, defaults = {}) => AF_KEYS.filter((k) => String(f[k] ?? '') !== '' && String(f[k]) !== String(defaults[k] ?? '')).length;
/** Liga o painel: atualiza f e chama onChange (texto e valores esperam a digitação parar). */
function bindFilterPanel(root, f, onChange, defaults = {}) {
  const slow = debounce(onChange, 350);
  root.addEventListener('input', (e) => {
    const k = e.target.dataset.af; if (!k) return;
    f[k] = e.target.value.trim();
    (k === 'customer' || k === 'min' || k === 'max') ? slow() : onChange();
  });
  root.addEventListener('change', (e) => { const k = e.target.dataset.af; if (k && (e.target.tagName === 'SELECT' || e.target.type === 'date')) { f[k] = e.target.value; onChange(); } });
  root.addEventListener('click', (e) => {
    if (!e.target.closest('[data-af-clear]')) return;
    AF_KEYS.forEach((k) => { f[k] = defaults[k] ?? ''; const el = $(`[data-af="${k}"]`, root); if (el) el.value = f[k]; });
    onChange();
  });
}
const filterParams = (f) => Object.fromEntries(AF_KEYS.map((k) => [k, f[k] ?? '']));

// ============ Campo com sugestões (substitui o <datalist> nativo, que não segue o visual do sistema) ============
// Qualquer <input list="x"> vira um campo com menu próprio: filtra enquanto digita, setas/Enter/Esc no teclado.
const combo = { input: null, el: null, items: [], idx: -1 };
const fold = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
function comboOptions(input) {
  const dl = document.getElementById(input.dataset.list || '');
  return dl ? [...dl.options].map((o) => o.value).filter(Boolean) : [];
}
function comboClose() { if (combo.el) combo.el.remove(); combo.el = null; combo.input = null; combo.idx = -1; }
function comboOpen(input, filter = true) {
  const all = comboOptions(input);
  const q = fold(input.value.trim());
  // Ao abrir (foco/clique) mostra tudo; ao digitar, filtra
  let items = filter && q ? all.filter((o) => fold(o).includes(q)) : all;
  if (items.length === 1 && fold(items[0]) === q) items = filter ? [] : items;
  // Campo que permite cadastrar a opção digitada (ex.: condição de pagamento)
  const createText = input.dataset.create && q && !all.some((o) => fold(o) === q) ? input.value.trim() : '';
  if (createText) items = [...items, { create: createText }];
  if (!items.length) { comboClose(); return; }
  if (!combo.el) { combo.el = document.createElement('div'); combo.el.className = 'combo-menu'; combo.el.setAttribute('role', 'listbox'); document.body.appendChild(combo.el); }
  combo.input = input; combo.items = items; combo.idx = -1;
  combo.el.innerHTML = items.map((o, i) => typeof o === 'object'
    ? `<div class="combo-opt combo-new" role="option" data-i="${i}">${icon('plus')}Cadastrar “${esc(o.create)}”</div>`
    : `<div class="combo-opt${o === input.value ? ' sel' : ''}" role="option" data-i="${i}">${esc(o)}</div>`).join('');
  comboPlace();
}
function comboPlace() {
  if (!combo.el || !combo.input) return;
  const r = combo.input.getBoundingClientRect();
  const below = window.innerHeight - r.bottom, h = Math.min(280, combo.el.scrollHeight);
  combo.el.style.left = Math.max(8, r.left) + 'px';
  combo.el.style.width = Math.max(r.width, 180) + 'px';
  combo.el.style.top = (below < h + 12 && r.top > below ? r.top - h - 4 : r.bottom + 4) + 'px';
}
function comboPick(i) {
  const input = combo.input; if (!input || combo.items[i] == null) return;
  const it = combo.items[i];
  if (typeof it === 'object') { comboClose(); (COMBO_CREATE[input.dataset.create] || (() => {}))(input, it.create); return; }
  input.value = it;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
  comboClose();
}
/** Cadastro rápido a partir do campo (data-create="..."). */
const COMBO_CREATE = {
  term: async (input, name) => {
    try {
      const r = await api('payment_term_save', { body: { name } });
      state.meta.payment_terms = r.terms; refreshTermLists();
      input.value = name; input.dispatchEvent(new Event('change', { bubbles: true }));
      toast(`Condição “${name}” cadastrada`);
    } catch (err) { fail(err); }
  },
};
/** <datalist> das condições de pagamento cadastradas (atualiza sozinha quando uma nova é criada). */
const termsList = (id) => `<datalist id="${id}" data-terms>${(state.meta?.payment_terms || []).map((t) => `<option value="${esc(t.name)}">`).join('')}</datalist>`;
const refreshTermLists = () => $$('datalist[data-terms]').forEach((dl) => { dl.innerHTML = (state.meta?.payment_terms || []).map((t) => `<option value="${esc(t.name)}">`).join(''); });
const termByName = (name) => (state.meta?.payment_terms || []).find((t) => fold(t.name) === fold(String(name || '').trim()));
/** Ao escolher uma condição que define parcelas, aplica no quadro de parcelas (se a forma permitir). */
function applyTermToInstallments(name, box, codes, quiet = false) {
  if (!box) return;
  const t = termByName(name);
  // Condição sem parcelamento (ou texto livre): à vista
  if (!t || !t.installments || (t.installments === 1 && !t.entry)) { box.update({ n: 1, entry: false }); return; }
  const { max } = instRules(codes);
  if (max <= 1) { box.update({ n: 1, entry: false }); if (!quiet && codes.length) toast(`A forma de pagamento escolhida não parcela: “${t.name}” fica à vista`); return; }
  const pays = t.installments + (t.entry ? 1 : 0);
  if (pays > max && !quiet) toast(`Esta forma de pagamento aceita até ${max} pagamentos: ajustei o parcelamento`);
  box.update({ entry: !!t.entry, n: Math.max(1, Math.min(t.installments, max - (t.entry ? 1 : 0))), days: t.interval_days || 30 });
}
function comboUpgrade(root) {
  (root.matches && root.matches('input[list]') ? [root] : [...(root.querySelectorAll ? root.querySelectorAll('input[list]') : [])]).forEach((i) => {
    i.dataset.list = i.getAttribute('list'); i.removeAttribute('list'); i.setAttribute('autocomplete', 'off'); i.classList.add('combo-input');
  });
}
new MutationObserver((ms) => ms.forEach((m) => m.addedNodes.forEach((n) => n.nodeType === 1 && comboUpgrade(n)))).observe(document.documentElement, { childList: true, subtree: true });
document.addEventListener('focusin', (e) => { if (e.target.matches && e.target.matches('input[data-list]')) comboOpen(e.target, false); });
document.addEventListener('input', (e) => { if (e.target === combo.input || (e.target.matches && e.target.matches('input[data-list]'))) { if (e.isTrusted) comboOpen(e.target, true); } });
document.addEventListener('mousedown', (e) => {
  const o = e.target.closest('.combo-opt');
  if (o) { e.preventDefault(); comboPick(+o.dataset.i); return; }
  if (e.target.matches && e.target.matches('input[data-list]')) { if (combo.input !== e.target) comboOpen(e.target, false); else if (!combo.el) comboOpen(e.target, false); return; }
  if (!e.target.closest('.combo-menu')) comboClose();
});
document.addEventListener('focusout', (e) => { if (e.target === combo.input) setTimeout(() => { if (document.activeElement !== combo.input) comboClose(); }, 120); });
document.addEventListener('keydown', (e) => {
  if (!combo.el || e.target !== combo.input) {
    if (e.key === 'ArrowDown' && e.target.matches && e.target.matches('input[data-list]')) { e.preventDefault(); comboOpen(e.target, false); }
    return;
  }
  const opts = $$('.combo-opt', combo.el);
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    combo.idx = (combo.idx + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
    opts.forEach((o, i) => o.classList.toggle('active', i === combo.idx));
    opts[combo.idx].scrollIntoView({ block: 'nearest' });
  } else if (e.key === 'Enter' && combo.idx >= 0) { e.preventDefault(); comboPick(combo.idx); }
  else if (e.key === 'Escape') { e.stopPropagation(); comboClose(); }
  else if (e.key === 'Tab') comboClose();
}, true);
window.addEventListener('resize', comboPlace);
document.addEventListener('scroll', (e) => { if (combo.el && !combo.el.contains(e.target)) comboPlace(); }, true);
window.addEventListener('hashchange', comboClose);

// ============ Layout e rotas ============
// Opções do botão "Novo" (menu lateral e painel)
const NEW_ITEMS = [
  ['orcamento/novo', 'Novo orçamento', 'quote', 'orcamentos'],
  ['vendas/nova', 'Nova venda', 'cart', 'vendas'],
  ['compra/nova', 'Nova ordem de compra', 'order', 'compras'],
  ['ordem/nova', 'Nova ordem de serviço', 'tool', 'ordens'],
];
/** Botão "Novo" com menu suspenso (orçamento, venda, ordem de compra). */
function newButton() {
  const items = NEW_ITEMS.filter(([, , , perm]) => can(perm));
  if (!items.length) return '';
  return `<div class="dd"><button type="button" class="btn primary dd-btn" aria-haspopup="true" aria-expanded="false">${icon('plus')}Novo${icon('chevron')}</button>
    <div class="dd-menu" role="menu" hidden>${items.map(([r, l, i]) => `<a role="menuitem" href="#/${r}">${icon(i)}${l}</a>`).join('')}</div></div>`;
}
document.addEventListener('click', (e) => {
  const b = e.target.closest('.dd-btn');
  $$('.dd-menu').forEach((m) => { if (!b || m.previousElementSibling !== b) { m.hidden = true; m.previousElementSibling.setAttribute('aria-expanded', 'false'); } });
  if (b) { const m = b.nextElementSibling; m.hidden = !m.hidden; b.setAttribute('aria-expanded', String(!m.hidden)); }
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') $$('.dd-menu').forEach((m) => (m.hidden = true)); });
// Menu: links diretos e grupos recolhíveis. O 4º item é a permissão exigida ('admin' = só administrador).
const NAV = [
  ['painel', 'Painel', 'home'],
  { g: 'novo', label: 'Novo', icon: 'plus', cls: 'nav-new', items: NEW_ITEMS },
  ['notas/importar', 'Importar nota', 'upload', 'notas'],
  { g: 'operacao', label: 'Operação', icon: 'swap', items: [
    ['vendas', 'Vendas', 'receipt', 'vendas'], ['orcamentos', 'Orçamentos', 'quote', 'orcamentos'],
    ['notas', 'Notas fiscais', 'receipt', 'notas'],
    ['compras', 'Ordens de compra', 'order', 'compras'], ['ordens', 'Ordens de serviço', 'tool', 'ordens']] },
  { g: 'cadastros', label: 'Cadastros', icon: 'box', items: [
    ['produtos', 'Produtos', 'box', 'produtos'], ['servicos', 'Serviços', 'tool', 'servicos'],
    ['clientes', 'Clientes', 'id', 'clientes'], ['fornecedores', 'Fornecedores', 'truck', 'fornecedores'],
    ['formas-pagamento', 'Formas de pagamento', 'wallet', 'formas_pagamento'], ['condicoes-pagamento', 'Condições de pagamento', 'wallet', 'admin'],
    ['equipamentos', 'Equipamentos', 'box', 'ordens'], ['tipos-tarefa', 'Tipos de tarefa', 'tool', 'ordens'], ['questionarios', 'Questionários', 'check', 'ordens']] },
  { g: 'financeiro', label: 'Financeiro', icon: 'wallet', items: [
    ['financeiro', 'Pagamentos e recebimentos', 'wallet', 'financeiro'], ['fluxo-caixa', 'Fluxo de caixa', 'flow', 'financeiro']] },
  { g: 'relatorios', label: 'Relatórios', icon: 'chart', items: [
    ['relatorios', 'Relatórios de vendas', 'chart', 'relatorios'], ['estoque', 'Movimentações', 'swap', 'movimentacoes']] },
  { g: 'empresa', label: 'Configurações', icon: 'gear', items: [
    ['empresa', 'Dados da empresa', 'gear', 'admin'],
    ['orcamento-config', 'Orçamento', 'quote', 'admin'], ['vendas-comportamento', 'Vendas', 'receipt', 'admin'],
    ['notas-comportamento', 'Notas fiscais', 'upload', 'admin'], ['compras-comportamento', 'Ordens de compra', 'order', 'admin'],
    // Sistema fica sempre por último em Configurações
    { sub: 'Sistema', key: 'sistema', icon: 'monitor', items: [['usuarios', 'Usuários', 'users', 'admin'], ['permissoes', 'Permissões', 'lock', 'admin'], ['backup', 'Backup', 'database', 'admin']] }] },
];
const can = (perm) => !perm || (perm === 'admin' ? isAdmin() : isAdmin() || (state.me?.permissions || []).includes(perm));

/** Qual item do menu fica destacado para cada endereço. */
function navSection(path) {
  if (['orcamento/novo', 'vendas/nova', 'notas/importar', 'compra/nova'].includes(path)) return path;
  if (['orcamento-status', 'orcamento-comportamento'].includes(path)) return 'orcamento-config';
  const map = [['compra/', 'compras'], ['produto/', 'produtos'], ['venda/', 'vendas'], ['nota/', 'notas'], ['cliente/', 'clientes'], ['fornecedor/', 'fornecedores'], ['orcamento/', 'orcamentos']];
  for (const [pre, sec] of map) if (path.startsWith(pre)) return sec;
  return path;
}

/** Cabeçalho do menu: logo e nome da empresa (Configurações → Dados da empresa); sem eles, o ícone e "Gestão de Serviços". */
function brandHtml() {
  const co = state.meta?.company || {};
  const name = String(co.name || '').trim();
  const logo = /^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,/.test(co.logo || '') ? co.logo : '';
  return `${logo ? `<img class="brand-img" src="${esc(logo)}" alt="">` : `<span class="logo">${icon('box')}</span>`}<span class="brand-name" title="${esc(name || 'Gestão de Serviços')}">${esc(name || 'Gestão de Serviços')}</span>`;
}
function paintBrand() {
  const b = document.getElementById('brand'); if (b) { b.innerHTML = brandHtml(); b.classList.toggle('has-img', !!b.querySelector('.brand-img')); }
  brandTab();
}
/** Nome e ícone da aba do navegador: nome fantasia e logo da empresa (guardados no aparelho para já abrir certo). */
function brandTab() {
  const co = state.meta?.company || {};
  const name = String(co.name || '').trim() || 'Gestão de Serviços';
  const link = document.querySelector('link[rel=icon]');
  const save = (k, v) => { try { if (v) localStorage.setItem(k, v); else localStorage.removeItem(k); } catch (_) {} };
  document.title = name; save('brandTitle', name === 'Gestão de Serviços' ? '' : name);
  if (!link) return;
  const logo = /^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,/.test(co.logo || '') ? co.logo : '';
  if (!logo) { link.href = link.dataset.default || link.href; save('brandIcon', ''); return; }
  // Logo num quadrado transparente de 64 px (logos largos não ficam esticados)
  const img = new Image();
  img.onload = () => {
    const c = document.createElement('canvas'); c.width = c.height = 64;
    const s = Math.min(64 / img.width, 64 / img.height), w = img.width * s, h = img.height * s;
    c.getContext('2d').drawImage(img, (64 - w) / 2, (64 - h) / 2, w, h);
    try { const href = c.toDataURL('image/png'); link.href = href; save('brandIcon', href); } catch (_) {}
  };
  img.src = logo;
}

function renderShell() {
  const link = (it) => {
    // Subgrupo recolhível dentro de um grupo (ex.: Configurações → Orçamento)
    if (it.sub) {
      const inner = it.items.map(link).join('');
      return inner ? `<div class="nav-sub" data-s="${it.key}"><button type="button" class="nav-sh">${icon(it.icon || 'chevron')}<span>${esc(it.sub)}</span>${icon('chevron')}</button><div class="nav-si">${inner}</div></div>` : '';
    }
    const [r, l, i, perm] = it;
    return can(perm) ? `<a href="#/${r}" data-r="${r}">${icon(i)}<span>${l}</span></a>` : '';
  };
  const html = NAV.map((n) => {
    if (!n.g) return link(n);
    const items = n.items.map(link).join('');
    if (!items) return '';
    return `<div class="nav-group ${n.cls || ''}" data-g="${n.g}"><button type="button" class="nav-gh">${icon(n.icon)}<span>${n.label}</span>${icon('chevron')}</button><div class="nav-gi">${items}</div></div>`;
  }).join('');
  $('#app').innerHTML = `<div class="shell">
    <aside class="side">
      <div class="brand ${/brand-img/.test(brandHtml()) ? 'has-img' : ''}" id="brand">${brandHtml()}</div>
      <nav class="nav">${html}</nav>
      <div class="foot"><div class="foot-row"><a href="#/conta" class="foot-user" title="Minha conta">${avatarHtml(state.me, 'sm')}<strong>${esc(state.me.name)}</strong></a><button type="button" class="theme-btn" id="themebtn" aria-label="Alternar tema: claro, escuro ou automático"></button></div>
        <div class="actions" style="margin-top:6px"><a href="#/conta">Minha conta</a> · <a href="#" id="logout">Sair</a></div>
        ${state.version ? `<div class="ver" title="Versão do sistema">Versão ${esc(state.version)}</div>` : ''}</div>
    </aside>
    <div>
      <header class="topbar"><button class="icon-btn" id="menu" aria-label="Menu">${icon('menu')}</button><span class="title" id="top-title">Gestão de Serviços</span></header>
      <main class="main" id="view"></main>
    </div></div>`;
  // Grupos abertos/fechados ficam guardados neste navegador
  let open = {};
  try { open = JSON.parse(localStorage.getItem('navOpen') || '{}'); } catch (_) {}
  $$('.nav-group').forEach((g) => { if (open[g.dataset.g] && g.dataset.g !== 'novo') g.classList.add('open'); });
  $$('.nav-sub').forEach((g) => { if (open['sub:' + g.dataset.s]) g.classList.add('open'); });
  $('.nav').addEventListener('click', (e) => {
    const sh = e.target.closest('.nav-sh');
    if (sh) {
      const g = sh.parentElement; g.classList.toggle('open');
      open['sub:' + g.dataset.s] = g.classList.contains('open');
      try { localStorage.setItem('navOpen', JSON.stringify(open)); } catch (_) {}
      return;
    }
    const h = e.target.closest('.nav-gh'); if (!h) return;
    const g = h.parentElement; g.classList.toggle('open');
    open[g.dataset.g] = g.classList.contains('open');
    try { localStorage.setItem('navOpen', JSON.stringify(open)); } catch (_) {}
  });
  applyTheme();
  $('#themebtn').onclick = () => { const t = THEME_NEXT[getTheme()] || 'light'; setTheme(t); toast(`Tema: ${THEMES[t]}`); if ($('#tp')) $$('#tp button').forEach((x) => x.classList.toggle('on', x.dataset.t === t)); };
  $('#menu').onclick = () => $('.shell').classList.toggle('menu-open');
  $('.side').addEventListener('click', (e) => { if (e.target.closest('a')) $('.shell').classList.remove('menu-open'); });
  $('.shell').addEventListener('click', (e) => { if (e.target === $('.shell')) $('.shell').classList.remove('menu-open'); });
  $('#logout').onclick = async (e) => { e.preventDefault(); try { await api('logout', { body: {} }); } catch (_) {} await loadMe(); renderLogin(); };
}

const ROUTES = [
  [/^painel$/, viewDashboard],
  [/^produtos$/, viewProducts, 'produtos'],
  [/^produto\/(\d+)$/, viewProduct],
  [/^estoque$/, viewMovements, 'movimentacoes'],
  [/^notas$/, viewInvoices, 'notas'],
  [/^notas\/importar$/, viewImport, 'notas'],
  [/^nota\/(\d+)$/, viewInvoice, 'notas'],
  [/^vendas$/, viewSales, 'vendas'],
  [/^vendas\/nova$/, viewNewSale, 'vendas'],
  [/^venda\/(\d+)$/, viewSale],
  [/^orcamentos$/, viewQuotes, 'orcamentos'],
  [/^orcamento\/novo$/, (v) => viewQuoteForm(v, null), 'orcamentos'],
  [/^orcamento\/(\d+)$/, viewQuoteForm, 'orcamentos'],
  [/^empresa$/, viewSettings, 'admin'],
  [/^servicos$/, viewServices, 'servicos'],
  [/^fornecedores$/, viewSuppliers, 'fornecedores'],
  [/^fornecedor\/(\d+)$/, viewSupplier, 'fornecedores'],
  [/^permissoes$/, viewPermissions, 'admin'],
  [/^clientes$/, viewCustomers, 'clientes'],
  [/^cliente\/(\d+)$/, viewCustomer, 'clientes'],
  [/^relatorios$/, viewReports, 'relatorios'],
  [/^usuarios$/, viewUsers, 'admin'],
  [/^condicoes-pagamento$/, viewPaymentTerms, 'admin'],
  [/^orcamento-(config|comportamento|status)$/, viewQuoteSettings, 'admin'],
  [/^vendas-comportamento$/, viewSaleBehavior, 'admin'],
  [/^notas-comportamento$/, viewInvoiceBehavior, 'admin'],
  [/^compras-comportamento$/, viewPoBehavior, 'admin'],
  [/^compras$/, viewPurchases, 'compras'],
  [/^formas-pagamento$/, viewPaymentMethods, 'formas_pagamento'],
  [/^compra\/nova$/, (v) => viewPurchaseForm(v, null), 'compras'],
  [/^compra\/(\d+)$/, viewPurchaseForm, 'compras'],
  [/^ordens$/, viewServiceOrders, 'ordens'],
  [/^ordem\/nova$/, (v) => viewServiceOrderForm(v, null), 'ordens'],
  [/^ordem\/(\d+)$/, viewServiceOrderForm, 'ordens'],
  [/^equipamentos$/, viewEquipments, 'ordens'],
  [/^tipos-tarefa$/, viewTaskTypes, 'ordens'],
  [/^questionarios$/, viewQuestionnaires, 'ordens'],
  [/^questionario\/nova$/, (v) => viewQuestionnaireForm(v, null), 'ordens'],
  [/^questionario\/(\d+)$/, viewQuestionnaireForm, 'ordens'],
  [/^financeiro$/, viewFinance, 'financeiro'],
  [/^fluxo-caixa$/, viewCashflow, 'financeiro'],
  [/^conta$/, viewAccount],
  [/^aparencia$/, viewAccount], // a Aparência fica em Minha conta
  [/^backup$/, viewBackup, 'admin'],
];

let routeToken = 0;
async function route() {
  if (!state.me) return renderLogin();
  if (!$('.shell')) renderShell();
  $('.shell').classList.remove('menu-open');
  const path = (location.hash.replace(/^#\/?/, '') || 'painel').split('?')[0];
  const [re, fn, perm] = ROUTES.find(([r]) => r.test(path)) || [/^/, viewDashboard];
  const args = path.match(re).slice(1);
  const section = navSection(path);
  $$('.nav a').forEach((a) => a.classList.toggle('active', a.dataset.r === section));
  const active = $('.nav a.active');
  if (active) { const g = active.closest('.nav-group'); if (g) g.classList.add('open'); const sg = active.closest('.nav-sub'); if (sg) sg.classList.add('open'); }
  $('#top-title').textContent = active ? active.textContent : 'Gestão de Serviços';
  // Troca o elemento da página para não acumular eventos de telas anteriores
  const old = $('#view'); const view = old.cloneNode(false); old.replaceWith(view);
  const token = ++routeToken;
  view.innerHTML = '<p class="muted">Carregando…</p>';
  try {
    if (!state.meta) { state.meta = await api('meta'); paintBrand(); }
    syncQStatus(); syncPoStatus();
    if (token !== routeToken) return;
    if (!can(perm)) { view.innerHTML = `<div class="empty">${icon('lock')}<h2>Sem acesso</h2><p>Você não tem permissão para esta área. Peça a um administrador para liberar em <strong>Configurações → Sistema → Permissões</strong>.</p><a class="btn" href="#/painel">Voltar ao painel</a></div>`; return; }
    await fn(view, ...args);
  } catch (e) {
    if (token === routeToken) view.innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
  }
  window.scrollTo(0, 0);
}
const go = (h) => { if (location.hash === '#/' + h) route(); else location.hash = '#/' + h; };

// ============ Login ============
// Figurinhas da tela de login (desenhos próprios: caixa de estoque e prancheta com gráfico)
const LOGIN_STICKERS = {
  box: `<svg viewBox="0 0 120 120" class="lg-st st1" aria-hidden="true"><g stroke="#1f1633" stroke-width="4" stroke-linejoin="round" stroke-linecap="round">
    <path d="M20 42 60 24l40 18v44L60 104 20 86z" fill="#f4a259"/><path d="M20 42l40 18 40-18M60 60v44" fill="none"/><path d="M60 60 100 42 100 86 60 104z" fill="#e0823a"/>
    <path d="M40 33l40 18v14l-9-4-9 4V47L22 41" fill="#c6f04a"/><path d="M86 14l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#f78fb3"/><path d="M16 96l2 5 5 2-5 2-2 5-2-5-5-2 5-2z" fill="#fff"/></g></svg>`,
  board: `<svg viewBox="0 0 120 120" class="lg-st st2" aria-hidden="true"><g stroke="#1f1633" stroke-width="4" stroke-linejoin="round" stroke-linecap="round">
    <rect x="24" y="18" width="66" height="86" rx="10" fill="#f78fb3"/><rect x="33" y="30" width="48" height="64" rx="5" fill="#fff"/><rect x="44" y="12" width="26" height="12" rx="4" fill="#c6f04a"/>
    <path d="M42 82V68M54 82V56M66 82V62M78 82V48" stroke-width="6"/><path d="M40 45h20" stroke="#a78bfa"/><path d="M96 70l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#c6f04a"/></g></svg>`,
};
function renderLogin() {
  let b = state.brand || {};
  if (!b.name) { try { b = { name: localStorage.getItem('brandTitle') || '', logo: localStorage.getItem('brandIcon') || '' }; } catch (_) {} }
  const logo = /^data:image\//.test(b.logo || '') ? `<img src="${esc(b.logo)}" alt="">` : icon('box');
  const h = new Date().getHours(), hello = h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite';
  $('#app').innerHTML = `<div class="lg-page">
    <header class="lg-top"><div class="lg-brand"><span class="lg-logo ${/^data:image/.test(b.logo || '') ? 'img' : ''}">${logo}</span>${esc(b.name || 'Gestão de Serviços')}</div><a class="lg-client" href="cliente/">${icon('user')}Área do cliente</a></header>
    <main class="lg-main">
      <section class="lg-hero">
        ${state.version ? `<span class="lg-ver">Versão ${esc(state.version)}</span>` : ''}
        <h1>Suas ordens<br>sob <mark>controle</mark>.</h1>
        <p>Ordens de serviço, clientes, orçamentos e financeiro num só lugar</p>
        ${LOGIN_STICKERS.box}${LOGIN_STICKERS.board}
      </section>
      <form class="lg-card" id="login">
        <h2>${hello}! Entre na sua conta</h2>
        <div id="login-err"></div>
        <label>E-mail <i>*</i><input name="email" type="email" autocomplete="username" required></label>
        <label>Senha <i>*</i><input name="password" type="password" autocomplete="current-password" required></label>
        <button class="lg-btn" type="submit">${icon('login')}Entrar</button>
        <p class="lg-help">Esqueceu a senha? Peça para um administrador redefinir.</p>
      </form>
    </main>
    <footer class="lg-foot"><svg viewBox="0 0 600 12" preserveAspectRatio="none" aria-hidden="true"><path d="M0 6 Q 15 0 30 6 T 60 6 T 90 6 T 120 6 T 150 6 T 180 6 T 210 6 T 240 6 T 270 6 T 300 6 T 330 6 T 360 6 T 390 6 T 420 6 T 450 6 T 480 6 T 510 6 T 540 6 T 570 6 T 600 6" fill="none" stroke="#c6f04a" stroke-width="2"/></svg>
      <span>${new Date().getFullYear()} © ${esc(b.name || 'Sistema de Gestão de Serviços')}</span></footer>
  </div>`;
  const f = $('#login');
  $('input', f).focus();
  f.onsubmit = async (e) => {
    e.preventDefault();
    const btn = $('button', f);
    await busy(btn, async () => {
      try {
        const d = await api('login', { body: formData(f) });
        state.me = d.user; state.csrf = d.csrf; state.meta = null; invalidateProducts(); applyAccent(state.me?.accent_color);
        renderShell(); route();
      } catch (err) { $('#login-err').innerHTML = `<div class="alert err">${esc(err.message)}</div>`; }
    });
  };
}

// ============ Painel ============
async function viewDashboard(v) {
  const d = await api('dashboard');
  v.innerHTML = `<div class="page-head"><div><h1>Olá, ${esc(state.me.name.split(' ')[0])}</h1><p class="muted">${new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })}</p></div>
    <div class="actions">${can('notas') ? `<a class="btn" href="#/notas/importar">${icon('upload')}Importar nota</a>` : ''}${newButton()}</div></div>
    ${d.today ? `<div class="kpis">
      <div class="kpi"><div class="label">Vendas hoje</div><div class="value">${money(d.today.revenue)}</div><div class="sub">${d.today.sales} venda(s)</div></div>
      <div class="kpi ${d.today.profit >= 0 ? 'pos' : 'neg'}"><div class="label">Lucro bruto hoje</div><div class="value">${money(d.today.profit)}</div></div>
      <div class="kpi"><div class="label">Faturamento no mês</div><div class="value">${money(d.month.revenue)}</div><div class="sub">${d.month.sales} venda(s)</div></div>
      <div class="kpi ${d.month.profit >= 0 ? 'pos' : 'neg'}"><div class="label">Lucro bruto no mês</div><div class="value">${money(d.month.profit)}</div><div class="sub">margem ${pct(d.month.margin)}</div></div>
      <div class="kpi"><div class="label">Valor em estoque</div><div class="value">${money(d.stock.value)}</div><div class="sub">${d.stock.products} produto(s)</div></div>
      <div class="kpi ${d.stock.low ? 'neg' : ''}"><div class="label">Abaixo do mínimo</div><div class="value">${d.stock.low}</div><div class="sub"><a href="#/produtos?low=1" data-low>ver lista</a></div></div>
    </div>` : `<div class="kpis"><div class="kpi ${d.stock.low ? 'neg' : ''}"><div class="label">Abaixo do mínimo</div><div class="value">${d.stock.low}</div><div class="sub"><a href="#/produtos" data-low>ver lista</a></div></div></div>`}
    ${d.finance ? `<div class="kpis">
      <div class="kpi ${d.finance.balance >= 0 ? '' : 'neg'}"><div class="label">Saldo em caixa</div><div class="value">${money(d.finance.balance)}</div><div class="sub"><a href="#/fluxo-caixa">fluxo de caixa</a></div></div>
      <div class="kpi"><div class="label">A receber em aberto</div><div class="value">${money(d.finance.receber.open_total)}</div><div class="sub">${d.finance.receber.overdue ? `<span class="stock-low">${money(d.finance.receber.overdue)} vencido</span>` : `${money(d.finance.receber.next7)} nos próximos 7 dias`}</div></div>
      <div class="kpi ${d.finance.pagar.overdue ? 'neg' : ''}"><div class="label">A pagar em aberto</div><div class="value">${money(d.finance.pagar.open_total)}</div><div class="sub">${d.finance.pagar.overdue ? `${d.finance.pagar.overdue_count} conta(s) vencida(s): <a href="#/financeiro" data-fin="vencido">ver</a>` : `${money(d.finance.pagar.next7)} nos próximos 7 dias`}</div></div>
      ${d.purchases ? `<div class="kpi ${d.purchases.late ? 'neg' : ''}"><div class="label">Ordens de compra abertas</div><div class="value">${d.purchases.open}</div><div class="sub">${d.purchases.late ? `${d.purchases.late} com entrega atrasada` : '<a href="#/compras">ver ordens</a>'}</div></div>` : ''}
    </div>` : ''}
    ${d.month_days ? `<div class="card"><div class="card-head"><h2>Este mês</h2></div>${barChart(d.month_days)}</div>` : ''}
    <div class="grid two">
      <div class="card"><div class="card-head"><h2>Precisa repor</h2>${can('compras') && d.low_stock.length ? `<a href="#/compra/nova" class="small" data-lowpo>gerar ordem de compra</a>` : ''}</div>
        ${d.low_stock.length ? `<div class="table-wrap"><table><thead><tr><th>Produto</th><th class="num">Estoque</th><th class="num">Mínimo</th></tr></thead><tbody>
        ${d.low_stock.map((p) => `<tr class="click" data-go="produto/${p.id}"><td>${esc(p.name)}</td><td class="num stock-low">${qty(p.stock)} ${esc(p.unit)}</td><td class="num">${qty(p.min_stock)}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted">Tudo em ordem. Nenhum produto abaixo do estoque mínimo.</p>'}</div>
      <div class="card"><div class="card-head"><h2>Últimas movimentações</h2><a href="#/estoque" class="small">ver todas</a></div>
        ${d.recent.length ? `<div class="table-wrap"><table><tbody>${d.recent.map((m) => `<tr><td><div>${esc(m.product_name)}</div><div class="small muted">${movLabel(m.type)} · ${dt(m.created_at)}</div></td><td class="num ${+m.quantity >= 0 ? 'qty-pos' : 'qty-neg'}">${m.type === 'ajuste_custo' ? 'novo custo ' + money(m.unit_cost) : `${+m.quantity > 0 ? '+' : ''}${qty(m.quantity)} ${esc(m.unit)}`}</td></tr>`).join('')}</tbody></table></div>`
        : `<p class="muted">Nenhuma movimentação ainda. Comece <a href="#/produtos">cadastrando produtos</a> ou <a href="#/notas/importar">importando uma nota</a>.</p>`}</div>
    </div>`;
  v.addEventListener('click', (e) => { const r = e.target.closest('[data-go]'); if (r) go(r.dataset.go); });
  $('[data-low]', v).onclick = (e) => { e.preventDefault(); sessionStorage.setItem('prodLow', '1'); go('produtos'); };
  const lp = $('[data-lowpo]', v); if (lp) lp.onclick = (e) => { e.preventDefault(); sessionStorage.setItem('poLow', '1'); go('compra/nova'); };
  const fv = $('[data-fin]', v); if (fv) fv.onclick = (e) => { e.preventDefault(); sessionStorage.setItem('finFilter', JSON.stringify({ type: 'pagar', status: 'vencido' })); go('financeiro'); };
}

// ============ Produtos ============
async function viewProducts(v) {
  const f = { q: '', low: sessionStorage.getItem('prodLow') === '1', active: '1' };
  sessionStorage.removeItem('prodLow');
  v.innerHTML = `<div class="page-head"><div><h1>Produtos</h1><p class="muted">Produtos que você vende e controla no estoque.</p></div>
    <div class="actions">${can('produtos') ? `<button class="btn" id="imp">${icon('upload')}Importar planilha</button><button class="btn primary" id="new">${icon('plus')}Novo produto</button>` : ''}</div></div>
    <div class="card">
      <div class="filters">
        <label class="grow">Buscar <input id="q" placeholder="Nome, marca, código (SKU, fabricante ou referência), código de barras ou localização"></label>
        <label class="check"><input type="checkbox" id="low" ${f.low ? 'checked' : ''}> Abaixo do mínimo</label>
        <label class="check"><input type="checkbox" id="inactive"> Mostrar inativos</label>
      </div>
      <div id="list"></div></div>`;
  const load = async () => {
    const rows = await api('products', { params: { q: f.q, low: f.low ? '1' : '', active: f.active, no_services: '1' } });
    $('#list', v).innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th>Cód. fabricante</th><th>Produto</th><th>Localização</th><th class="num">Estoque</th><th class="num">Custo unit.</th><th class="num">Preço</th><th class="num">Margem</th></tr></thead><tbody>
      ${rows.map((p) => {
        const c = +p.avg_cost;
        const m = +p.sale_price > 0 ? ((p.sale_price - c) / p.sale_price) * 100 : null;
        const low = +p.min_stock > 0 && +p.stock <= +p.min_stock;
        return `<tr class="click" data-id="${p.id}"><td class="nowrap">${p.mfr_code ? esc(p.mfr_code) : '<span class="muted">—</span>'}</td><td><div><strong>${esc(p.name)}</strong> ${+p.active ? '' : '<span class="badge">inativo</span>'}</div><div class="small muted">${esc([p.brand, p.category, p.sku].filter(Boolean).join(' · '))}</div></td>
        <td>${p.location ? esc(p.location) : '<span class="muted">—</span>'}</td>
        <td class="num ${low ? 'stock-low' : ''}">${qty(p.stock) + ' ' + esc(p.unit)}</td>
        <td class="num">${cost(c)}</td><td class="num">${money(p.sale_price) + (p.markup !== null && p.markup !== undefined ? `<div class="small muted">auto +${qty(p.markup, 2)}%</div>` : '')}</td>
        <td class="num">${m === null ? '—' : `<span class="${m < 0 ? 'qty-neg' : ''}">${pct(m)}</span>`}</td></tr>`;
      }).join('')}</tbody></table></div>${rows.length >= 1000 ? '<p class="help" style="margin:8px 0 0">Mostrando os primeiros 1.000 produtos. Use a busca para encontrar os demais.</p>' : ''}`
      : `<div class="empty">Nenhum produto encontrado.${!f.q && !f.low ? ' Cadastre o primeiro em <strong>Novo produto</strong> ou importe uma nota fiscal.' : ''}</div>`;
  };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; load(); });
  $('#low', v).onchange = (e) => { f.low = e.target.checked; load(); };
  $('#inactive', v).onchange = (e) => { f.active = e.target.checked ? 'all' : '1'; load(); };
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('produto/' + r.dataset.id); };
  if ($('#new', v)) $('#new', v).onclick = () => productForm(null, (id) => go('produto/' + id));
  if ($('#imp', v)) $('#imp', v).onclick = () => importProductsDialog(() => { invalidateProducts(); route(); });
  await load();
}

// ============ Tributação do produto (aba no cadastro) ============
/** Campos fiscais (padrão para NF-e). As listas vêm do servidor em state.meta.fiscal. */
function fiscalPaneHtml(fv) {
  const F = state.meta.fiscal, f = fv || {}, g = (k) => f[k] || {};
  const opt = (list, val, first = 'Selecione') => `<option value="">${first}</option>` + Object.entries(list).map(([k, l]) => `<option value="${esc(k)}" ${String(val ?? '') === k ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const sel = (k, list, val, first) => `<select data-f="${k}">${opt(list, val, first)}</select>`;
  const n = (v, d = 4) => (v === null || v === undefined || v === '' ? '' : qty(v, d));
  const inp = (k, val, extra = '') => `<input data-f="${k}" value="${esc(val ?? '')}" ${extra}>`;
  const dec = (k, val, ph = '') => inp(k, n(val), `inputmode="decimal" placeholder="${ph}"`);
  const calcSel = (k, list, val) => `<select data-f="${k}">${Object.entries(list).map(([c, l]) => `<option value="${c}" ${String(val || '') === c ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  const i = g('icms'), pis = g('pis'), cof = g('cofins'), ipi = g('ipi'), ibs = g('ibs_cbs');
  const pc = (k, x, label) => `
      <div class="row"><label>Situação tributária do ${label} ${sel(k + '.cst', F.cst_pis_cofins, x.cst)}</label></div>
      <div class="row"><label>Tipo de cálculo ${calcSel(k + '.calc', F.calc_pis_cofins, x.calc)}</label>
        <label data-calc="${k}:percentual">Base de cálculo do ${label} ${dec(k + '.bc', x.bc, '0,00')}</label>
        <label data-calc="${k}:percentual">Alíquota do ${label} (%) ${dec(k + '.p', x.p, '0,00')}</label>
        <label data-calc="${k}:valor">Valor unitário do ${label} (R$) ${dec(k + '.v_unit', x.v_unit, '0,00')}</label></div>`;
  return `<div class="fiscal">
    <p class="help" style="margin:0">Valores padrão do produto para a emissão de NF-e. Ficam guardados no cadastro; NCM, CEST e Origem são preenchidos sozinhos ao importar uma nota de compra, se estiverem em branco.</p>
    <div class="row"><label style="grid-column:span 2">Origem ${sel('origem', F.origens, f.origem)}</label>
      <label>Unidade tributável ${inp('unidade', f.unidade, 'maxlength="6" placeholder="Ex.: UN, PC, KG"')}</label></div>
    <div class="row"><label>NCM ${inp('ncm', f.ncm, 'inputmode="numeric" maxlength="10" placeholder="8 dígitos"')}</label>
      <label>CEST ${inp('cest', f.cest, 'inputmode="numeric" maxlength="9" placeholder="7 dígitos"')}</label>
      <label>CFOP ${inp('cfop', f.cfop, 'inputmode="numeric" maxlength="5" list="cfops" placeholder="Ex.: 5102"')}<datalist id="cfops">${Object.entries(F.cfop).map(([c, l]) => `<option value="${c}">${esc(c + ' - ' + l)}</option>`).join('')}</datalist></label></div>
    <div class="row"><label style="grid-column:span 2">Descrição na nota ${inp('descricao', f.descricao, 'maxlength="120" placeholder="Em branco = nome do produto"')}</label>
      <label>Código de benefício fiscal (cBenef) ${inp('cbenef', f.cbenef, 'maxlength="10" placeholder="Ex.: PR800000"')}</label></div>
    <div class="seg" id="ftabs">${[['icms', 'ICMS'], ['pis', 'PIS'], ['cofins', 'COFINS'], ['ipi', 'IPI'], ['ibs', 'IBS/CBS']].map(([k, l], x) => `<button type="button" data-ft="${k}" class="${x ? '' : 'on'}">${l}</button>`).join('')}</div>
    <div data-fp="icms" class="form">
      <div class="row"><label>CSOSN do ICMS ${sel('icms.csosn', F.csosn, i.csosn)}</label></div>
      <div class="row" data-grp="cred"><label>Alíquota aplicável de cálculo do crédito (%) ${dec('icms.p_cred_sn', i.p_cred_sn, '0,00')}</label></div>
      <div class="row" data-grp="own"><label>Modalidade da BC do ICMS ${sel('icms.mod_bc', F.mod_bc, i.mod_bc)}</label>
        <label>Redução da BC do ICMS (%) ${dec('icms.p_red_bc', i.p_red_bc, '0,00')}</label>
        <label>Alíquota do ICMS (%) ${dec('icms.p_icms', i.p_icms, '0,00')}</label></div>
      <div class="row" data-grp="st"><label>Modalidade da BC do ICMS ST ${sel('icms.mod_bc_st', F.mod_bc_st, i.mod_bc_st)}</label>
        <label>Margem de valor adicionado do ICMS ST (%) ${dec('icms.p_mva_st', i.p_mva_st, '0,00')}</label></div>
      <div class="row" data-grp="st"><label>Redução da BC do ICMS ST (%) ${dec('icms.p_red_bc_st', i.p_red_bc_st, '0,00')}</label>
        <label>Alíquota do ICMS ST (%) ${dec('icms.p_icms_st', i.p_icms_st, '0,00')}</label></div>
      <div class="row" data-grp="ret"><label>Base de cálculo do ICMS retido na operação anterior (R$) ${dec('icms.v_bc_st_ret', i.v_bc_st_ret, '0,00')}</label>
        <label>Alíquota suportada pelo consumidor final (%) ${dec('icms.p_st', i.p_st, '0,00')}</label></div>
      <div class="row" data-grp="ret"><label>Valor do ICMS próprio do substituto (R$) ${dec('icms.v_icms_substituto', i.v_icms_substituto, '0,00')}</label>
        <label>Valor do ICMS ST retido na operação anterior (R$) ${dec('icms.v_icms_st_ret', i.v_icms_st_ret, '0,00')}</label></div>
    </div>
    <div data-fp="pis" class="form" hidden>${pc('pis', pis, 'PIS')}</div>
    <div data-fp="cofins" class="form" hidden>${pc('cofins', cof, 'COFINS')}</div>
    <div data-fp="ipi" class="form" hidden>
      <div class="row"><label>Situação tributária do IPI ${sel('ipi.cst', F.cst_ipi, ipi.cst)}</label></div>
      <div class="row"><label>Código de enquadramento ${inp('ipi.enq', ipi.enq, 'inputmode="numeric" maxlength="3" placeholder="Ex.: 999"')}</label>
        <label>Tipo de cálculo ${calcSel('ipi.calc', F.calc_ipi, ipi.calc)}</label>
        <label data-calc="ipi:percentual">Base de cálculo do IPI ${dec('ipi.bc', ipi.bc, '0,00')}</label>
        <label data-calc="ipi:percentual">Alíquota do IPI (%) ${dec('ipi.p', ipi.p, '0,00')}</label></div>
      <label class="check"><input type="checkbox" data-f="ipi.devolvido" ${ipi.devolvido ? 'checked' : ''}> Informar IPI devolvido</label>
      <div class="row" data-devol><label>Percentual da mercadoria devolvida (%) ${dec('ipi.p_devol', ipi.p_devol, '0,00')}</label>
        <label>Valor do IPI devolvido (R$) ${dec('ipi.v_devol', ipi.v_devol, '0,00')}</label></div>
    </div>
    <div data-fp="ibs" class="form" hidden>
      <div class="row"><label>CST do IBS/CBS ${sel('ibs_cbs.cst', F.cst_ibs_cbs, ibs.cst)}</label>
        <label>Classificação tributária (cClassTrib) ${inp('ibs_cbs.cclass', ibs.cclass, 'inputmode="numeric" maxlength="6" placeholder="6 dígitos"')}</label></div>
      <p class="help" style="margin:0">Campos da reforma tributária (IBS e CBS).</p>
    </div>
  </div>`;
}

/** Liga as sub-abas e mostra só os campos que a opção escolhida usa. */
function bindFiscalPane(root) {
  const F = state.meta.fiscal, val = (k) => { const e = $(`[data-f="${k}"]`, root); return e ? (e.type === 'checkbox' ? e.checked : e.value) : ''; };
  const sync = () => {
    const cs = val('icms.csosn');
    $$('[data-grp]', root).forEach((r) => (r.hidden = !(cs && (F.csosn_groups[r.dataset.grp] || []).includes(cs))));
    $$('[data-calc]', root).forEach((l) => { const [k, c] = l.dataset.calc.split(':'); l.hidden = val(k + '.calc') !== c; });
    $('[data-devol]', root).hidden = !val('ipi.devolvido');
  };
  $('#ftabs', root).onclick = (e) => {
    const b = e.target.closest('[data-ft]'); if (!b) return;
    $$('#ftabs button', root).forEach((x) => x.classList.toggle('on', x === b));
    $$('[data-fp]', root).forEach((pn) => (pn.hidden = pn.dataset.fp !== b.dataset.ft));
  };
  root.addEventListener('change', sync);
  sync();
}

/** Lê os campos fiscais no formato que o servidor espera ({ ncm, icms: { csosn… } … }). */
function readFiscal(root) {
  const o = {};
  $$('[data-f]', root).forEach((e) => {
    const [a, b] = e.dataset.f.split('.'), v = e.type === 'checkbox' ? e.checked : e.value.trim();
    if (b) (o[a] = o[a] || {})[b] = v; else o[a] = v;
  });
  return o;
}

// Registros salvos abrem em visualização: os campos ficam travados até "Editar" (como no orçamento).
// .js-view-only aparece na visualização; .js-edit-only aparece ao editar; .js-needs-edit (adicionar, remover,
// buscar) fica travado na visualização; abas e links continuam clicáveis.
const NEEDS_EDIT = '.js-needs-edit, .sp-del, [data-delref]';
function editLock(root, form, startLocked, after) {
  const apply = (locked) => {
    form.querySelectorAll('input, select, textarea').forEach((el) => (el.disabled = locked));
    root.querySelectorAll(NEEDS_EDIT).forEach((el) => (el.disabled = locked));
    root.querySelectorAll('.js-view-only').forEach((el) => (el.hidden = !locked));
    root.querySelectorAll('.js-edit-only').forEach((el) => (el.hidden = locked));
    if (after) after(locked);
  };
  const ed = root.querySelector('[data-edit]');
  if (ed) ed.onclick = () => apply(false);
  apply(startLocked);
  return apply;
}

async function productForm(p, onSaved) {
  if (p && p.type === 'servico') return serviceForm(p, onSaved);
  const isNew = !p || !p.id;
  p = p || { type: 'produto', unit: 'un' };
  const cats = state.meta.categories || [];
  const m = modal({
    title: isNew ? 'Novo produto' : 'Editar produto', wide: true,
    body: `<form class="form" id="pf">
      <div class="seg" id="ptabs"><button type="button" data-pt="geral" class="on">Geral</button><button type="button" data-pt="desc">Observações</button><button type="button" data-pt="refs">Referências cruzadas</button><button type="button" data-pt="trib">Tributação</button></div>
      <div class="form" data-pp="geral">
      <label>Nome <input name="name" required value="${esc(p.name)}" maxlength="200"></label>
      <div class="row">
        <label>Marca <input name="brand" list="brands" value="${esc(p.brand)}" maxlength="80" placeholder="Ex.: SKF, Atlas Copco" autocomplete="off"><datalist id="brands">${(state.meta.brands || []).map((b) => `<option value="${esc(b)}">`).join('')}</datalist></label>
        <label>Código do fabricante <input name="mfr_code" value="${esc(p.mfr_code)}" maxlength="80" placeholder="Código na marca acima" autocomplete="off"></label>
      </div>
      <div class="row">
        <label>Categoria <input name="category" list="cats" value="${esc(p.category)}"><datalist id="cats">${cats.map((c) => `<option value="${esc(c)}">`).join('')}</datalist></label>
        <label><span class="unit-title">Unidade de estoque</span> <input name="unit" list="units" value="${esc(p.unit)}" maxlength="10"><datalist id="units">${state.meta.units.map((u) => `<option value="${u}">`).join('')}</datalist><span class="help">Como você conta: un, kg, g, L…</span></label>
        <label class="only-estoque">Estoque mínimo <input name="min_stock" inputmode="decimal" value="${+p.min_stock ? qty(p.min_stock, 4) : ''}" placeholder="0"><span class="help">Avisa quando chegar nesse valor</span></label>
        ${isNew ? `<label class="only-estoque">Estoque inicial <input name="initial_stock" inputmode="decimal" placeholder="0"><span class="help">Quantidade que você já tem hoje</span></label>` : ''}
      </div>
      <div class="row">
        <label>Código interno (SKU) <input name="sku" value="${esc(p.sku)}"></label>
        <label class="only-estoque">Código de barras <input name="barcode" inputmode="numeric" value="${esc(p.barcode)}"></label>
        <label class="cost-field"><span class="cost-title">Custo inicial por unidade (R$)</span> <input name="avg_cost" inputmode="decimal" placeholder="opcional" value="${+p.avg_cost ? qty(p.avg_cost, 4) : ''}"><span class="help cost-help">Se ainda não lançou uma compra</span></label>
      </div>
      <label class="only-estoque">Localização <input name="location" value="${esc(p.location)}" maxlength="80" placeholder="Ex.: Corredor 2 · Prateleira A3" autocomplete="off"><span class="help">Onde o produto fica guardado no almoxarifado</span></label>
      <fieldset class="only-venda"><legend>Preço de venda</legend>
        <div class="row">
          <label>Markup sobre o custo (%) <input name="markup" inputmode="decimal" value="${p.markup !== null && p.markup !== undefined && p.markup !== '' ? qty(p.markup, 2) : ''}" placeholder="vazio = preço manual"><span class="help">Ex.: 100% = vende pelo dobro do custo</span></label>
          <label>Preço de venda (R$) <input name="sale_price" inputmode="decimal" value="${+p.sale_price ? qty(p.sale_price, 2) : ''}" placeholder="0,00"><span class="help" id="pcalc"></span></label>
        </div>
        <p class="help" style="margin:0" id="pauto"></p>
      </fieldset>
      </div>
      <div data-pp="desc" hidden class="form">
        <label>Observações <textarea name="description" rows="10" maxlength="5000" placeholder="Detalhes do produto: medidas, aplicação, especificações, observações…">${esc(p.description || '')}</textarea></label>
      </div>
      <div data-pp="refs" hidden class="form">
        <p class="help" style="margin:0">Códigos equivalentes de outros fabricantes. A busca por código encontra o produto por qualquer um deles (ignorando traços, pontos e espaços).</p>
        <div id="refrows"></div>
        <div><button type="button" class="btn sm js-needs-edit" id="addref">${icon('plus')}Adicionar referência</button></div>
      </div>
      <div data-pp="trib" hidden>${fiscalPaneHtml(p.fiscal)}</div>
    </form>`,
    foot: `<button class="btn js-edit-only" data-close>Cancelar</button><button class="btn js-view-only" data-close>Fechar</button><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn primary js-edit-only" id="save">Salvar</button>`,
  });
  const f = $('#pf', m.el);
  const costIni = f.elements.avg_cost.value.trim();
  $('#ptabs', m.el).onclick = (e) => {
    const b = e.target.closest('[data-pt]'); if (!b) return;
    $$('#ptabs button', m.el).forEach((x) => x.classList.toggle('on', x === b));
    $$('[data-pp]', m.el).forEach((pn) => (pn.hidden = pn.dataset.pp !== b.dataset.pt));
  };
  bindFiscalPane($('[data-pp="trib"]', m.el));
  // Referências cruzadas: linhas Fabricante + Código
  const refRow = (r = {}) => `<div class="row ref-row"><label>Fabricante / marca <input data-r="manufacturer" value="${esc(r.manufacturer || '')}" maxlength="80" list="brands" autocomplete="off"></label><label>Código <input data-r="code" value="${esc(r.code || '')}" maxlength="80" autocomplete="off"></label><button type="button" class="btn icon ghost danger" data-delref title="Remover" aria-label="Remover">${icon('x')}</button></div>`;
  const refBox = $('#refrows', m.el);
  const paintRefsEmpty = () => { if (!refBox.querySelector('.ref-row')) refBox.innerHTML = '<p class="muted small" data-empty style="margin:0">Nenhuma referência cadastrada.</p>'; };
  refBox.innerHTML = (p.refs || []).map(refRow).join(''); paintRefsEmpty();
  $('#addref', m.el).onclick = () => { refBox.querySelector('[data-empty]')?.remove(); refBox.insertAdjacentHTML('beforeend', refRow()); refBox.lastElementChild.querySelector('input').focus(); };
  refBox.onclick = (e) => { const b = e.target.closest('[data-delref]'); if (b) { b.closest('.ref-row').remove(); paintRefsEmpty(); } };
  let type = p.type === 'servico' ? 'servico' : 'produto', pricingReady = false;
  const setType = (t) => {
    type = t;
    $$('.only-estoque', m.el).forEach((e) => (e.hidden = t === 'servico'));
    const svc = t === 'servico';
    $('#ptabs', m.el).hidden = svc;
    if (svc) $$('[data-pp]', m.el).forEach((pn) => (pn.hidden = pn.dataset.pp !== 'geral'));
    $('.unit-title', m.el).textContent = svc ? 'Unidade de cobrança' : 'Unidade de estoque';
    $('.cost-title', m.el).textContent = svc ? 'Custo do serviço (R$)' : isNew ? 'Custo inicial por unidade (R$)' : 'Valor de compra por unidade (R$)';
    $('.cost-help', m.el).textContent = svc ? 'Mão de obra, deslocamento… usado no cálculo do lucro' : isNew ? 'Se ainda não lançou uma compra' : 'Custo médio atual. Se mudar, fica registrado no histórico; as próximas compras refazem a média.';
    if (pricingReady) pricing();
  };
  setType(type);

  // Custo usado no cálculo do preço: custo médio (ou o custo inicial digitado)
  const baseCost = () => (isNew || type === 'servico' ? num(f.elements.avg_cost?.value) : +p.avg_cost) || 0;
  const pricing = () => {
    const mk = f.elements.markup.value.trim(), c = baseCost(), price = f.elements.sale_price;
    if (mk !== '') {
      price.readOnly = true;
      if (c > 0) price.value = (Math.round(c * (1 + num(mk) / 100) * 100) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      $('#pcalc', m.el).textContent = c > 0 ? `Custo ${cost(c)} + ${qty(num(mk), 2)}%` : 'Sem custo ainda: o preço será calculado na primeira compra';
      const pr = num(price.value);
      $('#pauto', m.el).innerHTML = `<span class="badge accent">automático</span> O preço se atualiza sozinho quando o custo mudar (compras e notas).${c > 0 && pr > 0 ? ` Margem sobre a venda: ${pct(((pr - c) / pr) * 100)}.` : ''}`;
    } else {
      price.readOnly = false;
      const pr = num(price.value);
      $('#pcalc', m.el).textContent = c > 0 && pr > 0 ? `Equivale a markup de ${pct(((pr - c) / c) * 100)} · margem ${pct(((pr - c) / pr) * 100)}` : c > 0 ? `Custo atual: ${cost(c)}` : '';
      $('#pauto', m.el).textContent = 'Preço manual: não muda quando o custo mudar. Preencha o markup para calcular automaticamente.';
    }
  };
  pricingReady = true;
  f.elements.markup.oninput = pricing;
  f.elements.sale_price.oninput = pricing;
  if (f.elements.avg_cost) f.elements.avg_cost.oninput = pricing;
  pricing();

  editLock(m.el, f, !isNew);
  $('#save', m.el).onclick = (e) => busy(e.target, async () => {
    const d = formData(f);
    d.type = type;
    if (!isNew) d.id = p.id;
    // Valor de compra: só manda se foi alterado (evita "ajuste" por arredondamento)
    if (type !== 'servico' && !isNew && String(d.avg_cost ?? '').trim() === costIni) delete d.avg_cost;
    if (type !== 'servico') d.fiscal = readFiscal($('[data-pp="trib"]', m.el));
    d.refs = $$('.ref-row', m.el).map((r) => ({ manufacturer: $('[data-r=manufacturer]', r).value, code: $('[data-r=code]', r).value }));
    const r = await api('product_save', { body: d }).catch((err) => {
      // Erro num campo fiscal: abre a aba Tributação para a pessoa ver onde está
      if (/NCM|CEST|CFOP|CSOSN|ICMS|PIS|COFINS|IPI|IBS|cBenef|Origem|Alíquota|Base de cálculo|enquadramento|crédito|devolvid|tributável|Classificação/i.test(err.message || '')) $('[data-pt="trib"]', m.el).click();
      else if (/referência/i.test(err.message || '')) $('[data-pt="refs"]', m.el).click();
      throw err;
    });
    invalidateProducts(); state.meta = null;
    m.close(); toast('Produto salvo');
    onSaved && onSaved(r.id);
  });
}

/** Resumo da tributação na página do produto. */
function fiscalCard(fv) {
  const f = fv || {}, F = (state.meta && state.meta.fiscal) || {};
  const lab = (list, k) => (k && list && list[k]) || k || '';
  const pc = (x) => (x && x.cst ? lab(F.cst_pis_cofins, x.cst) + (x.calc === 'percentual' && x.p != null ? ` · ${qty(x.p, 4)}%` : x.calc === 'valor' && x.v_unit != null ? ` · ${money(x.v_unit)}/un` : '') : '');
  const ipi = f.ipi || {}, icms = f.icms || {}, ibs = f.ibs_cbs || {};
  const rows = [
    ['NCM', f.ncm], ['CEST', f.cest], ['CFOP', f.cfop ? f.cfop + (F.cfop && F.cfop[f.cfop] ? ' - ' + F.cfop[f.cfop] : '') : ''],
    ['Origem', lab(F.origens, f.origem)], ['Unidade tributável', f.unidade], ['Descrição na nota', f.descricao], ['cBenef', f.cbenef],
    ['ICMS (CSOSN)', lab(F.csosn, icms.csosn)], ['PIS', pc(f.pis)], ['COFINS', pc(f.cofins)],
    ['IPI', ipi.cst ? lab(F.cst_ipi, ipi.cst) + (ipi.enq ? ` · enquadramento ${ipi.enq}` : '') + (ipi.calc === 'percentual' && ipi.p != null ? ` · ${qty(ipi.p, 4)}%` : '') : ''],
    ['IBS/CBS', ibs.cst ? lab(F.cst_ibs_cbs, ibs.cst) + (ibs.cclass ? ` · cClassTrib ${ibs.cclass}` : '') : ''],
  ].filter(([, v]) => v !== undefined && v !== null && v !== '');
  return `<div class="card"><div class="card-head"><h2>Tributação</h2></div>
    ${rows.length ? `<dl class="dl">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${esc(String(v))}</dd>`).join('')}</dl>`
      : '<p class="muted" style="margin:0">Sem dados fiscais. Preencha em Editar → Tributação (NCM, CEST e Origem também vêm das notas de compra importadas).</p>'}</div>`;
}

async function viewProduct(v, id) {
  const p = await api('product', { params: { id } });
  const svc = p.type === 'servico';
  const onDemand = svc;
  const unitCost = +p.unit_cost;
  const margin = +p.sale_price > 0 ? ((p.sale_price - unitCost) / p.sale_price) * 100 : null;
  const del = !!p.deleted_at;
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/produtos">← Produtos</a></p><h1>${esc(p.name)} ${del ? '<span class="badge err">excluído</span>' : +p.active ? '' : '<span class="badge">inativo</span>'}</h1>
      <p class="muted">${(svc ? 'Serviço' : 'Produto')}${p.brand ? ' · <strong>' + esc(p.brand) + '</strong>' + (p.mfr_code ? ' ' + esc(p.mfr_code) : '') : p.mfr_code ? ' · Cód. fabricante ' + esc(p.mfr_code) : ''}${p.category ? ' · ' + esc(p.category) : ''}${p.sku ? ' · SKU ' + esc(p.sku) : ''}${p.barcode ? ' · ' + esc(p.barcode) : ''}${p.location && !svc ? ' · Localização: <strong>' + esc(p.location) + '</strong>' : ''}</p></div>
    <div class="actions">
      ${del ? '' : `${!onDemand && can('movimentacoes') ? `<button class="btn" id="mov">${icon('swap')}Movimentar</button>` : ''}
      ${can(svc ? 'servicos' : 'produtos') ? '<button class="btn primary" id="edit">Editar</button>' : ''}
      ${isAdmin() ? `<button class="btn ${+p.active ? 'danger' : ''}" id="toggle">${+p.active ? 'Desativar' : 'Reativar'}</button><button class="btn danger" id="del">${icon('trash')}Excluir</button>` : ''}`}
    </div></div>
    ${del ? `<div class="alert err">Este item foi excluído em ${dt(p.deleted_at)}. Ele fica guardado só para o histórico de vendas, orçamentos e notas.</div>` : ''}
    <div class="kpis">
      <div class="kpi ${+p.min_stock > 0 && +p.stock <= +p.min_stock ? 'neg' : ''}"><div class="label">Estoque atual</div><div class="value">${onDemand ? '—' : qty(p.stock) + ' ' + esc(p.unit)}</div><div class="sub">${svc ? 'serviço: sem controle de estoque' : 'mínimo ' + qty(p.min_stock)}</div></div>
      <div class="kpi"><div class="label">Custo por ${esc(p.unit)}</div><div class="value">${cost(unitCost)}</div><div class="sub">${svc ? 'informado no cadastro' : 'custo médio'}</div></div>
      <div class="kpi"><div class="label">Preço de venda</div><div class="value">${money(p.sale_price)}</div><div class="sub">${p.markup !== null && p.markup !== undefined ? `automático: custo + ${qty(p.markup, 2)}%` : 'preço manual'}</div></div>
      <div class="kpi ${margin !== null && margin < 0 ? 'neg' : 'pos'}"><div class="label">Margem</div><div class="value">${margin === null ? '—' : pct(margin)}</div><div class="sub">${margin === null ? '' : 'lucro ' + money(p.sale_price - unitCost) + ' por ' + esc(p.unit)}</div></div>
      ${!onDemand ? `<div class="kpi"><div class="label">Valor em estoque</div><div class="value">${money(Math.max(0, p.stock) * p.avg_cost)}</div></div>` : ''}
    </div>
    ${p.description ? `<div class="card"><div class="card-head"><h2>Observações</h2></div><div class="prod-desc">${esc(p.description)}</div></div>` : ''}
    ${!svc ? `<div class="card"><div class="card-head"><h2>Referências cruzadas</h2></div>${(p.refs || []).length
      ? `<div class="ref-chips">${p.refs.map((r) => `<span class="ref-chip">${r.manufacturer ? `<span class="muted">${esc(r.manufacturer)}</span> ` : ''}<strong>${esc(r.code)}</strong></span>`).join('')}</div>`
      : '<p class="muted" style="margin:0">Nenhuma. Cadastre códigos equivalentes de outros fabricantes em Editar → Referências cruzadas.</p>'}</div>` : ''}
    ${!svc ? fiscalCard(p.fiscal) : ''}
    <div class="card"><div class="card-head"><h2>Histórico</h2><a class="small" href="#/estoque" id="allmov">ver tudo</a></div>
      ${p.movements.length ? `<div class="table-wrap"><table><thead><tr><th>Data</th><th>Tipo</th><th class="num">Qtd.</th><th class="num">Custo unit.</th><th class="num">Saldo</th><th>Obs.</th><th>Por</th></tr></thead><tbody>
      ${p.movements.map((x) => `<tr><td class="nowrap">${dt(x.created_at)}</td><td>${movLabel(x.type)}</td><td class="num ${+x.quantity >= 0 ? 'qty-pos' : 'qty-neg'}">${x.type === 'ajuste_custo' ? '—' : (+x.quantity > 0 ? '+' : '') + qty(x.quantity)}</td><td class="num">${cost(x.unit_cost)}</td><td class="num">${qty(x.balance_after)}</td><td class="small">${x.sale_id ? `<a href="#/venda/${x.sale_id}">${esc(x.note || 'venda')}</a>` : x.invoice_id ? `<a href="#/nota/${x.invoice_id}">${esc(x.note || 'nota')}</a>` : esc(x.note || '')}</td><td class="small muted">${esc(x.user_name || '')}</td></tr>`).join('')}
      </tbody></table></div>` : '<p class="muted">Nenhuma movimentação ainda.</p>'}</div>`;
  v.addEventListener('click', (e) => { const r = e.target.closest('[data-go]'); if (r) go(r.dataset.go); });
  $('#allmov', v).onclick = (e) => { e.preventDefault(); sessionStorage.setItem('movProduct', JSON.stringify({ id: p.id, name: p.name })); go('estoque'); };
  if ($('#edit', v)) $('#edit', v).onclick = () => productForm(p, () => route());
  if ($('#mov', v)) $('#mov', v).onclick = () => movementForm(p, () => route());
  if ($('#del', v)) $('#del', v).onclick = async (e) => {
    try { if (await deleteProduct(p)) go(svc ? 'servicos' : 'produtos'); } catch (err) { fail(err); }
  };
  if ($('#toggle', v)) $('#toggle', v).onclick = async (e) => {
    if (+p.active && !(await confirmBox(`Desativar "${p.name}"? Ele some das listas, mas o histórico é mantido.`, { ok: 'Desativar', danger: true }))) return;
    busy(e.target, async () => { await api('product_save', { body: { ...p, active: !+p.active } }); invalidateProducts(); toast('Produto atualizado'); route(); });
  };
}

/** Exclui produto/serviço. Os já usados ficam guardados no histórico (vendas, orçamentos, notas). */
async function deleteProduct(p, onDone) {
  const word = p.type === 'servico' ? 'o serviço' : 'o produto';
  if (!(await confirmBox(`Excluir ${word} "${p.name}"? Ele sai do cadastro e de todas as listas. Orçamentos, vendas e notas que já usaram este item continuam mostrando como era.`, { ok: 'Excluir', danger: true }))) return false;
  const r = await api('product_delete', { body: { id: p.id } });
  invalidateProducts();
  toast(r.result === 'apagado' ? 'Excluído' : 'Excluído do cadastro (mantido nos orçamentos, vendas e notas antigos)');
  onDone && onDone();
  return true;
}

// ============ Movimentações ============
function movementForm(preset, onDone) {
  const m = modal({
    title: 'Movimentar estoque',
    body: `<form class="form" id="mf">
      <label>Produto <input name="product" placeholder="Busque o produto" required></label>
      <div class="seg" id="kind"><button type="button" data-k="entrada" class="on">Entrada</button><button type="button" data-k="saida">Saída / uso</button><button type="button" data-k="perda">Perda</button><button type="button" data-k="ajuste">Contagem</button></div>
      <p class="help" id="khelp" style="margin:0"></p>
      <div class="row">
        <label data-for="entrada saida perda">Quantidade <span class="inline"><input name="quantity" inputmode="decimal" style="width:130px"> <span class="u muted small"></span></span></label>
        <label data-for="entrada">Valor total pago (R$) <input name="total_cost" inputmode="decimal" placeholder="0,00"></label>
        <label data-for="ajuste">Quantidade contada <span class="inline"><input name="counted" inputmode="decimal" style="width:130px"> <span class="u muted small"></span></span><span class="help cur"></span></label>
      </div>
      <label>Observação <input name="note" maxlength="255" placeholder="opcional"></label>
    </form>`,
    foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="save">Lançar</button>`,
  });
  const f = $('#mf', m.el);
  let kind = 'entrada', prod = preset || null;
  const help = { entrada: 'Compra ou recebimento sem nota fiscal. O custo entra no custo médio.', saida: 'Uso interno, amostra, consumo que não é venda.', perda: 'Produto vencido, quebrado ou descartado. Aparece nos relatórios como perda.', ajuste: 'Informe quanto tem de verdade na prateleira. O sistema ajusta a diferença.' };
  const paint = () => {
    $$('#kind button', m.el).forEach((b) => b.classList.toggle('on', b.dataset.k === kind));
    $$('[data-for]', m.el).forEach((l) => (l.hidden = !l.dataset.for.split(' ').includes(kind)));
    $('#khelp', m.el).textContent = help[kind];
    $$('.u', m.el).forEach((u) => (u.textContent = prod ? prod.unit : ''));
    $('.cur', m.el).textContent = prod ? `No sistema: ${qty(prod.stock)} ${prod.unit}` : '';
  };
  $('#kind', m.el).onclick = (e) => { const b = e.target.closest('button'); if (b) { kind = b.dataset.k; paint(); } };
  const picker = productPicker(f.elements.product, { filter: (x) => x.type !== 'servico', onPick: (x) => { prod = x; paint(); } });
  if (preset) picker.set(preset);
  paint();
  $('#save', m.el).onclick = (e) => busy(e.target, async () => {
    if (!prod || !f.elements.product.dataset.id) throw new Error('Escolha o produto na lista');
    const d = formData(f);
    await api('movement', { body: { product_id: prod.id, kind, quantity: d.quantity, total_cost: d.total_cost, counted: d.counted, note: d.note } });
    invalidateProducts(); m.close(); toast('Movimentação lançada'); onDone && onDone();
  });
}

async function viewMovements(v) {
  const pre = JSON.parse(sessionStorage.getItem('movProduct') || 'null'); sessionStorage.removeItem('movProduct');
  const f = { product_id: pre?.id || '', type: '', from: '', to: '', page: 1 };
  v.innerHTML = `<div class="page-head"><div><h1>Movimentações</h1><p class="muted">Tudo o que entrou e saiu do estoque.</p></div>
    <div class="actions"><button class="btn primary" id="new">${icon('plus')}Nova movimentação</button></div></div>
    <div class="card"><div class="filters">
      <label class="grow">Produto <input id="fp" placeholder="Todos" value="${esc(pre?.name || '')}"></label>
      <label>Tipo <select id="ft"><option value="">Todos</option>${Object.entries(state.meta.movement_labels).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></label>
      <label>De <input type="date" id="ff"></label><label>Até <input type="date" id="fto"></label>
    </div><div id="list"></div></div>`;
  const load = async () => {
    const d = await api('movements', { params: { product_id: f.product_id, type: f.type, from: f.from, to: f.to, page: f.page, limit: 50 } });
    const list = $('#list', v);
    list.innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Data</th><th>Produto</th><th>Tipo</th><th class="num">Qtd.</th><th class="num">Custo unit.</th><th class="num">Saldo</th><th>Obs.</th><th>Por</th></tr></thead><tbody>
      ${d.rows.map((x) => `<tr><td class="nowrap">${dt(x.created_at)}</td><td><a href="#/produto/${x.product_id}">${esc(x.product_name)}</a></td><td>${movLabel(x.type)}</td><td class="num ${+x.quantity >= 0 ? 'qty-pos' : 'qty-neg'}">${x.type === 'ajuste_custo' ? '—' : `${+x.quantity > 0 ? '+' : ''}${qty(x.quantity)} ${esc(x.unit)}`}</td><td class="num">${cost(x.unit_cost)}</td><td class="num">${qty(x.balance_after)}</td><td class="small">${x.sale_id ? `<a href="#/venda/${x.sale_id}">${esc(x.note || 'venda')}</a>` : x.invoice_id ? `<a href="#/nota/${x.invoice_id}">${esc(x.note || 'nota')}</a>` : esc(x.note || '')}</td><td class="small muted">${esc(x.user_name || '')}</td></tr>`).join('')}
      </tbody></table></div>` : '<div class="empty">Nenhuma movimentação encontrada.</div>';
    if (d.total > 50) list.appendChild(pager(d.total, f.page, 50, (p) => { f.page = p; load(); }));
  };
  productPicker($('#fp', v), { onPick: (x) => { f.product_id = x.id; f.page = 1; load(); } });
  $('#fp', v).addEventListener('input', (e) => { if (!e.target.value) { f.product_id = ''; f.page = 1; load(); } });
  $('#ft', v).onchange = (e) => { f.type = e.target.value; f.page = 1; load(); };
  $('#ff', v).onchange = (e) => { f.from = e.target.value; f.page = 1; load(); };
  $('#fto', v).onchange = (e) => { f.to = e.target.value; f.page = 1; load(); };
  $('#new', v).onclick = () => movementForm(null, load);
  await load();
}

// ============ Vendas ============
/** Configurações de comportamento (Configurações → Vendas / Notas fiscais / Ordens de compra). */
const BH = () => (state.meta && state.meta.behavior) || {};
/** Se o usuário pode cancelar a venda (Configurações → Vendas; administrador sempre pode). */
function canCancelSale(s) {
  if (s.status !== 'ok') return false;
  if (isAdmin()) return true;
  const b = BH();
  if (b.sale_cancel_admin === '1') return false;
  const days = +b.sale_cancel_days || 0;
  return !days || String(s.created_at).slice(0, 10) >= shiftDate(today(), -days);
}

async function viewNewSale(v) {
  const cart = [];
  let method = '', customer = null, inst = null;
  v.innerHTML = `<div class="page-head"><div><h1>Nova venda</h1></div></div>
    <div class="grid two sale-grid">
      <div>
      <div class="card"><div class="card-head"><h2>Cliente</h2><span class="actions">${can('clientes') ? `<button type="button" class="btn" id="newcust">${icon('plus')}Cadastrar cliente</button>` : ''}<button type="button" class="btn primary" id="pickcust">${icon('search')}Selecionar cliente</button></span></div>
        <div id="custbox"></div></div>
      <div class="card"><div class="card-head"><h2>Produtos</h2><span class="actions">${can('produtos') ? `<button type="button" class="btn" id="newprod">${icon('plus')}Cadastrar produto</button>` : ''}<button type="button" class="btn primary" id="add">${icon('plus')}Incluir produto</button></span></div>
        <div id="cart" data-kind="produto"></div></div>
      <div class="card"><div class="card-head"><h2>Serviços</h2><span class="actions">${can('servicos') ? `<button type="button" class="btn" id="newserv">${icon('plus')}Cadastrar serviço</button>` : ''}<button type="button" class="btn primary" id="addserv">${icon('plus')}Incluir serviço</button></span></div>
        <div id="cartserv" data-kind="servico"></div></div>
      <div class="card"><div class="card-head"><h2>Custos adicionais</h2><button type="button" class="btn primary" id="addextra">${icon('plus')}Incluir custo</button></div>
        <div id="extras"></div></div>
      <div class="card"><h2>Observações</h2>
        <div class="form"><textarea id="note" rows="3" maxlength="2000" aria-label="Observações" placeholder="Opcional. Ex.: combinado de entrega, número do pedido do cliente…"></textarea></div></div>
      </div>
      <div class="card sale-pay"><h2>Pagamento</h2>
        <div class="pay-grid" id="pay">${Object.entries(state.meta.payment_methods).map(([k, l]) => `<button type="button" data-m="${k}">${esc(l)}</button>`).join('')}</div>
        <div class="form" style="margin-top:12px"><label>Condição de pagamento <input id="sterms" list="sterms-dl" data-create="term" maxlength="160" placeholder="Escolha ou digite uma nova (opcional)">${termsList('sterms-dl')}</label></div>
        <div id="inst" class="form" style="margin-top:12px"></div>
        <div class="form" style="margin-top:14px">
          <label>Desconto (R$) <input id="disc" inputmode="decimal" placeholder="0,00">${+BH().sale_max_discount > 0 && !isAdmin() ? `<span class="help">Máximo de ${qty(BH().sale_max_discount, 2)}% do valor da venda.</span>` : ''}</label>
        </div>
        <div class="cart-total" style="margin:16px 0" id="tot"></div>
        <button class="btn primary lg" style="width:100%" id="finish">Finalizar venda</button>
      </div></div>`;
  const extras = []; // custos adicionais: { d: descrição, v: valor }
  const isServ = (p) => p.type === 'servico';
  // Produtos e serviços ficam no mesmo carrinho; cada quadro mostra só os seus (data-i = posição no carrinho)
  const paintKind = (box, serv) => {
    const rows = cart.map((c, i) => [c, i]).filter(([c]) => isServ(c.p) === serv);
    box.innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th>${serv ? 'Serviço' : 'Produto'}</th><th>Qtd.</th><th>Preço unit.</th><th class="num">Total</th><th></th></tr></thead><tbody>
      ${rows.map(([c, i]) => `<tr data-i="${i}"><td>${esc(c.p.name)}${serv ? '' : `<div class="small muted">estoque: ${qty(c.p.stock)} ${esc(c.p.unit)}</div>`}</td>
      <td><input class="qty-input" data-f="q" inputmode="decimal" value="${qty(c.q, 4)}"></td><td><input class="price-input" data-f="price" inputmode="decimal" value="${qty(c.price, 2)}"></td>
      <td class="num">${money(c.q * c.price)}</td><td><button class="icon-btn" data-rm aria-label="Remover">${icon('trash')}</button></td></tr>`).join('')}</tbody></table></div>`
      : `<div class="empty">Nenhum ${serv ? 'serviço' : 'produto'} ainda. Clique em <strong>Incluir ${serv ? 'serviço' : 'produto'}</strong>.</div>`;
  };
  const paint = () => { paintKind($('#cart', v), false); paintKind($('#cartserv', v), true); paintTotals(); };
  const paintExtras = () => {
    $('#extras', v).innerHTML = extras.length ? `<div class="table-wrap"><table><thead><tr><th>Custo adicional</th><th>Valor (R$)</th><th></th></tr></thead><tbody>
      ${extras.map((x, i) => `<tr data-e="${i}"><td><input data-f="d" value="${esc(x.d)}" maxlength="200" placeholder="Ex.: frete, embalagem, deslocamento"></td>
      <td><input class="price-input" data-f="v" inputmode="decimal" value="${x.v ? qty(x.v, 2) : ''}" placeholder="0,00"></td><td><button class="icon-btn" data-rme aria-label="Remover">${icon('trash')}</button></td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhum custo adicional. Use para frete, deslocamento, embalagem…</div>';
    paintTotals();
  };
  const paintTotals = () => {
    const line = (c) => Math.round(c.q * c.price * 100) / 100;
    const sp = cart.filter((c) => !isServ(c.p)).reduce((s, c) => s + line(c), 0), ss = cart.filter((c) => isServ(c.p)).reduce((s, c) => s + line(c), 0);
    const se = extras.reduce((s, x) => s + (x.v || 0), 0), disc = num($('#disc', v).value), total = sp + ss + se - disc;
    const costT = cart.reduce((s, c) => s + c.q * (+c.p.avg_cost || 0), 0);
    if (inst) inst.setTotal(total);
    $('#tot', v).innerHTML = `<div><span>Produtos</span><span>${money(sp)}</span></div>${ss ? `<div><span>Serviços</span><span>${money(ss)}</span></div>` : ''}${se ? `<div><span>Custos adicionais</span><span>+ ${money(se)}</span></div>` : ''}${disc ? `<div><span>Desconto</span><span>− ${money(disc)}</span></div>` : ''}
      <div class="big"><span>Total</span><span>${money(total)}</span></div><div class="small muted"><span>Lucro estimado</span><span>${money(total - costT)}</span></div>`;
  };
  const paintCustomer = () => {
    $('#custbox', v).innerHTML = customer
      ? `<div class="cust-card"><div><div class="cust-name">${esc(custName(customer))}</div>
          <div class="small muted">${[customer.trade_name ? customer.name : '', docFmt(customer.document), [customer.city, customer.state].filter(Boolean).join('/'), customer.phone].filter(Boolean).map(esc).join(' · ') || 'Sem documento'}</div></div>
          <button type="button" class="btn sm ghost" id="clearcust">Trocar</button></div>`
      : '<div class="empty" style="padding:14px">Nenhum cliente selecionado. Clique em <strong>Selecionar cliente</strong>.</div>';
    const c = $('#clearcust', v); if (c) c.onclick = () => $('#pickcust', v).click();
  };
  const setCustomer = (c) => { customer = c; paintCustomer(); };
  $('#pickcust', v).onclick = () => customerBrowser({ selected: customer, onSelect: setCustomer });
  if ($('#newcust', v)) $('#newcust', v).onclick = () => customerForm(null, async (id) => setCustomer(await api('customer', { params: { id } })));
  const preset = JSON.parse(sessionStorage.getItem('saleCustomer') || 'null'); sessionStorage.removeItem('saleCustomer');
  if (preset) setCustomer(preset); else if (BH().sale_customer) setCustomer(BH().sale_customer); else paintCustomer();
  if (BH().sale_default_notes) $('#note', v).value = BH().sale_default_notes;
  const addToCart = (p) => { const ex = cart.find((c) => c.p.id === p.id); if (ex) ex.q += 1; else cart.push({ p, q: 1, price: +p.sale_price }); paint(); };
  $('#add', v).onclick = () => productBrowser({ cart, onAdd: addToCart, filter: (p) => !isServ(p), checkStock: BH().sale_negative_stock !== '1' });
  $('#addserv', v).onclick = () => productBrowser({ cart, onAdd: addToCart, filter: isServ, checkStock: false, title: 'Incluir serviço',
    allowNew: can('servicos'), onNew: can('servicos') ? (cb) => serviceForm(null, cb) : null });
  if ($('#newprod', v)) $('#newprod', v).onclick = () => productForm({ type: 'produto', unit: 'un' }, () => { invalidateProducts(); toast('Agora inclua o produto na venda'); });
  if ($('#newserv', v)) $('#newserv', v).onclick = () => serviceForm(null, async (pid) => { const p = (await products(true)).find((x) => x.id == pid); if (p && isServ(p)) addToCart(p); });
  // Atualiza só os totais ao digitar (não recria os campos para não perder o foco)
  ['#cart', '#cartserv'].forEach((sel) => {
    $(sel, v).addEventListener('input', (e) => { const tr = e.target.closest('tr[data-i]'); if (!tr || !e.target.dataset.f) return; const c = cart[+tr.dataset.i]; if (e.target.dataset.f === 'q') c.q = Math.max(0, num(e.target.value)); else c.price = Math.max(0, num(e.target.value)); $('td.num', tr).textContent = money(c.q * c.price); paintTotals(); });
    $(sel, v).addEventListener('click', (e) => { if (e.target.closest('[data-rm]')) { cart.splice(+e.target.closest('tr').dataset.i, 1); paint(); } });
  });
  $('#addextra', v).onclick = () => { extras.push({ d: '', v: 0 }); paintExtras(); const ins = $$('#extras input[data-f=d]', v); ins[ins.length - 1].focus(); };
  $('#extras', v).addEventListener('input', (e) => { const tr = e.target.closest('tr[data-e]'); if (!tr || !e.target.dataset.f) return; const x = extras[+tr.dataset.e]; if (e.target.dataset.f === 'd') x.d = e.target.value; else x.v = Math.max(0, num(e.target.value)); paintTotals(); });
  $('#extras', v).addEventListener('focusout', (e) => { if (e.target.dataset.f === 'v' && e.target.value.trim()) e.target.value = qty(num(e.target.value), 2); });
  $('#extras', v).addEventListener('click', (e) => { if (e.target.closest('[data-rme]')) { extras.splice(+e.target.closest('tr').dataset.e, 1); paintExtras(); } });
  $('#disc', v).oninput = paintTotals;
  inst = installmentBox($('#inst', v));
  $('#sterms', v).addEventListener('change', (e) => applyTermToInstallments(e.target.value, inst, method ? [method] : []));
  $('#sterms', v).addEventListener('focusout', (e) => applyTermToInstallments(e.target.value, inst, method ? [method] : [], true));
  $('#pay', v).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; method = b.dataset.m; $$('#pay button', v).forEach((x) => x.classList.toggle('on', x === b)); inst.update({ codes: [method] }); applyTermToInstallments($('#sterms', v).value, inst, [method]); paintTotals(); };
  // Forma de pagamento padrão (Configurações → Vendas)
  const defPm = BH().sale_default_method; const defBtn = defPm && $(`#pay button[data-m="${defPm}"]`, v); if (defBtn) defBtn.click();
  $('#finish', v).onclick = (e) => busy(e.target, async () => {
    if (!customer) throw new Error('Selecione o cliente da venda');
    if (!cart.length) throw new Error('Adicione pelo menos um item');
    if (!method) throw new Error('Escolha a forma de pagamento');
    const r = await api('sale_create', { body: { payment_method: method, ...inst.get(), payment_terms: $('#sterms', v).value, discount: $('#disc', v).value, customer_id: customer.id, note: $('#note', v).value, items: cart.map((c) => ({ product_id: c.p.id, quantity: c.q, unit_price: c.price })), extras: extras.map((x) => ({ description: x.d, amount: x.v })) } });
    invalidateProducts(); toast(`Venda #${r.id} registrada`); go('venda/' + r.id);
  });
  paint(); paintExtras();
}

/** Janela "Incluir produto": lista paginada com busca, ordenação e seleção múltipla. */
async function productBrowser({ cart, onAdd, filter = () => true, checkStock = true, title = 'Incluir produto', newLabel = 'Cadastrar', onNew, showCost = false, allowNew = true }) {
  const PER = 30;
  const m = modal({
    title, wide: true,
    body: `<label class="pb-search">Pesquisar <input id="pbq" type="search" placeholder="Pesquisar produto" autocomplete="off"></label>
      <div class="table-wrap pb-wrap"><table class="pb-table"><thead><tr>
        <th class="pb-chk"><input type="checkbox" id="pball" aria-label="Selecionar todos desta página"></th>
        <th><button type="button" class="pb-sort" id="pbsort">Produto <span id="pbarrow">▲</span></button></th>
        <th class="num">${showCost ? 'Custo<span class="hide-m"> médio</span>' : 'Valor<span class="hide-m"> unitário</span>'}</th><th class="num"><span class="hide-m">Em </span>estoque</th></tr></thead><tbody id="pbl"><tr><td colspan="4" class="muted">Carregando…</td></tr></tbody></table></div>
      <div class="pager"><span id="pbinfo"></span><span class="actions" id="pbpages"></span></div>`,
    foot: `<button class="btn" data-close>${icon('x')}Cancelar</button><button class="btn" id="pbnew" ${allowNew ? '' : 'hidden'}>${icon('plus')}${esc(newLabel)}<span class="hide-m"> ${title.includes('serviço') ? 'serviço' : 'produto'}</span></button><button class="btn primary" id="pbok" disabled>Incluir</button>`,
  });
  let list = [], page = 1, asc = true;
  const sel = new Set();
  const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const onDemand = (p) => p.type === 'servico';
  const inCart = (p) => cart.find((c) => c.p.id === p.id)?.q || 0;
  const available = (p) => !checkStock || onDemand(p) || +p.stock - inCart(p) > 0;
  const load = async () => { list = (await products(true)).filter(filter); };
  const filtered = () => {
    const t = norm($('#pbq', m.el).value.trim());
    return list.filter((p) => !t || norm(p.name).includes(t) || norm(p.category).includes(t) || norm(p.brand).includes(t) || productCodeMatch(p, $('#pbq', m.el).value))
      .sort((x, y) => x.name.localeCompare(y.name, 'pt-BR') * (asc ? 1 : -1));
  };
  const paint = () => {
    const rows = filtered(), total = rows.length, pages = Math.max(1, Math.ceil(total / PER));
    page = Math.min(page, pages);
    const slice = rows.slice((page - 1) * PER, page * PER);
    $('#pbl', m.el).innerHTML = slice.length ? slice.map((p) => {
      const ok = available(p), n = inCart(p);
      return `<tr class="${ok ? 'click' : 'pb-off'} ${sel.has(p.id) ? 'pb-sel' : ''}" data-id="${p.id}">
        <td class="pb-chk"><input type="checkbox" ${sel.has(p.id) ? 'checked' : ''} ${ok ? '' : 'disabled'} aria-label="Selecionar ${esc(p.name)}"></td>
        <td><div class="pb-name">${esc(p.name)}</div><div class="small muted">${esc([[p.brand, p.mfr_code].filter(Boolean).join(' '), p.category, p.sku, p.barcode].filter(Boolean).join(' · '))}${n ? ` <span class="badge accent">${qty(n)} ${showCost ? 'no pedido' : 'no carrinho'}</span>` : ''}</div></td>
        <td class="num">${showCost ? cost(p.avg_cost) : money(p.sale_price)}</td>
        <td class="num">${onDemand(p) ? '<span class="muted small">serviço</span>' : `<span class="${ok ? '' : 'stock-low'}">${qty(p.stock)} ${esc(p.unit)}</span>`}</td></tr>`;
    }).join('') : `<tr><td colspan="4" class="empty">${list.length ? 'Nenhum item encontrado.' : 'Nada cadastrado ainda. Use o botão Cadastrar.'}</td></tr>`;
    const selectable = slice.filter(available);
    const all = $('#pball', m.el);
    all.checked = selectable.length > 0 && selectable.every((p) => sel.has(p.id));
    all.indeterminate = !all.checked && selectable.some((p) => sel.has(p.id));
    all.disabled = !selectable.length;
    $('#pbinfo', m.el).textContent = total ? `Mostrando de ${(page - 1) * PER + 1} até ${Math.min(page * PER, total)} de ${total} registro${total === 1 ? '' : 's'}` : '';
    // Paginação: Anterior 1 2 3 … N Próximo
    const nums = [];
    for (let i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - page) <= 2) nums.push(i); else if (nums[nums.length - 1] !== '…') nums.push('…');
    $('#pbpages', m.el).innerHTML = pages > 1 ? `<button class="btn sm" data-p="${page - 1}" ${page === 1 ? 'disabled' : ''}>Anterior</button>${nums.map((n) => n === '…' ? '<span class="muted">…</span>' : `<button class="btn sm ${n === page ? 'primary' : ''}" data-p="${n}">${n}</button>`).join('')}<button class="btn sm" data-p="${page + 1}" ${page === pages ? 'disabled' : ''}>Próximo</button>` : '';
    $('#pbarrow', m.el).textContent = asc ? '▲' : '▼';
    const ok = $('#pbok', m.el);
    ok.disabled = !sel.size;
    ok.innerHTML = sel.size ? `Incluir (${sel.size})` : 'Incluir';
  };
  const toggle = (id, on) => { if (on) sel.add(id); else sel.delete(id); };
  $('#pbq', m.el).addEventListener('input', () => { page = 1; paint(); });
  // Leitor de código de barras: Enter com código exato inclui direto
  $('#pbq', m.el).addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const t = e.target.value.trim();
    const p = list.find((x) => x.barcode && x.barcode === t);
    if (p && available(p)) { onAdd(p); e.target.value = ''; page = 1; paint(); toast(`${p.name} incluído`); }
  });
  $('#pbsort', m.el).onclick = () => { asc = !asc; paint(); };
  $('#pball', m.el).onchange = (e) => { const rows = filtered().slice((page - 1) * PER, page * PER).filter(available); rows.forEach((p) => toggle(p.id, e.target.checked)); paint(); };
  $('#pbl', m.el).addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]'); if (!tr || tr.classList.contains('pb-off')) return;
    const id = +tr.dataset.id;
    toggle(id, e.target.matches('input[type=checkbox]') ? e.target.checked : !sel.has(id));
    paint();
  });
  $('#pbpages', m.el).addEventListener('click', (e) => { const b = e.target.closest('[data-p]'); if (b && !b.disabled) { page = +b.dataset.p; paint(); $('.pb-wrap', m.el).scrollTop = 0; } });
  $('#pbok', m.el).onclick = () => {
    list.filter((p) => sel.has(p.id)).sort((x, y) => x.name.localeCompare(y.name, 'pt-BR')).forEach(onAdd);
    const what = title.includes('serviço') ? ['serviço incluído', 'serviços incluídos'] : ['produto incluído', 'produtos incluídos'];
    toast(sel.size === 1 ? `1 ${what[0]}` : `${sel.size} ${what[1]}`);
    m.close();
  };
  $('#pbnew', m.el).onclick = () => (onNew || ((cb) => productForm(null, cb)))(async (id) => {
    await load();
    const p = list.find((x) => x.id == id);
    if (p) { $('#pbq', m.el).value = ''; page = 1; if (available(p)) sel.add(p.id); }
    paint();
  });
  try { await load(); } catch (e) { fail(e); m.close(); return; }
  paint();
  if (window.innerWidth > 640) $('#pbq', m.el).focus();
}

async function viewSales(v) {
  const def = { from: shiftDate(today(), -29), to: today() };
  const f = { ...def, customer: '', status: '', min: '', max: '', user_id: '', page: 1 };
  const team = await api('team');
  v.innerHTML = `<div class="page-head"><div><h1>Vendas</h1></div><div class="actions"><a class="btn primary" href="#/vendas/nova">${icon('plus')}Nova venda</a></div></div>
    <div class="card">${filterPanelHtml({ statuses: [['ok', 'Concluídas'], ['cancelada', 'Canceladas']], team, f, userLabel: 'Vendedor', customerHint: 'Nome, fantasia ou CNPJ/CPF' })}
      <div class="inline" style="justify-content:space-between;margin:4px 0 10px"><strong id="totals"></strong></div><div id="list"></div></div>`;
  const load = async () => {
    const d = await api('sales', { params: { ...filterParams(f), page: f.page } });
    $('#totals', v).textContent = `${d.total} venda${d.total === 1 ? '' : 's'} · ${money(d.sum)}${f.status === 'cancelada' ? '' : ' em vendas concluídas'}`;
    const list = $('#list', v);
    list.innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>#</th><th>Data</th><th>Cliente</th><th>Pagamento</th><th class="num">Itens</th><th class="num">Total</th><th class="num">Lucro</th><th>Vendedor</th></tr></thead><tbody>
      ${d.rows.map((s) => `<tr class="click" data-id="${s.id}"><td>${s.id}</td><td class="nowrap">${dt(s.created_at)}</td><td>${esc(s.customer || '—')}</td><td>${esc(payLabel(s.payment_method))}</td><td class="num">${s.items}</td>
      <td class="num">${s.status === 'cancelada' ? `<s class="muted">${money(s.total)}</s> <span class="badge err">cancelada</span>` : money(s.total)}</td><td class="num">${s.status === 'cancelada' ? '—' : money(s.total - s.cost_total)}</td><td class="small muted">${esc(s.user_name || '')}</td></tr>`).join('')}</tbody></table></div>`
      : `<div class="empty">Nenhuma venda encontrada${filterCount(f, def) ? ' com esses filtros' : ' no período'}.</div>`;
    if (d.total > 50) list.appendChild(pager(d.total, f.page, 50, (p) => { f.page = p; load(); }));
  };
  bindFilterPanel($('.adv-filters', v), f, () => { f.page = 1; load(); }, def);
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('venda/' + r.dataset.id); };
  await load();
}

async function viewSale(v, id) {
  const s = await api('sale', { params: { id } });
  const profit = s.total - s.cost_total;
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/vendas">← Vendas</a></p><h1>Venda #${s.id} ${s.status === 'cancelada' ? '<span class="badge err">cancelada</span>' : ''}</h1>${s.quote_id ? `<p class="small" style="margin:0">Gerada a partir do <a href="#/orcamento/${s.quote_id}">orçamento #${s.quote_id}</a></p>` : ''}
    <p class="muted">${dt(s.created_at)} · ${esc(payLabel(s.payment_method))}${+s.installments > 1 || +s.installment_entry ? ' · ' + esc(instText(s.installments, s.installment_days, s.installment_entry)) : ''}${s.payment_terms ? ' · ' + esc(s.payment_terms) : ''} · por ${esc(s.user_name || '—')}</p></div>
    <div class="actions"><a class="btn" href="#/vendas/nova">Nova venda</a>${canCancelSale(s) ? '<button class="btn danger" id="cancel">Cancelar venda</button>' : ''}</div></div>
    ${s.status === 'cancelada' ? `<div class="alert err">Cancelada em ${dt(s.canceled_at)} por ${esc(s.canceled_by_name || '—')}. Os itens voltaram para o estoque.</div>` : ''}
    <div class="kpis"><div class="kpi"><div class="label">Total</div><div class="value">${money(s.total)}</div>${+s.discount ? `<div class="sub">desconto ${money(s.discount)}</div>` : ''}</div>
      <div class="kpi"><div class="label">Custo</div><div class="value">${money(s.cost_total)}</div></div>
      <div class="kpi ${profit >= 0 ? 'pos' : 'neg'}"><div class="label">Lucro bruto</div><div class="value">${money(profit)}</div><div class="sub">margem ${pct(s.total > 0 ? (profit / s.total) * 100 : 0)}</div></div></div>
    <div class="card"><div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qtd.</th><th class="num">Preço unit.</th><th class="num">Custo unit.</th><th class="num">Total</th></tr></thead><tbody>
      ${s.items.map((i) => `<tr><td><a href="#/produto/${i.product_id}">${esc(i.name)}</a>${i.type === 'servico' ? ' <span class="muted small">(serviço)</span>' : ''}</td><td class="num">${qty(i.quantity)} ${esc(i.unit)}</td><td class="num">${money(i.unit_price)}</td><td class="num">${cost(i.unit_cost)}</td><td class="num">${money(i.total)}</td></tr>`).join('')}
      </tbody><tfoot><tr><td colspan="4">Subtotal</td><td class="num">${money(s.subtotal)}</td></tr>${+s.discount ? `<tr><td colspan="4">Desconto</td><td class="num">− ${money(s.discount)}</td></tr>` : ''}${s.extras.length ? s.extras.map((x) => `<tr><td colspan="4">${esc(x.description)} <span class="muted small">(custo adicional)</span></td><td class="num">+ ${money(x.amount)}</td></tr>`).join('') : +s.surcharge ? `<tr><td colspan="4">Acréscimos (custos adicionais)</td><td class="num">+ ${money(s.surcharge)}</td></tr>` : ''}<tr><td colspan="4">Total</td><td class="num">${money(s.total)}</td></tr></tfoot></table></div>
      ${s.customer || s.note ? `<dl class="dl" style="margin-top:14px">${s.customer ? `<dt>Cliente</dt><dd>${s.customer_id ? `<a href="#/cliente/${s.customer_id}">${esc(s.customer_trade_name || s.customer_name || s.customer)}</a>` : esc(s.customer)}${s.customer_document ? ` <span class="muted small">${docFmt(s.customer_document)}</span>` : ''}</dd>` : ''}${s.note ? `<dt>Observações</dt><dd style="white-space:pre-line">${esc(s.note)}</dd>` : ''}</dl>` : ''}</div>`;
  if ($('#cancel', v)) $('#cancel', v).onclick = async (e) => {
    if (!(await confirmBox('Cancelar esta venda? Os itens voltam para o estoque e ela sai dos relatórios.', { ok: 'Cancelar venda', danger: true }))) return;
    busy(e.target, async () => { await api('sale_cancel', { body: { id: s.id } }); invalidateProducts(); toast('Venda cancelada'); route(); });
  };
}

// ============ Notas fiscais ============
const KIND_LABEL = { nfe: 'NF-e (XML)', nfce: 'NFC-e', danfe: 'DANFE (PDF)', manual: 'Manual' };

async function viewInvoices(v) {
  let page = 1;
  v.innerHTML = `<div class="page-head"><div><h1>Notas fiscais</h1><p class="muted">Notas de compra que deram entrada no estoque.</p></div>
    <div class="actions"><a class="btn primary" href="#/notas/importar">${icon('upload')}Importar nota</a></div></div><div class="card" id="list"></div>`;
  const load = async () => {
    const d = await api('invoices', { params: { page, limit: 50 } });
    const list = $('#list', v);
    list.innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Data</th><th>Fornecedor</th><th>Nota</th><th>Origem</th><th class="num">Itens</th><th class="num">Total</th><th>Por</th></tr></thead><tbody>
      ${d.rows.map((i) => `<tr class="click" data-id="${i.id}"><td class="nowrap">${dOnly(i.issued_at || i.created_at)}</td><td>${esc(i.supplier_name || '—')}<div class="small muted">${cnpjFmt(i.supplier_cnpj)}</div></td><td>${esc(i.number || 's/n')}${i.series ? ' / ' + esc(i.series) : ''}</td><td><span class="badge">${KIND_LABEL[i.kind] || i.kind}</span></td><td class="num">${i.items}</td><td class="num">${money(i.total)}</td><td class="small muted">${esc(i.user_name || '')}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhuma nota importada ainda.</div>';
    if (d.total > 50) list.appendChild(pager(d.total, page, 50, (p) => { page = p; load(); }));
  };
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('nota/' + r.dataset.id); };
  await load();
}

async function viewInvoice(v, id) {
  const i = await api('invoice', { params: { id } });
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/notas">← Notas fiscais</a></p><h1>Nota ${esc(i.number || 's/n')} · ${esc(i.supplier_name || 'Fornecedor')}</h1>
    <p class="muted">${KIND_LABEL[i.kind] || ''} · importada em ${dt(i.created_at)} por ${esc(i.user_name || '—')}</p></div>
    <div class="actions">${isAdmin() ? '<button class="btn danger" id="rev">Desfazer entrada</button>' : ''}</div></div>
    <div class="card"><dl class="dl">
      <dt>Fornecedor</dt><dd>${i.supplier_id && can('fornecedores') ? `<a href="#/fornecedor/${i.supplier_id}">${esc(i.supplier_name || 'ver fornecedor')}</a>` : esc(i.supplier_name || '—')}</dd><dt>CNPJ</dt><dd>${cnpjFmt(i.supplier_cnpj) || '—'}</dd>
      <dt>Número / série</dt><dd>${esc(i.number || '—')} / ${esc(i.series || '—')}</dd><dt>Emissão</dt><dd>${dt(i.issued_at) || '—'}</dd>
      ${i.access_key ? `<dt>Chave de acesso</dt><dd class="mono">${esc(i.access_key.replace(/(\d{4})/g, '$1 '))}</dd>` : ''}<dt>Total</dt><dd>${money(i.total)}</dd></dl></div>
    <div class="card"><div class="table-wrap"><table><thead><tr><th>Item da nota</th><th>Produto no estoque</th><th class="num">Qtd. nota</th><th class="num">Entrou</th><th class="num">Custo unit.</th><th class="num">Total</th></tr></thead><tbody>
      ${i.items.map((x) => `<tr><td>${esc(x.description || '')}<div class="small muted">${esc(x.supplier_code || '')}</div></td><td><a href="#/produto/${x.product_id}">${esc(x.product_name)}</a></td><td class="num">${qty(x.invoice_qty)} ${esc(x.invoice_unit || '')}</td><td class="num">${qty(x.stock_qty)} ${esc(x.unit)}</td><td class="num">${cost(x.unit_cost)}</td><td class="num">${money(x.total)}</td></tr>`).join('')}
      </tbody></table></div></div>
    ${i.payables && i.payables.length ? `<div class="card"><div class="card-head"><h2>Contas a pagar desta nota</h2>${can('financeiro') ? '<a href="#/financeiro" class="small" id="seefin">ver no financeiro</a>' : ''}</div>
      <div class="table-wrap"><table><thead><tr><th>Parcela</th><th>Vencimento</th><th>Situação</th><th class="num">Valor</th></tr></thead><tbody>
      ${i.payables.map((p) => `<tr><td>${p.installment}/${p.installments}</td><td>${dOnly(p.due_date)}</td><td>${finBadge({ ...p, type: 'pagar' })}${p.status === 'pago' ? ` <span class="small muted">${dOnly(p.paid_at)}</span>` : ''}</td><td class="num">${money(p.status === 'pago' ? p.paid_amount : p.amount)}</td></tr>`).join('')}
      </tbody></table></div></div>` : ''}`;
  const sf = $('#seefin', v); if (sf) sf.onclick = (e) => { e.preventDefault(); sessionStorage.setItem('finFilter', JSON.stringify({ type: 'pagar', q: 'NF ' + (i.number || ''), from: '', to: '' })); go('financeiro'); };
  if ($('#rev', v)) $('#rev', v).onclick = async (e) => {
    if (!(await confirmBox('Desfazer a entrada desta nota? As quantidades saem do estoque, as parcelas em aberto saem de Contas a pagar e a nota é removida (você pode importá-la de novo).', { ok: 'Desfazer', danger: true }))) return;
    busy(e.target, async () => { await api('invoice_revert', { body: { id: i.id } }); invalidateProducts(); toast('Entrada da nota desfeita'); go('notas'); });
  };
}

async function viewImport(v) {
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/notas">← Notas fiscais</a></p><h1>Importar nota fiscal</h1>
    <p class="muted">O sistema lê a nota, você confere e confirma. A entrada no estoque é feita na hora.</p></div></div>
    <div class="seg" id="mode" style="margin-bottom:14px"><button class="on" data-m="file">XML ou PDF</button><button data-m="qr">Cupom (QR Code)</button><button data-m="manual">Digitar itens</button></div>
    <div id="pane"></div><div id="review"></div>`;
  const pane = $('#pane', v), review = $('#review', v);
  let stopCam = null;
  const show = (mode) => {
    if (stopCam) { stopCam(); stopCam = null; }
    $$('#mode button', v).forEach((b) => b.classList.toggle('on', b.dataset.m === mode));
    review.innerHTML = '';
    if (mode === 'file') {
      pane.innerHTML = `<div class="card"><label class="dropzone" id="dz"><input type="file" id="file" accept=".xml,.pdf,application/pdf,text/xml,application/xml" multiple hidden>
        <strong>Arraste o arquivo aqui ou clique para escolher</strong><span class="muted small">XML da NF-e/NFC-e (recomendado: leitura exata) ou PDF do DANFE. Pode escolher vários XML de uma vez.</span></label><div id="queue" class="small muted" style="margin-top:8px"></div></div>`;
      const dz = $('#dz', pane), input = $('#file', pane);
      const handle = (files) => processQueue([...files]);
      input.onchange = () => handle(input.files);
      dz.ondragover = (e) => { e.preventDefault(); dz.classList.add('over'); };
      dz.ondragleave = () => dz.classList.remove('over');
      dz.ondrop = (e) => { e.preventDefault(); dz.classList.remove('over'); handle(e.dataTransfer.files); };
    } else if (mode === 'qr') {
      pane.innerHTML = `<div class="card"><div class="grid two">
        <div><h3>Escanear com a câmera</h3><p class="muted small">Aponte para o QR Code do cupom fiscal.</p><div class="video-box" id="vb" hidden><video id="vid" playsinline muted></video><div class="frame"></div></div>
          <button class="btn" id="cam">${icon('camera')}Abrir câmera</button></div>
        <div><h3>Ou cole o link</h3><p class="muted small">Se você leu o QR Code com outro app, cole aqui o endereço que ele mostrou.</p>
          <div class="form"><input id="qrtext" placeholder="https://...sefaz...gov.br/...?p=..."><button class="btn primary" id="qrgo">Consultar</button></div></div></div>
        <p class="help" style="margin-bottom:0">A consulta é feita no portal da SEFAZ do estado. Alguns estados pedem captcha ou ficam fora do ar; nesse caso peça o XML ao vendedor ou use "Digitar itens".</p></div>`;
      $('#qrgo', pane).onclick = (e) => busy(e.target, () => parseQr($('#qrtext', pane).value));
      $('#cam', pane).onclick = async () => {
        try { stopCam = await startScanner($('#vid', pane), (text) => { stopCam && stopCam(); stopCam = null; $('#vb', pane).hidden = true; $('#qrtext', pane).value = text; busy($('#qrgo', pane), () => parseQr(text)); }); $('#vb', pane).hidden = false; $('#cam', pane).hidden = true; }
        catch (e) { fail(e); }
      };
    } else {
      pane.innerHTML = '';
      renderReview(review, { kind: 'manual', items: [], warnings: [], supplier_name: '', supplier_cnpj: '', number: '', series: '', issued_at: today() + ' 00:00:00', access_key: '' });
    }
  };
  const parseQr = async (text) => {
    if (!text.trim()) throw new Error('Cole o link do QR Code');
    const fd = new FormData(); fd.append('qr', text.trim());
    const inv = await api('invoice_parse', { form: fd });
    renderReview(review, inv);
  };
  const processQueue = async (files) => {
    const queue = $('#queue', pane);
    for (let n = 0; n < files.length; n++) {
      const file = files[n];
      queue.textContent = `Lendo ${file.name}${files.length > 1 ? ` (${n + 1} de ${files.length})` : ''}…`;
      try {
        const fd = new FormData(); fd.append('file', file);
        const inv = await api('invoice_parse', { form: fd });
        queue.textContent = files.length > 1 ? `Nota ${n + 1} de ${files.length}: ${file.name}` : '';
        const res = await renderReview(review, inv, { batch: files.length > 1 });
        if (res === 'stop') break;
      } catch (e) { fail(new Error(file.name + ': ' + e.message)); }
    }
    if (files.length > 1) queue.textContent = 'Fila concluída.';
  };
  $('#mode', v).onclick = (e) => { const b = e.target.closest('button'); if (b) show(b.dataset.m); };
  window.addEventListener('hashchange', () => { if (stopCam) { stopCam(); stopCam = null; } }, { once: true });
  show('file');
}

/** Liga a câmera e procura um QR Code. Devolve uma função para desligar. */
async function startScanner(video, onFound) {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('Este navegador não permite usar a câmera. Cole o link do QR Code.');
  if (!window.isSecureContext) throw new Error('A câmera só funciona com HTTPS. Ative o SSL na hospedagem ou cole o link.');
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
  video.srcObject = stream; await video.play();
  const detector = 'BarcodeDetector' in window ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
  const canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
  let on = true;
  const tick = async () => {
    if (!on) return;
    try {
      if (video.readyState >= 2) {
        let text = null;
        if (detector) { const r = await detector.detect(video); if (r.length) text = r[0].rawValue; }
        else if (window.jsQR) {
          canvas.width = video.videoWidth; canvas.height = video.videoHeight; ctx.drawImage(video, 0, 0);
          const img = ctx.getImageData(0, 0, canvas.width, canvas.height); const r = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' }); if (r) text = r.data;
        }
        if (text) { onFound(text); return; }
      }
    } catch (_) { /* tenta de novo */ }
    setTimeout(tick, 180);
  };
  tick();
  return () => { on = false; stream.getTracks().forEach((t) => t.stop()); video.srcObject = null; };
}

/** Tela de conferência da nota antes de dar entrada. */
async function renderReview(box, inv, { batch = false } = {}) {
  const all = await products(true);
  const units = state.meta.units;
  // Preço de venda: manter | sugerir (desmarcado) | atualizar (já marcado)
  const PRICE_MODE = BH().invoice_price || 'manter';
  const items = inv.items.map((it) => ({ ...it, import: true, product_id: it.product_id || null, factor: it.factor || 1, newp: null, price_on: PRICE_MODE === 'atualizar' }));
  const guessUnit = (u) => { u = String(u || '').toLowerCase(); return units.includes(u) ? u : u === 'kg' ? 'kg' : u === 'lt' || u === 'l' ? 'L' : 'un'; };

  return new Promise((resolve) => {
    box.innerHTML = `<div class="card" id="rv"><div class="card-head"><h2>Conferir nota</h2><span class="badge">${KIND_LABEL[inv.kind] || ''}</span></div>
      ${inv.recipient_error ? `<div class="alert err"><strong>Nota não aceita.</strong> ${esc(inv.recipient_error)}${isAdmin() && /Dados da empresa/.test(inv.recipient_error) ? ' <a href="#/empresa">Abrir Dados da empresa</a>' : ''}${isAdmin() && /Configurações → Notas fiscais/.test(inv.recipient_error) ? ' <a href="#/notas-comportamento">Abrir CNPJs aceitos</a>' : ''}</div>` : ''}
      ${(inv.warnings || []).map((w) => `<div class="alert warn">${esc(w)}</div>`).join('')}
      <form class="form" id="hdr"><div class="row">
        <label>Fornecedor <input name="supplier_name" value="${esc(inv.supplier_name)}"></label>
        <label>CNPJ <input name="supplier_cnpj" value="${esc(cnpjFmt(inv.supplier_cnpj))}" inputmode="numeric"></label>
        <label>Número <input name="number" value="${esc(inv.number)}"></label>
        <label>Série <input name="series" value="${esc(inv.series)}"></label>
        <label>Emissão <input name="issued_at" type="date" value="${esc((inv.issued_at || '').slice(0, 10))}"></label>
      </div>${inv.access_key || inv.kind === 'manual' ? `<label>Chave de acesso <input name="access_key" class="mono" value="${esc(inv.access_key || '')}" placeholder="opcional — evita lançar a mesma nota duas vezes"></label>` : ''}</form>
      <h3 style="margin-top:18px">Itens</h3><p class="help">Para cada item, escolha o produto do seu estoque (ou cadastre um novo) e diga quantas unidades de estoque vêm em cada unidade da nota. Ex.: nota em CX, estoque em un, 1 CX = 12 un. Na próxima nota desse fornecedor o sistema lembra.</p>
      <div id="items"></div>
      <div class="actions" style="justify-content:space-between;margin-top:6px"><button type="button" class="btn sm" id="addItem">${icon('plus')}Adicionar item</button><span id="sum" class="muted small"></span></div>
      <div class="inv-pay"><div class="inline" style="justify-content:space-between"><h3 style="margin:0">Contas a pagar</h3>
        <label class="check"><input type="checkbox" id="payon" ${{ sempre: true, nunca: false }[BH().invoice_payables] ?? (inv.installments || []).length > 0 ? 'checked' : ''}> Lançar em Contas a pagar</label></div>
        <p class="help" id="payhelp"></p>
        <div id="paybox"><div class="table-wrap"><table class="inv-dups"><thead><tr><th>Parcela</th><th>Vencimento</th><th class="num">Valor (R$)</th><th>Já paga?</th><th></th></tr></thead><tbody id="dups"></tbody></table></div>
          <div class="inline" style="justify-content:space-between;margin-top:8px"><span class="inline"><button type="button" class="btn sm" id="adddup">${icon('plus')}Adicionar parcela</button>
            <label class="inline small" style="gap:6px">Forma <select id="paymethod" style="width:auto">${pmOptions(inv.payment_method || (state.meta.payment_methods[BH().invoice_pay_method || 'boleto'] ? BH().invoice_pay_method || 'boleto' : ''))}</select></label></span>
            <span id="dupsum" class="small"></span></div></div></div>
      <div class="actions" style="justify-content:flex-end;margin-top:16px">${batch ? '<button class="btn" id="skip">Pular esta nota</button>' : ''}<button class="btn" id="discard">Descartar</button><button class="btn primary lg" id="ok" ${inv.duplicate || inv.recipient_error ? 'disabled' : ''}>Dar entrada no estoque</button></div></div>`;
    const list = $('#items', box);

    const paintItem = (it, el) => {
      const p = all.find((x) => x.id == it.product_id);
      const stockUnit = p ? p.unit : it.newp ? it.newp.unit : '';
      const sq = num(it.qty) * (num(it.factor) || 1);
      el.className = 'inv-item' + (it.import ? '' : ' off');
      $('.result', el).innerHTML = it.import && (p || it.newp) && sq > 0
        ? `Entra <strong>${qty(sq, 4)} ${esc(stockUnit)}</strong> a <strong>${cost(num(it.cost_total) / sq)}</strong> por ${esc(stockUnit)}${p ? ` · estoque atual ${qty(p.stock)} ${esc(p.unit)}` : ' · produto novo'}`
        : it.import ? '<span class="stock-low">Escolha o produto do estoque</span>' : 'Item ignorado';
      $('.fu', el).textContent = stockUnit || 'un. estoque';
      $('.nu', el).textContent = it.unit || 'un. nota';
      $('.newp', el).hidden = !it.newp;
      $('.pick', el).hidden = !!it.newp;
      // Preço de venda pela margem (Configurações → Notas fiscais)
      const pb = $('.pricebox', el); if (!pb) return;
      const show = it.import && (p || it.newp) && sq > 0;
      pb.hidden = !show; if (!show) return;
      it.price_sug = Math.round(num(it.cost_total) / sq * (1 + (+BH().invoice_markup || 0) / 100) * 100) / 100;
      if (!it.price_edit) $('.pv', pb).value = qty(it.price_sug, 2);
      $('.pcur', pb).textContent = `${p ? `atual ${money(p.sale_price)} · ` : ''}sugerido ${money(it.price_sug)} (custo + ${qty(+BH().invoice_markup || 0, 2)}%)`;
    };
    const paintSum = () => {
      const s = items.filter((i) => i.import).reduce((a, i) => a + num(i.cost_total), 0);
      $('#sum', box).textContent = `${items.filter((i) => i.import).length} de ${items.length} itens · total ${money(s)}${inv.total ? ` (nota: ${money(inv.total)})` : ''}`;
    };
    const addItem = (it) => {
      const el = document.createElement('div');
      el.innerHTML = `<div class="top"><input type="checkbox" class="imp" ${it.import ? 'checked' : ''} aria-label="Importar este item">
        <div class="desc"><input class="d" value="${esc(it.description)}" placeholder="Descrição na nota"><div class="small muted" style="margin-top:4px">${it.code ? 'Cód. fornecedor: ' + esc(it.code) : ''}${it.ean ? ' · EAN ' + esc(it.ean) : ''}${it.match ? ` · <span class="badge ok">reconhecido pelo ${it.match === 'fornecedor' ? 'código do fornecedor' : it.match === 'codigo_barras' ? 'código de barras' : 'nome'}</span>` : ''}</div></div>
        <button type="button" class="icon-btn rm" aria-label="Remover">${icon('trash')}</button></div>
        <div class="grid4"><label>Qtd. na nota <input class="q" inputmode="decimal" value="${qty(it.qty, 4)}"></label>
          <label>Unidade da nota <input class="un" value="${esc(it.unit)}" maxlength="10"></label>
          <label>Custo total (R$) <input class="c" inputmode="decimal" value="${qty(it.cost_total, 2)}"></label>
          <label>Conversão <span class="inline" style="flex-wrap:nowrap"><span class="small nowrap">1 <span class="nu"></span> =</span><input class="fct" inputmode="decimal" value="${qty(it.factor, 4)}" style="width:100%;min-width:60px"><span class="small fu nowrap"></span></span></label></div>
        <div class="pick inline"><div style="flex:1;min-width:220px"><input class="pp" placeholder="Produto do estoque"></div><button type="button" class="btn sm mk">${icon('plus')}Cadastrar novo</button></div>
        <div class="newp row" hidden><label>Nome do novo produto <input class="nn" value="${esc(it.description)}"></label>
          <label>Unidade de estoque <input class="nun" list="units-dl" value="${esc(guessUnit(it.unit))}" maxlength="10"></label>
          <label style="align-self:end"><button type="button" class="btn sm ex">Usar produto existente</button></label></div>
        <div class="result"></div>
        ${PRICE_MODE !== 'manter' ? `<div class="pricebox inline" style="gap:8px;margin-top:6px"><label class="check"><input type="checkbox" class="pon" ${it.price_on ? 'checked' : ''}> Atualizar preço de venda para</label>
          <input class="pv" inputmode="decimal" style="width:110px" aria-label="Novo preço de venda"><span class="small muted pcur"></span></div>` : ''}`;
      list.appendChild(el);
      const picker = productPicker($('.pp', el), { list: () => all, onPick: (p) => { it.product_id = p.id; paintItem(it, el); } });
      if (it.product_id) picker.set(all.find((x) => x.id == it.product_id));
      const bind = (sel, fn) => $(sel, el).addEventListener('input', (e) => { fn(e.target); paintItem(it, el); paintSum(); });
      if ($('.pricebox', el)) {
        $('.pon', el).onchange = (e) => (it.price_on = e.target.checked);
        $('.pv', el).addEventListener('input', (e) => { it.price_edit = true; it.price_val = num(e.target.value); if (!it.price_on) { it.price_on = true; $('.pon', el).checked = true; } });
        $('.pv', el).addEventListener('focusout', (e) => { if (e.target.value.trim()) e.target.value = qty(num(e.target.value), 2); else { it.price_edit = false; paintItem(it, el); } });
      }
      bind('.d', (t) => (it.description = t.value));
      bind('.q', (t) => (it.qty = num(t.value)));
      bind('.un', (t) => (it.unit = t.value));
      bind('.c', (t) => (it.cost_total = num(t.value)));
      bind('.fct', (t) => (it.factor = num(t.value) || 1));
      bind('.nn', (t) => (it.newp.name = t.value));
      bind('.nun', (t) => (it.newp.unit = t.value));
      $('.pp', el).addEventListener('input', () => { it.product_id = null; paintItem(it, el); });
      $('.imp', el).onchange = (e) => { it.import = e.target.checked; paintItem(it, el); paintSum(); };
      $('.mk', el).onclick = () => { it.product_id = null; it.newp = { name: $('.nn', el).value || it.description, type: 'produto', unit: $('.nun', el).value }; paintItem(it, el); $('.nn', el).focus(); };
      $('.ex', el).onclick = () => { it.newp = null; picker.set(null); paintItem(it, el); $('.pp', el).focus(); };
      $('.rm', el).onclick = () => { items.splice(items.indexOf(it), 1); el.remove(); paintSum(); };
      paintItem(it, el);
    };
    // ---- Contas a pagar (duplicatas do faturamento)
    const dups = (inv.installments || []).map((d) => ({ number: d.number || '', due_date: d.due_date, amount: +d.amount, paid: false }));
    const fmt2 = (n) => (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const noteTotal = () => num(inv.total) || items.filter((i) => i.import).reduce((a, i) => a + num(i.cost_total), 0);
    const paintDups = () => {
      const on = $('#payon', box).checked;
      $('#paybox', box).hidden = !on;
      $('#payhelp', box).textContent = (inv.installments || []).length
        ? (on ? `A nota traz ${inv.installments.length} parcela(s) de faturamento. Elas entram em Contas a pagar, ligadas ao fornecedor. Confira antes de confirmar.` : 'As parcelas da nota não serão lançadas.')
        : (on ? 'Informe as parcelas a pagar desta compra.' : 'A nota não traz parcelas de faturamento (boletos). Marque se quiser lançar a compra em Contas a pagar.');
      const t = today();
      $('#dups', box).innerHTML = dups.map((d, i) => `<tr data-d="${i}"><td><input data-k="number" value="${esc(d.number)}" maxlength="20" style="width:80px" placeholder="${i + 1}"></td>
        <td><input type="date" data-k="due_date" value="${esc(d.due_date)}"><div class="small stock-low" ${d.due_date && d.due_date < t && !d.paid ? '' : 'hidden'}>vencida: marque se já foi paga</div></td>
        <td class="num"><input data-k="amount" inputmode="decimal" value="${fmt2(d.amount)}" style="width:120px;text-align:right"></td>
        <td><label class="check"><input type="checkbox" data-k="paid" ${d.paid ? 'checked' : ''}> paga</label></td>
        <td><button type="button" class="icon-btn" data-rmd aria-label="Remover parcela">${icon('trash')}</button></td></tr>`).join('') || '<tr><td colspan="5" class="muted small">Nenhuma parcela.</td></tr>';
      paintDupSum();
    };
    const paintDupSum = () => {
      const s2 = dups.reduce((a, d) => a + (d.amount || 0), 0), tot = noteTotal(), diff = Math.round((s2 - tot) * 100) / 100;
      $('#dupsum', box).innerHTML = `Parcelas: <strong>${money(s2)}</strong>${tot ? ` · nota: ${money(tot)}${Math.abs(diff) >= 0.01 ? ` <span class="stock-low">(diferença ${money(diff)})</span>` : ''}` : ''}`;
    };
    $('#payon', box).onchange = () => {
      if ($('#payon', box).checked && !dups.length) dups.push({ number: '', due_date: shiftDate(($('#hdr input[name=issued_at]', box).value || today()), 30), amount: Math.round(noteTotal() * 100) / 100, paid: false });
      paintDups();
    };
    $('#adddup', box).onclick = () => {
      const last = dups[dups.length - 1];
      dups.push({ number: '', due_date: shiftDate(last ? last.due_date : today(), 30), amount: 0, paid: false }); paintDups();
    };
    $('#dups', box).addEventListener('input', (e) => {
      const tr = e.target.closest('tr[data-d]'); if (!tr) return;
      const d = dups[+tr.dataset.d], k = e.target.dataset.k;
      if (k === 'amount') d.amount = num(e.target.value); else if (k === 'paid') d.paid = e.target.checked; else d[k] = e.target.value;
      if (k === 'due_date' || k === 'paid') { const w = $('.stock-low', tr); w.hidden = !(d.due_date && d.due_date < today() && !d.paid); }
      paintDupSum();
    });
    $('#dups', box).addEventListener('change', (e) => { if (e.target.dataset.k === 'paid') e.target.dispatchEvent(new Event('input', { bubbles: true })); });
    $('#dups', box).addEventListener('click', (e) => { const b = e.target.closest('[data-rmd]'); if (b) { dups.splice(+b.closest('tr').dataset.d, 1); paintDups(); } });
    paintDups();
    box.insertAdjacentHTML('beforeend', `<datalist id="units-dl">${units.map((u) => `<option value="${u}">`).join('')}</datalist>`);
    items.forEach(addItem);
    if (!items.length) { const it = { description: '', qty: 1, unit: 'UN', cost_total: 0, factor: 1, import: true }; items.push(it); addItem(it); }
    paintSum();
    $('#addItem', box).onclick = () => { const it = { description: '', qty: 1, unit: 'UN', cost_total: 0, factor: 1, import: true, price_on: PRICE_MODE === 'atualizar' }; items.push(it); addItem(it); paintSum(); };
    // "Sempre lançar em Contas a pagar": nota sem duplicatas já vem com uma parcela de 30 dias
    if ($('#payon', box).checked && !dups.length) $('#payon', box).onchange();
    $('#discard', box).onclick = () => { box.innerHTML = ''; resolve('stop'); };
    if ($('#skip', box)) $('#skip', box).onclick = () => { box.innerHTML = ''; resolve('skip'); };
    $('#ok', box).onclick = (e) => busy(e.target, async () => {
      const h = formData($('#hdr', box));
      const payload = {
        ...h, kind: inv.kind, issued_at: h.issued_at || null, recipient_doc: inv.recipient_doc || null, recipient_name: inv.recipient_name || null,
        items: items.map((it) => ({
          import: it.import, product_id: it.product_id, code: it.code, ean: it.ean, description: it.description, unit: it.unit, ncm: it.ncm, cest: it.cest, orig: it.orig,
          qty: it.qty, factor: it.factor, cost_total: it.cost_total,
          sale_price: PRICE_MODE !== 'manter' && it.price_on ? (it.price_edit ? it.price_val : it.price_sug) : null,
          new_product: it.newp ? { name: it.newp.name, type: it.newp.type, unit: it.newp.unit } : null,
        })),
        payables: $('#payon', box).checked && dups.length > 0,
        installments: dups.map((d) => ({ number: d.number, due_date: d.due_date, amount: d.amount, paid: d.paid })),
        payment_method: $('#paymethod', box).value,
      };
      if (payload.payables && dups.some((d) => !d.due_date || !(d.amount > 0))) throw new Error('Confira as parcelas: todas precisam de vencimento e valor');
      const r = await api('invoice_confirm', { body: payload });
      invalidateProducts(); state.meta = null;
      toast('Entrada no estoque feita');
      box.innerHTML = '';
      if (batch) { resolve('ok'); return; }
      resolve('ok'); go('nota/' + r.id);
    });
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

// ============ Clientes ============
/** Formulário de cliente com busca automática pelo CNPJ e pelo CEP. */
function customerForm(c, onSaved, { entity = 'customer', editing = false } = {}) {
  const isNew = !c || !c.id;
  const sup = entity === 'supplier';
  c = c || { type: 'pj' };
  const v = (k) => esc(c[k] ?? '');
  const m = modal({
    title: (isNew ? 'Novo ' : 'Editar ') + (sup ? 'fornecedor' : 'cliente'), wide: true,
    body: `<form class="form" id="cf" autocomplete="off">
      <div class="seg" id="ctype"><button type="button" data-t="pj">Empresa (CNPJ)</button><button type="button" data-t="pf">Pessoa física (CPF)</button></div>
      <div class="row">
        <label><span class="doc-label">CNPJ</span> <span class="inline" style="flex-wrap:nowrap"><input name="document" inputmode="numeric" value="${esc(docFmt(c.document))}" style="flex:1"><button type="button" class="btn js-needs-edit" id="lookup">${icon('search')}Buscar dados</button></span><span class="help" id="dochelp">Digite o CNPJ: os dados são preenchidos automaticamente.</span></label>
      </div>
      <div id="cstatus"></div>
      <div class="row">
        <label><span class="name-label">Razão social</span> <input name="name" value="${v('name')}" required maxlength="200"></label>
        <label class="pj-only">Nome fantasia <input name="trade_name" value="${v('trade_name')}" maxlength="200"></label>
      </div>
      <div class="row">
        <label>Telefone / WhatsApp <input name="phone" inputmode="tel" value="${v('phone')}" maxlength="40"></label>
        <label>E-mail <input name="email" type="email" value="${v('email')}" maxlength="190"></label>
        <label class="pj-only">Inscrição estadual <input name="state_registration" value="${v('state_registration')}" maxlength="30"></label>
      </div>
      <fieldset><legend>Endereço</legend>
        <div class="row">
          <label>CEP <input name="cep" inputmode="numeric" value="${esc(cepFmt(c.cep))}" maxlength="9"><span class="help" id="cephelp">Digite o CEP para preencher o endereço</span></label>
          <label style="grid-column: span 2">Rua <input name="street" value="${v('street')}" maxlength="200"></label>
        </div>
        <div class="row">
          <label>Número <input name="number" value="${v('number')}" maxlength="20"></label>
          <label>Complemento <input name="complement" value="${v('complement')}" maxlength="200"></label>
          <label>Bairro <input name="district" value="${v('district')}" maxlength="100"></label>
        </div>
        <div class="row">
          <label>Cidade <input name="city" value="${v('city')}" maxlength="100"></label>
          <label>UF <input name="state" value="${v('state')}" maxlength="2" style="text-transform:uppercase"></label>
        </div>
      </fieldset>
      <label>Observações <textarea name="notes" maxlength="500">${v('notes')}</textarea></label>
    </form>`,
    foot: `<button class="btn js-edit-only" data-close>Cancelar</button><button class="btn js-view-only" data-close>Fechar</button><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn primary js-edit-only" id="save">Salvar ${sup ? 'fornecedor' : 'cliente'}</button>`,
  });
  const f = $('#cf', m.el);
  let type = c.type === 'pf' ? 'pf' : 'pj', lastLookup = '';
  const setType = (t) => {
    type = t;
    $$('#ctype button', m.el).forEach((b) => b.classList.toggle('on', b.dataset.t === t));
    $$('.pj-only', m.el).forEach((e) => (e.hidden = t !== 'pj'));
    $('.doc-label', m.el).textContent = t === 'pj' ? 'CNPJ' : 'CPF (opcional)';
    const di = $('input[name=document]', m.el); di.dataset.mask = t === 'pj' ? 'cnpj' : 'cpf'; di.placeholder = t === 'pj' ? '00.000.000/0000-00' : '000.000.000-00';
    if (di.value) di.value = docMask(di.value, di.dataset.mask);
    $('.name-label', m.el).textContent = t === 'pj' ? 'Razão social' : 'Nome completo';
    $('#lookup', m.el).hidden = t !== 'pj';
    $('#dochelp', m.el).textContent = t === 'pj' ? 'Digite o CNPJ: os dados são preenchidos automaticamente.' : '';
  };
  $('#ctype', m.el).onclick = (e) => { const b = e.target.closest('button'); if (b) setType(b.dataset.t); };
  setType(type);
  const fill = (d, onlyEmpty = false) => {
    for (const [k, val] of Object.entries(d)) {
      const el = f.elements[k]; if (!el || val === null || val === undefined || val === '') continue;
      if (onlyEmpty && el.value.trim()) continue;
      el.value = k === 'cep' ? cepFmt(val) : k === 'document' ? docFmt(val) : val;
    }
  };
  const lookup = async (btn) => {
    const doc = f.elements.document.value.replace(/\D/g, '');
    if (!validCnpj(doc)) { $('#dochelp', m.el).innerHTML = '<span class="stock-low">CNPJ inválido. Confira os números.</span>'; return; }
    lastLookup = doc;
    const run = async () => {
      const d = await api('cnpj', { params: { cnpj: doc } });
      fill(d);
      const ativa = /ativa/i.test(d.status || '');
      $('#cstatus', m.el).innerHTML = d.status ? `<div class="alert ${ativa ? 'ok' : 'warn'}">Situação na Receita Federal: <strong>${esc(d.status)}</strong>${ativa ? '' : '. Confira antes de vender para esta empresa.'}</div>` : '';
      $('#dochelp', m.el).textContent = 'Dados preenchidos pela Receita Federal. Confira e complete se precisar.';
    };
    if (btn) await busy(btn, run); else { $('#dochelp', m.el).textContent = 'Buscando dados…'; try { await run(); } catch (e) { $('#dochelp', m.el).innerHTML = `<span class="stock-low">${esc(e.message)}</span>`; } }
  };
  $('#lookup', m.el).onclick = (e) => lookup(e.currentTarget);
  f.elements.document.addEventListener('input', (e) => {
    const d = e.target.value.replace(/\D/g, '');
    if (type === 'pj' && d.length === 14 && d !== lastLookup && isNew) lookup(null);
    else if (type === 'pj' && d.length === 14 && !validCnpj(d)) $('#dochelp', m.el).innerHTML = '<span class="stock-low">CNPJ inválido. Confira os números.</span>';
    else if (type === 'pf' && d.length === 11) $('#dochelp', m.el).innerHTML = validCpf(d) ? '' : '<span class="stock-low">CPF inválido. Confira os números.</span>';
  });
  f.elements.document.addEventListener('blur', (e) => { e.target.value = docFmt(e.target.value); });
  let lastCep = (c.cep || '');
  f.elements.cep.addEventListener('input', async (e) => {
    const cep = e.target.value.replace(/\D/g, '');
    if (cep.length !== 8 || cep === lastCep) return;
    lastCep = cep; e.target.value = cepFmt(cep);
    $('#cephelp', m.el).textContent = 'Buscando endereço…';
    try { const d = await api('cep', { params: { cep } }); fill({ street: d.street, district: d.district, city: d.city, state: d.state }); $('#cephelp', m.el).textContent = 'Endereço preenchido. Falta o número.'; f.elements.number.focus(); }
    catch (err) { $('#cephelp', m.el).innerHTML = `<span class="stock-low">${esc(err.message)}</span>`; }
  });
  editLock(m.el, f, !isNew && !editing);
  $('#save', m.el).onclick = (e) => busy(e.target, async () => {
    const d = formData(f);
    const doc = d.document.replace(/\D/g, '');
    if (type === 'pj' && doc && !validCnpj(doc)) throw new Error('CNPJ inválido. Confira os números.');
    if (type === 'pf' && doc && !validCpf(doc)) throw new Error('CPF inválido. Confira os números.');
    d.type = type; if (!isNew) d.id = c.id;
    const r = await api(sup ? 'supplier_save' : 'customer_save', { body: d });
    m.close(); toast(sup ? 'Fornecedor salvo' : 'Cliente salvo'); onSaved && onSaved(r.id);
  });
}

/** Janela "Selecionar cliente" (mesmo estilo de "Incluir produto"). */
function customerBrowser({ selected, onSelect, entity = 'customer' }) {
  const PER = 30, sup = entity === 'supplier';
  const word = sup ? 'fornecedor' : 'cliente';
  const m = modal({
    title: 'Selecionar ' + word, wide: true,
    body: `<label class="pb-search">Pesquisar <input id="cbq" type="search" placeholder="Nome, CNPJ/CPF, cidade ou telefone" autocomplete="off"></label>
      <div class="table-wrap pb-wrap"><table class="pb-table"><thead><tr><th class="pb-chk"></th>
        <th><button type="button" class="pb-sort" id="cbsort">${sup ? 'Fornecedor' : 'Cliente'} <span id="cbarrow">▲</span></button></th>
        <th class="hide-m">CNPJ / CPF</th><th class="hide-m">Cidade</th><th>Telefone</th></tr></thead>
        <tbody id="cbl"><tr><td colspan="5" class="muted">Carregando…</td></tr></tbody></table></div>
      <div class="pager"><span id="cbinfo"></span><span class="actions" id="cbpages"></span></div>`,
    foot: `<button class="btn" data-close>${icon('x')}Cancelar</button><button class="btn" id="cbnew" ${can(sup ? 'fornecedores' : 'clientes') ? '' : 'hidden'}>${icon('plus')}Cadastrar<span class="hide-m"> ${word}</span></button><button class="btn primary" id="cbok" disabled>Selecionar</button>`,
  });
  let page = 1, asc = true, rows = [], sel = selected ? selected.id : null, chosen = selected || null, token = 0;
  const load = async () => {
    const t = ++token;
    const d = await api(sup ? 'suppliers' : 'customers', { params: { q: $('#cbq', m.el).value.trim(), page, limit: PER, sort: asc ? 'asc' : 'desc' } });
    if (t !== token) return;
    rows = d.rows;
    const total = d.total, pages = Math.max(1, Math.ceil(total / PER));
    $('#cbl', m.el).innerHTML = rows.length ? rows.map((c) => `<tr class="click ${c.id == sel ? 'pb-sel' : ''}" data-id="${c.id}">
        <td class="pb-chk"><input type="radio" name="cbsel" ${c.id == sel ? 'checked' : ''} aria-label="Selecionar ${esc(custName(c))}"></td>
        <td><div class="pb-name">${esc(custName(c))}</div><div class="small muted">${c.trade_name ? esc(c.name) : ''}${c.sales_count > 0 ? `${c.trade_name ? ' · ' : ''}${c.sales_count} compra(s)` : ''}${c.invoices_count > 0 ? `${c.trade_name ? ' · ' : ''}${c.invoices_count} nota(s)` : ''}<span class="show-m">${c.document ? ' · ' + docFmt(c.document) : ''}</span></div></td>
        <td class="hide-m nowrap">${docFmt(c.document) || '<span class="muted">—</span>'}</td>
        <td class="hide-m">${esc([c.city, c.state].filter(Boolean).join('/')) || '<span class="muted">—</span>'}</td>
        <td class="nowrap">${esc(c.phone || '') || '<span class="muted">—</span>'}</td></tr>`).join('')
      : `<tr><td colspan="5" class="empty">Nenhum ${word} encontrado.${can(sup ? 'fornecedores' : 'clientes') ? ' <a href="#" id="cbnew2">Cadastrar agora</a>' : ''}</td></tr>`;
    const n2 = $('#cbnew2', m.el); if (n2) n2.onclick = (e) => { e.preventDefault(); $('#cbnew', m.el).click(); };
    $('#cbinfo', m.el).textContent = total ? `Mostrando de ${(page - 1) * PER + 1} até ${Math.min(page * PER, total)} de ${total} registro${total === 1 ? '' : 's'}` : '';
    const nums = [];
    for (let i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - page) <= 2) nums.push(i); else if (nums[nums.length - 1] !== '…') nums.push('…');
    $('#cbpages', m.el).innerHTML = pages > 1 ? `<button class="btn sm" data-p="${page - 1}" ${page === 1 ? 'disabled' : ''}>Anterior</button>${nums.map((n) => n === '…' ? '<span class="muted">…</span>' : `<button class="btn sm ${n === page ? 'primary' : ''}" data-p="${n}">${n}</button>`).join('')}<button class="btn sm" data-p="${page + 1}" ${page === pages ? 'disabled' : ''}>Próximo</button>` : '';
    $('#cbarrow', m.el).textContent = asc ? '▲' : '▼';
    $('#cbok', m.el).disabled = !sel;
  };
  const finish = (c) => { onSelect(c); m.close(); };
  $('#cbq', m.el).addEventListener('input', debounce(() => { page = 1; load().catch(fail); }, 250));
  $('#cbq', m.el).addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); if (rows.length === 1) finish(rows[0]); } });
  $('#cbsort', m.el).onclick = () => { asc = !asc; page = 1; load().catch(fail); };
  $('#cbl', m.el).addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]'); if (!tr) return;
    sel = +tr.dataset.id; chosen = rows.find((c) => c.id == sel);
    $$('#cbl tr', m.el).forEach((r) => { r.classList.toggle('pb-sel', r === tr); const i = $('input', r); if (i) i.checked = r === tr; });
    $('#cbok', m.el).disabled = false;
  });
  $('#cbl', m.el).addEventListener('dblclick', (e) => { const tr = e.target.closest('tr[data-id]'); if (tr) finish(rows.find((c) => c.id == tr.dataset.id)); });
  $('#cbpages', m.el).addEventListener('click', (e) => { const b = e.target.closest('[data-p]'); if (b && !b.disabled) { page = +b.dataset.p; load().catch(fail); } });
  $('#cbok', m.el).onclick = () => { if (chosen) finish(chosen); };
  $('#cbnew', m.el).onclick = () => customerForm(null, async (id) => finish(await api(sup ? 'supplier' : 'customer', { params: { id } })), { entity });
  load().catch(fail);
  if (window.innerWidth > 640) $('#cbq', m.el).focus();
}

async function viewCustomers(v) {
  const f = { q: '', active: '1', page: 1 };
  v.innerHTML = `<div class="page-head"><div><h1>Clientes</h1><p class="muted">Cadastre pelo CNPJ e os dados vêm da Receita Federal.</p></div>
    <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo cliente</button></div></div>
    <div class="card"><div class="filters"><label class="grow">Buscar <input id="q" placeholder="Nome, CNPJ/CPF, cidade, telefone ou e-mail"></label>
      <label class="check"><input type="checkbox" id="inactive"> Mostrar inativos</label></div><div id="list"></div></div>`;
  const load = async () => {
    const d = await api('customers', { params: { q: f.q, active: f.active, page: f.page, limit: 50 } });
    const list = $('#list', v);
    list.innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Cliente</th><th>CNPJ / CPF</th><th>Cidade</th><th>Telefone</th><th class="num">Compras</th><th>Última compra</th></tr></thead><tbody>
      ${d.rows.map((c) => `<tr class="click" data-id="${c.id}"><td><strong>${esc(custName(c))}</strong> ${+c.active ? '' : '<span class="badge">inativo</span>'}${+c.portal_open ? ' <span class="badge warn" title="Pedido de correção dos dados enviado pelo portal">correção pedida</span>' : ''}${c.trade_name ? `<div class="small muted">${esc(c.name)}</div>` : ''}</td>
        <td class="nowrap">${docFmt(c.document) || '<span class="muted">—</span>'}</td><td>${esc([c.city, c.state].filter(Boolean).join('/'))}</td><td class="nowrap">${esc(c.phone || '')}</td>
        <td class="num">${c.sales_count}</td><td class="nowrap">${c.last_sale ? dOnly(c.last_sale) : '<span class="muted">—</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : `<div class="empty">Nenhum cliente encontrado.</div>`;
    if (d.total > 50) list.appendChild(pager(d.total, f.page, 50, (p) => { f.page = p; load(); }));
  };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; f.page = 1; load(); });
  $('#inactive', v).onchange = (e) => { f.active = e.target.checked ? 'all' : '1'; f.page = 1; load(); };
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('cliente/' + r.dataset.id); };
  $('#new', v).onclick = () => customerForm(null, (id) => go('cliente/' + id));
  await load();
}

/** Endereço do portal do cliente (pasta cliente/ ao lado do sistema). */
const portalUrl = (qs = '') => new URL('cliente/' + qs, location.href.split('#')[0].split('?')[0]).href;

/** Cartão "Portal do cliente" na tela do cliente: acesso, link de convite e pedidos de correção. */
function portalCardHtml(c) {
  const p = c.portal;
  const hasDoc = /^(\d{11}|\d{14})$/.test(String(c.document || ''));
  const status = p.active ? `<span class="badge ok">acesso ativo</span> ${p.last_login ? `<span class="small muted">último acesso em ${dt(p.last_login)}</span>` : '<span class="small muted">ainda não entrou</span>'}`
    : p.invite_pending ? `<span class="badge warn">convite enviado</span> <span class="small muted">o link vale até ${dt(p.invite_expires)}</span>`
    : '<span class="badge">sem acesso</span>';
  const open = p.requests.filter((r) => !r.resolved_at);
  return `<div class="card" id="portal"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h2 style="margin:0">Portal do cliente</h2>${status}
      <span style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
        ${+c.active && hasDoc ? `<button class="btn" id="pinv">${icon('link')}${p.active ? 'Gerar link de nova senha' : 'Gerar link de acesso'}</button>` : ''}
        ${p.active || p.invite_pending ? '<button class="btn danger" id="prev">Remover acesso</button>' : ''}</span></div>
    <p class="small muted" style="margin:8px 0 0">${hasDoc ? `O cliente entra em <a href="${esc(portalUrl())}" target="_blank" rel="noopener">${esc(portalUrl())}</a> com o ${String(c.document).length === 14 ? 'CNPJ' : 'CPF'} e a senha que ele cria pelo link de acesso. Lá ele vê orçamentos (e pode aprovar ou recusar), compras, parcelas e os próprios dados.`
      : 'Cadastre o CPF ou CNPJ do cliente para liberar o portal: é com ele que o cliente entra.'}</p>
    ${p.requests.length ? `<h3 style="margin:14px 0 6px;font-size:.95rem">Pedidos de correção dos dados${open.length ? ` <span class="badge warn">${open.length} em aberto</span>` : ''}</h3>
      <div class="table-wrap"><table><tbody>${p.requests.map((r) => `<tr><td class="nowrap small">${dt(r.created_at)}</td><td style="white-space:pre-wrap">${esc(r.message)}</td>
        <td class="num nowrap">${r.resolved_at ? `<span class="small muted">resolvido${r.resolved_by_name ? ' por ' + esc(r.resolved_by_name) : ''} em ${dOnly(r.resolved_at)}</span>` : `<button class="btn sm" data-preq="${r.id}">Marcar como resolvido</button>`}</td></tr>`).join('')}</tbody></table></div>` : ''}
  </div>`;
}

function bindPortalCard(v, c) {
  const inv = $('#pinv', v), rev = $('#prev', v);
  if (inv) inv.onclick = () => busy(inv, async () => {
    const r = await api('customer_portal_invite', { body: { id: c.id } });
    const url = portalUrl('?p=convite&t=' + r.token);
    const company = state.meta?.company?.name || '';
    const msg = `Olá! Este é o seu acesso ao portal de clientes${company ? ' da ' + company : ''}, onde você acompanha orçamentos, compras e parcelas. Abra o link e crie sua senha (vale até ${dt(r.expires)}): ${url}\nDepois, para entrar, use o seu ${String(c.document).length === 14 ? 'CNPJ' : 'CPF'} e a senha em ${portalUrl()}`;
    const phone = String(c.phone || '').replace(/\D/g, '');
    const m = modal({
      title: 'Link de acesso ao portal',
      body: `<p>Envie este link para <strong>${esc(custName(c))}</strong>. Ele vale até <strong>${dt(r.expires)}</strong> e só funciona uma vez: o cliente cria a senha e já entra.${c.portal.active ? ' A senha atual continua valendo até ele criar a nova.' : ''}</p>
        <textarea id="pmsg" rows="5" style="width:100%">${esc(msg)}</textarea>
        <p class="small muted">Por segurança, o link não fica guardado no sistema: copie agora. Se perder, gere outro (o anterior deixa de valer).</p>`,
      foot: `${phone.length >= 10 ? `<a class="btn" target="_blank" rel="noopener" href="https://wa.me/55${phone}?text=${encodeURIComponent(msg)}">Enviar pelo WhatsApp</a>` : ''}<button class="btn primary" id="pcopy">${icon('copy')}Copiar mensagem</button>`,
    });
    $('#pcopy', m.el).onclick = async () => { const t = $('#pmsg', m.el); try { await navigator.clipboard.writeText(t.value); } catch (_) { t.select(); document.execCommand('copy'); } toast('Mensagem copiada'); };
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); route(); } });
    obs.observe($('#modal-root'), { childList: true });
  });
  if (rev) rev.onclick = async () => {
    if (!(await confirmBox(`Remover o acesso de "${custName(c)}" ao portal? A senha e o link de acesso deixam de valer. Para liberar de novo, gere outro link.`, { ok: 'Remover acesso', danger: true }))) return;
    busy(rev, async () => { await api('customer_portal_revoke', { body: { id: c.id } }); toast('Acesso ao portal removido'); route(); });
  };
  $('#portal', v).addEventListener('click', (e) => {
    const b = e.target.closest('[data-preq]'); if (!b) return;
    busy(b, async () => { await api('portal_request_done', { body: { id: +b.dataset.preq } }); toast('Pedido marcado como resolvido'); route(); });
  });
}

/** Status em que a ordem ainda não foi concluída (mesma regra de ServiceOrders::OPEN). */
const SO_OPEN_CODES = ['aberta', 'agendada', 'em_execucao', 'pausada'];
const soDate = (s) => (s ? String(s).split('-').reverse().join('/') : '—');

/** Tabela de ordens (ficha do cliente e histórico do equipamento). */
function soTableHtml(rows, statuses, types, { withEquipment = true } = {}) {
  return `<div class="table-wrap"><table><thead><tr><th>Ordem</th><th>Status</th><th>Tipo</th>${withEquipment ? '<th>Equipamento</th>' : ''}<th>Agenda</th><th>Técnico</th></tr></thead><tbody>
    ${rows.map((o) => `<tr class="click" data-so="${o.id}"><td><strong>#${o.id}</strong><div class="small muted">${esc(o.description || '')}</div></td>
      <td>${soStatusHtml(o.status, statuses)}</td><td>${esc(types[o.task_type_id] || '—')}</td>
      ${withEquipment ? `<td>${esc(o.equipment_name || '—')}</td>` : ''}
      <td class="nowrap">${o.scheduled_at ? dt(o.scheduled_at) : '—'}</td><td>${esc(o.technician_name || '—')}</td></tr>`).join('')}</tbody></table></div>`;
}

/** Cards de equipamentos e ordens na ficha do cliente. */
async function loadCustomerHistory(v, c) {
  const box = $('#custhist', v);
  if (!box) return;
  const [eqs, os] = await Promise.all([
    api('equipments', { params: { customer_id: c.id, all: 1 } }),
    api('service_orders', { params: { customer_id: c.id, status: 'todas' } }),
  ]);
  const abertas = os.rows.filter((o) => SO_OPEN_CODES.includes(o.status)).length;
  box.innerHTML = `<div class="card"><h2>Equipamentos <span class="muted small">(${eqs.length})</span></h2>${eqs.length
    ? `<div class="table-wrap"><table><thead><tr><th>Equipamento</th><th>Identificador</th><th>Garantia até</th><th>Situação</th></tr></thead><tbody>
      ${eqs.map((e) => `<tr class="click" data-eq="${e.id}"><td><strong>${esc(e.name)}</strong><div class="small muted">${esc([e.brand, e.model, e.category].filter(Boolean).join(' · '))}</div></td>
        <td>${esc(e.serial || '—')}</td><td>${soDate(e.warranty_until)}</td><td>${+e.active ? '' : '<span class="badge">inativo</span>'}</td></tr>`).join('')}</tbody></table></div>`
    : '<p class="muted">Nenhum equipamento cadastrado para este cliente.</p>'}</div>
    <div class="card"><h2>Ordens de serviço <span class="muted small">(${os.rows.length} no total · ${abertas} em aberto)</span></h2>${os.rows.length
    ? soTableHtml(os.rows, os.statuses, os.types) : '<p class="muted">Nenhuma ordem de serviço para este cliente.</p>'}</div>`;
  box.onclick = (e) => {
    const eqRow = e.target.closest('[data-eq]');
    if (eqRow) { equipmentForm(eqs.find((x) => x.id == eqRow.dataset.eq), () => route()).catch(fail); return; }
    const soRow = e.target.closest('[data-so]');
    if (soRow) go('ordem/' + soRow.dataset.so);
  };
}

async function viewCustomer(v, id) {
  const c = await api('customer', { params: { id } });
  const st = c.stats || {};
  const addr = [[c.street, c.number].filter(Boolean).join(', '), c.complement, c.district, [c.city, c.state].filter(Boolean).join('/'), cepFmt(c.cep)].filter(Boolean).join(' · ');
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/clientes">← Clientes</a></p><h1>${esc(custName(c))} ${+c.active ? '' : '<span class="badge">inativo</span>'}</h1>
      <p class="muted">${c.trade_name ? esc(c.name) + ' · ' : ''}${c.type === 'pj' ? 'Empresa' : 'Pessoa física'}${c.document ? ' · ' + docFmt(c.document) : ''}</p></div>
    <div class="actions">${+c.active ? `<button class="btn primary" id="sell">${icon('cart')}Nova venda</button>` : ''}<button class="btn" id="edit">Editar</button>
      ${isAdmin() ? `<button class="btn ${+c.active ? 'danger' : ''}" id="toggle">${+c.active ? 'Desativar' : 'Reativar'}</button>` : ''}</div></div>
    <div class="kpis">
      <div class="kpi"><div class="label">Total comprado</div><div class="value">${money(st.total)}</div><div class="sub">${st.n || 0} compra(s)</div></div>
      <div class="kpi pos"><div class="label">Lucro gerado</div><div class="value">${money(st.profit)}</div></div>
      <div class="kpi"><div class="label">Ticket médio</div><div class="value">${money(st.n ? st.total / st.n : 0)}</div></div>
      <div class="kpi"><div class="label">Última compra</div><div class="value" style="font-size:1.1rem">${st.last_sale ? dOnly(st.last_sale) : '—'}</div><div class="sub">${st.first_sale ? 'cliente desde ' + dOnly(st.first_sale) : ''}</div></div>
    </div>
    <div class="grid two">
      <div class="card"><h2>Dados</h2><dl class="dl">
        ${c.phone ? `<dt>Telefone</dt><dd>${esc(c.phone)} ${String(c.phone).replace(/\D/g, '').length >= 10 ? `<a class="small" target="_blank" rel="noopener" href="https://wa.me/55${String(c.phone).replace(/\D/g, '')}">WhatsApp</a>` : ''}</dd>` : ''}
        ${c.email ? `<dt>E-mail</dt><dd><a href="mailto:${esc(c.email)}">${esc(c.email)}</a></dd>` : ''}
        ${c.state_registration ? `<dt>Inscrição estadual</dt><dd>${esc(c.state_registration)}</dd>` : ''}
        ${addr ? `<dt>Endereço</dt><dd>${esc(addr)}</dd>` : ''}
        ${c.notes ? `<dt>Observações</dt><dd>${esc(c.notes)}</dd>` : ''}
        ${!c.phone && !c.email && !addr && !c.notes ? '<dt>—</dt><dd class="muted">Sem dados de contato</dd>' : ''}
      </dl></div>
      <div class="card"><h2>O que mais compra</h2>${c.top_products.length ? `<div class="table-wrap"><table><tbody>${c.top_products.map((p) => `<tr><td><a href="#/produto/${p.id}">${esc(p.name)}</a></td><td class="num">${qty(p.qty)} ${esc(p.unit)}</td><td class="num">${money(p.total)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Nenhuma compra ainda.</p>'}</div>
    </div>
    ${c.portal ? portalCardHtml(c) : ''}
    <div class="card"><h2>Compras</h2>${c.sales.length ? `<div class="table-wrap"><table><thead><tr><th>#</th><th>Data</th><th>Pagamento</th><th class="num">Itens</th><th class="num">Total</th><th>Vendedor</th></tr></thead><tbody>
      ${c.sales.map((s) => `<tr class="click" data-go="venda/${s.id}"><td>${s.id}</td><td class="nowrap">${dt(s.created_at)}</td><td>${esc(payLabel(s.payment_method))}</td><td class="num">${s.items}</td><td class="num">${s.status === 'cancelada' ? `<s class="muted">${money(s.total)}</s> <span class="badge err">cancelada</span>` : money(s.total)}</td><td class="small muted">${esc(s.user_name || '')}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Nenhuma compra ainda.</p>'}</div>
    <div id="custhist"></div>`;
  v.addEventListener('click', (e) => { const r = e.target.closest('[data-go]'); if (r) go(r.dataset.go); });
  $('#edit', v).onclick = () => customerForm(c, () => route(), { editing: true });
  if (c.portal) bindPortalCard(v, c);
  if (can('ordens')) loadCustomerHistory(v, c).catch(fail);
  if ($('#sell', v)) $('#sell', v).onclick = () => { sessionStorage.setItem('saleCustomer', JSON.stringify(c)); go('vendas/nova'); };
  if ($('#toggle', v)) $('#toggle', v).onclick = async (e) => {
    if (+c.active && !(await confirmBox(`Desativar "${custName(c)}"? Ele some da seleção de clientes, mas o histórico de compras é mantido.`, { ok: 'Desativar', danger: true }))) return;
    busy(e.target, async () => { await api('customer_save', { body: { ...c, active: !+c.active } }); toast('Cliente atualizado'); route(); });
  };
}

// ============ Serviços ============
/** Cadastro simples de serviço: só nome e preço. */
function serviceForm(sv, onSaved) {
  const isNew = !sv || !sv.id;
  const m = modal({
    title: isNew ? 'Novo serviço' : 'Editar serviço',
    body: `<form class="form" id="svf"><label>Nome do serviço * <input name="name" required maxlength="200" value="${esc(sv?.name)}" placeholder="Ex.: Montagem de mesa de doces"></label>
      <label>Preço (R$) * <input name="sale_price" inputmode="decimal" value="${sv && +sv.sale_price ? qty(sv.sale_price, 2) : ''}" placeholder="0,00"></label></form>`,
    foot: `${!isNew && isAdmin() ? `<button class="btn danger" id="svdel" style="margin-right:auto">${icon('trash')}Excluir</button>` : ''}<button class="btn js-edit-only" data-close>Cancelar</button><button class="btn js-view-only" data-close>Fechar</button><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn primary js-edit-only" id="svsave">Salvar serviço</button>`,
  });
  const f = $('#svf', m.el);
  const sd = $('#svdel', m.el);
  if (sd) sd.onclick = async () => { try { if (await deleteProduct({ ...sv, type: 'servico' })) { m.close(); onSaved && onSaved(); } } catch (err) { fail(err); } };
  const save = (btn) => busy(btn, async () => {
    const d = formData(f);
    if (!d.name.trim()) throw new Error('Informe o nome do serviço');
    if (!(num(d.sale_price) > 0)) throw new Error('Informe o preço do serviço');
    const body = isNew ? { type: 'servico', unit: 'un', name: d.name, sale_price: d.sale_price }
      : { ...sv, type: 'servico', name: d.name, sale_price: d.sale_price, markup: '' };
    const r = await api('product_save', { body });
    invalidateProducts(); m.close(); toast('Serviço salvo'); onSaved && onSaved(r.id);
  });
  editLock(m.el, f, !isNew);
  $('#svsave', m.el).onclick = (e) => save(e.currentTarget);
  f.onsubmit = (e) => { e.preventDefault(); save($('#svsave', m.el)); };
}

async function viewServices(v) {
  const f = { q: '', active: '1' };
  v.innerHTML = `<div class="page-head"><div><h1>Serviços</h1><p class="muted">Serviços que você oferece nos orçamentos e vendas.</p></div>
    <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo serviço</button></div></div>
    <div class="card"><div class="filters"><label class="grow">Buscar <input id="q" placeholder="Nome do serviço"></label>
      <label class="check"><input type="checkbox" id="inactive"> Mostrar inativos</label></div><div id="list"></div></div>`;
  const load = async () => {
    const rows = await api('products', { params: { type: 'servico', q: f.q, active: f.active } });
    $('#list', v).innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th>Serviço</th><th class="num">Preço</th><th></th></tr></thead><tbody>
      ${rows.map((p) => `<tr class="click" data-id="${p.id}"><td><strong>${esc(p.name)}</strong> ${+p.active ? '' : '<span class="badge">inativo</span>'}</td><td class="num">${money(p.sale_price)}</td>
        <td class="num nowrap">${isAdmin() ? `<button class="btn sm ghost" data-toggle="${p.id}">${+p.active ? 'Desativar' : 'Reativar'}</button><button class="btn sm ghost danger" data-del="${p.id}" title="Excluir" aria-label="Excluir ${esc(p.name)}">${icon('trash')}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhum serviço cadastrado. Clique em <strong>Novo serviço</strong>.</div>';
  };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; load(); });
  $('#inactive', v).onchange = (e) => { f.active = e.target.checked ? 'all' : '1'; load(); };
  $('#new', v).onclick = () => serviceForm(null, load);
  $('#list', v).onclick = async (e) => {
    const t = e.target.closest('[data-toggle]');
    const rows = await api('products', { params: { type: 'servico', active: 'all' } });
    const dl = e.target.closest('[data-del]');
    if (dl) { try { await deleteProduct(rows.find((x) => x.id == dl.dataset.del), load); } catch (err) { fail(err); } return; }
    if (t) {
      const p = rows.find((x) => x.id == t.dataset.toggle);
      if (+p.active && !(await confirmBox(`Desativar o serviço "${p.name}"? Ele some das listas, mas continua nos orçamentos e vendas antigos.`, { ok: 'Desativar', danger: true }))) return;
      await api('product_save', { body: { ...p, active: !+p.active } }); invalidateProducts(); toast('Serviço atualizado'); load(); return;
    }
    const r = e.target.closest('[data-id]'); if (r) serviceForm(rows.find((x) => x.id == r.dataset.id), load);
  };
  await load();
}

// ============ Ordens de serviço ============
const SO_TABS = [['abertas', 'Em aberto'], ['aberta', 'Abertas'], ['agendada', 'Agendadas'], ['em_execucao', 'Em execução'], ['pausada', 'Pausadas'], ['concluida', 'Concluídas'], ['faturada', 'Faturadas'], ['todas', 'Todas']];
const soStatusHtml = (code, labels) => {
  const cls = { concluida: 'ok', faturada: 'ok', pausada: 'warn', em_execucao: 'accent', agendada: 'accent' }[code] || '';
  return `<span class="badge ${cls}">${esc(labels[code] || code)}</span>`;
};
const soCustomer = (r) => esc(r.customer_trade_name || r.customer_name || '');
const dtLocal = (s) => (s ? String(s).replace(' ', 'T').slice(0, 16) : '');

async function viewServiceOrders(v) {
  const f = { status: prefGet('soStatus', 'abertas'), q: '' };
  v.innerHTML = `<div class="page-head"><div><h1>Ordens de serviço</h1><p class="muted">Atendimentos aos clientes, de chamados avulsos ou de orçamentos.</p></div>
      <div class="actions"><a class="btn primary" href="#/ordem/nova">${icon('plus')}Nova ordem</a></div></div>
    <div class="card"><div class="filters" style="align-items:center">
      <div class="seg" id="tabs">${SO_TABS.map(([k, l]) => `<button data-s="${k}">${l}</button>`).join('')}</div>
      <label class="grow">Buscar <input id="q" type="search" placeholder="Nº, cliente, equipamento ou descrição"></label></div>
      <div id="list"></div></div>`;
  let labels = {};
  const load = async () => {
    $$('#tabs [data-s]', v).forEach((b) => b.classList.toggle('on', b.dataset.s === f.status));
    const d = await api('service_orders', { params: { status: f.status, q: f.q } });
    labels = d.statuses;
    $('#list', v).innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Nº</th><th>Cliente</th><th>Equipamento</th><th>Tipo</th><th>Técnico</th><th>Agendada</th><th>Status</th></tr></thead><tbody>
      ${d.rows.map((r) => `<tr class="click" data-id="${r.id}"><td class="nowrap">#${r.id}</td><td><strong>${soCustomer(r)}</strong></td><td>${esc(r.equipment_name || '—')}</td>
        <td>${esc(r.task_type_name || '—')}</td><td>${esc(r.technician_name || '—')}</td><td class="nowrap">${r.scheduled_at ? dt(r.scheduled_at) : '—'}</td>
        <td>${soStatusHtml(r.status, labels)}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhuma ordem de serviço aqui. Clique em <strong>Nova ordem</strong>.</div>';
  };
  $$('#tabs [data-s]', v).forEach((b) => (b.onclick = () => { f.status = b.dataset.s; prefSet('soStatus', f.status); load().catch(fail); }));
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; load().catch(fail); });
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('ordem/' + r.dataset.id); };
  await load();
}

async function viewServiceOrderForm(v, id) {
  const isNew = !id;
  const o = isNew ? { status: 'aberta', origin: 'chamado' } : await api('service_order', { params: { id } });
  const form = await api('service_order_form', { params: { customer_id: o.customer_id || 0 } });
  const customers = await api('customers', { params: { active: '1', limit: 200, sort: 'asc' } });
  const fields = (f) => {
    const sel = (name, list, valueKey, labelFn, cur, empty) => `<select name="${name}">${empty ? `<option value="">${empty}</option>` : ''}${list.map((x) => `<option value="${esc(x[valueKey])}" ${String(cur ?? '') === String(x[valueKey]) ? 'selected' : ''}>${esc(labelFn(x))}</option>`).join('')}</select>`;
    $('#eqs', v).innerHTML = sel('equipment_id', f.equipments, 'id', (e) => `${e.name}${e.serial ? ' · ' + e.serial : ''}`, o.equipment_id, 'Nenhum equipamento');
    $('#qts', v).innerHTML = sel('quote_id', f.quotes, 'id', (q) => `Orçamento #${q.id}${q.lead_name ? ' · ' + q.lead_name : ''} (${dOnly(q.created_at)})`, o.quote_id, 'Selecione um orçamento');
  };
  const orig = o.origin || 'chamado';
  const taskOpts = (() => {
    const list = form.task_types.map((t) => ({ id: t.id, name: t.name }));
    if (o.task_type_id && !list.some((t) => +t.id === +o.task_type_id)) list.push({ id: o.task_type_id, name: `${o.task_type_name || 'Tipo removido'} (inativo)` });
    return list.map((t) => `<option value="${t.id}" ${+o.task_type_id === +t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
  })();
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/ordens">← Ordens de serviço</a></p><h1>${isNew ? 'Nova ordem de serviço' : 'Ordem de serviço #' + o.id}</h1>
      ${isNew ? '' : `<p class="muted">${soStatusHtml(o.status, form.statuses)} ${o.origin_label ? `· ${esc(o.origin_label)}` : ''}</p>`}</div>${isNew ? '' : `<div class="actions"><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn js-edit-only" id="socancel">${icon('x')}Cancelar</button></div>`}</div>
    <div class="card"><form class="form" id="soform">
      <label>Cliente * <select name="customer_id" id="cust" required><option value="">Selecione…</option>${customers.rows.map((c) => `<option value="${c.id}" ${+o.customer_id === +c.id ? 'selected' : ''}>${esc(custName(c))}</option>`).join('')}</select></label>
      <div class="grid two"><label>Equipamento <span id="eqs"></span></label>
        <label>Tipo de tarefa <select name="task_type_id" required><option value="">Selecione…</option>${taskOpts}</select></label></div>
      <fieldset class="fset"><legend>Origem</legend>
        <label class="check"><input type="radio" name="origin" value="chamado" ${orig === 'chamado' ? 'checked' : ''}> Chamado avulso</label>
        <label class="check"><input type="radio" name="origin" value="orcamento" ${orig === 'orcamento' ? 'checked' : ''}> Orçamento do cliente</label>
        <div id="qts-wrap" ${orig === 'orcamento' ? '' : 'hidden'}><span id="qts"></span></div></fieldset>
      <label>Descrição do serviço * <textarea name="description" required rows="3" maxlength="2000" placeholder="O que o cliente pediu ou relatou">${esc(o.description)}</textarea></label>
      <div class="grid two">
        <label>Técnico <select name="technician_id"><option value="">Sem técnico</option>${form.technicians.map((t) => `<option value="${t.id}" ${+o.technician_id === +t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label></div>
      <div class="grid two"><label>Agendar para <input type="datetime-local" name="scheduled_at" value="${dtLocal(o.scheduled_at)}"></label>
        <label>Prazo <input type="date" name="due_date" value="${esc(o.due_date || '')}"></label></div>
      <label>Observações internas <input name="notes" maxlength="500" value="${esc(o.notes)}"></label>
      <div class="actions">${isNew ? '<a class="btn" href="#/ordens">Cancelar</a>' : ''}<button class="btn primary js-edit-only" type="submit" id="sosave">Salvar ordem</button></div>
    </form>${isNew ? '' : `<div class="so-status"><h3>Andamento</h3>
      ${o.transitions.length ? `<div class="actions">${o.transitions.map((s) => `<button class="btn" data-to="${s}">${icon('check')}Mover para "${esc(form.statuses[s])}"</button>`).join('')}</div>` : '<p class="muted">Ordem finalizada: não há mais mudanças de status.</p>'}
    </div>`}</div>`;
  fields(form);
  const reloadCustomer = async () => {
    const cid = $('#cust', v).value;
    const f = await api('service_order_form', { params: { customer_id: cid || 0 } });
    o.equipment_id = null; o.quote_id = null;
    fields(f);
  };
  $('#cust', v).onchange = () => reloadCustomer().catch(fail);
  $$('[name="origin"]', v).forEach((r) => (r.onchange = () => { $('#qts-wrap', v).hidden = r.value !== 'orcamento'; }));
  editLock(v, $('#soform', v), !isNew);
  const socancel = $('#socancel', v); if (socancel) socancel.onclick = () => route();
  $('#soform', v).onsubmit = (e) => {
    e.preventDefault();
    busy($('#sosave', v), async () => {
      const d = formData($('#soform', v));
      if (d.origin === 'orcamento' && !d.quote_id) throw new Error('Selecione o orçamento');
      const r = await api('service_order_save', { body: { ...d, id: isNew ? undefined : o.id } });
      toast('Ordem salva');
      go('ordem/' + r.id);
    });
  };
  $$('[data-to]', v).forEach((b) => (b.onclick = () => busy(b, async () => {
    const to = b.dataset.to;
    if (to === 'faturada' && !(await confirmBox('Marcar esta ordem como faturada? Ela não poderá mais ser alterada.', { ok: 'Marcar como faturada' }))) return;
    await api('service_order_status', { body: { id: o.id, status: to } });
    toast('Status atualizado');
    route();
  })));
}

async function viewEquipments(v) {
  const f = { q: '', all: false };
  v.innerHTML = `<div class="page-head"><div><h1>Equipamentos</h1><p class="muted">Aparelhos dos clientes que recebem manutenção e atendimento.</p></div>
      <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo equipamento</button></div></div>
    <div class="card"><div class="filters"><label class="grow">Buscar <input id="q" type="search" placeholder="Nome, marca, modelo, identificador ou cliente"></label>
      <label class="check"><input type="checkbox" id="all"> Mostrar inativos</label></div><div id="list"></div></div>`;
  const load = async () => {
    const rows = await api('equipments', { params: { q: f.q, all: f.all ? 1 : '' } });
    $('#list', v).innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th>Equipamento</th><th>Cliente</th><th>Identificador</th><th>Garantia</th><th></th></tr></thead><tbody>
      ${rows.map((e) => `<tr class="click" data-id="${e.id}"><td><strong>${esc(e.name)}</strong><div class="small muted">${esc([e.brand, e.model, e.category].filter(Boolean).join(' · '))}</div></td>
        <td>${esc(e.customer_trade_name || e.customer_name)}</td><td>${esc(e.serial || '—')}</td><td>${e.warranty_until ? esc(e.warranty_until.split('-').reverse().join('/')) : '—'}</td><td>${+e.active ? '' : '<span class="badge">inativo</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhum equipamento cadastrado. Clique em <strong>Novo equipamento</strong>.</div>';
  };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; load().catch(fail); });
  $('#all', v).onchange = (e) => { f.all = e.target.checked; load().catch(fail); };
  $('#new', v).onclick = () => equipmentForm(null, load).catch(fail);
  $('#list', v).onclick = async (e) => {
    const r = e.target.closest('[data-id]');
    if (!r) return;
    const rows = await api('equipments', { params: { all: 1 } });
    equipmentForm(rows.find((x) => x.id == r.dataset.id), load).catch(fail);
  };
  await load();
}

async function equipmentForm(eq, onSaved) {
  const isNew = !eq;
  const customers = await api('customers', { params: { active: '1', limit: 200, sort: 'asc' } });
  const specs = (eq?.specs || []);
  const m = modal({
    title: isNew ? 'Novo equipamento' : 'Editar equipamento',
    body: `<div class="seg" id="eqtabs"><button type="button" data-et="geral" class="on">Geral</button><button type="button" data-et="specs">Especificações</button>${isNew ? '' : '<button type="button" data-et="ordens">Ordens</button>'}</div>
    <form class="form" id="eqf">
      <div data-pane="geral">
        <label>Cliente * <select name="customer_id" required><option value="">Selecione…</option>${customers.rows.map((c) => `<option value="${c.id}" ${eq && +eq.customer_id === +c.id ? 'selected' : ''}>${esc(custName(c))}</option>`).join('')}</select></label>
        <label>Nome do equipamento * <input name="name" required maxlength="120" value="${esc(eq?.name)}" placeholder="Ex.: Compressor de parafuso"></label>
        <div class="grid two"><label>Identificador <input name="serial" maxlength="80" value="${esc(eq?.serial)}" placeholder="Nº de série ou código"></label><label>Categoria <input name="category" maxlength="80" value="${esc(eq?.category)}" placeholder="Ex.: Compressores"></label></div>
        <div class="grid two"><label>Marca <input name="brand" maxlength="80" value="${esc(eq?.brand)}"></label><label>Modelo <input name="model" maxlength="80" value="${esc(eq?.model)}"></label></div>
        <div class="grid two"><label>Data de validade <input name="valid_until" type="date" value="${esc(eq?.valid_until)}"></label><label>Garantia até <input name="warranty_until" type="date" value="${esc(eq?.warranty_until)}"></label></div>
        <label>Descrição <textarea name="notes" maxlength="500" rows="3">${esc(eq?.notes)}</textarea></label>
      </div>
      <div data-pane="specs" hidden>
        <p class="small muted">Dados técnicos do aparelho, como potência, voltagem ou pressão de trabalho.</p>
        <div id="splist"></div>
        <button type="button" class="btn ghost js-needs-edit" id="spadd">${icon('plus')}Adicionar especificação</button>
      </div>
      ${isNew ? '' : '<div data-pane="ordens" hidden><div id="eqhist"><p class="muted">Carregando…</p></div></div>'}
    </form>`,
    foot: `${!isNew ? `<button class="btn ghost" id="eqact" style="margin-right:auto">${+eq.active ? 'Desativar' : 'Reativar'}</button>` : ''}<button class="btn js-edit-only" data-close>Cancelar</button><button class="btn js-view-only" data-close>Fechar</button><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn primary js-edit-only" id="eqsave">Salvar equipamento</button>`,
  });
  const f = $('#eqf', m.el);
  const splist = $('#splist', m.el);
  const specRow = (sp = {}) => {
    const row = document.createElement('div');
    row.className = 'grid two spec-row';
    row.innerHTML = `<label>Especificação <input class="sp-l" maxlength="80" value="${esc(sp.label)}" placeholder="Ex.: Potência"></label>
      <label>Valor <span class="spec-val"><input class="sp-v" maxlength="200" value="${esc(sp.value)}" placeholder="Ex.: 5 HP"><button type="button" class="btn ghost sp-del" title="Remover">${icon('x')}</button></span></label>`;
    row.querySelector('.sp-del').onclick = () => row.remove();
    splist.appendChild(row);
  };
  specs.forEach((sp) => specRow(sp));
  $('#spadd', m.el).onclick = () => { specRow(); splist.lastElementChild.querySelector('.sp-l').focus(); };
  // Histórico de ordens do equipamento: carrega só ao abrir a aba
  let histLoaded = false;
  const loadHist = async () => {
    if (histLoaded || isNew) return;
    histLoaded = true;
    const box = $('#eqhist', m.el);
    try {
      const d = await api('service_orders', { params: { equipment_id: eq.id, status: 'todas' } });
      const abertas = d.rows.filter((o) => SO_OPEN_CODES.includes(o.status)).length;
      box.innerHTML = `<p class="small"><strong>${abertas}</strong> em aberto · ${d.rows.length} no total</p>`
        + (d.rows.length ? soTableHtml(d.rows, d.statuses, d.types, { withEquipment: false }) : '<p class="muted">Este equipamento ainda não tem ordens de serviço.</p>');
      box.onclick = (e) => { const r = e.target.closest('[data-so]'); if (r) { m.close(); go('ordem/' + r.dataset.so); } };
    } catch (err) { histLoaded = false; fail(err); }
  };
  $$('#eqtabs button', m.el).forEach((b) => (b.onclick = () => {
    $$('#eqtabs button', m.el).forEach((x) => x.classList.toggle('on', x === b));
    $$('[data-pane]', m.el).forEach((p) => (p.hidden = p.dataset.pane !== b.dataset.et));
    if (b.dataset.et === 'ordens') loadHist();
  }));
  const save = (btn) => busy(btn, async () => {
    const d = formData(f);
    if (!d.customer_id) throw new Error('Selecione o cliente');
    if (!d.name.trim()) throw new Error('Informe o nome do equipamento');
    const list = $$('.spec-row', m.el).map((r) => ({ label: r.querySelector('.sp-l').value, value: r.querySelector('.sp-v').value }));
    if (list.some((x) => !x.label.trim() && x.value.trim())) throw new Error('Informe o nome de cada especificação');
    await api('equipment_save', { body: { ...d, id: eq?.id, specs: list } });
    m.close(); toast('Equipamento salvo'); onSaved && onSaved();
  });
  editLock(m.el, f, !isNew);
  $('#eqsave', m.el).onclick = (e) => save(e.currentTarget);
  f.onsubmit = (e) => { e.preventDefault(); save($('#eqsave', m.el)); };
  const act = $('#eqact', m.el);
  if (act) act.onclick = () => busy(act, async () => {
    const to = !+eq.active;
    if (!to && !(await confirmBox(`Desativar o equipamento "${eq.name}"? Ele some das listas, mas continua nas ordens antigas.`, { ok: 'Desativar', danger: true }))) return;
    await api('equipment_active', { body: { id: eq.id, active: to } });
    m.close(); toast(to ? 'Equipamento reativado' : 'Equipamento desativado'); onSaved && onSaved();
  });
}

// ============ Tipos de tarefa ============
async function viewTaskTypes(v) {
  const f = { all: false };
  v.innerHTML = `<div class="page-head"><div><h1>Tipos de tarefa</h1><p class="muted">Cada tipo escolhe os questionários que o técnico responde e o que é obrigatório na ordem.</p></div>
      <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo tipo</button></div></div>
    <div class="card"><div class="filters"><label class="check"><input type="checkbox" id="all"> Mostrar inativos</label></div><div id="list"></div></div>`;
  let rows = [];
  let qRows = [];
  let meta = null;
  const load = async () => {
    meta = await api('task_types', { params: { all: f.all ? 1 : '' } });
    qRows = (await api('questionnaires', { params: { all: 1 } })).rows;
    rows = meta.rows;
    const qName = Object.fromEntries(qRows.map((q) => [q.id, q.name]));
    $('#list', v).innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th>Tipo de tarefa</th><th>Questionários</th><th>Tolerância</th><th>Duração</th><th></th></tr></thead><tbody>
      ${rows.map((t) => `<tr class="click" data-id="${t.id}"><td><strong>${esc(t.name)}</strong></td>
        <td>${t.questionnaire_ids.length ? esc(t.questionnaire_ids.map((id) => qName[id] || '—').join(', ')) : '<span class="muted">Nenhum</span>'}</td>
        <td>${esc(t.tolerance || '—')}</td><td>${esc(t.duration || '—')}</td><td>${+t.active ? '' : '<span class="badge">inativo</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhum tipo de tarefa. Clique em <strong>Novo tipo</strong>.</div>';
  };
  $('#all', v).onchange = (e) => { f.all = e.target.checked; load().catch(fail); };
  $('#new', v).onclick = () => taskTypeForm(null, meta, qRows, load);
  $('#list', v).onclick = (e) => {
    const r = e.target.closest('[data-id]');
    if (r) taskTypeForm(rows.find((x) => x.id == r.dataset.id), meta, qRows, load);
  };
  await load();
}

function taskTypeForm(t, meta, qRows, onSaved) {
  const isNew = !t;
  const chosen = new Set(t?.questionnaire_ids || []);
  const reqs = new Set(t?.requires || []);
  // Questionários ativos, mais os inativos que este tipo ainda usa (para não sumirem ao salvar).
  const qList = qRows.filter((q) => +q.active || chosen.has(+q.id));
  const md = modal({
    title: isNew ? 'Novo tipo de tarefa' : 'Editar tipo de tarefa',
    wide: true,
    body: `<form class="form" id="ttf">
      <label>Descrição * <input name="name" required maxlength="120" value="${esc(t?.name)}" placeholder="Ex.: Manutenção preventiva"></label>
      <div class="grid two"><label>Tempo de tolerância <input name="tolerance" value="${esc(t?.tolerance || '')}" placeholder="HH:MM" inputmode="numeric"></label>
        <label>Tempo estimado de duração <input name="duration" value="${esc(t?.duration || '')}" placeholder="HH:MM" inputmode="numeric"></label></div>
      <fieldset class="fset"><legend>Questionários deste tipo</legend>
        ${qList.length ? qList.map((q) => `<label class="check"><input type="checkbox" name="questionnaire_ids" value="${q.id}" ${chosen.has(+q.id) ? 'checked' : ''}> ${esc(q.name)}${+q.active ? '' : ' <span class="badge">inativo</span>'}</label>`).join('')
          : '<p class="small muted">Nenhum questionário cadastrado. Crie em Cadastros → Questionários.</p>'}</fieldset>
      <fieldset class="fset"><legend>Obrigatoriedades</legend>
        ${Object.entries(meta.requires).map(([k, l]) => `<label class="check"><input type="checkbox" name="requires" value="${k}" ${reqs.has(k) ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</fieldset>
      <fieldset class="fset"><legend>Envio ao cliente</legend>
        <label class="check"><input type="checkbox" name="send_survey" ${t && +t.send_survey ? 'checked' : ''}> Enviar pesquisa de satisfação</label>
        <label class="check"><input type="checkbox" name="send_os_email" ${t && +t.send_os_email ? 'checked' : ''}> Enviar OS digital por e-mail</label></fieldset>
    </form>`,
    foot: `${!isNew ? `<button class="btn ghost" id="ttact" style="margin-right:auto">${+t.active ? 'Desativar' : 'Reativar'}</button>` : ''}<button class="btn js-edit-only" data-close>Cancelar</button><button class="btn js-view-only" data-close>Fechar</button><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn primary js-edit-only" id="ttsave">Salvar tipo</button>`,
  });
  const f = $('#ttf', md.el);
  const save = (btn) => busy(btn, async () => {
    const d = formData(f);
    if (!d.name.trim()) throw new Error('Informe a descrição do tipo de tarefa');
    await api('task_type_save', { body: {
      id: t?.id,
      name: d.name,
      tolerance: d.tolerance,
      duration: d.duration,
      send_survey: d.send_survey,
      send_os_email: d.send_os_email,
      questionnaire_ids: $$('[name=questionnaire_ids]:checked', f).map((i) => +i.value),
      requires: $$('[name=requires]:checked', f).map((i) => i.value),
    } });
    md.close(); toast('Tipo salvo'); onSaved && onSaved();
  });
  editLock(md.el, f, !isNew);
  $('#ttsave', md.el).onclick = (e) => save(e.currentTarget);
  f.onsubmit = (e) => { e.preventDefault(); save($('#ttsave', md.el)); };
  const act = $('#ttact', md.el);
  if (act) act.onclick = () => busy(act, async () => {
    const to = !+t.active;
    if (!to && !(await confirmBox(`Desativar o tipo "${t.name}"? Ordens antigas continuam com ele.`, { ok: 'Desativar', danger: true }))) return;
    await api('task_type_active', { body: { id: t.id, active: to } });
    md.close(); toast(to ? 'Tipo reativado' : 'Tipo desativado'); onSaved && onSaved();
  });
}

// ============ Questionários ============
async function viewQuestionnaires(v) {
  const f = { all: false };
  v.innerHTML = `<div class="page-head"><div><h1>Questionários</h1><p class="muted">Perguntas que o técnico responde na ordem. Um questionário pode ser usado em vários tipos de tarefa.</p></div>
      <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo questionário</button></div></div>
    <div class="card"><div class="filters"><label class="check"><input type="checkbox" id="all"> Mostrar inativos</label></div><div id="list"></div></div>`;
  const load = async () => {
    const d = await api('questionnaires', { params: { all: f.all ? 1 : '' } });
    $('#list', v).innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Questionário</th><th class="num">Perguntas</th><th>Equipamento</th><th></th></tr></thead><tbody>
      ${d.rows.map((q) => `<tr class="click" data-id="${q.id}"><td><strong>${esc(q.name)}</strong></td><td class="num">${q.questions_count}</td>
        <td>${q.allow_equipment ? (q.equipment_required ? 'Obrigatório' : 'Opcional') : '—'}</td>
        <td>${+q.active ? '' : '<span class="badge">inativo</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhum questionário. Clique em <strong>Novo questionário</strong>.</div>';
  };
  $('#all', v).onchange = (e) => { f.all = e.target.checked; load().catch(fail); };
  $('#new', v).onclick = () => go('questionario/nova');
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('questionario/' + r.dataset.id); };
  await load();
}

const Q_OPTION_TYPES = ['itens', 'multipla'];
const newQid = () => 'p' + Math.random().toString(36).slice(2, 9);

async function viewQuestionnaireForm(v, id) {
  const isNew = !id;
  const q = isNew
    ? { name: '', per_line: 1, show_os: 1, pairs: 0, show_unanswered: 0, allow_equipment: 0, equipment_required: 0, active: 1, questions: [] }
    : await api('questionnaire', { params: { id } });
  let locked = !isNew;
  let qs = (q.questions || []).map((x) => ({ ...x, options: x.options || [] }));
  const typeOpts = (cur) => Object.entries(types).map(([k, l]) => `<option value="${k}" ${cur === k ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const types = (await api('questionnaires', { params: { all: 1 } })).types; // lista de tipos de resposta

  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/questionarios">← Questionários</a></p><h1>${isNew ? 'Novo questionário' : esc(q.name)}</h1>
      ${isNew ? '' : `<p class="muted">${+q.active ? '' : '<span class="badge">inativo</span>'} ${q.questions_count} pergunta(s)</p>`}</div>
      <div class="actions">${isNew ? '' : `<button class="btn ghost" id="qact">${+q.active ? 'Desativar' : 'Reativar'}</button><button class="btn js-view-only" data-edit>${icon('pencil')}Editar</button><button class="btn js-edit-only" id="qcancel">${icon('x')}Cancelar</button>`}<button class="btn primary js-edit-only" id="qsave">${icon('check')}Salvar questionário</button></div></div>
    <div class="card"><form class="form" id="qform">
      <label>Nome * <input name="name" required maxlength="120" value="${esc(q.name)}" placeholder="Ex.: Checklist de compressor"></label>
      <div class="grid two"><label>Perguntas por linha <select name="per_line">${[1, 2, 3, 4].map((n) => `<option value="${n}" ${+q.per_line === n ? 'selected' : ''}>${n}</option>`).join('')}</select></label></div>
      <fieldset class="fset"><legend>Comportamento</legend>
        <label class="check"><input type="checkbox" name="show_os" ${+q.show_os ? 'checked' : ''}> Mostrar as respostas na OS</label>
        <label class="check"><input type="checkbox" name="pairs" ${+q.pairs ? 'checked' : ''}> Agrupar perguntas em pares</label>
        <label class="check"><input type="checkbox" name="show_unanswered" ${+q.show_unanswered ? 'checked' : ''}> Mostrar perguntas sem resposta</label>
        <label class="check"><input type="checkbox" name="allow_equipment" id="qeq" ${+q.allow_equipment ? 'checked' : ''}> Permitir vincular um equipamento</label>
        <label class="check"><input type="checkbox" name="equipment_required" id="qeqr" ${+q.equipment_required ? 'checked' : ''} ${+q.allow_equipment ? '' : 'disabled'}> Equipamento obrigatório</label></fieldset>
    </form></div>
    <h2 class="qtitle">Perguntas</h2>
    <div id="qlist"></div>
    <div class="actions"><button class="btn js-needs-edit" id="qadd">${icon('plus')}Adicionar pergunta</button></div>`;

  const box = $('#qlist', v);
  const earlierOf = (list, i) => list.slice(0, i).filter((x) => x.id);

  // Lê o que está na tela para o estado, antes de qualquer mudança estrutural.
  const readQs = () => {
    const out = [];
    $$('.qcard', box).forEach((card, i) => {
      const val = (k) => card.querySelector(`[data-k="${k}"]`);
      const type = val('type').value;
      const item = {
        id: card.dataset.qid,
        label: val('label').value.trim(),
        type,
        required: val('required').checked,
        description: val('description').value.trim(),
        options: Q_OPTION_TYPES.includes(type) ? val('options').value.split(/\r?\n/).map((s) => s.trim()).filter(Boolean) : [],
        cond: null,
      };
      if (type === 'foto') item.geo = val('geo').checked;
      if (val('hascond') && val('hascond').checked && val('condq')) {
        item.cond = { question: val('condq').value, value: val('condv').value };
      }
      out.push(item);
    });
    qs = out;
  };

  const render = () => {
    const d = locked ? 'disabled' : '';
    box.innerHTML = qs.length ? qs.map((x, i) => {
      const earlier = earlierOf(qs, i);
      const condQ = x.cond ? earlier.find((e) => e.id === x.cond.question) : null;
      const hasCond = !!x.cond;
      return `<div class="qcard" data-qid="${esc(x.id)}">
        <div class="qhead"><span class="qnum">${i + 1}</span>
          <input ${d} data-k="label" maxlength="200" placeholder="Texto da pergunta" value="${esc(x.label)}" class="qlabel">
          <select ${d} data-k="type" class="qtype">${typeOpts(x.type)}</select>
          <label class="check"><input type="checkbox" ${d} data-k="required" ${x.required ? 'checked' : ''}> Obrigatória</label>
          <span class="qtools">
            <button class="icon-btn" data-act="up" title="Subir" aria-label="Subir" ${d || (i === 0 ? 'disabled' : '')}>▲</button>
            <button class="icon-btn" data-act="down" title="Descer" aria-label="Descer" ${d || (i === qs.length - 1 ? 'disabled' : '')}>▼</button>
            <button class="icon-btn" ${d} data-act="dup" title="Duplicar" aria-label="Duplicar">${icon('copy')}</button>
            <button class="icon-btn" ${d} data-act="del" title="Remover" aria-label="Remover">${icon('trash')}</button>
          </span></div>
        <input ${d} data-k="description" maxlength="300" placeholder="Ajuda para o técnico (opcional)" value="${esc(x.description || '')}" class="qdesc">
        ${Q_OPTION_TYPES.includes(x.type) ? `<label>Opções <textarea ${d} data-k="options" rows="4" placeholder="Uma opção por linha">${esc(x.options.join('\n'))}</textarea></label>` : ''}
        ${x.type === 'foto' ? `<label class="check"><input type="checkbox" ${d} data-k="geo" ${x.geo ? 'checked' : ''}> Registrar a localização da foto</label>` : ''}
        <div class="qcond">
          <label class="check"><input type="checkbox" data-k="hascond" ${hasCond ? 'checked' : ''} ${d || (earlier.length ? '' : 'disabled')}> Mostrar só quando a pergunta anterior tiver uma resposta</label>
          ${hasCond ? `<div class="grid two">
            <label>Pergunta anterior <select ${d} data-k="condq">${earlier.map((e) => `<option value="${esc(e.id)}" ${x.cond.question === e.id ? 'selected' : ''}>${esc(e.label || '(sem texto)')}</option>`).join('')}</select></label>
            <label>Resposta que mostra esta pergunta ${condQ && condQ.options.length
              ? `<select ${d} data-k="condv">${condQ.options.map((o) => `<option value="${esc(o)}" ${x.cond.value === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`
              : `<input ${d} data-k="condv" maxlength="120" value="${esc(x.cond.value)}">`}</label></div>` : ''}
        </div></div>`;
    }).join('') : '<div class="empty">Nenhuma pergunta. Clique em <strong>Adicionar pergunta</strong>.</div>';
  };

  // Mudanças de estrutura: lê a tela, muda o estado e redesenha.
  box.onclick = (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    readQs();
    const i = $$('.qcard', box).indexOf(b.closest('.qcard'));
    const act = b.dataset.act;
    if (act === 'del') qs.splice(i, 1);
    if (act === 'up' && i > 0) [qs[i - 1], qs[i]] = [qs[i], qs[i - 1]];
    if (act === 'down' && i < qs.length - 1) [qs[i + 1], qs[i]] = [qs[i], qs[i + 1]];
    if (act === 'dup') qs.splice(i + 1, 0, { ...qs[i], id: newQid(), options: [...qs[i].options] });
    // Perguntas que dependiam de uma pergunta removida perdem a condição.
    qs = qs.map((x, j) => (x.cond && !earlierOf(qs, j).some((e) => e.id === x.cond.question) ? { ...x, cond: null } : x));
    render();
  };
  box.onchange = (e) => {
    const k = e.target.dataset.k;
    if (!k) return;
    // Trocar o tipo, marcar condição ou escolher pergunta anterior muda a tela: relê e redesenha.
    if (['type', 'hascond', 'condq'].includes(k)) {
      readQs();
      const i = $$('.qcard', box).indexOf(e.target.closest('.qcard'));
      if (k === 'hascond') {
        const first = earlierOf(qs, i)[0];
        qs[i].cond = e.target.checked && first ? { question: first.id, value: first.options[0] || '' } : null;
      }
      if (k === 'condq') {
        const pq = earlierOf(qs, i).find((x) => x.id === e.target.value);
        qs[i].cond = { question: e.target.value, value: pq?.options[0] || '' };
      }
      if (k === 'type') {
        qs[i].type = e.target.value;
        if (!Q_OPTION_TYPES.includes(qs[i].type)) qs[i].options = [];
      }
      render();
    }
  };

  $('#qeq', v).onchange = (e) => { $('#qeqr', v).disabled = !e.target.checked; if (!e.target.checked) $('#qeqr', v).checked = false; };
  $('#qadd', v).onclick = () => { readQs(); qs.push({ id: newQid(), label: '', type: 'texto', required: false, description: '', options: [], cond: null }); render(); $$('.qlabel', box).at(-1)?.focus(); };

  const save = (btn) => busy(btn, async () => {
    readQs();
    const d = formData($('#qform', v));
    if (!d.name.trim()) throw new Error('Informe o nome do questionário');
    const body = {
      id: isNew ? undefined : id,
      name: d.name,
      per_line: d.per_line,
      show_os: d.show_os,
      pairs: d.pairs,
      show_unanswered: d.show_unanswered,
      allow_equipment: d.allow_equipment,
      equipment_required: d.equipment_required,
      questions: qs.map((x) => ({ ...x, options: x.options || [] })),
    };
    const res = await api('questionnaire_save', { body });
    toast('Questionário salvo');
    if (isNew) go('questionario/' + res.id); else route();
  });
  $('#qsave', v).onclick = (e) => save(e.currentTarget).catch(fail);

  const act = $('#qact', v);
  if (act) act.onclick = () => busy(act, async () => {
    const to = !+q.active;
    if (!to && !(await confirmBox(`Desativar o questionário "${q.name}"? Tipos de tarefa que o usam deixam de receber ele.`, { ok: 'Desativar', danger: true }))) return;
    await api('questionnaire_active', { body: { id, active: to } });
    toast(to ? 'Questionário reativado' : 'Questionário desativado'); route();
  });

  editLock(v, $('#qform', v), !isNew, (l) => { locked = l; $('#qeqr', v).disabled = l || !$('#qeq', v).checked; render(); });
  const qc = $('#qcancel', v); if (qc) qc.onclick = () => route();
}

// ============ Fornecedores ============
async function viewSuppliers(v) {
  const f = { q: '', active: '1', page: 1 };
  v.innerHTML = `<div class="page-head"><div><h1>Fornecedores</h1><p class="muted">Cadastre pelo CNPJ. As notas importadas também cadastram o fornecedor sozinhas.</p></div>
    <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo fornecedor</button></div></div>
    <div class="card"><div class="filters"><label class="grow">Buscar <input id="q" placeholder="Nome, CNPJ, cidade ou e-mail"></label>
      <label class="check"><input type="checkbox" id="inactive"> Mostrar inativos</label></div><div id="list"></div></div>`;
  const load = async () => {
    const d = await api('suppliers', { params: { q: f.q, active: f.active, page: f.page, limit: 50 } });
    const list = $('#list', v);
    list.innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th>Fornecedor</th><th>CNPJ / CPF</th><th>Cidade</th><th>Telefone</th><th class="num">Notas</th><th class="num">Total comprado</th><th>Última compra</th></tr></thead><tbody>
      ${d.rows.map((c) => `<tr class="click" data-id="${c.id}"><td><strong>${esc(custName(c))}</strong> ${+c.active ? '' : '<span class="badge">inativo</span>'}${+c.portal_open ? ' <span class="badge warn" title="Pedido de correção dos dados enviado pelo portal">correção pedida</span>' : ''}${c.trade_name ? `<div class="small muted">${esc(c.name)}</div>` : ''}</td>
        <td class="nowrap">${docFmt(c.document) || '<span class="muted">—</span>'}</td><td>${esc([c.city, c.state].filter(Boolean).join('/'))}</td><td class="nowrap">${esc(c.phone || '')}</td>
        <td class="num">${c.invoices_count}</td><td class="num">${money(c.purchased)}</td><td class="nowrap">${c.last_purchase ? dOnly(c.last_purchase) : '<span class="muted">—</span>'}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty">Nenhum fornecedor encontrado.</div>';
    if (d.total > 50) list.appendChild(pager(d.total, f.page, 50, (p) => { f.page = p; load(); }));
  };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; f.page = 1; load(); });
  $('#inactive', v).onchange = (e) => { f.active = e.target.checked ? 'all' : '1'; f.page = 1; load(); };
  $('#list', v).onclick = (e) => { const r = e.target.closest('[data-id]'); if (r) go('fornecedor/' + r.dataset.id); };
  $('#new', v).onclick = () => customerForm(null, (id) => go('fornecedor/' + id), { entity: 'supplier' });
  await load();
}

async function viewSupplier(v, id) {
  const c = await api('supplier', { params: { id } });
  const st = c.stats || {};
  const addr = [[c.street, c.number].filter(Boolean).join(', '), c.complement, c.district, [c.city, c.state].filter(Boolean).join('/'), cepFmt(c.cep)].filter(Boolean).join(' · ');
  const phone = String(c.phone || '').replace(/\D/g, '');
  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/fornecedores">← Fornecedores</a></p><h1>${esc(custName(c))} ${+c.active ? '' : '<span class="badge">inativo</span>'}</h1>
      <p class="muted">${c.trade_name ? esc(c.name) + ' · ' : ''}${c.type === 'pj' ? 'Empresa' : 'Pessoa física'}${c.document ? ' · ' + docFmt(c.document) : ''}</p></div>
    <div class="actions">${can('notas') ? `<a class="btn" href="#/notas/importar">${icon('upload')}Importar nota</a>` : ''}<button class="btn primary" id="edit">Editar</button>
      ${isAdmin() ? `<button class="btn ${+c.active ? 'danger' : ''}" id="toggle">${+c.active ? 'Desativar' : 'Reativar'}</button>` : ''}</div></div>
    <div class="kpis">
      <div class="kpi"><div class="label">Total comprado</div><div class="value">${money(st.total)}</div><div class="sub">${st.n || 0} nota(s)</div></div>
      <div class="kpi"><div class="label">Última compra</div><div class="value" style="font-size:1.1rem">${st.last_purchase ? dOnly(st.last_purchase) : '—'}</div><div class="sub">${st.first_purchase ? 'desde ' + dOnly(st.first_purchase) : ''}</div></div>
    </div>
    <div class="grid two">
      <div class="card"><h2>Dados</h2><dl class="dl">
        ${c.phone ? `<dt>Telefone</dt><dd>${esc(c.phone)} ${phone.length >= 10 ? `<a class="small" target="_blank" rel="noopener" href="https://wa.me/55${phone}">WhatsApp</a>` : ''}</dd>` : ''}
        ${c.email ? `<dt>E-mail</dt><dd><a href="mailto:${esc(c.email)}">${esc(c.email)}</a></dd>` : ''}
        ${c.state_registration ? `<dt>Inscrição estadual</dt><dd>${esc(c.state_registration)}</dd>` : ''}
        ${addr ? `<dt>Endereço</dt><dd>${esc(addr)}</dd>` : ''}
        ${c.notes ? `<dt>Observações</dt><dd>${esc(c.notes)}</dd>` : ''}
        ${!c.phone && !c.email && !addr && !c.notes ? '<dt>—</dt><dd class="muted">Sem dados de contato. Clique em Editar e busque pelo CNPJ.</dd>' : ''}
      </dl></div>
      <div class="card"><h2>O que compramos dele</h2>${c.products.length ? `<div class="table-wrap"><table><thead><tr><th>Produto</th><th class="num">Qtd.</th><th class="num">Último custo</th><th class="num">Total</th></tr></thead><tbody>${c.products.map((p) => `<tr><td><a href="#/produto/${p.id}">${esc(p.name)}</a></td><td class="num">${qty(p.qty)} ${esc(p.unit)}</td><td class="num">${cost(p.last_cost)}</td><td class="num">${money(p.total)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Nenhuma compra registrada.</p>'}</div>
    </div>
    <div class="card"><h2>Notas fiscais</h2>${c.invoices.length ? `<div class="table-wrap"><table><thead><tr><th>Data</th><th>Nota</th><th>Origem</th><th class="num">Itens</th><th class="num">Total</th><th>Por</th></tr></thead><tbody>
      ${c.invoices.map((i) => `<tr class="click" data-go="nota/${i.id}"><td class="nowrap">${dOnly(i.issued_at || i.created_at)}</td><td>${esc(i.number || 's/n')}${i.series ? ' / ' + esc(i.series) : ''}</td><td><span class="badge">${KIND_LABEL[i.kind] || i.kind}</span></td><td class="num">${i.items}</td><td class="num">${money(i.total)}</td><td class="small muted">${esc(i.user_name || '')}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Nenhuma nota importada deste fornecedor.</p>'}</div>`;
  v.addEventListener('click', (e) => { const r = e.target.closest('[data-go]'); if (r) go(r.dataset.go); });
  $('#edit', v).onclick = () => customerForm(c, () => route(), { entity: 'supplier', editing: true });
  if ($('#toggle', v)) $('#toggle', v).onclick = async (e) => {
    if (+c.active && !(await confirmBox(`Desativar "${custName(c)}"? O histórico de notas é mantido.`, { ok: 'Desativar', danger: true }))) return;
    busy(e.target, async () => { await api('supplier_save', { body: { ...c, active: !+c.active } }); toast('Fornecedor atualizado'); route(); });
  };
}

// ============ Permissões ============
/** Criar ou editar um perfil de permissão. */
function profileForm(pr, perms, onSaved) {
  const isNew = !pr;
  const has = new Set(pr ? pr.permissions : []);
  const m = modal({
    title: isNew ? 'Novo perfil de permissão' : `Editar perfil "${pr.name}"`,
    body: `<form class="form" id="prf"><label>Nome do perfil * <input name="name" maxlength="80" required value="${esc(pr?.name)}" placeholder="Ex.: Vendedor, Estoquista, Gerente"></label>
      <div><div class="inline" style="justify-content:space-between"><div class="qlabel-f">O que este perfil pode acessar</div><span><button type="button" class="btn sm ghost" id="all">Tudo</button><button type="button" class="btn sm ghost" id="none">Nada</button></span></div>
      <div class="perm-list">${Object.entries(perms).map(([k, l]) => `<label class="check"><input type="checkbox" value="${k}" ${has.has(k) ? 'checked' : ''}> ${esc(l)}</label>`).join('')}</div></div></form>`,
    foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="prsave">Salvar perfil</button>`,
  });
  $('#all', m.el).onclick = () => $$('.perm-list input', m.el).forEach((c) => (c.checked = true));
  $('#none', m.el).onclick = () => $$('.perm-list input', m.el).forEach((c) => (c.checked = false));
  const save = (btn) => busy(btn, async () => {
    const name = $('#prf input[name=name]', m.el).value.trim();
    if (!name) throw new Error('Dê um nome ao perfil');
    const list = $$('.perm-list input', m.el).filter((c) => c.checked).map((c) => c.value);
    const r = await api('profile_save', { body: { id: pr?.id, name, permissions: list } });
    m.close(); toast('Perfil salvo'); onSaved && onSaved(r.id);
  });
  $('#prsave', m.el).onclick = (e) => save(e.currentTarget);
  $('#prf', m.el).onsubmit = (e) => { e.preventDefault(); save($('#prsave', m.el)); };
}

async function viewPermissions(v) {
  const [{ profiles, perms }, users] = await Promise.all([api('profiles'), api('users')]);
  const keys = Object.keys(perms);
  const members = users.filter((u) => u.role !== 'admin');
  const admins = users.filter((u) => u.role === 'admin' && +u.active);
  const profOptions = (sel) => `<option value="">Sem perfil (acesso total)</option>${profiles.map((p) => `<option value="${p.id}" ${p.id == sel ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}`;
  v.innerHTML = `<div class="page-head"><div><h1>Permissões</h1><p class="muted">Crie perfis com o que cada tipo de pessoa pode acessar e escolha o perfil de cada usuário. Mudou o perfil, muda para todos que o usam.</p></div>
      <div class="actions"><button class="btn primary" id="newp">${icon('plus')}Novo perfil</button></div></div>
    <div class="card"><div class="card-head"><h2>Perfis de permissão</h2></div>
      ${profiles.length ? `<div class="table-wrap"><table class="perm-table"><thead><tr><th>Área</th>${profiles.map((p) => `<th class="perm-user"><div>${esc(p.name)}</div><div class="small muted" style="font-weight:400">${p.users} usuário(s)</div>
          <div class="perm-tools"><button class="btn sm ghost" data-edit="${p.id}">Editar</button><button class="btn sm ghost danger" data-del="${p.id}" ${p.users ? 'disabled title="Em uso"' : ''}>Excluir</button></div></th>`).join('')}</tr></thead>
        <tbody>${keys.map((k) => `<tr><td>${esc(perms[k])}</td>${profiles.map((p) => `<td class="perm-cell">${p.permissions.includes(k) ? `<span class="perm-yes" title="Liberado">${icon('check')}</span>` : '<span class="perm-no" title="Bloqueado">—</span>'}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`
        : '<div class="empty">Nenhum perfil criado. Clique em <strong>Novo perfil</strong>.</div>'}
    </div>
    <div class="card"><div class="card-head"><h2>Usuários</h2><a class="small" href="#/usuarios">cadastrar usuário</a></div>
      ${members.length ? `<div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Perfil de permissão</th><th>Pode acessar</th></tr></thead><tbody>
        ${members.map((u) => `<tr><td><strong>${esc(u.name)}</strong> ${+u.active ? '' : '<span class="badge">inativo</span>'}<div class="small muted">${esc(u.email)}</div></td>
          <td><select data-user="${u.id}" style="min-width:200px">${profOptions(u.profile_id)}</select></td>
          <td class="small">${u.permissions.length === keys.length ? '<span class="badge ok">tudo</span>' : u.permissions.length ? u.permissions.map((k) => esc(perms[k].split(' (')[0])).join(', ') : '<span class="badge err">nada</span>'}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted">Nenhum usuário além dos administradores. Cadastre em <a href="#/usuarios">Usuários</a>.</p>'}
      <p class="help" style="margin-bottom:0">Administradores (${admins.map((u) => esc(u.name)).join(', ') || '—'}) sempre têm acesso total, inclusive a Empresa, Usuários e Permissões. Para tornar alguém administrador, use <a href="#/usuarios">Usuários</a>.</p>
    </div>`;
  $('#newp', v).onclick = () => profileForm(null, perms, () => route());
  v.addEventListener('click', async (e) => {
    const ed = e.target.closest('[data-edit]');
    if (ed) { profileForm(profiles.find((p) => p.id == ed.dataset.edit), perms, () => route()); return; }
    const del = e.target.closest('[data-del]');
    if (del && !del.disabled) {
      const p = profiles.find((x) => x.id == del.dataset.del);
      if (!(await confirmBox(`Excluir o perfil "${p.name}"?`, { ok: 'Excluir', danger: true }))) return;
      busy(del, async () => { await api('profile_delete', { body: { id: p.id } }); toast('Perfil excluído'); route(); });
    }
  });
  v.addEventListener('change', async (e) => {
    const sel = e.target.closest('select[data-user]'); if (!sel) return;
    try { await api('user_save', { body: { id: sel.dataset.user, profile_id: sel.value || null } }); toast('Perfil do usuário atualizado'); route(); }
    catch (err) { fail(err); }
  });
}

// ============ Orçamentos ============
const Q_STATUS = {
  rascunho: { label: 'Rascunho', color: '#65a30d' },
  em_elaboracao: { label: 'Em elaboração', color: '#0284c7' },
  aguardando_envio: { label: 'Aguardando envio', color: '#7c3aed' },
  enviado: { label: 'Aguardando aprovação', color: '#d97706' },
  aprovado: { label: 'Aprovado', color: '#15803d' },
  recusado: { label: 'Recusado', color: '#dc2626' },
  vendido: { label: 'Vendido', color: '#475569' },
};
/** Status cadastrados em Configurações → Orçamento (nome, cor, ordem e se está ativo). */
function syncQStatus() {
  const list = state.meta && state.meta.quote_statuses; if (!list || !list.length) return;
  Object.keys(Q_STATUS).forEach((k) => delete Q_STATUS[k]);
  list.forEach((s) => (Q_STATUS[s.code] = { label: s.label, color: s.color, active: s.active !== false, system: !!s.system }));
}
/** Status que podem ser escolhidos à mão (ativos, sem "vendido"); mantém o atual mesmo se desativado. */
const qSelectable = (current) => Object.entries(Q_STATUS).filter(([k, s]) => k !== 'vendido' && (s.active !== false || k === current));
const qBadge = (st) => `<span class="qbadge" style="--c:${(Q_STATUS[st] || {}).color || '#666'}">${esc((Q_STATUS[st] || {}).label || st)}</span>`;
const daysBetween = (a, b) => Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000);
function qDeadline(q, todayIso) {
  if (q.status === 'vendido') return `<div class="qstrip ok">Virou a venda #${esc(q.sale_id)}</div>`;
  if (q.status === 'aprovado') return '<div class="qstrip ok">O orçamento foi aprovado</div>';
  if (q.status === 'recusado') return '<div class="qstrip err">O orçamento foi recusado</div>';
  if (!q.expires_at) return '';
  const d = daysBetween(todayIso, q.expires_at), when = dOnly(q.expires_at);
  if (d < 0) return `<div class="qstrip err">A proposta venceu há ${-d} dia${d === -1 ? '' : 's'} (${when})</div>`;
  if (d === 0) return `<div class="qstrip warn">A proposta vence hoje (${when})</div>`;
  return `<div class="qstrip info">A proposta vence em ${d} dia${d === 1 ? '' : 's'} (${when})</div>`;
}
const qClient = (q) => q.customer_id ? (q.customer_trade_name || q.customer_name) : (q.lead_name ? q.lead_name + ' (lead)' : '—');
const prefGet = (k, d) => { try { return localStorage.getItem(k) || d; } catch (_) { return d; } };
const prefSet = (k, v) => { try { localStorage.setItem(k, v); } catch (_) {} };

async function viewQuotes(v) {
  const f = { q: '', from: '', to: '', customer: '', status: '', min: '', max: '', user_id: '', filter: '', view: prefGet('quotesView', window.innerWidth < 760 ? 'lista' : 'kanban') };
  const team = await api('team');
  const KPIS = [
    ['pendentes_envio', 'Pendentes de envio', { pending: '1' }, '#7c3aed'],
    ['aguardando_aprovacao', 'Aguardando aprovação', { status: 'enviado' }, '#2563eb'],
    ['expiram_mes', 'Expiram este mês', { expiring: '1' }, '#d97706'],
    ['recusados_mes', 'Recusados no mês', { month: 'refused' }, '#dc2626'],
    ['aprovados_mes', 'Aprovados no mês', { month: 'approved' }, '#16a34a'],
    ['aguardando_venda', 'Aprovados aguardando venda', { status: 'aprovado' }, '#ca8a04'],
    ['vendidos_mes', 'Viraram venda no mês', { month: 'sold' }, '#475569'],
  ];
  v.innerHTML = `<div class="page-head"><div><h1>Orçamentos</h1></div>
      <div class="actions"><button class="btn" id="csv">Download</button><a class="btn primary" href="#/orcamento/novo">${icon('plus')}Adicionar orçamento</a></div></div>
    <div class="qkpis" id="kpis"></div>
    <div class="card"><div class="filters" style="align-items:center">
      <label class="grow">Buscar <input id="q" placeholder="Nº, cliente, lead ou código externo"></label>
      <button type="button" class="btn af-toggle" id="aftoggle" aria-expanded="false">${icon('filter')}Filtros <span class="bulk-n af-n" hidden></span></button>
      <div class="seg seg-field" id="view"><button data-v="lista">Lista</button><button data-v="kanban">Kanban</button></div>
      <div class="dd" id="bulk"><button type="button" class="btn dd-btn bulk-btn" aria-haspopup="menu" disabled>${icon('check')}Ações em massa <span class="bulk-n" hidden></span>${icon('chevron')}</button>
        <div class="dd-menu" role="menu" hidden>
          <button type="button" role="menuitem" data-bulk="status">${icon('swap')}Alterar status</button>
          <button type="button" role="menuitem" data-bulk="pdf">${icon('download')}Download PDF (.zip)</button>
          <button type="button" role="menuitem" data-bulk="enviado">${icon('send')}Marcar como enviado</button>
          <button type="button" role="menuitem" data-bulk="aprovado">${icon('check')}Marcar como aprovado</button>
          <button type="button" role="menuitem" data-bulk="recusado">${icon('x')}Marcar como recusado</button>
          <button type="button" role="menuitem" data-bulk="delete" class="danger">${icon('trash')}Excluir</button>
        </div></div>
    </div>
    <div id="afpanel" hidden>${filterPanelHtml({ statuses: qSelectable('').concat(Q_STATUS.vendido ? [['vendido', Q_STATUS.vendido]] : []).map(([k, s]) => [k, s.label]), team, f })}</div>
    <div class="inline" style="justify-content:space-between;margin-bottom:10px"><strong id="totals"></strong><span id="activefilter"></span></div>
    <div id="board"></div></div>`;
  let data = null;
  const sel = new Set(); // orçamentos marcados para as ações em massa
  const paintSel = () => {
    const n = sel.size, b = $('.bulk-btn', v), c = $('.bulk-btn .bulk-n', v);
    b.disabled = !n; c.hidden = !n; c.textContent = n;
    $$('[data-sel]', v).forEach((x) => { x.checked = sel.has(+x.dataset.sel); x.closest('[data-id]')?.classList.toggle('sel', x.checked); });
    const all = $('#selall', v);
    if (all) { const ids = data.rows.map((r) => r.id); const k = ids.filter((id) => sel.has(id)).length; all.checked = k > 0 && k === ids.length; all.indeterminate = k > 0 && k < ids.length; }
  };
  const load = async () => {
    const kp = KPIS.find((k) => k[0] === f.filter);
    const n = filterCount(f), tb = $('#aftoggle', v);
    $('.af-n', tb).hidden = !n; $('.af-n', tb).textContent = n; tb.classList.toggle('on', !!n);
    const params = { q: f.q, ...filterParams(f), limit: 500, ...(kp ? kp[2] : {}), ...(f.view === 'kanban' && !kp && !n ? { hide_old: '1' } : {}) };
    data = await api('quotes', { params });
    const sm = data.summary;
    $('#kpis', v).innerHTML = KPIS.map(([k, label, , c]) => `<button class="qkpi ${f.filter === k ? 'on' : ''}" data-k="${k}" style="--c:${c}"><span class="n">${sm[k] ?? 0}</span><span>${label}</span></button>`).join('');
    $('#totals', v).textContent = `Total: ${money(data.sum)} · Orçamentos: ${data.total}`;
    $('#activefilter', v).innerHTML = kp ? `<span class="badge accent">${esc(kp[1])}</span> <a href="#" id="clearf" class="small">limpar filtro</a>` : '';
    const cf = $('#clearf', v); if (cf) cf.onclick = (e) => { e.preventDefault(); f.filter = ''; load(); };
    $$('#view button', v).forEach((b) => b.classList.toggle('on', b.dataset.v === f.view));
    // Mantém marcados só os que continuam na tela
    const vis = new Set(data.rows.map((r) => r.id)); [...sel].forEach((id) => { if (!vis.has(id)) sel.delete(id); });
    (f.view === 'kanban' ? paintKanban : paintList)();
    paintSel();
  };
  const card = (q) => `<div class="qcard" draggable="${q.status !== 'vendido'}" data-id="${q.id}">
      <div class="qcard-top"><label class="qsel" title="Selecionar"><input type="checkbox" data-sel="${q.id}" aria-label="Selecionar orçamento #${q.id}"></label><a href="#/orcamento/${q.id}">Orçamento #${q.id}</a>${qBadge(q.status)}</div>
      <div class="qlabel">Cliente:</div><div class="qclient">${esc(qClient(q))}</div>
      <div class="qgrid"><div><div class="qlabel">Valor:</div>${money(q.total)}</div><div><div class="qlabel">Responsável:</div>${esc(q.user_name || '—')}</div><div><div class="qlabel">Solicitação:</div>${dOnly(q.request_date)}</div></div>
      ${qDeadline(q, data.today)}</div>`;
  const paintKanban = () => {
    const by = {};
    Object.keys(Q_STATUS).forEach((k) => (by[k] = []));
    data.rows.forEach((q) => (by[q.status] || (by[q.status] = [])).push(q));
    // Status desativado só aparece como coluna se ainda tiver orçamento nele
    $('#board', v).innerHTML = `<div class="kanban">${Object.entries(Q_STATUS).filter(([k, st]) => st.active !== false || by[k].length).map(([k, st]) => {
      const list = by[k] || [], sum = list.reduce((a, q) => a + +q.total, 0);
      return `<div class="kcol" data-st="${k}"><div class="khead" style="--c:${st.color}"><strong>${esc(st.label)}</strong><span>${money(sum)} · ${list.length}</span></div>
        <div class="kbody">${list.map(card).join('') || '<div class="kempty">Arraste um orçamento para cá</div>'}</div></div>`;
    }).join('')}</div>
    <p class="help">Arraste os cartões entre as colunas para mudar o status. "Vendido" é definido ao gerar a venda. Recusados e vendidos há mais de 60 dias ficam só na Lista.</p>`;
    let dragId = null;
    $$('.qcard', v).forEach((c) => {
      c.addEventListener('dragstart', (e) => { dragId = c.dataset.id; c.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
      c.addEventListener('dragend', () => c.classList.remove('drag'));
      c.addEventListener('click', (e) => { if (!e.target.closest('a, .qsel')) go('orcamento/' + c.dataset.id); });
    });
    $$('.kcol', v).forEach((col) => {
      col.addEventListener('dragover', (e) => { if (col.dataset.st !== 'vendido') { e.preventDefault(); col.classList.add('over'); } });
      col.addEventListener('dragleave', () => col.classList.remove('over'));
      col.addEventListener('drop', async (e) => {
        e.preventDefault(); col.classList.remove('over');
        const q = data.rows.find((r) => r.id == dragId); if (!q || q.status === col.dataset.st) return;
        try { await api('quote_status', { body: { id: q.id, status: col.dataset.st } }); toast(`Orçamento #${q.id}: ${Q_STATUS[col.dataset.st].label}`); load(); }
        catch (err) { fail(err); }
      });
    });
  };
  const paintList = () => {
    $('#board', v).innerHTML = data.rows.length ? `<div class="table-wrap"><table><thead><tr><th class="selcol"><input type="checkbox" id="selall" aria-label="Selecionar todos"></th><th>#</th><th>Cliente</th><th>Status</th><th class="num">Valor</th><th>Responsável</th><th>Solicitação</th><th>Validade</th></tr></thead><tbody>
      ${data.rows.map((q) => { const d = q.expires_at && ['rascunho', 'em_elaboracao', 'aguardando_envio', 'enviado'].includes(q.status) ? daysBetween(data.today, q.expires_at) : null;
        return `<tr class="click" data-id="${q.id}"><td class="selcol"><input type="checkbox" data-sel="${q.id}" aria-label="Selecionar orçamento #${q.id}"></td><td>${q.id}</td><td>${esc(qClient(q))}${q.external_code ? `<div class="small muted">Ref. ${esc(q.external_code)}</div>` : ''}</td><td>${qBadge(q.status)}</td><td class="num">${money(q.total)}</td><td>${esc(q.user_name || '')}</td><td class="nowrap">${dOnly(q.request_date)}</td>
        <td class="nowrap ${d !== null && d < 0 ? 'stock-low' : ''}">${q.expires_at ? dOnly(q.expires_at) : '—'}${d !== null ? `<div class="small muted">${d < 0 ? 'vencido' : d === 0 ? 'vence hoje' : 'em ' + d + ' dia(s)'}</div>` : ''}</td></tr>`; }).join('')}</tbody></table></div>`
      : `<div class="empty">Nenhum orçamento encontrado. <a href="#/orcamento/novo">Criar o primeiro</a></div>`;
    $$('#board tr[data-id]', v).forEach((tr) => (tr.onclick = (e) => { if (!e.target.closest('.selcol')) go('orcamento/' + tr.dataset.id); }));
    const all = $('#selall', v); if (all) all.onchange = () => { data.rows.forEach((r) => (all.checked ? sel.add(r.id) : sel.delete(r.id))); paintSel(); };
  };
  $('#board', v).addEventListener('change', (e) => {
    const c = e.target.closest('[data-sel]'); if (!c) return;
    c.checked ? sel.add(+c.dataset.sel) : sel.delete(+c.dataset.sel); paintSel();
  });
  // ---- Ações em massa
  const bulkMenu = $('#bulk .dd-menu', v);
  const report = (r, verb) => {
    if (r.ok) toast(`${r.ok} orçamento(s) ${verb}`);
    if (r.errors.length) modal({ title: r.errors.length === 1 ? '1 orçamento não pôde ser alterado' : `${r.errors.length} orçamentos não puderam ser alterados`, body: `<ul class="bulk-err">${r.errors.map((x) => `<li><a href="#/orcamento/${x.id}">Orçamento #${x.id}</a>: ${esc(x.error)}</li>`).join('')}</ul>`, foot: '<button class="btn primary" data-close>Entendi</button>' });
    sel.clear(); load();
  };
  const runBulk = async (body, verb) => { try { report(await api('quotes_bulk', { body: { ids: [...sel], ...body } }), verb); } catch (err) { fail(err); } };
  const pickStatus = () => new Promise((resolve) => {
    const opts = qSelectable();
    const m = modal({ title: 'Alteração de status', body: `<p class="muted" style="margin-top:0">Selecione para qual status ${sel.size === 1 ? 'o orçamento selecionado deve' : `os ${sel.size} orçamentos selecionados devem`} ser alterado${sel.size === 1 ? '' : 's'}.</p>
      <div class="radio-list">${opts.map(([k, s], i) => `<label><input type="radio" name="bst" value="${k}" ${i === 0 ? '' : ''}><span class="qbadge" style="--c:${esc(s.color)}">${esc(s.label)}</span></label>`).join('')}</div>`,
      foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="bstok" disabled>Alterar</button>` });
    let val = null;
    m.el.addEventListener('change', (e) => { if (e.target.name === 'bst') { val = e.target.value; $('#bstok', m.el).disabled = false; } });
    $('#bstok', m.el).onclick = () => { m.close(); resolve(val); };
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); resolve(val && null); } });
    obs.observe($('#modal-root'), { childList: true });
  });
  const downloadZip = () => { toast(`Gerando ${sel.size} PDF(s)…`); return downloadFile('api.php?r=quotes_pdf_zip&ids=' + [...sel].join(','), `orcamentos-${today()}.zip`); };
  bulkMenu.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bulk]'); if (!b || !sel.size) return;
    bulkMenu.hidden = true;
    const n = sel.size, act = b.dataset.bulk, qs = `${n} orçamento(s)`;
    if (act === 'pdf') return downloadZip();
    if (act === 'status') {
      const st = await pickStatus(); if (!st) return;
      if (await typeConfirm({ text: `Você está prestes a alterar o status de ${qs} para "${Q_STATUS[st].label}".` })) runBulk({ action: 'status', status: st }, 'alterado(s)');
      return;
    }
    if (act === 'delete') {
      if (await typeConfirm({ text: `Você está prestes a excluir ${qs}. Não dá para desfazer.`, word: 'excluir', ok: 'Excluir', danger: true })) runBulk({ action: 'delete' }, 'excluído(s)');
      return;
    }
    const label = { enviado: 'enviado', aprovado: 'aprovado', recusado: 'recusado' }[act];
    if (await typeConfirm({ text: `Você está prestes a marcar ${qs} como ${label}.` })) runBulk({ action: act }, 'alterado(s)');
  });
  $('#kpis', v).onclick = (e) => { const b = e.target.closest('[data-k]'); if (!b) return; f.filter = f.filter === b.dataset.k ? '' : b.dataset.k; if (f.filter) { f.view = 'lista'; } load(); };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; load(); });
  bindFilterPanel($('#afpanel', v), f, () => load());
  $('#aftoggle', v).onclick = (e) => { const p = $('#afpanel', v); p.hidden = !p.hidden; e.currentTarget.setAttribute('aria-expanded', String(!p.hidden)); if (!p.hidden) $('[data-af=from]', p).focus(); };
  $('#view', v).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; f.view = b.dataset.v; prefSet('quotesView', f.view); load(); };
  $('#csv', v).onclick = () => {
    if (!data) return;
    const n = (x) => Number(x).toFixed(2).replace('.', ',');
    const lines = [['Número', 'Cliente', 'Status', 'Valor', 'Responsável', 'Solicitação', 'Validade', 'Código externo'].join(';'),
      ...data.rows.map((q) => [q.id, `"${qClient(q).replace(/"/g, '""')}"`, Q_STATUS[q.status]?.label || q.status, n(q.total), `"${(q.user_name || '').replace(/"/g, '""')}"`, dOnly(q.request_date), q.expires_at ? dOnly(q.expires_at) : '', `"${(q.external_code || '').replace(/"/g, '""')}"`].join(';'))];
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `orcamentos-${today()}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };
  await load();
}

/** Lateral do orçamento só para leitura (fora do modo de edição). */
function quoteReadSide(q, team) {
  const lead = !q.customer_id;
  const name = lead ? q.lead_name : (q.customer_trade_name || q.customer_name);
  const contacts = (lead ? [q.lead_phone, q.lead_email] : [q.customer_phone, q.customer_email]).filter(Boolean);
  const resp = (team.find((u) => +u.id === +q.user_id) || {}).name || q.user_name || '';
  const f = (label, val) => `<div class="ro-f"><div class="ro-l">${label}</div><div class="ro-v">${val || ''}</div></div>`;
  const when = q.status_changed_at ? ` em ${dt(q.status_changed_at)}` : '';
  const approval = q.status === 'vendido' ? `Aprovado e convertido na <a href="#/venda/${q.sale_id}">venda #${q.sale_id}</a>.`
    : q.status === 'aprovado' ? `Orçamento aprovado${when}.`
    : q.status === 'recusado' ? `Orçamento recusado${when}.`
    : 'Este orçamento ainda não foi aprovado pelo cliente.';
  return `<aside class="card qside-ro">
      <div class="qside-icon">${icon('quote')}</div>
      <div class="qside-title" style="text-align:left">Orçamento #${q.id}</div>
      <div style="margin-bottom:12px">${qBadge(q.status)}</div>
      ${f(lead ? 'Lead' : 'Cliente', lead ? esc(name) : (can('clientes') ? `<a href="#/cliente/${q.customer_id}">${esc(name)}</a>` : esc(name)))}
      ${f('Contatos', contacts.map(esc).join('<br>'))}
      ${f('Data de solicitação', dOnly(q.request_date))}
      ${f('Data de expiração', q.expires_at ? dOnly(q.expires_at) : '')}
      ${f('Colaborador responsável', esc(resp))}
      ${f('Código externo', esc(q.external_code || ''))}
    </aside>
    <aside class="card qside-ro"><h2 style="margin-bottom:8px">Status de aprovação</h2><p class="muted" style="margin:0">${approval}</p>
      ${q.client_response ? `<p style="margin:8px 0 0">Comentário do cliente: “${esc(q.client_response)}”</p>` : ''}</aside>`;
}

async function viewQuoteForm(v, id) {
  const [defs, team] = await Promise.all([api('quote_defaults'), api('team')]);
  const isNew = !id;
  let q = isNew ? { status: 'rascunho', request_date: defs.request_date, expires_at: defs.expires_at, user_id: defs.user_id, notes: defs.notes, items: [], extras: [], discount_type: 'valor', discount_value: 0, payment_methods: '' }
    : await api('quote', { params: { id } });
  // "Duplicar": abre um orçamento novo (não salvo) com a cópia do original e o cliente em branco
  const copyOf = isNew ? JSON.parse(sessionStorage.getItem('quoteCopy') || 'null') : null;
  sessionStorage.removeItem('quoteCopy');
  if (copyOf) {
    ['items', 'extras', 'payment_methods', 'payment_terms', 'installments', 'installment_days', 'installment_plan', 'installment_entry', 'discount_type', 'discount_value', 'notes', 'internal_notes']
      .forEach((k) => { if (copyOf[k] !== undefined) q[k] = copyOf[k]; });
    // Item já excluído do cadastro não entra em orçamento novo
    const gone = q.items.filter((i) => i.product_deleted).length;
    if (gone) { q.items = q.items.filter((i) => !i.product_deleted); setTimeout(() => toast(`${gone} item(ns) excluído(s) do cadastro ficaram de fora da cópia`), 300); }
  }
  // Orçamento salvo abre para visualizar; "Editar" libera os campos (vendido nunca é editável)
  const sold = q.status === 'vendido';
  const editFlag = sessionStorage.getItem('qedit') === String(id); sessionStorage.removeItem('qedit');
  const editing = !sold && (isNew || editFlag);
  const locked = !editing;
  const st = { target: q.customer_id || isNew ? 'cliente' : (q.lead_name ? 'lead' : 'cliente'), customer: q.customer_id ? { id: q.customer_id, name: q.customer_name, trade_name: q.customer_trade_name, document: q.customer_document, phone: q.customer_phone, email: q.customer_email, city: q.customer_city, state: q.customer_state } : null };
  if (isNew) { const pre = JSON.parse(sessionStorage.getItem('quoteCustomer') || 'null'); sessionStorage.removeItem('quoteCustomer'); if (pre) st.customer = pre; }
  const lines = q.items.map((i) => ({ p: { id: +i.product_id, name: i.description, unit: i.unit, stock: i.stock, type: i.product_type, deleted: !!i.product_deleted }, kind: i.kind, q: +i.quantity, price: +i.unit_price, dval: +(i.discount_value ?? i.discount), dtype: i.discount_type || 'valor' }));
  const extras = q.extras.map((e) => ({ d: e.description, v: +e.amount }));
  const methods = new Set(String(q.payment_methods || '').split(',').filter(Boolean));
  let qinst = null;
  const editableStatuses = qSelectable(q.status);

  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/orcamentos">← Orçamentos</a></p><h1>${isNew ? (copyOf ? `Novo orçamento <span class="muted small">(cópia do #${copyOf.id})</span>` : 'Novo orçamento') : 'Orçamento #' + q.id}</h1></div>
    <div class="actions">${!isNew ? `<a class="btn qbtn-view" href="${esc(q.public_url)}" target="_blank" rel="noopener">${icon('eye')}Visualizar orçamento</a>` : ''}
      ${!isNew && !editing && q.status === 'aprovado' ? `<button class="btn" id="conv" style="border-color:var(--ok);color:var(--ok)">${icon('cart')}Gerar venda</button>` : ''}
      ${editing ? `<button class="btn" id="cancel">${icon('x')}Cancelar</button><button class="btn primary" id="save">Salvar</button>`
        : `${sold ? '' : `<button class="btn" id="edit">${icon('pencil')}Editar</button>`}
        <div class="dd"><button type="button" class="btn dd-btn qbtn-more" aria-haspopup="true" aria-expanded="false">${icon('menu')}Mais ações${icon('chevron')}</button>
          <div class="dd-menu" role="menu" hidden>
            <button type="button" role="menuitem" data-act="link">${icon('link')}Copiar link</button>
            <button type="button" role="menuitem" data-act="pdf">${icon('download')}Download PDF</button>
            <button type="button" role="menuitem" data-act="email">${icon('mail')}Enviar por E-mail</button>
            <button type="button" role="menuitem" data-act="whats">${icon('whats')}Enviar por WhatsApp</button>
            ${sold ? '' : `<button type="button" role="menuitem" data-act="sent">${icon('send')}Marcar como enviado</button>
            <button type="button" role="menuitem" data-act="approved">${icon('check')}Marcar como aprovado</button>
            <button type="button" role="menuitem" data-act="refused">${icon('x')}Marcar como recusado</button>`}
            <button type="button" role="menuitem" data-act="dup">${icon('copy')}Duplicar</button>
            ${sold ? '' : `<button type="button" role="menuitem" data-act="del" class="danger">${icon('trash')}Excluir</button>`}
          </div></div>`}</div></div>
    ${sold ? `<div class="alert ok">Este orçamento virou a <a href="#/venda/${q.sale_id}"><strong>venda #${q.sale_id}</strong></a> e não pode mais ser alterado. Use <strong>Duplicar</strong> para fazer um parecido.</div>` : ''}
    ${editing && q.client_response ? `<div class="alert ${q.status === 'recusado' ? 'err' : 'ok'}">Resposta do cliente: “${esc(q.client_response)}”</div>` : ''}
    ${editing && !isNew && ['aprovado', 'recusado'].includes(q.status) && q.status_changed_at ? `<p class="muted small" style="margin-top:-8px">${Q_STATUS[q.status].label} em ${dt(q.status_changed_at)}</p>` : ''}
    <div class="qsumbar">
      <div class="card qsb"><span class="qsb-ic">${icon('dollar')}</span><div><div class="qsb-l">Valor orçado</div><div class="qsb-v" id="sumval">${money(q.total || 0)}</div></div></div>
      <div class="card qsb"><span class="qsb-ic">${icon('quote')}</span><div style="flex:1;min-width:0"><div class="qsb-l">Status</div>
        ${sold ? `<div class="qsb-v">${esc(Q_STATUS.vendido.label)}</div>` : `<select id="sumst" class="qsb-sel" aria-label="Status do orçamento">${editableStatuses.map(([k, s]) => `<option value="${k}" ${k === q.status ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}</select>`}</div></div>
      <div class="card qsb"><span class="qsb-ic">${icon('calendar')}</span><div><div class="qsb-l">Data cadastro</div><div class="qsb-v">${dOnly(q.created_at || today())}</div></div></div>
    </div>
    <div class="qform ${locked ? 'locked' : ''}">
      <div class="qside">
      ${locked ? quoteReadSide(q, team) : ''}
      <aside class="card" ${locked ? 'hidden' : ''}>
        <div class="qside-icon">${icon('quote')}</div>
        <div class="qside-title">${isNew ? 'Novo orçamento' : 'Orçamento #' + q.id}</div>
        <div style="text-align:center;margin-bottom:12px" id="stbadge">${qBadge(q.status)}</div>
        <div class="form">
          <div><div class="qlabel-f">Para</div><div class="inline"><label class="check"><input type="radio" name="target" value="cliente" ${st.target === 'cliente' ? 'checked' : ''}> Cliente</label><label class="check"><input type="radio" name="target" value="lead" ${st.target === 'lead' ? 'checked' : ''}> Lead</label></div>
            <p class="help" style="margin:2px 0 0">Lead é um contato que ainda não é cliente cadastrado.</p></div>
          <div id="tcliente"><div id="custsel"></div>
            <div class="inline" style="margin-top:8px"><button type="button" class="btn sm" id="pickc">${icon('search')}Selecionar</button>${can('clientes') ? `<button type="button" class="btn sm" id="newc">${icon('plus')}Cadastrar</button>` : ''}</div></div>
          <div id="tlead" class="form">
            <label>Nome do lead * <input id="lead_name" value="${esc(q.lead_name)}" maxlength="200" placeholder="Digite"></label>
            <label>Telefone / WhatsApp <input id="lead_phone" value="${esc(q.lead_phone)}" maxlength="40" inputmode="tel"></label>
            <label>E-mail <input id="lead_email" type="email" value="${esc(q.lead_email)}" maxlength="190"></label>
            <button type="button" class="btn sm" id="leadtocli">${icon('plus')}Transformar em cliente</button></div>
          <label>Data de solicitação * <input type="date" id="request_date" value="${esc(q.request_date)}"></label>
          <label>Data de expiração <input type="date" id="expires_at" value="${esc(q.expires_at || '')}"></label>
          <label>Colaborador responsável * <select id="user_id">${team.map((u) => `<option value="${u.id}" ${u.id == q.user_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
          <label>Código externo <input id="external_code" value="${esc(q.external_code)}" maxlength="60" placeholder="opcional"></label>
          <label>Status * <select id="status" ${locked ? 'disabled' : ''}>${(locked ? Object.entries(Q_STATUS) : editableStatuses).map(([k, s]) => `<option value="${k}" ${k === q.status ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}</select></label>
        </div>
      </aside></div>
      <div class="qmain">
        <section class="card"><div class="card-head"><h2>Produtos</h2><span class="actions">${can('produtos') ? `<button type="button" class="btn sm qbtn-blue" id="newp">${icon('plus')}Cadastrar produto</button>` : ''}<button type="button" class="btn sm qbtn-green" id="addp">${icon('plus')}Incluir produto</button></span></div><div id="lp"></div></section>
        <section class="card"><div class="card-head"><h2>Serviços</h2><span class="actions">${can('servicos') ? `<button type="button" class="btn sm qbtn-blue" id="news">${icon('plus')}Cadastrar serviço</button>` : ''}<button type="button" class="btn sm qbtn-green" id="adds">${icon('plus')}Incluir serviço</button></span></div><div id="ls"></div></section>
        <section class="card"><div class="card-head"><h2>Custos adicionais</h2><button type="button" class="btn sm qbtn-green" id="adde">${icon('plus')}Incluir custo</button></div><div id="le"></div></section>
        <section class="card"><h2>Resumo</h2><div class="table-wrap"><table class="qsum"><thead><tr><th>Produtos</th><th>Serviços</th><th>Custos adicionais</th><th>Desconto</th><th class="num">Total</th></tr></thead>
          <tbody><tr><td id="sp"></td><td id="ss"></td><td id="se"></td>
          <td><div class="inline" style="flex-wrap:nowrap;gap:4px"><input id="dval" inputmode="decimal" style="width:100px" value="${+q.discount_value ? qty(q.discount_value, 2) : ''}" placeholder="0,00"><select id="dtype" style="width:auto"><option value="valor" ${q.discount_type !== 'percent' ? 'selected' : ''}>R$</option><option value="percent" ${q.discount_type === 'percent' ? 'selected' : ''}>%</option></select></div><div class="small muted" id="dcalc"></div></td>
          <td class="num qtotal" id="st"></td></tr></tbody></table></div></section>
        <section class="card"><h2>Pagamento</h2><div class="row qpay">
          <div><div class="qlabel-f">Forma de pagamento</div><div class="pay-grid" id="pm">${pmEntries([...methods]).map(([k, l]) => `<button type="button" data-m="${esc(k)}" class="${methods.has(k) ? 'on' : ''}">${esc(l)}</button>`).join('')}</div>
            <label class="check" style="margin-top:8px"><input type="checkbox" id="multi" ${methods.size > 1 ? 'checked' : ''}> Utilizar múltiplas formas</label>
</div>
          <label>Condição de pagamento <input id="payment_terms" list="terms" data-create="term" value="${esc(q.payment_terms)}" maxlength="160" placeholder="Escolha ou digite uma nova">${termsList('terms')}</label>
        </div><div id="qinst" class="form" style="margin-top:14px"></div></section>
        <section class="card"><h2>Observação</h2><p class="help" style="margin-top:-6px">Aparece no orçamento enviado ao cliente.</p><textarea id="notes" rows="4" placeholder="Escreva aqui">${esc(q.notes)}</textarea></section>
        <section class="card"><h2>Nota interna</h2><p class="help" style="margin-top:-6px">Só a equipe vê. Não aparece para o cliente.</p><textarea id="internal_notes" rows="3" placeholder="Escreva aqui">${esc(q.internal_notes)}</textarea></section>
        ${editing ? `<div class="actions" style="justify-content:flex-end"><button class="btn" id="cancel2">${icon('x')}Cancelar</button><button class="btn primary" id="save2">Salvar</button></div>` : ''}
      </div></div>`;

  // ---- Cliente / lead
  const paintTarget = () => {
    $('#tcliente', v).hidden = st.target !== 'cliente';
    $('#tlead', v).hidden = st.target !== 'lead';
    const c = st.customer;
    $('#custsel', v).innerHTML = c ? `<div class="cust-card"><div><div class="cust-name">${esc(custName(c))}</div><div class="small muted">${[docFmt(c.document), [c.city, c.state].filter(Boolean).join('/'), c.phone].filter(Boolean).map(esc).join(' · ') || '—'}</div></div></div>`
      : '<div class="muted small">Nenhum cliente selecionado</div>';
  };
  $$('input[name=target]', v).forEach((r) => (r.onchange = () => { st.target = r.value; paintTarget(); }));
  $('#pickc', v).onclick = () => customerBrowser({ selected: st.customer, onSelect: (c) => { st.customer = c; paintTarget(); } });
  if ($('#newc', v)) $('#newc', v).onclick = () => customerForm(null, async (cid) => { st.customer = await api('customer', { params: { id: cid } }); paintTarget(); });
  $('#leadtocli', v).onclick = () => {
    const name = $('#lead_name', v).value.trim();
    if (!name) { fail(new Error('Preencha o nome do lead primeiro')); return; }
    customerForm({ type: 'pf', name, phone: $('#lead_phone', v).value, email: $('#lead_email', v).value }, async (cid) => {
      st.customer = await api('customer', { params: { id: cid } }); st.target = 'cliente';
      $('input[name=target][value=cliente]', v).checked = true; paintTarget(); toast('Lead cadastrado como cliente. Salve o orçamento.');
    });
  };
  paintTarget();

  // ---- Itens
  const lineRows = (kind) => lines.map((l, i) => [l, i]).filter(([l]) => l.kind === kind);
  const lineDisc = (l) => { const g = Math.round(l.q * l.price * 100) / 100; return Math.min(g, Math.round((l.dtype === 'percent' ? g * l.dval / 100 : l.dval) * 100) / 100); };
  const lineTotal = (l) => Math.max(0, Math.round(l.q * l.price * 100) / 100 - lineDisc(l));
  const fmt2 = (n) => (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const paintLines = (kind) => {
    const box = $(kind === 'produto' ? '#lp' : '#ls', v), rows = lineRows(kind);
    const label = kind === 'produto' ? 'Produto' : 'Serviço';
    box.innerHTML = `<div class="qtable"><table class="qitems"><thead><tr><th>${label}</th><th>Quantidade</th><th>Valor unitário (R$)</th><th>Desconto</th><th class="num">Valor total (R$)</th></tr></thead><tbody>
      ${rows.length ? rows.map(([l, i]) => `<tr data-i="${i}">
        <td><div class="qprod"><button type="button" class="qrm" data-rm aria-label="Remover ${esc(l.p.name)}" title="Remover">${icon('minusc')}</button>
          ${kind === 'produto' ? `<span class="qimg">${icon('image')}</span>` : ''}
          <div class="qprod-text"><input class="qdesc" data-f="name" value="${esc(l.p.name)}" maxlength="255" size="8" aria-label="Descrição">
            ${l.p.deleted ? '<div class="qsub"><span class="badge err">excluído do cadastro</span></div>' : kind === 'produto' && l.p.type !== 'servico' ? `<div class="qsub">estoque: ${qty(l.p.stock)} ${esc(l.p.unit || '')}</div>` : ''}</div></div></td>
        <td><div class="qstep"><button type="button" data-step="-1" aria-label="Diminuir">${icon('minus')}</button><input data-f="q" inputmode="decimal" value="${fmt2(l.q)}" aria-label="Quantidade"><button type="button" data-step="1" aria-label="Aumentar">${icon('plus')}</button></div></td>
        <td><input class="qnum" data-f="price" inputmode="decimal" value="${fmt2(l.price)}" aria-label="Valor unitário"></td>
        <td><div class="qdisc"><input data-f="dval" inputmode="decimal" value="${fmt2(l.dval)}" aria-label="Desconto"><select data-f="dtype" aria-label="Tipo de desconto"><option value="valor" ${l.dtype !== 'percent' ? 'selected' : ''}>R$</option><option value="percent" ${l.dtype === 'percent' ? 'selected' : ''}>%</option></select></div></td>
        <td class="num lt">${fmt2(lineTotal(l))}</td></tr>`).join('')
        : `<tr class="qempty"><td colspan="5">Nenhum ${label} selecionado</td></tr>`}
      </tbody><tfoot><tr><td colspan="4"></td><td class="num" id="t${kind}"></td></tr></tfoot></table></div>`;
    paintTotals();
  };
  const paintExtras = () => {
    $('#le', v).innerHTML = `<div class="table-wrap"><table class="qitems qextras"><thead><tr><th>Custo adicional</th><th>Valor (R$)</th><th></th></tr></thead><tbody>
      ${extras.length ? extras.map((e, i) => `<tr data-e="${i}"><td><input data-f="d" value="${esc(e.d)}" maxlength="200" placeholder="Ex.: frete, embalagem, deslocamento"></td><td><input class="price-input" data-f="v" inputmode="decimal" value="${e.v ? fmt2(e.v) : ''}" placeholder="0,00"></td><td><button type="button" class="icon-btn" data-rme aria-label="Remover">${icon('trash')}</button></td></tr>`).join('')
        : '<tr><td colspan="3" class="empty">Nenhum custo adicional selecionado</td></tr>'}
      </tbody><tfoot><tr><td></td><td class="num" id="textras"></td><td></td></tr></tfoot></table></div>`;
    paintTotals();
  };
  const totals = () => {
    const sp = lineRows('produto').reduce((a, [l]) => a + lineTotal(l), 0), ss = lineRows('servico').reduce((a, [l]) => a + lineTotal(l), 0);
    const se = extras.reduce((a, e) => a + (e.v || 0), 0), base = sp + ss + se;
    const dv = num($('#dval', v).value), dt2 = $('#dtype', v).value;
    const disc = Math.round((dt2 === 'percent' ? base * dv / 100 : dv) * 100) / 100;
    return { sp, ss, se, base, disc, total: base - disc };
  };
  const paintTotals = () => {
    const t = totals();
    const set = (sel, val) => { const el = $(sel, v); if (el) el.textContent = val; };
    set('#tproduto', fmt2(t.sp)); set('#tservico', fmt2(t.ss)); set('#textras', money(t.se));
    set('#sp', money(t.sp)); set('#ss', money(t.ss)); set('#se', money(t.se)); set('#st', money(t.total)); set('#sumval', money(t.total));
    if (qinst) qinst.setTotal(t.total);
    set('#dcalc', $('#dtype', v).value === 'percent' && t.disc ? '− ' + money(t.disc) : '');
  };
  const onInput = (e) => {
    const tr = e.target.closest('tr[data-i]');
    if (tr && e.target.dataset.f) {
      const l = lines[+tr.dataset.i], f2 = e.target.dataset.f;
      if (f2 === 'name') l.p.name = e.target.value; else if (f2 === 'q') l.q = Math.max(0, num(e.target.value)); else if (f2 === 'price') l.price = Math.max(0, num(e.target.value)); else if (f2 === 'dval') l.dval = Math.max(0, num(e.target.value)); else if (f2 === 'dtype') l.dtype = e.target.value;
      $('.lt', tr).textContent = fmt2(lineTotal(l)); paintTotals(); return;
    }
    const te = e.target.closest('tr[data-e]');
    if (te && e.target.dataset.f) { const x = extras[+te.dataset.e]; if (e.target.dataset.f === 'd') x.d = e.target.value; else x.v = Math.max(0, num(e.target.value)); paintTotals(); }
  };
  v.addEventListener('input', onInput);
  v.addEventListener('change', (e) => { if (e.target.matches('select[data-f=dtype]')) onInput(e); });
  // Formata os números ao sair do campo (1 → 1,00)
  v.addEventListener('focusout', (e) => { const t = e.target; if (t.closest('.qitems') && ['q', 'price', 'dval'].includes(t.dataset.f)) t.value = fmt2(num(t.value)); });
  v.addEventListener('click', (e) => {
    const stp = e.target.closest('[data-step]');
    if (stp) { const tr = stp.closest('tr'); const l = lines[+tr.dataset.i]; l.q = Math.max(0, Math.round((l.q + +stp.dataset.step) * 1000) / 1000); if (l.q === 0) l.q = 1; $('input[data-f=q]', tr).value = fmt2(l.q); $('.lt', tr).textContent = fmt2(lineTotal(l)); paintTotals(); return; }
    const rm = e.target.closest('[data-rm]'); if (rm) { const i = +rm.closest('tr').dataset.i; const k = lines[i].kind; lines.splice(i, 1); paintLines('produto'); paintLines('servico'); return; }
    const rme = e.target.closest('[data-rme]'); if (rme) { extras.splice(+rme.closest('tr').dataset.e, 1); paintExtras(); }
  });
  $('#dval', v).oninput = paintTotals; $('#dtype', v).onchange = paintTotals;
  const addLine = (kind) => (p) => {
    const ex = lines.find((l) => l.p.id === p.id);
    if (ex) ex.q += 1; else lines.push({ p: { ...p }, kind, q: 1, price: +p.sale_price, dval: 0, dtype: 'valor' });
    paintLines(kind);
  };
  const cartView = (kind) => lines.filter((l) => l.kind === kind).map((l) => ({ p: l.p, q: l.q, price: l.price }));
  const liveCart = (kind) => { const arr = cartView(kind); return [arr, (p) => { addLine(kind)(p); arr.splice(0, arr.length, ...cartView(kind)); }]; };
  $('#addp', v).onclick = () => { const [cart, onAdd] = liveCart('produto'); productBrowser({ cart, onAdd, filter: (p) => p.type === 'produto', checkStock: false, title: 'Incluir produto' }); };
  $('#adds', v).onclick = () => { const [cart, onAdd] = liveCart('servico'); productBrowser({ cart, onAdd, filter: (p) => p.type === 'servico', checkStock: false, title: 'Incluir serviço',
    onNew: can('servicos') ? (cb) => serviceForm(null, cb) : null }); };
  if ($('#newp', v)) $('#newp', v).onclick = () => productForm({ type: 'produto', unit: 'un' }, async (pid) => { const p = (await products(true)).find((x) => x.id == pid); if (p && p.type === 'produto') addLine('produto')(p); });
  if ($('#news', v)) $('#news', v).onclick = () => serviceForm(null, async (pid) => { const p = (await products(true)).find((x) => x.id == pid); if (p && p.type === 'servico') addLine('servico')(p); });
  $('#adde', v).onclick = () => { extras.push({ d: '', v: 0 }); paintExtras(); const ins = $$('#le input[data-f=d]', v); ins[ins.length - 1].focus(); };
  paintLines('produto'); paintLines('servico'); paintExtras();

  // ---- Pagamento
  $('#payment_terms', v).addEventListener('change', (e) => applyTermToInstallments(e.target.value, qinst, [...methods]));
  $('#payment_terms', v).addEventListener('focusout', (e) => applyTermToInstallments(e.target.value, qinst, [...methods], true));
  qinst = installmentBox($('#qinst', v), { codes: [...methods], total: totals().total, n: q.installments || 1, days: q.installment_days || 30, plan: q.installment_plan, entry: q.installment_entry, disabled: locked });
  $('#pm', v).onclick = (e) => {
    const b = e.target.closest('button[data-m]'); if (!b) return;
    if (!$('#multi', v).checked) { const was = methods.has(b.dataset.m); methods.clear(); if (!was) methods.add(b.dataset.m); }
    else if (methods.has(b.dataset.m)) methods.delete(b.dataset.m); else methods.add(b.dataset.m);
    $$('#pm button', v).forEach((x) => x.classList.toggle('on', methods.has(x.dataset.m)));
    if (qinst) { qinst.update({ codes: [...methods], total: totals().total }); applyTermToInstallments($('#payment_terms', v).value, qinst, [...methods]); }
  };
  $('#multi', v).onchange = (e) => { if (!e.target.checked && methods.size > 1) { const first = [...methods][0]; methods.clear(); methods.add(first); $$('#pm button', v).forEach((x) => x.classList.toggle('on', methods.has(x.dataset.m))); qinst.update({ codes: [...methods] }); applyTermToInstallments($('#payment_terms', v).value, qinst, [...methods], true); } };
  $('#status', v).onchange = (e) => { $('#stbadge', v).innerHTML = qBadge(e.target.value); if ($('#sumst', v)) $('#sumst', v).value = e.target.value; };
  // Status na barra de resumo: em edição acompanha o campo; em visualização muda na hora (como no kanban)
  if ($('#sumst', v)) $('#sumst', v).onchange = async (e) => {
    const val = e.target.value;
    if (editing) { $('#status', v).value = val; $('#status', v).onchange({ target: $('#status', v) }); return; }
    try { await api('quote_status', { body: { id: q.id, status: val } }); toast(`Status: ${Q_STATUS[val].label}`); route(); }
    catch (err) { e.target.value = q.status; fail(err); }
  };

  if (locked) { $$('.qform input, .qform select, .qform textarea, .qform button', v).forEach((el) => (el.disabled = true)); }

  // ---- Salvar
  const payload = (status) => ({
    id: isNew ? undefined : q.id, status: status || $('#status', v).value, target: st.target,
    customer_id: st.customer ? st.customer.id : null,
    lead_name: $('#lead_name', v).value, lead_phone: $('#lead_phone', v).value, lead_email: $('#lead_email', v).value,
    request_date: $('#request_date', v).value, expires_at: $('#expires_at', v).value, user_id: $('#user_id', v).value,
    external_code: $('#external_code', v).value, payment_methods: [...methods], ...qinst.get(), payment_terms: $('#payment_terms', v).value,
    discount_type: $('#dtype', v).value, discount_value: $('#dval', v).value, notes: $('#notes', v).value, internal_notes: $('#internal_notes', v).value,
    items: lines.map((l) => ({ product_id: l.p.id, description: l.p.name, quantity: l.q, unit_price: l.price, discount_type: l.dtype, discount_value: l.dval })),
    extras: extras.map((e) => ({ description: e.d, amount: e.v })),
  });
  const validate = () => {
    if (st.target === 'cliente' && !st.customer) throw new Error('Selecione o cliente do orçamento (ou marque "Lead" e informe o nome)');
    if (st.target === 'lead' && !$('#lead_name', v).value.trim()) throw new Error('Informe o nome do lead');
    if (st.target === 'lead' && $('#status', v).value === 'aprovado') throw new Error('Só é possível aprovar orçamento de cliente cadastrado. Cadastre o lead como cliente e selecione-o no orçamento.');
    if (totals().disc > totals().base + 0.001) throw new Error('O desconto é maior que o valor do orçamento');
  };
  const save = async (btn) => busy(btn, async () => {
    validate();
    const r = await api('quote_save', { body: payload() });
    toast('Orçamento salvo');
    if (isNew) go('orcamento/' + r.id); else route(); // volta para a visualização
  });
  ['#save', '#save2'].forEach((s2) => { const b = $(s2, v); if (b) b.onclick = (e) => save(e.currentTarget); });
  ['#cancel', '#cancel2'].forEach((s2) => { const b = $(s2, v); if (b) b.onclick = () => { if (isNew) go('orcamentos'); else route(); }; });
  if ($('#edit', v)) $('#edit', v).onclick = () => { sessionStorage.setItem('qedit', String(q.id)); route(); };
  // ---- Mais ações
  const contact = () => {
    const name = q.customer_id ? (q.customer_trade_name || q.customer_name) : q.lead_name;
    const phone = String((q.customer_id ? q.customer_phone : q.lead_phone) || '').replace(/\D/g, '');
    const email = q.customer_id ? q.customer_email : q.lead_email;
    const msg = `Olá${name ? ', ' + name.split(' ')[0] : ''}! Segue o orçamento #${q.id} no valor de ${money(q.total)}${q.expires_at ? `, válido até ${dOnly(q.expires_at)}` : ''}.\nVocê pode ver, imprimir e aprovar por aqui: ${q.public_url}`;
    return { name, phone, email, msg };
  };
  const menu = $('.page-head .dd-menu', v);
  if (menu) menu.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    menu.hidden = true;
    const c = contact();
    try {
      switch (b.dataset.act) {
        case 'link':
          try { await navigator.clipboard.writeText(q.public_url); } catch (_) { const t = document.createElement('textarea'); t.value = q.public_url; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); }
          toast('Link do orçamento copiado'); break;
        case 'pdf': await downloadFile('api.php?r=quote_pdf&id=' + q.id, `orcamento-${q.id}.pdf`); break;
        case 'email':
          if (!c.email) toast('O cliente não tem e-mail cadastrado: escolha o destinatário no seu e-mail');
          location.href = `mailto:${encodeURIComponent(c.email || '')}?subject=${encodeURIComponent('Orçamento #' + q.id)}&body=${encodeURIComponent(c.msg)}`; break;
        case 'whats':
          window.open(c.phone ? `https://wa.me/${c.phone.length <= 11 ? '55' + c.phone : c.phone}?text=${encodeURIComponent(c.msg)}` : `https://wa.me/?text=${encodeURIComponent(c.msg)}`, '_blank', 'noopener'); break;
        case 'sent': case 'approved': case 'refused': {
          const r = await api('quote_mark', { body: { id: q.id, action: { sent: 'enviado', approved: 'aprovado', refused: 'recusado' }[b.dataset.act] } });
          toast(`Status: ${(Q_STATUS[r.status] || {}).label || r.status}`); route(); break;
        }
        case 'dup': {
          sessionStorage.setItem('quoteCopy', JSON.stringify(q));
          toast(`Cópia do orçamento #${q.id}: escolha o cliente e salve.`);
          if (location.hash === '#/orcamento/novo') route(); else go('orcamento/novo'); break;
        }
        case 'del':
          if (!(await confirmBox(`Excluir o orçamento #${q.id}? Não dá para desfazer.`, { ok: 'Excluir', danger: true }))) return;
          await api('quote_delete', { body: { id: q.id } }); toast(`Orçamento #${q.id} excluído`); go('orcamentos'); break;
      }
    } catch (err) { fail(err); }
  });
  if ($('#conv', v)) $('#conv', v).onclick = () => convertQuote(q);
}

/** Dias entre a data do orçamento e hoje (0 se o orçamento for de hoje ou do futuro). */
function quoteShiftDays(q) {
  const a = new Date(String(q.request_date || '').slice(0, 10) + 'T12:00:00'), b = new Date(today() + 'T12:00:00');
  const d = Math.round((b - a) / 86400000);
  return Number.isFinite(d) && d > 0 ? d : 0;
}
/** Move os vencimentos do plano de parcelas em `days` dias (igual a Quotes::shiftPlan no servidor). */
function shiftPlanDates(plan, days) {
  let rows; try { rows = typeof plan === 'string' ? JSON.parse(plan || '[]') : plan; } catch (_) { return plan; }
  if (!Array.isArray(rows) || !rows.length || !days) return plan;
  return rows.map((r) => {
    return r.due_date ? { ...r, due_date: shiftDate(String(r.due_date).slice(0, 10), days) } : r;
  });
}

/** Gera a venda a partir do orçamento aprovado. */
async function convertQuote(q) {
  if (!q.customer_id) {
    const ok = await confirmBox('Este orçamento é para um lead. Para gerar a venda, primeiro cadastre o cliente e selecione-o no orçamento. Abrir o cadastro agora?', { ok: 'Cadastrar cliente' });
    if (ok) customerForm({ type: 'pf', name: q.lead_name, phone: q.lead_phone, email: q.lead_email }, () => toast('Cliente cadastrado. Selecione-o no orçamento, salve e gere a venda.'));
    return;
  }
  const first = String(q.payment_methods || '').split(',').filter(Boolean)[0] || '';
  const gone = q.items.filter((i) => i.product_deleted);
  if (gone.length) { fail(new Error(`Este orçamento tem item excluído do cadastro: ${gone.map((i) => i.description).join(', ')}. Tire o item (ou troque por outro) e salve antes de gerar a venda.`)); return; }
  const short = q.items.filter((i) => i.kind === 'produto' && i.product_type !== 'servico' && +i.quantity > +i.stock);
  const m = modal({
    title: `Gerar venda do orçamento #${q.id}`,
    body: `<p>Será criada uma venda de <strong>${money(q.total)}</strong> para <strong>${esc(q.customer_trade_name || q.customer_name)}</strong>, com baixa no estoque dos produtos. Os custos adicionais entram como acréscimo e os descontos como desconto.</p>
      ${short.length ? `<div class="alert warn">Estoque insuficiente: ${short.map((i) => `${esc(i.description)} (tem ${qty(i.stock)}, precisa ${qty(i.quantity)})`).join(', ')}. ${BH().sale_negative_stock === '1' ? 'A venda pode ser gerada, mas o estoque desses itens vai ficar negativo.' : 'Dê entrada no estoque antes de gerar a venda.'}</div>` : ''}
      <div class="qlabel-f">Forma de pagamento da venda</div>
      <div class="pay-grid" id="cpm">${Object.entries(state.meta.payment_methods).map(([k, l]) => `<button type="button" data-m="${k}" class="${k === first ? 'on' : ''}">${esc(l)}</button>`).join('')}</div>
      <div id="cinst" class="form" style="margin-top:12px"></div>`,
    foot: `<button class="btn" data-close>Voltar</button><button class="btn primary" id="go">${icon('cart')}Gerar venda</button>`,
  });
  let method = state.meta.payment_methods[first] ? first : '';
  // Começa com o parcelamento combinado no orçamento; os vencimentos andam os dias entre a data do orçamento e hoje
  const shift = quoteShiftDays(q);
  const cinst = installmentBox($('#cinst', m.el), { codes: method ? [method] : [], total: +q.total, n: q.installments || 1, days: q.installment_days || 30, plan: shiftPlanDates(q.installment_plan, shift), entry: q.installment_entry });
  if (shift > 0 && $('#cinst', m.el).innerHTML) $('#cinst', m.el).insertAdjacentHTML('beforeend', `<p class="help">Vencimentos ajustados para a data de hoje: ${shift} dia${shift > 1 ? 's' : ''} depois do previsto no orçamento (${dt(String(q.request_date).slice(0, 10))}).</p>`);
  $('#cpm', m.el).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; method = b.dataset.m; $$('#cpm button', m.el).forEach((x) => x.classList.toggle('on', x === b)); cinst.update({ codes: [method] }); };
  $('#go', m.el).onclick = (e) => busy(e.currentTarget, async () => {
    if (!method) throw new Error('Escolha a forma de pagamento');
    const r = await api('quote_convert', { body: { id: q.id, payment_method: method, ...cinst.get() } });
    invalidateProducts(); m.close(); toast(`Venda #${r.sale_id} criada a partir do orçamento #${q.id}`); go('venda/' + r.sale_id);
  });
}

// ============ Formas de pagamento ============
async function viewPaymentMethods(v) {
  v.innerHTML = `<div class="page-head"><div><h1>Formas de pagamento</h1><p class="muted">Aparecem nas vendas, nos orçamentos, no financeiro e nas ordens de compra, nesta ordem.</p></div>
      <div class="actions"><button class="btn primary" id="newpm">${icon('plus')}Nova forma de pagamento</button></div></div>
    <div class="card"><div id="list"></div>
    <p class="help" style="margin-bottom:0">Forma já usada não pode ser excluída: desative e ela some das listas, mas vendas e lançamentos antigos continuam mostrando o nome.</p></div>`;
  let rows = [];
  const load = async () => {
    rows = await api('payment_methods');
    $('#list', v).innerHTML = `<div class="table-wrap"><table><thead><tr><th style="width:70px">Ordem</th><th>Nome</th><th>Parcelamento</th><th>Situação</th><th class="num hide-m">Usos</th><th></th></tr></thead><tbody>
      ${rows.map((r, i) => `<tr data-id="${r.id}" class="${+r.active ? '' : 'muted'}">
        <td class="nowrap"><button class="icon-btn" data-up="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Subir" title="Subir">${icon('chevron')}</button><button class="icon-btn down" data-down="${i}" ${i === rows.length - 1 ? 'disabled' : ''} aria-label="Descer" title="Descer">${icon('chevron')}</button></td>
        <td><strong>${esc(r.name)}</strong></td>
        <td class="small">${+r.max_installments > 1 ? `até ${r.max_installments}x · ${r.interval_mode === 'livre' ? 'intervalo escolhido na venda' : 'de 30 em 30 dias'}` : '<span class="muted">à vista</span>'}</td>
        <td>${+r.active ? '<span class="badge ok">Ativa</span>' : '<span class="badge">Desativada</span>'}</td>
        <td class="num hide-m">${r.uses || '—'}</td>
        <td class="num nowrap"><button class="btn sm ghost" data-edit="${r.id}">Editar</button><button class="btn sm ghost" data-toggle="${r.id}">${+r.active ? 'Desativar' : 'Reativar'}</button>${+r.uses ? '' : `<button class="btn sm ghost danger" data-del="${r.id}" aria-label="Excluir ${esc(r.name)}" title="Excluir">${icon('trash')}</button>`}</td></tr>`).join('')}
      </tbody></table></div>`;
  };
  const changed = () => { state.meta = null; return api('meta').then((m) => { state.meta = m; }).then(load); };
  const form = (r) => {
    const m = modal({
      title: r ? 'Editar forma de pagamento' : 'Nova forma de pagamento',
      body: `<form class="form" id="pmf"><label>Nome * <input name="name" maxlength="60" required value="${esc(r ? r.name : '')}" placeholder="Ex.: Cartão de crédito, Vale, Fiado"></label>
        <label>Máximo de parcelas <select name="max_installments">${Array.from({ length: 48 }, (_, i) => i + 1).map((k) => `<option value="${k}" ${k === (+r?.max_installments || 1) ? 'selected' : ''}>${k === 1 ? '1x (só à vista)' : `até ${k}x`}</option>`).join('')}</select>
          <span class="help">Na venda e no orçamento dá para escolher de 1x até esse número.</span></label>
        <fieldset class="pm-int" ${(+r?.max_installments || 1) > 1 ? '' : 'hidden'}><legend>Intervalo entre as parcelas</legend>
          <label class="check"><input type="radio" name="interval_mode" value="fixo" ${r?.interval_mode !== 'livre' ? 'checked' : ''}> Sempre de 30 em 30 dias</label>
          <label class="check"><input type="radio" name="interval_mode" value="livre" ${r?.interval_mode === 'livre' ? 'checked' : ''}> Escolher o intervalo na hora da venda ou do orçamento</label></fieldset>
        ${r && +r.uses ? '<p class="help">Mudar o nome também muda como ela aparece nas vendas e lançamentos antigos.</p>' : ''}</form>`,
      foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="pmok">Salvar</button>`,
    });
    const f = $('#pmf', m.el); f.name.focus();
    f.max_installments.onchange = () => { $('.pm-int', m.el).hidden = +f.max_installments.value <= 1; };
    const go2 = (e) => { e && e.preventDefault(); busy($('#pmok', m.el), async () => {
      if (!f.reportValidity()) return;
      await api('payment_method_save', { body: { id: r ? r.id : null, name: f.name.value, max_installments: f.max_installments.value, interval_mode: f.interval_mode.value || 'fixo' } });
      toast(r ? 'Forma de pagamento atualizada' : 'Forma de pagamento cadastrada'); m.close(); await changed();
    }); };
    f.onsubmit = go2; $('#pmok', m.el).onclick = go2;
  };
  $('#newpm', v).onclick = () => form(null);
  $('#list', v).addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    try {
      if (b.dataset.edit) form(rows.find((r) => r.id == b.dataset.edit));
      else if (b.dataset.toggle) { const r = rows.find((x) => x.id == b.dataset.toggle); await api('payment_method_save', { body: { id: r.id, name: r.name, active: !+r.active } }); toast(+r.active ? `"${r.name}" desativada` : `"${r.name}" reativada`); await changed(); }
      else if (b.dataset.del) { const r = rows.find((x) => x.id == b.dataset.del); if (!(await confirmBox(`Excluir "${r.name}"?`, { ok: 'Excluir', danger: true }))) return; await api('payment_method_delete', { body: { id: r.id } }); toast('Excluída'); await changed(); }
      else if (b.dataset.up || b.dataset.down) {
        const i = +(b.dataset.up ?? b.dataset.down), j = b.dataset.up ? i - 1 : i + 1;
        const ids = rows.map((r) => r.id); [ids[i], ids[j]] = [ids[j], ids[i]];
        await api('payment_method_order', { body: { ids } }); await changed();
      }
    } catch (err) { fail(err); }
  });
  await load();
}

// ============ Comportamento do orçamento (Configurações → Orçamento) ============
/** Configurações → Orçamento: comportamento e status na mesma tela. */
async function viewQuoteSettings(v) {
  v.innerHTML = `<div class="page-head"><div><h1>Configurações de orçamento</h1><p class="muted">Padrões do orçamento novo, ações do botão <strong>Mais ações</strong> e status.</p></div></div>
    <section id="qcfg-beh"></section><section id="qcfg-st" style="margin-top:28px"></section>`;
  // As duas partes usam as telas de antes; o título de cada uma vira subtítulo
  const sub = async (sel, fn) => {
    const el = $(sel, v); await fn(el);
    const h = $('.page-head h1', el); if (h) h.outerHTML = `<h2 style="margin:0">${esc(h.textContent)}</h2>`;
  };
  await sub('#qcfg-beh', viewQuoteBehavior);
  await sub('#qcfg-st', viewQuoteStatuses);
}

async function viewQuoteBehavior(v) {
  const d = await api('quote_behavior');
  const opts = (sel) => Object.entries(d.statuses).map(([k, l]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${esc(l)}</option>`).join('');
  v.innerHTML = `<div class="page-head"><div><h1>Comportamento</h1><p class="muted">Valores que um orçamento novo já traz e o que acontece com o status nas ações do botão <strong>Mais ações</strong>.</p></div></div>
    <div class="card" style="max-width:720px"><form class="form" id="bf">
      <h2 style="margin:0">Padrões do orçamento novo</h2>
      <label>Validade padrão (dias) <input name="quote_validity_days" type="number" min="0" max="365" value="${esc(d.quote_validity_days)}" style="max-width:140px"><span class="help">A data de expiração de um orçamento novo é preenchida com hoje + esses dias (0 = sem validade).</span></label>
      <label>Observação padrão <textarea name="quote_default_notes" rows="3" placeholder="Ex.: Prazo de entrega de 5 dias úteis após a aprovação.">${esc(d.quote_default_notes)}</textarea></label>
      <h2 style="margin:8px 0 0">Ações do orçamento</h2>
      <label>Ao usar "Marcar como enviado", o orçamento vai para <select name="quote_on_sent">${opts(d.quote_on_sent)}</select></label>
      <label>Ao usar "Marcar como aprovado", o orçamento vai para <select name="quote_on_approved">${opts(d.quote_on_approved)}</select></label>
      <label>Ao usar "Marcar como recusado", o orçamento vai para <select name="quote_on_refused">${opts(d.quote_on_refused)}</select></label>
      <p class="help" style="margin:0">A resposta do cliente pelo link do orçamento continua levando para "Aprovado" ou "Recusado".</p>
      <div><button class="btn primary">Salvar</button></div></form></div>`;
  $('#bf', v).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { await api('quote_behavior', { body: formData(e.target) }); toast('Comportamento salvo'); }); };
}


/** Salva configurações de comportamento e recarrega o que o navegador usa (GET meta). */
async function saveBehavior(d) {
  await api('settings', { body: d });
  state.meta = await api('meta'); syncPoStatus();
  toast('Comportamento salvo');
}
const optList = (pairs, sel) => pairs.map(([k, l]) => `<option value="${esc(k)}" ${String(k) === String(sel) ? 'selected' : ''}>${esc(l)}</option>`).join('');
const pmChoices = (sel, blank = 'Nenhuma') => optList([['', blank], ...Object.entries(state.meta.payment_methods)], sel);

// ============ Comportamento das vendas (Configurações → Vendas) ============
async function viewSaleBehavior(v) {
  const s = await api('settings');
  v.innerHTML = `<div class="page-head"><div><h1>Configurações de vendas</h1><p class="muted">Padrões da tela <strong>Nova venda</strong> e regras para vender, dar desconto e cancelar.</p></div></div>
    <div class="card" style="max-width:720px"><form class="form" id="bf">
      <h2 style="margin:0">Nova venda</h2>
      <label>Cliente padrão <select name="sale_default_customer">${optList([['0', 'Nenhum (escolher a cada venda)'], ['1', 'Consumidor final (venda de balcão)']], s.sale_default_customer)}</select><span class="help">Dá para trocar o cliente na própria venda.</span></label>
      <label>Forma de pagamento padrão <select name="sale_default_method">${pmChoices(s.sale_default_method)}</select></label>
      <label>Observação padrão <textarea name="sale_default_notes" rows="3" maxlength="2000" placeholder="Ex.: Garantia de 90 dias para peças.">${esc(s.sale_default_notes)}</textarea></label>
      <h2 style="margin:8px 0 0">Estoque</h2>
      <label>Quando não há estoque suficiente <select name="sale_negative_stock">${optList([['0', 'Bloquear a venda'], ['1', 'Avisar e deixar vender (o estoque fica negativo)']], s.sale_negative_stock)}</select>
        <span class="help">Útil quando a peça já chegou, mas a nota ainda não foi lançada. Vale também para gerar venda a partir de orçamento.</span></label>
      <h2 style="margin:8px 0 0">Financeiro</h2>
      <label>Venda à vista (sem parcelas) entra como <select name="sale_cash_open">${optList([['0', 'Recebida'], ['1', 'Em aberto (dar baixa no Financeiro depois)']], s.sale_cash_open)}</select>
        <span class="help">Vendas parceladas sempre entram em aberto, uma conta a receber por parcela.</span></label>
      <h2 style="margin:8px 0 0">Regras para quem não é administrador</h2>
      <label>Desconto máximo (%) <input name="sale_max_discount" inputmode="decimal" value="${+s.sale_max_discount ? qty(s.sale_max_discount, 2) : ''}" placeholder="0" style="max-width:140px">
        <span class="help">Em relação ao valor da venda direta. Vazio ou 0 = sem limite. Administradores não têm limite, e a venda gerada de orçamento segue o desconto do orçamento.</span></label>
      <label>Quem pode cancelar venda <select name="sale_cancel_admin">${optList([['0', 'Quem tem acesso a Vendas'], ['1', 'Só administradores']], s.sale_cancel_admin)}</select></label>
      <label>Prazo para cancelar (dias) <input name="sale_cancel_days" type="number" min="0" max="365" value="${esc(s.sale_cancel_days)}" style="max-width:140px">
        <span class="help">Depois desse prazo, só um administrador cancela. 0 = sem prazo.</span></label>
      <div><button class="btn primary">Salvar</button></div></form></div>`;
  $('#bf', v).onsubmit = (e) => { e.preventDefault(); busy($('button.primary', e.target), () => saveBehavior(formData(e.target))); };
}

// ============ Comportamento das notas fiscais (Configurações → Notas fiscais) ============
async function viewInvoiceBehavior(v) {
  const s = await api('settings');
  let branches = []; try { branches = JSON.parse(s.company_branches || '[]') || []; } catch (_) { branches = []; }
  v.innerHTML = `<div class="page-head"><div><h1>Configurações de notas fiscais</h1><p class="muted">Regras da importação de notas (XML, DANFE ou cupom) em <strong>Importar nota</strong>.</p></div></div>
    <div class="card" style="max-width:820px"><form class="form" id="bf" autocomplete="off">
      <h2 style="margin:0">CNPJs aceitos</h2>
      <label>Notas aceitas na importação <select name="invoice_cnpj_mode" id="cmode">${optList([['cadastrados', 'Só as emitidas para os CNPJs cadastrados'], ['qualquer', 'Qualquer CNPJ (não confere o destinatário)']], s.invoice_cnpj_mode)}</select>
        <span class="help">O lançamento manual ("Digitar itens", compra sem nota) nunca é conferido.</span></label>
      <div id="cnpjs">
        <div class="small" style="margin-bottom:8px">CNPJ da empresa: ${s.company_document ? `<strong>${esc(docFmt(s.company_document))}</strong>` : '<span class="stock-low">não cadastrado</span>'} <a href="#/empresa" class="small">(Dados da empresa)</a></div>
        <p class="help" style="margin-top:0">Também são aceitos os CNPJs abaixo (filiais, outra empresa do grupo…). O nome é buscado pelo CNPJ.</p>
        <div id="branches"></div>
        <button type="button" class="btn sm" id="addbr">${icon('plus')}Adicionar CNPJ</button>
      </div>
      <h2 style="margin:8px 0 0">Contas a pagar</h2>
      <label>"Lançar em Contas a pagar" vem marcado <select name="invoice_payables">${optList([['duplicatas', 'Quando a nota traz parcelas (duplicatas)'], ['sempre', 'Sempre'], ['nunca', 'Nunca']], s.invoice_payables)}</select></label>
      <label>Forma de pagamento padrão das contas da nota <select name="invoice_pay_method">${pmChoices(s.invoice_pay_method)}</select></label>
      <h2 style="margin:8px 0 0">Tributação do produto</h2>
      <label>NCM, CEST e origem vindos da nota <select name="invoice_fiscal">${optList([['vazios', 'Preencher só o que está vazio no produto'], ['sempre', 'Sempre atualizar com os dados da nota'], ['nunca', 'Não mexer na tributação do produto']], s.invoice_fiscal)}</select></label>
      <h2 style="margin:8px 0 0">Preço de venda</h2>
      <label>Ao importar <select name="invoice_price" id="pmode">${optList([['manter', 'Não mexer no preço de venda'], ['sugerir', 'Sugerir pelo custo + margem (desmarcado; marco o que quiser)'], ['atualizar', 'Atualizar pelo custo + margem (já vem marcado)']], s.invoice_price)}</select>
        <span class="help">Na conferência da nota, cada item mostra o preço novo, que pode ser ajustado antes de confirmar.</span></label>
      <label id="mkup">Margem sobre o custo (%) <input name="invoice_markup" inputmode="decimal" value="${+s.invoice_markup ? qty(s.invoice_markup, 2) : ''}" placeholder="0" style="max-width:140px">
        <span class="help">Ex.: custo R$ 100,00 com margem de 60% → preço de venda R$ 160,00.</span></label>
      <div><button class="btn primary">Salvar</button></div></form></div>`;
  const paintMode = () => { $('#cnpjs', v).hidden = $('#cmode', v).value === 'qualquer'; $('#mkup', v).hidden = $('#pmode', v).value === 'manter'; };
  $('#cmode', v).onchange = paintMode; $('#pmode', v).onchange = paintMode; paintMode();
  const paintBranches = () => {
    $('#branches', v).innerHTML = branches.length ? branches.map((b, i) => `<div class="branch-row" data-b="${i}">
        <label>CNPJ <input data-k="cnpj" inputmode="numeric" value="${esc(docFmt(b.cnpj))}" placeholder="00.000.000/0000-00"></label>
        <label>Nome / apelido <input data-k="name" maxlength="80" value="${esc(b.name)}" placeholder="Ex.: Filial Contagem"></label>
        <button type="button" class="icon-btn" data-rmb aria-label="Remover CNPJ">${icon('trash')}</button>
        <span class="help" data-h></span></div>`).join('')
      : '<p class="muted small" style="margin:0 0 8px">Nenhum outro CNPJ cadastrado.</p>';
  };
  paintBranches();
  $('#addbr', v).onclick = () => { branches.push({ cnpj: '', name: '' }); paintBranches(); const ins = $$('#branches input[data-k=cnpj]', v); ins[ins.length - 1].focus(); };
  $('#branches', v).addEventListener('click', (e) => { const b = e.target.closest('[data-rmb]'); if (b) { branches.splice(+b.closest('[data-b]').dataset.b, 1); paintBranches(); } });
  $('#branches', v).addEventListener('input', async (e) => {
    const row = e.target.closest('[data-b]'); if (!row) return;
    const b = branches[+row.dataset.b], k = e.target.dataset.k;
    if (k === 'name') { b.name = e.target.value; return; }
    b.cnpj = e.target.value.replace(/\D/g, '');
    const h = $('[data-h]', row);
    if (b.cnpj.length !== 14) { h.textContent = ''; return; }
    e.target.value = docFmt(b.cnpj);
    if (!validCnpj(b.cnpj)) { h.innerHTML = '<span class="stock-low">CNPJ inválido</span>'; return; }
    if (b.name) { h.textContent = ''; return; }
    h.textContent = 'Buscando…';
    try { const d = await api('cnpj', { params: { cnpj: b.cnpj } }); b.name = [d.trade_name || d.name, d.city].filter(Boolean).join(' · ').slice(0, 80); $('input[data-k=name]', row).value = b.name; h.textContent = ''; }
    catch (err) { h.textContent = ''; }
  });
  $('#bf', v).onsubmit = (e) => {
    e.preventDefault();
    busy($('button.primary', e.target), async () => {
      const d = formData(e.target);
      for (const b of branches) if (b.cnpj && !validCnpj(b.cnpj)) throw new Error('CNPJ inválido: ' + docFmt(b.cnpj));
      d.company_branches = branches.filter((b) => b.cnpj);
      await saveBehavior(d);
    });
  };
}

// ============ Comportamento das ordens de compra (Configurações → Ordens de compra) ============
async function viewPoBehavior(v) {
  const s = await api('settings');
  let custom = {}; try { custom = JSON.parse(s.po_statuses || '{}') || {}; } catch (_) { custom = {}; }
  v.innerHTML = `<div class="page-head"><div><h1>Configurações de ordens de compra</h1><p class="muted">Valores que uma ordem de compra nova já traz e os nomes dos status.</p></div></div>
    <div class="card" style="max-width:720px"><form class="form" id="bf">
      <h2 style="margin:0">Padrões da ordem nova</h2>
      <label>Prazo de entrega padrão (dias) <input name="po_delivery_days" type="number" min="0" max="365" value="${esc(s.po_delivery_days)}" style="max-width:140px"><span class="help">"Entrega desejada até" vem preenchida com hoje + esses dias (0 = em branco).</span></label>
      <label>Forma de pagamento padrão <select name="po_default_method">${pmChoices(s.po_default_method)}</select></label>
      <label>Condição de pagamento padrão <input name="po_default_terms" list="poterms-cfg" data-create="term" value="${esc(s.po_default_terms)}" maxlength="60" placeholder="Escolha ou digite uma nova">${termsList('poterms-cfg')}</label>
      <label>Observação padrão <textarea name="po_default_notes" rows="3" maxlength="2000" placeholder="Ex.: Entregar de segunda a sexta, das 8h às 17h.">${esc(s.po_default_notes)}</textarea><span class="help">Aparece no pedido enviado ao fornecedor.</span></label>
      <h2 style="margin:8px 0 0">Status</h2>
      <p class="help" style="margin:-6px 0 0">Troque o nome e a cor que aparecem nas listas e na ordem. Os status e as regras de cada um continuam os mesmos.</p>
      <div class="table-wrap"><table><thead><tr><th>Status</th><th>Nome</th><th>Cor</th></tr></thead><tbody>
        ${Object.entries(PO_STATUS_DEFAULT).map(([k, d]) => `<tr data-st="${k}"><td class="muted">${esc(d.label)}</td>
          <td><input data-k="label" maxlength="40" value="${esc((custom[k] || {}).label || '')}" placeholder="${esc(d.label)}"></td>
          <td><input data-k="color" type="color" value="${esc((custom[k] || {}).color || d.color)}" style="width:52px;padding:2px"></td></tr>`).join('')}
      </tbody></table></div>
      <h2 style="margin:8px 0 0">Envio ao fornecedor</h2>
      <label>Link do pedido <select name="po_supplier_confirm">${optList([['1', 'O fornecedor vê, imprime e pode confirmar o pedido'], ['0', 'O fornecedor só vê e imprime (a confirmação é marcada à mão)']], s.po_supplier_confirm)}</select></label>
      <label>Mensagem de envio (WhatsApp e e-mail) <textarea name="po_message" rows="6" maxlength="2000" placeholder="${esc(poDefaultMessage(s.po_supplier_confirm !== '0'))}">${esc(s.po_message)}</textarea>
        <span class="help">Em branco, usa a mensagem padrão (a do exemplo). Códigos que são trocados: {fornecedor}, {numero}, {entrega}, {itens}, {link} e {empresa}.</span></label>
      <div><button class="btn primary">Salvar</button></div></form></div>`;
  $('#bf', v).onsubmit = (e) => {
    e.preventDefault();
    busy($('button.primary', e.target), async () => {
      const d = formData(e.target);
      const st = {};
      $$('tr[data-st]', v).forEach((tr) => {
        const k = tr.dataset.st, def = PO_STATUS_DEFAULT[k];
        const label = $('input[data-k=label]', tr).value.trim(), color = $('input[data-k=color]', tr).value.toLowerCase();
        const row = {}; if (label && label !== def.label) row.label = label; if (color !== def.color.toLowerCase()) row.color = color;
        if (Object.keys(row).length) st[k] = row;
      });
      d.po_statuses = JSON.stringify(st);
      delete d.label; delete d.color;
      await saveBehavior(d);
    });
  };
}

// ============ Importar produtos por planilha ============
const IMPORT_COLS = [['A', 'Código SKU'], ['B', 'Código do fabricante'], ['C', 'Nome do produto'], ['D', 'Marca'], ['E', 'Preço de venda'],
  ['F', 'Última movimentação (data)'], ['G', 'Localização'], ['H', 'Estoque inicial'], ['I', 'Observações']];
const IMPORT_BATCH = 300;

function importProductsDialog(onDone) {
  let data = null, running = false;
  const m = modal({
    title: 'Importar produtos da planilha', wide: true,
    body: `<div id="imp-body"></div>`,
    foot: `<button class="btn" data-close id="imp-cancel">Cancelar</button><button class="btn primary" id="imp-go" disabled>Ler planilha</button>`,
  });
  const body = $('#imp-body', m.el), go = $('#imp-go', m.el);
  const step1 = () => {
    body.innerHTML = `<div class="form">
      <p style="margin:0">Envie um arquivo <strong>.xlsx</strong> (Excel) ou <strong>.csv</strong>. A <strong>primeira linha é o cabeçalho</strong> e as colunas devem estar nesta ordem:</p>
      <div class="imp-cols">${IMPORT_COLS.map(([c, l]) => `<div><span class="imp-col">${c}</span>${esc(l)}</div>`).join('')}</div>
      <div><a class="btn sm" href="assets/product-import-example.xlsx?v=${esc(state.version || '')}" download="planilha-exemplo-produtos.xlsx">${icon('download')}Baixar planilha de exemplo</a> <span class="help">Já vem com o cabeçalho e uma linha preenchida de exemplo: apague a linha e cole os seus produtos.</span></div>
      <ul class="help" style="margin:0;padding-left:18px">
        <li>Com estoque maior que 0, o histórico ganha uma <strong>contagem</strong> (ajuste de inventário) na data da coluna F; sem data, na data de hoje.</li>
        <li>Com estoque 0, fica só um registro de <strong>entrada</strong> (cadastro) na data da importação.</li>
        <li>SKUs que já existem no sistema são <strong>pulados</strong> e listados no resumo. Linha sem nome usa o código do fabricante como nome.</li>
      </ul>
      <label>Planilha <input type="file" id="imp-file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"></label>
      <div id="imp-hist"></div>
    </div>`;
    if (state.me?.role === 'admin') paintHistory();
    go.textContent = 'Ler planilha'; go.disabled = true;
    $('#imp-file', m.el).onchange = (e) => { go.disabled = !e.target.files.length; };
    go.onclick = async (e) => {
      await busy(e.currentTarget, async () => {
        const fd = new FormData(); fd.append('file', $('#imp-file', m.el).files[0]);
        data = await api('products_import_preview', { form: fd });
      });
      if (data) step2();
    };
  };
  // Importações anteriores (por dia), com "Desfazer"
  const paintHistory = async () => {
    const box = $('#imp-hist', m.el); if (!box) return;
    let h = [];
    try { h = await api('products_imports'); } catch (_) { return; }
    if (!h.length) { box.innerHTML = ''; return; }
    const fmtDay = (d) => d.split('-').reverse().join('/');
    box.innerHTML = `<fieldset><legend>Importações anteriores</legend>
      <div class="imp-hist">${h.map((x) => `<div><span><strong>${fmtDay(x.day)}</strong> · ${x.total} produto(s)${x.used ? ` <span class="muted small">(${x.used} já usado(s) depois: ficam)</span>` : ''}</span>
        <button type="button" class="btn sm danger" data-undo="${x.day}" ${x.total - x.used > 0 ? '' : 'disabled'}>${icon('undo')}Desfazer</button></div>`).join('')}</div>
      <p class="help" style="margin:6px 0 0">Desfazer apaga de vez os produtos importados nesse dia que ainda não foram usados (sem vendas, orçamentos, notas, ordens ou movimentações depois da importação).</p></fieldset>`;
    box.onclick = async (e) => {
      const b = e.target.closest('[data-undo]'); if (!b) return;
      const x = h.find((i) => i.day === b.dataset.undo), n = x.total - x.used;
      if (!(await typeConfirm({ text: `Apagar ${n} produto(s) importado(s) em ${fmtDay(x.day)}? Isso não pode ser desfeito.${x.used ? ` Os ${x.used} que já foram usados ficam.` : ''}`, word: 'desfazer', ok: 'Desfazer importação', danger: true }))) return;
      await busy(b, async () => {
        const r = await api('products_import_undo', { body: { day: x.day } });
        toast(`${r.deleted} produto(s) apagado(s)${r.kept.length ? `; ${r.kept.length} mantido(s) por já terem sido usados` : ''}`);
        onDone && onDone();
      });
      paintHistory();
    };
  };
  const list = (items, kind) => items.length ? `<div class="imp-list">${items.slice(0, 200).map((x) => `<div><span class="muted">linha ${x.line}</span> ${x.sku ? `<strong>${esc(x.sku)}</strong> ` : ''}${esc(x.name || '')} — <span class="${kind === 'err' ? 'stock-low' : 'muted'}">${esc(x.reason || x.error)}</span></div>`).join('')}${items.length > 200 ? `<div class="muted">… e mais ${items.length - 200}</div>` : ''}</div>` : '';
  const step2 = () => {
    const n = data.rows.length;
    body.innerHTML = `<div class="form">
      <div class="imp-kpis">
        <div><span class="muted small">Linhas lidas</span><strong>${data.total}</strong></div>
        <div><span class="muted small">Vão ser cadastrados</span><strong class="qty-pos">${n}</strong></div>
        <div><span class="muted small">Pulados (SKU já existe)</span><strong>${data.skipped.length}</strong></div>
        <div><span class="muted small">Com erro</span><strong class="${data.errors.length ? 'stock-low' : ''}">${data.errors.length}</strong></div>
      </div>
      ${data.noname ? `<div class="alert">${data.noname} linha(s) sem nome: vão entrar com o <strong>código do fabricante</strong> (ou o SKU) como nome. Dá para corrigir depois no cadastro.</div>` : ''}
      ${n ? `<div class="table-wrap"><table><thead><tr><th>SKU</th><th>Fabricante</th><th>Nome</th><th>Marca</th><th class="num">Venda</th><th>Últ. mov.</th><th>Local</th><th class="num">Estoque</th></tr></thead><tbody>
        ${data.rows.slice(0, 8).map((r) => `<tr><td>${esc(r.sku)}</td><td>${esc(r.mfr_code)}</td><td>${esc(r.name)}${r.noname ? ' <span class="badge">sem nome</span>' : ''}</td><td>${esc(r.brand)}</td><td class="num">${r.price != null ? money(r.price) : ''}</td><td>${r.last_move ? r.last_move.split('-').reverse().join('/') : ''}</td><td>${esc(r.location)}</td><td class="num">${r.stock != null ? qty(r.stock) : ''}</td></tr>`).join('')}
      </tbody></table></div>${n > 8 ? `<p class="help" style="margin:0">Mostrando 8 de ${n}.</p>` : ''}` : ''}
      ${data.skipped.length ? `<details><summary>Pulados (${data.skipped.length})</summary>${list(data.skipped)}</details>` : ''}
      ${data.errors.length ? `<details open><summary>Com erro (${data.errors.length}) — não serão importados</summary>${list(data.errors, 'err')}</details>` : ''}
    </div>`;
    go.textContent = n ? `Importar ${n} produto${n > 1 ? 's' : ''}` : 'Nada para importar';
    go.disabled = !n;
    go.onclick = () => step3();
  };
  const step3 = async () => {
    running = true; go.disabled = true; $('#imp-cancel', m.el).disabled = true;
    const rows = data.rows, total = rows.length;
    let done = 0, created = 0; const skipped = [], errors = [];
    body.innerHTML = `<div class="form"><p style="margin:0" id="imp-st">Importando…</p><div class="imp-bar"><span id="imp-fill"></span></div></div>`;
    try {
      for (let i = 0; i < total; i += IMPORT_BATCH) {
        const r = await api('products_import', { body: { rows: rows.slice(i, i + IMPORT_BATCH) } });
        created += r.created; skipped.push(...r.skipped); errors.push(...r.errors);
        done = Math.min(total, i + IMPORT_BATCH);
        $('#imp-fill', m.el).style.width = Math.round((done / total) * 100) + '%';
        $('#imp-st', m.el).textContent = `Importando… ${done} de ${total}`;
      }
    } catch (err) {
      errors.push({ line: '', sku: '', name: '', error: `Parou em ${done} de ${total}: ${err.message}. Os já cadastrados ficaram; ao ler a planilha de novo, eles aparecem como pulados.` });
    }
    running = false; onDone && onDone();
    body.innerHTML = `<div class="form">
      <div class="alert ${errors.length ? '' : 'ok'}"><strong>${created}</strong> produto(s) cadastrado(s).${skipped.length ? ` ${skipped.length} pulado(s).` : ''}${errors.length ? ` ${errors.length} com erro.` : ''}</div>
      ${skipped.length ? `<details><summary>Pulados (${skipped.length})</summary>${list(skipped)}</details>` : ''}
      ${errors.length ? `<details open><summary>Com erro (${errors.length})</summary>${list(errors, 'err')}</details>` : ''}
    </div>`;
    $('#imp-cancel', m.el).disabled = false; $('#imp-cancel', m.el).textContent = 'Fechar';
    go.hidden = true;
  };
  // Não deixa fechar no meio da importação
  m.el.addEventListener('click', (e) => { if (running && e.target.closest('[data-close]')) { e.stopImmediatePropagation(); toast('Aguarde terminar a importação'); } }, true);
  step1();
}

// ============ Condições de pagamento (Cadastros) ============
async function viewPaymentTerms(v) {
  v.innerHTML = `<div class="page-head"><div><h1>Condições de pagamento</h1><p class="muted">Aparecem como sugestão no orçamento, na venda e na ordem de compra, nesta ordem. Também dá para cadastrar uma nova direto nessas telas, digitando no campo.</p></div>
      <div class="actions"><button class="btn primary" id="newt">${icon('plus')}Nova condição</button></div></div>
    <div class="card"><div id="list"></div>
    <p class="help" style="margin-bottom:0">Quando a condição define parcelas, escolher ela no orçamento ou na venda já preenche o parcelamento (se a forma de pagamento permitir). Alterar ou excluir uma condição não muda orçamentos e vendas já feitos.</p></div>`;
  let rows = [];
  const parc = (r) => !r.installments ? '<span class="muted">—</span>'
    : (+r.entry ? 'entrada + ' : '') + (+r.installments === 1 && !+r.entry ? '1x (à vista)' : `${r.installments}x · ${+r.interval_days === 30 ? 'de 30 em 30 dias' : `a cada ${r.interval_days} dias`}`);
  const load = async () => {
    rows = await api('payment_terms');
    $('#list', v).innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th style="width:70px">Ordem</th><th>Condição</th><th>Parcelamento</th><th>Situação</th><th></th></tr></thead><tbody>
      ${rows.map((r, i) => `<tr data-id="${r.id}" class="${+r.active ? '' : 'muted'}">
        <td class="nowrap"><button class="icon-btn" data-up="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Subir" title="Subir">${icon('chevron')}</button><button class="icon-btn down" data-down="${i}" ${i === rows.length - 1 ? 'disabled' : ''} aria-label="Descer" title="Descer">${icon('chevron')}</button></td>
        <td><strong>${esc(r.name)}</strong></td><td class="small">${parc(r)}</td>
        <td>${+r.active ? '<span class="badge ok">Ativa</span>' : '<span class="badge">Desativada</span>'}</td>
        <td class="num nowrap"><button class="btn sm ghost" data-edit="${r.id}">Editar</button><button class="btn sm ghost" data-toggle="${r.id}">${+r.active ? 'Desativar' : 'Reativar'}</button><button class="btn sm ghost danger" data-del="${r.id}" aria-label="Excluir ${esc(r.name)}" title="Excluir">${icon('trash')}</button></td></tr>`).join('')}
      </tbody></table></div>` : '<div class="empty">Nenhuma condição cadastrada.</div>';
  };
  const changed = async () => { state.meta = await api('meta'); await load(); };
  const body = (r) => ({ id: r.id, name: r.name, installments: r.installments, interval_days: r.interval_days, entry: +r.entry, entry_percent: null });
  const form = (r) => {
    const m = modal({
      title: r ? 'Editar condição de pagamento' : 'Nova condição de pagamento',
      body: `<form class="form" id="tf"><label>Condição * <input name="name" maxlength="160" required value="${esc(r ? r.name : '')}" placeholder="Ex.: 30/60/90 dias, Entrada + 2x, 50% na aprovação"></label>
        <label class="check"><input type="checkbox" name="has_inst" ${r && r.installments ? 'checked' : ''}> Esta condição define o parcelamento</label>
        <div class="row t-inst" ${r && r.installments ? '' : 'hidden'}>
          <label>Parcelas <input name="installments" type="number" min="1" max="48" value="${esc(r?.installments || 2)}"><span class="help">Sem contar a entrada.</span></label>
          <label>Intervalo entre parcelas (dias) <input name="interval_days" type="number" min="1" max="365" value="${esc(r?.interval_days || 30)}"></label></div>
        <div class="t-inst" ${r && r.installments ? '' : 'hidden'}>
          <label class="check"><input type="checkbox" name="entry" ${+r?.entry ? 'checked' : ''}> Com entrada (vence no dia da venda; o valor é informado no orçamento ou na venda)</label>
          <p class="help t-ex"></p></div>
        <p class="help t-inst" ${r && r.installments ? '' : 'hidden'} style="margin-top:-6px">Se a forma de pagamento for "sempre de 30 em 30 dias", o intervalo usado é 30.</p></form>`,
      foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="tok">Salvar</button>`,
    });
    const f = $('#tf', m.el); f.name.focus();
    const ex = () => {
      const n2 = +f.installments.value || 1, d2 = +f.interval_days.value || 30, ent = f.entry.checked;
      const seq = Array.from({ length: Math.min(n2, 8) }, (_, i) => d2 * (i + 1)).join('/') + (n2 > 8 ? '/…' : '');
      $('.t-ex', m.el).textContent = `Exemplo: ${ent ? 'entrada + ' : ''}${seq} dias${ent ? ` (${n2 + 1} pagamentos)` : ''}.`;
    };
    f.has_inst.onchange = () => { $$('.t-inst', m.el).forEach((x) => (x.hidden = !f.has_inst.checked)); ex(); };
    f.addEventListener('input', ex); f.addEventListener('change', ex); ex();
    const save = (e) => { e && e.preventDefault(); busy($('#tok', m.el), async () => {
      if (!f.reportValidity()) return;
      await api('payment_term_save', { body: { id: r ? r.id : null, name: f.name.value, installments: f.has_inst.checked ? f.installments.value : null, interval_days: f.has_inst.checked ? f.interval_days.value : null,
        entry: f.has_inst.checked && f.entry.checked ? 1 : 0, entry_percent: null } });
      toast(r ? 'Condição atualizada' : 'Condição cadastrada'); m.close(); await changed();
    }); };
    f.onsubmit = save; $('#tok', m.el).onclick = save;
  };
  $('#newt', v).onclick = () => form(null);
  $('#list', v).addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    try {
      const find = (id) => rows.find((x) => x.id == id);
      if (b.dataset.edit) form(find(b.dataset.edit));
      else if (b.dataset.toggle) { const r = find(b.dataset.toggle); await api('payment_term_save', { body: { ...body(r), active: !+r.active } }); toast(+r.active ? 'Condição desativada' : 'Condição reativada'); await changed(); }
      else if (b.dataset.del) { const r = find(b.dataset.del); if (!(await confirmBox(`Excluir a condição “${r.name}”? Orçamentos e vendas que já usaram continuam com o texto.`, { ok: 'Excluir', danger: true }))) return; await api('payment_term_delete', { body: { id: r.id } }); toast('Condição excluída'); await changed(); }
      else if (b.dataset.up || b.dataset.down) {
        const i = +(b.dataset.up ?? b.dataset.down), j = b.dataset.up ? i - 1 : i + 1;
        const ids = rows.map((r) => r.id); [ids[i], ids[j]] = [ids[j], ids[i]];
        await api('payment_term_order', { body: { ids } }); await changed();
      }
    } catch (err) { fail(err); }
  });
  await load();
}

const STATUS_COLORS = ['#65a30d', '#15803d', '#0d9488', '#0284c7', '#2563eb', '#7c3aed', '#c026d3', '#db2777', '#dc2626', '#ea580c', '#d97706', '#475569'];

async function viewQuoteStatuses(v) {
  v.innerHTML = `<div class="page-head"><div><h1>Status</h1><p class="muted">Nomes, cores e ordem dos status (é a ordem das colunas do kanban). Crie status próprios para as etapas da sua empresa.</p></div>
      <div class="actions"><button class="btn primary" id="news">${icon('plus')}Novo status</button></div></div>
    <div class="card"><div id="list"></div>
    <p class="help" style="margin-bottom:0">Os status <strong>do sistema</strong> têm regras (ex.: só "Aprovado" vira venda, "Vendido" é definido ao gerar a venda): dá para renomear, trocar a cor e reordenar, mas não excluir. Os status criados por você podem ser desativados ou, se nenhum orçamento estiver neles, excluídos.</p></div>`;
  let rows = [];
  const load = async () => {
    rows = await api('quote_statuses');
    $('#list', v).innerHTML = `<div class="table-wrap"><table><thead><tr><th style="width:70px">Ordem</th><th>Status</th><th>Tipo</th><th class="num">Orçamentos</th><th>Situação</th><th></th></tr></thead><tbody>
      ${rows.map((r, i) => `<tr data-id="${r.id}" class="${+r.active ? '' : 'muted'}">
        <td class="nowrap"><button class="icon-btn" data-up="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Subir" title="Subir">${icon('chevron')}</button><button class="icon-btn down" data-down="${i}" ${i === rows.length - 1 ? 'disabled' : ''} aria-label="Descer" title="Descer">${icon('chevron')}</button></td>
        <td><span class="qbadge" style="--c:${esc(r.color)}">${esc(r.name)}</span></td>
        <td class="small">${r.system ? 'Do sistema' : 'Criado por você'}</td>
        <td class="num">${r.used}</td>
        <td>${+r.active ? '<span class="badge ok">Ativo</span>' : '<span class="badge">Desativado</span>'}</td>
        <td class="num nowrap"><button class="btn sm ghost" data-edit="${r.id}">Editar</button>${r.system ? '' : `<button class="btn sm ghost" data-toggle="${r.id}">${+r.active ? 'Desativar' : 'Reativar'}</button><button class="btn sm ghost danger" data-del="${r.id}" aria-label="Excluir ${esc(r.name)}" title="Excluir">${icon('trash')}</button>`}</td></tr>`).join('')}
      </tbody></table></div>`;
  };
  const changed = async () => { state.meta = await api('meta'); syncQStatus(); await load(); };
  const form = (r) => {
    const cur = r ? r.color : STATUS_COLORS[rows.length % STATUS_COLORS.length];
    const m = modal({
      title: r ? 'Editar status' : 'Novo status',
      body: `<form class="form" id="sf"><label>Nome * <input name="name" maxlength="60" required value="${esc(r ? r.name : '')}" placeholder="Ex.: Em negociação, Aguardando visita técnica"></label>
        <div><div class="qlabel-f">Cor</div><div class="swatches" id="sw">${STATUS_COLORS.map((c) => `<button type="button" class="swatch ${c === cur ? 'on' : ''}" data-c="${c}" style="--c:${c}" aria-label="Cor ${c}" title="${c}"></button>`).join('')}
          <label class="swatch-custom" title="Outra cor"><input type="color" name="color" value="${esc(cur)}" aria-label="Outra cor"></label></div></div>
        <div><div class="qlabel-f">Prévia</div><span class="qbadge" id="prev"></span></div>
        ${r && r.system ? `<p class="help" style="margin:0">Status do sistema: o nome muda em todas as telas e no orçamento enviado ao cliente; as regras continuam as mesmas.</p>` : ''}</form>`,
      foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="sok">Salvar</button>`,
    });
    const f = $('#sf', m.el); f.name.focus();
    const prev = () => { const p = $('#prev', m.el); p.style.setProperty('--c', f.color.value); p.textContent = f.name.value.trim() || 'Nome do status'; $$('.swatch', m.el).forEach((b) => b.classList.toggle('on', b.dataset.c === f.color.value.toLowerCase())); };
    $('#sw', m.el).onclick = (e) => { const b = e.target.closest('[data-c]'); if (b) { f.color.value = b.dataset.c; prev(); } };
    f.addEventListener('input', prev); prev();
    const save = (e) => { e && e.preventDefault(); busy($('#sok', m.el), async () => {
      if (!f.reportValidity()) return;
      await api('quote_status_save', { body: { id: r ? r.id : null, name: f.name.value, color: f.color.value } });
      toast(r ? 'Status atualizado' : 'Status criado'); m.close(); await changed();
    }); };
    f.onsubmit = save; $('#sok', m.el).onclick = save;
  };
  $('#news', v).onclick = () => form(null);
  $('#list', v).addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    try {
      const find = (id) => rows.find((x) => x.id == id);
      if (b.dataset.edit) form(find(b.dataset.edit));
      else if (b.dataset.toggle) { const r = find(b.dataset.toggle); await api('quote_status_save', { body: { id: r.id, name: r.name, color: r.color, active: !+r.active } }); toast(+r.active ? 'Status desativado' : 'Status reativado'); await changed(); }
      else if (b.dataset.del) { const r = find(b.dataset.del); if (!(await confirmBox(`Excluir o status “${r.name}”?`, { ok: 'Excluir', danger: true }))) return; await api('quote_status_delete', { body: { id: r.id } }); toast('Status excluído'); await changed(); }
      else if (b.dataset.up || b.dataset.down) {
        const i = +(b.dataset.up ?? b.dataset.down), j = b.dataset.up ? i - 1 : i + 1;
        const ids = rows.map((r) => r.id); [ids[i], ids[j]] = [ids[j], ids[i]];
        await api('quote_status_order', { body: { ids } }); await changed();
      }
    } catch (err) { fail(err); }
  });
  await load();
}

// ============ Ordens de compra ============
const PO_STATUS_DEFAULT = {
  rascunho: { label: 'Rascunho', color: '#64748b' },
  enviada: { label: 'Enviada', color: '#2563eb' },
  confirmada: { label: 'Confirmada', color: '#7c3aed' },
  recebida: { label: 'Recebida', color: '#16a34a' },
  cancelada: { label: 'Cancelada', color: '#dc2626' },
};
const PO_STATUS = {};
/** Status da ordem de compra com os nomes e cores de Configurações → Ordens de compra. */
function syncPoStatus() {
  const custom = (state.meta && state.meta.behavior && state.meta.behavior.po_statuses) || {};
  Object.entries(PO_STATUS_DEFAULT).forEach(([k, d]) => (PO_STATUS[k] = { label: (custom[k] || {}).label || d.label, color: (custom[k] || {}).color || d.color, custom: !!(custom[k] || {}).label }));
}
syncPoStatus();
const poBadge = (st) => `<span class="qbadge" style="--c:${(PO_STATUS[st] || {}).color || '#666'}">${esc((PO_STATUS[st] || {}).label || st)}</span>`;
const supName = (o) => o.supplier_trade_name || o.supplier_name || '—';

async function viewPurchases(v) {
  const f = { status: prefGet('poStatus', 'abertas'), q: '', page: 1 };
  const tab = (k, plural) => (PO_STATUS[k].custom ? PO_STATUS[k].label : plural);
  const TABS = [['abertas', 'Em andamento'], ['rascunho', tab('rascunho', 'Rascunho')], ['enviada', tab('enviada', 'Enviadas')], ['confirmada', tab('confirmada', 'Confirmadas')], ['recebida', tab('recebida', 'Recebidas')], ['cancelada', tab('cancelada', 'Canceladas')], ['', 'Todas']];
  v.innerHTML = `<div class="page-head"><div><h1>Ordens de compra</h1><p class="muted">Pedidos formais de peças e produtos aos fornecedores.</p></div>
      <div class="actions"><a class="btn primary" href="#/compra/nova">${icon('plus')}Nova ordem de compra</a></div></div>
    <div class="card"><div class="filters" style="align-items:center">
      <div class="seg" id="tabs">${TABS.map(([k, l]) => `<button data-s="${k}">${l}<span class="tabn" data-n="${k}"></span></button>`).join('')}</div>
      <label class="grow">Buscar <input id="q" type="search" placeholder="Nº, fornecedor ou item"></label>
      <div class="dd" id="bulk"><button type="button" class="btn dd-btn bulk-btn" aria-haspopup="menu" disabled>${icon('check')}Ações em massa <span class="bulk-n" hidden></span>${icon('chevron')}</button>
        <div class="dd-menu" role="menu" hidden>
          <button type="button" role="menuitem" data-bulk="status">${icon('swap')}Alterar status</button>
          <button type="button" role="menuitem" data-bulk="delete" class="danger">${icon('trash')}Excluir</button>
        </div></div></div>
      <div id="list"></div></div>`;
  const sel = new Set(); // ordens marcadas para as ações em massa
  let rows = [];
  const paintSel = () => {
    const n = sel.size, b = $('.bulk-btn', v), c = $('.bulk-btn .bulk-n', v);
    b.disabled = !n; c.hidden = !n; c.textContent = n;
    $$('[data-sel]', v).forEach((x) => { x.checked = sel.has(+x.dataset.sel); x.closest('tr')?.classList.toggle('sel', x.checked); });
    const all = $('#selall', v);
    if (all) { const ids = rows.map((r) => r.id); const k = ids.filter((id) => sel.has(id)).length; all.checked = k > 0 && k === ids.length; all.indeterminate = k > 0 && k < ids.length; }
  };
  const load = async () => {
    const d = await api('purchases', { params: { status: f.status, q: f.q, page: f.page } });
    $$('#tabs button', v).forEach((b) => b.classList.toggle('on', b.dataset.s === f.status));
    const c = d.counts, n = (k) => (c[k] || {}).n || 0;
    const counts = { abertas: n('rascunho') + n('enviada') + n('confirmada'), rascunho: n('rascunho'), enviada: n('enviada'), confirmada: n('confirmada') };
    $$('#tabs [data-n]', v).forEach((s) => { const x = counts[s.dataset.n]; s.textContent = x ? ` ${x}` : ''; });
    const box = $('#list', v);
    rows = d.rows;
    box.innerHTML = d.rows.length ? `<div class="table-wrap"><table><thead><tr><th class="selcol"><input type="checkbox" id="selall" aria-label="Selecionar todas"></th><th>#</th><th>Fornecedor</th><th>Status</th><th class="hide-m">Pedido</th><th>Entrega</th><th class="num hide-m">Itens</th><th class="num">Total</th></tr></thead><tbody>
      ${d.rows.map((o) => { const late = o.expected_date && ['enviada', 'confirmada'].includes(o.status) && o.expected_date < d.today;
        return `<tr class="click" data-id="${o.id}"><td class="selcol"><input type="checkbox" data-sel="${o.id}" aria-label="Selecionar ordem #${o.id}"></td><td>${o.id}</td><td>${esc(supName(o))}<div class="small muted">${esc(o.user_name || '')}</div></td><td>${poBadge(o.status)}</td>
        <td class="hide-m nowrap">${dOnly(o.order_date)}</td><td class="nowrap ${late ? 'stock-low' : ''}">${o.status === 'recebida' && o.received_at ? 'recebida ' + dOnly(o.received_at) : o.expected_date ? dOnly(o.expected_date) + (late ? '<div class="small">atrasada</div>' : '') : '—'}</td>
        <td class="num hide-m">${o.item_count}</td><td class="num">${money(o.total)}</td></tr>`; }).join('')}</tbody></table></div>`
      : `<div class="empty">${f.q || f.status ? 'Nenhuma ordem encontrada.' : 'Nenhuma ordem de compra ainda.'} <a href="#/compra/nova">Criar uma ordem de compra</a></div>`;
    if (d.total > d.per_page) box.appendChild(pager(d.total, d.page, d.per_page, (p) => { f.page = p; load().catch(fail); }));
    $$('tr[data-id]', box).forEach((tr) => (tr.onclick = (e) => { if (!e.target.closest('.selcol')) go('compra/' + tr.dataset.id); }));
    const all = $('#selall', v); if (all) all.onchange = () => { rows.forEach((r) => (all.checked ? sel.add(r.id) : sel.delete(r.id))); paintSel(); };
    paintSel();
  };
  $('#list', v).addEventListener('change', (e) => {
    const c = e.target.closest('[data-sel]'); if (!c) return;
    c.checked ? sel.add(+c.dataset.sel) : sel.delete(+c.dataset.sel); paintSel();
  });
  // ---- Ações em massa
  const bulkMenu = $('#bulk .dd-menu', v);
  const report = (r, verb) => {
    if (r.ok) toast(`${r.ok} ordem(ns) ${verb}`);
    if (r.errors.length) modal({ title: r.errors.length === 1 ? '1 ordem não pôde ser alterada' : `${r.errors.length} ordens não puderam ser alteradas`, body: `<ul class="bulk-err">${r.errors.map((x) => `<li><a href="#/compra/${x.id}">Ordem #${x.id}</a>: ${esc(x.error)}</li>`).join('')}</ul>`, foot: '<button class="btn primary" data-close>Entendi</button>' });
    sel.clear(); load().catch(fail);
  };
  const runBulk = async (body, verb) => { try { report(await api('purchases_bulk', { body: { ids: [...sel], ...body } }), verb); } catch (err) { fail(err); } };
  const pickStatus = () => new Promise((resolve) => {
    const m = modal({ title: 'Alteração de status', body: `<p class="muted" style="margin-top:0">Selecione para qual status ${sel.size === 1 ? 'a ordem selecionada deve' : `as ${sel.size} ordens selecionadas devem`} ser alterada${sel.size === 1 ? '' : 's'}. As que não puderem mudar para esse status (ex.: recebida não volta a enviada) ficam como estão e aparecem num aviso.</p>
      <div class="radio-list">${Object.entries(PO_STATUS).map(([k, st]) => `<label><input type="radio" name="bst" value="${k}"><span class="qbadge" style="--c:${esc(st.color)}">${esc(st.label)}</span></label>`).join('')}</div>
      <label id="bdate" hidden style="margin-top:10px">Data do recebimento <input type="date" value="${today()}"></label>`,
      foot: `<button class="btn" data-close>Cancelar</button><button class="btn primary" id="bstok" disabled>Alterar</button>` });
    let val = null;
    m.el.addEventListener('change', (e) => { if (e.target.name === 'bst') { val = e.target.value; $('#bstok', m.el).disabled = false; $('#bdate', m.el).hidden = val !== 'recebida'; } });
    $('#bstok', m.el).onclick = () => { const date = $('#bdate input', m.el).value || today(); m.close(); resolve({ status: val, date }); };
    const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); resolve(null); } });
    obs.observe($('#modal-root'), { childList: true });
  });
  bulkMenu.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-bulk]'); if (!b || !sel.size) return;
    bulkMenu.hidden = true;
    const n = sel.size, act = b.dataset.bulk, qs = n === 1 ? '1 ordem de compra' : `${n} ordens de compra`;
    if (act === 'status') {
      const r = await pickStatus(); if (!r || !r.status) return;
      if (await typeConfirm({ text: `Você está prestes a alterar o status de ${qs} para "${PO_STATUS[r.status].label}".` })) runBulk({ action: 'status', status: r.status, date: r.date }, 'alterada(s)');
      return;
    }
    if (act === 'delete') {
      if (await typeConfirm({ text: `Você está prestes a excluir ${qs}. Só são excluídas as ordens em rascunho ou canceladas. Não dá para desfazer.`, word: 'excluir', ok: 'Excluir', danger: true })) runBulk({ action: 'delete' }, 'excluída(s)');
    }
  });
  $('#tabs', v).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; f.status = b.dataset.s; f.page = 1; prefSet('poStatus', f.status); load().catch(fail); };
  $('#q', v).oninput = debounce((e) => { f.q = e.target.value; f.page = 1; load().catch(fail); });
  await load();
}

async function viewPurchaseForm(v, id) {
  const defs = await api('purchase_defaults');
  const isNew = !id;
  const o = isNew ? { status: 'rascunho', order_date: defs.order_date, expected_date: defs.expected_date, payment_method: defs.payment_method, payment_terms: defs.payment_terms, notes: defs.notes, items: [], freight: 0, discount: 0 } : await api('purchase', { params: { id } });
  // Ordem salva abre só para visualizar; "Editar" libera os campos, e "Salvar"/"Cancelar" voltam à visualização
  const canEdit = ['rascunho', 'enviada'].includes(o.status);
  const editFlag = sessionStorage.getItem('poedit') === String(id); sessionStorage.removeItem('poedit');
  const editable = canEdit && (isNew || editFlag);
  const cancelBtn = isNew ? `<a class="btn" href="#/compras">${icon('x')}Cancelar</a>` : `<button type="button" class="btn" data-cancel>${icon('x')}Cancelar</button>`;
  let supplier = o.supplier_id ? { id: o.supplier_id, name: o.supplier_name, trade_name: o.supplier_trade_name, document: o.supplier_document, phone: o.supplier_phone, email: o.supplier_email } : null;
  const lines = o.items.map((i) => ({ p: { id: +i.product_id, name: i.description, unit: i.unit, stock: i.stock, min_stock: i.min_stock, avg_cost: i.avg_cost }, code: i.supplier_code, q: +i.quantity, c: +i.unit_cost }));
  const fmt2 = (n) => (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtC = (n) => { n = Number(n) || 0; return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: Math.abs(n * 100 - Math.round(n * 100)) > 0.0001 ? 4 : 2 }); };

  v.innerHTML = `<div class="page-head"><div><p class="small"><a href="#/compras">← Ordens de compra</a></p><h1>${isNew ? 'Nova ordem de compra' : 'Ordem de compra #' + o.id}</h1></div>
    <div class="actions">${!isNew && !editable ? `<a class="btn" href="${esc(o.public_url)}" target="_blank" rel="noopener">${icon('eye')}Visualizar / imprimir</a>` : ''}
      ${!isNew && !editable && canEdit ? `<button class="btn" id="edit">${icon('pencil')}Editar</button>` : ''}
      ${!isNew && !editable ? `<div class="dd"><button type="button" class="btn dd-btn qbtn-more" aria-haspopup="true" aria-expanded="false">${icon('menu')}Mais ações${icon('chevron')}</button>
        <div class="dd-menu" role="menu" id="stbtns" hidden>
          <button type="button" role="menuitem" id="send">${icon('send')}Enviar ao fornecedor</button>
          <button type="button" role="menuitem" id="dupl">${icon('copy')}Duplicar</button>
          ${[
            o.status === 'rascunho' ? ['enviada', 'Marcar como enviada', 'send'] : null,
            ['rascunho', 'enviada'].includes(o.status) ? ['confirmada', 'Fornecedor confirmou', 'check'] : null,
            ['enviada', 'confirmada'].includes(o.status) ? ['recebida', 'Mercadoria recebida', 'box'] : null,
            ['enviada', 'confirmada', 'recebida', 'cancelada'].includes(o.status) ? ['rascunho', 'Voltar para rascunho', 'undo'] : null,
            o.status !== 'cancelada' && o.status !== 'recebida' ? ['cancelada', 'Cancelar ordem', 'x'] : null,
          ].filter(Boolean).map(([k, l, ic]) => `<button type="button" role="menuitem" data-st="${k}" class="${k === 'cancelada' ? 'danger' : ''}">${icon(ic)}${l}</button>`).join('')}
          ${['rascunho', 'cancelada'].includes(o.status) ? `<button type="button" role="menuitem" class="danger" id="del">${icon('trash')}Excluir</button>` : ''}
        </div></div>` : ''}
      ${editable ? `${cancelBtn}${isNew || o.status === 'rascunho' ? `<button class="btn" id="savesend">${icon('send')}Salvar e enviar</button>` : ''}<button class="btn primary" id="save">Salvar</button>` : ''}</div></div>
    ${!isNew && o.status === 'confirmada' ? `<div class="alert ok">O fornecedor confirmou o pedido${o.confirmed_at ? ' em ' + dt(o.confirmed_at) : ''}.${o.supplier_comment ? ` Comentário: “${esc(o.supplier_comment)}”` : ''}</div>` : ''}
    ${!isNew && o.status === 'recebida' ? `<div class="alert ok">Mercadoria recebida em ${dOnly(o.received_at)}.${can('notas') ? ` Para dar entrada no estoque, <a href="#/notas/importar">importe a nota fiscal da compra</a>.` : ''}</div>` : ''}
    ${!isNew && o.status === 'cancelada' ? '<div class="alert err">Esta ordem de compra foi cancelada.</div>' : ''}
    ${!isNew && !canEdit ? `<p class="muted small" style="margin-top:-6px">Para alterar os itens, volte a ordem para rascunho ou use Duplicar.</p>` : ''}
    <div class="qform ${editable ? '' : 'locked'}">
      <aside class="card qside">
        <div class="qside-icon">${icon('order')}</div>
        <div class="qside-title">${isNew ? 'Nova ordem de compra' : 'Ordem de compra #' + o.id}</div>
        <div style="text-align:center;margin-bottom:12px">${poBadge(o.status)}</div>
        <div class="form">
          <div><div class="qlabel-f">Fornecedor *</div><div id="supsel"></div>
            <div class="inline" style="margin-top:8px"><button type="button" class="btn sm" id="picks">${icon('search')}Selecionar</button>${can('fornecedores') ? `<button type="button" class="btn sm" id="news">${icon('plus')}Cadastrar</button>` : ''}</div></div>
          <label>Data do pedido * <input type="date" id="order_date" value="${esc(o.order_date)}"></label>
          <label>Entrega desejada até <input type="date" id="expected_date" value="${esc(o.expected_date || '')}"></label>
          <label>Forma de pagamento <select id="payment_method">${pmOptions(o.payment_method)}</select></label>
          <label>Condição de pagamento <input id="payment_terms" list="poterms" data-create="term" value="${esc(o.payment_terms)}" maxlength="60" placeholder="Escolha ou digite uma nova">${termsList('poterms')}</label>
          ${!isNew ? `<div class="small muted">Criada por ${esc(o.user_name || '—')} em ${dt(o.created_at)}${o.sent_at ? `<br>Enviada em ${dt(o.sent_at)}` : ''}</div>` : ''}
        </div>
      </aside>
      <div class="qmain">
        <section class="card"><div class="card-head"><h2>Itens</h2><span class="actions">
          ${editable ? `<button type="button" class="btn sm" id="low">${icon('box')}Abaixo do mínimo</button>${can('produtos') ? `<button type="button" class="btn sm qbtn-blue" id="newp">${icon('plus')}Cadastrar produto</button>` : ''}<button type="button" class="btn sm qbtn-green" id="addp">${icon('plus')}Incluir item</button>` : ''}</span></div>
          <div id="lp"></div><p class="help" style="margin-bottom:0">O custo vem do custo médio do produto; ajuste para o preço combinado com o fornecedor. O pedido não mexe no estoque: a entrada acontece ao importar a nota da compra.</p></section>
        <section class="card"><h2>Resumo</h2><div class="table-wrap"><table class="qsum posum"><thead><tr><th>Itens</th><th>Frete</th><th>Desconto</th><th class="num">Total</th></tr></thead>
          <tbody><tr><td id="sitems"></td><td><input id="freight" inputmode="decimal" style="width:120px" value="${+o.freight ? fmt2(o.freight) : ''}" placeholder="0,00"></td>
          <td><input id="discount" inputmode="decimal" style="width:120px" value="${+o.discount ? fmt2(o.discount) : ''}" placeholder="0,00"></td><td class="num qtotal" id="stotal"></td></tr></tbody></table></div></section>
        <section class="card"><h2>Observações</h2><p class="help" style="margin-top:-6px">Aparece no pedido enviado ao fornecedor (endereço de entrega, horário de recebimento, transportadora…).</p>
          <textarea id="notes" rows="4" placeholder="Escreva aqui">${esc(o.notes)}</textarea>
          ${editable && isAdmin() ? '<label class="check" style="margin-top:8px"><input type="checkbox" id="defnotes"> Usar este texto como padrão nas próximas ordens</label>' : ''}</section>
        ${editable ? `<div class="actions" style="justify-content:flex-end">${cancelBtn}${isNew || o.status === 'rascunho' ? `<button class="btn" id="savesend2">${icon('send')}Salvar e enviar</button>` : ''}<button class="btn primary" id="save2">Salvar</button></div>` : ''}
      </div></div>`;

  const paintSupplier = () => {
    const c = supplier;
    $('#supsel', v).innerHTML = c ? `<div class="cust-card"><div><div class="cust-name">${esc(custName(c))}</div><div class="small muted">${[docFmt(c.document), c.phone, c.email].filter(Boolean).map(esc).join(' · ') || '—'}</div></div></div>`
      : '<div class="muted small">Nenhum fornecedor selecionado</div>';
  };
  $('#picks', v).onclick = () => customerBrowser({ entity: 'supplier', selected: supplier, onSelect: (c) => { supplier = c; paintSupplier(); } });
  if ($('#news', v)) $('#news', v).onclick = () => customerForm(null, async (sid) => { supplier = await api('supplier', { params: { id: sid } }); paintSupplier(); }, { entity: 'supplier' });
  paintSupplier();

  const totals = () => {
    const items = lines.reduce((a, l) => a + Math.round(l.q * l.c * 100) / 100, 0);
    const fr = Math.max(0, num($('#freight', v).value)), di = Math.max(0, num($('#discount', v).value));
    return { items, fr, di, total: items + fr - di };
  };
  const paintTotals = () => {
    const t = totals();
    $('#sitems', v).textContent = money(t.items); $('#stotal', v).textContent = money(t.total);
    const ft = $('#titems', v); if (ft) ft.textContent = fmt2(t.items);
  };
  const paintLines = () => {
    $('#lp', v).innerHTML = `<div class="qtable"><table class="qitems"><thead><tr><th>Produto</th><th>Quantidade</th><th>Custo unitário (R$)</th><th class="num">Total (R$)</th></tr></thead><tbody>
      ${lines.length ? lines.map((l, i) => `<tr data-i="${i}">
        <td><div class="qprod">${editable ? `<button type="button" class="qrm" data-rm aria-label="Remover ${esc(l.p.name)}" title="Remover">${icon('minusc')}</button>` : ''}
          <div class="qprod-text"><input class="qdesc" data-f="name" value="${esc(l.p.name)}" maxlength="255" size="8" aria-label="Descrição">
          <div class="qsub">estoque: ${qty(l.p.stock)} ${esc(l.p.unit || '')}${+l.p.min_stock ? ` · mínimo ${qty(l.p.min_stock)}` : ''}${l.code ? ` · cód. fornecedor ${esc(l.code)}` : ''}</div></div></div></td>
        <td><div class="qstep"><button type="button" data-step="-1" aria-label="Diminuir">${icon('minus')}</button><input data-f="q" inputmode="decimal" value="${fmt2(l.q)}" aria-label="Quantidade"><button type="button" data-step="1" aria-label="Aumentar">${icon('plus')}</button></div></td>
        <td><input class="qnum" data-f="c" inputmode="decimal" value="${fmtC(l.c)}" aria-label="Custo unitário"></td>
        <td class="num lt">${fmt2(l.q * l.c)}</td></tr>`).join('')
        : '<tr class="qempty"><td colspan="4">Nenhum item no pedido</td></tr>'}
      </tbody><tfoot><tr><td colspan="3"></td><td class="num" id="titems"></td></tr></tfoot></table></div>`;
    paintTotals();
  };
  v.addEventListener('input', (e) => {
    if (e.target.id === 'freight' || e.target.id === 'discount') { paintTotals(); return; }
    const tr = e.target.closest('#lp tr[data-i]'); if (!tr || !e.target.dataset.f) return;
    const l = lines[+tr.dataset.i], k = e.target.dataset.f;
    if (k === 'name') l.p.name = e.target.value; else if (k === 'q') l.q = Math.max(0, num(e.target.value)); else if (k === 'c') l.c = Math.max(0, num(e.target.value));
    $('.lt', tr).textContent = fmt2(l.q * l.c); paintTotals();
  });
  v.addEventListener('focusout', (e) => { const t = e.target; if (t.closest('#lp')) { if (t.dataset.f === 'q') t.value = fmt2(num(t.value)); if (t.dataset.f === 'c') t.value = fmtC(num(t.value)); } if (t.id === 'freight' || t.id === 'discount') t.value = num(t.value) ? fmt2(num(t.value)) : ''; });
  v.addEventListener('click', (e) => {
    const stp = e.target.closest('#lp [data-step]');
    if (stp) { const tr = stp.closest('tr'); const l = lines[+tr.dataset.i]; l.q = Math.max(1, Math.round((l.q + +stp.dataset.step) * 1000) / 1000); $('input[data-f=q]', tr).value = fmt2(l.q); $('.lt', tr).textContent = fmt2(l.q * l.c); paintTotals(); return; }
    const rm = e.target.closest('#lp [data-rm]'); if (rm) { lines.splice(+rm.closest('tr').dataset.i, 1); paintLines(); }
  });
  const addLine = (p, q = 1) => {
    const ex = lines.find((l) => l.p.id === p.id);
    if (ex) ex.q += q; else lines.push({ p: { ...p }, q, c: Math.round(+p.avg_cost * 10000) / 10000 });
    paintLines();
  };
  const isBuyable = (p) => p.type !== 'servico';
  if ($('#addp', v)) $('#addp', v).onclick = () => {
    const cart = lines.map((l) => ({ p: l.p, q: l.q }));
    productBrowser({ cart, onAdd: (p) => { addLine(p); cart.splice(0, cart.length, ...lines.map((l) => ({ p: l.p, q: l.q }))); }, filter: isBuyable, checkStock: false, showCost: true, title: 'Incluir item no pedido', allowNew: can('produtos') });
  };
  if ($('#newp', v)) $('#newp', v).onclick = () => productForm({ type: 'produto', unit: 'un' }, async (pid) => { const p = (await products(true)).find((x) => x.id == pid); if (p && isBuyable(p)) addLine(p); });
  const lowStock = async () => {
    const rows = await api('purchase_low_stock');
    const m = modal({
      title: 'Itens abaixo do estoque mínimo', wide: true,
      body: rows.length ? `<p class="muted" style="margin-top:0">A quantidade sugerida completa o mínimo, já descontando o que está em outras ordens abertas.</p>
        <div class="table-wrap"><table><thead><tr><th class="pb-chk"><input type="checkbox" id="lall" checked aria-label="Selecionar todos"></th><th>Item</th><th class="num">Estoque</th><th class="num">Mínimo</th><th class="num hide-m">Já pedido</th><th class="num">Pedir</th></tr></thead><tbody>
        ${rows.map((r, i) => `<tr><td class="pb-chk"><input type="checkbox" data-i="${i}" ${+r.missing > 0 && !lines.some((l) => l.p.id == r.id) ? 'checked' : ''}></td><td>${esc(r.name)}<div class="small muted">${r.sku ? esc(r.sku) : ''}${lines.some((l) => l.p.id == r.id) ? ' · <span class="badge accent">já está neste pedido</span>' : ''}</div></td>
          <td class="num stock-low">${qty(r.stock)} ${esc(r.unit)}</td><td class="num">${qty(r.min_stock)}</td><td class="num hide-m">${+r.on_order ? qty(r.on_order) : '—'}</td>
          <td class="num"><input data-q="${i}" inputmode="decimal" style="width:90px;text-align:right" value="${qty(Math.max(+r.missing, 0) || +r.min_stock - +r.stock)}"></td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted">Nenhum produto abaixo do estoque mínimo. Defina o estoque mínimo no cadastro do produto para usar esta lista.</p>',
      foot: rows.length ? `<button class="btn" data-close>Voltar</button><button class="btn primary" id="ladd">Incluir no pedido</button>` : `<button class="btn primary" data-close>Ok</button>`,
    });
    if (!rows.length) return;
    $('#lall', m.el).onchange = (e) => $$('input[data-i]', m.el).forEach((c) => (c.checked = e.target.checked));
    $('#ladd', m.el).onclick = async () => {
      const all = await products(true);
      let n = 0;
      $$('input[data-i]:checked', m.el).forEach((c) => {
        const r = rows[+c.dataset.i], q2 = num($(`input[data-q="${c.dataset.i}"]`, m.el).value), p = all.find((x) => x.id == r.id);
        if (p && q2 > 0) { addLine(p, q2); n++; }
      });
      m.close(); if (n) toast(n === 1 ? '1 item incluído' : `${n} itens incluídos`);
    };
  };
  if ($('#low', v)) $('#low', v).onclick = () => lowStock().catch(fail);
  paintLines();
  if (isNew && sessionStorage.getItem('poLow')) { sessionStorage.removeItem('poLow'); lowStock().catch(fail); }
  if (!editable) {
    $$('.qform input, .qform select, .qform textarea, .qform .qstep button', v).forEach((el) => (el.disabled = true));
    $('#picks', v).parentElement.remove(); // Selecionar/Cadastrar fornecedor só na edição
    // Datas em branco: mostra um traço em vez de dd/mm/aaaa
    $$('.qform input[type=date]', v).forEach((i) => { if (!i.value) { i.type = 'text'; i.value = '—'; } });
  }
  if ($('#edit', v)) $('#edit', v).onclick = () => { sessionStorage.setItem('poedit', String(o.id)); route(); };
  $$('[data-cancel]', v).forEach((b) => (b.onclick = () => route()));

  const payload = (status) => ({
    id: isNew ? undefined : o.id, status: status || o.status, supplier_id: supplier ? supplier.id : null,
    order_date: $('#order_date', v).value, expected_date: $('#expected_date', v).value,
    payment_method: $('#payment_method', v).value, payment_terms: $('#payment_terms', v).value,
    freight: $('#freight', v).value, discount: $('#discount', v).value, notes: $('#notes', v).value,
    items: lines.map((l) => ({ product_id: l.p.id, description: l.p.name, quantity: l.q, unit_cost: l.c })),
  });
  const validate = () => {
    if (!supplier) throw new Error('Selecione o fornecedor');
    if (!lines.length) throw new Error('Inclua pelo menos um item no pedido');
    if (lines.some((l) => !(l.q > 0))) throw new Error('Há item com quantidade zerada');
  };
  const persist = async (status) => {
    validate();
    const r = await api('purchase_save', { body: payload(status) });
    if ($('#defnotes', v) && $('#defnotes', v).checked) await api('purchase_notes_default', { body: { notes: $('#notes', v).value } });
    return r.id;
  };
  const save = (btn) => busy(btn, async () => { const nid = await persist(); toast('Ordem de compra salva'); if (isNew) go('compra/' + nid); else route(); });
  const saveSend = (btn) => busy(btn, async () => {
    const nid = await persist('enviada');
    const fresh = await api('purchase', { params: { id: nid } });
    if (isNew) history.replaceState(null, '', '#/compra/' + nid);
    sendPurchase(fresh, () => route());
  });
  ['#save', '#save2'].forEach((s2) => { const b = $(s2, v); if (b) b.onclick = (e) => save(e.currentTarget); });
  ['#savesend', '#savesend2'].forEach((s2) => { const b = $(s2, v); if (b) b.onclick = (e) => saveSend(e.currentTarget); });
  if ($('#send', v)) $('#send', v).onclick = (e) => busy(e.currentTarget, async () => {
    if (o.status === 'rascunho') await api('purchase_status', { body: { id: o.id, status: 'enviada' } });
    sendPurchase(await api('purchase', { params: { id: o.id } }), () => route());
  });
  if ($('#dupl', v)) $('#dupl', v).onclick = (e) => busy(e.currentTarget, async () => { const r = await api('purchase_duplicate', { body: { id: o.id } }); toast(`Cópia criada: ordem #${r.id}`); go('compra/' + r.id); });
  if ($('#stbtns', v)) $('#stbtns', v).onclick = async (e) => {
    const b = e.target.closest('[data-st]'); if (!b) return;
    const st = b.dataset.st;
    let date = null;
    if (st === 'cancelada' && !(await confirmBox(`Cancelar a ordem de compra #${o.id}?`, { ok: 'Cancelar ordem', danger: true }))) return;
    if (st === 'recebida') {
      date = await new Promise((resolve) => {
        const m = modal({ title: 'Mercadoria recebida', body: `<label>Data do recebimento <input type="date" id="rdate" value="${today()}"></label>
          <p class="help">Isto só registra o recebimento no pedido. ${can('notas') ? 'Para dar entrada no estoque, importe a nota fiscal da compra em <strong>Importar nota</strong>.' : ''}</p>`,
          foot: `<button class="btn" data-close>Voltar</button><button class="btn primary" id="rok">Confirmar</button>` });
        let done = false;
        $('#rok', m.el).onclick = () => { done = true; const d2 = $('#rdate', m.el).value; m.close(); resolve(d2 || today()); };
        const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); if (!done) resolve(null); } });
        obs.observe($('#modal-root'), { childList: true });
      });
      if (!date) return;
    }
    busy(b, async () => { await api('purchase_status', { body: { id: o.id, status: st, date } }); toast(`Ordem #${o.id}: ${PO_STATUS[st].label}`); route(); });
  };
  if ($('#del', v)) $('#del', v).onclick = async (e) => {
    if (!(await confirmBox(`Excluir a ordem de compra #${o.id}? Não dá para desfazer.`, { ok: 'Excluir', danger: true }))) return;
    busy(e.target.closest('button'), async () => { await api('purchase_delete', { body: { id: o.id } }); toast('Ordem excluída'); go('compras'); });
  };
}

const poDefaultMessage = (confirmOn = true) => `Olá, {fornecedor}! Segue o nosso pedido de compra #{numero}{entrega}:\n{itens}\n\nPedido completo (para imprimir${confirmOn ? ' e confirmar' : ''}): {link}`;
/** Troca {fornecedor}, {numero}, {entrega}, {itens}, {link} e {empresa} na mensagem de envio da ordem. */
const poMessage = (tpl, vars) => String(tpl).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)).replace(/Olá, !/g, 'Olá!');

/** Janela de envio do pedido ao fornecedor: link, WhatsApp e e-mail. */
function sendPurchase(o, onClose) {
  const name = supName(o);
  const phone = String(o.supplier_phone || '').replace(/\D/g, '');
  const list = o.items.map((i) => `• ${qty(i.quantity)} ${i.unit || ''} ${i.description}${i.supplier_code ? ` (cód. ${i.supplier_code})` : ''}`).join('\n');
  const confirmOn = BH().po_supplier_confirm !== '0';
  const msg = poMessage(BH().po_message || poDefaultMessage(confirmOn), {
    fornecedor: name && name !== '—' ? name : '', numero: o.id, entrega: o.expected_date ? `, com entrega até ${dOnly(o.expected_date)}` : '',
    itens: list, link: o.public_url, empresa: (state.meta.company || {}).name || '' });
  const wa = phone ? `https://wa.me/${phone.length <= 11 ? '55' + phone : phone}?text=${encodeURIComponent(msg)}` : `https://wa.me/?text=${encodeURIComponent(msg)}`;
  const mail = `mailto:${encodeURIComponent(o.supplier_email || '')}?subject=${encodeURIComponent('Pedido de compra #' + o.id)}&body=${encodeURIComponent(msg)}`;
  const m = modal({
    title: `Enviar pedido #${o.id}`,
    body: `<p>A ordem está marcada como <strong>Enviada</strong>. Mande o link para o fornecedor: ele vê o pedido e imprime${confirmOn ? ', e pode confirmar por ele' : ''}.</p>
      <label>Link do pedido <span class="inline" style="flex-wrap:nowrap"><input id="plink" readonly value="${esc(o.public_url)}" style="flex:1"><button type="button" class="btn" id="cpy">${icon('copy')}Copiar</button></span></label>
      <div class="send-grid">
        <a class="btn lg" style="background:#25d366;border-color:#25d366;color:#fff" target="_blank" rel="noopener" href="${esc(wa)}">WhatsApp${phone ? '' : ' (escolher contato)'}</a>
        <a class="btn lg" href="${esc(mail)}">E-mail${o.supplier_email ? '' : ' (sem e-mail cadastrado)'}</a>
        <a class="btn lg" target="_blank" rel="noopener" href="${esc(o.public_url)}">Abrir / imprimir PDF</a>
      </div>
      <details style="margin-top:12px"><summary class="small muted" style="cursor:pointer">Ver mensagem</summary><pre class="code" style="white-space:pre-wrap">${esc(msg)}</pre></details>`,
    foot: `<button class="btn primary" data-close>Concluir</button>`,
  });
  $('#cpy', m.el).onclick = async () => { try { await navigator.clipboard.writeText(o.public_url); toast('Link copiado'); } catch (_) { $('#plink', m.el).select(); document.execCommand('copy'); toast('Link copiado'); } };
  const obs = new MutationObserver(() => { if (!document.body.contains(m.el)) { obs.disconnect(); onClose && onClose(); } });
  obs.observe($('#modal-root'), { childList: true });
}

// ============ Financeiro ============
const FIN_STATUS = { aberto: ['Em aberto', 'warn'], vencido: ['Vencido', 'err'], pago: ['Pago', 'ok'], cancelado: ['Cancelado', ''] };
const finBadge = (e) => { const [l, c] = FIN_STATUS[e.display_status] || [e.display_status, '']; return `<span class="badge ${c}">${e.display_status === 'pago' ? (e.type === 'receber' ? 'Recebido' : 'Pago') : l}</span>`; };
const firstOfMonth = () => today().slice(0, 8) + '01';
const lastOfMonth = (iso = today()) => { const [y, m] = iso.split('-').map(Number); return new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10); };

async function viewFinance(v) {
  const meta = await api('finance_meta');
  const saved = JSON.parse(sessionStorage.getItem('finFilter') || 'null'); sessionStorage.removeItem('finFilter');
  const f = { type: '', status: '', from: firstOfMonth(), to: lastOfMonth(), q: '', category: '', page: 1, ...(saved || {}) };
  if (saved && saved.status === 'vencido') { f.from = ''; f.to = ''; }
  v.innerHTML = `<div class="page-head"><div><h1>Pagamentos e recebimentos</h1><p class="muted">Contas a pagar e a receber. As vendas entram aqui sozinhas, já recebidas.</p></div>
      <div class="actions"><button class="btn" id="newr" style="border-color:var(--ok);color:var(--ok)">${icon('plus')}Conta a receber</button><button class="btn primary" id="newp">${icon('plus')}Conta a pagar</button></div></div>
    <div class="kpis" id="kpis"></div>
    <div class="card"><div class="filters">
      <div class="seg" id="ftype"><button data-t="">Todas</button><button data-t="receber">A receber</button><button data-t="pagar">A pagar</button></div>
      <label>Situação <select id="fstatus"><option value="">Todas (menos canceladas)</option><option value="aberto">Em aberto</option><option value="vencido">Vencidas</option><option value="avencer">A vencer</option><option value="pago">Pagas / recebidas</option><option value="cancelado">Canceladas</option></select></label>
      <label>De <input type="date" id="ffrom"></label><label>Até <input type="date" id="fto"></label>
      <label>Categoria <select id="fcat"><option value="">Todas</option></select></label>
      <label class="grow">Buscar <input id="fq" type="search" placeholder="Descrição, cliente ou fornecedor"></label>
    </div>
    <div class="inline small" style="gap:6px;margin:-4px 0 10px" id="presets">
      <button type="button" class="btn sm" data-pr="mes">Este mês</button><button type="button" class="btn sm" data-pr="prox">Próximo mês</button>
      <button type="button" class="btn sm" data-pr="30">Próximos 30 dias</button><button type="button" class="btn sm" data-pr="tudo">Sem período</button></div>
    <p class="help" style="margin-top:0">O período considera a data do pagamento nas contas pagas e o vencimento nas demais.</p>
    <div id="list"></div></div>`;
  const cats = [...new Set([...(meta.categories.receber || []), ...(meta.categories.pagar || [])])];
  $('#fcat', v).innerHTML += cats.map((c) => `<option>${esc(c)}</option>`).join('');
  const sync = () => {
    $$('#ftype button', v).forEach((b) => b.classList.toggle('on', b.dataset.t === f.type));
    $('#fstatus', v).value = f.status; $('#ffrom', v).value = f.from; $('#fto', v).value = f.to; $('#fcat', v).value = f.category;
  };
  let data = null;
  const load = async () => {
    sync();
    data = await api('finance', { params: { type: f.type, status: f.status, from: f.from, to: f.to, q: f.q, category: f.category, page: f.page } });
    const s = data.summary;
    $('#kpis', v).innerHTML = `
      <div class="kpi"><div class="label">A receber em aberto</div><div class="value">${money(s.receber.open_total)}</div><div class="sub">${s.receber.overdue ? `<a href="#" data-k="receber">${money(s.receber.overdue)} vencido</a>` : `${money(s.receber.next7)} nos próximos 7 dias`}</div></div>
      <div class="kpi ${s.pagar.overdue ? 'neg' : ''}"><div class="label">A pagar em aberto</div><div class="value">${money(s.pagar.open_total)}</div><div class="sub">${s.pagar.overdue ? `<a href="#" data-k="pagar">${money(s.pagar.overdue)} vencido (${s.pagar.overdue_count})</a>` : `${money(s.pagar.next7)} nos próximos 7 dias`}</div></div>
      <div class="kpi pos"><div class="label">Recebido no mês</div><div class="value">${money(s.receber.paid_month)}</div></div>
      <div class="kpi"><div class="label">Pago no mês</div><div class="value">${money(s.pagar.paid_month)}</div></div>
      <div class="kpi ${s.balance >= 0 ? '' : 'neg'}"><div class="label">Saldo em caixa</div><div class="value">${money(s.balance)}</div><div class="sub"><a href="#/fluxo-caixa">fluxo de caixa</a></div></div>`;
    const box = $('#list', v);
    const val = (e) => e.status === 'pago' ? e.paid_amount : e.amount;
    box.innerHTML = data.rows.length ? `<div class="table-wrap"><table class="fin-table"><thead><tr><th>Data</th><th>Descrição</th><th class="hide-m">Categoria</th><th>Situação</th><th class="num">Valor</th><th></th></tr></thead><tbody>
      ${data.rows.map((e) => `<tr class="click" data-id="${e.id}">
        <td class="nowrap">${dOnly(e.status === 'pago' ? e.paid_at : e.due_date)}<div class="small muted">${e.status === 'pago' ? (e.paid_at !== e.due_date ? 'venc. ' + dOnly(e.due_date) : '') : 'vencimento'}</div></td>
        <td><div class="fin-desc"><span class="fin-dot ${e.type}"></span>${esc(e.description)}${+e.installments > 1 ? ` <span class="badge">${e.installment}/${e.installments}</span>` : ''}</div>
          <div class="small muted">${[e.type === 'receber' ? 'A receber' : 'A pagar', e.party_name, e.payment_method ? payLabel(e.payment_method) : '', e.sale_id ? 'venda #' + e.sale_id : ''].filter(Boolean).map(esc).join(' · ')}</div></td>
        <td class="hide-m">${esc(e.category || '—')}</td><td>${finBadge(e)}</td>
        <td class="num ${e.type === 'receber' ? 'qty-pos' : 'qty-neg'}">${e.type === 'receber' ? '' : '− '}${money(val(e))}</td>
        <td class="num">${e.status === 'aberto' ? `<button class="btn sm" data-pay="${e.id}">${e.type === 'receber' ? 'Receber' : 'Pagar'}</button>` : ''}</td></tr>`).join('')}</tbody>
      <tfoot><tr><td colspan="4" class="small muted">${data.total} lançamento(s) no filtro</td><td class="num" colspan="2"><span class="qty-pos">${money(data.sum_receber)}</span> · <span class="qty-neg">− ${money(data.sum_pagar)}</span></td></tr></tfoot></table></div>`
      : `<div class="empty">Nenhum lançamento encontrado neste filtro.</div>`;
    if (data.total > data.per_page) box.appendChild(pager(data.total, data.page, data.per_page, (p) => { f.page = p; load().catch(fail); }));
  };
  const reload = () => { f.page = 1; load().catch(fail); };
  $('#ftype', v).onclick = (e) => { const b = e.target.closest('button'); if (b) { f.type = b.dataset.t; reload(); } };
  $('#fstatus', v).onchange = (e) => { f.status = e.target.value; if (f.status === 'vencido') { f.from = ''; f.to = ''; } reload(); };
  $('#ffrom', v).onchange = (e) => { f.from = e.target.value; reload(); };
  $('#fto', v).onchange = (e) => { f.to = e.target.value; reload(); };
  $('#fcat', v).onchange = (e) => { f.category = e.target.value; reload(); };
  $('#fq', v).oninput = debounce((e) => { f.q = e.target.value; reload(); });
  $('#presets', v).onclick = (e) => {
    const b = e.target.closest('[data-pr]'); if (!b) return;
    const t = today(), pr = b.dataset.pr;
    if (pr === 'mes') { f.from = firstOfMonth(); f.to = lastOfMonth(); }
    else if (pr === 'prox') { const n = shiftDate(lastOfMonth(), 1); f.from = n; f.to = lastOfMonth(n); }
    else if (pr === '30') { f.from = t; f.to = shiftDate(t, 30); }
    else { f.from = ''; f.to = ''; }
    reload();
  };
  $('#kpis', v).onclick = (e) => { const a = e.target.closest('[data-k]'); if (!a) return; e.preventDefault(); Object.assign(f, { type: a.dataset.k, status: 'vencido', from: '', to: '' }); reload(); };
  $('#list', v).addEventListener('click', (e) => {
    const pb = e.target.closest('[data-pay]');
    if (pb) { e.stopPropagation(); const en = data.rows.find((r) => r.id == pb.dataset.pay); payEntry(en, meta, () => load().catch(fail)); return; }
    const tr = e.target.closest('tr[data-id]');
    if (tr) { const en = data.rows.find((r) => r.id == tr.dataset.id); financeForm(en, meta, () => load().catch(fail)); }
  });
  $('#newr', v).onclick = () => financeForm({ type: 'receber' }, meta, () => load().catch(fail));
  $('#newp', v).onclick = () => financeForm({ type: 'pagar' }, meta, () => load().catch(fail));
  await load();
}

/** Cadastro / edição de conta a pagar ou a receber. */
function financeForm(e, meta, onSaved) {
  const isNew = !e.id, rec = e.type === 'receber';
  const locked = !!e.sale_id || e.status === 'cancelado';
  let party = rec ? (e.customer_id ? { id: e.customer_id, name: e.customer_name } : null) : (e.supplier_id ? { id: e.supplier_id, name: e.supplier_name } : null);
  const fmt2 = (n) => (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const word = rec ? 'a receber' : 'a pagar';
  const m = modal({
    title: isNew ? `Nova conta ${word}` : `Conta ${word}`,
    body: `<form class="form" id="ff">
      ${!isNew ? `<div class="inline" style="justify-content:space-between">${finBadge(e)}<span class="small muted">${+e.installments > 1 ? `Parcela ${e.installment} de ${e.installments} · ` : ''}lançado ${dt(e.created_at)}${e.user_name ? ' por ' + esc(e.user_name) : ''}</span></div>` : ''}
      ${e.sale_id ? `<div class="alert">Este recebimento veio da <a href="#/venda/${e.sale_id}">venda #${e.sale_id}</a>. Para desfazer, cancele a venda.</div>` : ''}
      ${e.status === 'pago' ? `<div class="alert ok">${rec ? 'Recebido' : 'Pago'} em ${dOnly(e.paid_at)}: ${money(e.paid_amount)}${e.payment_method ? ' · ' + escpayLabel(e.payment_method) : ''}</div>` : ''}
      <label>Descrição * <input name="description" value="${esc(e.description)}" maxlength="200" required placeholder="${rec ? 'Ex.: Serviço de manutenção, cliente X' : 'Ex.: Aluguel, conta de luz, boleto do fornecedor'}"></label>
      <div class="row"><label>Valor ${isNew ? 'total ' : ''}* <input name="amount" inputmode="decimal" value="${e.amount ? fmt2(e.amount) : ''}" placeholder="0,00" required></label>
        <label>${isNew ? 'Vencimento (1ª parcela)' : 'Vencimento'} * <input type="date" name="due_date" value="${esc(e.due_date || today())}" required></label></div>
      <div class="row"><label>Categoria <input name="category" list="fcats" value="${esc(e.category || '')}" maxlength="80" placeholder="Escolha ou digite"><datalist id="fcats">${(meta.categories[e.type] || []).map((c) => `<option value="${esc(c)}">`).join('')}</datalist></label>
        <label>Forma de pagamento <select name="payment_method">${pmOptions(e.payment_method)}</select></label></div>
      <div><div class="qlabel-f">${rec ? 'Cliente' : 'Fornecedor'} (opcional)</div><div class="inline" style="flex-wrap:nowrap"><div id="party" style="flex:1"></div><button type="button" class="btn sm" id="pickp">${icon('search')}Selecionar</button><button type="button" class="btn sm" id="clrp" title="Remover">${icon('x')}</button></div></div>
      ${isNew ? `<div class="row"><label>Parcelas <input type="number" name="installments" min="1" max="120" value="1"></label>
        <label>Como lançar <select name="installment_mode"><option value="dividir">Dividir o valor nas parcelas</option><option value="repetir">Repetir o valor todo mês</option></select></label></div>
        <p class="help" id="instinfo" style="margin-top:-6px"></p>
        <label class="check"><input type="checkbox" name="paid"> Já foi ${rec ? 'recebido' : 'pago'} (a 1ª parcela)</label>
        <label id="paidat" hidden>Data do ${rec ? 'recebimento' : 'pagamento'} <input type="date" name="paid_at" value="${today()}"></label>` : ''}
      <label>Observação <textarea name="notes" rows="2" maxlength="500">${esc(e.notes || '')}</textarea></label>
    </form>`,
    foot: `${!isNew && !e.sale_id ? `<span style="margin-right:auto" class="inline">${e.status === 'pago' ? '<button class="btn sm" id="reopen">Reabrir</button>' : ''}${e.status === 'aberto' ? '<button class="btn sm danger" id="cancel">Cancelar conta</button>' : ''}${e.status !== 'pago' ? `<button class="btn sm danger" id="del">${icon('trash')}Excluir</button>` : ''}</span>` : ''}
      <button class="btn" data-close>Fechar</button>${!isNew && e.status === 'aberto' ? `<button class="btn" id="pay">${rec ? 'Receber' : 'Pagar'}</button>` : ''}${locked ? '' : '<button class="btn primary" id="fsave">Salvar</button>'}`,
  });
  const fm = $('#ff', m.el);
  if (locked) $$('input,select,textarea,#pickp,#clrp', fm).forEach((x) => (x.disabled = true));
  const paintParty = () => { $('#party', m.el).innerHTML = party ? `<div class="cust-card" style="padding:8px 12px"><div class="cust-name">${esc(custName(party))}</div></div>` : '<span class="muted small">Nenhum</span>'; $('#clrp', m.el).hidden = !party || locked; };
  paintParty();
  $('#pickp', m.el).onclick = () => customerBrowser({ entity: rec ? 'customer' : 'supplier', selected: party, onSelect: (c) => { party = c; paintParty(); } });
  $('#clrp', m.el).onclick = () => { party = null; paintParty(); };
  if (isNew) {
    const info = () => {
      const n = Math.max(1, parseInt(fm.installments.value) || 1), a = num(fm.amount.value), mode = fm.installment_mode.value;
      $('#instinfo', m.el).textContent = n > 1 ? (mode === 'dividir' ? `${n} parcelas de ${money(Math.floor(a / n * 100) / 100)}, uma por mês.` : `${n} lançamentos de ${money(a)}, um por mês (total ${money(a * n)}).`) : '';
    };
    fm.addEventListener('input', info); fm.addEventListener('change', info);
    fm.paid.onchange = () => { $('#paidat', m.el).hidden = !fm.paid.checked; };
  }
  const sv = $('#fsave', m.el);
  if (sv) sv.onclick = () => busy(sv, async () => {
    if (!fm.reportValidity()) return;
    const d = formData(fm);
    await api('finance_save', { body: { ...d, id: e.id, type: e.type, customer_id: rec && party ? party.id : null, supplier_id: !rec && party ? party.id : null } });
    toast(isNew ? 'Conta lançada' : 'Conta atualizada'); m.close(); onSaved && onSaved();
  });
  const act = (sel, route2, msg, ask) => { const b = $(sel, m.el); if (b) b.onclick = async () => { if (ask && !(await confirmBox(ask, { ok: 'Confirmar', danger: true }))) return; busy(b, async () => { await api(route2, { body: { id: e.id } }); toast(msg); m.close(); onSaved && onSaved(); }); }; };
  act('#reopen', 'finance_reopen', 'Conta reaberta');
  act('#cancel', 'finance_cancel', 'Conta cancelada', 'Cancelar esta conta? Ela sai do fluxo de caixa, mas continua no histórico.');
  act('#del', 'finance_delete', 'Conta excluída', 'Excluir esta conta? Não dá para desfazer.');
  const pb = $('#pay', m.el); if (pb) pb.onclick = () => { m.close(); payEntry(e, meta, onSaved); };
}

/** Baixa: registrar pagamento ou recebimento. */
function payEntry(e, meta, onDone) {
  const rec = e.type === 'receber';
  const fmt2 = (n) => (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const m = modal({
    title: rec ? 'Registrar recebimento' : 'Registrar pagamento',
    body: `<p style="margin-top:0"><strong>${esc(e.description)}</strong><br><span class="muted small">${e.party_name ? esc(e.party_name) + ' · ' : ''}vence ${dOnly(e.due_date)} · ${money(e.amount)}</span></p>
      <form class="form" id="pf">
      <div class="row"><label>Data * <input type="date" name="paid_at" value="${today()}" required></label>
        <label>Valor ${rec ? 'recebido' : 'pago'} * <input name="paid_amount" inputmode="decimal" value="${fmt2(e.amount)}" required></label></div>
      <label>Forma de pagamento <select name="payment_method">${pmOptions(e.payment_method)}</select></label>
      <label class="check" id="restw" hidden><input type="checkbox" name="keep_rest" checked> <span id="restl"></span></label>
      <p class="help" id="morel" hidden>Valor maior que o da conta (juros ou multa): o valor ${rec ? 'recebido' : 'pago'} é o que entra no fluxo de caixa.</p>
      </form>`,
    foot: `<button class="btn" data-close>Voltar</button><button class="btn primary" id="pok">${icon('check')}Confirmar</button>`,
  });
  const fm = $('#pf', m.el);
  const upd = () => {
    const pv = num(fm.paid_amount.value), rest = Math.round((+e.amount - pv) * 100) / 100;
    $('#restw', m.el).hidden = !(rest > 0.004 && pv > 0);
    $('#restl', m.el).textContent = `Deixar o restante (${money(rest)}) em aberto com o mesmo vencimento`;
    $('#morel', m.el).hidden = !(rest < -0.004);
  };
  fm.paid_amount.oninput = upd; upd();
  $('#pok', m.el).onclick = (ev) => busy(ev.currentTarget, async () => {
    if (!fm.reportValidity()) return;
    const d = formData(fm);
    if ($('#restw', m.el).hidden) d.keep_rest = false;
    await api('finance_pay', { body: { ...d, id: e.id } });
    toast(rec ? 'Recebimento registrado' : 'Pagamento registrado'); m.close(); onDone && onDone();
  });
}

// ============ Fluxo de caixa ============
function cashChart(rows, group) {
  if (!rows.length) return '<p class="muted small">Sem dados no período.</p>';
  return chartBox((w) => cashSvg(rows, group, w), 230) + `
    <div class="legend"><span><i class="lg-in"></i>Entradas</span><span><i class="lg-out"></i>Saídas</span><span><i class="lg-proj"></i>Previsto (em aberto)</span><span><i class="lg-bal"></i>Saldo</span></div>`;
}
function cashSvg(rows, group, W) {
  const H = 230, pad = { l: 58, r: 8, t: 12, b: 26 };
  const ins = rows.map((r) => r.in + r.in_proj), outs = rows.map((r) => r.out + r.out_proj);
  const bals = rows.map((r) => r.balance_proj);
  const top = Math.max(1, ...ins, ...outs, ...bals), bottom = Math.min(0, ...bals);
  const niceStep = (span) => { const raw = span / 4, p = Math.pow(10, Math.floor(Math.log10(raw || 1))), f = raw / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; };
  const step = niceStep(top - bottom), hi = Math.ceil(top / step) * step, lo = Math.floor(bottom / step) * step;
  const iw = W - pad.l - pad.r, ih = H - pad.t - pad.b, bw = iw / rows.length;
  const y = (val) => pad.t + ih - ((val - lo) / (hi - lo || 1)) * ih;
  const lab = (k) => group === 'mes' ? `${k.slice(5, 7)}/${k.slice(2, 4)}` : `${k.slice(8, 10)}/${k.slice(5, 7)}`;
  const fmtK = (val) => Math.abs(val) >= 1000 ? (val / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + 'k' : val.toLocaleString('pt-BR');
  let g = '';
  for (let val = lo; val <= hi + step / 2; val += step) { const yy = y(val); g += `<line class="${val === 0 ? 'axis0' : 'axis'}" x1="${pad.l}" x2="${W - pad.r}" y1="${yy}" y2="${yy}"/><text x="${pad.l - 6}" y="${yy + 3}" text-anchor="end">${fmtK(val)}</text>`; }
  const labStep = Math.ceil(rows.length / Math.max(4, Math.floor(W / 70))), w = Math.max(1, Math.min(bw * 0.36, 24)), z = y(0);
  rows.forEach((r, i) => {
    const x = pad.l + i * bw + bw / 2 - w;
    const tip = `${group === 'mes' ? lab(r.key) : dOnly(r.key)} — entradas ${money(r.in)}${r.in_proj ? ` (+${money(r.in_proj)} previsto)` : ''}, saídas ${money(r.out)}${r.out_proj ? ` (+${money(r.out_proj)} previsto)` : ''}, saldo ${money(r.balance_proj)}`;
    const bar = (cls, x0, v0, v1) => v1 > 0 ? `<rect class="${cls}" x="${x0}" y="${y(v0 + v1)}" width="${w}" height="${Math.max(0, y(v0) - y(v0 + v1))}"><title>${esc(tip)}</title></rect>` : '';
    g += bar('cin', x, 0, r.in) + bar('cin proj', x, r.in, r.in_proj) + bar('cout', x + w, 0, r.out) + bar('cout proj', x + w, r.out, r.out_proj);
    if (i % labStep === 0) g += `<text x="${pad.l + i * bw + bw / 2}" y="${H - 7}" text-anchor="middle">${lab(r.key)}</text>`;
  });
  const pts = rows.map((r, i) => `${(pad.l + i * bw + bw / 2).toFixed(1)},${y(r.balance_proj).toFixed(1)}`).join(' ');
  g += `<polyline class="cbal" points="${pts}"/>`;
  if (rows.length <= 40) rows.forEach((r, i) => { g += `<circle class="cbal-dot" cx="${(pad.l + i * bw + bw / 2).toFixed(1)}" cy="${y(r.balance_proj).toFixed(1)}" r="2.5"/>`; });
  return `<svg class="chart cash" viewBox="0 0 ${W} ${H}" role="img" aria-label="Entradas, saídas e saldo do caixa">${g}<line class="axis0" x1="${pad.l}" x2="${W - pad.r}" y1="${z}" y2="${z}"/></svg>`;
}

async function viewCashflow(v) {
  const f = { from: firstOfMonth(), to: lastOfMonth(), group: 'dia', hideEmpty: true };
  v.innerHTML = `<div class="page-head"><div><h1>Fluxo de caixa</h1><p class="muted">Entradas e saídas realizadas e o que ainda vai entrar e sair (contas em aberto).</p></div>
      <div class="actions"><button class="btn" id="csv">Download</button><a class="btn" href="#/financeiro">${icon('wallet')}Pagamentos e recebimentos</a></div></div>
    <div class="card"><div class="filters">
      <label>De <input type="date" id="from"></label><label>Até <input type="date" id="to"></label>
      <div class="seg" id="grp"><button data-g="dia">Por dia</button><button data-g="mes">Por mês</button></div>
      <div class="inline small" style="gap:6px" id="presets"><button type="button" class="btn sm" data-pr="mes">Este mês</button><button type="button" class="btn sm" data-pr="prox">Próximo mês</button><button type="button" class="btn sm" data-pr="90">Próximos 90 dias</button><button type="button" class="btn sm" data-pr="ano">Este ano</button></div>
    </div></div>
    <div id="out"><p class="muted">Carregando…</p></div>`;
  let d = null;
  const load = async () => {
    $('#from', v).value = f.from; $('#to', v).value = f.to;
    $$('#grp button', v).forEach((b) => b.classList.toggle('on', b.dataset.g === f.group));
    d = await api('cashflow', { params: { from: f.from, to: f.to, group: f.group } });
    const t = d.totals, hasProj = t.in_proj || t.out_proj;
    const label = (k) => f.group === 'mes' ? new Date(k + '-15T12:00:00').toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }) : dOnly(k) + ' <span class="muted small">' + new Date(k + 'T12:00:00').toLocaleDateString('pt-BR', { weekday: 'short' }) + '</span>';
    const rows = d.rows.filter((r) => !(f.hideEmpty && f.group === 'dia') || r.in || r.out || r.in_proj || r.out_proj || r.key === d.today);
    const byCat = (type) => d.by_category.filter((c) => c.type === type);
    const catTable = (type, title) => { const list = byCat(type), tot = list.reduce((a, c) => a + c.value, 0); return `<div class="card"><h2>${title}</h2>${list.length ? `<div class="table-wrap"><table><tbody>${list.map((c) => `<tr><td>${esc(c.category)}<div class="catbar"><i style="width:${tot ? (c.value / tot * 100).toFixed(1) : 0}%;background:var(${type === 'receber' ? '--ok' : '--danger'})"></i></div></td><td class="num">${money(c.value)}</td><td class="num muted small">${tot ? pct(c.value / tot * 100) : ''}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted small">Nada realizado no período.</p>'}</div>`; };
    $('#out', v).innerHTML = `
      ${d.overdue.receber || d.overdue.pagar ? `<div class="alert warn">Contas vencidas em aberto: ${d.overdue.pagar ? `<strong>${money(d.overdue.pagar)}</strong> a pagar` : ''}${d.overdue.pagar && d.overdue.receber ? ' e ' : ''}${d.overdue.receber ? `<strong>${money(d.overdue.receber)}</strong> a receber` : ''}. No previsto elas aparecem em hoje. <a href="#/financeiro" id="seeov">Ver contas vencidas</a></div>` : ''}
      <div class="kpis">
        <div class="kpi"><div class="label">Saldo no início</div><div class="value">${money(d.opening)}</div><div class="sub">${isAdmin() ? `<a href="#" id="setop">saldo inicial do caixa: ${money(d.opening_setting)}</a>` : `saldo inicial do caixa: ${money(d.opening_setting)}`}</div></div>
        <div class="kpi pos"><div class="label">Entradas realizadas</div><div class="value">${money(t.in)}</div>${t.in_proj ? `<div class="sub">+ ${money(t.in_proj)} previsto</div>` : ''}</div>
        <div class="kpi neg"><div class="label">Saídas realizadas</div><div class="value">${money(t.out)}</div>${t.out_proj ? `<div class="sub">+ ${money(t.out_proj)} previsto</div>` : ''}</div>
        <div class="kpi ${d.closing >= 0 ? '' : 'neg'}"><div class="label">Saldo realizado</div><div class="value">${money(d.closing)}</div><div class="sub">${d.to >= d.today && d.from <= d.today ? 'até hoje' : 'no fim do período'}</div></div>
        ${hasProj || d.to >= d.today ? `<div class="kpi ${d.closing_proj >= 0 ? '' : 'neg'}"><div class="label">Saldo previsto</div><div class="value">${money(d.closing_proj)}</div><div class="sub">em ${dOnly(d.to)}, se tudo for pago</div></div>` : ''}
      </div>
      <div class="card"><div class="card-head"><h2>Movimento ${f.group === 'mes' ? 'mensal' : 'diário'}</h2></div>${cashChart(d.rows, f.group)}</div>
      <div class="card"><div class="card-head"><h2>Detalhamento</h2>${f.group === 'dia' ? `<label class="check small"><input type="checkbox" id="hide" ${f.hideEmpty ? 'checked' : ''}> Ocultar dias sem movimento</label>` : ''}</div>
        <div class="table-wrap"><table class="cash-table"><thead><tr><th>${f.group === 'mes' ? 'Mês' : 'Data'}</th><th class="num">Entradas</th><th class="num">Saídas</th>${hasProj ? '<th class="num hide-m">A receber</th><th class="num hide-m">A pagar</th>' : ''}<th class="num">Saldo</th>${hasProj ? '<th class="num">Saldo previsto</th>' : ''}</tr></thead>
        <tbody><tr class="muted"><td>Saldo anterior</td><td></td><td></td>${hasProj ? '<td class="hide-m"></td><td class="hide-m"></td>' : ''}<td class="num">${money(d.opening)}</td>${hasProj ? `<td class="num">${money(d.opening_proj)}</td>` : ''}</tr>
        ${rows.map((r) => `<tr class="${r.key === d.today || (f.group === 'mes' && d.today.startsWith(r.key)) ? 'today' : ''}"><td class="nowrap">${label(r.key)}</td>
          <td class="num qty-pos">${r.in ? money(r.in) : ''}</td><td class="num qty-neg">${r.out ? '− ' + money(r.out) : ''}</td>
          ${hasProj ? `<td class="num hide-m muted">${r.in_proj ? money(r.in_proj) : ''}</td><td class="num hide-m muted">${r.out_proj ? '− ' + money(r.out_proj) : ''}</td>` : ''}
          <td class="num ${r.balance < 0 ? 'stock-low' : ''}">${money(r.balance)}</td>${hasProj ? `<td class="num ${r.balance_proj < 0 ? 'stock-low' : ''}">${money(r.balance_proj)}</td>` : ''}</tr>`).join('') || `<tr><td colspan="7" class="empty">Nenhum movimento no período.</td></tr>`}</tbody>
        <tfoot><tr><td>Total</td><td class="num qty-pos">${money(t.in)}</td><td class="num qty-neg">− ${money(t.out)}</td>${hasProj ? `<td class="num hide-m">${money(t.in_proj)}</td><td class="num hide-m">− ${money(t.out_proj)}</td>` : ''}<td class="num">${money(d.closing)}</td>${hasProj ? `<td class="num">${money(d.closing_proj)}</td>` : ''}</tr></tfoot></table></div></div>
      <div class="grid two">${catTable('receber', 'Entradas por categoria')}${catTable('pagar', 'Saídas por categoria')}</div>`;
    const h = $('#hide', v); if (h) h.onchange = (e) => { f.hideEmpty = e.target.checked; load().catch(fail); };
    const so = $('#seeov', v); if (so) so.onclick = (e) => { e.preventDefault(); sessionStorage.setItem('finFilter', JSON.stringify({ status: 'vencido' })); go('financeiro'); };
    if ($('#setop', v)) $('#setop', v).onclick = (e) => { e.preventDefault(); openingForm(d.opening_setting, () => load().catch(fail)); };
  };
  $('#from', v).onchange = (e) => { f.from = e.target.value; load().catch(fail); };
  $('#to', v).onchange = (e) => { f.to = e.target.value; load().catch(fail); };
  $('#grp', v).onclick = (e) => { const b = e.target.closest('button'); if (b) { f.group = b.dataset.g; load().catch(fail); } };
  $('#presets', v).onclick = (e) => {
    const b = e.target.closest('[data-pr]'); if (!b) return;
    const t = today(), pr = b.dataset.pr;
    if (pr === 'mes') { f.from = firstOfMonth(); f.to = lastOfMonth(); f.group = 'dia'; }
    else if (pr === 'prox') { const n = shiftDate(lastOfMonth(), 1); f.from = n; f.to = lastOfMonth(n); f.group = 'dia'; }
    else if (pr === '90') { f.from = t; f.to = shiftDate(t, 90); }
    else { f.from = t.slice(0, 4) + '-01-01'; f.to = t.slice(0, 4) + '-12-31'; f.group = 'mes'; }
    load().catch(fail);
  };
  $('#csv', v).onclick = () => {
    if (!d) return;
    const n = (x) => Number(x).toFixed(2).replace('.', ',');
    const lines = [['Data', 'Entradas', 'Saídas', 'A receber (previsto)', 'A pagar (previsto)', 'Saldo', 'Saldo previsto'].join(';'),
      ...d.rows.map((r) => [f.group === 'mes' ? r.key : dOnly(r.key), n(r.in), n(r.out), n(r.in_proj), n(r.out_proj), n(r.balance), n(r.balance_proj)].join(';'))];
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `fluxo-de-caixa-${d.from}-a-${d.to}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };
  await load();
}

function openingForm(cur, onSaved) {
  const m = modal({
    title: 'Saldo inicial do caixa',
    body: `<p style="margin-top:0">Quanto havia em caixa e no banco <strong>antes</strong> do primeiro lançamento registrado no sistema. É o ponto de partida do saldo.</p>
      <label>Saldo inicial (R$) <input id="opv" inputmode="decimal" value="${(Number(cur) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}"></label>
      <p class="help">Pode ser negativo (ex.: conta no cheque especial). Use o sinal de menos: −500,00.</p>`,
    foot: `<button class="btn" data-close>Voltar</button><button class="btn primary" id="opok">Salvar</button>`,
  });
  $('#opok', m.el).onclick = (e) => busy(e.currentTarget, async () => {
    const raw = $('#opv', m.el).value.replace('−', '-');
    await api('cash_opening', { body: { value: raw } }); toast('Saldo inicial atualizado'); m.close(); onSaved && onSaved();
  });
}

// ============ Empresa (configurações) ============
async function viewSettings(v) {
  if (!isAdmin()) { v.innerHTML = '<div class="alert err">Apenas administradores.</div>'; return; }
  const s = await api('settings');
  // Endereço antigo (linha única) vai para "Rua" até a pessoa preencher os campos
  const legacy = !s.company_street && !s.company_cep && s.company_address ? s.company_address : '';
  let logo = s.company_logo || '';
  v.innerHTML = `<div class="page-head"><div><h1>Dados da empresa</h1><p class="muted">Aparecem no cabeçalho dos orçamentos enviados aos clientes e no PDF.</p></div>
      <div class="actions"><a class="btn" id="preview" href="#" title="Abre o último orçamento para ver como fica">Ver num orçamento</a></div></div>
    <div class="card" style="max-width:820px"><form class="form" id="sf" autocomplete="off">
      <fieldset><legend>Logo</legend>
        <div class="logo-row"><div class="logo-box" id="logobox"></div>
          <div class="form" style="gap:8px"><input type="file" id="logofile" accept="image/png,image/jpeg,image/webp" hidden>
            <div class="inline"><button type="button" class="btn" id="logoup">${icon('upload')}Enviar logo</button><button type="button" class="btn ghost danger" id="logodel">Remover</button></div>
            <span class="help">PNG (de preferência com fundo transparente), JPG ou WebP. A imagem é ajustada automaticamente para no máximo 600 × 240 pixels.</span></div></div>
      </fieldset>
      <div class="row"><label>CNPJ <span class="inline" style="flex-wrap:nowrap"><input name="company_document" value="${esc(docFmt(s.company_document))}" inputmode="numeric" style="flex:1"><button type="button" class="btn" id="lk">${icon('search')}Buscar dados</button></span><span class="help">Preenche razão social, nome fantasia, contato e endereço pela Receita Federal.</span></label></div>
      <div class="row"><label>Razão social <input name="company_legal_name" value="${esc(s.company_legal_name)}" maxlength="200"></label><label>Nome fantasia <input name="company_name" value="${esc(s.company_name)}" maxlength="200"><span class="help">Aparece no menu e no destaque dos orçamentos</span></label></div>
      <div class="row"><label>Telefone / WhatsApp <input name="company_phone" value="${esc(s.company_phone)}" maxlength="60"></label><label>E-mail <input name="company_email" value="${esc(s.company_email)}" maxlength="190"></label><label>Site / Instagram <input name="company_site" value="${esc(s.company_site)}" maxlength="190"></label></div>
      <fieldset><legend>Endereço</legend>
        <div class="row">
          <label>CEP <input name="company_cep" inputmode="numeric" maxlength="9" value="${esc(cepFmt(s.company_cep))}"><span class="help" id="cephelp">Digite o CEP para preencher o endereço</span></label>
          <label style="grid-column: span 2">Rua <input name="company_street" maxlength="200" value="${esc(s.company_street || legacy)}"></label>
        </div>
        <div class="row">
          <label>Número <input name="company_number" maxlength="20" value="${esc(s.company_number)}"></label>
          <label>Complemento <input name="company_complement" maxlength="200" value="${esc(s.company_complement)}"></label>
          <label>Bairro <input name="company_district" maxlength="100" value="${esc(s.company_district)}"></label>
        </div>
        <div class="row">
          <label>Cidade <input name="company_city" maxlength="100" value="${esc(s.company_city)}"></label>
          <label>UF <input name="company_state" maxlength="2" style="text-transform:uppercase" value="${esc(s.company_state)}"></label>
        </div>
        ${legacy ? '<p class="help" style="margin:0">Seu endereço antigo foi colocado em "Rua". Digite o CEP para organizar nos campos.</p>' : ''}
      </fieldset>
      <div><button class="btn primary">Salvar</button></div></form></div>`;
  const f = $('#sf', v);
  const paintLogo = () => {
    $('#logobox', v).innerHTML = logo ? `<img src="${esc(logo)}" alt="Logo">` : `<span class="muted small">${icon('image')}<br>Sem logo</span>`;
    $('#logodel', v).hidden = !logo;
  };
  paintLogo();
  // Reduz a imagem no navegador antes de enviar (deixa leve e no tamanho certo)
  const shrink = (file) => new Promise((resolve, reject) => {
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return reject(new Error('Envie o logo em PNG, JPG ou WebP'));
    if (file.size > 15 * 1024 * 1024) return reject(new Error('Imagem grande demais (máx. 15 MB)'));
    const img = new Image(), url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      const k = Math.min(1, 600 / img.width, 240 / img.height);
      const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      const keepAlpha = file.type !== 'image/jpeg';
      let out = c.toDataURL(keepAlpha ? 'image/png' : 'image/jpeg', 0.9);
      if (out.length > 780000) out = c.toDataURL('image/webp', 0.85);
      resolve(out);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Não consegui abrir essa imagem')); };
    img.src = url;
  });
  $('#logoup', v).onclick = () => $('#logofile', v).click();
  $('#logofile', v).onchange = async (e) => {
    const file = e.target.files[0]; e.target.value = '';
    if (!file) return;
    try { logo = await shrink(file); paintLogo(); await api('settings', { body: { company_logo: logo } }); toast('Logo salvo'); }
    catch (err) { fail(err); logo = s.company_logo || ''; paintLogo(); }
  };
  $('#logodel', v).onclick = async () => {
    if (!(await confirmBox('Remover o logo da empresa?', { ok: 'Remover', danger: true }))) return;
    try { await api('settings', { body: { company_logo: '' } }); logo = ''; s.company_logo = ''; paintLogo(); toast('Logo removido'); } catch (err) { fail(err); }
  };
  const fill = (d) => { for (const [k, val] of Object.entries(d)) { const el = f.elements[k]; if (el && val) el.value = k === 'company_cep' ? cepFmt(val) : val; } };
  let lastCep = String(s.company_cep || '');
  f.elements.company_cep.addEventListener('input', async (e) => {
    const cep = e.target.value.replace(/\D/g, '');
    if (cep.length !== 8 || cep === lastCep) return;
    lastCep = cep; e.target.value = cepFmt(cep);
    $('#cephelp', v).textContent = 'Buscando endereço…';
    try {
      const d = await api('cep', { params: { cep } });
      if (legacy && f.elements.company_street.value === legacy) f.elements.company_street.value = '';
      fill({ company_street: d.street, company_district: d.district, company_city: d.city, company_state: d.state });
      $('#cephelp', v).textContent = 'Endereço preenchido. Falta o número.'; f.elements.company_number.focus();
    } catch (err) { $('#cephelp', v).innerHTML = `<span class="stock-low">${esc(err.message)}</span>`; }
  });
  $('#lk', v).onclick = (e) => busy(e.currentTarget, async () => {
    const d = await api('cnpj', { params: { cnpj: f.elements.company_document.value } });
    f.elements.company_legal_name.value = d.name || '';
    f.elements.company_name.value = d.trade_name || d.name || '';
    if (d.phone && !f.elements.company_phone.value) f.elements.company_phone.value = d.phone;
    if (d.email && !f.elements.company_email.value) f.elements.company_email.value = d.email;
    ['company_street', 'company_number', 'company_complement', 'company_district', 'company_city', 'company_state', 'company_cep'].forEach((k) => (f.elements[k].value = ''));
    fill({ company_cep: d.cep, company_street: d.street, company_number: d.number, company_complement: d.complement, company_district: d.district, company_city: d.city, company_state: d.state });
    lastCep = String(d.cep || '');
    toast('Dados preenchidos');
  });
  $('#preview', v).onclick = async (e) => {
    e.preventDefault();
    try {
      const d = await api('quotes', { params: { limit: 1 } });
      if (!d.rows.length) { toast('Crie um orçamento para ver como fica'); return; }
      const q = await api('quote', { params: { id: d.rows[0].id } });
      window.open(q.public_url, '_blank', 'noopener');
    } catch (err) { fail(err); }
  };
  f.onsubmit = (e) => {
    e.preventDefault();
    busy($('button.primary', f), async () => {
      const d = formData(f);
      if (d.company_email && !/^\S+@\S+\.\S+$/.test(d.company_email)) throw new Error('E-mail inválido');
      await api('settings', { body: d }); toast('Dados da empresa salvos');
      state.meta = null; state.meta = await api('meta'); paintBrand();
    });
  };
}

// ============ Relatórios ============
async function viewReports(v) {
  const t = today();
  const first = t.slice(0, 8) + '01';
  const prevEnd = shiftDate(first, -1);
  const presets = { hoje: [t, t], '7d': [shiftDate(t, -6), t], mes: [first, t], anterior: [prevEnd.slice(0, 8) + '01', prevEnd], '90d': [shiftDate(t, -89), t] };
  let [from, to] = presets.mes;
  v.innerHTML = `<div class="page-head"><div><h1>Relatórios</h1></div><div class="actions"><button class="btn" id="csv">Exportar CSV</button><button class="btn" id="print">Imprimir</button></div></div>
    <div class="card"><div class="filters"><div class="seg" id="pre"><button data-p="hoje">Hoje</button><button data-p="7d">7 dias</button><button data-p="mes" class="on">Este mês</button><button data-p="anterior">Mês passado</button><button data-p="90d">90 dias</button></div>
      <label>De <input type="date" id="ff" value="${from}"></label><label>Até <input type="date" id="ft" value="${to}"></label></div></div><div id="rep"></div>`;
  let data = null;
  const load = async () => {
    data = await api('report', { params: { from, to } });
    const d = data;
    $('#rep', v).innerHTML = `<div class="kpis">
      <div class="kpi"><div class="label">Faturamento</div><div class="value">${money(d.revenue)}</div><div class="sub">${d.sales_count} venda(s) · ticket ${money(d.avg_ticket)}</div></div>
      <div class="kpi"><div class="label">Custo dos produtos vendidos</div><div class="value">${money(d.cost)}</div></div>
      <div class="kpi ${d.gross_profit >= 0 ? 'pos' : 'neg'}"><div class="label">Lucro bruto</div><div class="value">${money(d.gross_profit)}</div><div class="sub">margem ${pct(d.margin)}</div></div>
      <div class="kpi"><div class="label">Perdas</div><div class="value">${money(d.losses)}</div><div class="sub">descontos dados ${money(d.discounts)}</div></div>
      <div class="kpi ${d.profit_after_losses >= 0 ? 'pos' : 'neg'}"><div class="label">Lucro após perdas</div><div class="value">${money(d.profit_after_losses)}</div></div>
      <div class="kpi"><div class="label">Compras no período</div><div class="value">${money(d.purchases)}</div><div class="sub">notas e entradas manuais</div></div>
      <div class="kpi"><div class="label">Valor em estoque hoje</div><div class="value">${money(d.stock.value)}</div></div></div>
      ${d.by_day.length ? `<div class="card"><h2>Por dia</h2>${barChart(d.by_day)}</div>` : ''}
      <div class="grid two report-grid">
        <div class="card"><h2>Por produto</h2>${d.by_product.length ? `<div class="table-wrap"><table><thead><tr><th>Produto</th><th class="num">Qtd.</th><th class="num">Faturamento</th><th class="num">Custo</th><th class="num">Lucro</th><th class="num">Margem</th></tr></thead><tbody>
          ${d.by_product.map((p) => `<tr><td><a href="#/produto/${p.id}">${esc(p.name)}</a></td><td class="num">${qty(p.qty)} ${esc(p.unit)}</td><td class="num">${money(p.revenue)}</td><td class="num">${money(p.cost)}</td><td class="num ${p.profit < 0 ? 'qty-neg' : ''}">${money(p.profit)}</td><td class="num">${pct(p.margin)}</td></tr>`).join('')}
          </tbody></table></div>` : '<p class="muted">Sem vendas no período.</p>'}</div>
        <div><div class="card"><h2>Formas de pagamento</h2>${d.by_payment.length ? `<div class="table-wrap"><table><tbody>${d.by_payment.map((p) => `<tr><td>${esc(p.label)}<div class="small muted">${p.n} venda(s)</div></td><td class="num">${money(p.total)}<div class="small muted">${pct(d.revenue ? (p.total / d.revenue) * 100 : 0)}</div></td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Sem vendas no período.</p>'}</div>
        <div class="card"><h2>Melhores clientes</h2>${(d.by_customer || []).length ? `<div class="table-wrap"><table><tbody>${d.by_customer.map((c) => `<tr><td>${c.id ? `<a href="#/cliente/${c.id}">${esc(c.name)}</a>` : esc(c.name)}<div class="small muted">${c.n} compra(s)</div></td><td class="num">${money(c.total)}<div class="small muted">lucro ${money(c.profit)}</div></td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Sem vendas no período.</p>'}</div></div>
      </div>
      <p class="help">Lucro bruto = faturamento − custo dos produtos vendidos (pelo custo médio na hora da venda). Não inclui despesas fixas como aluguel, gás, embalagens não cadastradas ou taxas de cartão.</p>`;
  };
  const set = (f, t2) => { from = f; to = t2; $('#ff', v).value = f; $('#ft', v).value = t2; load(); };
  $('#pre', v).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; $$('#pre button', v).forEach((x) => x.classList.toggle('on', x === b)); set(...presets[b.dataset.p]); };
  $('#ff', v).onchange = (e) => { $$('#pre button', v).forEach((x) => x.classList.remove('on')); from = e.target.value; load(); };
  $('#ft', v).onchange = (e) => { $$('#pre button', v).forEach((x) => x.classList.remove('on')); to = e.target.value; load(); };
  $('#print', v).onclick = () => window.print();
  $('#csv', v).onclick = () => {
    if (!data) return;
    const n = (x) => String(Number(x).toFixed(2)).replace('.', ',');
    const lines = [['Produto', 'Quantidade', 'Unidade', 'Faturamento', 'Custo', 'Lucro', 'Margem %'].join(';'),
      ...data.by_product.map((p) => [`"${p.name.replace(/"/g, '""')}"`, String(p.qty).replace('.', ','), p.unit, n(p.revenue), n(p.cost), n(p.profit), n(p.margin)].join(';')),
      '', ['Total', '', '', n(data.revenue), n(data.cost), n(data.gross_profit), n(data.margin)].join(';')];
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `relatorio-${from}-a-${to}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };
  await load();
}

// ============ Usuários e conta ============
async function viewUsers(v) {
  if (!isAdmin()) { v.innerHTML = '<div class="alert err">Apenas administradores.</div>'; return; }
  const [rows, { profiles }] = await Promise.all([api('users'), api('profiles')]);
  v.innerHTML = `<div class="page-head"><div><h1>Usuários</h1><p class="muted">Quem pode acessar o sistema. O que cada um vê depende do perfil de permissão (em <a href="#/permissoes">Permissões</a>).</p></div>
    <div class="actions"><button class="btn primary" id="new">${icon('plus')}Novo usuário</button></div></div>
    <div class="card"><div class="table-wrap"><table><thead><tr><th>Nome</th><th>Contato</th><th>Tipo</th><th>Perfil de permissão</th><th>Situação</th><th></th></tr></thead><tbody>
      ${rows.map((u) => `<tr><td><span class="user-cell">${avatarHtml(u, 'sm')}${esc(u.name)}</span></td><td>${esc(u.email)}${u.phone ? `<div class="small muted">${esc(u.phone)}</div>` : ''}</td><td>${u.role === 'admin' ? '<span class="badge accent">administrador</span>' : '<span class="badge">membro</span>'}</td><td>${u.role === 'admin' ? '<span class="muted small">acesso total</span>' : u.profile_name ? esc(u.profile_name) : '<span class="muted small">sem perfil (acesso total)</span>'}</td><td>${+u.active ? '<span class="badge ok">ativo</span>' : '<span class="badge">inativo</span>'}</td><td class="num">${u.role === 'admin' && u.id != state.me.id ? '<span class="muted small" title="Dados de outro administrador só pelo console do servidor">só pelo console</span>' : `<button class="btn sm" data-id="${u.id}">Editar</button>`}</td></tr>`).join('')}
    </tbody></table></div></div>
</div>`;
  const form = (u) => {
    const m = modal({
      title: u ? 'Editar usuário' : 'Novo usuário',
      body: `<form class="form" id="uf">${u ? `<div class="avatar-row" id="uav">${avatarHtml(u, 'lg')}<div class="inline"><button type="button" class="btn sm" data-av="up">${icon('upload')}Trocar foto</button><button type="button" class="btn sm ghost danger" data-av="del" ${u.avatar ? '' : 'hidden'}>Remover foto</button></div></div>` : ''}
        <label>Nome <input name="name" value="${esc(u?.name)}" required></label>
        <label>E-mail <input name="email" type="email" value="${esc(u?.email)}" required autocomplete="off">${u ? '<span class="help">É o login do usuário. A senha não muda.</span>' : ''}</label>
        <label>Telefone / WhatsApp <input name="phone" type="tel" inputmode="tel" maxlength="30" value="${esc(u?.phone)}" placeholder="(31) 99999-9999"><span class="help">Aparece junto com o e-mail no cabeçalho dos orçamentos em que ele é o responsável.</span></label>
        <label>Tipo de usuário <select name="role"><option value="membro">Membro (acessa o que o perfil permitir)</option><option value="admin" ${u?.role === 'admin' ? 'selected' : ''}>Administrador (acesso total)</option></select></label>
        <label class="prof-field">Perfil de permissão <select name="profile_id"><option value="">Sem perfil (acesso total)</option>${profiles.map((p) => `<option value="${p.id}" ${(u ? u.profile_id == p.id : p.name === 'Vendedor') ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select><span class="help">Crie e edite perfis em Configurações → Sistema → Permissões.</span></label>
        ${u && u.role === 'admin' ? `<p class="help" style="margin:0">Administradores só podem ser rebaixados, desativados ou excluídos pelo console do servidor.</p>`
          : u ? `<label class="check"><input type="checkbox" name="active" ${+u.active ? 'checked' : ''}> Ativo</label>` : ''}
        <label>${u ? 'Nova senha (deixe em branco para manter)' : 'Senha inicial'} <input name="password" type="text" minlength="8" ${u ? '' : 'required'} autocomplete="off" placeholder="mínimo 8 caracteres"></label></form>`,
      foot: `${u && u.role !== 'admin' && u.id != state.me.id ? `<button class="btn danger" id="udel" style="margin-right:auto">${icon('trash')}Excluir</button>` : ''}<button class="btn" data-close>Cancelar</button><button class="btn primary" id="save">Salvar</button>`,
    });
    const roleSel = $('#uf select[name=role]', m.el);
    if (u && u.role === 'admin') roleSel.disabled = true; // rebaixar administrador só pelo console
    const del = $('#udel', m.el);
    if (del) del.onclick = async () => {
      if (!(await confirmBox(`Excluir o usuário "${u.name}"? Se ele já tiver vendas, orçamentos, notas ou outros registros no sistema, ele só será desativado, para manter o histórico.`, { ok: 'Excluir', danger: true }))) return;
      busy(del, async () => {
        const r = await api('user_delete', { body: { id: u.id } });
        m.close(); toast(r.result === 'deleted' ? 'Usuário excluído' : 'O usuário tem registros no sistema: foi desativado'); route();
      });
    };
    // Foto de perfil do usuário (grava na hora)
    if ($('#uav', m.el)) $('#uav', m.el).onclick = async (e) => {
      const b = e.target.closest('[data-av]'); if (!b) return;
      try {
        const data = b.dataset.av === 'up' ? await pickAvatar() : ''; if (data === null) return;
        const r = await api('user_avatar', { body: { id: u.id, avatar: data } });
        u.avatar = r.avatar; $('.avatar', m.el).outerHTML = avatarHtml(u, 'lg'); $('[data-av=del]', m.el).hidden = !u.avatar;
        if (u.id == state.me.id) { state.me.avatar = u.avatar; renderShell(); }
        toast(u.avatar ? 'Foto atualizada' : 'Foto removida'); route();
      } catch (err) { fail(err); }
    };
    const syncRole = () => { $('.prof-field', m.el).hidden = roleSel.value === 'admin'; };
    roleSel.onchange = syncRole; syncRole();
    $('#save', m.el).onclick = (e) => busy(e.target, async () => {
      const d = formData($('#uf', m.el)); if (u) d.id = u.id;
      d.profile_id = d.profile_id || null;
      await api('user_save', { body: d }); m.close(); toast('Usuário salvo');
      if (u && u.id == state.me.id && d.email) state.me.email = d.email.trim().toLowerCase();
      route();
    });
  };
  $('#new', v).onclick = () => form(null);
  v.addEventListener('click', (e) => { const b = e.target.closest('button[data-id]'); if (b) form(rows.find((u) => u.id == b.dataset.id)); });
}

// ============ Minha conta → Aparência (tema e cor de destaque; cada usuário escolhe os seus) ============
/** Configurações → Sistema → Backup: download do banco (.sql) e backup automático no Google Drive (por Google Apps Script). */
async function viewBackup(v) {
  let g = await api('gdrive');
  let editing = false;
  const size = (b) => b >= 1048576 ? qty(b / 1048576, 1) + ' MB' : qty(Math.max(1, b / 1024), 0) + ' KB';
  const ago = (s) => s ? (Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 60000 : null;
  const draw = () => {
    const cronMin = ago(g.cron_at);
    const hours = Array.from({ length: 24 }, (_, h) => `<option value="${h}" ${h === g.hour ? 'selected' : ''}>${String(h).padStart(2, '0')}:00</option>`).join('');
    const days = g.weekdays.map((d, i) => `<option value="${i}" ${i === g.weekday ? 'selected' : ''}>${esc(d)}</option>`).join('');
    const setup = !g.connected || editing;
    v.innerHTML = `<div class="page-head"><div><h1>Backup</h1><p class="muted">Cópia de segurança de todos os dados do sistema.</p></div></div>
    <div class="card" style="max-width:820px"><h2>Backup do banco de dados</h2><p class="muted">Baixe uma cópia de todos os dados. Guarde em local seguro (Google Drive, por exemplo). A hospedagem também costuma fazer backup do MySQL pelo cPanel.</p>
      <a class="btn" href="api.php?r=backup">${icon('download')}Baixar backup (.sql)</a></div>

    <div class="card" style="max-width:820px"><h2>Backup automático no Google Drive</h2>
      <p class="muted">O sistema envia uma cópia do banco (.sql.gz) para uma pasta do seu Google Drive e manda para a lixeira os backups mais antigos. Quem grava na pasta é um pequeno script na sua conta Google, que só aceita envios deste sistema.</p>
      ${g.connected && !editing ? `
        <div class="alert ok" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">${icon('check').replace('<svg', '<svg style="width:18px;height:18px;flex:none"')}<span>Enviando para a pasta <a href="${esc(g.folder_url)}" target="_blank" rel="noopener"><strong>${esc(g.folder_name)}</strong></a>${g.account ? ' de ' + esc(g.account) : ''}</span>
          <span style="margin-left:auto;display:flex;gap:6px"><button type="button" class="btn" id="gedit">Alterar</button><button type="button" class="btn" id="gdisc">Desligar</button></span></div>
        <form class="form" id="gsch">
          <div class="row">
            <label>Frequência <select name="freq"><option value="off" ${g.freq === 'off' ? 'selected' : ''}>Desligado</option><option value="daily" ${g.freq === 'daily' ? 'selected' : ''}>Todo dia</option><option value="weekly" ${g.freq === 'weekly' ? 'selected' : ''}>Toda semana</option></select></label>
            <label id="gwd" ${g.freq === 'weekly' ? '' : 'hidden'}>Dia da semana <select name="weekday">${days}</select></label>
            <label>Horário <select name="hour">${hours}</select></label>
            <label>Guardar os últimos <input name="keep" type="number" min="0" max="1000" value="${g.keep}"><span class="help">0 = guardar todos</span></label>
          </div>
          <div class="actions"><button class="btn primary">Salvar</button><button type="button" class="btn" id="gnow">${icon('upload')}Fazer backup agora</button></div>
        </form>
        <div style="margin-top:14px">
          ${g.last_error ? `<div class="alert err"><strong>Último envio falhou</strong>${g.last_try ? ' (' + dt(g.last_try) + ')' : ''}: ${esc(g.last_error)}</div>` : ''}
          <p class="help" style="margin:0">${g.last_ok ? `Último backup: <strong>${dt(g.last_ok)}</strong> · ${esc(g.last_file)}${g.last_size ? ' · ' + size(g.last_size) : ''}` : 'Nenhum backup enviado ainda.'}
          ${g.freq !== 'off' && g.next_at ? `<br>Próximo: <strong>${dt(g.next_at)}</strong> (o agendador confere a cada 15 minutos, mais ou menos).` : ''}</p>
          ${g.freq !== 'off' && (cronMin === null || cronMin > 30) ? `<div class="alert warn" style="margin-top:10px">${cronMin === null ? 'O agendador ainda não rodou.' : 'O agendador não roda desde ' + dt(g.cron_at) + '.'} Ele é a mesma tarefa de <strong>publicação automática</strong> (Cron Jobs do cPanel, a cada 15 minutos). Confira se ela está cadastrada; sem ela o backup automático não acontece (o botão "Fazer backup agora" funciona mesmo assim).</div>` : ''}
        </div>` : ''}
      ${setup ? `
        <ol class="gsteps">
          <li><strong>Pasta:</strong> no Google Drive, crie ou abra a pasta onde os backups vão ficar e copie o endereço da barra do navegador.</li>
          <li><strong>Script:</strong> abra <a href="https://script.google.com/home/projects/create" target="_blank" rel="noopener">script.google.com → Novo projeto</a>, apague o que estiver lá, cole o código abaixo e clique em <strong>Salvar</strong> (ícone de disquete).
            <textarea readonly id="gscript" class="mono" rows="6" style="width:100%;margin-top:6px;font-size:.78rem">${esc(g.script)}</textarea>
            <button type="button" class="btn" id="gcopy" style="margin-top:4px">${icon('copy')}Copiar código</button></li>
          <li><strong>Autorizar:</strong> na barra de cima do editor, escolha a função <strong>autorizar</strong> e clique em <strong>Executar</strong>. Escolha sua conta, marque <strong>todas as caixas</strong> de permissão (principalmente a do Google Drive) e clique em <strong>Continuar</strong>. Se aparecer "O Google não verificou este app", clique em <strong>Avançado → Acessar</strong>; o script é seu.</li>
          <li><strong>Publicar:</strong> clique em <strong>Implantar → Nova implantação</strong>, no ícone de engrenagem escolha <strong>App da Web</strong>, deixe "Executar como: <strong>Eu</strong>" e "Quem pode acessar: <strong>Qualquer pessoa</strong>" e clique em <strong>Implantar</strong>.</li>
          <li><strong>Copie o "URL do app da Web"</strong> (termina em <code>/exec</code>) e cole abaixo, junto com o link da pasta.</li>
        </ol>
        <form class="form" id="gconn">
          <label>Link da pasta do Google Drive <input name="folder" value="${esc(g.folder_url)}" placeholder="https://drive.google.com/drive/folders/…" autocomplete="off"></label>
          <label>URL do app da Web (script) <input name="url" value="${esc(g.url)}" placeholder="https://script.google.com/macros/s/…/exec" autocomplete="off"></label>
          <div class="actions"><button class="btn primary">Testar e salvar</button>${editing ? '<button type="button" class="btn" id="gcancel">Cancelar</button>' : ''}</div>
        </form>` : ''}
    </div>`;
    bind();
  };
  const bind = () => {
    const on = (id, fn) => { const el = $('#' + id, v); if (el) el.onclick = fn; };
    on('gcopy', async () => { const t = $('#gscript', v); try { await navigator.clipboard.writeText(t.value); } catch (_) { t.select(); document.execCommand('copy'); } toast('Código copiado'); });
    on('gedit', () => { editing = true; draw(); });
    on('gcancel', () => { editing = false; draw(); });
    const c = $('#gconn', v);
    if (c) c.onsubmit = (e) => { e.preventDefault(); busy($('button', c), async () => { g = await api('gdrive_connect', { body: formData(c) }); editing = false; toast('Tudo certo: pasta ' + g.folder_name); draw(); }); };
    on('gdisc', (e) => { if (!confirm('Desligar o backup no Google Drive? Os backups que já estão na pasta continuam lá.')) return; busy(e.currentTarget, async () => { await api('gdrive_disconnect', { body: {} }); g = await api('gdrive'); toast('Backup no Google Drive desligado'); draw(); }); });
    on('gnow', (e) => busy(e.currentTarget, async () => {
      try { const r = await api('gdrive_run', { body: {} }); g = r.status; toast('Backup enviado: ' + r.file); }
      catch (err) { g = await api('gdrive'); draw(); throw err; }
      draw();
    }));
    const f = $('#gsch', v);
    if (f) {
      f.freq.onchange = () => { $('#gwd', v).hidden = f.freq.value !== 'weekly'; };
      f.onsubmit = (e) => { e.preventDefault(); busy($('button', f), async () => { g = await api('gdrive_config', { body: formData(f) }); toast('Agenda salva'); draw(); }); };
    }
  };
  draw();
}

async function viewAppearance(v) {
  v.innerHTML = `<div class="page-head"><div><h1>Aparência</h1><p class="muted">Tema e cor de destaque. São só seus: cada usuário escolhe os próprios.</p></div></div>
    <div class="card" style="max-width:560px"><h2>Tema</h2><p class="muted" style="margin-top:-4px">Vale para este aparelho e navegador.</p>
      <div class="theme-pick" id="tp">${Object.entries(THEMES).map(([k, l]) => `<button type="button" data-t="${k}" class="${getTheme() === k ? 'on' : ''}"><span class="sw ${k}"></span>${l}</button>`).join('')}</div>
      <p class="help" style="margin-bottom:0">Automático segue o tema do celular ou do computador. Também dá para alternar pelo botão de tema no rodapé do menu: cada clique passa para o próximo (claro, escuro, automático), e o automático aparece com um "A".</p></div>
    <div class="card" style="max-width:560px"><h2>Cor de destaque</h2><p class="muted" style="margin-top:-4px">A cor dos botões, do menu e dos links. Vale em qualquer aparelho em que você entrar.</p>
      <div class="form">
      <div class="swatches" id="sw">${ACCENT_PRESETS.map(([c, l]) => `<button type="button" class="swatch ${accentNow() === c ? 'on' : ''}" data-c="${c}" title="${esc(l)}" aria-label="${esc(l)}" style="background:${c}"></button>`).join('')}</div>
      <div class="row"><label>Outra cor <input type="color" id="pick" value="${accentNow()}" style="height:42px;padding:4px"></label>
        <label>Código (#RRGGBB) <input id="hex" value="${accentNow()}" maxlength="7" autocomplete="off"></label></div>
      <div><button type="button" class="btn" id="reset">Voltar ao padrão</button></div>
      <p class="help" style="margin:0">A cor é aplicada e salva na hora. Os PDFs e as páginas enviadas a clientes e fornecedores continuam na cor padrão.</p>
    </div></div>`;
  $('#tp', v).onclick = (e) => { const b = e.target.closest('button[data-t]'); if (!b) return; setTheme(b.dataset.t); $$('#tp button', v).forEach((x) => x.classList.toggle('on', x === b)); toast(`Tema: ${THEMES[b.dataset.t]}`); };
  // Cor: aplica na hora e salva na conta (espera um pouco enquanto o seletor é arrastado)
  const saveAccent = debounce(async (c) => {
    try { const r = await api('account_accent', { body: { accent_color: c } }); state.me.accent_color = r.accent_color; toast('Cor de destaque salva'); } catch (err) { fail(err); }
  }, 600);
  const setAccent = (c, from) => {
    c = c.toLowerCase();
    if (from !== 'pick') $('#pick', v).value = c;
    if (from !== 'hex') $('#hex', v).value = c;
    $$('#sw .swatch', v).forEach((b) => b.classList.toggle('on', b.dataset.c === c));
    state.me.accent_color = c; applyAccent(c); saveAccent(c);
  };
  $('#sw', v).onclick = (e) => { const b = e.target.closest('[data-c]'); if (b) setAccent(b.dataset.c); };
  $('#pick', v).oninput = (e) => setAccent(e.target.value, 'pick');
  $('#hex', v).oninput = (e) => { let x = e.target.value.trim(); if (x && !x.startsWith('#')) x = '#' + x; if (/^#[0-9a-f]{6}$/i.test(x)) setAccent(x, 'hex'); };
  $('#reset', v).onclick = () => setAccent(ACCENT_DEFAULT);
}

async function viewAccount(v) {
  v.innerHTML = `<div class="page-head"><div><h1>Minha conta</h1><p class="muted">${esc(state.me.name)} · ${esc(state.me.email)}</p></div></div>
    <div class="card" style="max-width:560px"><h2>Foto de perfil</h2><div class="avatar-row" id="myav">${avatarHtml(state.me, 'lg')}
      <div class="form" style="gap:8px"><div class="inline"><button type="button" class="btn" data-av="up">${icon('upload')}Enviar foto</button><button type="button" class="btn ghost danger" data-av="del" ${state.me.avatar ? '' : 'hidden'}>Remover</button></div>
      <span class="help">Aparece no menu e na lista de usuários. A imagem é recortada em quadrado e reduzida no seu aparelho.</span></div></div></div>
    <div class="card" style="max-width:560px"><h2>Meus dados</h2><form class="form" id="md">
      <label>Nome <input name="name" required maxlength="120" value="${esc(state.me.name)}"></label>
      <label>Telefone / WhatsApp <input name="phone" type="tel" inputmode="tel" maxlength="30" value="${esc(state.me.phone)}" placeholder="(31) 99999-9999"></label>
      <p class="help" style="margin:-4px 0 0">Seu nome, telefone e e-mail (${esc(state.me.email)}) aparecem no cabeçalho dos orçamentos em que você é o responsável.</p>
      <button class="btn primary">Salvar</button></form></div>
    <div class="card" style="max-width:560px"><h2>Trocar senha</h2><form class="form" id="pw">
      <label>Senha atual <input type="password" name="current" required autocomplete="current-password"></label>
      <label>Nova senha <input type="password" name="new" minlength="8" required autocomplete="new-password"></label>
      <label>Repita a nova senha <input type="password" name="new2" minlength="8" required autocomplete="new-password"></label>
      <button class="btn primary">Salvar nova senha</button></form></div>
    <section id="myappearance" style="margin-top:28px"></section>
    <p class="muted small">Versão ${esc(state.version || '')}</p>`;
  // Aparência (tema e cor de destaque): só muda para o próprio usuário, por isso fica aqui e não em Configurações
  const ap = $('#myappearance', v);
  await viewAppearance(ap);
  const h = $('.page-head h1', ap); if (h) h.outerHTML = `<h2 style="margin:0">${esc(h.textContent)}</h2>`;
  $('#myav', v).onclick = async (e) => {
    const b = e.target.closest('[data-av]'); if (!b) return;
    try {
      const data = b.dataset.av === 'up' ? await pickAvatar() : ''; if (data === null) return;
      const r = await api('account_avatar', { body: { avatar: data } });
      state.me.avatar = r.avatar; renderShell(); toast(r.avatar ? 'Foto atualizada' : 'Foto removida'); route();
    } catch (err) { fail(err); }
  };
  $('#md', v).onsubmit = (e) => {
    e.preventDefault();
    busy($('button', e.target), async () => {
      const d = formData(e.target);
      await api('account', { body: d }); await loadMe(); renderShell(); toast('Dados salvos'); route();
    });
  };
  $('#pw', v).onsubmit = (e) => {
    e.preventDefault();
    busy($('button', e.target), async () => {
      const d = formData(e.target);
      if (d.new !== d.new2) throw new Error('As senhas não conferem');
      await api('password', { body: d }); e.target.reset(); toast('Senha alterada');
    });
  };
}

// ============ Início ============
applyTheme();
window.addEventListener('hashchange', route);
(async () => {
  try { await loadMe(); } catch (e) { $('#app').innerHTML = `<div class="boot">${esc(e.message)}</div>`; return; }
  if (state.me) renderShell();
  route();
})();
