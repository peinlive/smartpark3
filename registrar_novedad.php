<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/registrar_novedad.php
// v1.0 (3AB): Registrar una novedad/observación de vehículo desde la consulta rápida.
//   Aditivo: no modifica el módulo /observaciones existente.
//   Solo inserta en la tabla `observaciones_vehiculo`.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');   // v7.70: +ronda (registra novedades en la revista)

$formatoJson = isset($_POST['formato']) && $_POST['formato'] === 'json';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($formatoJson) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
    redirect('/consultas');
}
csrf_require();

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$vehiculoId  = (int)($_POST['vehiculo_id'] ?? 0);
$tipo        = in_array($_POST['tipo'] ?? '', ['mal_parqueo','advertencia','reincidencia','queja','otro'], true) ? $_POST['tipo'] : 'mal_parqueo';
$gravedad    = in_array($_POST['gravedad'] ?? '', ['ninguna','leve','media','grave'], true) ? $_POST['gravedad'] : 'ninguna'; // v6.6: +ninguna, default
$descripcion = clean_string($_POST['descripcion'] ?? '', 1000);
$fotoOcr     = clean_string($_POST['foto_ocr'] ?? '', 500);

$respuesta = ['ok' => false];

if ($vehiculoId < 1)       { $respuesta['error'] = 'Vehículo inválido'; }
elseif ($descripcion === ''){ $respuesta['error'] = 'La descripción es obligatoria'; }
else {
    // Validar que el vehículo pertenece al conjunto del usuario
    $stCk = $pdo->prepare("SELECT id FROM vehiculos WHERE id = :id AND conjunto_id = :c LIMIT 1");
    $stCk->execute([':id' => $vehiculoId, ':c' => $conjuntoId]);
    if (!$stCk->fetchColumn()) {
        $respuesta['error'] = 'El vehículo no pertenece a tu conjunto';
    } else {
        try {
            $ins = $pdo->prepare("INSERT INTO observaciones_vehiculo
                    (vehiculo_id, tipo, gravedad, descripcion, evidencia_url, usuario_registra)
                VALUES (:v, :tp, :gr, :ds, :ev, :us)");
            $ins->execute([
                ':v'  => $vehiculoId,
                ':tp' => $tipo,
                ':gr' => $gravedad,
                ':ds' => $descripcion,
                ':ev' => $fotoOcr !== '' ? $fotoOcr : null,
                ':us' => $uid ?: null,
            ]);
            $newObsId = (int)$pdo->lastInsertId();
            $respuesta = ['ok' => true, 'id' => $newObsId];
            flash_set('ok', 'Novedad registrada.');

            // v3AJ: audit_log
            if (function_exists('audit_log')) {
                audit_log('create_observacion', 'observaciones_vehiculo', $newObsId,
                          'Registró novedad tipo ' . $tipo . ' (' . $gravedad . ') a vehículo #' . $vehiculoId,
                          null,
                          ['vehiculo_id' => $vehiculoId, 'tipo' => $tipo, 'gravedad' => $gravedad,
                           'descripcion' => $descripcion, 'con_foto' => (bool)$fotoOcr]);
            }
        } catch (Exception $ex) {
            $respuesta['error'] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al guardar';
        }
    }
}

if ($formatoJson) {
    header('Content-Type: application/json');
    echo json_encode($respuesta);
    exit;
}

if (!$respuesta['ok']) {
    flash_set('error', $respuesta['error'] ?? 'Error al guardar');
}

$retorno = $_POST['return_url'] ?? '/consultas';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') $retorno = '/consultas';
redirect($retorno);
