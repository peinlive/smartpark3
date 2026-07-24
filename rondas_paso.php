<?php
// /home/myzonaco/smartpark.myzona360.com/api/rondas_paso.php
// v3e.1: blindaje JSON + CSRF flexible.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

ob_start();
register_shutdown_function(function () {
    $out = ob_get_clean();
    if ($out === false || $out === '') return;
    json_decode($out);
    if (json_last_error() === JSON_ERROR_NONE) { echo $out; return; }
    if (!headers_sent()) { header_remove('Content-Type'); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok' => false, 'error' => 'Error del servidor: ' . trim(strip_tags(substr($out, 0, 300)))]);
});

header('Content-Type: application/json; charset=utf-8');

require_once INCLUDES_PATH . '/upload_helpers.php';
require_once INCLUDES_PATH . '/ocr_helpers.php';

auth_require_role('super_admin','admin','supervisor','ronda','porteria');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Método no permitido.']);
    exit;
}

// CSRF flexible
$csrfPosted = $_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfOk = false;
try {
    if (function_exists('csrf_validate')) { $csrfOk = csrf_validate($csrfPosted); }
    elseif (function_exists('csrf_check')) { $csrfOk = csrf_check($csrfPosted); }
    else {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $st = $_SESSION['_csrf'] ?? $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
        $csrfOk = !empty($csrfPosted) && !empty($st) && hash_equals($st, $csrfPosted);
    }
} catch (Throwable $e) { $csrfOk = false; }
if (!$csrfOk) {
    echo json_encode(['ok'=>false, 'error'=>'Token de seguridad inválido. Recarga (F5) y reintenta.']);
    exit;
}

$u = auth_user(); $pdo = db();
$conjuntoId = $u['conjunto_id'] ?? 1;

$revistaId = clean_int($_POST['revista_id'] ?? null, 1);
$celda     = clean_string($_POST['celda'] ?? '', 10);
$accion    = $_POST['accion'] ?? '';

if (!$revistaId || $celda === '' || !in_array($accion, ['ocr','confirmar','manual','vacia'], true)) {
    echo json_encode(['ok'=>false, 'error'=>'Parámetros inválidos.']);
    exit;
}

$st = $pdo->prepare("SELECT * FROM revistas WHERE id = :id AND conjunto_id = :c AND estado = 'en_curso'");
$st->execute([':id' => $revistaId, ':c' => $conjuntoId]);
$revista = $st->fetch();
if (!$revista) { echo json_encode(['ok'=>false, 'error'=>'Revista no encontrada o ya cerrada.']); exit; }
if ((int)$revista['usuario_id'] !== (int)$u['id'] && !auth_has_role('super_admin','admin')) {
    echo json_encode(['ok'=>false, 'error'=>'No tienes permisos.']); exit;
}

// ── ACCIÓN: OCR ──
if ($accion === 'ocr') {
    try { $fotoRel = upload_foto_vehiculo($_FILES['foto'] ?? [], 'revistas'); }
    catch (RuntimeException $e) { echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit; }
    if (!$fotoRel) { echo json_encode(['ok'=>false, 'error'=>'No se recibió la foto.']); exit; }

    $fotoAbs = UPLOADS_PATH . '/' . $fotoRel;
    $ocrResp = ocr_detectar_placa($fotoAbs);

    if (!$ocrResp['ok']) {
        echo json_encode(['ok'=>false, 'error'=>$ocrResp['error'],
            'foto_url'=>url_foto($fotoRel), 'foto_path'=>$fotoRel]);
        exit;
    }
    $placa = $ocrResp['placa'];
    $busqueda = buscar_placa_unificada($pdo, $conjuntoId, $placa);

    echo json_encode([
        'ok'=>true, 'placa'=>$placa,
        'confidence'=>round($ocrResp['confidence'], 4),
        'confidence_pct'=>round($ocrResp['confidence']*100, 1),
        'low_confidence'=>$ocrResp['confidence'] < OCR_MIN_CONFIDENCE,
        'tipo'=>$busqueda['tipo'], 'resultado'=>$busqueda['data'],
        'foto_url'=>url_foto($fotoRel), 'foto_path'=>$fotoRel,
    ]);
    exit;
}

