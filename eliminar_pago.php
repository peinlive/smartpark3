<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/eliminar_pago.php
// v1.0 (3Z): Eliminar un pago registrado (por si se cargó por error).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/reportes/alquileres');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$pagoId = (int)($_POST['pago_id'] ?? 0);
if ($pagoId < 1) { flash_set('error', 'ID de pago inválido.'); redirect('/reportes/alquileres'); }

try {
    $st = $pdo->prepare("DELETE FROM pagos_alquileres WHERE id = :id AND conjunto_id = :c");
    $st->execute([':id' => $pagoId, ':c' => $conjuntoId]);
    if ($st->rowCount() === 0) {
        flash_set('error', 'Pago no encontrado.');
    } else {
        flash_set('ok', 'Pago eliminado. La asignación quedó como "Pendiente" en ese mes.');
    }
} catch (Exception $ex) {
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar el pago.');
}

$retorno = $_POST['return_url'] ?? '/reportes/alquileres';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') $retorno = '/reportes/alquileres';
redirect($retorno);
