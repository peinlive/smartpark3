<?php
// /home/myzonaco/smartpark.myzona360.com/modules/auth/logout.php
if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_logout();
flash_set('ok', 'Sesión cerrada correctamente.');
redirect('/login');
