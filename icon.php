<?php
// /home/myzonaco/smartpark.myzona360.com/api/icon.php
// Genera PNG dinámico para íconos PWA (192 o 512).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

$size = (int)($_GET['size'] ?? 192);
if ($size < 32 || $size > 1024) $size = 192;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800'); // 7 días

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500); exit;
}

$img = imagecreatetruecolor($size, $size);
$bg = imagecolorallocate($img, 30, 108, 255); // var(--color-primary) #1e6cff
$white = imagecolorallocate($img, 255, 255, 255);
$shadow = imagecolorallocatealpha($img, 0, 0, 0, 90);

// Fondo redondeado simulado (cuadrado con esquinas no es perfecto pero suficiente)
imagefilledrectangle($img, 0, 0, $size, $size, $bg);

// Dibujar "P" centrada — usamos imagefilledrectangle/arc para que se vea fuerte
$cx = $size / 2; $cy = $size / 2;
$fontSize = (int)($size * 0.6);

// Sombra
imagestring($img, 5, $cx - 5, $cy - 8, 'P', $shadow);

// Si tenemos imagettftext disponible y TTF — no garantizado en hosting compartido
$ttf = null; // dejarlo simple, usar texto built-in
$builtinFont = 5; // el más grande de GD
$letter = "P";
$charW = imagefontwidth($builtinFont);
$charH = imagefontheight($builtinFont);

// Para tamaños grandes, dibujamos formas geométricas en vez del font pequeño
// Una P estilizada: rectángulo vertical + semicírculo arriba

$strokeWidth = (int)max(2, $size * 0.12);
$pHeight = (int)($size * 0.6);
$pWidth  = (int)($size * 0.4);
$pX = (int)($size * 0.3);
$pY = (int)($size * 0.2);

// Tronco vertical de la P
imagefilledrectangle($img, $pX, $pY, $pX + $strokeWidth, $pY + $pHeight, $white);

// Curva superior de la P (rectángulo + circulo simulado)
imagefilledrectangle($img, $pX, $pY, $pX + $pWidth, $pY + $strokeWidth, $white);
imagefilledrectangle($img, $pX + $pWidth - $strokeWidth, $pY, $pX + $pWidth, $pY + (int)($pHeight * 0.45), $white);
imagefilledrectangle($img, $pX, $pY + (int)($pHeight * 0.45) - $strokeWidth, $pX + $pWidth, $pY + (int)($pHeight * 0.45), $white);

imagepng($img);
imagedestroy($img);
