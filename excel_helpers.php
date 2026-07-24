<?php
// /home/myzonaco/smartpark.myzona360.com/includes/excel_helpers.php
// ──────────────────────────────────────────────────────────────
// Lector mínimo de XLSX usando ZipArchive + SimpleXML.
// Sin Composer, sin PhpSpreadsheet. Solo extensiones nativas
// estándar de PHP (zip, libxml, simplexml).
//
// Solo soporta lectura. Devuelve la primera hoja como array
// asociativo usando la primera fila como encabezados.
// ──────────────────────────────────────────────────────────────

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Lee un archivo .xlsx y retorna sus filas como array asociativo.
 *
 * @param string $path             Ruta al archivo .xlsx
 * @param array  $columnasRequeridas  Columnas (lowercase) que DEBEN existir
 * @return array{rows: array, headers: array, total: int}
 * @throws RuntimeException
 */
function xlsx_leer(string $path, array $columnasRequeridas = []): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión ZipArchive habilitada.');
    }

    if (!is_readable($path)) {
        throw new RuntimeException('No se puede leer el archivo XLSX.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el XLSX (puede estar dañado).');
    }

    // ─── Leer sharedStrings.xml (si existe) ───
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $sx = @simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($sx) {
            foreach ($sx->si as $si) {
                // Puede ser <t>texto</t> o múltiples <r><t>texto</t></r>
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $buf = '';
                    foreach ($si->r as $r) {
                        if (isset($r->t)) $buf .= (string)$r->t;
                    }
                    $shared[] = $buf;
                }
            }
        }
    }

    // ─── Leer hoja 1 ───
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('No se encontró la primera hoja en el XLSX.');
    }

    $sx = @simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$sx) {
        throw new RuntimeException('No se pudo parsear la hoja del XLSX.');
    }

    if (!isset($sx->sheetData) || !isset($sx->sheetData->row)) {
        return ['rows' => [], 'headers' => [], 'total' => 0];
    }

    $headers = [];
    $rows    = [];
    $rowIdx  = 0;

    foreach ($sx->sheetData->row as $row) {
        $rowIdx++;
        $rowData = [];

        foreach ($row->c as $c) {
            // ref tipo "A1", "B2", etc -> letra de columna
            $ref     = (string)($c['r'] ?? '');
            $colLetter = preg_replace('/[0-9]/', '', $ref);
            $colIndex  = _xlsx_letra_a_indice($colLetter);

            $type  = (string)($c['t'] ?? '');
            $value = isset($c->v) ? (string)$c->v : '';

            // Resolver valor según tipo
            if ($type === 's') {
                // Shared string
                $idx = (int)$value;
                $value = $shared[$idx] ?? '';
            } elseif ($type === 'b') {
                // Boolean
                $value = $value === '1' ? '1' : '0';
            } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                $value = (string)$c->is->t;
            } elseif ($type === 'str' && isset($c->v)) {
                $value = (string)$c->v;
            }
            // Numérico: $value ya viene como string del número

            $rowData[$colIndex] = trim($value);
        }

        if ($rowIdx === 1) {
            // Primera fila: encabezados
            ksort($rowData);
            foreach ($rowData as $i => $h) {
                $headers[$i] = strtolower(trim((string)$h));
            }
            // Validar columnas requeridas
            foreach ($columnasRequeridas as $req) {
                if (!in_array($req, $headers, true)) {
                    throw new RuntimeException("Falta la columna obligatoria: {$req}");
                }
            }
            continue;
        }

        // Saltar filas completamente vacías
        if (count(array_filter($rowData, fn($v) => $v !== '')) === 0) {
            continue;
        }

        // Convertir índices numéricos → claves por nombre de columna
        $assoc = [];
        foreach ($headers as $i => $name) {
            $assoc[$name] = $rowData[$i] ?? '';
        }
        $assoc['__line'] = $rowIdx;
        $rows[] = $assoc;
    }

    return [
        'rows'    => $rows,
        'headers' => $headers,
        'total'   => count($rows),
    ];
}

/**
 * Convierte letra de columna Excel (A, B, ..., Z, AA, AB...) a índice 0-based.
 */
function _xlsx_letra_a_indice(string $letras): int
{
    $letras = strtoupper($letras);
    $n = 0;
    for ($i = 0; $i < strlen($letras); $i++) {
        $n = $n * 26 + (ord($letras[$i]) - ord('A') + 1);
    }
    return $n - 1;
}
