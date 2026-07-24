<?php
// /home/myzonaco/smartpark.myzona360.com/includes/csv_helpers.php
// ──────────────────────────────────────────────────────────────
// Helpers de normalización y comparación para imports.
// Los CSV generados desde el sistema usan ; como delimitador
// para que Excel en español los abra como columnas directamente.
// ──────────────────────────────────────────────────────────────

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Normaliza nombre para comparación: minúsculas, sin tildes,
 * sin espacios extra.
 */
function normalizar_nombre(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $from = ['á','é','í','ó','ú','ñ','ü','à','è','ì','ò','ù','â','ê','î','ô','û'];
    $to   = ['a','e','i','o','u','n','u','a','e','i','o','u','a','e','i','o','u'];
    $s = str_replace($from, $to, $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?? '';
}

/**
 * Normaliza un celular: quita caracteres no numéricos.
 */
function normalizar_celular(string $s): string
{
    $s = preg_replace('/[^0-9]/', '', $s);
    return $s ?? '';
}

/**
 * Normaliza tipo de residente del Excel a los valores de la BD.
 *   inquilino  → inquilino
 *   inqu       → inquilino
 *   propietario → propietario
 *   prop       → propietario
 *   familiar   → familiar
 *   cualquier otro → otro
 */
function normalizar_tipo_residente(string $s): string
{
    $s = strtolower(trim($s));
    $map = [
        'inquilino'   => 'inquilino',
        'inqu'        => 'inquilino',
        'inq'         => 'inquilino',
        'inqui'       => 'inquilino',
        'arrendatario'=> 'inquilino',
        'propietario' => 'propietario',
        'prop'        => 'propietario',
        'dueno'       => 'propietario',
        'dueño'       => 'propietario',
        'familiar'    => 'familiar',
        'fam'         => 'familiar',
    ];
    return $map[$s] ?? 'otro';
}

/**
 * Genera el contenido de un CSV listo para descarga.
 * Usa ; como delimitador (compatibilidad con Excel español/Latam)
 * y BOM UTF-8 para que las tildes se vean bien.
 */
function csv_generar_errores(array $errores): string
{
    if (empty($errores)) return '';

    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

    $headers = array_keys($errores[0]);
    fputcsv($out, $headers, ';', '"', '\\');
    foreach ($errores as $row) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = $row[$h] ?? '';
        }
        fputcsv($out, $line, ';', '"', '\\');
    }

    rewind($out);
    $contenido = stream_get_contents($out);
    fclose($out);
    return $contenido;
}