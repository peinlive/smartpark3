<?php
// /home/myzonaco/smartpark.myzona360.com/modules/auth/login.php
// v3.0 DEFINITIVO: usa auth_login() del sistema (guarda roles por CODIGO),
//   manejo suave de CSRF expirado, anti-cache, toggle de contraseña.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

// Anti-cache: fuerza siempre página fresca
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (auth_check()) {
    redirect('/dashboard');
}

$errores      = [];
$sesionExpiro = false;
$usuario_prev = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF suave: si expiró, no mostrar error rojo, solo regenerar y avisar
    if (!csrf_check()) {
        csrf_regenerate();
        $sesionExpiro = true;
        $usuario_prev = clean_string($_POST['usuario'] ?? '', 100);
    } else {

        $usuario_prev = clean_string($_POST['usuario'] ?? '', 100);
        $password     = (string)($_POST['password'] ?? '');

        if ($usuario_prev === '' || $password === '') {
            $errores[] = 'Usuario y contraseña son obligatorios.';
        } else {
            // auth_login() guarda roles por CODIGO (super_admin, admin, etc.)
            // y maneja bloqueos, intentos fallidos y session_regenerate_id()
            $ok = auth_login($usuario_prev, $password);
            if ($ok) {
                csrf_regenerate(); // token nuevo tras login exitoso
                flash_set('ok', 'Bienvenido, ' . (auth_user()['nombre_completo'] ?? ''));
                redirect('/dashboard');
            } else {
                $errores[] = 'Usuario o contraseña incorrectos.';
            }
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark · Iniciar sesión</title>
    <link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>">
</head>
<body class="login-page">
    <main class="login">
        <div class="login__brand">
            <h1>🅿️ SmartPark</h1>
            <p>Sistema de gestión de parqueadero</p>
        </div>

        <?php if ($sesionExpiro): ?>
            <div class="flash flash--warn">
                ⏱️ Tu sesión expiró por inactividad. Vuelve a ingresar.
            </div>
        <?php endif; ?>

        <?php if (!empty($errores)): ?>
            <div class="flash flash--error">
                <?php foreach ($errores as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="login__form" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

            <label class="field">
                <span>Usuario</span>
                <input type="text" name="usuario" required maxlength="100"
                       value="<?= e($usuario_prev) ?>"
                       autocomplete="username" autofocus>
            </label>

            <label class="field">
                <span>Contraseña</span>
                <div class="password-wrap">
                    <input type="password" name="password" id="passwordInput" required
                           autocomplete="current-password" maxlength="200">
                    <button type="button" class="password-toggle" id="togglePass"
                            aria-label="Mostrar contraseña" title="Mostrar contraseña">
                        <span id="eyeIcon">👁️</span>
                    </button>
                </div>
            </label>

            <button type="submit" class="btn btn--primary btn--block">
                Iniciar sesión
            </button>
        </form>

        <p class="login__foot">
            SmartPark v1.0 · <?= date('Y') ?> · Acceso autorizado únicamente.
        </p>
    </main>

    <style>
    .password-wrap { position: relative; }
    .password-wrap input { padding-right: 42px !important; width: 100%; }
    .password-toggle {
        position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
        background: transparent; border: none; cursor: pointer;
        font-size: 18px; padding: 6px 10px; line-height: 1;
        border-radius: 6px; transition: background .15s;
    }
    .password-toggle:hover { background: rgba(0,0,0,.06); }
    .password-toggle:focus { outline: 2px solid var(--color-primary); outline-offset: 1px; }
    </style>

    <script>
    (function () {
        var btn  = document.getElementById('togglePass');
        var inp  = document.getElementById('passwordInput');
        var icon = document.getElementById('eyeIcon');
        if (!btn || !inp) return;
        btn.addEventListener('click', function () {
            if (inp.type === 'password') {
                inp.type = 'text';
                icon.textContent = '🙈';
                btn.setAttribute('aria-label', 'Ocultar contraseña');
            } else {
                inp.type = 'password';
                icon.textContent = '👁️';
                btn.setAttribute('aria-label', 'Mostrar contraseña');
            }
            inp.focus();
        });
    })();
    </script>
</body>
</html>