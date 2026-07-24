<?php
// /home/myzonaco/smartpark.myzona360.com/api/lecturas_batch.php
// Recibe lecturas pendientes desde el celular cuando vuelve la conexión.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

ob_start();
register_shutdown_function(function () {
    $out = ob_get_clean();
    if ($out === false || $out === '') return;
    json_decode($out);
    if (json_last_error() === JSON_ERROR_NONE) { echo $out; return; }
    if (!headers_sent()) { header_remove('Content-Type'); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok' => false, 'error' => 'Error servidor: ' . trim(strip_tags(substr($out, 0, 200)))]);
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
    if (function_exists('csrf_validate'))     $csrfOk = csrf_validate($csrfPosted);
    elseif (function_exists('csrf_check'))    $csrfOk = csrf_check($csrfPosted);
    else {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $st = $_SESSION['_csrf'] ?? $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
        $csrfOk = !empty($csrfPosted) && !empty($st) && hash_equals($st, $csrfPosted);
    }
} catch (Throwable $e) { $csrfOk = false; }
if (!$csrfOk) { echo json_encode(['ok'=>false, 'error'=>'CSRF inválido.']); exit; }

$u = auth_user(); $pdo = db();
$conjuntoId = $u['conjunto_id'] ?? 1;

$revistaId   = clean_int($_POST['revista_id'] ?? null, 1);
$celda       = clean_string($_POST['celda'] ?? '', 10);
$placaRaw    = (string)($_POST['placa'] ?? '');
$placa       = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($placaRaw)));
$celdaVacia  = (int)($_POST['celda_vacia'] ?? 0) === 1 ? 1 : 0;
$placaManual = (int)($_POST['placa_manual'] ?? 0) === 1 ? 1 : 0;
$fuente      = in_array($_POST['fuente'] ?? '', ['consulta','revista','porteria','manual'], true) ? $_POST['fuente'] : 'revista';
$nivel       = clean_string($_POST['nivel'] ?? '', 10) ?: null;
$obs         = clean_string($_POST['observaciones'] ?? '', 500) ?: null;
$creadoLocal = clean_string($_POST['creado_en_local'] ?? '', 50);

// Validaciones mínimas
if ($celda === '' && $fuente === 'revista') {
    echo json_encode(['ok'=>false, 'error'=>'Falta el número de celda.']);
    exit;
}
if (!$celdaVacia && $placa === '') {
    echo json_encode(['ok'=>false, 'error'=>'Falta la placa.']);
    exit;
}

// Validar revista si aplica
$revistaData = null;
if ($revistaId) {
    $st = $pdo->prepare("SELECT * FROM revistas WHERE id = :id AND conjunto_id = :c");
    $st->execute([':id' => $revistaId, ':c' => $conjuntoId]);
    $revistaData = $st->fetch();
    if (!$revistaData) { echo json_encode(['ok'=>false, 'error'=>'Revista no existe.']); exit; }
    // Permitir incluso si está terminada (puede sincronizar tarde)
}

// Subir foto si vino
$fotoRel = null;
if (!empty($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    try {
        $fotoRel = upload_foto_vehiculo($_FILES['foto'], 'revistas');
    } catch (RuntimeException $e) {
        // No fatal: registramos la lectura sin foto
        $fotoRel = null;
    }
}

// OCR opcional si hay token y la placa fue manual o vacía
// (Si el rondero ya digitó, NO desperdiciamos cuota en OCR)
// Solo aplicamos OCR si placa está vacía pero hay foto y no es celda vacía
$confidence = null;
if ($placa === '' && !$celdaVacia && $fotoRel && function_exists('ocr_detectar_placa')) {
    $abs = UPLOADS_PATH . '/' . $fotoRel;
    $resp = ocr_detectar_placa($abs);
    if ($resp['ok']) {
        $placa = $resp['placa'];
        $confidence = $resp['confidence'];
        $placaManual = 0;
    }
}

// Buscar match en BD
$vehiculoId = null; $visitanteId = null; $tipoRes = 'no_encontrado';
if (!$celdaVacia && $placa !== '') {
    $b = buscar_placa_unificada($pdo, $conjuntoId, $placa);
    if ($b['tipo'] === 'residente') { $vehiculoId = (int)$b['data']['id']; $tipoRes = 'residente'; }
    elseif ($b['tipo'] === 'visitante') { $visitanteId = (int)$b['data']['id']; $tipoRes = 'visitante'; }
}

try {
    $pdo->beginTransaction();

    // Convertir fecha local del cliente a SQL (o NOW si falla)
    $createdSql = 'NOW()';
    $createdParams = [];
    if ($creadoLocal !== '') {
        $ts = strtotime($creadoLocal);
        if ($ts) { $createdSql = ':cl'; $createdParams[':cl'] = date('Y-m-d H:i:s', $ts); }
    }

    $sql = "INSERT INTO lecturas_placas
                (conjunto_id, placa_detectada, confidence, foto_path,
                 vehiculo_id, visitante_id, tipo_resultado, fuente,
                 nivel, celda, usuario_id, revista_id, celda_vacia, placa_manual,
                 observaciones, creado_en)
            VALUES (:c, :p, :cf, :fp, :v, :vi, :tr, :fu, :ni, :ce, :u, :r, :cv, :pm, :ob, $createdSql)";
    $ins = $pdo->prepare($sql);
    $params = array_merge([
        ':c'  => $conjuntoId,
        ':p'  => $celdaVacia ? 'VACIA' : $placa,
        ':cf' => $celdaVacia ? null : $confidence,
        ':fp' => $fotoRel,
        ':v'  => $vehiculoId, ':vi' => $visitanteId, ':tr' => $tipoRes,
        ':fu' => $fuente, ':ni' => $nivel, ':ce' => $celda,
        ':u'  => $u['id'], ':r' => $revistaId,
        ':cv' => $celdaVacia, ':pm' => $placaManual,
        ':ob' => $obs,
    ], $createdParams);
    $ins->execute($params);
    $lecturaId = (int)$pdo->lastInsertId();

    // Actualizar contadores de la revista
    if ($revistaId) {
        $pdo->prepare("UPDATE revistas
                          SET celdas_revisadas = celdas_revisadas + 1,
                              celdas_ocupadas = celdas_ocupadas + :ocup,
                              celdas_vacias = celdas_vacias + :vac
                        WHERE id = :id")
            ->execute([':ocup'=>$celdaVacia?0:1, ':vac'=>$celdaVacia?1:0, ':id'=>$revistaId]);
    }

    // Visitante en revista → +1 visita
    if ($visitanteId !== null && $fuente === 'revista') {
        $pdo->prepare("UPDATE visitantes_vehiculos
                          SET visitas_count = visitas_count + 1, ultima_visita = NOW(),
                              recurrente = CASE WHEN visitas_count + 1 >= 3 THEN 1 ELSE recurrente END
                        WHERE id = :id")->execute([':id' => $visitanteId]);
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'lectura_id' => $lecturaId,
        'placa' => $placa,
        'tipo' => $tipoRes,
        'foto_url' => $fotoRel ? url_foto($fotoRel) : null,
    ]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok'=>false, 'error'=>APP_DEBUG ? $ex->getMessage() : 'Error al guardar.']);
}
