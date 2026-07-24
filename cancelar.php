<?php
// /home/myzonaco/smartpark.myzona360.com/modules/asignaciones/cancelar.php
// v3n: cancela (activa=0) una asignación. Solo si era activa.
//      NO la elimina de la BD para conservar historial.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/asignaciones');

if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) {
    if (function_exists('flash_set')) flash_set('error', 'ID inválido.');
    redirect('/asignaciones');
}

try {
    // Validar que la asignación pertenezca al conjunto
    $st = $pdo->prepare("SELECT asg.id FROM asignaciones_celdas asg
                            JOIN celdas c ON c.id = asg.celda_id
                           WHERE asg.id = :id AND c.conjunto_id = :c AND asg.activa = 1 LIMIT 1");
    $st->execute([':id' => $id, ':c' => $conjuntoId]);
    if (!$st->fetchColumn()) {
        if (function_exists('flash_set')) flash_set('error', 'La asignación no existe o ya está cancelada.');
        redirect('/asignaciones');
    }

    $pdo->prepare("UPDATE asignaciones_celdas SET activa = 0 WHERE id = :id")
        ->execute([':id' => $id]);

    if (function_exists('flash_set')) flash_set('ok', 'Asignación cancelada.');
}
catch (Exception $e) {
    if (function_exists('flash_set')) flash_set('error',
        (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Error al cancelar.');
}

redirect('/asignaciones');
