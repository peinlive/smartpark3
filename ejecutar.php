<?php
// /home/myzonaco/smartpark.myzona360.com/modules/rondas/ejecutar.php
// v3f: OFFLINE-FIRST. Guarda local primero, sube cuando hay conexión.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/rondas'); }

$st = $pdo->prepare("SELECT * FROM revistas WHERE id = :id AND conjunto_id = :c");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$revista = $st->fetch();
if (!$revista) { flash_set('error', 'Revista no encontrada.'); redirect('/rondas'); }
if ((int)$revista['usuario_id'] !== (int)$u['id'] && !auth_has_role('super_admin','admin')) {
    flash_set('error', 'No tienes permisos.'); redirect('/rondas');
}

// Cargar TODAS las celdas del nivel (las pendientes se determinan en JS combinando server + local)
$celdas = $pdo->prepare("
    SELECT c.id, c.numero_celda, c.es_privada, c.asignada_a_apto, c.orden,
           l.id AS lectura_id
      FROM parqueadero_celdas c
 LEFT JOIN lecturas_placas l ON l.revista_id = :r AND l.celda = c.numero_celda
     WHERE c.conjunto_id = :c AND c.nivel = :n
  ORDER BY c.orden, c.numero_celda");
$celdas->execute([':r' => $id, ':c' => $conjuntoId, ':n' => $revista['nivel']]);
$celdas = $celdas->fetchAll();

$celdasJson = json_encode(array_map(function($c) {
    return [
        'numero' => $c['numero_celda'],
        'privada' => (int)$c['es_privada'],
        'apto' => $c['asignada_a_apto'],
        'revisada_servidor' => !empty($c['lectura_id']),
    ];
}, $celdas));

$_pageTitle = 'Revista ' . $revista['nivel'];
include INCLUDES_PATH . '/header.php';
?>

<!-- CSRF para JS -->
<form id="csrfHolder" style="display:none"><?= csrf_field() ?></form>

<!-- PWA setup -->
<link rel="manifest" href="<?= url('/manifest.json') ?>">
<meta name="theme-color" content="#1e6cff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SmartPark">

<div class="sync-bar" id="syncBar">
    <span class="sync-dot" id="syncDot"></span>
    <span id="syncText">Cargando...</span>
    <button type="button" class="sync-btn" id="syncBtn" style="display:none">↑ Sincronizar</button>
</div>

<div class="wiz-shell">
    <div class="wiz-header">
        <div class="wiz-title">🌙 Revista <?= e($revista['nivel']) ?></div>
        <div class="wiz-progress">
            <div class="wiz-progress-bar"><div class="wiz-progress-fill" id="wizProgressFill" style="width:0%"></div></div>
            <span id="wizProgressText">0 / <?= count($celdas) ?></span>
        </div>
    </div>

    <div class="wiz-celda">
        <div class="wiz-celda-num">📍 CELDA <span id="celdaActualNum">—</span></div>
        <div class="wiz-celda-tag" id="celdaTag" style="display:none"></div>
    </div>

    <div id="wizContent" class="wiz-content">
        <!-- ESTADO: Inicial -->
        <div id="state-initial" class="wiz-state is-active">
            <label for="wizFoto" class="wiz-cam-btn">
                <span class="wiz-cam-icon">📷</span>
                <span class="wiz-cam-text">TOMAR FOTO</span>
                <input type="file" id="wizFoto" accept="image/*" capture="environment" style="display:none">
            </label>
            <div class="wiz-divider">— o —</div>
            <button type="button" class="wiz-vacia-btn" id="wizVaciaBtn">🅿️ CELDA VACÍA</button>
        </div>

        <!-- ESTADO: Después de tomar foto, pide placa -->
        <div id="state-placa" class="wiz-state">
            <img id="fotoPreview" class="wiz-result-foto" alt="">
            <p style="text-align:center;color:#6b7280;margin:14px 0">Escribe la placa que se ve en la foto:</p>
            <input type="text" id="placaInput" maxlength="15" placeholder="ABC123"
                   style="width:100%;padding:18px;font-size:32px;font-weight:700;letter-spacing:4px;
                          text-align:center;text-transform:uppercase;border:2px solid #1e293b;border-radius:8px;">
            <div class="wiz-actions">
                <button type="button" class="btn btn--primary btn--xl" id="btnRegistrarLectura">✓ REGISTRAR Y SIGUIENTE</button>
                <button type="button" class="btn" id="btnReintento">↻ Tomar otra foto</button>
            </div>
        </div>

        <!-- ESTADO: Procesando -->
        <div id="state-processing" class="wiz-state">
            <div class="wiz-processing">
                <div class="spinner"></div>
                <p id="processingMsg">Guardando...</p>
            </div>
        </div>

        <!-- ESTADO: Completado -->
        <div id="state-done" class="wiz-state">
            <div style="text-align:center;padding:40px 20px">
                <div style="font-size:64px">🎉</div>
                <h2>¡Revista completada!</h2>
                <p class="t-muted">Todas las celdas fueron revisadas.</p>
                <div style="margin-top:20px">
                    <a class="btn btn--primary" href="<?= url('/rondas/ver?id=' . $id) ?>">Ver resumen</a>
                </div>
            </div>
        </div>
    </div>

    <div class="wiz-footer">
        <a class="btn btn--sm" href="<?= url('/rondas/ver?id=' . $id) ?>">📋 Resumen</a>
        <a class="btn btn--sm" href="<?= url('/rondas/sincronizar') ?>">🔄 Pendientes</a>
        <form method="post" action="<?= url('/rondas/terminar') ?>" style="display:inline"
              onsubmit="return confirm('¿Terminar la revista? Las celdas no revisadas quedarán pendientes.');">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn--sm btn--primary">✓ Terminar</button>
        </form>
    </div>
</div>

<style>
.sync-bar{display:flex;align-items:center;gap:10px;padding:8px 14px;background:#0f172a;color:#fff;
    font-size:13px;border-radius:8px;margin-bottom:14px;}
.sync-dot{width:10px;height:10px;border-radius:50%;background:#9ca3af;display:inline-block;}
.sync-dot.online{background:#22c55e;animation:pulse 2s infinite;}
.sync-dot.offline{background:#f59e0b;}
.sync-dot.syncing{background:#3b82f6;animation:pulse 1s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.sync-btn{margin-left:auto;background:#1e6cff;color:#fff;border:none;padding:5px 12px;border-radius:6px;
    font-size:12px;font-weight:600;cursor:pointer;}

.wiz-shell{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;
    box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden;}
.wiz-header{padding:14px 18px;border-bottom:1px solid var(--color-border);background:#0f172a;color:#fff;}
.wiz-title{font-size:14px;font-weight:600;margin-bottom:8px;}
.wiz-progress{display:flex;align-items:center;gap:10px;font-size:13px;}
.wiz-progress-bar{flex:1;height:8px;background:#334155;border-radius:4px;overflow:hidden;}
.wiz-progress-fill{height:100%;background:#16a34a;transition:width .3s;}
.wiz-celda{padding:24px;text-align:center;background:#f8fafc;border-bottom:1px solid var(--color-border);}
.wiz-celda-num{font-size:28px;font-weight:700;color:#0f172a;}
.wiz-celda-tag{margin-top:8px;display:inline-block;padding:4px 12px;background:#fef3c7;color:#92400e;
    border-radius:999px;font-size:12px;font-weight:600;}
.wiz-content{padding:28px 24px;min-height:300px;}
.wiz-state{display:none;}.wiz-state.is-active{display:block;}
.wiz-cam-btn{display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:8px;padding:32px;background:#1e6cff;color:#fff;border-radius:14px;cursor:pointer;
    box-shadow:0 6px 16px rgba(30,108,255,.3);transition:transform .15s;}
.wiz-cam-btn:active{transform:scale(.97);}
.wiz-cam-icon{font-size:56px;}
.wiz-cam-text{font-size:18px;font-weight:700;letter-spacing:1px;}
.wiz-divider{text-align:center;margin:20px 0;color:#9ca3af;font-size:13px;}
.wiz-vacia-btn{width:100%;padding:18px;background:#fff;border:2px dashed var(--color-border);
    color:#475569;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;}
.wiz-processing{text-align:center;padding:40px 20px;}
.spinner{width:48px;height:48px;border:4px solid #e5e7eb;border-top-color:#1e6cff;
    border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px;}
@keyframes spin{to{transform:rotate(360deg);}}
.wiz-result-foto{width:100%;max-height:200px;object-fit:cover;border-radius:8px;border:1px solid var(--color-border);}
.wiz-actions{display:flex;flex-direction:column;gap:10px;margin-top:14px;}
.btn--xl{padding:18px;font-size:18px;font-weight:700;}
.wiz-footer{padding:14px 18px;border-top:1px solid var(--color-border);background:#f8fafc;
    display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;}
@media (max-width:520px){.wiz-shell{margin:0;border-radius:0;}.main{padding:0 !important;}}
</style>

<script src="<?= url('/assets/js/sp_offline.js') ?>"></script>
<script>
(function () {
    var REVISTA_ID = <?= $id ?>;
    var NIVEL = '<?= e($revista['nivel']) ?>';
    var CELDAS = <?= $celdasJson ?>;

    var indiceActual = 0;
    var fotoBlob = null;

    function findSiguiente(localPendientes) {
        // Marcar las que ya están revisadas (servidor o local)
        var revisadasLocales = {};
        localPendientes.forEach(function (p) {
            if (String(p.revista_id) === String(REVISTA_ID)) revisadasLocales[p.celda] = true;
        });
        for (var i = 0; i < CELDAS.length; i++) {
            var c = CELDAS[i];
            if (!c.revisada_servidor && !revisadasLocales[c.numero]) {
                return { idx: i, celda: c };
            }
        }
        return null;
    }

    function refreshCeldaActual() {
        return SPOffline.listar().then(function (locales) {
            var prox = findSiguiente(locales);
            var revisadas = 0;
            CELDAS.forEach(function (c) { if (c.revisada_servidor) revisadas++; });
            locales.forEach(function (p) { if (String(p.revista_id) === String(REVISTA_ID)) revisadas++; });

            var total = CELDAS.length;
            document.getElementById('wizProgressFill').style.width = (revisadas / total * 100) + '%';
            document.getElementById('wizProgressText').textContent = revisadas + ' / ' + total;

            if (!prox) {
                showState('done');
                return;
            }

            indiceActual = prox.idx;
            document.getElementById('celdaActualNum').textContent = prox.celda.numero;
            var tag = document.getElementById('celdaTag');
            if (prox.celda.privada) {
                tag.style.display = 'inline-block';
                tag.textContent = '🔒 Privada' + (prox.celda.apto ? ' · Apto ' + prox.celda.apto : '');
            } else {
                tag.style.display = 'none';
            }
        });
    }

    function showState(name) {
        ['initial','placa','processing','done'].forEach(function (s) {
            document.getElementById('state-' + s).classList.remove('is-active');
        });
        document.getElementById('state-' + name).classList.add('is-active');
    }

    // ── Tomar foto ──
    document.getElementById('wizFoto').addEventListener('change', function (e) {
        if (!e.target.files || !e.target.files[0]) return;
        fotoBlob = e.target.files[0];
        var url = URL.createObjectURL(fotoBlob);
        document.getElementById('fotoPreview').src = url;
        document.getElementById('placaInput').value = '';
        showState('placa');
        setTimeout(function () { document.getElementById('placaInput').focus(); }, 200);
        e.target.value = '';
    });

    document.getElementById('btnReintento').addEventListener('click', function () {
        fotoBlob = null;
        showState('initial');
    });

    // ── Registrar lectura ──
    document.getElementById('btnRegistrarLectura').addEventListener('click', async function () {
        var placa = document.getElementById('placaInput').value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (placa.length < 4) { alert('Placa muy corta. Mínimo 4 caracteres.'); return; }

        var celda = CELDAS[indiceActual].numero;
        showState('processing');
        document.getElementById('processingMsg').textContent = 'Guardando localmente...';

        try {
            await SPOffline.guardar({
                revista_id: REVISTA_ID,
                celda: celda,
                placa: placa,
                foto_blob: fotoBlob,
                celda_vacia: 0,
                placa_manual: 1,
                fuente: 'revista',
                nivel: NIVEL,
            });
            fotoBlob = null;
            await refreshCeldaActual();
            showState('initial');
        } catch (e) {
            alert('Error guardando: ' + e.message);
            showState('placa');
        }
    });

    // ── Celda vacía ──
    document.getElementById('wizVaciaBtn').addEventListener('click', async function () {
        var celda = CELDAS[indiceActual].numero;
        if (!confirm('¿Marcar celda ' + celda + ' como VACÍA?')) return;
        showState('processing');
        document.getElementById('processingMsg').textContent = 'Guardando...';
        try {
            await SPOffline.guardar({
                revista_id: REVISTA_ID,
                celda: celda,
                placa: '',
                foto_blob: null,
                celda_vacia: 1,
                placa_manual: 0,
                fuente: 'revista',
                nivel: NIVEL,
            });
            await refreshCeldaActual();
            showState('initial');
        } catch (e) {
            alert('Error: ' + e.message);
            showState('initial');
        }
    });

    // ── Barra de sincronización ──
    function actualizarBarra(stats) {
        var dot = document.getElementById('syncDot');
        var txt = document.getElementById('syncText');
        var btn = document.getElementById('syncBtn');
        dot.className = 'sync-dot ' + (stats.online ? 'online' : 'offline');
        if (stats.pendientes === 0) {
            txt.textContent = stats.online ? '🟢 Online · 0 pendientes' : '🟠 Offline · 0 pendientes';
            btn.style.display = 'none';
        } else {
            txt.textContent = (stats.online ? '🟢 Online' : '🟠 Offline') + ' · ' + stats.pendientes + ' pendiente' + (stats.pendientes===1?'':'s');
            btn.style.display = stats.online ? 'inline-block' : 'none';
        }
    }
    document.getElementById('syncBtn').addEventListener('click', async function () {
        var dot = document.getElementById('syncDot');
        dot.className = 'sync-dot syncing';
        var r = await SPOffline.sincronizar();
        await refreshCeldaActual();
    });

    // ── Inicialización ──
    SPOffline.init().then(function () {
        SPOffline.onCambio(actualizarBarra);
        return refreshCeldaActual();
    });
})();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
