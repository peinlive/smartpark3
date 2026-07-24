<?php
// ARCHIVO TEMPORAL DE EMERGENCIA - ELIMINAR DESPUÉS DE USARLO
// Sube este archivo a la raíz del servidor como logout_forzado.php
// Luego visita: https://smartpark.myzona360.com/logout_forzado.php
// Esto limpia la sesión corrupta y te redirige al login.

session_name('SMARTPARK_SESS');
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Sesión limpiada</title></head>
<body style="font-family:system-ui;padding:40px;text-align:center;background:#f0fdf4">
    <h1 style="color:#166534">✅ Sesión limpiada</h1>
    <p>Tu sesión fue eliminada correctamente.</p>
    <p><a href="https://smartpark.myzona360.com/login"
          style="background:#1e6cff;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">
       → Ir al Login
    </a></p>
    <p style="margin-top:24px;color:#6b7280;font-size:13px">
       ⚠️ Elimina este archivo del servidor después de iniciar sesión.
    </p>
</body>
</html>