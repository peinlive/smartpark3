<?php
// /home/myzonaco/smartpark.myzona360.com/modules/observaciones/eliminar.php
// v1.0 (3V): Eliminar una observación.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/observaciones');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) redirect('/observaciones');

// Validar que la observación pertenece a un vehículo del conjunto
$st = $pdo->prepare("SELECT o.id FROM observaciones_vehiculo o
                     JOIN vehiculos v ON v.id = o.vehiculo_id
                    WHERE o.id = :id AND v.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
if (!$st->fetchColumn()) { flash_set('error', 'Observación no encontrada.'); redirect('/observaciones'); }

try {
    $pdo->prepare("DELETE FROM observaciones_vehiculo WHERE id = :id")->execute([':id' => $id]);
    flash_set('ok', 'Observación eliminada.');
} catch (Exception $ex) {
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar.');
}

$retorno = $_POST['return_url'] ?? '/observaciones';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') $retorno = '/observaciones';
redirect($retorno);