// ── ACCIONES: confirmar / manual / vacia ──
$fotoPath  = clean_string($_POST['foto_path'] ?? '', 255);
$placa     = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_POST['placa'] ?? ''))));
$confidence = isset($_POST['confidence']) ? (float)$_POST['confidence'] : null;
$celda_vacia = ($accion === 'vacia') ? 1 : 0;
$placa_manual = ($accion === 'manual') ? 1 : 0;

if ($accion === 'confirmar' && $placa === '') { echo json_encode(['ok'=>false,'error'=>'Placa vacía.']); exit; }
if ($accion === 'manual' && $placa === '')    { echo json_encode(['ok'=>false,'error'=>'Debes escribir la placa.']); exit; }

$vehiculoId = null; $visitanteId = null; $tipoRes = 'no_encontrado';
if ($placa !== '' && !$celda_vacia) {
    $b = buscar_placa_unificada($pdo, $conjuntoId, $placa);
    if ($b['tipo'] === 'residente') { $vehiculoId = (int)$b['data']['id']; $tipoRes = 'residente'; }
    elseif ($b['tipo'] === 'visitante') { $visitanteId = (int)$b['data']['id']; $tipoRes = 'visitante'; }
}
if ($celda_vacia) $tipoRes = 'no_encontrado';

try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("
        INSERT INTO lecturas_placas
            (conjunto_id, placa_detectada, confidence, foto_path, vehiculo_id, visitante_id,
             tipo_resultado, fuente, nivel, celda, usuario_id, revista_id, celda_vacia, placa_manual)
        VALUES (:c, :p, :cf, :fp, :v, :vi, :tr, 'revista', :ni, :ce, :u, :r, :cv, :pm)");
    $ins->execute([
        ':c'=>$conjuntoId, ':p'=>$celda_vacia ? 'VACIA' : $placa,
        ':cf'=>$celda_vacia ? null : $confidence, ':fp'=>$fotoPath ?: null,
        ':v'=>$vehiculoId, ':vi'=>$visitanteId, ':tr'=>$tipoRes,
        ':ni'=>$revista['nivel'], ':ce'=>$celda, ':u'=>$u['id'], ':r'=>$revistaId,
        ':cv'=>$celda_vacia, ':pm'=>$placa_manual,
    ]);

    $pdo->prepare("UPDATE revistas
                      SET celdas_revisadas = celdas_revisadas + 1,
                          celdas_ocupadas = celdas_ocupadas + :ocup,
                          celdas_vacias = celdas_vacias + :vac
                    WHERE id = :id")
        ->execute([':ocup'=>$celda_vacia?0:1, ':vac'=>$celda_vacia?1:0, ':id'=>$revistaId]);

    if ($visitanteId !== null) {
        $pdo->prepare("UPDATE visitantes_vehiculos
                          SET visitas_count = visitas_count + 1, ultima_visita = NOW(),
                              recurrente = CASE WHEN visitas_count + 1 >= 3 THEN 1 ELSE recurrente END
                        WHERE id = :id")->execute([':id'=>$visitanteId]);
    }

    $chk = $pdo->prepare("SELECT total_celdas, celdas_revisadas FROM revistas WHERE id = :id");
    $chk->execute([':id'=>$revistaId]);
    $r = $chk->fetch();
    $terminado = false;
    if ((int)$r['celdas_revisadas'] >= (int)$r['total_celdas']) {
        $pdo->prepare("UPDATE revistas SET estado='terminada', terminado_en=NOW() WHERE id = :id")
            ->execute([':id'=>$revistaId]);
        $terminado = true;
    }
    $pdo->commit();

    echo json_encode([
        'ok'=>true, 'terminado'=>$terminado,
        'next_url'=>$terminado ? null : url('/rondas/ejecutar?id=' . $revistaId),
    ]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false, 'error'=> APP_DEBUG ? $ex->getMessage() : 'Error al guardar.']);
}
