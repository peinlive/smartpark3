<?php
// /home/myzonaco/smartpark.myzona360.com/api/residentes_apto.php
// Endpoint AJAX: lista residentes activos de un apto para llenar dropdown.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require();
header('Content-Type: application/json; charset=utf-8');

$u   = auth_user();
$pdo = db();
$conjuntoId = $u['conjunto_id'] ?? 1;
$apto = clean_string($_GET['apto'] ?? '', 20);

if ($apto === '') {
    echo json_encode(['residentes' => []]);
    exit;
}

$st = $pdo->prepare("
    SELECT r.id, r.nombre, r.tipo, r.celular
      FROM residentes r
      JOIN apartamentos a ON a.id = r.apartamento_id
     WHERE a.conjunto_id = :c
       AND a.numero_visible = :n
       AND r.archivado_en IS NULL
  ORDER BY r.tipo, r.nombre
");
$st->execute([':c' => $conjuntoId, ':n' => $apto]);
echo json_encode(['residentes' => $st->fetchAll()]);
