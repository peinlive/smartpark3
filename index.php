<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/index.php
// v3c: agrega botón "Ver detalle" en cada fila del histórico.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$st = $pdo->prepare("
    SELECT i.*, us.nombre_completo AS usuario_nombre
      FROM importaciones_log i
 LEFT JOIN usuarios us ON us.id = i.usuario_id
     WHERE i.conjunto_id = :c
  ORDER BY i.creado_en DESC
     LIMIT 50
");
$st->execute([':c' => $conjuntoId]);
$logs = $st->fetchAll();

$_pageTitle = 'Importaciones';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>

    <h1 class="page-head__title">Importaciones masivas</h1>
    <p class="page-head__sub">Carga datos en bloque desde archivos Excel (XLSX) o CSV.</p>
</div>

<!-- ── v7.2: importar desde Google Contacts (recomendado para residentes) ── -->
<div class="card" style="margin-bottom:18px;background:linear-gradient(135deg,#eff6ff,#f0fdf4);
     border:2px solid #93c5fd">
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div style="font-size:38px">📇</div>
        <div style="flex:1;min-width:240px">
            <h3 style="margin:0 0 3px;font-size:17px">
                Residentes desde <b>Google Contacts</b>
                <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:9px;
                             font-size:11px;font-weight:700;margin-left:5px">RECOMENDADO</span>
            </h3>
            <p style="margin:0;font-size:13.5px;color:#374151;line-height:1.6">
                Subís el <code>.vcf</code> tal cual sale de Google. <b>No hay que preparar nada.</b><br>
                Lee <code>1020 Inqu Juan Soto</code> → apto, tipo, nombre y celular.
                Detecta <b>nuevos, cambios y los que ya no están</b>, con preview antes de aplicar.
            </p>
        </div>
        <a class="btn btn--primary" href="<?= url('/importaciones/contactos') ?>"
           style="white-space:nowrap">📇 Importar contactos</a>
    </div>
</div>

<div class="cards" style="margin-bottom:24px">
    <div class="card card--accent">
        <div class="card__label">👥 Residentes <small style="font-weight:400;color:#9ca3af">(Excel/CSV)</small></div>
        <div class="card__value" style="font-size:14px;font-weight:400;margin-top:8px">
            Columnas: <code>apto</code>, <code>tipo</code>, <code>nombre</code>, <code>celular</code>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn btn--primary btn--sm" href="<?= url('/importaciones/nueva?tipo=residentes') ?>">+ Nueva</a>
            <a class="btn btn--sm" href="<?= url('/importaciones/plantilla_residentes') ?>">📄 Plantilla</a>
        </div>
    </div>

    <div class="card card--accent">
        <div class="card__label">🚗 Vehículos</div>
        <div class="card__value" style="font-size:14px;font-weight:400;margin-top:8px">
            Columnas: <code>apto</code>, <code>placa</code>, <code>usuario</code>, <code>observacion</code>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn btn--primary btn--sm" href="<?= url('/importaciones/nueva?tipo=vehiculos') ?>">+ Nueva</a>
            <a class="btn btn--sm" href="<?= url('/importaciones/plantilla_vehiculos') ?>">📄 Plantilla</a>
        </div>
    </div>

    <div class="card card--accent">
        <div class="card__label">🔗 Vincular sin asignar</div>
        <div class="card__value" style="font-size:14px;font-weight:400;margin-top:8px">
            Asigna vínculo (inquilino/propietario) a los vehículos que están "Sin asignar".
        </div>
        <div style="margin-top:10px">
            <a class="btn btn--primary btn--sm" href="<?= url('/importaciones/vincular_vehiculos') ?>">🔗 Abrir</a>
        </div>
    </div>
</div>

<h2 style="font-size:16px;margin:0 0 12px">Histórico de importaciones</h2>

<?php if (empty($logs)): ?>
    <div class="notice">Aún no se han realizado importaciones.</div>
<?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Fecha</th><th>Tipo</th><th>Archivo</th><th>Usuario</th>
                <th class="t-right">Total</th><th class="t-right">OK</th><th class="t-right">Errores</th>
                <th class="t-right">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td><?= e(fecha_humana($l['creado_en'])) ?></td>
                <td><?= e(ucfirst($l['tipo'])) ?></td>
                <td><?= e($l['archivo_nombre'] ?? '—') ?></td>
                <td><?= e($l['usuario_nombre'] ?? '—') ?></td>
                <td class="t-right"><?= (int)$l['total_filas'] ?></td>
                <td class="t-right"><span class="pill pill--ok"><?= (int)$l['filas_ok'] ?></span></td>
                <td class="t-right">
                    <?php if ((int)$l['filas_error'] > 0): ?>
                        <span class="pill pill--warn"><?= (int)$l['filas_error'] ?></span>
                    <?php else: ?>
                        0
                    <?php endif; ?>
                </td>
                <td class="t-right">
                    <a class="btn btn--sm" href="<?= url('/importaciones/detalle?id=' . (int)$l['id']) ?>">
                        👁️ Ver detalle
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
