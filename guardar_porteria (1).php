<?php
// /home/myzonaco/smartpark.myzona360.com/modules/configuracion/guardar_porteria.php
// v2.0 (3AK): Guarda la configuración de portería en JSON.
//   Movido desde /modules/consultas/guardar_porteria.php.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/configuracion/porteria');
csrf_require();

$u = auth_user();
$uid = (int)($u['id'] ?? 0);
$nombreUsuario = $u['nombre_completo'] ?? ($u['usuario'] ?? 'usuario_' . $uid);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$numPrincipal    = preg_replace('/[^0-9]/', '', (string)($_POST['numero_principal'] ?? ''));
$nombrePrincipal = clean_string($_POST['nombre_principal'] ?? '', 100);

if (strlen($numPrincipal) < 10 || strlen($numPrincipal) > 15) {
    flash_set('error', 'El número principal debe tener entre 10 y 15 dígitos (código país + número).');
    redirect('/configuracion/porteria');
}

// Adicionales: array de objetos {numero, nombre}
$adicionales = [];
if (!empty($_POST['adicionales']) && is_array($_POST['adicionales'])) {
    foreach ($_POST['adicionales'] as $item) {
        if (!is_array($item)) continue;
        $n = preg_replace('/[^0-9]/', '', (string)($item['numero'] ?? ''));
        $nm = clean_string($item['nombre'] ?? '', 100);
        if (strlen($n) < 10 || strlen($n) > 15) continue;
        // Evitar duplicar el principal
        if ($n === $numPrincipal) continue;
        $adicionales[] = ['numero' => $n, 'nombre' => $nm];
    }
    // Deduplicar por número
    $seen = [];
    $adicionales = array_values(array_filter($adicionales, function($x) use (&$seen){
        if (isset($seen[$x['numero']])) return false;
        $seen[$x['numero']] = true;
        return true;
    }));
}

$config = [
    'numero_principal'    => $numPrincipal,
    'nombre_principal'    => $nombrePrincipal,
    'numeros_adicionales' => $adicionales,
    'actualizado_en'      => date('d/m/Y H:i:s'),
    'actualizado_por'     => $nombreUsuario,
];

$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';
$dirCfg = $baseUploads . '/config';
if (!is_dir($dirCfg)) @mkdir($dirCfg, 0755, true);

$archivo = $dirCfg . '/porteria_' . $conjuntoId . '.json';
$ok = @file_put_contents($archivo, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($ok === false) {
    flash_set('error', 'No se pudo guardar el archivo de configuración. Verifica permisos en /uploads/config/.');
} else {
    flash_set('ok', '✅ Configuración guardada. El número principal es ' . $numPrincipal .
        (count($adicionales) > 0 ? ' + ' . count($adicionales) . ' contacto(s) adicional(es).' : '.'));
}

redirect('/configuracion/porteria');
