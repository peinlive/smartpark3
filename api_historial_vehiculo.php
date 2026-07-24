<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/api_historial_vehiculo.php
// v1.0 (3AB): Devuelve JSON con las observaciones y últimas revistas de un vehículo.
//   Se usa desde el modal "Perfil e historial" de la consulta rápida.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { echo json_encode(['error'=>'ID inválido']); exit; }

// Validar que el vehículo pertenece al conjunto
$stV = $pdo->prepare("SELECT placa FROM vehiculos WHERE id = :id AND conjunto_id = :c LIMIT 1");
$stV->execute([':id' => $id, ':c' => $conjuntoId]);
$placa = $stV->fetchColumn();
if (!$placa) { echo json_encode(['error'=>'Vehículo no encontrado']); exit; }

// Observaciones (últimas 20) con evidencias adicionales
$labelsTipo = [
    'mal_parqueo'  => 'Mal parqueo',
    'advertencia'  => 'Advertencia',
    'reincidencia' => 'Reincidencia',
    'queja'        => 'Queja',
    'otro'         => 'Otro',
];

$stO = $pdo->prepare("SELECT id, tipo, gravedad, descripcion, evidencia_url, creado_en
                       FROM observaciones_vehiculo
                      WHERE vehiculo_id = :v
                   ORDER BY creado_en DESC LIMIT 20");
$stO->execute([':v' => $id]);
$obsRows = $stO->fetchAll();

// Cargar evidencias adicionales (tabla nueva 3AD). Try/catch por si no se ejecutó migración
$evidenciasPorObs = [];
if (!empty($obsRows)) {
    try {
        $ids = array_column($obsRows, 'id');
        $ph = [];
        $params = [];
        foreach ($ids as $i => $oid) { $k = ':o'.$i; $ph[] = $k; $params[$k] = $oid; }
        $sqlEv = "SELECT id, observacion_id, tipo, archivo_url, creado_en
                    FROM observaciones_evidencias
                   WHERE observacion_id IN (" . implode(',', $ph) . ")
                ORDER BY creado_en ASC";
        $stEv = $pdo->prepare($sqlEv);
        $stEv->execute($params);
        foreach ($stEv->fetchAll() as $e) {
            $oid = (int)$e['observacion_id'];
            if (!isset($evidenciasPorObs[$oid])) $evidenciasPorObs[$oid] = [];
            $evidenciasPorObs[$oid][] = [
                'id'   => (int)$e['id'],
                'tipo' => $e['tipo'],
                'url'  => '/uploads/' . ltrim($e['archivo_url'], '/'),
            ];
        }
    } catch (Exception $ex) { /* tabla no existe todavía */ }
}

$obs = [];
foreach ($obsRows as $o) {
    $oid = (int)$o['id'];
    $obs[] = [
        'id'           => $oid,
        'tipo'         => $o['tipo'],
        'tipo_label'   => $labelsTipo[$o['tipo']] ?? $o['tipo'],
        'gravedad'     => $o['gravedad'],
        'descripcion'  => (string)$o['descripcion'],
        'evidencia'    => $o['evidencia_url'] ? (strpos($o['evidencia_url'],'http')===0 ? $o['evidencia_url'] : '/uploads/'.ltrim($o['evidencia_url'],'/')) : null,
        'evidencias'   => $evidenciasPorObs[$oid] ?? [],
        'fecha'        => date('d/m/Y H:i:s', strtotime($o['creado_en'])),
    ];
}

// Últimas apariciones en revistas (10)
$stR = $pdo->prepare("SELECT rd.id, rd.revisado_en, c.nombre_visible AS celda, n.codigo AS nivel
                       FROM revistas_detalle rd
                       JOIN revistas rv ON rv.id = rd.revista_id
                       JOIN celdas c    ON c.id = rd.celda_id
                  LEFT JOIN niveles_parqueadero n ON n.id = c.nivel_id
                      WHERE rv.conjunto_id = :c
                        AND (rd.vehiculo_id = :v OR (rd.placa_detectada = :p AND rd.placa_detectada IS NOT NULL))
                   ORDER BY rd.revisado_en DESC
                      LIMIT 10");
$stR->execute([':c' => $conjuntoId, ':v' => $id, ':p' => $placa]);
$revs = [];
foreach ($stR->fetchAll() as $r) {
    $revs[] = [
        'celda' => (string)$r['celda'],
        'nivel' => (string)($r['nivel'] ?? '—'),
        'fecha' => date('d/m/Y H:i', strtotime($r['revisado_en'])),
    ];
}

// Total de apariciones para el contador
$stC = $pdo->prepare("SELECT COUNT(*) FROM revistas_detalle rd
                       JOIN revistas rv ON rv.id = rd.revista_id
                      WHERE rv.conjunto_id = :c
                        AND (rd.vehiculo_id = :v OR (rd.placa_detectada = :p AND rd.placa_detectada IS NOT NULL))");
$stC->execute([':c' => $conjuntoId, ':v' => $id, ':p' => $placa]);
$totalRevs = (int)$stC->fetchColumn();

echo json_encode([
    'observaciones'   => $obs,
    'revistas'        => $revs,
    'revistas_total'  => $totalRevs,
]);
