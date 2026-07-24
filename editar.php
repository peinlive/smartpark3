<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/editar.php (v3AX)
//   v3AX: al crear un residente nuevo desde editar, permite elegir el TIPO
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

$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/vehiculos'); }

$st = $pdo->prepare("
    SELECT v.*, a.numero_visible AS apto_numero
      FROM vehiculos v
      JOIN apartamentos a ON a.id = v.apartamento_id
     WHERE v.id = :id AND v.conjunto_id = :c LIMIT 1
");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$vehiculo = $st->fetch();
if (!$vehiculo) { flash_set('error', 'Vehículo no encontrado.'); redirect('/vehiculos'); }
if ($vehiculo['archivado_en']) {
    flash_set('warn', 'Este vehículo está archivado. Restáuralo primero para editar.');
    redirect('/vehiculos/ver?id=' . $id);
}

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

    $residente_id_sel = clean_int($_POST['residente_id'] ?? null, 1);
    $r_nuevo_nombre   = clean_string($_POST['residente_nuevo_nombre']  ?? '', 150);
    $r_nuevo_celular  = normalizar_celular(clean_string($_POST['residente_nuevo_celular'] ?? '', 30));

    // v3AX/BG: tipo elegible desde el formulario. Fallback: inquilino
    //   v3BG: si eligen "visitante", ese NO se guarda en residentes (los visitantes
    //         viven en tabla visitantes_vehiculos con lógica distinta).
    //         Se muestra aviso y redirige a /vehiculos/mover_visitante.
    $tipoResNuevo = in_array($_POST['residente_tipo_nuevo'] ?? '', ['inquilino','propietario','visitante'], true)
        ? $_POST['residente_tipo_nuevo']
        : 'inquilino';

    // v3BG: si eligió visitante Y no está creando residente nuevo con nombre → redirigir
    if ($tipoResNuevo === 'visitante' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Guardar el resto de cambios primero SIN tocar el tipo, luego redirigir
        // Pero para no complicar: informar y redirigir directo al mover.
        flash_set('warn', 'Los visitantes tienen su propio módulo. Usa "Mover a visitantes" para migrar este vehículo.');
        redirect('/vehiculos/mover_visitante?id=' . $id);
    }

    // v3AX: si es visitante y no hay nombre, generar uno automático (solo si NO redirigió arriba)
    // (Ya no aplica aquí porque redirigimos, pero lo dejamos por seguridad)
    if ($tipoResNuevo === 'visitante' && $r_nuevo_nombre === '' && $apto_num !== '') {
        $r_nuevo_nombre = 'Visitante Apto ' . $apto_num;
    }

    if ($placa === '' || strlen($placa) < 4) $errores[] = 'Placa inválida.';
    if ($apto_num === '')                    $errores[] = 'Apartamento obligatorio.';

    $apto = null;
    if ($apto_num !== '') {
        $sa = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
        $sa->execute([':c' => $conjuntoId, ':n' => $apto_num]);
        $apto = $sa->fetch();
        if (!$apto) $errores[] = "El apartamento '{$apto_num}' no existe.";
    }

    if ($placa !== '') {
        $sp = $pdo->prepare("SELECT id FROM vehiculos
                              WHERE conjunto_id = :c AND placa = :p AND id <> :id AND archivado_en IS NULL LIMIT 1");
        $sp->execute([':c' => $conjuntoId, ':p' => $placa, ':id' => $id]);
        if ($sp->fetchColumn()) $errores[] = "Ya existe otro vehículo activo con la placa '{$placa}'.";
    }

    $residenteId = null;
    if ($apto && $r_nuevo_nombre === '' && $residente_id_sel !== null) {
        $sr = $pdo->prepare("SELECT id FROM residentes
                              WHERE id = :r AND apartamento_id = :a AND archivado_en IS NULL");
        $sr->execute([':r' => $residente_id_sel, ':a' => (int)$apto['id']]);
        if (!$sr->fetchColumn()) {
            $errores[] = 'El residente seleccionado no pertenece a este apartamento.';
        } else {
            $residenteId = $residente_id_sel;
        }
    }

    $fotoNueva = null;
    if (empty($errores)) {
        try { $fotoNueva = upload_foto_vehiculo($_FILES['foto'] ?? [], 'vehiculos'); }
        catch (RuntimeException $e) { $errores[] = $e->getMessage(); }
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

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

            // v3BF: si el vehículo YA tiene residente vinculado y NO se está creando uno nuevo,
            //       y el radio del tipo trae un valor distinto al tipo actual del residente
            //       → ACTUALIZAR el tipo del residente vinculado.
            //       Esto replica la lógica del importador: al reimportar con tipo distinto,
            //       el residente queda con el tipo actualizado.
            //       Solo se hace si el usuario tocó el radio (residente_tipo_nuevo != vacío).
            $tipoRadioEnviado = $_POST['residente_tipo_nuevo'] ?? '';
            if ($residenteId
                && $r_nuevo_nombre === ''
                && $tipoRadioEnviado !== ''
                && in_array($tipoRadioEnviado, ['inquilino','propietario','visitante','familiar','otro'], true)) {
                // Buscar el tipo actual del residente
                $stCurTipo = $pdo->prepare("SELECT tipo FROM residentes WHERE id = :r LIMIT 1");
                $stCurTipo->execute([':r' => (int)$residenteId]);
                $tipoActual = (string)$stCurTipo->fetchColumn();

                if ($tipoActual !== '' && $tipoActual !== $tipoRadioEnviado) {
                    $pdo->prepare("UPDATE residentes SET tipo = :t WHERE id = :r")
                        ->execute([':t' => $tipoRadioEnviado, ':r' => (int)$residenteId]);
                    // audit_log defensivo
                    if (function_exists('audit_log')) {
                        audit_log('cambiar_tipo_residente', 'residentes', (int)$residenteId,
                                  "Cambió tipo de residente #{$residenteId} de '{$tipoActual}' → '{$tipoRadioEnviado}' desde /vehiculos/editar del vehículo #{$id}",
                                  ['tipo' => $tipoActual], ['tipo' => $tipoRadioEnviado]);
                    }
                }
            }

            $fotoFinal = $fotoNueva ?: $vehiculo['foto_principal'];

            $up = $pdo->prepare("
                UPDATE vehiculos SET
                    apartamento_id = :a, residente_id = :r,
                    placa = :p, tipo = :t,
                    marca = :m, linea = :l, color = :co, modelo_anio = :an,
                    observaciones = :ob, foto_principal = :fp
                WHERE id = :id AND conjunto_id = :c
            ");
            $up->execute([
                ':a'  => (int)$apto['id'], ':r' => $residenteId,
                ':p'  => $placa, ':t' => $tipo,
                ':m'  => $marca ?: null, ':l' => $linea ?: null, ':co' => $color ?: null,
                ':an' => $anio, ':ob' => $obs ?: null, ':fp' => $fotoFinal,
                ':id' => $id, ':c' => $conjuntoId,
            ]);

            if ($fotoNueva && !empty($vehiculo['foto_principal']) && $vehiculo['foto_principal'] !== $fotoNueva) {
                eliminar_foto($vehiculo['foto_principal']);
            }

            $pdo->prepare("INSERT INTO audit_log
                    (conjunto_id, usuario_id, accion, entidad, entidad_id, descripcion)
                 VALUES (:c, :u, 'editar', 'vehiculo', :id, :d)")
                ->execute([
                    ':c' => $conjuntoId, ':u' => $u['id'], ':id' => $id,
                    ':d' => "Editó vehículo {$placa}"
                            . ($r_nuevo_nombre !== '' ? " (residente nuevo: {$r_nuevo_nombre} · {$tipoResNuevo})" : ''),
                ]);

            $pdo->commit();
            flash_set('ok', "Vehículo {$placa} actualizado.");
            // v3o: si vino con ?return= o return_url, volver allí (mantiene filtros)
            $retorno = $_POST['return_url'] ?? ($_GET['return'] ?? '/vehiculos/ver?id=' . $id);
            if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
                $retorno = '/vehiculos/ver?id=' . $id;
            }
            redirect($retorno);
        } catch (Exception $ex) {
            $pdo->rollBack();
            if ($fotoNueva) eliminar_foto($fotoNueva);
            $errores[] = APP_DEBUG ? $ex->getMessage() : 'Error al actualizar.';
        }
    }
}

$_pageTitle = 'Editar vehículo ' . $vehiculo['placa'];
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">Editar vehículo</h1>
    <p class="page-head__sub">Placa <strong><?= e($vehiculo['placa']) ?></strong></p>
</div>

<?php if (!empty($_GET['return'])): ?>
    <div class="toolbar">
        <a class="btn" href="<?= e($_GET['return']) ?>">← Volver a la lista</a>
        <!-- v3BG: Botón mover a visitantes -->
        <a class="btn" href="<?= url('/vehiculos/mover_visitante?id=' . $id) ?>"
           style="background:#ede9fe;color:#5b21b6;margin-left:auto"
           onclick="return confirm('¿Mover este vehículo a la tabla de visitantes?\n\nSe usa cuando el vehículo NO pertenece a un residente sino a alguien que visita el apto (parientes, invitados, etc).\n\nEl vehículo actual quedará archivado y se creará el registro en visitantes.');">
            👥 Mover a visitantes
        </a>
    </div>
<?php else: ?>
    <!-- v3BG: Botón mover a visitantes (sin lista de retorno) -->
    <div class="toolbar">
        <a class="btn" href="<?= url('/vehiculos') ?>">← Volver</a>
        <a class="btn" href="<?= url('/vehiculos/mover_visitante?id=' . $id) ?>"
           style="background:#ede9fe;color:#5b21b6;margin-left:auto"
           onclick="return confirm('¿Mover este vehículo a la tabla de visitantes?\n\nSe usa cuando el vehículo NO pertenece a un residente sino a alguien que visita el apto (parientes, invitados, etc).\n\nEl vehículo actual quedará archivado y se creará el registro en visitantes.');">
            👥 Mover a visitantes
        </a>
    </div>
<?php endif; ?>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px">
            <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
$action      = url('/vehiculos/editar?id=' . $id);
if (!empty($_GET['return'])) $action .= '&return=' . urlencode($_GET['return']);
$submitLabel = 'Guardar cambios';
include __DIR__ . '/_form.php';
?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
