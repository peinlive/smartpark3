<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/archivar.php
// Archivar vehículo (borrado lógico con motivo).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$id = clean_int($_GET['id'] ?? $_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/vehiculos'); }

$st = $pdo->prepare("SELECT id, placa, archivado_en FROM vehiculos
                      WHERE id = :id AND conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'Vehículo no encontrado.'); redirect('/vehiculos'); }
if ($v['archivado_en']) {
    flash_set('warn', 'Este vehículo ya está archivado.');
    redirect('/vehiculos/ver?id=' . $id);
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $motivo = clean_string($_POST['motivo'] ?? '', 255);
    if ($motivo === '') $errores[] = 'Indica un motivo.';

    if (empty($errores)) {
        $up = $pdo->prepare("UPDATE vehiculos
                                SET archivado_en = NOW(), archivado_motivo = :m, activo = 0
                              WHERE id = :id");
        $up->execute([':m' => $motivo, ':id' => $id]);

        $pdo->prepare("INSERT INTO audit_log
                (conjunto_id, usuario_id, accion, entidad, entidad_id, descripcion)
             VALUES (:c, :u, 'archivar', 'vehiculo', :id, :d)")
            ->execute([
                ':c' => $conjuntoId, ':u' => $u['id'], ':id' => $id,
                ':d' => "Archivó vehículo {$v['placa']}: {$motivo}",
            ]);

        flash_set('ok', "Vehículo {$v['placa']} archivado.");
        redirect('/vehiculos/ver?id=' . $id);
    }
}

$_pageTitle = 'Archivar vehículo';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">📁 Archivar vehículo</h1>
    <p class="page-head__sub">Placa <strong><?= e($v['placa']) ?></strong></p>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px">
            <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/vehiculos/archivar') ?>" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-section">
        <h3 class="form-section__title">Motivo del archivado *</h3>
        <label class="field">
            <input type="text" name="motivo" required maxlength="255"
                   placeholder="Ej: Se vendió, residente se mudó, vehículo dado de baja..."
                   autofocus>
            <small class="field__hint">
                El vehículo se ocultará del listado por defecto. Podrás restaurarlo desde la vista del vehículo.
            </small>
        </label>
    </div>

    <div class="form-actions">
        <a class="btn" href="<?= url('/vehiculos/ver?id=' . $id) ?>">Cancelar</a>
        <button type="submit" class="btn btn--danger">Archivar</button>
    </div>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
