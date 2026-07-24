<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/importar.php
// v3p: importador de celdas desde CSV.
//      Flujo: upload → preview (con decisión actualizar/saltar por fila) → confirmar.
//      Detecta celdas existentes y permite ACTUALIZAR o SALTAR (pregunta por cada una).

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// ────────────────────────────────────────────────────────────────
//  HELPERS de parsing
// ────────────────────────────────────────────────────────────────
function normalizarTipo($txt) {
    $txt = trim((string)$txt);
    if ($txt === '') return '';
    // Quitar acentos y bajar
    $sin = strtolower(strtr($txt,
        ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
         'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n']));
    // Compactar espacios y puntos
    $sin = preg_replace('/[.\s_]+/', ' ', $sin);
    $sin = trim($sin);

    $map = [
        'comun'              => 'comun',
        'común'              => 'comun',
        'privada'            => 'privada',
        'privado'            => 'privada',
        'moto comun'         => 'moto_comun',
        'motocomun'          => 'moto_comun',
        'moto'               => 'moto_comun',
        'libre'              => 'libre',
        'mov reducida'       => 'movilidad_reducida',
        'movilidad reducida' => 'movilidad_reducida',
        'movilidad'          => 'movilidad_reducida',
        'discapacitado'      => 'movilidad_reducida',
        'discapacitados'     => 'movilidad_reducida',
    ];
    return $map[$sin] ?? '';
}

function siNo($txt) {
    $t = strtolower(trim((string)$txt));
    if ($t === '') return 0;
    if (in_array($t, ['x','si','sí','s','y','yes','true','1','✓','✔'], true)) return 1;
    return 0;
}

function detectarDelimitador($linea) {
    $cnt = [';' => substr_count($linea, ';'), ',' => substr_count($linea, ',')];
    return $cnt[';'] > $cnt[','] ? ';' : ',';
}

function decodificarUtf8($s) {
    // Si ya es UTF-8 válido, devolver tal cual
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    // Sino, intentar desde Latin1/Windows-1252
    return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1,Windows-1252');
}

// ────────────────────────────────────────────────────────────────
//  PASO 1 - Subir archivo CSV y parsear
// ────────────────────────────────────────────────────────────────
$errorGlobal = '';
$filas = [];   // array de filas parseadas con su estado
$resumen = ['crear' => 0, 'actualizar' => 0, 'error' => 0];

$paso = $_POST['paso'] ?? 'upload';

