<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/plantilla_celdas.php
// Descarga la plantilla CSV de celdas (delimitador ;).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

// Usar ; como delimitador para compatibilidad con Excel en español/Latam.
// BOM UTF-8 al inicio para que Excel respete tildes y caracteres especiales.
$content =
    "Codigo;Nivel;Tipo;Permite carro;Permite moto;Apto dueño;Observaciones\n" .
    "S99001;S99;Comun;X;;;\n" .
    "S99002;S99;Comun;X;;;\n" .
    "S99003;S99;Moto comun;;X;;\n" .
    "P1001;P1;Privada;X;X;101;\n" .
    "P1002;P1;Privada;X;X;102;Segundo carro del apto\n" .
    "P1003;P1;Movilidad reducida;X;;;Uso general, sin dueño fijo\n" .
    "P1004;P1;Libre;X;;;\n";

$content = "\xEF\xBB\xBF" . $content;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_celdas.csv"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store');
echo $content;
exit;