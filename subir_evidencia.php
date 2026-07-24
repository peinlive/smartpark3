<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/subir_evidencia.php
// v1.0 (3AD): Subir foto o video adicional a una observación existente.
//   Usado tanto durante la creación (después de guardar) como después
//   desde el perfil del vehículo.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda'); // v4b: +ronda (hace revistas desde el sotano, sin señal)

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }
csrf_require();

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$obsId = (int)($_POST['obs_id'] ?? 0);
if ($obsId < 1) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

// Validar que la observación pertenece a un vehículo del conjunto
$stCk = $pdo->prepare("SELECT o.id FROM observaciones_vehiculo o
                        JOIN vehiculos v ON v.id = o.vehiculo_id
                       WHERE o.id = :id AND v.conjunto_id = :c LIMIT 1");
$stCk->execute([':id' => $obsId, ':c' => $conjuntoId]);
if (!$stCk->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Observación no encontrada']); exit; }

if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'No se recibió archivo']);
    exit;
}

$f = $_FILES['archivo'];
$mime = mime_content_type($f['tmp_name']) ?: 'application/octet-stream';
$tipoArchivo = null;
$extPermitidas = [];

if (strpos($mime, 'image/') === 0) {
    $tipoArchivo = 'foto';
    $extPermitidas = ['jpg','jpeg','png','webp'];
} elseif (strpos($mime, 'video/') === 0) {
    $tipoArchivo = 'video';
    $extPermitidas = ['mp4','webm','mov','3gp'];
} else {
    echo json_encode(['ok'=>false,'error'=>'Tipo de archivo no permitido']); exit;
}

// Límite de tamaño: 20 MB fotos, 50 MB videos
$maxBytes = $tipoArchivo === 'video' ? 50*1024*1024 : 20*1024*1024;
if ($f['size'] > $maxBytes) {
    echo json_encode(['ok'=>false,'error'=>'Archivo muy grande (máx '.($maxBytes/1024/1024).' MB)']); exit;
}

$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $extPermitidas, true)) {
    echo json_encode(['ok'=>false,'error'=>'Extensión no permitida']); exit;
}

$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';
$dirDest = $baseUploads . '/observaciones';
if (!is_dir($dirDest)) @mkdir($dirDest, 0755, true);

$nombreFinal = 'obs' . $obsId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$rutaFinal   = $dirDest . '/' . $nombreFinal;
$urlRel      = 'observaciones/' . $nombreFinal;

if (!@move_uploaded_file($f['tmp_name'], $rutaFinal)) {
    echo json_encode(['ok'=>false,'error'=>'No se pudo guardar el archivo']); exit;
}

try {
    $stI = $pdo->prepare("INSERT INTO observaciones_evidencias
            (observacion_id, tipo, archivo_url, mime, tamano_bytes, subido_por)
        VALUES (:o, :tp, :url, :m, :sz, :us)");
    $stI->execute([
        ':o'   => $obsId,
        ':tp'  => $tipoArchivo,
        ':url' => $urlRel,
        ':m'   => $mime,
        ':sz'  => (int)$f['size'],
        ':us'  => $uid ?: null,
    ]);
    $evId = (int)$pdo->lastInsertId();

    // v3AJ: audit_log
    if (function_exists('audit_log')) {
        audit_log('create_evidencia', 'observaciones_evidencias', $evId,
                  'Subió ' . $tipoArchivo . ' a observación #' . $obsId .
                  ' (' . round($f['size']/1024) . ' KB)',
                  null,
                  ['observacion_id' => $obsId, 'tipo' => $tipoArchivo, 'archivo_url' => $urlRel, 'tamano_bytes' => (int)$f['size']]);
    }

    echo json_encode([
        'ok'   => true,
        'id'   => $evId,
        'tipo' => $tipoArchivo,
        'url'  => '/uploads/' . $urlRel,
    ]);
} catch (Exception $ex) {
    // Rollback: borrar el archivo
    @unlink($rutaFinal);
    echo json_encode(['ok'=>false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al registrar en BD']);
}
