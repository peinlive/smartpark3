<?php
// /home/myzonaco/smartpark.myzona360.com/modules/cuartos/importar.php
// v1.0: Importación masiva de cuartos útiles desde XLSX o CSV.
//       Basado en el patrón del importar.php de celdas.
//       Autocontenido: lector CSV y XLSX propios.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

if (session_status() === PHP_SESSION_NONE) session_start();

$errores   = [];
$fase      = 'subir'; // subir | preview | resultado
$resultado = null;

/* ─────────────────────────────────────────────────────────────
   HELPERS LOCALES (prefijo _cuImp_ para no chocar)
   ───────────────────────────────────────────────────────────── */

function _cuImp_normalizarHeader(string $h): string {
    $h = trim((string)$h);
    $h = function_exists('mb_strtolower') ? mb_strtolower($h, 'UTF-8') : strtolower($h);
    $h = strtr($h, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n']);
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim((string)$h, '_');
}

function _cuImp_buscarCampo(array $row, array $posibles) {
    $normalizados = [];
    foreach ($row as $k => $v) {
        if ($k === '__line') continue;
        $normalizados[_cuImp_normalizarHeader((string)$k)] = $v;
    }
    foreach ($posibles as $p) {
        if (array_key_exists($p, $normalizados)) return $normalizados[$p];
    }
    return null;
}

function _cuImp_colIndex(string $letters): int {
    $letters = strtoupper($letters);
    $result = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $result = $result * 26 + (ord($letters[$i]) - 64);
    }
    return $result - 1;
}

function _cuImp_leerCsv(string $path): array {
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) throw new RuntimeException('No se puede abrir el CSV.');
    $first = fread($fh, 3);
    if ($first !== "\xEF\xBB\xBF") rewind($fh);
    $sample = fread($fh, 4096); rewind($fh);
    if ($first === "\xEF\xBB\xBF") fread($fh, 3);
    $delim = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
    $headersRaw = fgetcsv($fh, 0, $delim, '"', '\\');
    if (!$headersRaw) { fclose($fh); throw new RuntimeException('El CSV está vacío o no se pudo leer el encabezado.'); }
    $headers = array_map('_cuImp_normalizarHeader', $headersRaw);

    $lineNum = 1;
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $lineNum++;
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $r = array_pad($r, count($headers), '');
        $assoc = array_combine($headers, array_slice($r, 0, count($headers)));
        foreach ($assoc as $k => $v) {
            if (!mb_check_encoding((string)$v, 'UTF-8')) {
                $assoc[$k] = mb_convert_encoding((string)$v, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
            }
            $assoc[$k] = trim((string)$assoc[$k]);
        }
        $assoc['__line'] = $lineNum;
        $rows[] = $assoc;
    }
    fclose($fh);
    return ['rows' => $rows, 'headers' => $headers, 'total' => count($rows)];
}

function _cuImp_leerXlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene ZipArchive para leer XLSX. Sube el archivo como CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('No se pudo abrir el XLSX (¿corrupto?).');

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false && isset($ss->si)) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) $shared[] = (string)$si->t;
                else {
                    $txt = '';
                    if (isset($si->r)) foreach ($si->r as $r) $txt .= (string)$r->t;
                    $shared[] = $txt;
                }
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($sheetPath) === false) {
        $sheetPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string)$name)) { $sheetPath = $name; break; }
        }
    }
    if ($sheetPath === null) { $zip->close(); throw new RuntimeException('No se encontró ninguna hoja dentro del XLSX.'); }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('No se pudo leer la hoja del XLSX.');

    $sx = @simplexml_load_string($sheetXml);
    if ($sx === false || !isset($sx->sheetData)) throw new RuntimeException('El archivo XLSX está dañado o no es válido.');

    $rowsRaw = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $colIdx = _cuImp_colIndex($m[1] ?? 'A');
            $type = (string)$c['t'];
            $val = '';
            if (isset($c->v)) {
                $vRaw = (string)$c->v;
                $val = ($type === 's') ? ($shared[(int)$vRaw] ?? '') : $vRaw;
            } elseif (isset($c->is->t)) {
                $val = (string)$c->is->t;
            }
            $cells[$colIdx] = $val;
        }
        $rowsRaw[] = $cells;
    }

    if (empty($rowsRaw)) throw new RuntimeException('El archivo XLSX no tiene filas.');

    $headerRow = array_shift($rowsRaw);
    $maxCol = 0;
    foreach ($headerRow as $idx => $v) $maxCol = max($maxCol, $idx);

    $headers = [];
    for ($i = 0; $i <= $maxCol; $i++) {
        $h = trim((string)($headerRow[$i] ?? ''));
        $headers[$i] = _cuImp_normalizarHeader($h) ?: ('col' . $i);
    }

    $rows = [];
    $lineNum = 1;
    foreach ($rowsRaw as $r) {
        $lineNum++;
        $assoc = [];
        $allEmpty = true;
        for ($i = 0; $i <= $maxCol; $i++) {
            $v = trim((string)($r[$i] ?? ''));
            if ($v !== '') $allEmpty = false;
            $assoc[$headers[$i]] = $v;
        }
        if ($allEmpty) continue;
        $assoc['__line'] = $lineNum;
        $rows[] = $assoc;
    }

    return ['rows' => $rows, 'headers' => array_values($headers), 'total' => count($rows)];
}

