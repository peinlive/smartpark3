<?php
// /home/myzonaco/smartpark.myzona360.com/api/ocr_placa.php
// v3d.1: GARANTIZA respuesta JSON aunque falle CSRF o cualquier cosa.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

// ── BLINDAJE: capturar cualquier salida no-JSON y convertirla ──
ob_start();
register_shutdown_function(function () {
    $out = ob_get_clean();
    if ($out === false) return;
    if ($out === '') return;
    // Si ya es JSON válido, dejarlo pasar
    json_decode($out);
    if (json_last_error() === JSON_ERROR_NONE) { echo $out; return; }
    // Si no, envolverlo
    if (!headers_sent()) {
        header_remove('Content-Type');
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Error del servidor: ' . trim(strip_tags(substr($out, 0, 300))),
    ]);
});

// Forzar JSON desde el inicio
header('Content-Type: application/json; charset=utf-8');

require_once INCLUDES_PATH . '/upload_helpers.php';
require_once INCLUDES_PATH . '/ocr_helpers.php';

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido. Usa POST.']);
    exit;
}

// ── CSRF flexible: aceptar cualquier nombre común ──
$csrfPosted = $_POST['csrf_token']
           ?? $_POST['_csrf']
           ?? $_POST['_token']
           ?? $_SERVER['HTTP_X_CSRF_TOKEN']
           ?? '';

$csrfOk = false;
try {
    if (function_exists('csrf_validate')) {
        $csrfOk = csrf_validate($csrfPosted);
    } elseif (function_exists('csrf_check')) {
        $csrfOk = csrf_check($csrfPosted);
    } else {
        // Fallback: comparar con $_SESSION
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $sessionToken = $_SESSION['_csrf'] ?? $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
        $csrfOk = !empty($csrfPosted) && !empty($sessionToken) && hash_equals($sessionToken, $csrfPosted);
    }
} catch (Throwable $e) {
    $csrfOk = false;
}

if (!$csrfOk) {
    echo json_encode([
        'ok' => false,
        'error' => 'Token de seguridad inválido. Recarga la página (F5) y vuelve a intentar.',
    ]);
    exit;
}

$u = auth_user(); $pdo = db();
$conjuntoId = $u['conjunto_id'] ?? 1;

$fuente = in_array($_POST['fuente'] ?? '', ['consulta','revista','porteria'], true) ? $_POST['fuente'] : 'consulta';
$nivel  = clean_string($_POST['nivel'] ?? '', 10) ?: null;
$celda  = clean_string($_POST['celda'] ?? '', 10) ?: null;

// 1) Guardar foto con compresión y marca de agua
try {
    $fotoRel = upload_foto_vehiculo($_FILES['foto'] ?? [], 'ocr');
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
if (!$fotoRel) {
    echo json_encode(['ok' => false, 'error' => 'No se recibió ninguna foto.']);
    exit;
}
$fotoAbs = UPLOADS_PATH . '/' . $fotoRel;

// 2) OCR
$ocrResp = ocr_detectar_placa($fotoAbs);

if (!$ocrResp['ok']) {
    echo json_encode([
        'ok'        => false,
        'error'     => $ocrResp['error'],
        'foto_url'  => url_foto($fotoRel),
        'foto_path' => $fotoRel,
    ]);
    exit;
}

$placa = $ocrResp['placa'];
$conf  = $ocrResp['confidence'];

// 3) Buscar en BD
$busqueda = buscar_placa_unificada($pdo, $conjuntoId, $placa);

$vehiculoId = null; $visitanteId = null; $tipoRes = 'no_encontrado'; $data = null;

if ($busqueda['tipo'] === 'residente') {
    $vehiculoId = (int)$busqueda['data']['id']; $tipoRes = 'residente'; $data = $busqueda['data'];
} elseif ($busqueda['tipo'] === 'visitante') {
    $visitanteId = (int)$busqueda['data']['id']; $tipoRes = 'visitante'; $data = $busqueda['data'];
}

// 4) Registrar lectura
try {
    $lecturaId = registrar_lectura_placa(
        $pdo, $conjuntoId, $placa, $conf, $fotoRel,
        $tipoRes, $vehiculoId, $visitanteId, (int)$u['id'],
        $fuente, $nivel, $celda
    );
} catch (Throwable $e) {
    $lecturaId = null;
}

// 5) Visitante en revista → incrementar contador
if ($tipoRes === 'visitante' && $fuente === 'revista' && $visitanteId !== null) {
    try {
        $pdo->prepare("UPDATE visitantes_vehiculos
                          SET visitas_count = visitas_count + 1, ultima_visita = NOW(),
                              recurrente = CASE WHEN visitas_count + 1 >= 3 THEN 1 ELSE recurrente END
                        WHERE id = :id")->execute([':id' => $visitanteId]);
    } catch (Throwable $e) { /* no romper la respuesta */ }
}

echo json_encode([
    'ok'             => true,
    'placa'          => $placa,
    'confidence'     => round($conf, 4),
    'confidence_pct' => round($conf * 100, 1),
    'low_confidence' => $conf < OCR_MIN_CONFIDENCE,
    'tipo'           => $tipoRes,
    'foto_url'       => url_foto($fotoRel),
    'foto_path'      => $fotoRel,
    'lectura_id'     => $lecturaId,
    'resultado'      => $data,
    'urls'           => [
        'crear_visitante' => url('/visitantes/crear') . '?placa=' . urlencode($placa),
        'crear_vehiculo'  => url('/vehiculos/crear')  . '?placa=' . urlencode($placa),
        'ver_lecturas'    => url('/lecturas'),
    ],
]);
