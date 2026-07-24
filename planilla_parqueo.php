<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/planilla_parqueo.php
// v2.0 (3AU): UNIFICADO — mismo archivo hace formulario Y genera Excel.
//   - Sin parámetros de rango → muestra el formulario
//   - Con `formato=xls` en la URL → descarga el .xls
//   Elimina la dependencia del archivo /reportes/planilla_parqueadero_xls
//   (que requería registrar otra ruta en /index.php).
//
//   Búsqueda TRIPLE: rondas_detalle + lecturas_placas + revistas_detalle
//                    (la última es donde Rafael tiene sus registros)

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
          7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
$mesesUp = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',
            7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];

// ═════════════════════════════════════════════════════════════════
// MODO XLS: si viene `formato=xls`, generamos el Excel y salimos
// ═════════════════════════════════════════════════════════════════
if (($_GET['formato'] ?? '') === 'xls') {
    $modo = $_GET['modo'] ?? 'mes';
    $tipoVeh = in_array($_GET['tipo_veh'] ?? '', ['carro','moto'], true) ? $_GET['tipo_veh'] : 'todos';
    $orden = in_array($_GET['orden'] ?? '', ['apto','placa','dias'], true) ? $_GET['orden'] : 'apto';

    // Rango
    if ($modo === 'rango') {
        $desde = clean_string($_GET['desde'] ?? date('Y-m-01'), 10);
        $hasta = clean_string($_GET['hasta'] ?? date('Y-m-d'), 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) exit('Fechas inválidas');
        $tituloRango = 'DEL ' . date('d/m/Y', strtotime($desde)) . ' AL ' . date('d/m/Y', strtotime($hasta));
    } else {
        $mesXls  = (int)($_GET['mes'] ?? date('m'));
        $anioXls = (int)($_GET['anio'] ?? date('Y'));
        if ($mesXls < 1 || $mesXls > 12 || $anioXls < 2020 || $anioXls > 2100) exit('Fecha inválida');
        $desde = sprintf('%04d-%02d-01', $anioXls, $mesXls);
        $hasta = date('Y-m-t', strtotime($desde));
        $tituloRango = $mesesUp[$mesXls] . ' ' . $anioXls;
    }
    $desdeSql = $desde . ' 00:00:00';
    $hastaSql = $hasta . ' 23:59:59';

    $diaInicio = (int)date('j', strtotime($desde));
    $diaFin    = (int)date('j', strtotime($hasta));
    $mismoMes  = date('Y-m', strtotime($desde)) === date('Y-m', strtotime($hasta));
    if (!$mismoMes) { $diaInicio = 1; $diaFin = 31; }

    $filtroTipo = '';
    $paramsBase = [':c' => $conjuntoId, ':fd' => $desdeSql, ':fh' => $hastaSql];
    if ($tipoVeh === 'carro' || $tipoVeh === 'moto') {
        $filtroTipo = " AND v.tipo = :tv ";
        $paramsBase[':tv'] = $tipoVeh;
    }

    // ── FUENTE A: rondas_detalle ──
    $sqlA = "SELECT v.id AS vehiculo_id, v.placa, v.tipo AS tipo_veh,
                    a.numero_visible AS apto,
                    res.nombre AS residente_nombre, res.tipo AS residente_tipo,
                    DATE(rd.creado_en) AS fecha_dia,
                    c.nombre_visible AS celda_nombre,
                    np.codigo AS nivel_codigo
               FROM rondas_detalle rd
               JOIN rondas r ON r.id = rd.ronda_id
               JOIN vehiculos v ON v.id = rd.vehiculo_id
          LEFT JOIN apartamentos a ON a.id = v.apartamento_id
          LEFT JOIN residentes res ON res.id = v.residente_id
          LEFT JOIN celdas c ON c.id = rd.celda_id
          LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
              WHERE r.conjunto_id = :c
                AND rd.creado_en >= :fd AND rd.creado_en <= :fh
              $filtroTipo";
    try {
        $stA = $pdo->prepare($sqlA);
        $stA->execute($paramsBase);
        $rowsA = $stA->fetchAll();
    } catch (Exception $e) { $rowsA = []; }

    // ── FUENTE B: lecturas_placas con revista_id o nivel+celda ──
    $sqlB = "SELECT v.id AS vehiculo_id, v.placa, v.tipo AS tipo_veh,
                    a.numero_visible AS apto,
                    res.nombre AS residente_nombre, res.tipo AS residente_tipo,
                    DATE(lp.creado_en) AS fecha_dia,
                    lp.celda AS celda_nombre,
                    lp.nivel AS nivel_codigo
               FROM lecturas_placas lp
               JOIN vehiculos v ON v.id = lp.vehiculo_id OR v.placa = lp.placa_detectada
          LEFT JOIN apartamentos a ON a.id = v.apartamento_id
          LEFT JOIN residentes res ON res.id = v.residente_id
              WHERE lp.conjunto_id = :c
                AND v.conjunto_id = :c2
                AND lp.creado_en >= :fd AND lp.creado_en <= :fh
                AND (lp.revista_id IS NOT NULL OR (lp.nivel IS NOT NULL AND lp.celda IS NOT NULL))
              $filtroTipo";
    try {
        $paramsB = $paramsBase; $paramsB[':c2'] = $conjuntoId;
        $stB = $pdo->prepare($sqlB);
        $stB->execute($paramsB);
        $rowsB = $stB->fetchAll();
    } catch (Exception $e) { $rowsB = []; }

    // ── FUENTE C: revistas_detalle (LA TABLA REAL v3AT) ──
    $sqlC = "SELECT v.id AS vehiculo_id, v.placa, v.tipo AS tipo_veh,
                    a.numero_visible AS apto,
                    res.nombre AS residente_nombre, res.tipo AS residente_tipo,
                    DATE(rv.iniciado_en) AS fecha_dia,
                    c.nombre_visible AS celda_nombre,
                    np.codigo AS nivel_codigo
               FROM revistas_detalle rvd
               JOIN revistas rv ON rv.id = rvd.revista_id
               JOIN vehiculos v ON v.id = rvd.vehiculo_id OR v.placa = rvd.placa_detectada
          LEFT JOIN apartamentos a ON a.id = v.apartamento_id
          LEFT JOIN residentes res ON res.id = v.residente_id
          LEFT JOIN celdas c ON c.id = rvd.celda_id
          LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
              WHERE rv.conjunto_id = :c
                AND v.conjunto_id = :c2
                AND rv.iniciado_en >= :fd AND rv.iniciado_en <= :fh
                AND rvd.estado = 'ocupada'
              $filtroTipo";
    try {
        $paramsC = $paramsBase; $paramsC[':c2'] = $conjuntoId;
        $stC = $pdo->prepare($sqlC);
        $stC->execute($paramsC);
        $rowsC = $stC->fetchAll();
    } catch (Exception $e) { $rowsC = []; }

    $rows = array_merge($rowsA, $rowsB, $rowsC);

    // Agrupar
    // v7.65: días que el vehículo lleva EN SU CELDA ACTUAL.
    // Se toma el último día con registro y se cuenta hacia atrás mientras
    // la celda sea la MISMA. Un cambio de celda corta la cuenta; los días
    // sin revista NO la cortan (no se revisa todos los días).
    if (!function_exists('sp_dias_en_celda_actual')) {
        function sp_dias_en_celda_actual(array $dias): array {
            if (!$dias) return ['dias' => 0, 'celda' => ''];
            $conDato = array_keys($dias);
            sort($conDato, SORT_NUMERIC);
            $celdaAct = $dias[end($conDato)];
            $cuenta = 0;
            foreach (array_reverse($conDato) as $d) {
                if (($dias[$d] ?? '') === $celdaAct) { $cuenta++; }
                else { break; }
            }
            return ['dias' => $cuenta, 'celda' => $celdaAct];
        }
    }

    $vehiculos = [];
    $diaCounter = [];
    foreach ($rows as $r) {
        $vid = (int)$r['vehiculo_id'];
        if ($vid <= 0) continue;
        if (!isset($vehiculos[$vid])) {
            $vehiculos[$vid] = [
                'id' => $vid,
                'placa' => $r['placa'],
                'apto' => $r['apto'] ?? '',
                'tipo_veh' => $r['tipo_veh'] ?? 'carro',
                'residente_nombre' => $r['residente_nombre'] ?? '',
                'residente_tipo' => $r['residente_tipo'] ?? '',
                'dias' => [],
            ];
        }
        $dia = (int)date('j', strtotime($r['fecha_dia']));
        if ($dia < $diaInicio || $dia > $diaFin) continue;
        if (!$mismoMes) {
            $fechaReal = $r['fecha_dia'];
            if ($fechaReal < $desde || $fechaReal > $hasta) continue;
        }
        // v3AW: solo la CELDA, sin el nivel
        $celda = trim($r['celda_nombre'] ?? '');
        if ($celda === '') continue;
        if (!isset($diaCounter[$vid][$dia][$celda])) $diaCounter[$vid][$dia][$celda] = 0;
        $diaCounter[$vid][$dia][$celda]++;
    }
    foreach ($diaCounter as $vid => $dias) {
        foreach ($dias as $dia => $celdas) {
            arsort($celdas);
            $vehiculos[$vid]['dias'][$dia] = array_key_first($celdas);
        }
    }
    usort($vehiculos, function($a, $b) use ($orden){
        if ($orden === 'apto')  return strcmp($a['apto'] ?? '', $b['apto'] ?? '');
        if ($orden === 'placa') return strcmp($a['placa'], $b['placa']);
        if ($orden === 'dias')  return count($b['dias']) - count($a['dias']);
        return 0;
    });

    // Generar XLS (HTML-based)
    $filename = 'planilla_parqueadero_' . str_replace([' ','/'], '_', strtolower($tituloRango)) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";

    $numDias = $diaFin - $diaInicio + 1;
    $colsFijas = 5;
    $totalCols = $colsFijas + $numDias + 2; // +2: DIAS EN CELDA ACTUAL y TOTAL DIAS
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<style>
table { border-collapse: collapse; font-family: Calibri, Arial; font-size: 11px; }
th, td { border: 1px solid #333; padding: 4px 6px; text-align: center; vertical-align: middle; }
.titulo { background-color: #1F4E78; color: white; font-weight: bold; font-size: 14px; padding: 10px; text-align: center; }
.hdr    { background-color: #FFC000; color: #000; font-weight: bold; text-align: center; }
.hdr-dia{ background-color: #FFE699; color: #000; font-weight: bold; text-align: center; }
.hdr-tot{ background-color: #A9D08E; color: #000; font-weight: bold; text-align: center; }
.n      { background-color: #F2F2F2; font-weight: bold; text-align: center; }
.placa  { background-color: #FFF2CC; font-weight: bold; text-align: center; font-family: Consolas, monospace; }
.apto   { background-color: #FFF2CC; text-align: center; font-weight: bold; }
.usu    { text-align: left; padding-left: 8px; }
.tipo   { text-align: center; }
.dia-vacio { background-color: #FFFFFF; }
.dia-lleno { background-color: #E2EFDA; font-family: Consolas, monospace; font-size: 10px; font-weight: bold; color: #375623; }
.tot    { background-color: #C6E0B4; font-weight: bold; text-align: center; }
</style>
</head>
<body>
<table>
    <tr>
        <td colspan="<?= $totalCols ?>" class="titulo">
            PLANILLA VEHÍCULOS EN PARQUEADEROS — <?= e($tituloRango) ?>
        </td>
    </tr>
    <tr>
        <td colspan="<?= $totalCols ?>" style="text-align:center;font-size:10px;background:#f8f8f8">
            Generado desde SmartPark v3AW · <?= date('d/m/Y H:i') ?> · Conjunto ID <?= (int)$conjuntoId ?>
            · Usuario: <?= e($u['nombre_completo'] ?? $u['username'] ?? '—') ?>
            · <?= count($vehiculos) ?> vehículos con actividad
        </td>
    </tr>
    <tr>
        <th class="hdr">N</th>
        <th class="hdr">PLACA</th>
        <th class="hdr">APTO</th>
        <th class="hdr">USUARIO</th>
        <th class="hdr">TIPO</th>
        <?php for ($d = $diaInicio; $d <= $diaFin; $d++): ?>
            <th class="hdr-dia"><?= $d ?></th>
        <?php endfor; ?>
        <th class="hdr-tot" style="background:#FFE699;color:#000">DÍAS EN<br>CELDA ACTUAL</th>
        <th class="hdr-tot">TOTAL DÍAS</th>
    </tr>
<?php if (empty($vehiculos)): ?>
    <tr>
        <td colspan="<?= $totalCols ?>" style="text-align:center;padding:20px;color:#6b7280">
            Sin datos en el rango seleccionado.
            Verifica que hay revistas en el período <?= e($tituloRango) ?>.
        </td>
    </tr>
<?php else: ?>
    <?php $n = 1; foreach ($vehiculos as $v):
        // v3AW: solo el TIPO de usuario, no el nombre
        $tipoRaw = strtoupper(trim($v['residente_tipo'] ?? ''));
        $usuarioLbl = $tipoRaw ?: '—';
        $totalDias = count($v['dias']);
        $racha     = sp_dias_en_celda_actual($v['dias'] ?? []);
        $diasCelda = (int)$racha['dias'];
        $colRacha  = $diasCelda >= 4 ? '#FCE4D6' : ($diasCelda >= 2 ? '#FFF2CC' : '#E2EFDA');
        $txtRacha  = $diasCelda >= 4 ? '#833C00' : ($diasCelda >= 2 ? '#7F6000' : '#375623');
    ?>
        <tr>
            <td class="n"><?= $n++ ?></td>
            <td class="placa"><?= e($v['placa']) ?></td>
            <td class="apto"><?= e($v['apto'] ?: '—') ?></td>
            <td class="usu"><?= e($usuarioLbl) ?></td>
            <td class="tipo"><?= $v['tipo_veh'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></td>
            <?php for ($d = $diaInicio; $d <= $diaFin; $d++):
                $celda = $v['dias'][$d] ?? '';
            ?>
                <?php if ($celda): ?>
                    <td class="dia-lleno"><?= e($celda) ?></td>
                <?php else: ?>
                    <td class="dia-vacio">&nbsp;</td>
                <?php endif; ?>
            <?php endfor; ?>
            <td class="tot" style="background:<?= $colRacha ?>;color:<?= $txtRacha ?>;font-weight:700"
                title="<?= e($racha['celda']) ?>"><?= $diasCelda ?></td>
            <td class="tot"><?= $totalDias ?></td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</table>
</body>
</html>
    <?php
    exit; // Fin del modo XLS
}

// ═════════════════════════════════════════════════════════════════
// MODO FORMULARIO (default)
// ═════════════════════════════════════════════════════════════════
$hoy = date('Y-m-d');
$mesActual = (int)date('m');
$anioActual = (int)date('Y');

$stCount = $pdo->prepare("SELECT COUNT(*) FROM lecturas_placas
                           WHERE conjunto_id = :c
                             AND (revista_id IS NOT NULL OR (nivel IS NOT NULL AND celda IS NOT NULL))");
$stCount->execute([':c' => $conjuntoId]);
$totalLect = (int)$stCount->fetchColumn();

$stCount2 = $pdo->prepare("SELECT COUNT(*) FROM revistas_detalle rvd
                             JOIN revistas rv ON rv.id = rvd.revista_id
                            WHERE rv.conjunto_id = :c AND rvd.estado = 'ocupada'");
$stCount2->execute([':c' => $conjuntoId]);
$totalRevDet = (int)$stCount2->fetchColumn();

$_pageTitle = 'Planilla mensual de parqueadero';
include INCLUDES_PATH . '/header.php';
?>

<style>
.pl-head{background:linear-gradient(135deg,#065f46,#0f766e);color:#fff;border-radius:10px;padding:20px 24px;margin-top:12px;}
.pl-head h1{margin:0;font-size:22px;}
.pl-head p{margin:6px 0 0;font-size:13px;opacity:.9;}

.pl-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px 26px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.pl-card h3{margin:0 0 12px;font-size:15px;color:#065f46;padding-bottom:8px;border-bottom:2px solid #d1fae5;}

.pl-tabs{display:flex;gap:8px;margin-bottom:14px;}
.pl-tab{flex:1;padding:12px;text-align:center;background:#f3f4f6;border-radius:8px;cursor:pointer;font-weight:600;transition:all .15s;border:2px solid transparent;}
.pl-tab.active{background:#065f46;color:#fff;border-color:#065f46;}

.pl-form-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;}
.pl-field{flex:1;min-width:180px;}
.pl-field label{display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:600;text-transform:uppercase;}
.pl-field select, .pl-field input{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;}
.pl-btn-generate{background:#065f46;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-top:10px;}
.pl-btn-generate:hover{background:#047857;}

.pl-info{background:#f0fdf4;border-left:4px solid #16a34a;padding:12px 16px;border-radius:6px;color:#166534;font-size:13px;line-height:1.6;margin-bottom:14px;}
.pl-stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;}
.pl-stat{background:#fff;border:1px solid #d1fae5;padding:8px 12px;border-radius:6px;font-size:12px;}
.pl-stat strong{color:#065f46;font-size:15px;display:block;}
</style>

<div class="pl-head">
    <h1>📊 Planilla mensual de parqueadero</h1>
    <p>Exporta un Excel con la ocupación de cada vehículo por día. En cada casilla aparece la CELDA donde estuvo parqueado.</p>
</div>

<div class="toolbar">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <a class="btn" href="<?= url('/rondas') ?>">🧭 Rondas</a>
    <a class="btn" href="<?= url('/revistas') ?>">📋 Revistas</a>
</div>

<div class="pl-info">
    <strong>ℹ️ Cómo funciona</strong><br>
    Cada fila es un vehículo que apareció en alguna revista dentro del rango. Cada columna
    numerada (1-31) es un día del mes. Si el vehículo estuvo parqueado ese día, verás la
    celda donde estuvo (ej: <code>C99102</code>). Si no fue detectado, la casilla queda vacía.
    <div class="pl-stats">
        <div class="pl-stat"><strong><?= number_format($totalRevDet) ?></strong>Registros en revistas</div>
        <div class="pl-stat"><strong><?= number_format($totalLect) ?></strong>Lecturas con contexto</div>
        <div class="pl-stat"><strong><?= e($meses[$mesActual]) ?> <?= $anioActual ?></strong>Mes actual</div>
    </div>
</div>

<div class="pl-card">
    <h3>Rango a exportar</h3>

    <div class="pl-tabs">
        <div class="pl-tab active" id="tab-mes" onclick="plCambiarTab('mes')">📅 Por mes completo</div>
        <div class="pl-tab" id="tab-rango" onclick="plCambiarTab('rango')">📆 Rango personalizado</div>
    </div>

    <!-- v3AU: action apunta a este MISMO archivo con formato=xls -->
    <form method="get" action="<?= url('/reportes/planilla_parqueo') ?>" target="_blank">
        <input type="hidden" name="formato" value="xls">
        <input type="hidden" name="modo" id="pl-modo" value="mes">

        <div id="pl-panel-mes">
            <div class="pl-form-row">
                <div class="pl-field">
                    <label>Mes</label>
                    <select name="mes">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $mesActual ? 'selected' : '' ?>><?= e($meses[$m]) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="pl-field">
                    <label>Año</label>
                    <select name="anio">
                        <?php for ($a = $anioActual; $a >= $anioActual - 3; $a--): ?>
                            <option value="<?= $a ?>" <?= $a === $anioActual ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>

        <div id="pl-panel-rango" style="display:none">
            <div class="pl-form-row">
                <div class="pl-field">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="pl-field">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= $hoy ?>">
                </div>
            </div>
        </div>

        <div class="pl-form-row">
            <div class="pl-field">
                <label>Tipo de vehículos a incluir</label>
                <select name="tipo_veh">
                    <option value="todos" selected>Todos (carros + motos)</option>
                    <option value="carro">Solo carros</option>
                    <option value="moto">Solo motos</option>
                </select>
            </div>
            <div class="pl-field">
                <label>Ordenar por</label>
                <select name="orden">
                    <option value="apto" selected>Apto (menor a mayor)</option>
                    <option value="placa">Placa (A-Z)</option>
                    <option value="dias">Días con parqueo (más a menos)</option>
                </select>
            </div>
        </div>

        <button type="submit" class="pl-btn-generate">📥 Generar Excel</button>
    </form>
</div>

<div class="pl-card">
    <h3>📝 Detalles del formato</h3>
    <p style="font-size:13px;color:#374151;line-height:1.7">
        El archivo se descarga como <code>.xls</code> y Excel lo abre directamente. Contiene:
    </p>
    <ul style="font-size:13px;color:#374151;line-height:1.8">
        <li><strong>Título:</strong> "PLANILLA VEHÍCULOS EN PARQUEADEROS [MES] [AÑO]"</li>
        <li><strong>Columnas fijas:</strong> N, PLACA, APTO, USUARIO (tipo residente/propietario), TIPO</li>
        <li><strong>Columnas de días:</strong> del 1 al último día del mes (o del rango elegido)</li>
        <li><strong>Contenido de cada día:</strong> nombre de la celda donde estuvo parqueado (ej: <code>C99102</code>). Si aparece en varias celdas ese día, muestra la más frecuente. Si no fue detectado, queda vacío.</li>
        <li><strong>Resumen final:</strong> última columna con total de días con actividad</li>
    </ul>
</div>

<script>
function plCambiarTab(modo) {
    document.getElementById('pl-modo').value = modo;
    document.getElementById('tab-mes').classList.toggle('active', modo === 'mes');
    document.getElementById('tab-rango').classList.toggle('active', modo === 'rango');
    document.getElementById('pl-panel-mes').style.display = modo === 'mes' ? '' : 'none';
    document.getElementById('pl-panel-rango').style.display = modo === 'rango' ? '' : 'none';
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
