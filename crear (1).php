<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/crear.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
require_once INCLUDES_PATH . '/upload_helpers.php';
require_once INCLUDES_PATH . '/csv_helpers.php';

auth_require_role('super_admin','admin','supervisor','porteria');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $placa    = normalizar_placa(clean_string($_POST['placa'] ?? '', 15));
    $tipo     = in_array($_POST['tipo'] ?? '', ['carro','moto'], true) ? $_POST['tipo'] : 'carro';
    $apto_num = clean_string($_POST['apto_numero'] ?? '', 20);
    $nombre   = clean_string($_POST['nombre_visitante'] ?? '', 150);
    $parent   = clean_string($_POST['parentesco'] ?? '', 80);
    $cel      = normalizar_celular(clean_string($_POST['celular'] ?? '', 30));
    $marca    = clean_string($_POST['marca'] ?? '', 60);
    $color    = clean_string($_POST['color'] ?? '', 40);
    $recurr   = (int)($_POST['recurrente'] ?? 0) === 1 ? 1 : 0;
    $obs      = clean_string($_POST['observaciones'] ?? '', 500);

    if ($placa === '' || strlen($placa) < 4) $errores[] = 'Placa inválida.';
    if ($apto_num === '') $errores[] = 'Apartamento obligatorio.';

    $apto = null;
    if ($apto_num !== '') {
        $st = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':n' => $apto_num]);
        $apto = $st->fetch();
        if (!$apto) $errores[] = "El apartamento '{$apto_num}' no existe.";
    }

    // Verificar si esta placa ya existe como visitante de este apto (para incrementar contador)
    $existente = null;
    if ($placa !== '' && $apto) {
        $st = $pdo->prepare("SELECT id, visitas_count FROM visitantes_vehiculos
                              WHERE conjunto_id = :c AND apartamento_id = :a AND placa = :p
                                AND archivado_en IS NULL LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':a' => (int)$apto['id'], ':p' => $placa]);
        $existente = $st->fetch();
    }

    $fotoRel = null;
    if (empty($errores)) {
        try { $fotoRel = upload_foto_vehiculo($_FILES['foto'] ?? [], 'visitantes'); }
        catch (RuntimeException $e) { $errores[] = $e->getMessage(); }
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            if ($existente) {
                // Incrementar contador de visitas + marcar como recurrente si llegó a 3+
                $newCount = (int)$existente['visitas_count'] + 1;
                $newRecurr = ($newCount >= 3 || $recurr === 1) ? 1 : 0;
                $up = $pdo->prepare("UPDATE visitantes_vehiculos
                                        SET visitas_count = :c, ultima_visita = NOW(),
                                            recurrente = :r,
                                            observaciones = COALESCE(NULLIF(:ob,''), observaciones)
                                      WHERE id = :id");
                $up->execute([':c' => $newCount, ':r' => $newRecurr, ':ob' => $obs, ':id' => (int)$existente['id']]);
                $vId = (int)$existente['id'];
                flash_set('ok', "Visita #{$newCount} registrada para placa {$placa}.");
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO visitantes_vehiculos
                        (conjunto_id, apartamento_id, placa, tipo, nombre_visitante, parentesco, celular,
                         recurrente, visitas_count, primera_visita, ultima_visita,
                         marca, color, foto_principal, observaciones, registrado_por)
                    VALUES (:c, :a, :p, :t, :n, :pa, :ce, :r, 1, NOW(), NOW(),
                            :m, :co, :fp, :ob, :u)
                ");
                $ins->execute([
                    ':c' => $conjuntoId, ':a' => (int)$apto['id'], ':p' => $placa, ':t' => $tipo,
                    ':n' => $nombre ?: null, ':pa' => $parent ?: null, ':ce' => $cel ?: null,
                    ':r' => $recurr, ':m' => $marca ?: null, ':co' => $color ?: null,
                    ':fp' => $fotoRel, ':ob' => $obs ?: null, ':u' => $u['id'],
                ]);
                $vId = (int)$pdo->lastInsertId();
                flash_set('ok', "Visitante con placa {$placa} registrado.");
            }
            $pdo->commit();
            redirect('/visitantes/ver?id=' . $vId);
        } catch (Exception $ex) {
            $pdo->rollBack();
            if ($fotoRel) eliminar_foto($fotoRel);
            $errores[] = APP_DEBUG ? $ex->getMessage() : 'Error al guardar.';
        }
    }
}

$_pageTitle = 'Registrar visitante';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>

    <h1 class="page-head__title">Registrar visitante</h1>
    <p class="page-head__sub">Si la placa ya estaba registrada en este apto, se incrementa el contador de visitas automáticamente.</p>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php $action = url('/visitantes/crear'); $submitLabel = 'Registrar visita'; $visitante = null;
include __DIR__ . '/_form.php'; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
