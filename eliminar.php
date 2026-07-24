<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/eliminar.php
// v1.0 (3U): Elimina una revista + su detalle + sus fotos.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/revistas');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) redirect('/revistas');

// Validar que la revista es del conjunto
$st = $pdo->prepare("SELECT id FROM revistas WHERE id = :id AND conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
if (!$st->fetchColumn()) {
    flash_set('error', 'Revista no encontrada.');
    redirect('/revistas');
}

try {
    $pdo->beginTransaction();

    // Borrar detalles (foto_path para eliminar archivos)
    $stD = $pdo->prepare("SELECT foto_path FROM revistas_detalle WHERE revista_id = :r");
    $stD->execute([':r' => $id]);
    $baseDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas';
    foreach ($stD->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        if ($fp) {
            $full = $baseDir . '/' . $fp;
            if (is_file($full)) @unlink($full);
        }
    }
    $pdo->prepare("DELETE FROM revistas_detalle WHERE revista_id = :r")->execute([':r' => $id]);

    // Borrar carpeta de la revista si queda vacía
    $revDir = $baseDir . '/' . $id;
    if (is_dir($revDir)) {
        $files = @scandir($revDir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($revDir . '/' . $f);
            }
            @rmdir($revDir);
        }
    }

    $pdo->prepare("DELETE FROM revistas WHERE id = :id AND conjunto_id = :c")
        ->execute([':id' => $id, ':c' => $conjuntoId]);

    $pdo->commit();
    flash_set('ok', "Revista #{$id} eliminada.");
} catch (Exception $ex) {
    $pdo->rollBack();
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar.');
}

$retorno = $_POST['return_url'] ?? '/revistas';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
    $retorno = '/revistas';
}
redirect($retorno);
