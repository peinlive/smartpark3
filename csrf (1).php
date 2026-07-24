<?php
// /home/myzonaco/smartpark.myzona360.com/api/csrf.php
// v6.4 — Devuelve un token CSRF FRESCO.
//
// EL PROBLEMA QUE RESUELVE:
//   El token se genera cuando se carga /offline y queda "congelado" en la
//   pagina. La ronda baja al sotano, trabaja 40-60 minutos, vuelve y pulsa
//   Sincronizar... pero para entonces la sesion de PHP ya caduco y ese token
//   NO SIRVE. El servidor responde "CSRF inválido" y TODO el trabajo se queda
//   atascado en la cola.
//
//   Peor aun: si el celular estuvo suspendido, o la PWA quedo abierta de un
//   dia para el otro, el token es viejisimo.
//
// SOLUCION:
//   El celular pide un token nuevo JUSTO ANTES de sincronizar. Si la sesion
//   sigue viva, devuelve uno valido. Si caduco, devuelve 401 y el celular
//   avisa "vuelve a iniciar sesion" en vez de fallar en silencio.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ¿Sigue viva la sesion?
$u = function_exists('auth_user') ? auth_user() : null;
if (!$u || empty($u['id'])) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => 'sesion_expirada',
        'msg'   => 'La sesión expiró. Vuelve a iniciar sesión para sincronizar.',
    ]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'csrf'    => csrf_token(),      // regenera/devuelve el de la sesion actual
    'usuario' => (string)($u['username'] ?? ''),
    'rol'     => (string)($u['rol'] ?? ''),
]);
