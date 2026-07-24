<?php
// /home/myzonaco/smartpark.myzona360.com/config/app.php
// ──────────────────────────────────────────────────────────────
// Configuración general de la aplicación SmartPark.
// Este archivo NO contiene credenciales. Las credenciales van
// en config/db.php (y NUNCA se suben a Git).
// ──────────────────────────────────────────────────────────────

// Bloquear acceso directo
if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// ───── Datos básicos ─────
define('APP_NAME',    'SmartPark');
define('APP_VERSION', '1.0.0');
define('APP_URL',     'https://smartpark.myzona360.com');
define('APP_TIMEZONE','America/Bogota');

// ───── Modo de depuración ─────
// En etapa de desarrollo: true (muestra errores en pantalla).
// Producción: false (errores van a log de Apache).
define('APP_DEBUG', true);

// ───── Rutas absolutas del proyecto ─────
define('BASE_PATH',     dirname(__DIR__));
define('CONFIG_PATH',   BASE_PATH . '/config');
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('MODULES_PATH',  BASE_PATH . '/modules');
define('UPLOADS_PATH',  BASE_PATH . '/uploads');
define('ASSETS_URL',    APP_URL . '/assets');

// ───── Zona horaria ─────
date_default_timezone_set(APP_TIMEZONE);

// ───── Manejo de errores según modo ─────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ───── Sesiones endurecidas ─────
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly',  '1');
ini_set('session.cookie_secure',    '1');
ini_set('session.cookie_samesite',  'Lax');
ini_set('session.use_strict_mode',  '1');
ini_set('session.gc_maxlifetime',   '7200'); // 2 horas

session_name('SMARTPARK_SESS');
