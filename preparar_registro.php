<?php
// /home/myzonaco/smartpark.myzona360.com/modules/consultas/preparar_registro.php
// v1.0 (3AB): Copia la foto del OCR a la carpeta de vehículos/visitantes y
//   redirige al formulario de crear pasándola como pre-cargada.
//
//   La foto pre-cargada se pasa por SESSION. Para consumirla en tus formularios
//   ya existentes (/modules/vehiculos/crear.php y /modules/visitantes/crear.php),
//   agrega al inicio del PHP:
//
//     if (session_status() !== PHP_SESSION_ACTIVE) session_start();
//     $fotoPrecargada = $_SESSION['foto_precargada_veh'] ?? '';
//     if ($fotoPrecargada) unset($_SESSION['foto_precargada_veh']);
//
//   Y en el HTML del formulario, mostrar la foto (o campo hidden):
//     <input type="hidden" name="foto_precargada" value="'.htmlspecialchars($fotoPrecargada).'">
//     <img src="/uploads/temp_registro/xxx.jpg" style="max-width:200px">
//
//   Y en el handler POST: si $_POST['foto_precargada'] no está vacío,
//   moverla desde /uploads/temp_registro/ a /uploads/vehiculos/ (o donde
//   guardas normalmente las fotos de vehículo).
//
//   Si no modificas esos formularios, este endpoint no rompe nada: solo
//   deja la foto en /uploads/temp_registro/ y el usuario deberá subir
//   la foto normalmente. Las fotos huérfanas quedan en temp_registro
//   para limpieza periódica.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda'); // v4b: +ronda (hace revistas desde el sotano, sin señal)

$placa    = strtoupper(preg_replace('/[^A-Z0-9]/i','', clean_string($_GET['placa'] ?? '', 15)));
$fotoUrl  = clean_string($_GET['foto'] ?? '', 500);
$tipo     = in_array($_GET['tipo'] ?? '', ['vehiculo','visitante'], true) ? $_GET['tipo'] : 'vehiculo';

if ($placa === '') { flash_set('error', 'Placa vacía'); redirect('/consultas'); }

// Convertir foto absoluta a relativa dentro de /uploads si es necesario
$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';
$fotoRel = '';
if ($fotoUrl !== '') {
    // Extraer solo la parte después de /uploads/
    if (preg_match('#/uploads/(.+)$#', $fotoUrl, $m)) {
        $fotoRel = $m[1];
    }
}

if ($fotoRel !== '' && is_file($baseUploads . '/' . $fotoRel)) {
    // Copiar al directorio temp_registro para que el form la use
    $tempDir = $baseUploads . '/temp_registro';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);

    $ext = strtolower(pathinfo($fotoRel, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png'], true)) $ext = 'jpg';
    $nuevoNombre = 'reg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $tempDir . '/' . $nuevoNombre;

    if (@copy($baseUploads . '/' . $fotoRel, $destino)) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['foto_precargada_veh'] = 'temp_registro/' . $nuevoNombre;
        flash_set('ok', '📸 Foto del OCR lista para usar en el registro.');
    } else {
        flash_set('warn', 'No se pudo copiar la foto. Regístralo y sube foto manualmente.');
    }
}

// Redirigir al formulario correspondiente
$destinoUrl = $tipo === 'visitante'
    ? '/visitantes/crear?placa=' . urlencode($placa)
    : '/vehiculos/crear?placa='  . urlencode($placa);

redirect($destinoUrl);
