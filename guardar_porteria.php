<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/guardar_porteria.php
// v3.0 (3AK): REDIRECT DE COMPATIBILIDAD.
//   El endpoint POST se movió a /configuracion/guardar_porteria.
//   Este archivo reenvía la petición POST a la nueva ubicación por si
//   alguien tiene el form action apuntando aquí.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

// Reenvío 307 (mantiene método POST)
header('Location: ' . url('/configuracion/guardar_porteria'), true, 307);
exit;
