<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/restaurar.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/visitantes');
csrf_require();

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/visitantes'); }

$st = $pdo->prepare("SELECT placa FROM visitantes_vehiculos
                      WHERE id = :id AND conjunto_id = :c AND archivado_en IS NOT NULL");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'No encontrado o no estaba archivado.'); redirect('/visitantes'); }

$pdo->prepare("UPDATE visitantes_vehiculos
                  SET archivado_en = NULL, archivado_motivo = NULL
                WHERE id = :id")->execute([':id' => $id]);

flash_set('ok', "Visitante {$v['placa']} restaurado.");
redirect('/visitantes/ver?id=' . $id);
