<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/restaurar.php

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/vehiculos');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$id = clean_int($_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/vehiculos'); }

$st = $pdo->prepare("SELECT id, placa FROM vehiculos
                      WHERE id = :id AND conjunto_id = :c AND archivado_en IS NOT NULL");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'No encontrado o no estaba archivado.'); redirect('/vehiculos'); }

$pdo->prepare("UPDATE vehiculos
                  SET archivado_en = NULL, archivado_motivo = NULL, activo = 1
                WHERE id = :id")->execute([':id' => $id]);

$pdo->prepare("INSERT INTO audit_log
        (conjunto_id, usuario_id, accion, entidad, entidad_id, descripcion)
     VALUES (:c, :u, 'restaurar', 'vehiculo', :id, :d)")
    ->execute([':c'=>$conjuntoId, ':u'=>$u['id'], ':id'=>$id,
               ':d' => "Restauró vehículo {$v['placa']}"]);

flash_set('ok', "Vehículo {$v['placa']} restaurado.");
redirect('/vehiculos/ver?id=' . $id);
