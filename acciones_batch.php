<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/acciones_batch.php
// v1.0 (3U): Acciones masivas sobre revistas (por ahora solo eliminar).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/revistas');
csrf_require();

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$accion    = $_POST['accion'] ?? '';
$seleccion = (array)($_POST['seleccion'] ?? []);

if (!in_array($accion, ['eliminar', 'eliminar_imagenes'], true)) { flash_set('error', 'Acción inválida.'); redirect('/revistas'); }
if (empty($seleccion))     { flash_set('error', 'No seleccionaste ninguna revista.'); redirect('/revistas'); }

$ids = [];
foreach ($seleccion as $item) {
    if (is_array($item)) continue;
    $id = (int)$item;
    if ($id >= 1) $ids[] = $id;
}
if (empty($ids)) { flash_set('error', 'IDs inválidos.'); redirect('/revistas'); }

$inList = implode(',', array_map('intval', $ids));
$baseDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas';

// ─── ACCIÓN: eliminar solo imágenes (conserva los registros) ───
if ($accion === 'eliminar_imagenes') {
    $fotosBorradas = 0;
    try {
        // Validar que las revistas son del conjunto del usuario
        $stChk = $pdo->prepare("SELECT id FROM revistas WHERE id IN ($inList) AND conjunto_id = :c");
        $stChk->execute([':c' => $conjuntoId]);
        $idsValidos = array_map('intval', $stChk->fetchAll(PDO::FETCH_COLUMN));

        if (empty($idsValidos)) {
            flash_set('error', 'Ninguna revista corresponde a tu conjunto.');
        } else {
            $inValid = implode(',', $idsValidos);

            // Obtener foto_paths
            $stF = $pdo->prepare("SELECT foto_path FROM revistas_detalle
                                    WHERE revista_id IN ($inValid) AND foto_path IS NOT NULL");
            $stF->execute();
            foreach ($stF->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $full = $baseDir . '/' . $fp;
                if (is_file($full)) { @unlink($full); $fotosBorradas++; }
            }

            // Limpiar foto_path en BD (conservando el registro)
            $pdo->prepare("UPDATE revistas_detalle SET foto_path = NULL
                            WHERE revista_id IN ($inValid)")->execute();

            // Intentar borrar carpetas vacías
            foreach ($idsValidos as $rid) {
                $revDir = $baseDir . '/' . $rid;
                if (is_dir($revDir)) {
                    $files = @scandir($revDir);
                    if ($files !== false) {
                        $vacio = true;
                        foreach ($files as $f) if ($f !== '.' && $f !== '..') { $vacio = false; break; }
                        if ($vacio) @rmdir($revDir);
                    }
                }
            }

            flash_set('ok', "Se eliminaron {$fotosBorradas} imagen(es) de " . count($idsValidos) . " revista(s). Los registros se conservan.");
        }
    } catch (Exception $ex) {
        flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar imágenes.');
    }

    $retorno = $_POST['return_url'] ?? '/revistas';
    if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') $retorno = '/revistas';
    redirect($retorno);
}

// ─── ACCIÓN: eliminar revistas completas (original) ───
$eliminadas = 0;

try {
    $pdo->beginTransaction();

    // Borrar fotos de los detalles
    $stF = $pdo->prepare("SELECT foto_path FROM revistas_detalle
                          WHERE revista_id IN ($inList) AND foto_path IS NOT NULL");
    $stF->execute();
    foreach ($stF->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $full = $baseDir . '/' . $fp;
        if (is_file($full)) @unlink($full);
    }

    // Borrar detalles
    $pdo->exec("DELETE FROM revistas_detalle WHERE revista_id IN ($inList)");

    // Borrar carpetas
    foreach ($ids as $rid) {
        $revDir = $baseDir . '/' . $rid;
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
    }

    // Borrar revistas (solo del conjunto)
    $eliminadas = (int)$pdo->exec("DELETE FROM revistas WHERE id IN ($inList) AND conjunto_id = $conjuntoId");

    $pdo->commit();
    flash_set('ok', "Se eliminaron $eliminadas revista(s).");
} catch (Exception $ex) {
    $pdo->rollBack();
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al eliminar.');
}

$retorno = $_POST['return_url'] ?? '/revistas';
if (!is_string($retorno) || $retorno === '' || $retorno[0] !== '/' || substr($retorno, 0, 2) === '//') {
    $retorno = '/revistas';
}
redirect($retorno);
