<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/preview.php
// v3j2: agrega columna VINCULO opcional + ruteo a visitantes_vehiculos.
//       Mantiene 100% la lógica previa de residentes y vehículos sin vínculo.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDES_PATH . '/csv_helpers.php';
require_once INCLUDES_PATH . '/excel_helpers.php';
require_once INCLUDES_PATH . '/upload_helpers.php';

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

if (session_status() === PHP_SESSION_NONE) session_start();
$imp = $_SESSION['import'] ?? null;
if (!$imp) { flash_set('warn', 'No hay archivo en proceso.'); redirect('/importaciones'); }

$path = UPLOADS_PATH . '/imports/' . $imp['token'] . '.' . $imp['ext'];
if (!is_file($path)) {
    unset($_SESSION['import']);
    flash_set('error', 'El archivo subido ya no está disponible.');
    redirect('/importaciones');
}

$tipo = $imp['tipo'];
$columnas_requeridas = $tipo === 'vehiculos' ? ['apto','placa'] : ['apto','tipo','nombre','celular'];

try {
    if ($imp['ext'] === 'xlsx') {
        $data = xlsx_leer($path, $columnas_requeridas);
    } else {
        $data = _leer_csv_dinamico($path, $columnas_requeridas);
    }
} catch (RuntimeException $e) {
    flash_set('error', 'Error al leer el archivo: ' . $e->getMessage());
    redirect('/importaciones/nueva?tipo=' . $tipo);
}

function _leer_csv_dinamico(string $path, array $required): array {
    $rows = []; $headers = [];
    if (($fh = fopen($path, 'r')) === false) throw new RuntimeException('No se puede abrir el CSV.');
    $first = fread($fh, 3);
    if ($first !== "\xEF\xBB\xBF") rewind($fh);
    $sample = fread($fh, 4096); rewind($fh);
    if ($first === "\xEF\xBB\xBF") fread($fh, 3);
    $delim = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
    $headersRaw = fgetcsv($fh, 0, $delim, '"', '\\');
    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $headersRaw ?: []);
    // Normalizar tildes en headers (vínculo → vinculo)
    $headers = array_map(function($h) {
        return strtr($h, ['í'=>'i','é'=>'e','á'=>'a','ó'=>'o','ú'=>'u']);
    }, $headers);
    foreach ($required as $req) {
        if (!in_array($req, $headers, true)) throw new RuntimeException("Falta la columna: {$req}");
    }
    $lineNum = 1;
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $lineNum++;
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $r = array_pad($r, count($headers), '');
        $assoc = array_combine($headers, array_slice($r, 0, count($headers)));
        foreach ($assoc as $k => $v) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                $assoc[$k] = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
            }
            $assoc[$k] = trim($assoc[$k]);
        }
        $assoc['__line'] = $lineNum;
        $rows[] = $assoc;
    }
    fclose($fh);
    return ['rows' => $rows, 'headers' => $headers, 'total' => count($rows)];
}

// ─────────── Detectar si CSV trae columna VINCULO (solo vehículos) ───────────
$modoVinculo = ($tipo === 'vehiculos' && in_array('vinculo', $data['headers'], true));

