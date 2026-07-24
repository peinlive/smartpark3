<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/eliminar_observacion.php
// v1.0 (3AD): Elimina una observación y sus evidencias adicionales (solo super_admin).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$obsId = (int)($_POST['obs_id'] ?? 0);
if ($obsId < 1) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

// Validar que la observación pertenece al conjunto del usuario
$stCk = $pdo->prepare("SELECT o.id, o.tipo, o.gravedad, o.descripcion, o.evidencia_url,
                              o.vehiculo_id, o.creado_en, v.placa
                         FROM observaciones_vehiculo o
                         JOIN vehiculos v ON v.id = o.vehiculo_id
                        WHERE o.id = :id AND v.conjunto_id = :c LIMIT 1");
$stCk->execute([':id' => $obsId, ':c' => $conjuntoId]);
$obs = $stCk->fetch();
if (!$obs) { echo json_encode(['ok'=>false,'error'=>'Observación no encontrada']); exit; }

$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';

try {
    // Traer evidencias adicionales para borrar archivos (si la tabla existe)
    $archivos = [];
    try {
        $stE = $pdo->prepare("SELECT archivo_url FROM observaciones_evidencias WHERE observacion_id = :o");
        $stE->execute([':o' => $obsId]);
        foreach ($stE->fetchAll() as $e) $archivos[] = $e['archivo_url'];
        // Eliminar registros
        $stDE = $pdo->prepare("DELETE FROM observaciones_evidencias WHERE observacion_id = :o");
        $stDE->execute([':o' => $obsId]);
    } catch (Exception $ex) { /* tabla no existe todavía */ }

    // Eliminar observación
    $stD = $pdo->prepare("DELETE FROM observaciones_vehiculo WHERE id = :id");
    $stD->execute([':id' => $obsId]);

    // Borrar archivos físicos (best-effort)
    if (!empty($obs['evidencia_url'])) $archivos[] = $obs['evidencia_url'];
    foreach ($archivos as $rel) {
        if (preg_match('#/uploads/(.+)$#', $rel, $m)) $rel = $m[1];
        $rutaFis = $baseUploads . '/' . ltrim($rel, '/');
        if (is_file($rutaFis) && strpos(realpath($rutaFis), realpath($baseUploads)) === 0) {
            @unlink($rutaFis);
        }
    }

    // v3AJ: audit_log
    if (function_exists('audit_log')) {
        audit_log('delete_observacion', 'observaciones_vehiculo', $obsId,
                  'Eliminó observación de placa ' . ($obs['placa'] ?? '?') .
                  ' (' . count($archivos) . ' archivo(s) físico(s) borrado(s))',
                  $obs, null);
    }

    echo json_encode(['ok' => true]);
} catch (Exception $ex) {
    echo json_encode(['ok'=>false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar']);
}
