<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/crear.php (v3AX)
//   v3AX: al crear un residente nuevo, permite elegir el TIPO
//         (inquilino / propietario / visitante) desde el formulario.
//         Si es visitante y no viene nombre, se guarda "Visitante Apto XXXX".

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDES_PATH . '/upload_helpers.php';
require_once INCLUDES_PATH . '/csv_helpers.php';

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $placa    = normalizar_placa(clean_string($_POST['placa'] ?? '', 15));
    $tipo     = in_array($_POST['tipo'] ?? '', ['carro','moto'], true) ? $_POST['tipo'] : 'carro';
    $apto_num = clean_string($_POST['apto_numero'] ?? '', 20);
    $marca    = clean_string($_POST['marca']  ?? '', 60);
    $linea    = clean_string($_POST['linea']  ?? '', 60);
    $color    = clean_string($_POST['color']  ?? '', 40);
    $anio     = clean_int($_POST['modelo_anio'] ?? null, 1950, 2099);
    $obs      = clean_string($_POST['observaciones'] ?? '', 500);

    // Residente: prioriza nuevo si vino texto, sino el id del select
    $residente_id_sel = clean_int($_POST['residente_id'] ?? null, 1);
    $r_nuevo_nombre   = clean_string($_POST['residente_nuevo_nombre']  ?? '', 150);
    $r_nuevo_celular  = normalizar_celular(clean_string($_POST['residente_nuevo_celular'] ?? '', 30));

    // v3AX: tipo elegible desde el formulario. Fallback: inquilino
    $tipoResNuevo = in_array($_POST['residente_tipo_nuevo'] ?? '', ['inquilino','propietario','visitante'], true)
        ? $_POST['residente_tipo_nuevo']
        : 'inquilino';

    // v3AX: si es visitante y no hay nombre, generar uno automático
    if ($tipoResNuevo === 'visitante' && $r_nuevo_nombre === '' && $apto_num !== '') {
        $r_nuevo_nombre = 'Visitante Apto ' . $apto_num;
    }

    if ($placa === '' || strlen($placa) < 4) $errores[] = 'Placa inválida.';
    if ($apto_num === '') $errores[] = 'Apartamento obligatorio.';

    $apto = null;
    if ($apto_num !== '') {
        $st = $pdo->prepare("SELECT id, numero_visible FROM apartamentos
                              WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':n' => $apto_num]);
        $apto = $st->fetch();
        if (!$apto) $errores[] = "El apartamento '{$apto_num}' no existe.";
    }

    if ($placa !== '') {
        $st = $pdo->prepare("SELECT id FROM vehiculos
                              WHERE conjunto_id = :c AND placa = :p AND archivado_en IS NULL LIMIT 1");
        $st->execute([':c' => $conjuntoId, ':p' => $placa]);
        if ($st->fetchColumn()) $errores[] = "Ya existe un vehículo activo con la placa '{$placa}'.";
    }

    // Validar que el residente_id pertenezca al apto (seguridad)
    $residenteId = null;
    if ($apto && $r_nuevo_nombre === '' && $residente_id_sel !== null) {
        $st = $pdo->prepare("SELECT id FROM residentes
                              WHERE id = :r AND apartamento_id = :a AND archivado_en IS NULL");
        $st->execute([':r' => $residente_id_sel, ':a' => (int)$apto['id']]);
        if (!$st->fetchColumn()) {
            $errores[] = 'El residente seleccionado no pertenece a este apartamento.';
        } else {
            $residenteId = $residente_id_sel;
        }
    }

    $fotoRel = null;
    if (empty($errores)) {
        try { $fotoRel = upload_foto_vehiculo($_FILES['foto'] ?? [], 'vehiculos'); }
        catch (RuntimeException $e) { $errores[] = $e->getMessage(); }
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

            // Si vino "residente nuevo" con texto, créalo (prioridad sobre el select)
            // v3AX: usa el tipo elegible en vez de hardcoded 'inquilino'
            if ($r_nuevo_nombre !== '') {
                $ins = $pdo->prepare("INSERT INTO residentes
                        (apartamento_id, nombre, celular, tipo, vive_en_apto, activo)
                    VALUES (:a, :n, :c, :tipo, 1, 1)");
                $ins->execute([
                    ':a'    => (int)$apto['id'],
                    ':n'    => $r_nuevo_nombre,
                    ':c'    => $r_nuevo_celular ?: null,
                    ':tipo' => $tipoResNuevo,
                ]);
                $residenteId = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare("
                INSERT INTO vehiculos
                    (conjunto_id, apartamento_id, residente_id, placa, tipo,
                     marca, linea, color, modelo_anio, observaciones, foto_principal, activo)
                VALUES (:c, :a, :r, :p, :t, :m, :l, :co, :an, :ob, :fp, 1)
            ");
            $ins->execute([
                ':c'  => $conjuntoId, ':a' => (int)$apto['id'], ':r' => $residenteId,
                ':p'  => $placa, ':t' => $tipo,
                ':m'  => $marca ?: null, ':l' => $linea ?: null, ':co' => $color ?: null,
                ':an' => $anio, ':ob' => $obs ?: null, ':fp' => $fotoRel,
            ]);
            $vehId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO audit_log
                    (conjunto_id, usuario_id, accion, entidad, entidad_id, descripcion)
                 VALUES (:c, :u, 'crear', 'vehiculo', :id, :d)")
                ->execute([
                    ':c' => $conjuntoId, ':u' => $u['id'], ':id' => $vehId,
                    ':d' => "Creó vehículo {$placa} para apto {$apto['numero_visible']}"
                            . ($r_nuevo_nombre !== '' ? " (residente nuevo: {$r_nuevo_nombre} · {$tipoResNuevo})" : ''),
                ]);

            $pdo->commit();
            flash_set('ok', "Vehículo {$placa} registrado.");
            redirect('/vehiculos/ver?id=' . $vehId);
        } catch (Exception $ex) {
            $pdo->rollBack();
            if ($fotoRel) eliminar_foto($fotoRel);
            $errores[] = APP_DEBUG ? $ex->getMessage() : 'Error al guardar el vehículo.';
        }
    }
}

$_pageTitle = 'Nuevo vehículo';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">Nuevo vehículo</h1>
    <p class="page-head__sub">Registrar un vehículo y asociarlo a un apartamento.</p>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <strong>No se pudo guardar:</strong>
        <ul style="margin:6px 0 0 18px">
            <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
$action      = url('/vehiculos/crear');
$submitLabel = 'Crear vehículo';
$vehiculo    = null;
include __DIR__ . '/_form.php';
?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
