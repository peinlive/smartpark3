<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/registrar_pago.php
// v1.0 (3Z): Registrar o actualizar un pago de alquiler.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/reportes/alquileres');
csrf_require();

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$pagoId          = (int)($_POST['pago_id'] ?? 0);
$asignacionTipo  = $_POST['asignacion_tipo'] ?? '';
$asignacionId    = (int)($_POST['asignacion_id'] ?? 0);
$mes             = (int)($_POST['mes'] ?? 0);
$anio            = (int)($_POST['anio'] ?? 0);
$valorPagado     = (float)($_POST['valor_pagado'] ?? 0);
$fechaPago       = clean_string($_POST['fecha_pago'] ?? '', 10);
$referencia      = clean_string($_POST['referencia'] ?? '', 100);
$observacion     = clean_string($_POST['observacion'] ?? '', 500);

$errores = [];
if (!in_array($asignacionTipo, ['celda','cuarto'], true)) $errores[] = 'Tipo de asignación inválido.';
if ($asignacionId < 1) $errores[] = 'ID de asignación inválido.';
if ($mes < 1 || $mes > 12) $errores[] = 'Mes inválido.';
if ($anio < 2020 || $anio > 2099) $errores[] = 'Año inválido.';
if ($valorPagado <= 0) $errores[] = 'El valor pagado debe ser mayor a 0.';
if (!strtotime($fechaPago)) $errores[] = 'Fecha de pago inválida.';

if (!empty($errores)) { flash_set('error', implode(' · ', $errores)); redirect('/reportes/alquileres'); }

// Validar que la asignación existe y es del conjunto del usuario
$valorEsperado = 0;
if ($asignacionTipo === 'celda') {
    $stCheck = $pdo->prepare("SELECT ac.valor_mensual
                               FROM asignaciones_celdas ac
                               JOIN celdas c ON c.id = ac.celda_id
                              WHERE ac.id = :aid AND c.conjunto_id = :cid
                                AND ac.tipo = 'alquiler' LIMIT 1");
} else {
    $stCheck = $pdo->prepare("SELECT ac.valor_mensual
                               FROM asignaciones_cuartos ac
                               JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
                              WHERE ac.id = :aid AND cu.conjunto_id = :cid
                                AND ac.tipo = 'alquiler' LIMIT 1");
}
$stCheck->execute([':aid' => $asignacionId, ':cid' => $conjuntoId]);
$row = $stCheck->fetch();
if (!$row) { flash_set('error', 'Asignación no encontrada o no es tipo alquiler.'); redirect('/reportes/alquileres'); }
$valorEsperado = (float)$row['valor_mensual'];

try {
    if ($pagoId > 0) {
        // Actualizar pago existente (validando que sea del mismo conjunto)
        $stU = $pdo->prepare("UPDATE pagos_alquileres SET
                valor_pagado = :vp, fecha_pago = :fp,
                referencia = :ref, observacion = :obs
            WHERE id = :id AND conjunto_id = :cid");
        $stU->execute([
            ':vp'  => $valorPagado,
            ':fp'  => $fechaPago,
            ':ref' => $referencia !== '' ? $referencia : null,
            ':obs' => $observacion !== '' ? $observacion : null,
            ':id'  => $pagoId,
            ':cid' => $conjuntoId,
        ]);
        flash_set('ok', 'Pago actualizado.');
    } else {
        // Insertar (con UNIQUE KEY protegiendo duplicados)
        $stI = $pdo->prepare("INSERT INTO pagos_alquileres
                (conjunto_id, asignacion_tipo, asignacion_id, mes, anio,
                 valor_esperado, valor_pagado, fecha_pago, referencia, observacion, registrado_por)
            VALUES (:c, :tp, :aid, :m, :a, :ve, :vp, :fp, :ref, :obs, :ru)");
        $stI->execute([
            ':c'   => $conjuntoId,
            ':tp'  => $asignacionTipo,
            ':aid' => $asignacionId,
            ':m'   => $mes,
            ':a'   => $anio,
            ':ve'  => $valorEsperado,
            ':vp'  => $valorPagado,
            ':fp'  => $fechaPago,
            ':ref' => $referencia !== '' ? $referencia : null,
            ':obs' => $observacion !== '' ? $observacion : null,
            ':ru'  => $uid ?: null,
        ]);
        flash_set('ok', 'Pago registrado.');
    }
} catch (Exception $ex) {
    // Duplicate key error → ya existe un pago para ese mes
    if (strpos($ex->getMessage(), 'Duplicate') !== false || strpos($ex->getMessage(), '1062') !== false) {
        flash_set('error', 'Ya existe un pago registrado para este alquiler en ese mes/año. Edítalo en vez de crear uno nuevo.');
    } else {
        flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al guardar el pago.');
    }
}

$retorno = $_POST['return_url'] ?? '/reportes/alquileres';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') $retorno = '/reportes/alquileres';
redirect($retorno);
