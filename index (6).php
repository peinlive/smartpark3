<?php
// /home/myzonaco/smartpark.myzona360.com/modules/perfil/index.php
// v1.0: Mi perfil del usuario logueado + cambio de contraseña.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// Cualquier usuario autenticado puede ver/editar su perfil
auth_require();

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
if ($uid < 1) redirect('/login');

$errores  = [];
$errores2 = [];
$mensajeOk = '';

// ─── Cargar datos actuales ───
$st = $pdo->prepare("SELECT id, conjunto_id, username, nombre_completo, email, celular,
                            ultimo_login, creado_en
                       FROM usuarios WHERE id = :id LIMIT 1");
$st->execute([':id' => $uid]);
$user = $st->fetch();
if (!$user) redirect('/login');

// Cargar roles del usuario
$stR = $pdo->prepare("SELECT r.nombre FROM usuario_roles ur
                        JOIN roles r ON r.id = ur.rol_id
                       WHERE ur.usuario_id = :id
                    ORDER BY r.nombre");
$stR->execute([':id' => $uid]);
$roles = $stR->fetchAll(PDO::FETCH_COLUMN);

/* ─────────────────────────────────────────────────────────────
   ACCIÓN 1: guardar datos personales
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_datos') {
    csrf_require();

    $nombre  = clean_string($_POST['nombre_completo'] ?? '', 150);
    $email   = clean_string($_POST['email']   ?? '', 150);
    $celular = clean_string($_POST['celular'] ?? '', 30);

    if ($nombre === '') $errores[] = 'El nombre completo es obligatorio.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no tiene un formato válido.';
    }

    // Validar que el email no lo tenga otro usuario
    if ($email !== '') {
        $stE = $pdo->prepare("SELECT id FROM usuarios
                               WHERE email = :em AND id <> :id LIMIT 1");
        $stE->execute([':em' => $email, ':id' => $uid]);
        if ($stE->fetchColumn()) $errores[] = 'Ese email ya está en uso por otro usuario.';
    }

    if (empty($errores)) {
        try {
            $up = $pdo->prepare("UPDATE usuarios SET
                    nombre_completo = :n, email = :em, celular = :ce
                WHERE id = :id");
            $up->execute([
                ':n'  => $nombre,
                ':em' => $email !== '' ? $email : null,
                ':ce' => $celular !== '' ? $celular : null,
                ':id' => $uid,
            ]);

            if (function_exists('flash_set')) flash_set('ok', 'Datos actualizados.');
            redirect('/perfil');
        } catch (Exception $ex) {
            $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al guardar.';
        }
    }

    // Repintar con lo que envió
    $user['nombre_completo'] = $nombre;
    $user['email']   = $email;
    $user['celular'] = $celular;
}

/* ─────────────────────────────────────────────────────────────
   ACCIÓN 2: cambiar contraseña
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_password') {
    csrf_require();

    $actual   = (string)($_POST['password_actual']  ?? '');
    $nueva    = (string)($_POST['password_nueva']   ?? '');
    $confirma = (string)($_POST['password_confirma']?? '');

    if ($actual === '')   $errores2[] = 'Debes ingresar tu contraseña actual.';
    if (strlen($nueva) < 6)         $errores2[] = 'La nueva contraseña debe tener al menos 6 caracteres.';
    if (strlen($nueva) > 128)       $errores2[] = 'Contraseña demasiado larga.';
    if ($nueva !== $confirma)       $errores2[] = 'La confirmación no coincide con la nueva contraseña.';
    if ($actual !== '' && $actual === $nueva) $errores2[] = 'La nueva contraseña debe ser diferente de la actual.';

    // Verificar contraseña actual
    if (empty($errores2)) {
        $stP = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = :id LIMIT 1");
        $stP->execute([':id' => $uid]);
        $hashActual = $stP->fetchColumn();
        if (!$hashActual || !password_verify($actual, $hashActual)) {
            $errores2[] = 'La contraseña actual no es correcta.';
        }
    }

    if (empty($errores2)) {
        try {
            $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE usuarios SET password_hash = :h WHERE id = :id");
            $up->execute([':h' => $nuevoHash, ':id' => $uid]);

            // Log de auditoría
            if (function_exists('flash_set')) flash_set('ok', 'Contraseña actualizada correctamente.');
            try {
                $pdo->prepare("INSERT INTO audit_log
                        (conjunto_id, usuario_id, accion, entidad, entidad_id, descripcion)
                     VALUES (:c, :u, 'password_cambio', 'usuario', :id, 'El usuario cambió su contraseña')")
                    ->execute([':c' => $user['conjunto_id'], ':u' => $uid, ':id' => $uid]);
            } catch (Exception $eLog) { /* no bloquear si falla el log */ }

            redirect('/perfil');
        } catch (Exception $ex) {
            $errores2[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al cambiar la contraseña.';
        }
    }
}

$_pageTitle = 'Mi perfil';
include INCLUDES_PATH . '/header.php';
?>