/* ─────────────────────────────────────────────────────────────
   ACCIÓN: subir archivo y generar vista previa
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'preview') {
    csrf_require();

    $file = $_FILES['archivo'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errores[] = 'Debes seleccionar un archivo.';
    } elseif (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $errores[] = 'El archivo excede 5 MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) $errores[] = 'El archivo debe ser .xlsx o .csv';
    }

    if (empty($errores)) {
        $importDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/imports';
        if (!is_dir($importDir)) @mkdir($importDir, 0755, true);
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $token = bin2hex(random_bytes(8));
        $dest  = $importDir . '/cuartos_' . $token . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errores[] = 'No se pudo guardar el archivo subido.';
        } else {
            try {
                $data = ($ext === 'xlsx') ? _cuImp_leerXlsx($dest) : _cuImp_leerCsv($dest);

                $headersNorm = array_map('_cuImp_normalizarHeader', $data['headers'] ?? []);
                if (!in_array('codigo', $headersNorm, true)) {
                    throw new RuntimeException('Falta la columna obligatoria: codigo');
                }
                if (empty($data['rows'])) throw new RuntimeException('El archivo no tiene filas de datos.');
                if (count($data['rows']) > 3000) throw new RuntimeException('Máximo 3000 filas por importación.');

                // Lookups
                $stN = $pdo->prepare("SELECT id, codigo FROM niveles_parqueadero WHERE conjunto_id = :c");
                $stN->execute([':c' => $conjuntoId]);
                $nivelesMap = [];
                foreach ($stN as $n) {
                    $nivelesMap[mb_strtoupper(trim($n['codigo']), 'UTF-8')] = ['id' => (int)$n['id'], 'codigo' => $n['codigo']];
                }

                $stA = $pdo->prepare("SELECT id, numero_visible FROM apartamentos WHERE conjunto_id = :c");
                $stA->execute([':c' => $conjuntoId]);
                $aptosMap = [];
                foreach ($stA as $a) $aptosMap[(string)$a['numero_visible']] = (int)$a['id'];

                $stC = $pdo->prepare("SELECT codigo FROM cuartos_utiles WHERE conjunto_id = :c");
                $stC->execute([':c' => $conjuntoId]);
                $cuartosExistentes = [];
                foreach ($stC as $c) $cuartosExistentes[mb_strtoupper(trim($c['codigo']), 'UTF-8')] = true;

                $filas = [];
                $vistosEnArchivo = [];
                $totales = ['nuevos' => 0, 'duplicados' => 0, 'errores' => 0];

                foreach ($data['rows'] as $row) {
                    $linea = $row['__line'] ?? 0;
                    $rowErr = [];

                    $codigoRaw = (string)(_cuImp_buscarCampo($row, ['codigo']) ?? '');
                    $codigo = clean_string(trim($codigoRaw), 30);

                    $nivelRaw = trim((string)(_cuImp_buscarCampo($row, ['nivel']) ?? ''));
                    $aptoRaw  = trim((string)(_cuImp_buscarCampo($row, ['apto_dueno','apto','apartamento']) ?? ''));
                    if ($aptoRaw !== '' && is_numeric($aptoRaw)) $aptoRaw = (string)(int)floatval($aptoRaw);

                    $areaRaw = trim((string)(_cuImp_buscarCampo($row, ['area_m2','area','m2']) ?? ''));

                    $observ = clean_string(trim((string)(_cuImp_buscarCampo($row, ['observaciones','observacion','nota','notas']) ?? '')), 255);

                    if ($codigo === '') $rowErr[] = 'Código vacío.';

                    // Nivel: OPCIONAL en cuartos
                    $nivelId = null;
                    if ($nivelRaw !== '') {
                        $nivel = $nivelesMap[mb_strtoupper($nivelRaw, 'UTF-8')] ?? null;
                        if (!$nivel) $rowErr[] = "El nivel '{$nivelRaw}' no existe en este conjunto.";
                        else $nivelId = $nivel['id'];
                    }

                    // Apto: OPCIONAL en cuartos
                    $aptoDuenoId = null;
                    if ($aptoRaw !== '') {
                        $aptoDuenoId = $aptosMap[$aptoRaw] ?? null;
                        if (!$aptoDuenoId) $rowErr[] = "El apto '{$aptoRaw}' no existe.";
                    }

                    // Area: OPCIONAL, si viene debe ser numérica válida
                    $area = null;
                    if ($areaRaw !== '') {
                        $areaNorm = str_replace(',', '.', $areaRaw);
                        if (!is_numeric($areaNorm)) {
                            $rowErr[] = "Área '{$areaRaw}' no es numérica.";
                        } else {
                            $area = (float)$areaNorm;
                            if ($area < 0 || $area > 9999.99) {
                                $rowErr[] = 'Área fuera de rango (0 a 9999.99).';
                                $area = null;
                            }
                        }
                    }

                    // Duplicado dentro del archivo
                    $codigoKey = mb_strtoupper($codigo, 'UTF-8');
                    if ($codigo !== '' && isset($vistosEnArchivo[$codigoKey])) {
                        $rowErr[] = 'Código repetido en el archivo.';
                    }
                    if ($codigo !== '') $vistosEnArchivo[$codigoKey] = true;

                    $existeEnBD = $codigo !== '' && isset($cuartosExistentes[$codigoKey]);

                    if (!empty($rowErr))     { $estado = 'error';      $totales['errores']++; }
                    elseif ($existeEnBD)     { $estado = 'duplicado';  $totales['duplicados']++; }
                    else                     { $estado = 'nuevo';      $totales['nuevos']++; }

                    $filas[] = [
                        'linea' => $linea, 'estado' => $estado, 'errores' => $rowErr,
                        'codigo' => $codigo,
                        'nivel_raw' => $nivelRaw, 'nivel_id' => $nivelId,
                        'apto_raw' => $aptoRaw,   'apto_dueno_id' => $aptoDuenoId,
                        'area_raw' => $areaRaw,   'area_m2' => $area,
                        'observaciones' => $observ,
                    ];
                }

                $_SESSION['import_cuartos'] = [
                    'filas' => $filas, 'totales' => $totales,
                    'archivo_orig' => $file['name'], 'guardado_en' => time(),
                ];

            } catch (\Throwable $ex) {
                $errores[] = 'Error al leer el archivo: ' . $ex->getMessage();
            }
        }
        if (is_file($dest ?? '')) @unlink($dest);
    }
}

/* ACCIÓN: confirmar */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'confirmar') {
    csrf_require();
    $imp = $_SESSION['import_cuartos'] ?? null;
    if (!$imp || empty($imp['filas'])) {
        $errores[] = 'No hay datos de importación pendientes. Sube el archivo de nuevo.';
    } else {
        $filas = $imp['filas'];
        $contador = ['ok' => 0, 'duplicado' => 0, 'error' => 0];
        $logErrores = [];

        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare("INSERT INTO cuartos_utiles
                    (conjunto_id, nivel_id, codigo, apto_dueno_id, area_m2, activo, observaciones)
                VALUES (:c, :nv, :cd, :ad, :ar, 1, :ob)");

            foreach ($filas as $f) {
                if ($f['estado'] === 'error') {
                    $contador['error']++;
                    $logErrores[] = ['linea' => $f['linea'], 'codigo' => $f['codigo'], 'motivo' => implode(' | ', $f['errores'])];
                    continue;
                }
                if ($f['estado'] === 'duplicado') { $contador['duplicado']++; continue; }

                $ins->execute([
                    ':c'  => $conjuntoId, ':nv' => $f['nivel_id'],
                    ':cd' => $f['codigo'], ':ad' => $f['apto_dueno_id'],
                    ':ar' => $f['area_m2'],
                    ':ob' => $f['observaciones'] !== '' ? $f['observaciones'] : null,
                ]);
                $contador['ok']++;
            }

            $totalFilas = count($filas);
            $pdo->prepare("INSERT INTO importaciones_log
                    (conjunto_id, usuario_id, tipo, archivo_nombre, total_filas, filas_ok, filas_error, detalle_json)
                VALUES (:c, :u, 'cuartos', :f, :tt, :ok, :er, :dj)")
                ->execute([
                    ':c' => $conjuntoId, ':u' => $u['id'] ?? null,
                    ':f' => $imp['archivo_orig'] ?? null, ':tt' => $totalFilas,
                    ':ok' => $contador['ok'], ':er' => $contador['error'],
                    ':dj' => json_encode(['totales' => $contador, 'errores' => $logErrores], JSON_UNESCAPED_UNICODE),
                ]);

            $pdo->commit();
            unset($_SESSION['import_cuartos']);

            $resultado = ['total' => $totalFilas, 'totales' => $contador, 'errores' => $logErrores];
            $fase = 'resultado';

            if (function_exists('flash_set')) {
                flash_set('ok', "Importación completada: {$contador['ok']} cuarto(s) creado(s).");
            }
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            $errores[] = 'Error al guardar en BD: ' . (defined('APP_DEBUG') && APP_DEBUG ? $ex->getMessage() : 'intenta de nuevo.');
        }
    }
}

/* ACCIÓN: cancelar / empezar de nuevo */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar') {
    csrf_require();
    unset($_SESSION['import_cuartos']);
    redirect('/cuartos/importar');
}

