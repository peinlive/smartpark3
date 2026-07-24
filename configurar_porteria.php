<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/configurar_porteria.php
// v3.0 (3AK): REDIRECT DE COMPATIBILIDAD.
//   La configuración de WhatsApp portería se movió al módulo Configuración.
//   Este archivo solo redirige para no romper bookmarks/enlaces existentes.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

// Redirigir permanentemente a la nueva ubicación
redirect('/configuracion/porteria');
