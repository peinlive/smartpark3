<?php
// /home/myzonaco/smartpark.myzona360.com/modules/rondas/terminar.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/rondas');
csrf_require();

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/rondas'); }

$st = $pdo->prepare("SELECT * FROM revistas WHERE id = :id AND conjunto_id = :c");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$r = $st->fetch();
if (!$r) { flash_set('error', 'No encontrada.'); redirect('/rondas'); }
if ((int)$r['usuario_id'] !== (int)$u['id'] && !auth_has_role('super_admin','admin')) {
    flash_set('error', 'No tienes permisos.'); redirect('/rondas');
}
if ($r['estado'] !== 'en_curso') {
    flash_set('warn', 'Ya estaba ' . $r['estado'] . '.'); redirect('/rondas/ver?id=' . $id);
}

$pdo->prepare("UPDATE revistas SET estado='terminada', terminado_en=NOW() WHERE id = :id")
    ->execute([':id' => $id]);

flash_set('ok', "Revista #{$id} terminada. {$r['celdas_revisadas']} de {$r['total_celdas']} celdas revisadas.");
redirect('/rondas/ver?id=' . $id . '&completa=1');
