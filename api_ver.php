<?php
// /home/myzonaco/smartpark.myzona360.com/modules/observaciones/api_ver.php
// v6.7: Devuelve una observacion COMPLETA en JSON, con sus evidencias.
//
// Lo consume el modal flotante de /observaciones (boton 👁 Ver).
// Antes, para ver la foto de evidencia habia que entrar a EDITAR — incomodo
// y arriesgado (un click de mas y modificas el registro).
//
// SOLO LEE. No modifica nada.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit;
}

$pdo = db();
$u   = auth_user();
$cj  = (int)($u['conjunto_id'] ?? 1);

try {
    // La observacion + el vehiculo + el apto + el usuario que la registro.
    // observaciones_vehiculo NO tiene conjunto_id -> se valida por el JOIN
    // con vehiculos (asi lo hace el resto del sistema).
    $st = $pdo->prepare("
        SELECT o.*,
               v.placa, v.tipo AS veh_tipo, v.marca, v.color,
               a.numero_visible AS apto, a.piso,
               t.numero AS torre,
               u.nombre_completo AS usuario_nombre
          FROM observaciones_vehiculo o
          JOIN vehiculos v    ON v.id = o.vehiculo_id
     LEFT JOIN apartamentos a ON a.id = v.apartamento_id
     LEFT JOIN torres t       ON t.id = a.torre_id
     LEFT JOIN usuarios u     ON u.id = o.usuario_registra
         WHERE o.id = :id AND v.conjunto_id = :cj
         LIMIT 1");
    $st->execute([':id' => $id, ':cj' => $cj]);
    $o = $st->fetch();

    if (!$o) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Observación no encontrada']);
        exit;
    }

    // Foto principal (la del OCR / la que se tomo al registrar)
    $fotoOcr = null;
    if (!empty($o['evidencia_url'])) {
        $fotoOcr = (strpos($o['evidencia_url'], 'http') === 0)
            ? $o['evidencia_url']
            : url('/uploads/' . ltrim($o['evidencia_url'], '/'));
    }

    // Evidencias adicionales. Try/catch: la tabla puede no existir.
    $evid = [];
    try {
        $stE = $pdo->prepare("
            SELECT id, tipo, archivo_url, mime, tamano_bytes, creado_en
              FROM observaciones_evidencias
             WHERE observacion_id = :o
          ORDER BY id ASC");
        $stE->execute([':o' => $id]);
        while ($e = $stE->fetch()) {
            $ru = (string)($e['archivo_url'] ?? '');
            $evid[] = [
                'id'     => (int)$e['id'],
                'tipo'   => (string)($e['tipo'] ?? 'foto'),
                'url'    => (strpos($ru, 'http') === 0) ? $ru : url('/uploads/' . ltrim($ru, '/')),
                'mime'   => (string)($e['mime'] ?? ''),
                'peso'   => (int)($e['tamano_bytes'] ?? 0),
                'creado' => (string)($e['creado_en'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // tabla ausente -> seguimos con la foto principal nomas
    }

    echo json_encode([
        'ok' => true,
        'obs' => [
            'id'          => (int)$o['id'],
            'vehiculo_id' => (int)$o['vehiculo_id'],
            'placa'       => strtoupper((string)($o['placa'] ?? '')),
            'veh_tipo'    => (string)($o['veh_tipo'] ?? ''),
            'marca'       => (string)($o['marca'] ?? ''),
            'color'       => (string)($o['color'] ?? ''),
            'apto'        => (string)($o['apto'] ?? ''),
            'torre'       => (string)($o['torre'] ?? ''),
            'piso'        => (string)($o['piso'] ?? ''),
            'tipo'        => (string)($o['tipo'] ?? ''),
            'gravedad'    => (string)($o['gravedad'] ?? ''),
            'descripcion' => (string)($o['descripcion'] ?? ''),
            'estado'      => (string)($o['estado'] ?? ''),
            'creado'      => (string)($o['creado_en'] ?? ''),
            'usuario'     => (string)($o['usuario_nombre'] ?? ''),
            'foto_ocr'    => $fotoOcr,
        ],
        'evidencias' => $evid,
        'url_ver'    => url('/observaciones/ver?id=' . $id),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al cargar la observación']);
}
