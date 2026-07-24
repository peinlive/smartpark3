<?php
// /home/myzonaco/smartpark.myzona360.com/modules/asignaciones_cuartos/cancelar.php
// v1.0 (3V): Archivar una asignación activa.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/asignaciones_cuartos');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) redirect('/asignaciones_cuartos');

// Validar
$st = $pdo->prepare("SELECT ac.id FROM asignaciones_cuartos ac
                     JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
                    WHERE ac.id = :id AND cu.conjunto_id = :c
                      AND ac.activa = 1 AND ac.archivado_en IS NULL LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
if (!$st->fetchColumn()) { flash_set('error', 'Asignación no encontrada o ya archivada.'); redirect('/asignaciones_cuartos'); }

try {
    $pdo->prepare("UPDATE asignaciones_cuartos SET activa = 0, archivado_en = NOW() WHERE id = :id")
        ->execute([':id' => $id]);
    flash_set('ok', "Asignación #{$id} archivada.");
} catch (Exception $ex) {
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al archivar.');
}

$retorno = $_POST['return_url'] ?? '/asignaciones_cuartos';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') $retorno = '/asignaciones_cuartos';
redirect($retorno);