if ($paso === 'parsear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $errorGlobal = 'No se recibió el archivo o hubo un error al subirlo.';
    } else {
        $tmp = $_FILES['archivo']['tmp_name'];
        $contenido = file_get_contents($tmp);
        if ($contenido === false || strlen($contenido) === 0) {
            $errorGlobal = 'El archivo está vacío.';
        } else {
            // Quitar BOM UTF-8 si lo trae
            if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") $contenido = substr($contenido, 3);
            $contenido = decodificarUtf8($contenido);
            // Normalizar fin de líneas
            $contenido = str_replace(["\r\n","\r"], "\n", $contenido);
            $lineas = explode("\n", $contenido);
            $lineas = array_filter($lineas, fn($l) => trim($l) !== '');
            $lineas = array_values($lineas);

            if (count($lineas) < 2) {
                $errorGlobal = 'El archivo debe tener al menos el encabezado y 1 fila de datos.';
            } else {
                // Detectar delimitador con la primera línea
                $delim = detectarDelimitador($lineas[0]);

                // Parsear como CSV
                $rows = [];
                foreach ($lineas as $ln) {
                    $h = fopen('php://memory', 'r+');
                    fwrite($h, $ln);
                    rewind($h);
                    $f = fgetcsv($h, 0, $delim);
                    fclose($h);
                    if ($f && count($f) > 0) $rows[] = $f;
                }

                if (count($rows) < 2) {
                    $errorGlobal = 'No se pudieron parsear las filas. Verifica el formato.';
                } else {
                    // Header
                    $header = array_map(fn($x) => strtolower(trim($x)), $rows[0]);
                    array_shift($rows);

                    // Mapear columnas (busca por nombre, tolerante)
                    function colIdx($header, $candidatos) {
                        foreach ($candidatos as $c) {
                            $c = strtolower($c);
                            foreach ($header as $i => $h) {
                                if (strtolower(strtr($h, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'])) === $c) return $i;
                            }
                        }
                        return -1;
                    }
                    $iCodigo  = colIdx($header, ['codigo','código']);
                    $iNivel   = colIdx($header, ['nivel']);
                    $iTipo    = colIdx($header, ['tipo']);
                    $iCarro   = colIdx($header, ['permite carro','carro','permite_carro']);
                    $iMoto    = colIdx($header, ['permite moto','moto','permite_moto']);
                    $iApto    = colIdx($header, ['apto dueno','apto dueño','apto_dueno','apto']);
                    $iObs     = colIdx($header, ['observaciones','observacion','obs']);

                    if ($iCodigo < 0 || $iNivel < 0 || $iTipo < 0) {
                        $errorGlobal = 'Faltan columnas obligatorias. El encabezado debe tener al menos: Codigo, Nivel, Tipo.';
                    } else {
                        // Cargar lookups: niveles y aptos
                        $niveles = [];
                        $st = $pdo->prepare("SELECT id, codigo FROM niveles_parqueadero
                                              WHERE conjunto_id = :c AND activo = 1");
                        $st->execute([':c' => $conjuntoId]);
                        foreach ($st->fetchAll() as $r) $niveles[strtolower($r['codigo'])] = (int)$r['id'];

                        // Procesar cada fila
                        foreach ($rows as $idx => $r) {
                            $codigo  = isset($r[$iCodigo]) ? trim($r[$iCodigo]) : '';
                            $nivelTx = isset($r[$iNivel])  ? trim($r[$iNivel])  : '';
                            $tipoTx  = isset($r[$iTipo])   ? trim($r[$iTipo])   : '';
                            $carro   = ($iCarro >= 0) ? siNo($r[$iCarro] ?? '') : 1;
                            $moto    = ($iMoto  >= 0) ? siNo($r[$iMoto]  ?? '') : 0;
                            $aptoTx  = ($iApto  >= 0) ? trim($r[$iApto]  ?? '') : '';
                            $obs     = ($iObs   >= 0) ? trim($r[$iObs]   ?? '') : '';

                            $fila = [
                                'linea'  => $idx + 2,  // +1 por header, +1 por base 0
                                'codigo' => $codigo,
                                'nivel'  => $nivelTx,
                                'tipo_orig' => $tipoTx,
                                'permite_carro' => $carro,
                                'permite_moto'  => $moto,
                                'apto'    => $aptoTx,
                                'obs'     => mb_substr($obs, 0, 255),
                                'errores' => [],
                                'accion'  => 'crear',   // crear / actualizar / error
                                'celda_existente_id' => null,
                                'nivel_id' => null,
                                'apto_id'  => null,
                                'tipo'     => '',
                            ];

                            // Validaciones
                            if ($codigo === '')            $fila['errores'][] = 'Código vacío';
                            if (mb_strlen($codigo) > 20)   $fila['errores'][] = 'Código > 20 caracteres';
                            if ($nivelTx === '')           $fila['errores'][] = 'Nivel vacío';
                            else {
                                $nid = $niveles[strtolower($nivelTx)] ?? null;
                                if (!$nid) $fila['errores'][] = "Nivel '$nivelTx' no existe en la BD";
                                else $fila['nivel_id'] = $nid;
                            }

                            $tipoNorm = normalizarTipo($tipoTx);
                            if ($tipoNorm === '') $fila['errores'][] = "Tipo '$tipoTx' no reconocido";
                            else $fila['tipo'] = $tipoNorm;

                            if ($carro === 0 && $moto === 0) {
                                $fila['errores'][] = 'Debe permitir al menos carro o moto';
                            }

                            // Apto dueño
                            if ($aptoTx !== '') {
                                $sa = $pdo->prepare("SELECT id FROM apartamentos
                                                      WHERE conjunto_id = :c AND numero_visible = :n LIMIT 1");
                                $sa->execute([':c' => $conjuntoId, ':n' => $aptoTx]);
                                $aid = (int)$sa->fetchColumn();
                                if (!$aid) $fila['errores'][] = "Apto '$aptoTx' no existe";
                                else $fila['apto_id'] = $aid;
                            }

                            // Privada requiere apto dueño
                            if ($tipoNorm === 'privada' && !$fila['apto_id']) {
                                $fila['errores'][] = 'Privada requiere apto dueño';
                            }

                            // ¿Ya existe la celda?
                            if ($codigo !== '') {
                                $sc = $pdo->prepare("SELECT id FROM celdas
                                                      WHERE conjunto_id = :c AND nombre_visible = :nm LIMIT 1");
                                $sc->execute([':c' => $conjuntoId, ':nm' => $codigo]);
                                $eid = (int)$sc->fetchColumn();
                                if ($eid) {
                                    $fila['celda_existente_id'] = $eid;
                                    if (empty($fila['errores'])) $fila['accion'] = 'actualizar';
                                }
                            }

                            if (!empty($fila['errores'])) $fila['accion'] = 'error';

                            $filas[] = $fila;
                            $resumen[$fila['accion']]++;
                        }
                    }
                }
            }
        }
    }
}

// ────────────────────────────────────────────────────────────────
//  PASO 2 - Confirmar (procesar)
// ────────────────────────────────────────────────────────────────
$resultado = null;

if ($paso === 'confirmar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['_csrf']) && !empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
    csrf_require();

    $filasJson = $_POST['filas_json'] ?? '';
    $decisiones = $_POST['decision'] ?? [];

    $filasRaw = json_decode($filasJson, true);
    if (!is_array($filasRaw)) {
        $errorGlobal = 'Datos inválidos. Vuelve a empezar la importación.';
    } else {
        $r = ['creadas' => 0, 'actualizadas' => 0, 'saltadas' => 0, 'errores' => 0, 'detalles' => []];

        try {
            $pdo->beginTransaction();

            // Cache de numero_orden por nivel
            $maxOrden = [];

            foreach ($filasRaw as $i => $f) {
                $decision = $decisiones[$i] ?? 'saltar';
                if (!in_array($decision, ['crear','actualizar','saltar'], true)) $decision = 'saltar';

                if (!empty($f['errores'])) {
                    $r['errores']++;
                    $r['detalles'][] = "Línea {$f['linea']}: omitida por error";
                    continue;
                }
                if ($decision === 'saltar') {
                    $r['saltadas']++;
                    continue;
                }

                $codigo  = $f['codigo'];
                $nivelId = (int)$f['nivel_id'];
                $tipo    = $f['tipo'];
                $aptoId  = $f['apto_id'] ? (int)$f['apto_id'] : null;
                $carro   = (int)$f['permite_carro'];
                $moto    = (int)$f['permite_moto'];
                $obs     = $f['obs'] ?: null;

                if ($decision === 'crear') {
                    if (!isset($maxOrden[$nivelId])) {
                        $stOrd = $pdo->prepare("SELECT COALESCE(MAX(numero_orden), 0)
                                                  FROM celdas WHERE nivel_id = :nv AND conjunto_id = :c");
                        $stOrd->execute([':nv' => $nivelId, ':c' => $conjuntoId]);
                        $maxOrden[$nivelId] = (int)$stOrd->fetchColumn();
                    }
                    $maxOrden[$nivelId]++;

                    $ins = $pdo->prepare("INSERT INTO celdas
                            (conjunto_id, nivel_id, numero_orden, nombre_visible, tipo, apto_dueno_id,
                             permite_carro, permite_moto, activa, observaciones)
                        VALUES (:c, :nv, :no, :nm, :t, :ad, :pc, :pm, 1, :ob)");
                    $ins->execute([
                        ':c' => $conjuntoId, ':nv' => $nivelId,
                        ':no' => $maxOrden[$nivelId], ':nm' => $codigo,
                        ':t' => $tipo, ':ad' => $aptoId,
                        ':pc' => $carro, ':pm' => $moto, ':ob' => $obs,
                    ]);
                    $r['creadas']++;
                }
                elseif ($decision === 'actualizar') {
                    $eid = (int)$f['celda_existente_id'];
                    if ($eid < 1) {
                        $r['errores']++;
                        $r['detalles'][] = "Línea {$f['linea']}: no se encontró ID de celda existente";
                        continue;
                    }
                    // NO se cambia nivel_id ni numero_orden (preservan orden físico)
                    $up = $pdo->prepare("UPDATE celdas SET
                            tipo = :t, apto_dueno_id = :ad,
                            permite_carro = :pc, permite_moto = :pm, observaciones = :ob
                        WHERE id = :id AND conjunto_id = :c");
                    $up->execute([
                        ':t' => $tipo, ':ad' => $aptoId,
                        ':pc' => $carro, ':pm' => $moto, ':ob' => $obs,
                        ':id' => $eid, ':c' => $conjuntoId,
                    ]);
                    $r['actualizadas']++;
                }
            }

            $pdo->commit();
            $resultado = $r;
            if (function_exists('flash_set')) {
                $msg = "Importación lista: {$r['creadas']} creadas, {$r['actualizadas']} actualizadas";
                if ($r['saltadas'] > 0) $msg .= ", {$r['saltadas']} saltadas";
                if ($r['errores']  > 0) $msg .= ", {$r['errores']} con error";
                flash_set('ok', $msg . '.');
            }
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errorGlobal = APP_DEBUG ? $ex->getMessage() : 'Error al procesar. No se guardó nada.';
        }
    }
}

$_pageTitle = 'Importar celdas';
include INCLUDES_PATH . '/header.php';
?>

<style>
.imp-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.imp-card h3{margin:0 0 10px;font-size:15px;}
.imp-step{display:flex;gap:14px;align-items:center;padding:14px;background:#dbeafe;border-radius:6px;margin-bottom:14px;color:#1e3a8a;}
.imp-step strong{font-size:18px;}
.imp-resumen{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0;}
.imp-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;min-width:120px;}
.imp-kpi strong{display:block;font-size:22px;line-height:1.1;}
.imp-kpi span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.imp-kpi.ok strong{color:#15803d;}
.imp-kpi.upd strong{color:#d97706;}
.imp-kpi.err strong{color:#dc2626;}
.imp-tabla{width:100%;border-collapse:collapse;margin-top:8px;font-size:13px;}
.imp-tabla th,.imp-tabla td{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left;}
.imp-tabla th{background:#f3f4f6;font-weight:600;font-size:11px;text-transform:uppercase;color:#374151;}
.imp-tabla tr.estado-error{background:#fef2f2;}
.imp-tabla tr.estado-actualizar{background:#fefce8;}
.imp-tabla tr.estado-crear{background:#f0fdf4;}
.imp-tabla .errores{color:#dc2626;font-size:12px;}
.imp-tabla .pill-mini{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.imp-tabla .pill-mini.crear{background:#dcfce7;color:#166534;}
.imp-tabla .pill-mini.actualizar{background:#fef3c7;color:#92400e;}
.imp-tabla .pill-mini.error{background:#fee2e2;color:#991b1b;}
.imp-tabla .pill-mini.saltar{background:#f3f4f6;color:#6b7280;}
.imp-tabla select.deci{padding:3px 6px;font-size:12px;border:1px solid #d1d5db;border-radius:4px;}
.imp-formato{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:13px;margin-top:10px;}
.imp-formato code{background:#f3f4f6;padding:1px 5px;border-radius:3px;font-family:monospace;}
.imp-formato ul{margin:6px 0 6px 18px;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📥 Importar celdas desde Excel/CSV</h1>
    <p class="page-head__sub">Carga masiva con detección de duplicados.</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/parqueadero') ?>">← Volver a celdas</a>
    <a class="btn" href="<?= url('/parqueadero/plantilla_celdas') ?>">📄 Descargar plantilla CSV</a>
</div>

<?php if ($errorGlobal): ?>
    <div class="flash flash--error"><?= e($errorGlobal) ?></div>
<?php endif; ?>

<?php if ($resultado): ?>
    <!-- ═════════════ PASO 3: RESULTADO ═════════════ -->
    <div class="imp-step">
        <strong>✅ Listo</strong>
        <span>Importación completada.</span>
    </div>

    <div class="imp-resumen">
        <div class="imp-kpi ok"><strong><?= $resultado['creadas'] ?></strong><span>Creadas</span></div>
        <div class="imp-kpi upd"><strong><?= $resultado['actualizadas'] ?></strong><span>Actualizadas</span></div>
        <div class="imp-kpi"><strong><?= $resultado['saltadas'] ?></strong><span>Saltadas</span></div>
        <div class="imp-kpi err"><strong><?= $resultado['errores'] ?></strong><span>Con error</span></div>
    </div>

    <?php if (!empty($resultado['detalles'])): ?>
        <div class="imp-card">
            <h3>Detalle</h3>
            <ul style="margin:0 0 0 18px"><?php foreach ($resultado['detalles'] as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div style="margin-top:14px">
        <a class="btn btn--primary" href="<?= url('/parqueadero') ?>">Ver celdas →</a>
        <a class="btn" href="<?= url('/parqueadero/importar') ?>">Importar otro archivo</a>
    </div>

<?php elseif (!empty($filas)): ?>
    <!-- ═════════════ PASO 2: PREVIEW ═════════════ -->
    <div class="imp-step">
        <strong>Paso 2 de 3</strong>
        <span>Revisa el resultado del análisis. Marca qué hacer con cada fila y confirma.</span>
    </div>

    <div class="imp-resumen">
        <div class="imp-kpi ok"><strong><?= $resumen['crear'] ?></strong><span>Para crear</span></div>
        <div class="imp-kpi upd"><strong><?= $resumen['actualizar'] ?></strong><span>Ya existen</span></div>
        <div class="imp-kpi err"><strong><?= $resumen['error'] ?></strong><span>Con error</span></div>
    </div>

    <form method="POST" action="<?= url('/parqueadero/importar') ?>"
          onsubmit="return confirm('¿Aplicar los cambios marcados? Esto no se puede deshacer.');">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="paso" value="confirmar">
        <input type="hidden" name="filas_json" value='<?= e(json_encode($filas, JSON_UNESCAPED_UNICODE)) ?>'>

        <div class="imp-card">
            <h3>Filas detectadas (<?= count($filas) ?>)</h3>
            <table class="imp-tabla">
                <thead>
                <tr>
                    <th>#</th><th>Estado</th><th>Decisión</th>
                    <th>Código</th><th>Nivel</th><th>Tipo</th>
                    <th>🚗</th><th>🏍️</th><th>Apto dueño</th><th>Observación / Errores</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $i => $f):
                    $estado = $f['accion'];
                    $pillClass = $estado === 'crear' ? 'crear' : ($estado === 'actualizar' ? 'actualizar' : 'error');
                    $pillTxt   = $estado === 'crear' ? '✨ Nueva' : ($estado === 'actualizar' ? '🔄 Ya existe' : '⚠️ Error');
                ?>
                    <tr class="estado-<?= $estado ?>">
                        <td><?= (int)$f['linea'] ?></td>
                        <td><span class="pill-mini <?= $pillClass ?>"><?= $pillTxt ?></span></td>
                        <td>
                            <?php if ($estado === 'crear'): ?>
                                <select class="deci" name="decision[<?= $i ?>]">
                                    <option value="crear">Crear</option>
                                    <option value="saltar">Saltar</option>
                                </select>
                            <?php elseif ($estado === 'actualizar'): ?>
                                <select class="deci" name="decision[<?= $i ?>]">
                                    <option value="actualizar" selected>Actualizar</option>
                                    <option value="saltar">Saltar</option>
                                </select>
                            <?php else: ?>
                                <span class="pill-mini saltar">Se saltará</span>
                                <input type="hidden" name="decision[<?= $i ?>]" value="saltar">
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($f['codigo']) ?></strong></td>
                        <td><?= e($f['nivel']) ?></td>
                        <td><?= e($f['tipo_orig']) ?><?php if ($f['tipo']): ?> → <small><?= e($f['tipo']) ?></small><?php endif; ?></td>
                        <td><?= $f['permite_carro'] ? '✓' : '' ?></td>
                        <td><?= $f['permite_moto']  ? '✓' : '' ?></td>
                        <td><?= e($f['apto']) ?></td>
                        <td>
                            <?php if (!empty($f['errores'])): ?>
                                <span class="errores"><?= e(implode('; ', $f['errores'])) ?></span>
                            <?php else: ?>
                                <?= e($f['obs']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
            <button type="submit" class="btn btn--primary">✅ Aplicar cambios</button>
            <a class="btn" href="<?= url('/parqueadero/importar') ?>">↺ Empezar de nuevo</a>
        </div>
    </form>

<?php else: ?>
    <!-- ═════════════ PASO 1: SUBIR ═════════════ -->
    <div class="imp-step">
        <strong>Paso 1 de 3</strong>
        <span>Sube tu archivo CSV con las celdas a importar.</span>
    </div>

    <div class="imp-card" style="max-width:640px">
        <form method="POST" action="<?= url('/parqueadero/importar') ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="paso" value="parsear">

            <label style="display:block;font-weight:600;margin-bottom:6px">Archivo CSV</label>
            <input type="file" name="archivo" accept=".csv,text/csv" required style="margin-bottom:10px">

            <div style="margin-top:14px">
                <button type="submit" class="btn btn--primary">Analizar archivo →</button>
                <a class="btn" href="<?= url('/parqueadero/plantilla_celdas') ?>">📄 Descargar plantilla</a>
            </div>
        </form>

        <div class="imp-formato">
            <strong>Formato esperado:</strong>
            <ul>
                <li>CSV con encabezado en la primera fila.</li>
                <li>Columnas: <code>Codigo</code>, <code>Nivel</code>, <code>Tipo</code>, <code>Permite carro</code>, <code>Permite moto</code>, <code>Apto dueno</code>, <code>Observaciones</code>.</li>
                <li>Para <strong>Permite carro/moto</strong> usa <code>x</code> o déjalo vacío.</li>
                <li>Tipos válidos: <code>Comun</code>, <code>Privada</code>, <code>Moto comun</code>, <code>Libre</code>, <code>Mov. Reducida</code>.</li>
                <li>Si una celda con el mismo código ya existe, te preguntará si actualizarla.</li>
            </ul>
            <strong style="display:block;margin-top:8px">Desde Excel:</strong>
            <ul>
                <li>Archivo → Guardar como → <strong>"CSV UTF-8 (delimitado por comas)"</strong>.</li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
