<?php
// /home/myzonaco/smartpark.myzona360.com/api/search_apto.php
// Endpoint AJAX: verifica si un apto existe en el conjunto activo.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require();
header('Content-Type: application/json; charset=utf-8');

$u   = auth_user();
$pdo = db();
$conjuntoId = $u['conjunto_id'] ?? 1;
$q = clean_string($_GET['q'] ?? '', 20);

if ($q === '') {
    echo json_encode(['found' => false]);
    exit;
}

$st = $pdo->prepare("
    SELECT a.numero_visible, a.piso, t.numero AS torre
      FROM apartamentos a
      JOIN torres t ON t.id = a.torre_id
     WHERE a.conjunto_id = :c AND a.numero_visible = :n
     LIMIT 1
");
$st->execute([':c' => $conjuntoId, ':n' => $q]);
$row = $st->fetch();

if ($row) {
    echo json_encode([
        'found'           => true,
        'numero_visible'  => $row['numero_visible'],
        'torre'           => (int)$row['torre'],
        'piso'            => (int)$row['piso'],
    ]);
} else {
    echo json_encode(['found' => false]);
}
