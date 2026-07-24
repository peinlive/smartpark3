<?php
// /home/myzonaco/smartpark.myzona360.com/modules/rondas/sincronizar.php
// Gestor de lecturas pendientes en el navegador (IndexedDB).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

$_pageTitle = 'Sincronización';
include INCLUDES_PATH . '/header.php';
?>

<form id="csrfHolder" style="display:none"><?= csrf_field() ?></form>

<div class="page-head">
    <h1 class="page-head__title">🔄 Sincronización offline</h1>
    <p class="page-head__sub">Lecturas guardadas en este celular esperando subir al servidor.</p>
</div>

<div class="cards">
    <div class="card card--accent">
        <div class="card__label">Pendientes</div>
        <div class="card__value" id="statPendientes">—</div>
    </div>
    <div class="card">
        <div class="card__label">Estado</div>
        <div class="card__value" style="font-size:16px" id="statOnline">—</div>
    </div>
    <div class="card" id="cardErrores" style="display:none">
        <div class="card__label">Con errores</div>
        <div class="card__value" id="statErrores">—</div>
    </div>
</div>

<div class="toolbar">
    <button type="button" class="btn btn--primary btn--lg" id="btnSyncAll">↑ Sincronizar ahora</button>
    <a class="btn" href="<?= url('/rondas') ?>">← Volver</a>
</div>

<div id="msgArea"></div>

<div id="listaPendientes" class="detail-card detail-card--full" style="display:none">
    <h3 class="detail-card__title">Detalle de pendientes</h3>
    <div class="table-wrap">
    <table class="data-table data-table--compact" id="tablaPend">
        <thead>
            <tr><th>Celda</th><th>Placa</th><th>Tipo</th><th>Foto</th><th>Creada</th><th>Intentos</th><th>Error</th><th></th></tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>
</div>

<script src="<?= url('/assets/js/sp_offline.js') ?>"></script>
<script>
(function () {
    var msgArea = document.getElementById('msgArea');
    var btnSync = document.getElementById('btnSyncAll');

    function msg(text, klass) {
        msgArea.innerHTML = '<div class="flash flash--' + (klass||'info') + '">' + text + '</div>';
        if (klass === 'ok') setTimeout(function(){ msgArea.innerHTML = ''; }, 4000);
    }

    function renderTabla(pendientes) {
        var tb = document.querySelector('#tablaPend tbody');
        tb.innerHTML = '';
        if (pendientes.length === 0) {
            document.getElementById('listaPendientes').style.display = 'none';
            return;
        }
        document.getElementById('listaPendientes').style.display = 'block';
        pendientes.forEach(function (p) {
            var fotoCell = '—';
            if (p.foto_blob) {
                var url = URL.createObjectURL(p.foto_blob);
                fotoCell = '<a href="' + url + '" target="_blank"><img src="' + url + '" style="height:32px;width:32px;border-radius:4px;object-fit:cover"></a>';
            }
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><strong>' + (p.celda||'—') + '</strong></td>' +
                '<td>' + (p.celda_vacia ? '<span class="t-muted">VACÍA</span>' : '<strong>' + (p.placa||'?') + '</strong>') + '</td>' +
                '<td>' + (p.fuente||'') + '</td>' +
                '<td>' + fotoCell + '</td>' +
                '<td><small>' + new Date(p.creado_en).toLocaleString() + '</small></td>' +
                '<td>' + (p.intentos||0) + '</td>' +
                '<td><small class="t-error">' + (p.ultimo_error||'') + '</small></td>' +
                '<td><button type="button" class="btn btn--sm" data-id="' + p.local_id + '">Eliminar</button></td>';
            tb.appendChild(tr);
        });
        // bind eliminar
        tb.querySelectorAll('button[data-id]').forEach(function (b) {
            b.addEventListener('click', async function () {
                if (!confirm('¿Eliminar esta lectura pendiente? Se perderá.')) return;
                await SPOffline.eliminar(parseInt(b.dataset.id, 10));
                refrescar();
            });
        });
    }

    async function refrescar() {
        var pendientes = await SPOffline.listar();
        var s = await SPOffline.stats();
        document.getElementById('statPendientes').textContent = s.pendientes;
        document.getElementById('statOnline').innerHTML = s.online
            ? '<span class="pill pill--ok">🟢 Online</span>'
            : '<span class="pill pill--warn">🟠 Offline</span>';
        document.getElementById('cardErrores').style.display = s.con_errores > 0 ? 'block' : 'none';
        document.getElementById('statErrores').textContent = s.con_errores;
        btnSync.disabled = !s.online || s.pendientes === 0;
        renderTabla(pendientes);
    }

    btnSync.addEventListener('click', async function () {
        if (!navigator.onLine) { msg('Sin conexión. Conéctate a WiFi y vuelve a intentar.', 'warn'); return; }
        btnSync.disabled = true;
        msg('🔄 Sincronizando... no cierres la página.', 'info');
        var r = await SPOffline.sincronizar();
        if (r.ok) {
            var t = '✓ Sincronizadas: ' + r.sincronizadas + ' de ' + r.total + '.';
            if (r.errores > 0) t += ' (' + r.errores + ' con errores)';
            msg(t, r.errores > 0 ? 'warn' : 'ok');
        } else {
            msg('No se pudo sincronizar: ' + (r.motivo || 'desconocido'), 'warn');
        }
        await refrescar();
    });

    SPOffline.init().then(function () {
        SPOffline.onCambio(refrescar);
        refrescar();
    });
    window.addEventListener('online',  refrescar);
    window.addEventListener('offline', refrescar);
})();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
