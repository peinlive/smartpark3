<?php
// /modules/lectura_prueba/diag_pwa.php
// v4c: Diagnostico de la PWA. Dice EXACTAMENTE por que no aparece "Instalar app".
if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico PWA</title>
<link rel="manifest" href="<?= url('/manifest.json') ?>">
<style>
body{font:15px system-ui;background:#0f1115;color:#e6e8ec;padding:18px;max-width:760px;margin:0 auto}
h1{font-size:19px;margin-bottom:4px}
.chk{display:flex;gap:10px;align-items:flex-start;padding:11px;border-bottom:1px solid #262b35}
.chk b{flex:1}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#ffd44d}
small{color:#7d8798;display:block;margin-top:3px;font-size:12.5px}
pre{background:#161a21;padding:11px;border-radius:8px;font-size:12px;overflow:auto;margin-top:14px}
button{background:#1e6cff;color:#fff;border:0;padding:12px 18px;border-radius:9px;
       font-size:15px;font-weight:600;cursor:pointer;margin-top:14px}
</style></head><body>
<h1>Diagnóstico PWA · SmartPark</h1>
<p style="color:#7d8798;font-size:13px">Si "Instalar app" no aparece, acá está el motivo.</p>
<div id="out"></div>
<button id="btnInst" style="display:none">⬇ Instalar app</button>
<pre id="raw">…</pre>

<script>
var out = document.getElementById('out');
function fila(ok, titulo, detalle) {
  var cls = ok === true ? 'ok' : (ok === null ? 'warn' : 'err');
  var ic  = ok === true ? '✅' : (ok === null ? '⚠️' : '❌');
  out.innerHTML += '<div class="chk"><span class="'+cls+'">'+ic+'</span>'
                 + '<b>'+titulo+'<small>'+(detalle||'')+'</small></b></div>';
}

// 1. HTTPS
fila(location.protocol === 'https:', 'HTTPS',
     location.protocol === 'https:' ? 'OK' : 'Una PWA EXIGE https. Sin esto no se instala nunca.');

// 2. Service Worker soportado
fila('serviceWorker' in navigator, 'Service Worker soportado por el navegador');

// 3. SW registrado y activo
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.getRegistrations().then(function (rs) {
    if (!rs.length) {
      fila(false, 'Service Worker REGISTRADO',
           'No hay ninguno. Revisa que includes/header.php tenga el bloque de registro (v4c).');
    } else {
      rs.forEach(function (r) {
        var st = r.active ? 'activo' : (r.installing ? 'instalando' : 'esperando');
        fila(!!r.active, 'Service Worker registrado', 'scope: ' + r.scope + ' · estado: ' + st);
      });
    }
  });
}

// 4. Manifest accesible y bien formado
fetch('<?= url('/manifest.json') ?>')
  .then(function (r) {
    var ct = r.headers.get('content-type') || '';
    fila(r.ok, 'manifest.json accesible', 'HTTP ' + r.status + ' · ' + ct);
    return r.json();
  })
  .then(function (m) {
    fila(!!m.name, 'manifest: name', m.name || 'FALTA');
    fila(!!m.start_url, 'manifest: start_url', m.start_url || 'FALTA');
    fila(m.display === 'standalone' || m.display === 'fullscreen',
         'manifest: display', m.display || 'FALTA (debe ser standalone)');
    var i192 = (m.icons||[]).some(function(i){ return (i.sizes||'').indexOf('192') === 0; });
    var i512 = (m.icons||[]).some(function(i){ return (i.sizes||'').indexOf('512') === 0; });
    fila(i192, 'manifest: ícono 192x192', i192 ? 'OK' : 'FALTA. Chrome lo exige.');
    fila(i512, 'manifest: ícono 512x512', i512 ? 'OK' : 'FALTA. Chrome lo exige.');
    document.getElementById('raw').textContent = JSON.stringify(m, null, 2);
    // 5. iconos que realmente cargan
    (m.icons||[]).slice(0,2).forEach(function (ic) {
      fetch(ic.src).then(function (r) {
        fila(r.ok, 'ícono ' + ic.sizes + ' carga',
             'HTTP ' + r.status + ' · ' + (r.headers.get('content-type')||''));
      }).catch(function(){ fila(false, 'ícono ' + ic.sizes, 'no carga'); });
    });
  })
  .catch(function (e) {
    fila(false, 'manifest.json', 'No se pudo leer: ' + e.message);
  });

// 6. ¿ya está instalada?
var standalone = window.matchMedia('(display-mode: standalone)').matches
              || window.navigator.standalone === true;
if (standalone) fila(null, 'La app YA está instalada', 'Estás viéndola en modo app.');

// 7. evento de instalación
var got = false;
window.addEventListener('beforeinstallprompt', function (e) {
  e.preventDefault(); got = true;
  window.__p = e;
  fila(true, 'Chrome ofrece instalar', 'Pulsa el botón de abajo.');
  var b = document.getElementById('btnInst');
  b.style.display = 'block';
  b.onclick = function () { e.prompt(); };
});
setTimeout(function () {
  if (!got && !standalone) {
    fila(null, 'Chrome no ofreció instalar (todavía)',
         'Causas típicas: (a) ya está instalada, (b) el SW aún no terminó de activarse '
       + '— recarga en 10 s, (c) Chrome exige haber interactuado con el sitio antes, '
       + '(d) estás en escritorio con un perfil que ya la tiene. '
       + 'En Android siempre podés: menú ⋮ → "Instalar aplicación".');
  }
}, 4000);

// 8. caches
if ('caches' in window) {
  caches.keys().then(function (k) {
    fila(k.length > 0, 'Caches del Service Worker',
         k.length ? k.join(', ') : 'vacío — abre /revistas con red para precachear');
  });
}
</script>
</body></html>
