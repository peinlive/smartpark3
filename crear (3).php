<?php
// /home/myzonaco/smartpark.myzona360.com/modules/usuarios/crear.php
// v1.0 (3V): Crear usuario nuevo + asignar roles.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esSuperAdmin = auth_has_role('super_admin');

$errores = [];
$prev = ['username'=>'','nombre_completo'=>'','email'=>'','celular'=>'','activo'=>1,'roles'=>[]];

// Roles disponibles (admin no puede asignar super_admin)
$rolesQ = $pdo->query("SELECT id, codigo, nombre, descripcion, nivel FROM roles ORDER BY nivel DESC");
$rolesAll = $rolesQ->fetchAll();
if (!$esSuperAdmin) {
    $rolesAll = array_values(array_filter($rolesAll, function($r){ return $r['codigo'] !== 'super_admin'; }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $prev['username']        = strtolower(clean_string($_POST['username'] ?? '', 50));
    $prev['nombre_completo'] = clean_string($_POST['nombre_completo'] ?? '', 120);
    $prev['email']           = clean_string($_POST['email'] ?? '', 120);
    $prev['celular']         = clean_string($_POST['celular'] ?? '', 30);
    $prev['activo']          = isset($_POST['activo']) ? 1 : 0;
    $prev['roles']           = array_map('intval', (array)($_POST['roles'] ?? []));
    $password                = (string)($_POST['password'] ?? '');
    $password2               = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $prev['username'])) $errores[] = 'Username inválido (3-50 chars: a-z 0-9 . _ -).';
    if ($prev['nombre_completo'] === '') $errores[] = 'Nombre completo obligatorio.';
    if ($prev['email'] !== '' && !filter_var($prev['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido.';
    if (strlen($password) < 8) $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    if ($password !== $password2) $errores[] = 'Las contraseñas no coinciden.';
    if (empty($prev['roles'])) $errores[] = 'Selecciona al menos un rol.';

    // Validar que los roles seleccionados sean válidos y permitidos
    $rolesIdsPermitidos = array_column($rolesAll, 'id');
    foreach ($prev['roles'] as $ridSel) {
        if (!in_array($ridSel, $rolesIdsPermitidos)) { $errores[] = 'Rol inválido seleccionado.'; break; }
    }

    // Unicidad del username
    if (empty($errores)) {
        $stU = $pdo->prepare("SELECT id FROM usuarios WHERE username = :us LIMIT 1");
        $stU->execute([':us' => $prev['username']]);
        if ($stU->fetchColumn()) $errores[] = 'Ya existe un usuario con ese username.';
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO usuarios
                    (conjunto_id, username, password_hash, nombre_completo, email, celular, activo)
                VALUES (:c, :us, :ph, :nm, :em, :cl, :ac)");
            $ins->execute([
                ':c'  => $conjuntoId,
                ':us' => $prev['username'],
                ':ph' => $hash,
                ':nm' => $prev['nombre_completo'],
                ':em' => $prev['email'] !== '' ? $prev['email'] : null,
                ':cl' => $prev['celular'] !== '' ? $prev['celular'] : null,
                ':ac' => $prev['activo'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            $insR = $pdo->prepare("INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (:u, :r)");
            foreach ($prev['roles'] as $rid) {
                $insR->execute([':u' => $newId, ':r' => $rid]);
            }

            $pdo->commit();
            flash_set('ok', 'Usuario creado.');
            redirect('/usuarios');
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al crear.';
        }
    }
}

$_pageTitle = 'Nuevo usuario';
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
</style>

<div class="page-head">
    <h1 class="page-head__title">🧑‍💼 Nuevo usuario</h1>
</div>

<div class="toolbar"><a class="btn" href="<?= url('/usuarios') ?>">← Volver</a></div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" class="usr-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <label>Username *</label>
    <input type="text" name="username" required maxlength="50" value="<?= e($prev['username']) ?>"
           style="text-transform:lowercase" autocomplete="off"
           oninput="this.value=this.value.toLowerCase()">
    <small style="color:#6b7280">Solo minúsculas, números y . _ - (3-50 chars).</small>

    <label>Nombre completo *</label>
    <input type="text" name="nombre_completo" required maxlength="120" value="<?= e($prev['nombre_completo']) ?>">

    <label>Email</label>
    <input type="email" name="email" maxlength="120" value="<?= e($prev['email']) ?>">

    <label>Celular</label>
    <input type="text" name="celular" maxlength="30" value="<?= e($prev['celular']) ?>">

    <label>Contraseña * (mín. 8 caracteres)</label>
    <input type="password" name="password" required minlength="8" autocomplete="new-password">

    <label>Repetir contraseña *</label>
    <input type="password" name="password2" required minlength="8" autocomplete="new-password">

    <label style="margin-top:16px">
        <input type="checkbox" name="activo" value="1" <?= $prev['activo'] ? 'checked' : '' ?>>
        Cuenta activa (puede iniciar sesión)
    </label>

    <label style="margin-top:16px">Roles asignados *</label>
    <div class="roles-grid">
        <?php foreach ($rolesAll as $r): ?>
            <label class="rol-card <?= in_array((int)$r['id'], $prev['roles']) ? 'selected' : '' ?>">
                <strong>
                    <input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>"
                           <?= in_array((int)$r['id'], $prev['roles']) ? 'checked' : '' ?>
                           onchange="this.closest('.rol-card').classList.toggle('selected', this.checked)">
                    <?= e($r['nombre']) ?>
                </strong>
                <?php if ($r['descripcion']): ?><small><?= e($r['descripcion']) ?></small><?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:18px;display:flex;gap:8px">
        <button type="submit" class="btn btn--primary">💾 Crear usuario</button>
        <a class="btn" href="<?= url('/usuarios') ?>">Cancelar</a>
    </div>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
