<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/eliminar_evidencia.php
// v1.0 (3AH): Eliminar una evidencia adicional (foto/video) sin borrar la observación.
//   Solo super_admin. Borra el registro en observaciones_evidencias y el archivo físico.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$evId = (int)($_POST['evidencia_id'] ?? 0);
if ($evId < 1) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

// Validar que la evidencia pertenece a una observación de un vehículo del conjunto
try {
    $stCk = $pdo->prepare("SELECT e.id, e.archivo_url, e.tipo, e.observacion_id
                             FROM observaciones_evidencias e
                             JOIN observaciones_vehiculo o ON o.id = e.observacion_id
                             JOIN vehiculos v ON v.id = o.vehiculo_id
                            WHERE e.id = :id AND v.conjunto_id = :c LIMIT 1");
    $stCk->execute([':id' => $evId, ':c' => $conjuntoId]);
    $ev = $stCk->fetch();
    if (!$ev) { echo json_encode(['ok'=>false,'error'=>'Evidencia no encontrada']); exit; }

    // Eliminar el registro
    $stD = $pdo->prepare("DELETE FROM observaciones_evidencias WHERE id = :id");
    $stD->execute([':id' => $evId]);

    // v3AJ: audit_log
    if (function_exists('audit_log')) {
        audit_log('delete_evidencia', 'observaciones_evidencias', $evId,
                  'Eliminó ' . $ev['tipo'] . ' de observación #' . $ev['observacion_id'],
                  $ev, null);
    }

    // Eliminar archivo físico (best-effort, con protección path-traversal)
    $baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';
    $rel = $ev['archivo_url'];
    if (preg_match('#/uploads/(.+)$#', $rel, $m)) $rel = $m[1];
    $rutaFis = $baseUploads . '/' . ltrim($rel, '/');
    if (is_file($rutaFis)) {
        $realBase = realpath($baseUploads);
        $realFile = realpath($rutaFis);
        if ($realBase && $realFile && strpos($realFile, $realBase) === 0) {
            @unlink($rutaFis);
        }
    }

    echo json_encode(['ok' => true]);
} catch (Exception $ex) {
    echo json_encode(['ok'=>false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar']);
}
