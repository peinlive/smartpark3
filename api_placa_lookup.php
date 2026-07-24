<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/api_placa_lookup.php
// v1.0 (3U): Busca una placa en la BD y devuelve info del vehículo/apto.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$placa = strtoupper(preg_replace('/[^A-Z0-9]/i', '', clean_string($_GET['placa'] ?? '', 15)));
if ($placa === '') { echo json_encode(['encontrada' => false]); exit; }

$st = $pdo->prepare("SELECT v.id, v.placa, v.tipo,
                              a.numero_visible AS apto,
                              a.estado_morosidad, a.meses_mora
                       FROM vehiculos v
                  LEFT JOIN apartamentos a ON a.id = v.apartamento_id
                      WHERE v.placa = :p AND v.conjunto_id = :c AND v.archivado_en IS NULL
                      LIMIT 1");
$st->execute([':p' => $placa, ':c' => $conjuntoId]);
$v = $st->fetch();

if ($v) {
    $mora   = (int)($v['meses_mora'] ?? 0);
    $estado = $v['estado_morosidad'] ?? 'al_dia';
    // Normalizar — algunos registros usan 'al_dia', otros 'al dia', otros null
    if (!$estado || $estado === '' || $mora === 0) $estado = 'al_dia';

    echo json_encode([
        'encontrada'       => true,
        'vehiculo_id'      => (int)$v['id'],
        'placa'            => $v['placa'],
        'tipo'             => $v['tipo'],
        'apto'             => $v['apto'],
        'estado_pago'      => $estado,   // 'al_dia' | 'moroso' | 'paz_salvo' ...
        'meses_mora'       => $mora,
    ]);
} else {
    echo json_encode(['encontrada' => false, 'placa' => $placa]);
}
