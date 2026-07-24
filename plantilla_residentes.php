<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/plantilla_residentes.php
// Descarga la plantilla CSV de residentes (delimitador ;).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

// Usar ; como delimitador para compatibilidad con Excel en español/Latam.
// BOM UTF-8 al inicio para que Excel respete tildes y caracteres especiales.
$content =
    "apto;tipo;nombre;celular\n" .
    "101;inquilino;Carolina Posada;3215655119\n" .
    "102;propietario;Luis Hoyos;3177969774\n" .
    "102;propietario;Luz Hoyos;3113387190\n" .
    "103;inquilino;Sandra Maribel Gil;3117265853\n" .
    "117;propietario;Jonny Salazar;3148437145\n";

$content = "\xEF\xBB\xBF" . $content;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_residentes.csv"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store');
echo $content;
exit;