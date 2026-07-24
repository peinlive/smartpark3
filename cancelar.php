<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/cancelar.php
// v1.0: Cancela una revista en curso.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/revistas');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) redirect('/revistas');

try {
    $up = $pdo->prepare("UPDATE revistas SET
            estado = 'cancelada',
            terminado_en = NOW()
        WHERE id = :id AND conjunto_id = :c AND estado = 'en_curso'");
    $up->execute([':id' => $id, ':c' => $conjuntoId]);

    if ($up->rowCount() > 0) {
        if (function_exists('flash_set')) flash_set('ok', "Revista #{$id} cancelada.");
    } else {
        if (function_exists('flash_set')) flash_set('warn', "La revista no estaba en curso.");
    }
} catch (Exception $ex) {
    if (function_exists('flash_set')) {
        flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al cancelar.');
    }
}

$retorno = $_POST['return_url'] ?? '/revistas';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
    $retorno = '/revistas';
}
redirect($retorno);
