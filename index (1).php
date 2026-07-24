<?php
// /home/myzonaco/smartpark.myzona360.com/modules/offline/index.php
// v5 — SHELL OFFLINE. Una sola pagina con TODOS los modulos que usa la ronda.
//
// Se instala una vez con WiFi (precache) y despues funciona SIEMPRE sin señal.
// Navegacion entre modulos SIN recargar y SIN tocar el servidor.
// Un unico boton "Sincronizar": baja la BD y sube lo pendiente.
//
// NO modifica ningun modulo existente. Convive con la app normal.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
$_pageTitle = 'Modo offline';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>SmartPark · Offline</title>
<link rel="manifest" href="<?= url('/manifest.json') ?>">
<meta name="theme-color" content="#1e6cff">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;color:#111827;
     padding-bottom:74px}
.top{background:#111827;color:#fff;padding:11px 14px;position:sticky;top:0;z-index:50;
     display:flex;align-items:center;gap:10px}
.top h1{font-size:16px;flex:1;font-weight:600}
.net{font-size:11px;padding:3px 9px;border-radius:10px;font-weight:600;white-space:nowrap}
.net.on{background:#065f46;color:#a7f3d0}
.net.off{background:#7c2d12;color:#fed7aa}
.sync{background:#1e6cff;color:#fff;border:0;padding:8px 13px;border-radius:8px;
      font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}
.sync:disabled{opacity:.5}
.wrap{max-width:900px;margin:0 auto;padding:12px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:14px;margin-bottom:12px}
h2{font-size:16px;margin-bottom:10px}
input[type=text],select,textarea{width:100%;padding:11px;border:1px solid #d1d5db;
     border-radius:8px;font-size:16px;margin:6px 0;background:#fff;color:#111827}
input[type=text]{text-transform:uppercase}
input.nocaps{text-transform:none;font-family:inherit;letter-spacing:normal;font-size:16px}

/* PLACAS: fuente monoespaciada grande y con separacion.
   Con la fuente normal, "0/O", "1/I" y "5/S" se confunden — justo los
   caracteres que el OCR mas equivoca. Asi se leen sin dudar. */
input.placa{font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
     font-size:22px;font-weight:700;letter-spacing:3px;text-align:center;
     padding:14px;border:2px solid #1e6cff;color:#111827}
input.placa::placeholder{font-size:15px;letter-spacing:1px;font-weight:400;color:#9ca3af}

/* la placa tambien grande cuando se MUESTRA en resultados */
.plc{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;
     letter-spacing:1.5px}
.btn{padding:11px 15px;border:0;border-radius:8px;font-size:14px;font-weight:600;
     cursor:pointer;background:#e5e7eb;color:#111827}
.btn.p{background:#1e6cff;color:#fff}
.btn.g{background:#059669;color:#fff}
.btn.w{background:#92400e;color:#fff}
.btn.blk{width:100%}
.btn.sm{padding:7px 11px;font-size:13px}
.row{display:flex;gap:8px;flex-wrap:wrap}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
.pill{font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;display:inline-block}
.pill.mor{background:#fee2e2;color:#991b1b}
.pill.blo{background:#fef3c7;color:#92400e}
.pill.ok{background:#dcfce7;color:#166534}
.pill.vis{background:#dbeafe;color:#1e40af}
.pill.arc{background:#f3f4f6;color:#6b7280}
.pill.pend{background:#fef3c7;color:#92400e}
table{width:100%;border-collapse:collapse;font-size:13.5px;margin-top:6px}
th{text-align:left;color:#6b7280;font-size:11px;padding:5px;border-bottom:1px solid #e5e7eb}
td{padding:7px 5px;border-bottom:1px solid #f3f4f6}
tr.clic{cursor:pointer}
tr.clic:hover{background:#f9fafb}
.vacio{color:#9ca3af;text-align:center;padding:22px;font-size:14px}
.nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e7eb;
     display:flex;z-index:50;box-shadow:0 -2px 8px rgba(0,0,0,.06)}
.nav button{flex:1;border:0;background:0;padding:9px 2px;font-size:10px;color:#6b7280;
     cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px}
.nav button.act{color:#1e6cff;font-weight:700}
.nav b{font-size:19px;line-height:1}
.sec{display:none}
.sec.act{display:block}
.cola{position:fixed;bottom:70px;right:10px;background:#fef3c7;color:#92400e;
      padding:7px 13px;border-radius:12px;font-size:12px;font-weight:600;z-index:49;
      cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.15);display:none}
.muted{color:#6b7280;font-size:12.5px}
.tot{font-size:11.5px;color:#6b7280;margin-top:7px;line-height:1.5}
.ficha th{width:110px;color:#6b7280;font-weight:600;vertical-align:top}
.back{background:0;border:0;color:#1e6cff;font-size:14px;cursor:pointer;padding:4px 0;margin-bottom:6px}
</style>
</head>
<body>

<div class="top">
  <h1>SmartPark <span style="opacity:.5;font-weight:400">· offline</span></h1>
  <span id="net" class="net">…</span>
  <a id="btnOnline" href="<?= url('/dashboard') ?>" class="sync"
     style="background:#334155;text-decoration:none;display:inline-flex;align-items:center">
     🌐 App en línea
  </a>
  <button id="btnSync" class="sync">🔄 Sincronizar</button>
</div>

<div class="wrap">

  <!-- ══════════ INICIO ══════════ -->
  <div class="sec act" id="s-inicio">
    <div class="card">
      <h2>Estado de los datos</h2>
      <div id="estado-tot" class="tot">Cargando…</div>
      <div class="row" style="margin-top:11px">
        <button class="btn p" onclick="sincronizar()">🔄 Sincronizar ahora</button>
        <a href="<?= url('/dashboard') ?>" class="btn"
           style="background:#334155;color:#fff;text-decoration:none;display:inline-flex;
                  align-items:center">🌐 Ir a la app en línea</a>
        <button class="btn" onclick="repararDatos()" style="background:#fef3c7;color:#92400e">
          🛠️ Reparar datos
        </button>
      </div>
      <p class="muted" id="aviso-online" style="margin-top:7px;display:none">
        ⚠️ La app en línea <b>necesita señal</b>. Sin conexión no va a cargar.
      </p>
      <p class="muted" style="margin-top:9px">
        Pulsa Sincronizar cuando tengas señal. Baja la base de datos actualizada
        y sube todo lo que registraste sin conexión.
      </p>
    </div>
    <div class="card">
      <h2>¿Cómo funciona?</h2>
      <p class="muted" style="line-height:1.7">
        Esta pantalla no necesita internet. Todos los datos están guardados en el
        celular.<br>
        • <b>Consultar</b> · placa, apto, residente, celda<br>
        • <b>Revistas</b> · ejecutar y guardar sin señal<br>
        • <b>Vehículos y Residentes</b> · buscar y ver fichas<br>
        • <b>Parqueadero</b> · celdas y asignaciones<br>
        • <b>Novedades</b> · registrar y ver historial<br><br>
        Lo que registres se guarda acá y sube cuando pulses <b>Sincronizar</b>.
      </p>
    </div>
  </div>

  <!-- ══════════ CONSULTA ══════════ -->
  <div class="sec" id="s-consulta">
    <div class="card">
      <h2>📷 Leer placa</h2>
      <div class="row">
        <button class="btn p" onclick="$('inp-cam').click()">📷 Tomar foto</button>
        <button class="btn" onclick="$('inp-file').click()">📁 Adjuntar</button>
        <label style="display:flex;align-items:center;gap:6px;font-size:14px">
          <input type="checkbox" id="es-moto" style="width:auto;margin:0"> moto
        </label>
      </div>
      <input type="file" id="inp-cam" accept="image/*" capture="environment" hidden>
      <input type="file" id="inp-file" accept="image/*" hidden>
      <div id="ocr-estado" class="muted" style="margin-top:8px">⏳ Cargando motor OCR…</div>
      <div id="ocr-res" style="margin-top:10px"></div>
    </div>

    <!-- v7.51: pestañas de búsqueda (offline) para no bajar tanto -->
    <div class="cqo-tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 10px">
      <button type="button" class="cqo-tab is-active" data-tab="placa"     onclick="cqoTab('placa')">🚗 Placa</button>
      <button type="button" class="cqo-tab" data-tab="apto"      onclick="cqoTab('apto')">🏠 Apto</button>
      <button type="button" class="cqo-tab" data-tab="res"       onclick="cqoTab('res')">👤 Residente</button>
      <button type="button" class="cqo-tab" data-tab="celda"     onclick="cqoTab('celda')">🅿️ Celda</button>
      <button type="button" class="cqo-tab" data-tab="cuarto"    onclick="cqoTab('cuarto')">📦 Cuarto</button>
    </div>

    <div class="cqo-panel is-active" data-panel="placa">
      <div class="card">
        <b>🚗 Placa</b>
        <input type="text" id="q-placa" class="placa" placeholder="ABC123" maxlength="15">
        <button class="btn p blk" onclick="bPlaca()">Buscar placa</button>
      </div>
    </div>
    <div class="cqo-panel" data-panel="apto">
      <div class="card">
        <b>🏠 Apartamento</b>
        <input type="text" id="q-apto" placeholder="Ej: 1502" maxlength="20">
        <button class="btn blk" style="background:#0e7490;color:#fff" onclick="bApto()">Buscar apto</button>
      </div>
    </div>
    <div class="cqo-panel" data-panel="res">
      <div class="card">
        <b>👤 Residente</b>
        <input type="text" id="q-res" class="nocaps" placeholder="Nombre o celular" maxlength="60">
        <button class="btn blk" style="background:#7c3aed;color:#fff" onclick="bRes()">Buscar residente</button>
      </div>
    </div>
    <div class="cqo-panel" data-panel="celda">
      <div class="card">
        <b>🅿️ Celda</b>
        <input type="text" id="q-celda" placeholder="Ej: C99102" maxlength="30">
        <button class="btn g blk" onclick="bCelda()">Buscar celda</button>
      </div>
    </div>
    <div class="cqo-panel" data-panel="cuarto">
      <div class="card">
        <b>📦 Cuarto útil</b>
        <input type="text" id="q-cuarto" placeholder="Ej: CU99034" maxlength="30">
        <button class="btn blk" style="background:#166534;color:#fff" onclick="bCuarto()">Buscar cuarto</button>
      </div>
    </div>
    <style>
      .cqo-tab{padding:8px 12px;border:1px solid #d1d5db;background:#fff;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;color:#374151}
      .cqo-tab.is-active{background:#1e6cff;color:#fff;border-color:#1e6cff}
      .cqo-panel{display:none}
      .cqo-panel.is-active{display:block}
    </style>
    <script>
      window.cqoTab = function(name){
        document.querySelectorAll('.cqo-panel').forEach(function(p){
          p.classList.toggle('is-active', p.getAttribute('data-panel')===name);
        });
        document.querySelectorAll('.cqo-tab').forEach(function(t){
          t.classList.toggle('is-active', t.getAttribute('data-tab')===name);
        });
        var r = document.getElementById('r-consulta'); if (r) r.innerHTML='';
      };
    </script>
    <div id="r-consulta"></div>
  </div>

  <!-- ══════════ REVISTAS ══════════ -->
  <div class="sec" id="s-revistas">
    <div class="row" id="rev-tabs" style="margin-bottom:10px">
      <button class="btn p sm" data-rt="curso">▶ En curso</button>
      <button class="btn sm"   data-rt="hechas">✅ Terminadas</button>
    </div>
    <div id="r-revistas"></div>
  </div>

  <!-- ══════════ VEHICULOS ══════════ -->
  <div class="sec" id="s-vehiculos">
    <div class="card">
      <h2>🚗 Vehículos</h2>
      <input type="text" id="q-veh" class="placa" placeholder="ABC123" maxlength="15">
      <button class="btn p blk" onclick="bVeh()">Buscar</button>
    </div>
    <div id="r-vehiculos"></div>
  </div>

  <!-- ══════════ RESIDENTES ══════════ -->
  <div class="sec" id="s-residentes">
    <div class="grid">
      <div class="card">
        <b>👤 Por nombre o celular</b>
        <input type="text" id="q-resi" class="nocaps" placeholder="Juan / 3001234567">
        <button class="btn p blk" onclick="bResi()">Buscar residente</button>
      </div>
      <div class="card">
        <b>🏠 Por apartamento</b>
        <input type="text" id="q-resi-apto" placeholder="Ej: 916" maxlength="20">
        <button class="btn blk" style="background:#0e7490;color:#fff"
                onclick="bResiApto()">Buscar por apto</button>
      </div>
    </div>
    <div id="r-residentes"></div>
  </div>

  <!-- ══════════ PARQUEADERO ══════════ -->
  <div class="sec" id="s-parqueadero">
    <div class="card">
      <h2>🅿️ Parqueadero</h2>
      <input type="text" id="q-park" placeholder="Celda o apto">
      <button class="btn g blk" onclick="bPark()">Buscar celda</button>
    </div>
    <div id="r-parqueadero"></div>
  </div>

  <!-- ══════════ NOVEDADES ══════════ -->
  <div class="sec" id="s-novedades">
    <div class="card">
      <h2>📝 Registrar novedad</h2>
      <input type="text" id="nov-placa" class="placa" placeholder="ABC123" maxlength="10">
      <!-- v6.6: los valores DEBEN coincidir con el enum de la BD.
           Antes mandaba 'mal_parqueado'/'baja'/'alta', que NO existen:
           registrar_novedad.php hace in_array() y caia al default en
           silencio. Por eso TODO el historial decia mal_parqueo/leve. -->
      <select id="nov-tipo">
        <option value="mal_parqueo">🚫 Mal parqueo</option>
        <option value="advertencia">⚠️ Advertencia</option>
        <option value="reincidencia">🔁 Reincidencia</option>
        <option value="queja">📢 Queja</option>
        <option value="otro" selected>📌 Otro</option>
      </select>
      <select id="nov-grav">
        <option value="ninguna" selected>⚪ Ninguna — informativa</option>
        <option value="leve">🟢 Leve</option>
        <option value="media">🟡 Media</option>
        <option value="grave">🔴 Grave</option>
      </select>
      <textarea id="nov-desc" rows="3" class="nocaps" placeholder="Descripción..."></textarea>

      <div style="margin:8px 0">
        <button class="btn sm" onclick="$('nov-cam').click()">📷 Agregar foto</button>
        <span class="muted" style="margin-left:6px">hasta 4</span>
        <input type="file" id="nov-cam" accept="image/*" capture="environment" hidden>
        <div id="nov-fotos" style="margin-top:6px"></div>
      </div>

      <button class="btn w blk" id="nov-btn" onclick="guardarNovedad()">💾 Guardar novedad</button>
      <p class="muted" style="margin-top:7px">
        Se guarda en el celular con las fotos. Sube al pulsar <b>Sincronizar</b>.
      </p>
    </div>
    <div class="card">
      <h2>📜 Historial de novedades</h2>
      <input type="text" id="q-nov" class="placa" placeholder="PLACA O APTO" maxlength="12">
      <div id="r-novedades"></div>
    </div>
  </div>

</div>

<div id="cola" class="cola" onclick="sincronizar()"></div>

<div class="nav">
  <button data-s="inicio"      class="act"><b>🏠</b>Inicio</button>
  <button data-s="consulta"><b>🔍</b>Consulta</button>
  <button data-s="revistas"><b>📋</b>Revistas</button>
  <button data-s="vehiculos"><b>🚗</b>Vehíc.</button>
  <button data-s="residentes"><b>👤</b>Resid.</button>
  <button data-s="parqueadero"><b>🅿️</b>Parq.</button>
  <button data-s="novedades"><b>📝</b>Nov.</button>
</div>

<script src="<?= url('/assets/ocr/vendor/ort/ort.min.js') ?>"></script>
<script src="<?= url('/assets/ocr/sp_ocr.js') ?>"></script>
<script src="<?= url('/assets/js/sp_snapshot.js') ?>?v=757"></script>
<script src="<?= url('/assets/js/sp_cola.js') ?>"></script>
<script src="<?= url('/assets/js/sp_lote.js') ?>?v=787"></script>
<script>
window.API_SYNC_LOTE = <?= json_encode(url('/api/sync_lote')) ?>;
window.API_CSRF      = <?= json_encode(url('/api/csrf')) ?>;   // v6.4: token fresco
window.API_SNAPSHOT = <?= json_encode(url('/consultas/api_snapshot')) ?>;
window.API_NOVEDAD  = <?= json_encode(url('/consultas/registrar_novedad')) ?>;
window.API_GUARDAR  = <?= json_encode(url('/revistas/api_guardar_paso')) ?>;
window.OCR_BASE     = <?= json_encode(url('/assets/ocr/')) ?>;
window.CSRF         = <?= json_encode(csrf_token()) ?>;
</script>
<script src="<?= url('/assets/js/sp_shell.js') ?>?v=787"></script>
<script>
document.getElementById('nov-cam').onchange = function (e) {
  if (e.target.files[0]) window.addFotoNovedad(e.target.files[0]);
  e.target.value = '';
};
</script>
</body>
</html>
