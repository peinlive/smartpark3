<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/api_guardar_paso.php
// v1.0 (3U): Guarda o actualiza un registro de revistas_detalle.
//            Recibe: revista_id, celda_id, estado, placa (opt), foto (dataURL opt).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']); exit;
}
if (!csrf_check()) {
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']); exit;
}

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$revistaId = (int)($_POST['revista_id'] ?? 0);
$celdaId   = (int)($_POST['celda_id'] ?? 0);
$estado    = $_POST['estado'] ?? '';
$placa     = strtoupper(preg_replace('/[^A-Z0-9]/i', '', clean_string($_POST['placa'] ?? '', 15)));
$fotoDataUrl = $_POST['foto'] ?? '';

if (!in_array($estado, ['ocupada','vacia','pendiente'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Estado inválido']); exit;
}

// ── FIX v3v: no permitir la MISMA placa en dos celdas de la misma revista ──
if ($placa !== '' && $estado !== 'vacia') {
    $stDup = $pdo->prepare("SELECT rd.id, c.nombre_visible AS celda_nombre
                              FROM revistas_detalle rd
                              JOIN celdas c ON c.id = rd.celda_id
                             WHERE rd.revista_id = :rv
                               AND rd.placa_detectada = :pl
                               AND rd.celda_id != :cd
                             LIMIT 1");
    $stDup->execute([':rv' => $revistaId, ':pl' => $placa, ':cd' => $celdaId]);
    $dup = $stDup->fetch();
    if ($dup) {
        echo json_encode([
            'ok' => false,
            'duplicada' => true,
            'error' => "⚠️ La placa {$placa} ya está registrada en la celda {$dup['celda_nombre']} de esta misma revista. Corrige la placa o edita esa celda.",
            'celda_duplicada' => $dup['celda_nombre'],
        ]);
        exit;
    }
}

// Validar revista
$stR = $pdo->prepare("SELECT id FROM revistas WHERE id = :id AND conjunto_id = :c AND estado = 'en_curso'");
$stR->execute([':id' => $revistaId, ':c' => $conjuntoId]);
if (!$stR->fetchColumn()) {
    echo json_encode(['ok' => false, 'error' => 'Revista no válida o no está en curso']); exit;
}

// Validar celda
$stC = $pdo->prepare("SELECT id FROM celdas WHERE id = :id AND conjunto_id = :c");
$stC->execute([':id' => $celdaId, ':c' => $conjuntoId]);
if (!$stC->fetchColumn()) {
    echo json_encode(['ok' => false, 'error' => 'Celda no válida']); exit;
}

// Buscar vehiculo_id si hay placa
$vehiculoId = null;
if ($placa !== '') {
    $stV = $pdo->prepare("SELECT id FROM vehiculos
                           WHERE placa = :p AND conjunto_id = :c AND archivado_en IS NULL LIMIT 1");
    $stV->execute([':p' => $placa, ':c' => $conjuntoId]);
    $vid = (int)$stV->fetchColumn();
    if ($vid > 0) $vehiculoId = $vid;
}

// Guardar foto si viene
$fotoPath = null;
if ($fotoDataUrl && strpos($fotoDataUrl, 'data:image/') === 0) {
    $partes = explode(',', $fotoDataUrl, 2);
    if (count($partes) === 2) {
        $binario = base64_decode($partes[1]);
        if ($binario !== false) {
            $baseDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads');
            $dir = $baseDir . '/revistas/' . $revistaId;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $filename = 'celda_' . $celdaId . '_' . time() . '.jpg';
            $fullPath = $dir . '/' . $filename;
            if (file_put_contents($fullPath, $binario) !== false) {
                // Path relativo desde /uploads/revistas/
                $fotoPath = $revistaId . '/' . $filename;
            }
        }
    }
}

try {
    // ¿Ya existe el registro?
    $stE = $pdo->prepare("SELECT id, foto_path FROM revistas_detalle
                           WHERE revista_id = :r AND celda_id = :cd LIMIT 1");
    $stE->execute([':r' => $revistaId, ':cd' => $celdaId]);
    $existe = $stE->fetch();

    if ($existe) {
        // Actualizar. Si viene foto nueva, borrar la anterior.
        if ($fotoPath && !empty($existe['foto_path'])) {
            $viejaFull = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas/' . $existe['foto_path'];
            if (is_file($viejaFull)) @unlink($viejaFull);
        }
        // Si estado='vacia' se borra la foto vieja también
        if ($estado === 'vacia' && !empty($existe['foto_path'])) {
            $viejaFull = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas/' . $existe['foto_path'];
            if (is_file($viejaFull)) @unlink($viejaFull);
            $fotoPath = null;
        }
        $up = $pdo->prepare("UPDATE revistas_detalle SET
                estado = :st, placa_detectada = :p, vehiculo_id = :v,
                foto_path = COALESCE(:fp, " . ($estado === 'vacia' ? 'NULL' : 'foto_path') . ")
            WHERE id = :id");
        $up->execute([
            ':st' => $estado,
            ':p'  => $placa !== '' ? $placa : null,
            ':v'  => $vehiculoId,
            ':fp' => $fotoPath,
            ':id' => (int)$existe['id'],
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO revistas_detalle
                (revista_id, celda_id, estado, placa_detectada, vehiculo_id, foto_path)
            VALUES (:r, :cd, :st, :p, :v, :fp)");
        $ins->execute([
            ':r' => $revistaId, ':cd' => $celdaId, ':st' => $estado,
            ':p' => $placa !== '' ? $placa : null,
            ':v' => $vehiculoId, ':fp' => $fotoPath,
        ]);
    }

    // Actualizar conteos en revistas
    $stCnt = $pdo->prepare("SELECT
            SUM(CASE WHEN estado = 'ocupada' THEN 1 ELSE 0 END) AS oc,
            SUM(CASE WHEN estado = 'vacia' THEN 1 ELSE 0 END) AS vc,
            COUNT(*) AS rv
        FROM revistas_detalle WHERE revista_id = :r");
    $stCnt->execute([':r' => $revistaId]);
    $c = $stCnt->fetch();
    $pdo->prepare("UPDATE revistas SET
            celdas_revisadas = :rv, celdas_ocupadas = :oc, celdas_vacias = :vc
        WHERE id = :id")
        ->execute([':rv' => (int)$c['rv'], ':oc' => (int)$c['oc'], ':vc' => (int)$c['vc'], ':id' => $revistaId]);

    echo json_encode(['ok' => true, 'foto_path' => $fotoPath]);
} catch (Exception $ex) {
    echo json_encode(['ok' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al guardar']);
}
