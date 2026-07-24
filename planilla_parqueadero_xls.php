<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/planilla_parqueadero_xls.php
// v1.1 (3AT): Genera el archivo .xls (HTML-based) con la planilla mensual.
//   Excel abre este formato directamente y respeta colores, colspan, etc.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$meses = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',
          7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];

$modo = $_GET['modo'] ?? 'mes';
$tipoVeh = in_array($_GET['tipo_veh'] ?? '', ['carro','moto'], true) ? $_GET['tipo_veh'] : 'todos';
$orden = in_array($_GET['orden'] ?? '', ['apto','placa','dias'], true) ? $_GET['orden'] : 'apto';

// Determinar rango de fechas
if ($modo === 'rango') {
    $desde = clean_string($_GET['desde'] ?? date('Y-m-01'), 10);
    $hasta = clean_string($_GET['hasta'] ?? date('Y-m-d'), 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        exit('Fechas inválidas');
    }
    $tituloRango = 'DEL ' . date('d/m/Y', strtotime($desde)) . ' AL ' . date('d/m/Y', strtotime($hasta));
} else {
    $mes  = (int)($_GET['mes'] ?? date('m'));
    $anio = (int)($_GET['anio'] ?? date('Y'));
    if ($mes < 1 || $mes > 12 || $anio < 2020 || $anio > 2100) exit('Fecha inválida');
    $desde = sprintf('%04d-%02d-01', $anio, $mes);
    $hasta = date('Y-m-t', strtotime($desde));  // último día del mes
    $tituloRango = $meses[$mes] . ' ' . $anio;
}

$desdeSql = $desde . ' 00:00:00';
$hastaSql = $hasta . ' 23:59:59';

// Rango de días para las columnas
$diaInicio = (int)date('j', strtotime($desde));
$diaFin    = (int)date('j', strtotime($hasta));
$mismoMes  = date('Y-m', strtotime($desde)) === date('Y-m', strtotime($hasta));
if (!$mismoMes) {
    // Cross-month: usar 1..31 y filtrar cuáles caen en el rango después
    $diaInicio = 1;
    $diaFin = 31;
}

// ── OBTENER TODOS LOS EVENTOS DE PARQUEO EN EL RANGO ──
// Búsqueda dual: rondas_detalle + lecturas_placas con revista_id
$filtroTipo = '';
$paramsBase = [':c' => $conjuntoId, ':fd' => $desdeSql, ':fh' => $hastaSql];
if ($tipoVeh === 'carro' || $tipoVeh === 'moto') {
    $filtroTipo = " AND v.tipo = :tv ";
    $paramsBase[':tv'] = $tipoVeh;
}

// FUENTE A: rondas_detalle
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
$stA = $pdo->prepare($sqlA);
$stA->execute($paramsBase);
$rowsA = $stA->fetchAll();

// FUENTE B: lecturas_placas con revista_id (o nivel+celda)
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
$paramsB = $paramsBase;
$paramsB[':c2'] = $conjuntoId;
$stB = $pdo->prepare($sqlB);
$stB->execute($paramsB);
$rowsB = $stB->fetchAll();

// FUENTE C: revistas_detalle (LA TABLA QUE FALTABA v3AT)
// Estructura: rvd(id, revista_id, celda_id, estado, placa_detectada, vehiculo_id, foto_path)
// Sin creado_en propio → usar rv.iniciado_en como fecha
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
$paramsC = $paramsBase;
$paramsC[':c2'] = $conjuntoId;
$stC = $pdo->prepare($sqlC);
$stC->execute($paramsC);
$rowsC = $stC->fetchAll();

// Combinar las 3 fuentes
$rows = array_merge($rowsA, $rowsB, $rowsC);

