<?php
// /home/myzonaco/smartpark.myzona360.com/index.php
// Front Controller v1.8 (Entrega 3H - ordenamiento + dashboard vehículos + vínculo)

define('SMARTPARK_BOOT', true);

require __DIR__ . '/config/app.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';

$route = $_GET['route'] ?? '';
$route = trim((string)$route, '/');
$route = strtolower(preg_replace('#[^a-z0-9/_.-]#i', '', $route));

if ($route === 'manifest.json') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/manifest.json');
    exit;
}
if ($route === 'sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/sw.js');
    exit;
}

if ($route === '' || $route === 'index.php') {
    redirect(auth_check() ? '/dashboard' : '/login');
}

$routes = [
    'login' => MODULES_PATH . '/auth/login.php',
    'logout' => MODULES_PATH . '/auth/logout.php',
    'dashboard' => MODULES_PATH . '/dashboard/index.php',

    'residentes'            => MODULES_PATH . '/residentes/index.php',
    'residentes/crear'      => MODULES_PATH . '/residentes/crear.php',
    'residentes/ver'        => MODULES_PATH . '/residentes/ver.php',
    'residentes/editar'     => MODULES_PATH . '/residentes/editar.php',
    'residentes/mudanza'    => MODULES_PATH . '/residentes/mudanza.php',
    'residentes/restaurar'  => MODULES_PATH . '/residentes/restaurar.php',

    'vehiculos'             => MODULES_PATH . '/vehiculos/index.php',
    'vehiculos/crear'       => MODULES_PATH . '/vehiculos/crear.php',
    'vehiculos/ver'         => MODULES_PATH . '/vehiculos/ver.php',
    'vehiculos/editar'      => MODULES_PATH . '/vehiculos/editar.php',
    'vehiculos/archivar'    => MODULES_PATH . '/vehiculos/archivar.php',
    'vehiculos/restaurar'   => MODULES_PATH . '/vehiculos/restaurar.php',

    'visitantes'            => MODULES_PATH . '/visitantes/index.php',
    'visitantes/crear'      => MODULES_PATH . '/visitantes/crear.php',
    'visitantes/ver'        => MODULES_PATH . '/visitantes/ver.php',
    'visitantes/editar'     => MODULES_PATH . '/visitantes/editar.php',
    'visitantes/archivar'   => MODULES_PATH . '/visitantes/archivar.php',
    'visitantes/restaurar'  => MODULES_PATH . '/visitantes/restaurar.php',
    'visitantes/visita_mas' => MODULES_PATH . '/visitantes/visita_mas.php',

    'consultas'             => MODULES_PATH . '/consultas/index.php',
    'lecturas'              => MODULES_PATH . '/lecturas/index.php',

    'rondas'                => MODULES_PATH . '/rondas/index.php',
    'rondas/nueva'          => MODULES_PATH . '/rondas/nueva.php',
    'rondas/ejecutar'       => MODULES_PATH . '/rondas/ejecutar.php',
    'rondas/ver'            => MODULES_PATH . '/rondas/ver.php',
    'rondas/terminar'       => MODULES_PATH . '/rondas/terminar.php',
    'rondas/sincronizar'    => MODULES_PATH . '/rondas/sincronizar.php',

    'importaciones'                       => MODULES_PATH . '/importaciones/index.php',
    'importaciones/nueva'                 => MODULES_PATH . '/importaciones/nueva.php',
    'importaciones/preview'               => MODULES_PATH . '/importaciones/preview.php',
    'importaciones/confirmar'             => MODULES_PATH . '/importaciones/confirmar.php',
    'importaciones/resultado'             => MODULES_PATH . '/importaciones/resultado.php',
    'importaciones/detalle'               => MODULES_PATH . '/importaciones/detalle.php',
    'importaciones/plantilla_residentes'  => MODULES_PATH . '/importaciones/plantilla_residentes.php',
    'importaciones/plantilla_vehiculos'   => MODULES_PATH . '/importaciones/plantilla_vehiculos.php',

    'api/search_apto'          => BASE_PATH . '/api/search_apto.php',
    'api/search_placa'         => BASE_PATH . '/api/search_placa.php',
    'api/residentes_apto'      => BASE_PATH . '/api/residentes_apto.php',
    'api/vehiculos_apto'       => BASE_PATH . '/api/vehiculos_apto.php',
    'api/dashboard_vehiculos'  => BASE_PATH . '/api/dashboard_vehiculos.php', // ← NUEVA v3h
    'api/ocr_placa'            => BASE_PATH . '/api/ocr_placa.php',
    'api/rondas_paso'          => BASE_PATH . '/api/rondas_paso.php',
    'api/lecturas_batch'       => BASE_PATH . '/api/lecturas_batch.php',
    'api/icon'                 => BASE_PATH . '/api/icon.php',
];

if (isset($routes[$route])) { require $routes[$route]; exit; }

$placeholders = ['apartamentos','parqueadero','asignaciones',
                 'observaciones','usuarios','conjuntos','auditoria'];

if (in_array($route, $placeholders, true)) {
    auth_require();
    $_pageTitle = ucfirst($route);
    include INCLUDES_PATH . '/header.php';
    echo '<div class="page-head"><h1 class="page-head__title">' . e($_pageTitle) . '</h1></div>';
    echo '<div class="notice notice--info">Esta sección estará disponible en la próxima entrega.</div>';
    include INCLUDES_PATH . '/footer.php';
    exit;
}

http_response_code(404);
if (auth_check()) {
    $_pageTitle = 'No encontrado';
    include INCLUDES_PATH . '/header.php';
    echo '<div class="page-head"><h1 class="page-head__title">404</h1></div>';
    echo '<div class="notice notice--error">La ruta <code>/' . e($route) . '</code> no existe.</div>';
    include INCLUDES_PATH . '/footer.php';
} else {
    echo '<!DOCTYPE html><html lang="es"><meta charset="UTF-8"><title>404</title>';
    echo '<body style="font-family:system-ui;padding:40px;text-align:center;">';
    echo '<h1 style="font-size:64px;margin:0">404</h1>';
    echo '<p><a href="' . url('/login') . '">Ir al login</a></p></body></html>';
}
