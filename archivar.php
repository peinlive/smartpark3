<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/archivar.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_GET['id'] ?? $_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/visitantes'); }

$st = $pdo->prepare("SELECT id, placa, archivado_en FROM visitantes_vehiculos
                      WHERE id = :id AND conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'No encontrado.'); redirect('/visitantes'); }
if ($v['archivado_en']) { flash_set('warn', 'Ya archivado.'); redirect('/visitantes/ver?id=' . $id); }

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $motivo = clean_string($_POST['motivo'] ?? '', 255);
    if ($motivo === '') $errores[] = 'Indica un motivo.';
    if (empty($errores)) {
        $up = $pdo->prepare("UPDATE visitantes_vehiculos
                                SET archivado_en = NOW(), archivado_motivo = :m
                              WHERE id = :id");
        $up->execute([':m' => $motivo, ':id' => $id]);
        flash_set('ok', "Visitante {$v['placa']} archivado.");
        redirect('/visitantes/ver?id=' . $id);
    }
}

$_pageTitle = 'Archivar visitante';
include INCLUDES_PATH . '/header.php';
?>
<div class="page-head"><h1 class="page-head__title">📁 Archivar visitante</h1>
    <p class="page-head__sub">Placa <strong><?= e($v['placa']) ?></strong></p></div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error"><ul style="margin:0 0 0 18px">
        <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= url('/visitantes/archivar') ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-section">
        <h3 class="form-section__title">Motivo *</h3>
        <label class="field">
            <input type="text" name="motivo" required maxlength="255" autofocus
                   placeholder="Ej: ya no es visitante recurrente, dato incorrecto...">
        </label>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= url('/visitantes/ver?id=' . $id) ?>">Cancelar</a>
        <button type="submit" class="btn btn--danger">Archivar</button>
    </div>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
