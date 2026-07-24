<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/api_actualizar_apto.php
// v1.0 (3U.1): Actualiza el apto dueño de un vehículo existente.
//              Recibe: vehiculo_id, apto (numero_visible; vacío = desvincular).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método']); exit; }
if (!csrf_check())                          { echo json_encode(['ok'=>false,'error'=>'CSRF']);    exit; }

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$vehiculoId = (int)($_POST['vehiculo_id'] ?? 0);
$aptoNum    = clean_string($_POST['apto'] ?? '', 20);

if ($vehiculoId < 1) { echo json_encode(['ok'=>false,'error'=>'vehiculo_id inválido']); exit; }

// Validar que el vehículo existe y es del conjunto
$stV = $pdo->prepare("SELECT id FROM vehiculos WHERE id = :v AND conjunto_id = :c LIMIT 1");
$stV->execute([':v' => $vehiculoId, ':c' => $conjuntoId]);
if (!$stV->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Vehículo no encontrado']); exit; }

$aptoId = null;
if ($aptoNum !== '') {
    $stA = $pdo->prepare("SELECT id FROM apartamentos WHERE numero_visible = :n AND conjunto_id = :c LIMIT 1");
    $stA->execute([':n' => $aptoNum, ':c' => $conjuntoId]);
    $aptoId = (int)$stA->fetchColumn();
    if (!$aptoId) { echo json_encode(['ok'=>false,'error'=>"Apto {$aptoNum} no existe"]); exit; }
}

try {
    $pdo->prepare("UPDATE vehiculos SET apartamento_id = :a WHERE id = :v AND conjunto_id = :c")
        ->execute([':a' => $aptoId, ':v' => $vehiculoId, ':c' => $conjuntoId]);
    echo json_encode(['ok' => true, 'apto' => $aptoNum]);
} catch (Exception $ex) {
    echo json_encode(['ok' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al actualizar']);
}
