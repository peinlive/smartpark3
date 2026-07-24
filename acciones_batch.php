<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/acciones_batch.php
// v3k.1: HOTFIX CSRF. El sistema espera $_POST['_csrf'] pero el JS del index.php
//        envía 'csrf_token'. Aliasing defensivo antes de llamar csrf_require().
//        Acepta también _token y header X-CSRF-Token (uso futuro AJAX).
//
// Acciones: archivar | restaurar | eliminar. Eliminar es DELETE definitivo.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/vehiculos');

// ───── HOTFIX CSRF: alias del campo si viene con otro nombre ─────
if (empty($_POST['_csrf'])) {
    if      (!empty($_POST['csrf_token']))            $_POST['_csrf'] = $_POST['csrf_token'];
    elseif  (!empty($_POST['_token']))                $_POST['_csrf'] = $_POST['_token'];
    elseif  (!empty($_SERVER['HTTP_X_CSRF_TOKEN']))   $_POST['_csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
}
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$accion    = $_POST['accion'] ?? '';
$seleccion = (array)($_POST['seleccion'] ?? []);

if (!in_array($accion, ['archivar', 'restaurar', 'eliminar'], true)) {
    if (function_exists('flash_set')) flash_set('error', 'Acción no válida.');
    redirect('/vehiculos');
}

// v7.62: el rol RONDA puede crear/editar/asignar, pero NO eliminar.
if ($accion === 'eliminar') {
    $__u = function_exists('auth_user') ? auth_user() : null;
    $__roles = $__u['roles'] ?? [];
    $__soloRonda = in_array('ronda', $__roles, true)
        && !array_intersect($__roles, ['super_admin', 'admin', 'supervisor']);
    if ($__soloRonda) {
        if (function_exists('flash_set')) flash_set('error', 'Tu rol no puede eliminar registros.');
        redirect('/vehiculos');
    }
}

if (empty($seleccion)) {
    if (function_exists('flash_set')) flash_set('error', 'No seleccionaste ningún vehículo.');
    redirect('/vehiculos');
}

// Parsear "origen:id"
$idsRes = [];
$idsVis = [];
foreach ($seleccion as $item) {
    if (!is_string($item)) continue;
    if (!preg_match('/^(residente|visitante):(\d+)$/', $item, $m)) continue;
    $id = (int)$m[2];
    if ($id < 1) continue;
    if ($m[1] === 'residente') $idsRes[] = $id;
    else                       $idsVis[] = $id;
}

$total = count($idsRes) + count($idsVis);
if ($total === 0) {
    if (function_exists('flash_set')) flash_set('error', 'No se seleccionaron vehículos válidos.');
    redirect('/vehiculos');
}

$afectados = 0;
$msg = '';

try {
    $pdo->beginTransaction();

    if ($accion === 'archivar') {
        if (!empty($idsRes)) {
            $inList = implode(',', array_map('intval', $idsRes));
            $afectados += (int)$pdo->exec(
                "UPDATE vehiculos
                    SET archivado_en = NOW(),
                        archivado_motivo = COALESCE(archivado_motivo, 'Archivado manual')
                  WHERE id IN ($inList)
                    AND conjunto_id = $conjuntoId
                    AND archivado_en IS NULL"
            );
        }
        if (!empty($idsVis)) {
            $inList = implode(',', array_map('intval', $idsVis));
            $afectados += (int)$pdo->exec(
                "UPDATE visitantes_vehiculos
                    SET archivado_en = NOW()
                  WHERE id IN ($inList)
                    AND conjunto_id = $conjuntoId
                    AND archivado_en IS NULL"
            );
        }
        $msg = "Se archivaron $afectados vehículo(s).";
    }
    elseif ($accion === 'restaurar') {
        if (!empty($idsRes)) {
            $inList = implode(',', array_map('intval', $idsRes));
            $afectados += (int)$pdo->exec(
                "UPDATE vehiculos
                    SET archivado_en = NULL,
                        archivado_motivo = NULL
                  WHERE id IN ($inList)
                    AND conjunto_id = $conjuntoId
                    AND archivado_en IS NOT NULL"
            );
        }
        if (!empty($idsVis)) {
            $inList = implode(',', array_map('intval', $idsVis));
            $afectados += (int)$pdo->exec(
                "UPDATE visitantes_vehiculos
                    SET archivado_en = NULL
                  WHERE id IN ($inList)
                    AND conjunto_id = $conjuntoId
                    AND archivado_en IS NOT NULL"
            );
        }
        $msg = "Se restauraron $afectados vehículo(s).";
    }
    elseif ($accion === 'eliminar') {
        if (!empty($idsRes)) {
            $inList = implode(',', array_map('intval', $idsRes));
            $afectados += (int)$pdo->exec(
                "DELETE FROM vehiculos
                  WHERE id IN ($inList)
                    AND conjunto_id = $conjuntoId"
            );
        }
        if (!empty($idsVis)) {
            $inList = implode(',', array_map('intval', $idsVis));
            $afectados += (int)$pdo->exec(
                "DELETE FROM visitantes_vehiculos
                  WHERE id IN ($inList)
                    AND conjunto_id = $conjuntoId"
            );
        }
        $msg = "Se eliminaron PERMANENTEMENTE $afectados vehículo(s) de la BD.";
    }

    $pdo->commit();
    if (function_exists('flash_set')) flash_set('success', $msg);
}
catch (Exception $e) {
    $pdo->rollBack();
    $errMsg = (defined('APP_DEBUG') && APP_DEBUG)
        ? ('Error: ' . $e->getMessage())
        : 'Error al ejecutar la acción. No se hizo ningún cambio.';
    if (function_exists('flash_set')) flash_set('error', $errMsg);
}

$retorno = $_POST['return_url'] ?? '/vehiculos';
if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
    $retorno = '/vehiculos';
}
redirect($retorno);
