<?php
// /home/myzonaco/smartpark.myzona360.com/includes/header.php
// Cabecera HTML compartida por todas las páginas autenticadas.
if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

$_pageTitle = $_pageTitle ?? 'Panel';
$_user      = auth_user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($_pageTitle) ?> · SmartPark</title>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>?v=<?= APP_VERSION ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset('img/logo.svg') ?>">

<!-- ── v4c: PWA. Antes el manifest solo estaba en /rondas (modulo que no se usa)
     y el Service Worker NUNCA se registraba, asi que el precache offline no
     existia y Chrome no ofrecia instalar la app. ── -->
<link rel="manifest" href="<?= url('/manifest.json') ?>">
<meta name="theme-color" content="#1e6cff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SmartPark">
<link rel="apple-touch-icon" href="<?= url('/api/icon?size=192') ?>">
<script>
// Registro del Service Worker (offline + instalable)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= url('/sw.js') ?>')
      .then(function (reg) {
        console.log('SW registrado:', reg.scope);
        // si hay una version nueva, activarla sin esperar
        reg.addEventListener('updatefound', function () {
          var nw = reg.installing;
          if (!nw) return;
          nw.addEventListener('statechange', function () {
            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
              nw.postMessage({ type: 'skip-waiting' });
            }
          });
        });
      })
      .catch(function (e) { console.warn('SW no se pudo registrar:', e); });
  });
}

// Boton "Instalar app" (aparece solo si Chrome lo permite)
window.SP_INSTALL_PROMPT = null;

// ── Monitor de sesiones: heartbeat cada 55s ──
// Captura: página actual, IP (la detecta el servidor) y GPS opcional.
// Si el usuario rechaza la ubicación, funciona igual sin coordenadas.
(function () {
    var PING_URL = '<?= url('/api/monitor_ping') ?>';
    var _lat = null, _lng = null;

    function enviarPing() {
        var body = 'pagina=' + encodeURIComponent(window.location.pathname);
        if (_lat !== null) body += '&lat=' + _lat + '&lng=' + _lng;
        fetch(PING_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            keepalive: true   // sobrevive a cambios de página
        }).catch(function () {});
    }

    // Solicitar GPS una vez (el usuario puede denegar)
    if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                _lat = pos.coords.latitude.toFixed(7);
                _lng = pos.coords.longitude.toFixed(7);
                enviarPing(); // reenviar ahora con coords
            },
            function () { /* rechazado o sin señal — no pasa nada */ },
            { timeout: 8000, maximumAge: 120000 }
        );
    }

    enviarPing();                          // ping inicial
    setInterval(enviarPing, 55000);        // ping cada 55s
})();

window.addEventListener('beforeinstallprompt', function (e) {
  e.preventDefault();
  window.SP_INSTALL_PROMPT = e;
  var b = document.getElementById('sp-install-btn');
  if (b) b.style.display = 'inline-flex';
});
function spInstalar() {
  if (!window.SP_INSTALL_PROMPT) {
    alert('Para instalar: menú ⋮ de Chrome → "Instalar aplicación" / "Añadir a pantalla de inicio".');
    return;
  }
  window.SP_INSTALL_PROMPT.prompt();
  window.SP_INSTALL_PROMPT.userChoice.then(function (r) {
    if (r.outcome === 'accepted') {
      var b = document.getElementById('sp-install-btn');
      if (b) b.style.display = 'none';
    }
    window.SP_INSTALL_PROMPT = null;
  });
}
window.addEventListener('appinstalled', function () {
  var b = document.getElementById('sp-install-btn');
  if (b) b.style.display = 'none';
});
</script>
</head>
<body class="app">
<header class="topbar">
    <button class="topbar__menu" id="btnMenu" aria-label="Abrir menú">
        <span></span><span></span><span></span>
    </button>
    <a class="topbar__brand" href="<?= url('/dashboard') ?>">
        <img src="<?= asset('img/logo.svg') ?>" alt="SmartPark" width="28" height="28">
        <span>SmartPark</span>
    </a>
    <div class="topbar__user">
        <button type="button" id="sp-install-btn" onclick="spInstalar()"
                style="display:none;align-items:center;gap:5px;margin-right:10px;padding:6px 12px;
                       border:0;border-radius:8px;background:#1e6cff;color:#fff;font-size:13px;
                       font-weight:600;cursor:pointer">
            ⬇ Instalar app
        </button>
        <span class="topbar__greeting">Hola, <strong><?= e($_user['nombre_completo'] ?? 'Usuario') ?></strong></span>
        <button type="button" id="btnForzarUpdate" class="topbar__logout"
                style="background:none;border:none;cursor:pointer;font:inherit;color:inherit;margin-right:10px"
                title="Forzar actualización de la app">🔄 Actualizar</button>
        <a class="topbar__logout" href="<?= url('/logout') ?>">Salir</a>
    </div>
      <script src="/assets/js/sp_image_compress.js" defer></script>  
      <script src="/assets/js/sp_duplicate_check.js" defer></script>

      <!-- ── Botón "Forzar actualización" ──
           Limpia caché del SW y recarga desde el servidor.
           DEBE estar aquí (post-body) para que btnForzarUpdate ya exista en el DOM. -->
      <script>
      (function () {
          function spForzarUpdate() {
              var btn = document.getElementById('btnForzarUpdate');
              if (!btn) return;

              btn.addEventListener('click', async function () {
                  if (!navigator.onLine) {
                      alert('📴 Estás fuera de línea.\n\nConéctate a internet para poder actualizar la app.');
                      return;
                  }
                  if (!confirm('¿Forzar actualización?\n\nSe descargará la versión más reciente del servidor.\nPuede tardar unos segundos.')) {
                      return;
                  }

                  // Feedback visual inmediato
                  btn.disabled = true;
                  btn.textContent = '⏳ Actualizando...';
                  btn.style.opacity = '0.7';

                  try {
                      // 1) Borrar TODOS los cachés del navegador
                      if ('caches' in window) {
                          var nombres = await caches.keys();
                          await Promise.all(nombres.map(function (n) { return caches.delete(n); }));
                      }
                      // 2) Desregistrar el Service Worker para que el navegador
                      //    descargue TODO desde el servidor en la próxima carga
                      if ('serviceWorker' in navigator) {
                          var regs = await navigator.serviceWorker.getRegistrations();
                          await Promise.all(regs.map(function (r) { return r.unregister(); }));
                      }
                  } catch (e) {
                      console.warn('Error limpiando caché:', e);
                  }

                  // 3) Recargar forzando red (equivale a Ctrl+Shift+R pero desde código)
                  window.location.reload(true);
              });
          }

          // Ejecutar cuando el DOM esté listo (por si acaso el script carga antes)
          if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', spForzarUpdate);
          } else {
              spForzarUpdate();
          }
      })();
      </script>
</header>

<div class="layout">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    <main class="main">
        <?php foreach (flash_get() as $f): ?>
            <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>