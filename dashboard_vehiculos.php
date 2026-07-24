<?php
// /home/myzonaco/smartpark.myzona360.com/api/dashboard_vehiculos.php
// Desglose de vehículos por torre para el modal del dashboard.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

ob_start();
register_shutdown_function(function () {
    $out = ob_get_clean();
    if ($out === false || $out === '') return;
    json_decode($out);
    if (json_last_error() === JSON_ERROR_NONE) { echo $out; return; }
    if (!headers_sent()) { header_remove('Content-Type'); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok'=>false, 'error'=>'Error servidor: ' . trim(strip_tags(substr($out, 0, 200)))]);
});

header('Content-Type: application/json; charset=utf-8');

auth_require();

$pdo = db();
$u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$tipo = in_array($_GET['tipo'] ?? '', ['carro','moto','todos'], true) ? $_GET['tipo'] : 'todos';

// Conteo por torre con LEFT JOIN para incluir torres con 0 vehículos
$st = $pdo->prepare("
    SELECT t.id, t.numero, t.nombre,
           COALESCE(SUM(CASE WHEN v.tipo='carro' AND v.archivado_en IS NULL THEN 1 ELSE 0 END),0) AS carros,
           COALESCE(SUM(CASE WHEN v.tipo='moto'  AND v.archivado_en IS NULL THEN 1 ELSE 0 END),0) AS motos,
           COALESCE(SUM(CASE WHEN v.archivado_en IS NULL THEN 1 ELSE 0 END),0) AS total
      FROM torres t
 LEFT JOIN apartamentos a ON a.torre_id = t.id
 LEFT JOIN vehiculos v    ON v.apartamento_id = a.id
     WHERE t.conjunto_id = :c AND t.activo = 1
  GROUP BY t.id, t.numero, t.nombre
  ORDER BY t.numero
");
$st->execute([':c' => $conjuntoId]);
$torres = $st->fetchAll();

// Filtrar torres sin vehículos del tipo solicitado
$torresOut = [];
$gtCarros = 0; $gtMotos = 0; $gtTotal = 0;

foreach ($torres as $t) {
    $c = (int)$t['carros']; $m = (int)$t['motos']; $tot = (int)$t['total'];
    $gtCarros += $c; $gtMotos += $m; $gtTotal += $tot;

    // Si el filtro es por tipo específico, omitir torres con 0 del tipo
    if ($tipo === 'carro' && $c === 0) continue;
    if ($tipo === 'moto'  && $m === 0) continue;
    if ($tipo === 'todos' && $tot === 0) continue;

    $torresOut[] = [
        'id'     => (int)$t['id'],
        'numero' => (int)$t['numero'],
        'nombre' => $t['nombre'],
        'carros' => $c,
        'motos'  => $m,
        'total'  => $tot,
    ];
}

echo json_encode([
    'ok'         => true,
    'tipo'       => $tipo,
    'torres'     => $torresOut,
    'gran_total' => [
        'carros' => $gtCarros,
        'motos'  => $gtMotos,
        'total'  => $gtTotal,
    ],
]);
