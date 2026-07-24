<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/api_vehiculos_residente.php
// v1.0 (3AZ): Devuelve JSON con los vehículos vinculados a un residente.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$residenteId = (int)($_GET['id'] ?? 0);
if ($residenteId < 1) { echo json_encode(['ok'=>false,'error'=>'ID inválido','vehiculos'=>[]]); exit; }

try {
    // Validar que el residente pertenezca al conjunto
    $stR = $pdo->prepare("SELECT r.id, r.nombre, r.apartamento_id
                            FROM residentes r
                           WHERE r.id = :r AND r.conjunto_id = :c LIMIT 1");
    $stR->execute([':r' => $residenteId, ':c' => $conjuntoId]);
    $res = $stR->fetch();
    if (!$res) { echo json_encode(['ok'=>false,'error'=>'Residente no encontrado','vehiculos'=>[]]); exit; }

    // Traer sus vehículos
    $stV = $pdo->prepare("SELECT v.id, v.placa, v.tipo, v.marca, v.color, v.foto_principal
                            FROM vehiculos v
                           WHERE v.residente_id = :r AND v.conjunto_id = :c AND v.archivado_en IS NULL
                        ORDER BY v.tipo, v.placa");
    $stV->execute([':r' => $residenteId, ':c' => $conjuntoId]);
    $vehiculos = [];
    foreach ($stV->fetchAll() as $v) {
        $vehiculos[] = [
            'id'       => (int)$v['id'],
            'placa'    => $v['placa'],
            'tipo'     => $v['tipo'],
            'marca'    => $v['marca'] ?: '',
            'color'    => $v['color'] ?: '',
            'foto_url' => $v['foto_principal']
                ? (function_exists('url_foto') ? url_foto($v['foto_principal']) : '/uploads/' . ltrim($v['foto_principal'], '/'))
                : null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'residente'  => ['id' => (int)$res['id'], 'nombre' => $res['nombre']],
        'vehiculos'  => $vehiculos,
    ]);
} catch (Exception $ex) {
    echo json_encode([
        'ok' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al consultar',
        'vehiculos' => [],
    ]);
}
