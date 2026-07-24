<?php
// /home/myzonaco/smartpark.myzona360.com/api/search_placa.php
// Búsqueda AJAX de vehículos por placa (autocomplete).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require();
header('Content-Type: application/json; charset=utf-8');

$u   = auth_user();
$pdo = db();
$conjuntoId = $u['conjunto_id'] ?? 1;

$q = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['q'] ?? ''))));
if (strlen($q) < 2) {
    echo json_encode(['results' => []]); exit;
}

$st = $pdo->prepare("
    SELECT v.id, v.placa, v.tipo, v.archivado_en,
           a.numero_visible AS apto, t.numero AS torre,
           r.nombre AS residente_nombre
      FROM vehiculos v
      JOIN apartamentos a ON a.id = v.apartamento_id
      JOIN torres t       ON t.id = a.torre_id
 LEFT JOIN residentes r   ON r.id = v.residente_id
     WHERE v.conjunto_id = :c AND v.placa LIKE :q
     ORDER BY v.archivado_en IS NULL DESC, v.placa
     LIMIT 10
");
$st->execute([':c' => $conjuntoId, ':q' => "%{$q}%"]);
$rows = $st->fetchAll();

echo json_encode(['results' => $rows]);
