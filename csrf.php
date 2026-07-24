<?php
// /home/myzonaco/smartpark.myzona360.com/includes/csrf.php
// v6.8 — Sesión de LARGA DURACIÓN para la ronda offline.
//
// ═══════════════════════════════════════════════════════════════════════
// EL PROBLEMA QUE RESUELVE
// ═══════════════════════════════════════════════════════════════════════
// Antes:
//     ini_set('session.gc_maxlifetime', '7200');   // 2 horas
//     ini_set('session.cookie_lifetime', '7200');  // 2 horas
//
// La ronda inicia sesión, baja al sótano, trabaja 40-60 min con el celular
// suspendido entre celda y celda... y al volver la sesión YA MURIÓ.
// Resultado: "CSRF inválido", pide re-login, y el trabajo queda atascado.
//
// Peor todavía: en un cPanel COMPARTIDO, el garbage collector de PHP limpia
// las sesiones de TODOS los sitios del servidor. Aunque pongas 7200, otro
// sitio con gc_maxlifetime bajo puede barrer tus archivos de sesión ANTES.
// Por eso a veces se caía a los 20-30 minutos.
//
// ═══════════════════════════════════════════════════════════════════════
// LA SOLUCIÓN
// ═══════════════════════════════════════════════════════════════════════
// 1. Directorio de sesiones PROPIO (/storage/sessions).
//    El GC de otros sitios ya no las puede tocar.
//
// 2. Vida larga: 30 días.
//    Es lo que hace cualquier app móvil. La ronda no debería re-loguearse
//    todos los días, y menos en el sótano donde no hay señal.
//
// 3. La cookie se RENUEVA en cada request.
//    Mientras la ronda use la app, la sesión se mantiene viva.
//
// ═══════════════════════════════════════════════════════════════════════
// SOBRE LA SEGURIDAD (hay que decirlo)
// ═══════════════════════════════════════════════════════════════════════
// Una sesión de 30 días es MENOS segura que una de 2 horas: si roban el
// celular desbloqueado, el acceso queda abierto más tiempo.
//
// Mitigaciones incluidas:
//   - cookie HttpOnly  -> JavaScript no la puede leer (anti-XSS)
//   - cookie Secure    -> solo viaja por HTTPS
//   - SameSite=Lax     -> anti-CSRF a nivel navegador
//   - Se puede cerrar sesión desde el panel (invalida el usuario)
//
// Si algún día hace falta más control, lo correcto sería un token de
// dispositivo revocable, no acortar la sesión.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

if (session_status() === PHP_SESSION_NONE) {

    // ── 1) Directorio de sesiones propio ────────────────────────────────
    // Sin esto, el GC compartido de cPanel puede borrar la sesión antes de
    // tiempo, sin importar lo que digan los ini_set de abajo.
    $dirSes = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($dirSes)) {
        @mkdir($dirSes, 0700, true);
    }
    if (is_dir($dirSes) && is_writable($dirSes)) {
        // Proteger el directorio por si quedara accesible por web
        $ht = $dirSes . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        session_save_path($dirSes);
    }

    // ── 2) Vida de la sesión: 30 días ───────────────────────────────────
    $vida = 60 * 60 * 24 * 30;           // 2.592.000 s
    ini_set('session.gc_maxlifetime', (string)$vida);
    ini_set('session.cookie_lifetime', (string)$vida);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');   // GC muy poco frecuente

    // ── 3) Cookie segura ────────────────────────────────────────────────
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $vida,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,          // JS no la puede leer
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params($vida, '/; samesite=Lax', '', $https, true);
    }

    session_start();

    // ── 4) Renovar la cookie en cada request ────────────────────────────
    // Mientras la ronda use la app, la sesión se mantiene viva.
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $vida,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_SESSION['_ultimo_acceso'] = time();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Fuerza un token nuevo (llamar tras login exitoso y al expirar). */
function csrf_regenerate(): string
{
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

/**
 * Verifica el token. Acepta varios nombres de campo y el header X-CSRF-Token.
 * Devuelve bool — no interrumpe la ejecución.
 */
function csrf_check(): bool
{
    $enviado = $_POST['_csrf']
        ?? $_POST['csrf_token']
        ?? $_POST['_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $esperado = $_SESSION['_csrf'] ?? '';
    if ($esperado === '' || $enviado === '') return false;
    return hash_equals($esperado, $enviado);
}

/** Corta con 403 si el token no es válido. */
function csrf_require(): void
{
    if (!csrf_check()) {
        http_response_code(403);
        exit('CSRF token inválido');
    }
}