<style>
.perfil-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:14px;}
@media (max-width:900px){.perfil-grid{grid-template-columns:1fr;}}
.perfil-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;}
.perfil-card h2{margin:0 0 14px 0;font-size:16px;color:#1f2937;}
.perfil-card .form-row{margin-bottom:12px;}
.perfil-card label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.perfil-card input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:14px;}
.perfil-card input[readonly]{background:#f3f4f6;color:#6b7280;}
.perfil-card .help{font-size:12px;color:#6b7280;margin-top:3px;}
.perfil-meta{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:14px;font-size:13px;}
.perfil-meta strong{color:#1f2937;}
.perfil-meta .row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #f3f4f6;}
.perfil-meta .row:last-child{border-bottom:none;}
.roles-pills{display:flex;gap:6px;flex-wrap:wrap;}
.role-pill{background:#dbeafe;color:#1e3a8a;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:500;}
.pass-strength{margin-top:6px;font-size:12px;color:#6b7280;}
</style>

<div class="page-head">
    <h1 class="page-head__title">👤 Mi perfil</h1>
    <p class="page-head__sub">Actualiza tus datos y cambia tu contraseña.</p>
</div>

<div class="perfil-meta">
    <div class="row"><span>Usuario:</span> <strong><?= e($user['username']) ?></strong></div>
    <div class="row"><span>Roles:</span> <span class="roles-pills">
        <?php foreach ($roles as $r): ?>
            <span class="role-pill"><?= e($r) ?></span>
        <?php endforeach; ?>
        <?php if (empty($roles)): ?><span class="t-muted">— sin roles —</span><?php endif; ?>
    </span></div>
    <div class="row"><span>Cuenta creada:</span> <strong><?= $user['creado_en'] ? e(date('d/m/Y', strtotime($user['creado_en']))) : '—' ?></strong></div>
    <div class="row"><span>Último acceso:</span> <strong><?= $user['ultimo_login'] ? e(date('d/m/Y H:i', strtotime($user['ultimo_login']))) : '—' ?></strong></div>
</div>

<div class="perfil-grid">
    <!-- Datos personales -->
    <div class="perfil-card">
        <h2>📝 Datos personales</h2>

        <?php if (!empty($errores)): ?>
            <div class="flash flash--error" style="margin-bottom:10px">
                <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="accion" value="guardar_datos">

            <div class="form-row">
                <label>Usuario</label>
                <input type="text" value="<?= e($user['username']) ?>" readonly>
                <div class="help">El usuario no se puede cambiar.</div>
            </div>

            <div class="form-row">
                <label>Nombre completo *</label>
                <input type="text" name="nombre_completo" maxlength="150" required
                       value="<?= e($user['nombre_completo']) ?>">
            </div>

            <div class="form-row">
                <label>Email</label>
                <input type="email" name="email" maxlength="150"
                       value="<?= e($user['email']) ?>">
                <div class="help">Se usará para notificaciones y recuperación.</div>
            </div>

            <div class="form-row">
                <label>Celular</label>
                <input type="text" name="celular" maxlength="30"
                       value="<?= e($user['celular']) ?>" placeholder="+57 300 000 0000">
            </div>

            <button type="submit" class="btn btn--primary">Guardar cambios</button>
        </form>
    </div>

    <!-- Cambio de contraseña -->
    <div class="perfil-card">
        <h2>🔒 Cambiar contraseña</h2>

        <?php if (!empty($errores2)): ?>
            <div class="flash flash--error" style="margin-bottom:10px">
                <ul style="margin:0 0 0 18px"><?php foreach ($errores2 as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="accion" value="cambiar_password">

            <div class="form-row">
                <label>Contraseña actual *</label>
                <input type="password" name="password_actual" required autocomplete="current-password">
            </div>

            <div class="form-row">
                <label>Nueva contraseña *</label>
                <input type="password" name="password_nueva" required minlength="6" maxlength="128"
                       autocomplete="new-password" id="pass-nueva" oninput="checkPassStrength()">
                <div class="pass-strength" id="pass-strength">Mínimo 6 caracteres.</div>
            </div>

            <div class="form-row">
                <label>Confirmar nueva contraseña *</label>
                <input type="password" name="password_confirma" required minlength="6" maxlength="128"
                       autocomplete="new-password" id="pass-confirma" oninput="checkPassMatch()">
                <div class="pass-strength" id="pass-match"></div>
            </div>

            <button type="submit" class="btn btn--primary">Cambiar contraseña</button>
        </form>
    </div>
</div>

<script>
function checkPassStrength() {
    var v = document.getElementById('pass-nueva').value;
    var el = document.getElementById('pass-strength');
    if (v.length === 0) { el.textContent = 'Mínimo 6 caracteres.'; el.style.color = '#6b7280'; return; }
    if (v.length < 6) { el.textContent = '⚠️ Muy corta (mínimo 6).'; el.style.color = '#dc2626'; return; }
    var score = 0;
    if (/[a-z]/.test(v)) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^a-zA-Z0-9]/.test(v)) score++;
    if (v.length >= 12) score++;
    var t, c;
    if (score <= 1) { t = '🔴 Débil'; c = '#dc2626'; }
    else if (score <= 2) { t = '🟡 Aceptable'; c = '#d97706'; }
    else if (score <= 3) { t = '🟢 Buena'; c = '#15803d'; }
    else                { t = '💪 Fuerte'; c = '#15803d'; }
    el.textContent = t; el.style.color = c;
    checkPassMatch();
}
function checkPassMatch() {
    var a = document.getElementById('pass-nueva').value;
    var b = document.getElementById('pass-confirma').value;
    var el = document.getElementById('pass-match');
    if (b.length === 0) { el.textContent = ''; return; }
    if (a === b) { el.textContent = '✓ Coinciden'; el.style.color = '#15803d'; }
    else         { el.textContent = '✗ No coinciden'; el.style.color = '#dc2626'; }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
