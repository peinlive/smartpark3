<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/acciones_batch.php
// v3n: acciones individuales o masivas sobre celdas.
//      Como la tabla `celdas` usa columna `activa` (no archivado_en):
//        - desactivar = activa = 0  (equivale a "archivar")
//        - activar    = activa = 1  (equivale a "restaurar")
//        - eliminar   = DELETE definitivo

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/parqueadero');

// CSRF flexible (acepta _csrf, csrf_token, header)
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

if (!in_array($accion, ['desactivar', 'activar', 'eliminar'], true)) {
    if (function_exists('flash_set')) flash_set('error', 'Acción no válida.');
    redirect('/parqueadero');
}

// v7.62: el rol RONDA puede crear/editar/asignar, pero NO eliminar.
if ($accion === 'eliminar') {
    $__u = function_exists('auth_user') ? auth_user() : null;
    $__roles = $__u['roles'] ?? [];
    $__soloRonda = in_array('ronda', $__roles, true)
        && !array_intersect($__roles, ['super_admin', 'admin', 'supervisor']);
    if ($__soloRonda) {
        if (function_exists('flash_set')) flash_set('error', 'Tu rol no puede eliminar registros.');
        redirect('/parqueadero');
    }
}

if (empty($seleccion)) {
    if (function_exists('flash_set')) flash_set('error', 'No seleccionaste ninguna celda.');
    redirect('/parqueadero');
}

$ids = [];
foreach ($seleccion as $item) {
    if (is_array($item)) continue;
    $id = (int)$item;
    if ($id >= 1) $ids[] = $id;
}

if (empty($ids)) {
    if (function_exists('flash_set')) flash_set('error', 'No se seleccionaron celdas válidas.');
    redirect('/parqueadero');
}

$inList = implode(',', array_map('intval', $ids));
$afectados = 0;
$msg = '';

try {
    $pdo->beginTransaction();

    if ($accion === 'desactivar') {
        $afectados = (int)$pdo->exec(
            "UPDATE celdas SET activa = 0
              WHERE id IN ($inList) AND conjunto_id = $conjuntoId AND activa = 1"
        );
        $msg = "Se desactivaron $afectados celda(s).";
    }
    elseif ($accion === 'activar') {
        $afectados = (int)$pdo->exec(
            "UPDATE celdas SET activa = 1
              WHERE id IN ($inList) AND conjunto_id = $conjuntoId AND activa = 0"
        );
        $msg = "Se activaron $afectados celda(s).";
    }
    elseif ($accion === 'eliminar') {
        // Eliminación DEFINITIVA. CASCADE elimina sus asignaciones_celdas.
        $afectados = (int)$pdo->exec(
            "DELETE FROM celdas WHERE id IN ($inList) AND conjunto_id = $conjuntoId"
        );
        $msg = "Se eliminaron PERMANENTEMENTE $afectados celda(s) de la BD.";
    }

    $pdo->commit();
    if (function_exists('flash_set')) flash_set('ok', $msg);
}
catch (Exception $e) {
    $pdo->rollBack();
    $errMsg = (defined('APP_DEBUG') && APP_DEBUG)
        ? ('Error: ' . $e->getMessage())
        : 'Error al ejecutar la acción. No se hizo ningún cambio.';
    if (function_exists('flash_set')) flash_set('error', $errMsg);
}

$retorno = $_POST['return_url'] ?? '/parqueadero';
if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
    $retorno = '/parqueadero';
}
redirect($retorno);
