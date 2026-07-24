<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/api_residentes_apto.php
// v1.0 (3AO): Devuelve JSON con residentes activos de un apto por numero_visible.
//   Se usa en el modal de "Registrar vehículo desde revista" para el selector opcional.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$aptoNum = clean_string($_GET['apto'] ?? '', 20);
if ($aptoNum === '') { echo json_encode(['ok'=>false,'error'=>'Apto vacío','residentes'=>[]]); exit; }

try {
    // Buscar el apto
    $stA = $pdo->prepare("SELECT id, numero_visible FROM apartamentos
                           WHERE numero_visible = :n AND conjunto_id = :c LIMIT 1");
    $stA->execute([':n' => $aptoNum, ':c' => $conjuntoId]);
    $apto = $stA->fetch();
    if (!$apto) {
        echo json_encode(['ok'=>false,'error'=>'Apto no existe','residentes'=>[]]);
        exit;
    }

    // Residentes activos (los que aparecen NULL o vacío como archivado_en)
    // Detección defensiva de columnas por si el esquema difiere
    $stR = $pdo->prepare("SELECT id, nombre, celular, tipo
                            FROM residentes
                           WHERE apartamento_id = :a
                             AND conjunto_id = :c
                             AND (activo = 1 OR activo IS NULL)
                             AND archivado_en IS NULL
                        ORDER BY (tipo='propietario') DESC, nombre");
    $stR->execute([':a' => (int)$apto['id'], ':c' => $conjuntoId]);
    $residentes = $stR->fetchAll();

    echo json_encode([
        'ok' => true,
        'apto_id'    => (int)$apto['id'],
        'apto_num'   => $apto['numero_visible'],
        'residentes' => $residentes,
    ]);
} catch (Exception $ex) {
    echo json_encode([
        'ok' => false,
        'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al consultar',
        'residentes' => [],
    ]);
}
