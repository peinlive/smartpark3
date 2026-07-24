<?php
// /home/myzonaco/smartpark.myzona360.com/modules/usuarios/editar.php
// v1.0 (3V): Editar usuario. Cambiar datos, roles, reset password, bloqueo/desbloqueo.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$pdo = db();
$u   = auth_user();
$uidActual = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esSuperAdmin = auth_has_role('super_admin');

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) redirect('/usuarios');

$st = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$usr = $st->fetch();
if (!$usr) { flash_set('error', 'Usuario no encontrado.'); redirect('/usuarios'); }

// Un admin no puede editar un super_admin y solo puede ver usuarios de su conjunto
$stR = $pdo->prepare("SELECT r.id, r.codigo FROM usuario_roles ur
                       JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :u");
$stR->execute([':u' => $id]);
$rolesUsr = $stR->fetchAll();
$rolesCodesUsr = array_column($rolesUsr, 'codigo');
$rolesIdsUsr = array_map('intval', array_column($rolesUsr, 'id'));

$esTargetSuperAdmin = in_array('super_admin', $rolesCodesUsr, true);
if ($esTargetSuperAdmin && !$esSuperAdmin) {
    flash_set('error', 'No tienes permiso para editar un super_admin.');
    redirect('/usuarios');
}
if (!$esSuperAdmin && (int)$usr['conjunto_id'] !== $conjuntoId) {
    flash_set('error', 'Ese usuario pertenece a otro conjunto.');
    redirect('/usuarios');
}

$errores = [];

// Todos los roles disponibles (filtro para admin)
$rolesQ = $pdo->query("SELECT id, codigo, nombre, descripcion, nivel FROM roles ORDER BY nivel DESC");
$rolesAll = $rolesQ->fetchAll();
if (!$esSuperAdmin) {
    $rolesAll = array_values(array_filter($rolesAll, function($r){ return $r['codigo'] !== 'super_admin'; }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $accion = $_POST['accion'] ?? '';

    // ─── Reset de contraseña ───
    if ($accion === 'reset_password') {
        $p1 = (string)($_POST['new_password'] ?? '');
        $p2 = (string)($_POST['new_password2'] ?? '');
        if (strlen($p1) < 8) $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($p1 !== $p2)     $errores[] = 'Las contraseñas no coinciden.';
        if (empty($errores)) {
            $pdo->prepare("UPDATE usuarios SET
                    password_hash = :ph, intentos_fallidos = 0, bloqueado_hasta = NULL
                WHERE id = :id")
                ->execute([':ph' => password_hash($p1, PASSWORD_DEFAULT), ':id' => $id]);
            flash_set('ok', 'Contraseña reseteada. Comunícasela al usuario.');
            redirect('/usuarios/editar?id=' . $id);
        }
    }

    // ─── Bloquear / desbloquear ───
    if ($accion === 'bloquear') {
        $pdo->prepare("UPDATE usuarios SET bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = :id")
            ->execute([':id' => $id]);
        flash_set('ok', 'Usuario bloqueado por 24 horas.');
        redirect('/usuarios/editar?id=' . $id);
    }
    if ($accion === 'desbloquear') {
        $pdo->prepare("UPDATE usuarios SET bloqueado_hasta = NULL, intentos_fallidos = 0 WHERE id = :id")
            ->execute([':id' => $id]);
        flash_set('ok', 'Usuario desbloqueado.');
        redirect('/usuarios/editar?id=' . $id);
    }

    // ─── Guardar datos + roles ───
    if ($accion === '' || $accion === 'guardar') {
        $nombre = clean_string($_POST['nombre_completo'] ?? '', 120);
        $email  = clean_string($_POST['email'] ?? '', 120);
        $celular= clean_string($_POST['celular'] ?? '', 30);
        $activo = isset($_POST['activo']) ? 1 : 0;
        $rolesSel = array_map('intval', (array)($_POST['roles'] ?? []));

        if ($nombre === '') $errores[] = 'Nombre completo obligatorio.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido.';
        if (empty($rolesSel)) $errores[] = 'Debe tener al menos un rol.';

        $rolesIdsPermitidos = array_column($rolesAll, 'id');
        foreach ($rolesSel as $ridSel) {
            if (!in_array($ridSel, $rolesIdsPermitidos)) { $errores[] = 'Rol inválido seleccionado.'; break; }
        }

        // Protección: no permitir que el usuario actual se quite el rol super_admin ni se desactive
        if ($id === $uidActual) {
            if ($activo !== 1) $errores[] = 'No puedes desactivarte a ti mismo.';
            $stMi = $pdo->prepare("SELECT rol_id FROM usuario_roles WHERE usuario_id = :u");
            $stMi->execute([':u' => $uidActual]);
            $misRolesIds = array_map('intval', $stMi->fetchAll(PDO::FETCH_COLUMN));
            $stSa = $pdo->prepare("SELECT id FROM roles WHERE codigo = 'super_admin'");
            $stSa->execute();
            $saId = (int)$stSa->fetchColumn();
            if ($saId && in_array($saId, $misRolesIds) && !in_array($saId, $rolesSel)) {
                $errores[] = 'No puedes quitarte a ti mismo el rol super_admin.';
            }
        }

        // Protección: no permitir quitar el ÚLTIMO super_admin
        if (empty($errores)) {
            $stSaId = $pdo->prepare("SELECT id FROM roles WHERE codigo = 'super_admin'");
            $stSaId->execute();
            $saId = (int)$stSaId->fetchColumn();
            if ($saId && in_array($saId, $rolesIdsUsr, true) && !in_array($saId, $rolesSel, true)) {
                // Se está quitando super_admin a este usuario. Verificar cuántos quedan.
                $stCntSa = $pdo->prepare("SELECT COUNT(*) FROM usuario_roles ur JOIN roles r ON r.id = ur.rol_id
                                           WHERE r.codigo = 'super_admin' AND ur.usuario_id != :u");
                $stCntSa->execute([':u' => $id]);
                if ((int)$stCntSa->fetchColumn() === 0) {
                    $errores[] = 'No puedes quitar el rol super_admin: sería el último del sistema.';
                }
            }
        }

        if (empty($errores)) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE usuarios SET
                        nombre_completo = :nm, email = :em, celular = :cl, activo = :ac
                    WHERE id = :id")
                    ->execute([
                        ':nm' => $nombre,
                        ':em' => $email !== '' ? $email : null,
                        ':cl' => $celular !== '' ? $celular : null,
                        ':ac' => $activo,
                        ':id' => $id,
                    ]);

                // Sincronizar roles: borrar todos y reinsertar los seleccionados
                $pdo->prepare("DELETE FROM usuario_roles WHERE usuario_id = :u")->execute([':u' => $id]);
                $insR = $pdo->prepare("INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (:u, :r)");
                foreach ($rolesSel as $rid) { $insR->execute([':u' => $id, ':r' => $rid]); }

                $pdo->commit();
                flash_set('ok', 'Usuario actualizado.');
                redirect('/usuarios');
            } catch (Exception $ex) {
                $pdo->rollBack();
                $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al actualizar.';
            }
        }
        // Repoblar
        $usr['nombre_completo'] = $nombre;
        $usr['email']           = $email;
        $usr['celular']         = $celular;
        $usr['activo']          = $activo;
        $rolesIdsUsr = $rolesSel;
    }
}

$bloqueado = $usr['bloqueado_hasta'] && strtotime($usr['bloqueado_hasta']) > time();

$_pageTitle = 'Editar usuario';
include INCLUDES_PATH . '/header.php';
?>

<style>
.usr-form{max-width:640px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.usr-form label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;margin-top:12px;}
.usr-form input,.usr-form select{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:5px;font-size:14px;}
.roles-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-top:8px;}
.rol-card{padding:10px;border:1.5px solid #d1d5db;border-radius:6px;cursor:pointer;background:#fff;}
.rol-card:hover{background:#f9fafb;}
.rol-card.selected{border-color:#1e6cff;background:#eff6ff;}
.rol-card input{width:auto;margin-right:6px;}
.rol-card small{display:block;color:#6b7280;font-size:11px;margin-top:2px;}
.side-box{background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:14px;margin-top:14px;}
.side-box h4{margin:0 0 8px;font-size:14px;color:#1e3a8a;}
.side-box.warn{background:#fef3c7;border-color:#fbbf24;}
.side-box.warn h4{color:#92400e;}
</style>

<div class="page-head">
    <h1 class="page-head__title">✏️ Editar <?= e($usr['username']) ?></h1>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/usuarios') ?>">← Volver</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="usr-form">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="accion" value="guardar">

    <div style="background:#f8fafc;padding:8px 12px;border-radius:5px;font-size:13px">
        <strong>Username:</strong> <code><?= e($usr['username']) ?></code>
        <?php if ($id === $uidActual): ?><span style="color:#1e6cff;margin-left:8px">(este eres tú)</span><?php endif; ?>
        <br><small style="color:#6b7280">
            Creado <?= e(date('d/m/Y', strtotime($usr['creado_en']))) ?>
            · Último login: <?= $usr['ultimo_login'] ? e(date('d/m/Y H:i', strtotime($usr['ultimo_login']))) : 'nunca' ?>
        </small>
    </div>

    <label>Nombre completo *</label>
    <input type="text" name="nombre_completo" required maxlength="120" value="<?= e($usr['nombre_completo']) ?>">

    <label>Email</label>
    <input type="email" name="email" maxlength="120" value="<?= e($usr['email']) ?>">

    <label>Celular</label>
    <input type="text" name="celular" maxlength="30" value="<?= e($usr['celular']) ?>">

    <label style="margin-top:16px">
        <input type="checkbox" name="activo" value="1" <?= (int)$usr['activo'] === 1 ? 'checked' : '' ?>
               <?= $id === $uidActual ? 'disabled' : '' ?>>
        Cuenta activa
        <?php if ($id === $uidActual): ?>
            <small style="color:#6b7280">(no puedes desactivarte a ti mismo)</small>
            <input type="hidden" name="activo" value="1">
        <?php endif; ?>
    </label>

    <label style="margin-top:16px">Roles</label>
    <div class="roles-grid">
        <?php foreach ($rolesAll as $r): ?>
            <label class="rol-card <?= in_array((int)$r['id'], $rolesIdsUsr) ? 'selected' : '' ?>">
                <strong>
                    <input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>"
                           <?= in_array((int)$r['id'], $rolesIdsUsr) ? 'checked' : '' ?>
                           onchange="this.closest('.rol-card').classList.toggle('selected', this.checked)">
                    <?= e($r['nombre']) ?>
                </strong>
                <?php if ($r['descripcion']): ?><small><?= e($r['descripcion']) ?></small><?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:18px;display:flex;gap:8px">
        <button type="submit" class="btn btn--primary">💾 Guardar cambios</button>
    </div>
</form>

<!-- Reset password -->
<form method="POST" class="usr-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="accion" value="reset_password">
    <h3 style="margin:0 0 8px;color:#7c3aed">🔑 Cambiar contraseña</h3>
    <label>Nueva contraseña</label>
    <input type="password" name="new_password" minlength="8" autocomplete="new-password">
    <label>Repetir</label>
    <input type="password" name="new_password2" minlength="8" autocomplete="new-password">
    <div style="margin-top:12px">
        <button type="submit" class="btn" style="background:#7c3aed;color:#fff">🔑 Resetear contraseña</button>
    </div>
</form>

<!-- Bloqueo -->
<div class="side-box <?= $bloqueado ? 'warn' : '' ?>">
    <?php if ($bloqueado): ?>
        <h4>🔒 Usuario bloqueado</h4>
        <p style="margin:0 0 10px;font-size:13px">Bloqueo hasta <?= e(date('d/m/Y H:i', strtotime($usr['bloqueado_hasta']))) ?>.</p>
        <form method="POST" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="accion" value="desbloquear">
            <button type="submit" class="btn btn--primary">🔓 Desbloquear ahora</button>
        </form>
    <?php else: ?>
        <h4>🔓 Usuario NO bloqueado</h4>
        <p style="margin:0 0 10px;font-size:13px">Intentos fallidos: <?= (int)$usr['intentos_fallidos'] ?></p>
        <?php if ($id !== $uidActual): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('¿Bloquear este usuario por 24 horas?');">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="accion" value="bloquear">
                <button type="submit" class="btn" style="background:#dc2626;color:#fff">🔒 Bloquear 24 horas</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
