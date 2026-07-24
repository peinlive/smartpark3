<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/detalle.php
// Ver el detalle completo de una importación: filas que se crearon, errores, etc.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/importaciones'); }

$st = $pdo->prepare("
    SELECT i.*, us.nombre_completo AS usuario_nombre
      FROM importaciones_log i
 LEFT JOIN usuarios us ON us.id = i.usuario_id
     WHERE i.id = :id AND i.conjunto_id = :c LIMIT 1
");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$log = $st->fetch();
if (!$log) { flash_set('error', 'Importación no encontrada.'); redirect('/importaciones'); }

$detalle = json_decode($log['detalle_json'] ?? '{}', true);
$errores = $detalle['errores'] ?? [];
$totales = $detalle['totales'] ?? [];
$tipo = $log['tipo'];

// Para mostrar lista de lo que se IMPORTÓ, buscamos en la BD los registros creados
// alrededor de la fecha del log (ventana de 5 minutos)
$registros_creados = [];
if ($tipo === 'residentes') {
    $st = $pdo->prepare("
        SELECT r.id, r.nombre, r.tipo, r.celular, r.creado_en,
               a.numero_visible AS apto, t.numero AS torre
          FROM residentes r
          JOIN apartamentos a ON a.id = r.apartamento_id
          JOIN torres t ON t.id = a.torre_id
         WHERE a.conjunto_id = :c
           AND r.creado_en BETWEEN :ini AND :fin
      ORDER BY r.creado_en DESC, a.numero_visible
    ");
} elseif ($tipo === 'vehiculos') {
    $st = $pdo->prepare("
        SELECT v.id, v.placa, v.tipo, v.creado_en, v.observaciones,
               a.numero_visible AS apto, t.numero AS torre,
               r.nombre AS residente_nombre
          FROM vehiculos v
          JOIN apartamentos a ON a.id = v.apartamento_id
          JOIN torres t ON t.id = a.torre_id
     LEFT JOIN residentes r ON r.id = v.residente_id
         WHERE v.conjunto_id = :c
           AND v.creado_en BETWEEN :ini AND :fin
      ORDER BY v.creado_en DESC, v.placa
    ");
} else {
    $st = null;
}

if ($st) {
    // Ventana de 5 minutos alrededor de la importación
    $ini = date('Y-m-d H:i:s', strtotime($log['creado_en']) - 10);
    $fin = date('Y-m-d H:i:s', strtotime($log['creado_en']) + 300);
    $st->execute([':c' => $conjuntoId, ':ini' => $ini, ':fin' => $fin]);
    $registros_creados = $st->fetchAll();
}

$_pageTitle = 'Detalle importación #' . $id;
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">Detalle de importación #<?= $id ?></h1>
    <p class="page-head__sub">
        Tipo: <strong><?= e($tipo) ?></strong> ·
        Archivo: <code><?= e($log['archivo_nombre'] ?: '—') ?></code> ·
        <?= e(fecha_humana($log['creado_en'])) ?> ·
        por <?= e($log['usuario_nombre'] ?? '—') ?>
    </p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/importaciones') ?>">← Volver al histórico</a>
</div>

<div class="cards">
    <div class="card card--accent">
        <div class="card__label">Total filas</div>
        <div class="card__value"><?= (int)$log['total_filas'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">Importadas OK</div>
        <div class="card__value"><?= (int)$log['filas_ok'] ?></div>
    </div>
    <?php if (!empty($totales['duplicado'])): ?>
    <div class="card">
        <div class="card__label">Duplicados omitidos</div>
        <div class="card__value"><?= (int)$totales['duplicado'] ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($totales['actualizados'])): ?>
    <div class="card">
        <div class="card__label">Actualizados</div>
        <div class="card__value"><?= (int)$totales['actualizados'] ?></div>
    </div>
    <?php endif; ?>
    <div class="card <?= ((int)$log['filas_error']>0?'card--warn':'') ?>">
        <div class="card__label">Con errores</div>
        <div class="card__value"><?= (int)$log['filas_error'] ?></div>
    </div>
</div>

<?php if (!empty($registros_creados)): ?>
<div class="detail-card detail-card--full">
    <h3 class="detail-card__title">
        ✅ Registros creados/actualizados en esta importación (<?= count($registros_creados) ?>)
    </h3>
    <p class="t-muted" style="margin-top:0">
        Detectados por ventana de tiempo (creados ±5 min de la importación).
        Si en esa franja también creaste algo manual, podría aparecer aquí.
    </p>
    <div class="table-wrap">
    <table class="data-table data-table--compact">
        <thead>
            <tr>
                <?php if ($tipo === 'residentes'): ?>
                    <th>Apto</th><th>Torre</th><th>Nombre</th><th>Tipo</th><th>Celular</th><th>Creado</th><th class="t-right"></th>
                <?php else: ?>
                    <th>Apto</th><th>Torre</th><th>Placa</th><th>Tipo</th><th>Residente vinculado</th><th>Observación</th><th>Creado</th><th class="t-right"></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($registros_creados as $r): ?>
            <tr>
                <?php if ($tipo === 'residentes'): ?>
                    <td><strong><?= e($r['apto']) ?></strong></td>
                    <td>T<?= (int)$r['torre'] ?></td>
                    <td><?= e($r['nombre']) ?></td>
                    <td><?= e($r['tipo']) ?></td>
                    <td><?= e($r['celular'] ?: '—') ?></td>
                    <td><small><?= e(fecha_humana($r['creado_en'])) ?></small></td>
                    <td class="t-right">
                        <a class="btn btn--sm" href="<?= url('/residentes/ver?id=' . (int)$r['id']) ?>">Ver</a>
                    </td>
                <?php else: ?>
                    <td><strong><?= e($r['apto']) ?></strong></td>
                    <td>T<?= (int)$r['torre'] ?></td>
                    <td><strong><?= e($r['placa']) ?></strong></td>
                    <td><?= $r['tipo'] === 'moto' ? '🏍️' : '🚗' ?> <?= e($r['tipo']) ?></td>
                    <td><?= e($r['residente_nombre'] ?: '—') ?></td>
                    <td><small class="t-muted"><?= e($r['observaciones'] ?: '—') ?></small></td>
                    <td><small><?= e(fecha_humana($r['creado_en'])) ?></small></td>
                    <td class="t-right">
                        <a class="btn btn--sm" href="<?= url('/vehiculos/ver?id=' . (int)$r['id']) ?>">Ver</a>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($errores)): ?>
<div class="detail-card detail-card--full" style="margin-top:16px">
    <h3 class="detail-card__title">⚠️ Filas con errores (<?= count($errores) ?>)</h3>
    <p class="t-muted" style="margin-top:0">No se importaron. Corrige el archivo origen y vuelve a subir.</p>
    <div class="table-wrap">
    <table class="data-table data-table--compact">
        <thead>
            <tr><th>Línea CSV</th><th>Apto</th>
                <?php if ($tipo === 'residentes'): ?><th>Nombre</th><?php else: ?><th>Placa</th><?php endif; ?>
                <th>Motivo</th></tr>
        </thead>
        <tbody>
        <?php foreach ($errores as $err): ?>
            <tr>
                <td><?= (int)$err['linea'] ?></td>
                <td><?= e($err['apto'] ?? '') ?></td>
                <td><?= e($err['nombre'] ?? $err['placa'] ?? '') ?></td>
                <td class="t-error"><?= e($err['motivo']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($registros_creados) && empty($errores)): ?>
    <div class="notice">No hay registros para mostrar de esta importación.</div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
