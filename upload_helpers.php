<?php
// /home/myzonaco/smartpark.myzona360.com/includes/upload_helpers.php
// ──────────────────────────────────────────────────────────────
// Subida segura de imágenes con compresión y marca de agua.
//   - Valida MIME real (no solo extensión).
//   - Recomprime a JPEG máximo 1024x1024, calidad 80.
//   - Estampa fecha/hora abajo-derecha (texto blanco + sombra negra).
//   - Renombra con hash aleatorio.
// ──────────────────────────────────────────────────────────────

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

define('FOTO_MAX_BYTES',    8 * 1024 * 1024); // 8 MB de entrada
define('FOTO_MAX_DIM',      1024);
define('FOTO_JPEG_QUALITY', 80);

/**
 * Procesa $_FILES[...] y guarda como JPEG comprimido con marca de agua.
 * Retorna ruta relativa (ej: "vehiculos/abc123.jpg") o null si no se subió.
 */
function upload_foto_vehiculo(array $file, string $subdir = 'vehiculos'): ?string
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(_upload_err_msg($file['error']));
    }
    if (($file['size'] ?? 0) > FOTO_MAX_BYTES) {
        throw new RuntimeException('La foto excede el tamaño máximo permitido (8 MB).');
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Carga no válida.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        throw new RuntimeException('Formato no permitido. Solo JPG, PNG o WEBP.');
    }
    if (!function_exists('imagecreatefromjpeg')) {
        throw new RuntimeException('El servidor no tiene la extensión GD habilitada.');
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default      => false,
    };
    if (!$src) throw new RuntimeException('No se pudo procesar la imagen.');

    // Manejar orientación EXIF (fotos de celular)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        if (!empty($exif['Orientation'])) {
            switch ((int)$exif['Orientation']) {
                case 3: $src = imagerotate($src, 180, 0); break;
                case 6: $src = imagerotate($src, -90, 0); break;
                case 8: $src = imagerotate($src,  90, 0); break;
            }
        }
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Redimensionar manteniendo aspect ratio
    if ($srcW > FOTO_MAX_DIM || $srcH > FOTO_MAX_DIM) {
        if ($srcW >= $srcH) {
            $newW = FOTO_MAX_DIM;
            $newH = (int)round($srcH * (FOTO_MAX_DIM / $srcW));
        } else {
            $newH = FOTO_MAX_DIM;
            $newW = (int)round($srcW * (FOTO_MAX_DIM / $srcH));
        }
    } else {
        $newW = $srcW; $newH = $srcH;
    }

    $dst = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($src);

    // ─── MARCA DE AGUA: fecha/hora abajo-derecha ───
    _aplicar_marca_agua($dst, $newW, $newH);

    // Guardar
    $base = UPLOADS_PATH . '/' . trim($subdir, '/');
    if (!is_dir($base)) @mkdir($base, 0755, true);

    $filename = bin2hex(random_bytes(8)) . '_' . time() . '.jpg';
    $fullPath = $base . '/' . $filename;
    $relPath  = trim($subdir, '/') . '/' . $filename;

    $ok = imagejpeg($dst, $fullPath, FOTO_JPEG_QUALITY);
    imagedestroy($dst);

    if (!$ok) throw new RuntimeException('No se pudo guardar la imagen.');
    return $relPath;
}

/**
 * Estampa fecha/hora abajo-derecha (texto blanco + sombra negra).
 */
function _aplicar_marca_agua($img, int $w, int $h): void
{
    $texto  = date('Y-m-d H:i');
    $padding = 8;

    // Usar fuente GD interna (font 5 es la más grande, fallback portable)
    $font   = 5;
    $charW  = imagefontwidth($font);
    $charH  = imagefontheight($font);
    $textW  = strlen($texto) * $charW;

    $x = $w - $textW - $padding;
    $y = $h - $charH - $padding;

    $sombra = imagecolorallocatealpha($img, 0, 0, 0, 40);
    $blanco = imagecolorallocate($img, 255, 255, 255);

    // Sombra negra (1px offset en todas direcciones para outline)
    foreach ([[-1,-1],[1,-1],[-1,1],[1,1],[0,-1],[0,1],[-1,0],[1,0]] as [$dx,$dy]) {
        imagestring($img, $font, $x + $dx, $y + $dy, $texto, $sombra);
    }
    // Texto blanco
    imagestring($img, $font, $x, $y, $texto, $blanco);
}

/** Elimina foto si existe. */
function eliminar_foto(string $relPath): bool
{
    if (empty($relPath)) return false;
    $relPath = str_replace(['..','\\'], '', $relPath);
    $full = UPLOADS_PATH . '/' . ltrim($relPath, '/');
    if (is_file($full)) return @unlink($full);
    return false;
}

/** URL pública de una foto. */
function url_foto(?string $relPath): string
{
    if (empty($relPath)) return '';
    return rtrim(APP_URL, '/') . '/uploads/' . ltrim($relPath, '/');
}

function _upload_err_msg(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La foto es demasiado grande.',
        UPLOAD_ERR_PARTIAL    => 'La carga se interrumpió. Vuelve a intentarlo.',
        UPLOAD_ERR_NO_FILE    => 'No se seleccionó ninguna foto.',
        UPLOAD_ERR_NO_TMP_DIR => 'Error del servidor: sin carpeta temporal.',
        UPLOAD_ERR_CANT_WRITE => 'Error del servidor: no se puede escribir el archivo.',
        UPLOAD_ERR_EXTENSION  => 'Carga bloqueada por extensión PHP.',
        default               => 'Error desconocido al cargar la foto.',
    };
}

/**
 * Normaliza placa: mayúsculas, sin espacios ni guiones.
 * Si no existe en helpers.php base, ponemos aquí también por seguridad.
 */
if (!function_exists('normalizar_placa')) {
    function normalizar_placa(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($s))));
    }
}

/**
 * Detecta el tipo de vehículo por formato de placa colombiano.
 *   ABC123  → carro
 *   ABC12D  → moto
 */
function detectar_tipo_placa(string $placa): string
{
    $p = normalizar_placa($placa);
    if (preg_match('/^[A-Z]{3}\d{2}[A-Z]$/', $p)) return 'moto';
    if (preg_match('/^[A-Z]{2}\d{3}[A-Z]$/', $p)) return 'moto';
    if (preg_match('/^[A-Z]{3}\d{3}$/',      $p)) return 'carro';
    return 'carro';
}