if ($fase !== 'resultado') {
    $fase = !empty($_SESSION['import_cuartos']['filas']) ? 'preview' : 'subir';
}

$previewData = $_SESSION['import_cuartos'] ?? null;

$_pageTitle = 'Importar cuartos útiles';
include INCLUDES_PATH . '/header.php';
?>

<style>
.imp-cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;}
.imp-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;min-width:110px;}
.imp-card__label{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.imp-card__value{font-size:24px;font-weight:700;margin-top:4px;}
.imp-card--ok .imp-card__value{color:#166534;}
.imp-card--warn .imp-card__value{color:#92400e;}
.imp-card--err .imp-card__value{color:#991b1b;}
.form-upload{max-width:680px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.form-upload label{display:block;font-size:13px;color:#374151;margin-bottom:6px;font-weight:500;}
.form-upload input[type=file]{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:5px;background:#fff;}
.imp-tabla{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px;}
.imp-tabla th,.imp-tabla td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;}
.imp-tabla th{background:#f3f4f6;font-weight:600;font-size:11px;text-transform:uppercase;color:#374151;}
.imp-tabla tr.estado-error{background:#fef2f2;}
.imp-tabla tr.estado-duplicado{background:#fefce8;}
.imp-tabla tr.estado-nuevo{background:#f0fdf4;}
.imp-tabla .errores{color:#dc2626;font-size:12px;}
.pill-mini{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.pill-mini.nuevo{background:#dcfce7;color:#166534;}
.pill-mini.duplicado{background:#fef3c7;color:#92400e;}
.pill-mini.error{background:#fee2e2;color:#991b1b;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📥 Importar cuartos útiles</h1>
    <p class="page-head__sub">Sube un archivo Excel (.xlsx) o CSV para crear muchos cuartos de una sola vez.</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/cuartos') ?>">← Volver a cuartos</a>
    <a class="btn" href="<?= url('/cuartos/plantilla_cuartos') ?>">📄 Descargar plantilla CSV</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($fase === 'resultado'): ?>
    <div class="imp-cards">
        <div class="imp-card imp-card--ok"><div class="imp-card__label">Creados</div><div class="imp-card__value"><?= (int)$resultado['totales']['ok'] ?></div></div>
        <div class="imp-card imp-card--warn"><div class="imp-card__label">Duplicados (saltados)</div><div class="imp-card__value"><?= (int)$resultado['totales']['duplicado'] ?></div></div>
        <div class="imp-card imp-card--err"><div class="imp-card__label">Con error</div><div class="imp-card__value"><?= (int)$resultado['totales']['error'] ?></div></div>
    </div>

    <?php if (!empty($resultado['errores'])): ?>
        <div class="flash flash--warn"><strong>Detalle de errores:</strong>
            <ul style="margin:6px 0 0 18px">
                <?php foreach ($resultado['errores'] as $er): ?>
                    <li>Línea <?= (int)$er['linea'] ?> — <?= e($er['codigo']) ?>: <?= e($er['motivo']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div style="margin-top:14px">
        <a class="btn btn--primary" href="<?= url('/cuartos') ?>">Ver cuartos →</a>
        <a class="btn" href="<?= url('/cuartos/importar') ?>">Importar otro</a>
    </div>

<?php elseif ($fase === 'preview' && $previewData): ?>
    <?php $t = $previewData['totales']; $totalFilas = count($previewData['filas']); ?>
    <div class="imp-cards">
        <div class="imp-card"><div class="imp-card__label">Total filas</div><div class="imp-card__value"><?= $totalFilas ?></div></div>
        <div class="imp-card imp-card--ok"><div class="imp-card__label">Nuevos</div><div class="imp-card__value"><?= (int)$t['nuevos'] ?></div></div>
        <div class="imp-card imp-card--warn"><div class="imp-card__label">Ya existen</div><div class="imp-card__value"><?= (int)$t['duplicados'] ?></div></div>
        <div class="imp-card imp-card--err"><div class="imp-card__label">Con error</div><div class="imp-card__value"><?= (int)$t['errores'] ?></div></div>
    </div>

    <form method="POST" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="accion" value="confirmar">
        <button type="submit" class="btn btn--primary"
                onclick="return confirm('Se van a crear <?= (int)$t['nuevos'] ?> cuartos nuevos. ¿Continuar?');"
                <?= $t['nuevos'] === 0 ? 'disabled' : '' ?>>
            ✅ Confirmar e importar <?= (int)$t['nuevos'] ?> cuarto(s)
        </button>
    </form>
    <form method="POST" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="accion" value="cancelar">
        <button type="submit" class="btn">↺ Cancelar y subir otro archivo</button>
    </form>

    <table class="imp-tabla">
        <thead>
            <tr><th>#</th><th>Estado</th><th>Código</th><th>Nivel</th><th>Apto</th><th>Área m²</th><th>Errores / Obs</th></tr>
        </thead>
        <tbody>
            <?php foreach ($previewData['filas'] as $f):
                $pillClass = $f['estado'] === 'nuevo' ? 'nuevo' : ($f['estado'] === 'duplicado' ? 'duplicado' : 'error');
                $pillTxt   = $f['estado'] === 'nuevo' ? '✨ Nuevo' : ($f['estado'] === 'duplicado' ? '🔄 Ya existe' : '⚠️ Error');
            ?>
                <tr class="estado-<?= e($f['estado']) ?>">
                    <td><?= (int)$f['linea'] ?></td>
                    <td><span class="pill-mini <?= $pillClass ?>"><?= $pillTxt ?></span></td>
                    <td><strong><?= e($f['codigo']) ?></strong></td>
                    <td><?= e($f['nivel_raw']) ?></td>
                    <td><?= e($f['apto_raw']) ?></td>
                    <td><?= e($f['area_raw']) ?></td>
                    <td>
                        <?php if (!empty($f['errores'])): ?>
                            <span class="errores"><?= e(implode(' | ', $f['errores'])) ?></span>
                        <?php else: ?>
                            <?= e($f['observaciones']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>
    <form method="POST" enctype="multipart/form-data" class="form-upload">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="accion" value="preview">

        <label>Archivo (.xlsx o .csv, máximo 5 MB)</label>
        <input type="file" name="archivo" accept=".xlsx,.csv,text/csv" required>

        <div style="margin-top:14px">
            <button type="submit" class="btn btn--primary">Analizar archivo →</button>
            <a class="btn" href="<?= url('/cuartos/plantilla_cuartos') ?>">📄 Descargar plantilla</a>
        </div>

        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:13px;margin-top:14px">
            <strong>Formato esperado:</strong>
            <ul style="margin:6px 0 6px 18px">
                <li>Columnas: <code>Codigo</code>, <code>Nivel</code>, <code>Apto dueño</code>, <code>Area m2</code>, <code>Observaciones</code>.</li>
                <li>Solo <code>Codigo</code> es obligatorio. El resto es opcional.</li>
                <li>Si el código ya existe, la fila se marca como DUPLICADA y se salta.</li>
                <li>Los cuartos importados quedan activos.</li>
            </ul>
        </div>
    </form>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
