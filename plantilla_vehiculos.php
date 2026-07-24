<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/plantilla_vehiculos.php
// v3j2: plantilla con columnas apto;placa;usuario;vinculo;observacion

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

while (ob_get_level()) ob_end_clean();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_vehiculos.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel

echo "apto;placa;usuario;vinculo;observacion\r\n";
echo "1502;QIX448;;residente;Carro principal del apto\r\n";
echo "1009;KYZ607;Juan Pérez;propietario;\r\n";
echo "902;GEZ044;María González;inquilino;\r\n";
echo "1308;KYU893;Pedro Visitante;visitante;Visita semanal\r\n";
echo "1222;ABC12D;;visitante;\r\n";

exit;