// Helper para normalizar valores de vínculo
function _norm_vinculo($v) {
    $v = strtolower(trim((string)$v));
    $v = strtr($v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
    $map = [
        'propietario'=>'propietario','prop'=>'propietario','dueno'=>'propietario',
        'inquilino'=>'inquilino','inqu'=>'inquilino','arrendatario'=>'inquilino',
        'familiar'=>'familiar',
        'visitante'=>'visitante','visita'=>'visitante',
        'residente'=>'residente','res'=>'residente',
        'otro'=>'otro',
        ''=>'',
    ];
    return $map[$v] ?? null;
}

// Pre-cargar aptos
$aptosMap = [];
$st = $pdo->prepare("SELECT id, numero_visible FROM apartamentos WHERE conjunto_id = :c");
$st->execute([':c' => $conjuntoId]);
foreach ($st as $r) { $aptosMap[(string)$r['numero_visible']] = (int)$r['id']; }

$filas = [];
$totales = ['nuevos' => 0, 'duplicados' => 0, 'errores' => 0, 'a_visitantes' => 0];

if ($tipo === 'residentes') {
    // ════════════ RESIDENTES (sin cambios respecto a v3b.1) ════════════
    $residentesPorApto = [];
    $stR = $pdo->prepare("
        SELECT r.id, r.apartamento_id, r.nombre, r.celular
          FROM residentes r
          JOIN apartamentos a ON a.id = r.apartamento_id
         WHERE a.conjunto_id = :c AND r.archivado_en IS NULL
    ");
    $stR->execute([':c' => $conjuntoId]);
    foreach ($stR as $r) {
        $aid = (int)$r['apartamento_id'];
        $residentesPorApto[$aid][] = [
            'id' => (int)$r['id'],
            'nombre_n'  => normalizar_nombre($r['nombre']),
            'celular_n' => normalizar_celular($r['celular'] ?? ''),
            'nombre' => $r['nombre'], 'celular' => $r['celular'],
        ];
    }
    foreach ($data['rows'] as $row) {
        $linea = $row['__line'] ?? 0;
        $apto = trim((string)($row['apto'] ?? ''));
        $nombre = trim((string)($row['nombre'] ?? ''));
        $celRaw = trim((string)($row['celular'] ?? ''));
        $tipoR = trim((string)($row['tipo'] ?? ''));
        if ($apto !== '' && is_numeric($apto)) $apto = (string)(int)floatval($apto);
        if ($celRaw !== '' && is_numeric($celRaw)) $celRaw = (string)(int)floatval($celRaw);
        $rowErr = [];
        if ($apto === '') $rowErr[] = 'Apto vacío.';
        if ($nombre === '') $rowErr[] = 'Nombre vacío.';
        $tipoN  = normalizar_tipo_residente($tipoR);
        $aptoId = $aptosMap[$apto] ?? null;
        if ($apto !== '' && $aptoId === null) $rowErr[] = "Apto '{$apto}' no existe.";
        $celN = normalizar_celular($celRaw);
        $nomN = normalizar_nombre($nombre);
        $duplicadoDe = null;
        if ($aptoId !== null && !empty($residentesPorApto[$aptoId])) {
            foreach ($residentesPorApto[$aptoId] as $existente) {
                if ($celN !== '' && $existente['celular_n'] === $celN) { $duplicadoDe = $existente; break; }
                if ($nomN !== '' && $existente['nombre_n']  === $nomN) { $duplicadoDe = $existente; break; }
            }
        }
        if (!empty($rowErr))     { $estado = 'error'; $totales['errores']++; }
        elseif ($duplicadoDe)    { $estado = 'duplicado'; $totales['duplicados']++; }
        else                      { $estado = 'nuevo'; $totales['nuevos']++; }
        $filas[] = ['linea'=>$linea,'estado'=>$estado,'errores'=>$rowErr,
            'apto'=>$apto,'apto_id'=>$aptoId,'nombre'=>$nombre,'celular'=>$celRaw,
            'tipo_raw'=>$tipoR,'tipo'=>$tipoN,'duplicado_de'=>$duplicadoDe];
    }

} else {
    // ════════════ VEHÍCULOS (preserva flow viejo, agrega vínculo si presente) ════════════
    // Placas existentes en vehiculos
    $placasActivas = [];
    $stV = $pdo->prepare("
        SELECT v.id, v.placa, v.apartamento_id, v.archivado_en, a.numero_visible AS apto_numero
          FROM vehiculos v JOIN apartamentos a ON a.id = v.apartamento_id
         WHERE v.conjunto_id = :c
    ");
    // v7.6 BUGFIX: antes filtraba por 'v.archivado_en IS NULL', o sea que solo
    // veia los vehiculos ACTIVOS. Pero el indice unico de la BD es
    //     uk_vehiculos_conjunto_placa (conjunto_id, placa)
    // y ese indice NO distingue archivados.
    //
    // Resultado: si una placa estaba ARCHIVADA, el preview la marcaba como
    // 'nuevo', el INSERT chocaba con el indice, y la transaccion entera hacia
    // ROLLBACK. Por eso no guardaba NADA, ni siquiera los que si eran nuevos.
    //     SQLSTATE[23000]: Duplicate entry '1-TFQ470' for key 'uk_vehiculos_conjunto_placa'
    $stV->execute([':c' => $conjuntoId]);
    foreach ($stV as $r) {
        $placasActivas[$r['placa']] = [
            'id'        => (int)$r['id'],
            'apto'      => $r['apto_numero'],
            'archivado' => !empty($r['archivado_en']),
        ];
    }

    // Placas existentes en visitantes_vehiculos (solo si modo vínculo activo)
    $placasVisitantes = [];
    if ($modoVinculo) {
        $stVV = $pdo->prepare("
            SELECT vv.id, vv.placa, vv.apartamento_id, a.numero_visible AS apto_numero
              FROM visitantes_vehiculos vv JOIN apartamentos a ON a.id = vv.apartamento_id
             WHERE vv.conjunto_id = :c AND vv.archivado_en IS NULL
        ");
        $stVV->execute([':c' => $conjuntoId]);
        foreach ($stVV as $r) {
            $placasVisitantes[$r['placa']] = ['id'=>(int)$r['id'],'apto'=>$r['apto_numero']];
        }
    }

    // Residentes activos por apto (con tipo para resolución vínculo)
    $residentesPorApto = [];
    $stR = $pdo->prepare("
        SELECT r.id, r.apartamento_id, r.nombre, r.tipo
          FROM residentes r JOIN apartamentos a ON a.id = r.apartamento_id
         WHERE a.conjunto_id = :c AND r.archivado_en IS NULL
    ");
    $stR->execute([':c' => $conjuntoId]);
    foreach ($stR as $r) {
        $aid = (int)$r['apartamento_id'];
        $residentesPorApto[$aid][] = [
            'id' => (int)$r['id'],
            'nombre' => $r['nombre'],
            'tipo' => $r['tipo'],
            'nombre_n' => normalizar_nombre($r['nombre']),
        ];
    }

    foreach ($data['rows'] as $row) {
        $linea = $row['__line'] ?? 0;
        $apto  = trim((string)($row['apto'] ?? ''));
        $placa = normalizar_placa(trim((string)($row['placa'] ?? '')));
        $usu   = trim((string)($row['usuario'] ?? ''));
        $obs   = trim((string)($row['observacion'] ?? ''));
        $vincRaw = $modoVinculo ? trim((string)($row['vinculo'] ?? '')) : '';
        if ($apto !== '' && is_numeric($apto)) $apto = (string)(int)floatval($apto);

        $rowErr = [];
        if ($apto === '') $rowErr[] = 'Apto vacío.';
        if ($placa === '' || strlen($placa)<4) $rowErr[] = 'Placa inválida.';
        $aptoId = $aptosMap[$apto] ?? null;
        if ($apto !== '' && $aptoId === null) $rowErr[] = "Apto '{$apto}' no existe.";

        $tipoVeh = detectar_tipo_placa($placa);

        // Defaults
        $destino = 'vehiculos';
        $vinculoNorm = '';
        $residente_match = null;
        $duplicada = null;

        if ($modoVinculo && empty($rowErr)) {
            $vinculoNorm = _norm_vinculo($vincRaw);
            if ($vinculoNorm === null) {
                $rowErr[] = "Vínculo inválido: '$vincRaw' (válidos: residente, propietario, inquilino, familiar, otro, visitante).";
            } elseif ($vinculoNorm === 'visitante') {
                $destino = 'visitantes_vehiculos';
                // Verificar conflictos cruzados
                if (isset($placasActivas[$placa])) {
                    $rowErr[] = "Placa ya existe como residente (apto {$placasActivas[$placa]['apto']}).";
                } else {
                    $duplicada = $placasVisitantes[$placa] ?? null;
                }
            } else {
                // Vínculo de residente → resolver
                $residentes = $residentesPorApto[$aptoId] ?? [];
                if (empty($residentes)) {
                    $rowErr[] = "Apto '{$apto}' sin residentes activos.";
                } elseif ($vinculoNorm === 'residente' || $vinculoNorm === '') {
                    // Cascada: nombre → inquilino → propietario → cualquiera
                    if ($usu !== '') {
                        $usuN = normalizar_nombre($usu);
                        foreach ($residentes as $c) {
                            if ($c['nombre_n'] === $usuN) { $residente_match = $c; break; }
                        }
                    }
                    if (!$residente_match) {
                        foreach ($residentes as $c) {
                            if ($c['tipo'] === 'inquilino') { $residente_match = $c; break; }
                        }
                    }
                    if (!$residente_match) {
                        foreach ($residentes as $c) {
                            if ($c['tipo'] === 'propietario') { $residente_match = $c; break; }
                        }
                    }
                    if (!$residente_match) $residente_match = $residentes[0];
                } else {
                    // Tipo explícito: propietario / inquilino / familiar / otro
                    if ($usu !== '') {
                        $usuN = normalizar_nombre($usu);
                        foreach ($residentes as $c) {
                            if ($c['tipo'] === $vinculoNorm && $c['nombre_n'] === $usuN) {
                                $residente_match = $c; break;
                            }
                        }
                    }
                    if (!$residente_match) {
                        foreach ($residentes as $c) {
                            if ($c['tipo'] === $vinculoNorm) { $residente_match = $c; break; }
                        }
                    }
                    if (!$residente_match) {
                        $rowErr[] = "No hay residente tipo '{$vinculoNorm}' en apto '{$apto}'.";
                    }
                }
                $duplicada = $placasActivas[$placa] ?? null;
            }
        } else {
            // Modo viejo: matchear "usuario" con nombre del residente (sin filtro de tipo)
            if ($usu !== '' && $aptoId !== null && !empty($residentesPorApto[$aptoId])) {
                $busc = normalizar_nombre($usu);
                foreach ($residentesPorApto[$aptoId] as $c) {
                    if ($c['nombre_n'] === $busc) { $residente_match = $c; break; }
                }
                if (!$residente_match) {
                    foreach ($residentesPorApto[$aptoId] as $c) {
                        if (str_contains($c['nombre_n'], $busc) || str_contains($busc, $c['nombre_n'])) {
                            $residente_match = $c; break;
                        }
                    }
                }
            }
            $duplicada = $placasActivas[$placa] ?? null;
        }

        if (!empty($rowErr))     { $estado = 'error';     $totales['errores']++; }
        elseif ($duplicada)      { $estado = 'duplicado'; $totales['duplicados']++; }
        else {
            $estado = 'nuevo';   $totales['nuevos']++;
            if ($destino === 'visitantes_vehiculos') $totales['a_visitantes']++;
        }

        $filas[] = [
            'linea'=>$linea,'estado'=>$estado,'errores'=>$rowErr,
            'apto'=>$apto,'apto_id'=>$aptoId,'placa'=>$placa,'tipo'=>$tipoVeh,
            'usuario'=>$usu,'observacion'=>$obs,'duplicada'=>$duplicada,
            'usuario_match'=>$residente_match,
            'destino'=>$destino,'vinculo'=>$vinculoNorm,
        ];
    }
}

$_SESSION['import']['analisis'] = ['filas' => $filas, 'totales' => $totales, 'modo_vinculo' => $modoVinculo];
$_pageTitle = 'Vista previa';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>

    <h1 class="page-head__title">Vista previa</h1>
    <p class="page-head__sub">
        Paso 2 de 3: <?= count($filas) ?> fila<?= count($filas) === 1 ? '' : 's' ?> · tipo: <?= e($tipo) ?>
        <?php if ($modoVinculo): ?>
            · <span style="color:#1e6cff">📋 Modo Vínculo activo</span>
            (👋 <?= $totales['a_visitantes'] ?> visitante<?= $totales['a_visitantes']===1?'':'s' ?>)
        <?php endif; ?>
    </p>
</div>

<div class="cards">
    <div class="card card--accent">
        <div class="card__label">Nuevos</div>
        <div class="card__value"><?= $totales['nuevos'] ?></div>
    </div>
    <div class="card <?= $totales['duplicados']>0?'card--warn':'' ?>">
        <div class="card__label">Duplicados</div>
        <div class="card__value"><?= $totales['duplicados'] ?></div>
    </div>
    <div class="card <?= $totales['errores']>0?'card--warn':'' ?>">
        <div class="card__label">Con errores</div>
        <div class="card__value"><?= $totales['errores'] ?></div>
    </div>
</div>

<?php if ($totales['errores'] > 0): ?>
    <div class="flash flash--warn">
        ⚠️ Hay <strong><?= $totales['errores'] ?></strong> con error. Filtra "🔴 Errores" para revisar.
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/importaciones/confirmar') ?>" class="form-grid" id="formConfirmar">
    <?= csrf_field() ?>
    <?php
    // v7.8: las decisiones de duplicados NO se mandan como cientos de campos
    // sueltos (dup[0], dup[1]...). Eso reventaba max_input_vars y arrastraba
    // el _csrf -> "CSRF token invalido". Ahora un JS las empaqueta aca, en UN
    // solo campo JSON, justo antes de enviar. El POST pasa de 1000+ variables
    // a un punado. El limite de PHP deja de importar.
    ?>
    <input type="hidden" name="dup_json" id="dupJson" value="">

    <div class="preview-toolbar">
        <div class="preview-filters">
            <strong>Filtrar:</strong>
            <button type="button" class="btn btn--sm preview-filter is-active" data-filter="all">Todos (<?= count($filas) ?>)</button>
            <?php if ($totales['nuevos'] > 0): ?>
                <button type="button" class="btn btn--sm preview-filter" data-filter="nuevo">🟢 Nuevos (<?= $totales['nuevos'] ?>)</button>
            <?php endif; ?>
            <?php if ($totales['duplicados'] > 0): ?>
                <button type="button" class="btn btn--sm preview-filter" data-filter="duplicado">🟡 Duplicados (<?= $totales['duplicados'] ?>)</button>
            <?php endif; ?>
            <?php if ($totales['errores'] > 0): ?>
                <button type="button" class="btn btn--sm preview-filter" data-filter="error">🔴 Errores (<?= $totales['errores'] ?>)</button>
            <?php endif; ?>
        </div>
        <div class="preview-search">
            <input type="text" id="previewSearch" placeholder="🔎 Buscar..." autocomplete="off">
        </div>
    </div>

    <div class="table-wrap">
    <table class="data-table data-table--compact" id="previewTable">
        <thead>
            <tr>
                <th>Fila</th>
                <th>Estado</th>
                <th>Apto</th>
                <?php if ($tipo === 'residentes'): ?>
                    <th>Nombre</th><th>Tipo</th><th>Celular</th>
                <?php else: ?>
                    <th>Placa</th><th>Tipo</th>
                    <?php if ($modoVinculo): ?><th>Destino</th><?php endif; ?>
                    <th>Usuario</th><th>Observación</th>
                <?php endif; ?>
                <th>Detalles</th>
            </tr>
        </thead>
        <tbody id="previewBody">
        <?php foreach ($filas as $idx => $f):
            if ($tipo === 'residentes') {
                $searchText = strtolower(($f['apto']??'').' '.($f['nombre']??'').' '.($f['celular']??''));
            } else {
                $searchText = strtolower(($f['apto']??'').' '.($f['placa']??'').' '.($f['usuario']??'').' '.($f['observacion']??''));
            }
            $rowBg = ($tipo === 'vehiculos' && ($f['destino'] ?? '') === 'visitantes_vehiculos') ? 'background:#fdf2f8' : '';
        ?>
            <tr class="row-state row-state--<?= e($f['estado']) ?>"
                data-estado="<?= e($f['estado']) ?>" data-search="<?= e($searchText) ?>"
                style="<?= $rowBg ?>">
                <td><?= (int)$f['linea'] ?></td>
                <td>
                    <?php if ($f['estado']==='nuevo'): ?><span class="pill pill--ok">Nuevo</span>
                    <?php elseif ($f['estado']==='duplicado'): ?><span class="pill pill--warn">Duplicado</span>
                    <?php else: ?><span class="pill pill--danger">Error</span><?php endif; ?>
                </td>
                <td><strong><?= e($f['apto']) ?></strong></td>
                <?php if ($tipo === 'residentes'): ?>
                    <td><?= e($f['nombre']) ?></td>
                    <td><?= e($f['tipo']) ?></td>
                    <td><?= e($f['celular']) ?></td>
                <?php else: ?>
                    <td><strong><?= e($f['placa']) ?></strong></td>
                    <td><?= $f['tipo'] === 'moto' ? '🏍️ Moto' : ($f['tipo'] === 'carro' ? '🚗 Carro' : '—') ?></td>
                    <?php if ($modoVinculo): ?>
                        <td>
                            <?php if (($f['destino'] ?? '') === 'visitantes_vehiculos'): ?>
                                <span style="background:#fce7f3;color:#9d174d;padding:2px 8px;border-radius:999px;font-size:12px">👋 Visitante</span>
                            <?php elseif (!empty($f['vinculo'])): ?>
                                <span style="background:#dbeafe;color:#1e3a8a;padding:2px 8px;border-radius:999px;font-size:12px">
                                    🏠 <?= e(ucfirst($f['vinculo'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="t-muted">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td>
                        <?php if ($f['usuario'] === ''): ?>
                            <span class="t-muted">—</span>
                        <?php elseif (!empty($f['usuario_match'])): ?>
                            <span class="pill pill--ok" style="font-size:11px">✓ vinculará</span><br>
                            <small><?= e($f['usuario_match']['nombre']) ?></small><br>
                            <small class="t-muted">del Excel: "<?= e($f['usuario']) ?>"</small>
                        <?php elseif (($f['destino'] ?? '') === 'visitantes_vehiculos'): ?>
                            <small><?= e($f['usuario']) ?></small><br>
                            <small class="t-muted">nombre de visitante</small>
                        <?php else: ?>
                            <span class="pill pill--warn" style="font-size:11px">sin match</span><br>
                            <small><?= e($f['usuario']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= $f['observacion'] !== '' ? e($f['observacion']) : '<span class="t-muted">—</span>' ?></td>
                <?php endif; ?>
                <td>
                    <?php if ($f['estado'] === 'error'): ?>
                        <?php foreach ($f['errores'] as $err): ?>
                            <div class="t-error">✗ <?= e($err) ?></div>
                        <?php endforeach; ?>
                    <?php elseif ($f['estado'] === 'duplicado'): ?>
                        <?php if ($tipo === 'residentes'): ?>
                            <span class="t-muted">
                                Ya existe: <?= e($f['duplicado_de']['nombre']) ?>
                                <?php if (!empty($f['duplicado_de']['celular'])): ?>· <?= e($f['duplicado_de']['celular']) ?><?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="t-muted">
                                Placa activa en apto <?= e($f['duplicada']['apto']) ?>
                                <?= ($f['destino'] ?? '') === 'visitantes_vehiculos' ? '(como visitante)' : '' ?>
                            </span>
                            <label class="inline-radio"><input type="radio" name="dup[<?= $idx ?>]" value="skip" checked>
                                <span>Saltar</span></label>
                            <label class="inline-radio"><input type="radio" name="dup[<?= $idx ?>]" value="update">
                                <span>Actualizar campos llenos</span></label>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="t-muted">Se creará.</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="preview-footer">
        <div id="previewCounter" class="t-muted"></div>
        <nav class="pager" id="previewPager"></nav>
    </div>

    <div class="form-actions">
        <a class="btn" href="<?= url('/importaciones/nueva?tipo=' . $tipo) ?>">← Cancelar</a>
        <?php $puede = ($totales['nuevos'] + ($tipo === 'vehiculos' ? $totales['duplicados'] : 0)) > 0; ?>
        <button type="submit" class="btn btn--primary" <?= $puede ? '' : 'disabled' ?>>
            Confirmar importación →
        </button>
    </div>
</form>

<style>
.preview-toolbar{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center;
    background:#fff;border:1px solid var(--color-border);border-radius:var(--radius);padding:12px;margin-bottom:12px;}
.preview-filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.preview-filters strong{font-size:13px;color:var(--color-muted);margin-right:4px;}
.preview-filter.is-active{background:var(--color-primary);border-color:var(--color-primary);color:#fff;}
.preview-search{flex:1;min-width:200px;max-width:320px;}
.preview-search input{width:100%;padding:8px 12px;border:1px solid var(--color-border);
    border-radius:var(--radius-sm);font-size:14px;font-family:inherit;}
.preview-footer{display:flex;flex-wrap:wrap;justify-content:space-between;
    align-items:center;gap:12px;margin:12px 0 18px;}
</style>

<script>
(function () {
    var PER_PAGE = 50, currentPage = 1, currentFilter = 'all', searchTerm = '';
    var tbody = document.getElementById('previewBody');
    var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var counter = document.getElementById('previewCounter');
    var pager = document.getElementById('previewPager');
    var filters = document.querySelectorAll('.preview-filter');
    var search = document.getElementById('previewSearch');

    function getVisible() {
        return allRows.filter(function (tr) {
            if (currentFilter !== 'all' && tr.dataset.estado !== currentFilter) return false;
            if (searchTerm && tr.dataset.search.indexOf(searchTerm) === -1) return false;
            return true;
        });
    }
    function render() {
        var visible = getVisible();
        var total = visible.length;
        var totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
        if (currentPage > totalPages) currentPage = totalPages;
        allRows.forEach(function (tr) { tr.style.display = 'none'; });
        var start = (currentPage - 1) * PER_PAGE;
        visible.slice(start, start + PER_PAGE).forEach(function (tr) { tr.style.display = ''; });
        counter.textContent = total === 0 ? 'Sin resultados.'
            : 'Mostrando ' + (start + 1) + '–' + Math.min(start + PER_PAGE, total) + ' de ' + total
              + (total !== allRows.length ? ' (filtrados)' : '');
        pager.innerHTML = '';
        if (totalPages > 1) {
            var add = function (label, page, isActive, isDisabled) {
                var b = document.createElement(isActive ? 'span' : 'button');
                b.className = 'pager__item' + (isActive ? ' is-active' : '');
                b.textContent = label;
                if (!isActive && !isDisabled) {
                    b.type = 'button';
                    b.addEventListener('click', function () {
                        currentPage = page; render();
                        document.getElementById('previewTable').scrollIntoView({behavior:'smooth', block:'start'});
                    });
                }
                if (isDisabled) b.disabled = true;
                pager.appendChild(b);
            };
            add('«', 1, false, currentPage === 1);
            add('‹', currentPage - 1, false, currentPage === 1);
            var s = Math.max(1, currentPage - 3), eP = Math.min(totalPages, s + 6);
            if (eP - s < 6) s = Math.max(1, eP - 6);
            for (var i = s; i <= eP; i++) add(String(i), i, i === currentPage, false);
            add('›', currentPage + 1, false, currentPage === totalPages);
            add('»', totalPages, false, currentPage === totalPages);
        }
    }
    filters.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filters.forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            currentFilter = btn.dataset.filter; currentPage = 1; render();
        });
    });
    var t = null;
    search.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { searchTerm = search.value.trim().toLowerCase(); currentPage = 1; render(); }, 200);
    });
    render();
})();

/* v7.8 — Empaquetar decisiones de duplicados en UN solo campo JSON.
   Corre al enviar el form. Recolecta cada radio dup[i] marcado, arma
   un objeto {i: 'skip'|'update'} y lo mete en #dupJson. Luego DESACTIVA
   los radios para que NO viajen sueltos en el POST (asi no cuentan para
   max_input_vars). El token y el resto siguen viajando normal. */
(function () {
  var form = document.getElementById('formConfirmar');
  if (!form) return;
  form.addEventListener('submit', function () {
    var radios = form.querySelectorAll('input[type=radio][name^="dup["]:checked');
    var dec = {};
    for (var i = 0; i < radios.length; i++) {
      var m = radios[i].name.match(/dup\[(\d+)\]/);
      if (m) dec[m[1]] = radios[i].value;
    }
    var campo = document.getElementById('dupJson');
    if (campo) campo.value = JSON.stringify(dec);
    // Desactivar TODOS los dup[] (marcados o no) para que no viajen sueltos.
    var todos = form.querySelectorAll('input[name^="dup["]');
    for (var j = 0; j < todos.length; j++) todos[j].disabled = true;
  });
})();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
