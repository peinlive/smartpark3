<?php
// /home/myzonaco/smartpark.myzona360.com/modules/usuarios/eliminar.php
// v1.0 (3V): Eliminar usuario, con protecciones críticas.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/usuarios');
csrf_require();

$pdo = db();
$u   = auth_user();
$uidActual = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esSuperAdmin = auth_has_role('super_admin');

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) redirect('/usuarios');

// Protección 1: no auto-eliminar
if ($id === $uidActual) {
    flash_set('error', 'No puedes eliminarte a ti mismo.');
    redirect('/usuarios');
}

$stU = $pdo->prepare("SELECT id, username, conjunto_id FROM usuarios WHERE id = :id LIMIT 1");
$stU->execute([':id' => $id]);
$usr = $stU->fetch();
if (!$usr) { flash_set('error', 'Usuario no encontrado.'); redirect('/usuarios'); }

// Protección 2: admin solo puede eliminar de su conjunto
if (!$esSuperAdmin && (int)$usr['conjunto_id'] !== $conjuntoId) {
    flash_set('error', 'Ese usuario pertenece a otro conjunto.');
    redirect('/usuarios');
}

// Protección 3: no eliminar super_admin si no eres super_admin
$stR = $pdo->prepare("SELECT r.codigo FROM usuario_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :u");
$stR->execute([':u' => $id]);
$rolesUsr = array_map(function($r){ return $r['codigo']; }, $stR->fetchAll());
if (in_array('super_admin', $rolesUsr, true) && !$esSuperAdmin) {
    flash_set('error', 'No tienes permiso para eliminar un super_admin.');
    redirect('/usuarios');
}

// Protección 4: no eliminar el último super_admin del sistema
if (in_array('super_admin', $rolesUsr, true)) {
    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM usuario_roles ur
                             JOIN roles r ON r.id = ur.rol_id
                            WHERE r.codigo = 'super_admin' AND ur.usuario_id != :u");
    $stCnt->execute([':u' => $id]);
    if ((int)$stCnt->fetchColumn() === 0) {
        flash_set('error', 'No puedes eliminar el único super_admin del sistema.');
        redirect('/usuarios');
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM usuario_roles WHERE usuario_id = :u")->execute([':u' => $id]);
    $pdo->prepare("DELETE FROM usuarios WHERE id = :id")->execute([':id' => $id]);
    $pdo->commit();
    flash_set('ok', "Usuario '{$usr['username']}' eliminado.");
} catch (Exception $ex) {
    $pdo->rollBack();
    // Si el usuario está referenciado en otras tablas (revistas, etc.) puede fallar por FK
    flash_set('error', (defined('APP_DEBUG') && APP_DEBUG)
        ? $ex->getMessage()
        : 'No se pudo eliminar. Es posible que tenga registros vinculados (revistas, observaciones, etc.). Considera desactivarlo en su lugar.');
}

redirect('/usuarios');
