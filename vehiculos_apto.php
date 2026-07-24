<?php
// /home/myzonaco/smartpark.myzona360.com/api/vehiculos_apto.php
// v3i: devuelve vehículos del apto (residentes + visitantes) para modal en /residentes.

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

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$aptoId = clean_int($_GET['apto_id'] ?? null, 1);
$tipo   = in_array($_GET['tipo'] ?? '', ['carro','moto'], true) ? $_GET['tipo'] : '';

if (!$aptoId) {
    echo json_encode(['ok' => false, 'error' => 'Falta apto_id.']);
    exit;
}

// Verificar apto pertenece al conjunto
$st = $pdo->prepare("SELECT id, numero_visible FROM apartamentos
                      WHERE id = :id AND conjunto_id = :c LIMIT 1");
$st->execute([':id' => $aptoId, ':c' => $conjuntoId]);
$apto = $st->fetch();
if (!$apto) {
    echo json_encode(['ok' => false, 'error' => 'Apartamento no encontrado.']);
    exit;
}

$vehiculos = [];

// ── Residentes del apto ──
$whereR = ['v.apartamento_id = :apto', 'v.archivado_en IS NULL'];
$paramsR = [':apto' => $aptoId];
if ($tipo !== '') { $whereR[] = 'v.tipo = :tipo'; $paramsR[':tipo'] = $tipo; }

$st = $pdo->prepare("
    SELECT v.id, v.placa, v.tipo, v.marca, v.color, v.foto_principal, v.observaciones,
           r.nombre AS usuario_nombre,
           COALESCE(r.tipo, 'sin_asignar') AS vinculo
      FROM vehiculos v
 LEFT JOIN residentes r ON r.id = v.residente_id
     WHERE " . implode(' AND ', $whereR) . "
  ORDER BY v.tipo, v.placa
");
$st->execute($paramsR);
foreach ($st->fetchAll() as $r) {
    $vehiculos[] = [
        'id'               => (int)$r['id'],
        'origen'           => 'residente',
        'placa'            => $r['placa'],
        'tipo'             => $r['tipo'],
        'marca'            => $r['marca'],
        'color'            => $r['color'],
        'observaciones'    => $r['observaciones'],
        'usuario_nombre'   => $r['usuario_nombre'],
        'vinculo'          => $r['vinculo'],
        'foto_url'         => !empty($r['foto_principal']) ? url_foto($r['foto_principal']) : null,
        'ver_url'          => url('/vehiculos/ver?id=' . (int)$r['id']),
    ];
}

// ── Visitantes del apto ──
$whereV = ['vv.apartamento_id = :apto', 'vv.archivado_en IS NULL'];
$paramsV = [':apto' => $aptoId];
if ($tipo !== '') { $whereV[] = 'vv.tipo = :tipo'; $paramsV[':tipo'] = $tipo; }

$st = $pdo->prepare("
    SELECT vv.id, vv.placa, vv.tipo, vv.observaciones,
           vv.nombre_visitante AS usuario_nombre,
           vv.visitas_count, vv.recurrente
      FROM visitantes_vehiculos vv
     WHERE " . implode(' AND ', $whereV) . "
  ORDER BY vv.tipo, vv.placa
");
$st->execute($paramsV);
foreach ($st->fetchAll() as $r) {
    $vehiculos[] = [
        'id'               => (int)$r['id'],
        'origen'           => 'visitante',
        'placa'            => $r['placa'],
        'tipo'             => $r['tipo'],
        'marca'            => null,
        'color'            => null,
        'observaciones'    => $r['observaciones'],
        'usuario_nombre'   => $r['usuario_nombre'],
        'vinculo'          => 'visitante',
        'foto_url'         => null,
        'visitas_count'    => (int)$r['visitas_count'],
        'recurrente'       => (int)$r['recurrente'],
        'ver_url'          => url('/visitantes/ver?id=' . (int)$r['id']),
    ];
}

echo json_encode([
    'ok'         => true,
    'apto_id'    => $aptoId,
    'apto_num'   => $apto['numero_visible'],
    'total'      => count($vehiculos),
    'vehiculos'  => $vehiculos,
]);
