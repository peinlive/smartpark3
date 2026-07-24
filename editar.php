<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/editar.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
require_once INCLUDES_PATH . '/upload_helpers.php';
require_once INCLUDES_PATH . '/csv_helpers.php';
auth_require_role('super_admin','admin','supervisor','porteria');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/visitantes'); }

$st = $pdo->prepare("
    SELECT v.*, a.numero_visible AS apto_numero
      FROM visitantes_vehiculos v
      JOIN apartamentos a ON a.id = v.apartamento_id
     WHERE v.id = :id AND v.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$visitante = $st->fetch();
if (!$visitante) { flash_set('error', 'No encontrado.'); redirect('/visitantes'); }
if ($visitante['archivado_en']) {
    flash_set('warn', 'Archivado. Restaúralo primero.');
    redirect('/visitantes/ver?id=' . $id);
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $placa  = normalizar_placa(clean_string($_POST['placa'] ?? '', 15));
    $tipo   = in_array($_POST['tipo'] ?? '', ['carro','moto'], true) ? $_POST['tipo'] : 'carro';
    $apto_n = clean_string($_POST['apto_numero'] ?? '', 20);
    $nombre = clean_string($_POST['nombre_visitante'] ?? '', 150);
    $parent = clean_string($_POST['parentesco'] ?? '', 80);
    $cel    = normalizar_celular(clean_string($_POST['celular'] ?? '', 30));
    $marca  = clean_string($_POST['marca'] ?? '', 60);
    $color  = clean_string($_POST['color'] ?? '', 40);
    $recurr = (int)($_POST['recurrente'] ?? 0) === 1 ? 1 : 0;
    $obs    = clean_string($_POST['observaciones'] ?? '', 500);

    if ($placa === '') $errores[] = 'Placa requerida.';
    if ($apto_n === '') $errores[] = 'Apto requerido.';

    $apto = null;
    if ($apto_n !== '') {
        $sa = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $sa->execute([':c' => $conjuntoId, ':n' => $apto_n]);
        $apto = $sa->fetch();
        if (!$apto) $errores[] = "Apto '{$apto_n}' no existe.";
    }

    $fotoNueva = null;
    if (empty($errores)) {
        try { $fotoNueva = upload_foto_vehiculo($_FILES['foto'] ?? [], 'visitantes'); }
        catch (RuntimeException $e) { $errores[] = $e->getMessage(); }
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            $fotoFinal = $fotoNueva ?: $visitante['foto_principal'];
            $up = $pdo->prepare("
                UPDATE visitantes_vehiculos SET
                    apartamento_id = :a, placa = :p, tipo = :t,
                    nombre_visitante = :n, parentesco = :pa, celular = :ce,
                    marca = :m, color = :co, recurrente = :r,
                    foto_principal = :fp, observaciones = :ob
                WHERE id = :id AND conjunto_id = :c
            ");
            $up->execute([
                ':a' => (int)$apto['id'], ':p' => $placa, ':t' => $tipo,
                ':n' => $nombre ?: null, ':pa' => $parent ?: null, ':ce' => $cel ?: null,
                ':m' => $marca ?: null, ':co' => $color ?: null, ':r' => $recurr,
                ':fp' => $fotoFinal, ':ob' => $obs ?: null,
                ':id' => $id, ':c' => $conjuntoId,
            ]);
            if ($fotoNueva && !empty($visitante['foto_principal']) && $visitante['foto_principal'] !== $fotoNueva) {
                eliminar_foto($visitante['foto_principal']);
            }
            $pdo->commit();
            flash_set('ok', "Visitante {$placa} actualizado.");
            redirect('/visitantes/ver?id=' . $id);
        } catch (Exception $ex) {
            $pdo->rollBack();
            if ($fotoNueva) eliminar_foto($fotoNueva);
            $errores[] = APP_DEBUG ? $ex->getMessage() : 'Error al actualizar.';
        }
    }
}

$_pageTitle = 'Editar visitante ' . $visitante['placa'];
include INCLUDES_PATH . '/header.php';
?>
<div class="page-head"><h1 class="page-head__title">Editar visitante</h1>
    <p class="page-head__sub">Placa <strong><?= e($visitante['placa']) ?></strong></p></div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error"><ul style="margin:0 0 0 18px">
        <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php $action = url('/visitantes/editar?id=' . $id); $submitLabel = 'Guardar';
include __DIR__ . '/_form.php'; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