// ── AGRUPAR: por vehículo → por día → celda más frecuente ──
$vehiculos = [];  // [vehiculo_id => ['placa','apto','residente_nombre','residente_tipo','tipo_veh','dias'=>[dia => celda]]]
$diaCounter = []; // [vehiculo_id][dia][celda] = count

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
        // Verificar que la fecha real cae dentro del rango
        $fechaReal = $r['fecha_dia'];
        if ($fechaReal < $desde || $fechaReal > $hasta) continue;
    }

    $celda = trim(($r['nivel_codigo'] ?? '') . ($r['nivel_codigo'] ? '/' : '') . ($r['celda_nombre'] ?? ''));
    if ($celda === '' || $celda === '/') continue;

    if (!isset($diaCounter[$vid][$dia][$celda])) {
        $diaCounter[$vid][$dia][$celda] = 0;
    }
    $diaCounter[$vid][$dia][$celda]++;
}

// v7.65: días que el vehículo lleva EN SU CELDA ACTUAL.
// Se busca el último día con registro y se cuenta hacia atrás mientras
// la celda sea la MISMA. Los días sin registro no cortan la racha
// (la revista no se hace todos los días), pero un cambio de celda sí.
function sp_dias_en_celda_actual(array $dias): array {
    if (!$dias) return ['dias' => 0, 'celda' => ''];
    $conDato = array_keys($dias);
    sort($conDato, SORT_NUMERIC);
    $ultimo  = end($conDato);
    $celdaAct = $dias[$ultimo];
    $cuenta  = 0;
    // recorrer los días CON registro, del más nuevo al más viejo
    foreach (array_reverse($conDato) as $d) {
        if (($dias[$d] ?? '') === $celdaAct) {
            $cuenta++;
        } else {
            break;   // cambió de celda: la racha termina
        }
    }
    return ['dias' => $cuenta, 'celda' => $celdaAct];
}

// Resolver la celda ganadora por día (la que más se repite)
foreach ($diaCounter as $vid => $dias) {
    foreach ($dias as $dia => $celdas) {
        arsort($celdas);
        $vehiculos[$vid]['dias'][$dia] = array_key_first($celdas);
    }
}

// Ordenar
usort($vehiculos, function($a, $b) use ($orden){
    if ($orden === 'apto')  return strcmp($a['apto'] ?? '', $b['apto'] ?? '');
    if ($orden === 'placa') return strcmp($a['placa'], $b['placa']);
    if ($orden === 'dias')  return count($b['dias']) - count($a['dias']);
    return 0;
});

// ── GENERAR EL ARCHIVO XLS (HTML-based) ──
$filename = 'planilla_parqueadero_' . str_replace([' ','/'], '_', strtolower($tituloRango)) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Encoding para Excel
echo "\xEF\xBB\xBF"; // BOM UTF-8

$numDias = $diaFin - $diaInicio + 1;
$colsFijas = 5; // N, PLACA, APTO, USUARIO, TIPO
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
            PLANILLA VEHÍCULOS EN PARQUEADEROS COMUNES — <?= e($tituloRango) ?>
        </td>
    </tr>
    <tr>
        <td colspan="<?= $totalCols ?>" style="text-align:center;font-size:10px;background:#f8f8f8">
            Generado desde SmartPark · <?= date('d/m/Y H:i') ?> · Conjunto ID <?= (int)$conjuntoId ?>
            · Usuario: <?= e($u['nombre_completo'] ?? $u['username'] ?? '—') ?>
            · <?= count($vehiculos) ?> vehículos con actividad
        </td>
    </tr>

    <!-- Encabezados -->
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
        $usuarioLbl = trim($v['residente_nombre']);
        if ($v['residente_tipo']) $usuarioLbl .= ' (' . strtoupper($v['residente_tipo']) . ')';
        if (!$usuarioLbl) $usuarioLbl = '—';
        $totalDias = count($v['dias']);
        $racha     = sp_dias_en_celda_actual($v['dias'] ?? []);
        $diasCelda = (int)$racha['dias'];
        // colores SUAVES, de la misma paleta de la planilla:
        //   1 día  → verde claro (como los días con dato)
        //   2-3    → crema
        //   4+     → durazno claro (llama la atención sin gritar)
        $colRacha = $diasCelda >= 4 ? '#FCE4D6' : ($diasCelda >= 2 ? '#FFF2CC' : '#E2EFDA');
        $txtRacha = $diasCelda >= 4 ? '#833C00' : ($diasCelda >= 2 ? '#7F6000' : '#375623');
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
