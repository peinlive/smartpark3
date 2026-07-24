<?php
// /home/myzonaco/smartpark.myzona360.com/includes/ocr_helpers.php
// Cliente OCR para PlateRecognizer.com + helpers de matching.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once CONFIG_PATH . '/ocr.php';

/**
 * Detecta placa(s) en una imagen llamando a PlateRecognizer.
 *
 * @param string $imagePath Ruta absoluta a la imagen ya subida (jpg/png/webp).
 * @return array{
 *   ok: bool,
 *   placa: string,
 *   confidence: float,
 *   all_results: array,
 *   error: string|null
 * }
 */
function ocr_detectar_placa(string $imagePath): array
{
    $result = [
        'ok' => false, 'placa' => '', 'confidence' => 0.0,
        'all_results' => [], 'error' => null,
    ];

    if (!OCR_ENABLED) {
        $result['error'] = 'El OCR no está configurado. Revisa /config/ocr.php (OCR_API_TOKEN).';
        return $result;
    }
    if (!is_file($imagePath)) {
        $result['error'] = 'Imagen no encontrada.';
        return $result;
    }
    if (!function_exists('curl_init')) {
        $result['error'] = 'cURL no disponible en este servidor.';
        return $result;
    }

    $ch = curl_init(OCR_API_URL);
    $postFields = [
        'upload'  => new CURLFile($imagePath),
        'regions' => OCR_REGIONS,
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => OCR_TIMEOUT,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Token ' . OCR_API_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $result['error'] = 'Error de conexión con OCR: ' . $curlErr;
        return $result;
    }

    if ($httpCode >= 400) {
        $body = @json_decode($response, true);
        $msg = is_array($body) && !empty($body['detail']) ? $body['detail'] : "HTTP {$httpCode}";
        $result['error'] = 'OCR rechazó la petición: ' . $msg;
        return $result;
    }

    $data = @json_decode($response, true);
    if (!is_array($data) || !isset($data['results'])) {
        $result['error'] = 'Respuesta del OCR no válida.';
        return $result;
    }

    $result['all_results'] = $data['results'];

    if (empty($data['results'])) {
        $result['error'] = 'No se detectó ninguna placa en la foto.';
        return $result;
    }

    // Tomar el resultado con mayor confidence
    usort($data['results'], fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $best = $data['results'][0];

    $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($best['plate'] ?? '')));
    $conf  = (float)($best['score'] ?? 0);

    if ($placa === '') {
        $result['error'] = 'Placa detectada pero ilegible.';
        return $result;
    }

    $result['ok']         = true;
    $result['placa']      = $placa;
    $result['confidence'] = $conf;
    return $result;
}

/**
 * Busca una placa en vehículos residentes y visitantes.
 * Retorna ['tipo' => 'residente'|'visitante'|'no_encontrado', 'data' => array]
 */
function buscar_placa_unificada(PDO $pdo, int $conjuntoId, string $placa): array
{
    $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($placa)));
    if ($placa === '') return ['tipo' => 'no_encontrado', 'data' => null];

    // 1) Residente
    $st = $pdo->prepare("
        SELECT v.id, v.placa, v.tipo, v.marca, v.color, v.foto_principal,
               v.archivado_en, v.residente_id,
               a.id AS apto_id, a.numero_visible AS apto, a.piso,
               a.estado_morosidad, a.meses_mora, a.bloqueo_comunes,
               t.numero AS torre,
               r.nombre AS residente_nombre, r.celular AS residente_celular
          FROM vehiculos v
          JOIN apartamentos a ON a.id = v.apartamento_id
          JOIN torres t ON t.id = a.torre_id
     LEFT JOIN residentes r ON r.id = v.residente_id
         WHERE v.conjunto_id = :c AND v.placa = :p AND v.archivado_en IS NULL
         LIMIT 1
    ");
    $st->execute([':c' => $conjuntoId, ':p' => $placa]);
    $row = $st->fetch();
    if ($row) return ['tipo' => 'residente', 'data' => $row];

    // 2) Visitante
    $st = $pdo->prepare("
        SELECT v.*, a.numero_visible AS apto, t.numero AS torre, a.piso
          FROM visitantes_vehiculos v
          JOIN apartamentos a ON a.id = v.apartamento_id
          JOIN torres t ON t.id = a.torre_id
         WHERE v.conjunto_id = :c AND v.placa = :p AND v.archivado_en IS NULL
         LIMIT 1
    ");
    $st->execute([':c' => $conjuntoId, ':p' => $placa]);
    $row = $st->fetch();
    if ($row) return ['tipo' => 'visitante', 'data' => $row];

    return ['tipo' => 'no_encontrado', 'data' => null];
}

/**
 * Registra una lectura de placa en la tabla lecturas_placas.
 */
function registrar_lectura_placa(
    PDO $pdo, int $conjuntoId, string $placa, ?float $confidence,
    ?string $fotoPath, string $tipoResultado, ?int $vehiculoId, ?int $visitanteId,
    int $usuarioId, string $fuente = 'consulta',
    ?string $nivel = null, ?string $celda = null, ?string $obs = null
): int
{
    $st = $pdo->prepare("
        INSERT INTO lecturas_placas
            (conjunto_id, placa_detectada, confidence, foto_path, vehiculo_id, visitante_id,
             tipo_resultado, fuente, nivel, celda, usuario_id, observaciones)
        VALUES (:c, :p, :cf, :fp, :v, :vi, :tr, :fu, :ni, :ce, :u, :ob)
    ");
    $st->execute([
        ':c' => $conjuntoId, ':p' => $placa,
        ':cf' => $confidence, ':fp' => $fotoPath,
        ':v' => $vehiculoId, ':vi' => $visitanteId,
        ':tr' => $tipoResultado, ':fu' => $fuente,
        ':ni' => $nivel, ':ce' => $celda,
        ':u' => $usuarioId, ':ob' => $obs,
    ]);
    return (int)$pdo->lastInsertId();
}
