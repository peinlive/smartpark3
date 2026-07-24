<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/plantilla_cuartos.php
// Descarga la plantilla CSV de cuartos útiles (delimitador ;).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

// Delimitador ; para compatibilidad con Excel en español/Latam.
// BOM UTF-8 al inicio para tildes y caracteres especiales.
$content =
    "Codigo;Nivel;Apto dueño;Area m2;Observaciones\n" .
    "CU-101;S99;918;3.5;\n" .
    "CU-102;S99;;4.0;Sin asignar\n" .
    "CU-201;P1;1502;2.8;Con escaleras\n" .
    "CU-A;;805;;Sin nivel asignado\n";

$content = "\xEF\xBB\xBF" . $content;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_cuartos.csv"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store');
echo $content;
exit;
