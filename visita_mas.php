<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/visita_mas.php
// Incrementa el contador de visitas para un visitante existente.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/visitantes');
csrf_require();

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/visitantes'); }

$st = $pdo->prepare("SELECT id, placa, visitas_count FROM visitantes_vehiculos
                      WHERE id = :id AND conjunto_id = :c AND archivado_en IS NULL LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'No encontrado o archivado.'); redirect('/visitantes'); }

$newCount = (int)$v['visitas_count'] + 1;
$newRecurr = $newCount >= 3 ? 1 : null;  // si llega a 3, marcar recurrente

if ($newRecurr !== null) {
    $up = $pdo->prepare("UPDATE visitantes_vehiculos
                            SET visitas_count = :c, ultima_visita = NOW(), recurrente = 1
                          WHERE id = :id");
    $up->execute([':c' => $newCount, ':id' => $id]);
} else {
    $up = $pdo->prepare("UPDATE visitantes_vehiculos
                            SET visitas_count = :c, ultima_visita = NOW()
                          WHERE id = :id");
    $up->execute([':c' => $newCount, ':id' => $id]);
}

flash_set('ok', "Visita #{$newCount} registrada para placa {$v['placa']}.");

// Volver al referer si vino de listado, sino a la vista de detalle
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($ref, '/visitantes') !== false && strpos($ref, '/ver') === false) {
    redirect('/visitantes');
} else {
    redirect('/visitantes/ver?id=' . $id);
}
