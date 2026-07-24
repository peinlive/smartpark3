<?php
// /home/myzonaco/smartpark.myzona360.com/config/db.php
// ──────────────────────────────────────────────────────────────
// Conexión a la base de datos MySQL/MariaDB usando PDO.
//
// ⚠️  IMPORTANTE: REEMPLAZAR EL VALOR DE DB_PASS POR LA
//     CONTRASEÑA REAL DEL USUARIO `myzonaco_spuser` ANTES DE
//     SUBIR EL ARCHIVO AL SERVIDOR.
//
// Este archivo NUNCA debe quedar en repositorios públicos.
// El .htaccess de /config/ bloquea acceso HTTP directo.
// ──────────────────────────────────────────────────────────────

// Bloquear acceso directo
if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// ───── Credenciales de la BD ─────
define('DB_HOST',    'localhost');
define('DB_NAME',    'myzonaco_smartpark');
define('DB_USER',    'myzonaco_spuser');
define('DB_PASS',    'U#fnTNd8(ZrRzQdb');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT',    3306);

// ───── Singleton de conexión PDO ─────
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (APP_DEBUG) {
            http_response_code(500);
            exit('DB ERROR: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        error_log('SmartPark DB error: ' . $e->getMessage());
        http_response_code(500);
        exit('Error de conexión con la base de datos. Intente más tarde.');
    }

    return $pdo;
}
