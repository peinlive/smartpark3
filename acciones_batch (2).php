<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/acciones_batch.php
// v3q: acciones masivas sobre cuartos útiles.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/cuartos');

if (empty($_POST['_csrf'])) {
    if      (!empty($_POST['csrf_token']))            $_POST['_csrf'] = $_POST['csrf_token'];
    elseif  (!empty($_SERVER['HTTP_X_CSRF_TOKEN']))   $_POST['_csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
}
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$accion    = $_POST['accion'] ?? '';
$seleccion = (array)($_POST['seleccion'] ?? []);

if (!in_array($accion, ['desactivar','activar','eliminar'], true)) {
    if (function_exists('flash_set')) flash_set('error', 'Acción no válida.');
    redirect('/cuartos');
}

// v7.62: el rol RONDA puede crear/editar/asignar, pero NO eliminar.
if ($accion === 'eliminar') {
    $__u = function_exists('auth_user') ? auth_user() : null;
    $__roles = $__u['roles'] ?? [];
    $__soloRonda = in_array('ronda', $__roles, true)
        && !array_intersect($__roles, ['super_admin', 'admin', 'supervisor']);
    if ($__soloRonda) {
        if (function_exists('flash_set')) flash_set('error', 'Tu rol no puede eliminar registros.');
        redirect('/cuartos');
    }
}

if (empty($seleccion)) {
    if (function_exists('flash_set')) flash_set('error', 'No seleccionaste ningún cuarto.');
    redirect('/cuartos');
}

$ids = [];
foreach ($seleccion as $item) {
    if (is_array($item)) continue;
    $id = (int)$item;
    if ($id >= 1) $ids[] = $id;
}
if (empty($ids)) {
    if (function_exists('flash_set')) flash_set('error', 'IDs inválidos.');
    redirect('/cuartos');
}

$inList = implode(',', array_map('intval', $ids));
$afectados = 0;
$msg = '';

try {
    $pdo->beginTransaction();
    if ($accion === 'desactivar') {
        $afectados = (int)$pdo->exec("UPDATE cuartos_utiles SET activo = 0
                                       WHERE id IN ($inList) AND conjunto_id = $conjuntoId AND activo = 1");
        $msg = "Se desactivaron $afectados cuarto(s).";
    } elseif ($accion === 'activar') {
        $afectados = (int)$pdo->exec("UPDATE cuartos_utiles SET activo = 1
                                       WHERE id IN ($inList) AND conjunto_id = $conjuntoId AND activo = 0");
        $msg = "Se activaron $afectados cuarto(s).";
    } elseif ($accion === 'eliminar') {
        $afectados = (int)$pdo->exec("DELETE FROM cuartos_utiles
                                       WHERE id IN ($inList) AND conjunto_id = $conjuntoId");
        $msg = "Se eliminaron PERMANENTEMENTE $afectados cuarto(s).";
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

$retorno = $_POST['return_url'] ?? '/cuartos';
if (!is_string($retorno) || strlen($retorno) === 0 || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
    $retorno = '/cuartos';
}
redirect($retorno);
