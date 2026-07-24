<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/alquileres.php
// v1.0 (3Y): Reporte mensual de alquileres a cobrar.
//   Combina celdas de parqueadero + cuartos útiles con tipo = 'alquiler'
//   Muestra los cobros esperados por mes basados en asignaciones activas/vigentes.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// Filtros
$hoy       = date('Y-m-d');
$f_mes     = (int)($_GET['mes'] ?? date('n'));
$f_anio    = (int)($_GET['anio'] ?? date('Y'));
$f_tipo    = in_array($_GET['tipo'] ?? '', ['celdas','cuartos'], true) ? $_GET['tipo'] : '';
$f_torre   = clean_string($_GET['torre'] ?? '', 20);
$f_apto    = clean_string($_GET['apto'] ?? '', 20);
$f_periodo = in_array($_GET['periodo'] ?? '', ['actuales','vigentes_mes'], true) ? $_GET['periodo'] : 'actuales';

if ($f_mes < 1 || $f_mes > 12) $f_mes = (int)date('n');
if ($f_anio < 2020 || $f_anio > 2099) $f_anio = (int)date('Y');

// Rango del mes seleccionado (para modo 'vigentes_mes')
$mesInicio = sprintf('%04d-%02d-01', $f_anio, $f_mes);
$mesFin    = date('Y-m-t', strtotime($mesInicio)); // último día del mes

$meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
          7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// ── Filtro base según modo ──
function condicionActividad(string $periodo, string $mesInicio, string $mesFin): array {
    if ($periodo === 'vigentes_mes') {
        // Una asignación es vigente en un mes si:
        //   fecha_inicio <= último día del mes
        //   Y (fecha_fin IS NULL OR fecha_fin >= primer día del mes)
        //   Y (archivado_en IS NULL OR archivado_en >= primer día del mes)
        return [
            'sql' => "ac.fecha_inicio <= :fin
                     AND (ac.fecha_fin IS NULL OR ac.fecha_fin >= :ini)
                     AND (ac.archivado_en IS NULL OR ac.archivado_en >= :ini2)",
            'params' => [':fin' => $mesFin, ':ini' => $mesInicio, ':ini2' => $mesInicio],
        ];
    }
    // Modo default: solo activas actuales
    return [
        'sql' => "ac.activa = 1 AND ac.archivado_en IS NULL",
        'params' => [],
    ];
}

// ────────────────────────────────────────────────────────────────
// CONSULTAS
// ────────────────────────────────────────────────────────────────
$cond = condicionActividad($f_periodo, $mesInicio, $mesFin);

// ── Cargar pagos del mes/año seleccionado ──
// Mapeo: [ 'celda-123' => {...}, 'cuarto-45' => {...} ]
$pagosMes = [];
$stPagos = $pdo->prepare("SELECT id, asignacion_tipo, asignacion_id, valor_esperado, valor_pagado,
                                fecha_pago, referencia, observacion, creado_en
                           FROM pagos_alquileres
                          WHERE conjunto_id = :c AND mes = :m AND anio = :a");
$stPagos->execute([':c' => $conjuntoId, ':m' => $f_mes, ':a' => $f_anio]);
foreach ($stPagos->fetchAll() as $p) {
    $pagosMes[$p['asignacion_tipo'] . '-' . (int)$p['asignacion_id']] = $p;
}

$filas = [];

// ═══ CELDAS ═══
if ($f_tipo === '' || $f_tipo === 'celdas') {
    $sqlCeldas = "SELECT ac.id, ac.tipo, ac.valor_mensual, ac.fecha_inicio, ac.fecha_fin,
                         ac.activa, ac.archivado_en, ac.observacion,
                         'celda' AS categoria,
                         c.nombre_visible AS elemento_nombre,
                         n.codigo AS nivel_codigo,
                         ad.numero_visible AS apto_dueno_num,
                         au.numero_visible AS apto_usuario_num,
                         tu.numero AS torre_usuario,
                         au.piso AS piso_usuario,
                         ru.nombre AS residente_usuario,
                         ru.celular AS residente_celular
                    FROM asignaciones_celdas ac
                    JOIN celdas c              ON c.id = ac.celda_id
                    JOIN niveles_parqueadero n ON n.id = c.nivel_id
               LEFT JOIN apartamentos ad       ON ad.id = ac.apto_dueno_id
               LEFT JOIN apartamentos au       ON au.id = ac.apto_usuario_id
               LEFT JOIN torres tu             ON tu.id = au.torre_id
               LEFT JOIN residentes ru ON ru.id = (SELECT r.id FROM residentes r WHERE r.apartamento_id = au.id AND r.activo = 1 ORDER BY (r.tipo='propietario') DESC, r.id LIMIT 1)
                   WHERE c.conjunto_id = :cid
                     AND ac.tipo = 'alquiler'
                     AND {$cond['sql']}";
    $params = array_merge([':cid' => $conjuntoId], $cond['params']);

    if ($f_torre !== '') { $sqlCeldas .= " AND tu.numero = :tr"; $params[':tr'] = $f_torre; }
    if ($f_apto  !== '') { $sqlCeldas .= " AND au.numero_visible = :ap"; $params[':ap'] = $f_apto; }

    $sqlCeldas .= " ORDER BY tu.numero, au.numero_visible, c.nombre_visible";

    $st = $pdo->prepare($sqlCeldas);
    $st->execute($params);
    foreach ($st->fetchAll() as $r) $filas[] = $r;
}

// ═══ CUARTOS ═══
if ($f_tipo === '' || $f_tipo === 'cuartos') {
    $sqlCuartos = "SELECT ac.id, ac.tipo, ac.valor_mensual, ac.fecha_inicio, ac.fecha_fin,
                          ac.activa, ac.archivado_en, ac.observacion,
                          'cuarto' AS categoria,
                          cu.codigo AS elemento_nombre,
                          NULL AS nivel_codigo,
                          ad.numero_visible AS apto_dueno_num,
                          au.numero_visible AS apto_usuario_num,
                          tu.numero AS torre_usuario,
                          au.piso AS piso_usuario,
                          ru.nombre AS residente_usuario,
                          ru.celular AS residente_celular
                     FROM asignaciones_cuartos ac
                     JOIN cuartos_utiles cu    ON cu.id = ac.cuarto_id
                LEFT JOIN apartamentos ad      ON ad.id = ac.apto_dueno_id
                LEFT JOIN apartamentos au      ON au.id = ac.apto_usuario_id
                LEFT JOIN torres tu            ON tu.id = au.torre_id
                LEFT JOIN residentes ru ON ru.id = (SELECT r.id FROM residentes r WHERE r.apartamento_id = au.id AND r.activo = 1 ORDER BY (r.tipo='propietario') DESC, r.id LIMIT 1)
                    WHERE cu.conjunto_id = :cid
                      AND ac.tipo = 'alquiler'
                      AND {$cond['sql']}";
    $params2 = array_merge([':cid' => $conjuntoId], $cond['params']);

    if ($f_torre !== '') { $sqlCuartos .= " AND tu.numero = :tr"; $params2[':tr'] = $f_torre; }
    if ($f_apto  !== '') { $sqlCuartos .= " AND au.numero_visible = :ap"; $params2[':ap'] = $f_apto; }

    $sqlCuartos .= " ORDER BY tu.numero, au.numero_visible, cu.codigo";

    $st2 = $pdo->prepare($sqlCuartos);
    $st2->execute($params2);
    foreach ($st2->fetchAll() as $r) $filas[] = $r;
}

// Ordenar todo por torre → apto usuario → categoría
usort($filas, function($a, $b) {
    $t = strcmp((string)$a['torre_usuario'], (string)$b['torre_usuario']);
    if ($t !== 0) return $t;
    $x = strcmp((string)$a['apto_usuario_num'], (string)$b['apto_usuario_num']);
    if ($x !== 0) return $x;
    return strcmp($a['categoria'], $b['categoria']);
});

// KPIs
$totalGeneral = 0;
$totalCeldas  = 0;
$totalCuartos = 0;
$cntCeldas    = 0;
$cntCuartos   = 0;
$totalPagado    = 0;
$totalPendiente = 0;
$cntPagado      = 0;
$cntPendiente   = 0;
foreach ($filas as $r) {
    $v = (float)$r['valor_mensual'];
    $totalGeneral += $v;
    if ($r['categoria'] === 'celda') { $totalCeldas += $v; $cntCeldas++; }
    else                             { $totalCuartos += $v; $cntCuartos++; }

    $key = $r['categoria'] . '-' . (int)$r['id'];
    if (isset($pagosMes[$key])) {
        $totalPagado += (float)$pagosMes[$key]['valor_pagado'];
        $cntPagado++;
    } else {
        $totalPendiente += $v;
        $cntPendiente++;
    }
}

// Totales por torre (para vista agrupada)
$totalesPorTorre = [];
foreach ($filas as $r) {
    $tr = (string)($r['torre_usuario'] ?? '?');
    if (!isset($totalesPorTorre[$tr])) $totalesPorTorre[$tr] = ['n' => 0, 'total' => 0];
    $totalesPorTorre[$tr]['n']++;
    $totalesPorTorre[$tr]['total'] += (float)$r['valor_mensual'];
}
ksort($totalesPorTorre);

// Export a CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alquileres_' . $f_anio . '-' . str_pad($f_mes, 2, '0', STR_PAD_LEFT) . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Torre','Apto Usuario','Piso','Residente','Celular','Categoría','Elemento','Nivel','Apto Dueño','Fecha Inicio','Fecha Fin','Valor Mensual','Estado','Valor Pagado','Fecha Pago','Referencia','Observación Alquiler'], ';');
    foreach ($filas as $r) {
        $keyP = $r['categoria'] . '-' . (int)$r['id'];
        $p = $pagosMes[$keyP] ?? null;
        $estado = $p ? 'PAGADO' : 'PENDIENTE';
        fputcsv($out, [
            $r['torre_usuario'] ?? '',
            $r['apto_usuario_num'] ?? '',
            $r['piso_usuario'] ?? '',
            $r['residente_usuario'] ?? '',
            $r['residente_celular'] ?? '',
            $r['categoria'] === 'celda' ? 'Celda' : 'Cuarto útil',
            $r['elemento_nombre'] ?? '',
            $r['nivel_codigo'] ?? '',
            $r['apto_dueno_num'] ?? '',
            $r['fecha_inicio'],
            $r['fecha_fin'] ?? '',
            number_format((float)$r['valor_mensual'], 0, ',', '.'),
            $estado,
            $p ? number_format((float)$p['valor_pagado'], 0, ',', '.') : '',
            $p ? $p['fecha_pago'] : '',
            $p ? $p['referencia'] : '',
            $r['observacion'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

$_pageTitle = 'Reporte de alquileres';
include INCLUDES_PATH . '/header.php';
?>

<style>
.rep-head{background:linear-gradient(135deg,#059669,#0e7490);color:#fff;border-radius:10px;padding:18px 22px;margin-top:12px;}
.rep-head h1{margin:0;font-size:20px;}
.rep-head p{margin:6px 0 0;font-size:13px;opacity:.95;}
.rep-head .periodo{background:rgba(255,255,255,.2);padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;display:inline-block;margin-top:8px;}

.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:12px 0 16px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;}
.kpi-card.total{border-left:5px solid #059669;background:#f0fdf4;}
.kpi-card.pagado{border-left:5px solid #15803d;background:#f0fdf4;}
.kpi-card.pendiente{border-left:5px solid #dc2626;background:#fee2e2;}
.kpi-card.celdas{border-left:5px solid #1e6cff;background:#eff6ff;}
.kpi-card.cuartos{border-left:5px solid #d97706;background:#fef3c7;}
.kpi-card strong{display:block;font-size:22px;color:#1f2937;line-height:1;font-family:monospace;}
.kpi-card.total strong{color:#059669;font-size:26px;}
.kpi-card.pagado strong{color:#15803d;font-size:22px;}
.kpi-card.pendiente strong{color:#dc2626;font-size:22px;}
.kpi-card.celdas strong{color:#1e40af;}
.kpi-card.cuartos strong{color:#92400e;}
.kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;display:block;margin-top:4px;}
.kpi-card small{font-size:11px;color:#6b7280;}

/* Estados de pago por fila */
.estado-pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
.estado-pill.pagado{background:#dcfce7;color:#166534;}
.estado-pill.pendiente{background:#fee2e2;color:#991b1b;}
.estado-pill.vencido{background:#fecaca;color:#7f1d1d;border:1.5px solid #dc2626;}
.btn-marcar{background:#15803d;color:#fff;border:none;padding:5px 10px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;}
.btn-marcar:hover{background:#14532d;}
.btn-editar-pago{background:#eff6ff;color:#1e40af;border:1px solid #93c5fd;padding:4px 8px;border-radius:5px;font-size:11px;cursor:pointer;}
.btn-editar-pago:hover{background:#dbeafe;}
.btn-eliminar-pago{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 8px;border-radius:5px;font-size:11px;cursor:pointer;}
.btn-eliminar-pago:hover{background:#fee2e2;}
.pago-info{font-size:10px;color:#6b7280;margin-top:2px;}

/* Modal registrar pago */
.pago-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.pago-modal.mostrar{display:flex;}
.pago-modal .caja{background:#fff;border-radius:10px;padding:22px;max-width:520px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);}
.pago-modal h3{margin:0 0 6px;color:#059669;font-size:18px;}
.pago-modal .subt{color:#6b7280;font-size:13px;margin:0 0 14px;}
.pago-modal label{display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:600;margin-top:12px;}
.pago-modal input,.pago-modal textarea{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:5px;font-size:14px;box-sizing:border-box;}
.pago-modal .filas{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.pago-modal .info-line{background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:8px 12px;border-radius:6px;font-size:13px;margin-bottom:10px;}
.pago-modal .acciones{display:flex;gap:8px;margin-top:16px;justify-content:flex-end;}

.torres-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.torre-badge{background:#f3f4f6;border:1px solid #d1d5db;padding:6px 12px;border-radius:8px;font-size:12px;}
.torre-badge strong{color:#059669;font-family:monospace;}

.tabla-alq{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.tabla-alq th{background:#f3f4f6;padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;color:#374151;border-bottom:2px solid #e5e7eb;position:sticky;top:0;}
.tabla-alq td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.tabla-alq tr:hover{background:#f9fafb;}
.tabla-alq tr.torre-header td{background:#f8fafc;font-weight:700;color:#059669;border-top:2px solid #e5e7eb;}
.tabla-alq .valor{font-family:monospace;font-weight:700;color:#059669;text-align:right;white-space:nowrap;}
.categoria-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;}
.categoria-badge.celda{background:#dbeafe;color:#1e40af;}
.categoria-badge.cuarto{background:#fef3c7;color:#92400e;}
.pill--warn{background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:8px;font-size:10px;font-weight:600;}

.aviso-modo{background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;padding:10px 14px;border-radius:6px;font-size:13px;margin:8px 0 14px;}
</style>

<div class="rep-head">
    <h1>💰 Reporte de alquileres a cobrar</h1>
    <p>Cobros esperados por mes basados en asignaciones activas de celdas y cuartos útiles.</p>
    <span class="periodo">
        📅 <?= e($meses[$f_mes]) ?> <?= $f_anio ?>
        · <?= $f_periodo === 'actuales' ? 'Activas HOY' : 'Vigentes ese mes' ?>
    </span>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/asignaciones_cuartos') ?>">🔑 Ver cuartos</a>
    <a class="btn" href="<?= url('/asignaciones') ?>">🚗 Ver celdas</a>
    <?php
      $qs = $_GET; $qs['export'] = 'csv'; $qStr = http_build_query($qs);
    ?>
    <a class="btn btn--primary" href="<?= url('/reportes/alquileres') ?>?<?= e($qStr) ?>">📥 Exportar CSV (Excel)</a>
    <button type="button" class="btn" onclick="window.print()">🖨️ Imprimir</button>
</div>

<form method="get" action="<?= url('/reportes/alquileres') ?>" class="filters">
    <select name="mes">
        <?php for ($m=1; $m<=12; $m++): ?>
            <option value="<?= $m ?>" <?= $f_mes === $m ? 'selected' : '' ?>><?= e($meses[$m]) ?></option>
        <?php endfor; ?>
    </select>
    <select name="anio">
        <?php $anioActual = (int)date('Y');
        for ($y = $anioActual - 2; $y <= $anioActual + 1; $y++): ?>
            <option value="<?= $y ?>" <?= $f_anio === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    <select name="tipo">
        <option value="">Celdas + Cuartos</option>
        <option value="celdas"  <?= $f_tipo === 'celdas'  ? 'selected' : '' ?>>🚗 Solo celdas</option>
        <option value="cuartos" <?= $f_tipo === 'cuartos' ? 'selected' : '' ?>>🔑 Solo cuartos</option>
    </select>
    <select name="periodo">
        <option value="actuales"     <?= $f_periodo === 'actuales'     ? 'selected' : '' ?>>Activas HOY</option>
        <option value="vigentes_mes" <?= $f_periodo === 'vigentes_mes' ? 'selected' : '' ?>>Vigentes ese mes</option>
    </select>
    <input type="text" name="torre" placeholder="Torre" value="<?= e($f_torre) ?>" maxlength="20" style="width:80px">
    <input type="text" name="apto"  placeholder="Apto"  value="<?= e($f_apto) ?>"  maxlength="20" style="width:100px">
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/reportes/alquileres') ?>">Limpiar</a>
</form>

<?php if ($f_periodo === 'actuales'): ?>
    <div class="aviso-modo">
        ℹ️ Modo "<strong>Activas HOY</strong>": muestra las asignaciones con <code>activa=1</code> y sin archivar,
        sin importar el mes/año seleccionado. Cambia a "<strong>Vigentes ese mes</strong>" para reportes históricos.
    </div>
<?php else: ?>
    <div class="aviso-modo">
        ℹ️ Modo "<strong>Vigentes en <?= e($meses[$f_mes]) ?> <?= $f_anio ?></strong>":
        incluye asignaciones cuya fecha_inicio ≤ <?= e(date('d/m/Y', strtotime($mesFin))) ?>
        y (sin fecha_fin O fecha_fin ≥ <?= e(date('d/m/Y', strtotime($mesInicio))) ?>),
        incluidas las que se archivaron durante ese mes.
    </div>
<?php endif; ?>

<div class="kpi-row">
    <div class="kpi-card total">
        <strong>$<?= number_format($totalGeneral, 0, ',', '.') ?></strong>
        <span>💰 Total esperado</span>
        <small><?= count($filas) ?> alquiler(es)</small>
    </div>
    <div class="kpi-card pagado">
        <strong>$<?= number_format($totalPagado, 0, ',', '.') ?></strong>
        <span>✅ Cobrado</span>
        <small><?= $cntPagado ?> pago(s) registrado(s)</small>
    </div>
    <div class="kpi-card pendiente">
        <strong>$<?= number_format($totalPendiente, 0, ',', '.') ?></strong>
        <span>⏳ Pendiente</span>
        <small><?= $cntPendiente ?> sin cobrar</small>
    </div>
    <div class="kpi-card celdas">
        <strong>$<?= number_format($totalCeldas, 0, ',', '.') ?></strong>
        <span>🚗 Celdas</span>
        <small><?= $cntCeldas ?> celda(s)</small>
    </div>
    <div class="kpi-card cuartos">
        <strong>$<?= number_format($totalCuartos, 0, ',', '.') ?></strong>
        <span>🔑 Cuartos</span>
        <small><?= $cntCuartos ?> cuarto(s)</small>
    </div>
</div>

<?php if (!empty($totalesPorTorre)): ?>
    <div class="torres-row">
        <?php foreach ($totalesPorTorre as $tr => $info): ?>
            <span class="torre-badge">
                🏢 Torre <?= e($tr ?: '—') ?>:
                <strong>$<?= number_format($info['total'], 0, ',', '.') ?></strong>
                (<?= $info['n'] ?>)
            </span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($filas)): ?>
    <div class="notice notice--info">
        No hay alquileres activos con los filtros aplicados.
        <?php if ($f_periodo === 'actuales'): ?>
            <br><small>Verifica que tengas asignaciones de tipo "alquiler" activas en <a href="<?= url('/asignaciones') ?>">Celdas</a> o <a href="<?= url('/asignaciones_cuartos') ?>">Cuartos</a>.</small>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-wrap">
    <table class="tabla-alq">
        <thead>
            <tr>
                <th>Torre</th>
                <th>Apto Usuario</th>
                <th>Residente</th>
                <th>Categoría</th>
                <th>Elemento</th>
                <th>Apto Dueño</th>
                <th>Desde</th>
                <th>Hasta</th>
                <th class="t-right">Valor mensual</th>
                <th>Estado (<?= e($meses[$f_mes]) ?> <?= $f_anio ?>)</th>
            </tr>
        </thead>
        <tbody>
        <?php $torreAnterior = null; $subtotalTorre = 0; $primerRow = true;
        // Calcular si estamos en un mes ya pasado (para marcar vencidos)
        $mesReporteInicio = strtotime($mesInicio);
        $inicioMesActual  = strtotime(date('Y-m-01'));
        $esMesVencido     = $mesReporteInicio < $inicioMesActual;

        foreach ($filas as $r):
            $torreActual = (string)($r['torre_usuario'] ?? '?');
            // Header de torre cuando cambia
            if ($torreActual !== $torreAnterior):
                if (!$primerRow && $torreAnterior !== null): ?>
                    <tr style="background:#f0fdf4;font-weight:600">
                        <td colspan="8" style="text-align:right;color:#166534">Subtotal Torre <?= e($torreAnterior) ?>:</td>
                        <td class="valor" style="color:#166534">$<?= number_format($subtotalTorre, 0, ',', '.') ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                <tr class="torre-header">
                    <td colspan="10">🏢 <strong>Torre <?= e($torreActual ?: '—') ?></strong></td>
                </tr>
                <?php $torreAnterior = $torreActual; $subtotalTorre = 0; $primerRow = false;
            endif;
            $subtotalTorre += (float)$r['valor_mensual'];
            $keyPago = $r['categoria'] . '-' . (int)$r['id'];
            $pago = $pagosMes[$keyPago] ?? null;
        ?>
            <tr>
                <td><?= e($r['torre_usuario'] ?: '—') ?></td>
                <td>
                    <strong><?= e($r['apto_usuario_num']) ?></strong>
                    <?php if ($r['piso_usuario']): ?><br><small class="t-muted">Piso <?= (int)$r['piso_usuario'] ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['residente_usuario']): ?>
                        <?= e($r['residente_usuario']) ?>
                        <?php if ($r['residente_celular']): ?>
                            <br><small class="t-muted">📱 <?= e($r['residente_celular']) ?></small>
                        <?php endif; ?>
                    <?php else: ?><span class="t-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($r['categoria'] === 'celda'): ?>
                        <span class="categoria-badge celda">🚗 Celda</span>
                    <?php else: ?>
                        <span class="categoria-badge cuarto">🔑 Cuarto</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong style="font-family:monospace"><?= e($r['elemento_nombre']) ?></strong>
                    <?php if ($r['nivel_codigo']): ?>
                        <br><small class="t-muted"><?= e($r['nivel_codigo']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= $r['apto_dueno_num'] ? e($r['apto_dueno_num']) : '<span class="t-muted">—</span>' ?></td>
                <td><?= e(date('d/m/Y', strtotime($r['fecha_inicio']))) ?></td>
                <td>
                    <?php if ($r['fecha_fin']): ?>
                        <?= e(date('d/m/Y', strtotime($r['fecha_fin']))) ?>
                        <?php if (strtotime($r['fecha_fin']) < time()): ?>
                            <br><span class="pill--warn">⚠️ vencido</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="t-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="valor">$<?= number_format((float)$r['valor_mensual'], 0, ',', '.') ?></td>
                <td>
                    <?php if ($pago): ?>
                        <span class="estado-pill pagado">✅ Pagado</span>
                        <div class="pago-info">
                            $<?= number_format((float)$pago['valor_pagado'], 0, ',', '.') ?>
                            · <?= e(date('d/m/Y', strtotime($pago['fecha_pago']))) ?>
                            <?php if ($pago['referencia']): ?><br>Ref: <?= e($pago['referencia']) ?><?php endif; ?>
                        </div>
                        <div style="margin-top:4px;display:flex;gap:4px">
                            <button type="button" class="btn-editar-pago" title="Editar pago"
                                    onclick='abrirPagoModal(<?= json_encode([
                                        "tipo" => $r['categoria'],
                                        "id"   => (int)$r['id'],
                                        "elemento" => $r['elemento_nombre'],
                                        "apto" => $r['apto_usuario_num'],
                                        "valor_esperado" => (float)$r['valor_mensual'],
                                        "pago" => $pago,
                                    ]) ?>)'>✏️</button>
                            <button type="button" class="btn-eliminar-pago" title="Eliminar pago"
                                    onclick="eliminarPago(<?= (int)$pago['id'] ?>)">🗑️</button>
                        </div>
                    <?php else: ?>
                        <?php if ($esMesVencido): ?>
                            <span class="estado-pill vencido">⚠️ Vencido</span>
                        <?php else: ?>
                            <span class="estado-pill pendiente">⏳ Pendiente</span>
                        <?php endif; ?>
                        <div style="margin-top:6px">
                            <button type="button" class="btn-marcar"
                                    onclick='abrirPagoModal(<?= json_encode([
                                        "tipo" => $r['categoria'],
                                        "id"   => (int)$r['id'],
                                        "elemento" => $r['elemento_nombre'],
                                        "apto" => $r['apto_usuario_num'],
                                        "valor_esperado" => (float)$r['valor_mensual'],
                                        "pago" => null,
                                    ]) ?>)'>✓ Marcar cobrado</button>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
            <!-- Último subtotal -->
            <?php if ($torreAnterior !== null): ?>
                <tr style="background:#f0fdf4;font-weight:600">
                    <td colspan="8" style="text-align:right;color:#166534">Subtotal Torre <?= e($torreAnterior) ?>:</td>
                    <td class="valor" style="color:#166534">$<?= number_format($subtotalTorre, 0, ',', '.') ?></td>
                    <td></td>
                </tr>
            <?php endif; ?>
            <!-- Total final -->
            <tr style="background:#059669;color:#fff;font-weight:700;font-size:14px">
                <td colspan="8" style="text-align:right">TOTAL GENERAL:</td>
                <td class="valor" style="color:#fff;font-size:16px">$<?= number_format($totalGeneral, 0, ',', '.') ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>
    </div>

<!-- ═══ Modal Registrar/Editar Pago ═══ -->
<div class="pago-modal" id="pago-modal">
    <div class="caja">
        <h3 id="pago-modal-titulo">✓ Registrar pago</h3>
        <div class="subt" id="pago-modal-subt"></div>

        <form method="POST" action="<?= url('/reportes/registrar_pago') ?>" id="pago-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="pago_id" id="f-pago-id" value="">
            <input type="hidden" name="asignacion_tipo" id="f-tipo" value="">
            <input type="hidden" name="asignacion_id" id="f-asignacion-id" value="">
            <input type="hidden" name="mes" value="<?= $f_mes ?>">
            <input type="hidden" name="anio" value="<?= $f_anio ?>">
            <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '/reportes/alquileres') ?>">

            <div class="info-line">
                📅 Período: <strong><?= e($meses[$f_mes]) ?> <?= $f_anio ?></strong>
                · Valor esperado: <strong>$<span id="f-valor-esp">0</span></strong>
            </div>

            <div class="filas">
                <div>
                    <label>Valor pagado (COP) *</label>
                    <input type="number" name="valor_pagado" id="f-valor-pagado" required min="0" step="0.01">
                </div>
                <div>
                    <label>Fecha del pago *</label>
                    <input type="date" name="fecha_pago" id="f-fecha-pago" required value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <label>Referencia (transferencia, recibo, etc)</label>
            <input type="text" name="referencia" id="f-referencia" maxlength="100" placeholder="Ej: 123456 / Transferencia Bancolombia">

            <label>Observación</label>
            <textarea name="observacion" id="f-observacion" maxlength="500" rows="2" style="resize:vertical"></textarea>

            <div class="acciones">
                <button type="button" class="btn" onclick="cerrarPagoModal()">Cancelar</button>
                <button type="submit" class="btn btn--primary" style="background:#15803d">💾 Guardar pago</button>
            </div>
        </form>
    </div>
</div>

<script>
window.PAGO_ELIM_URL = <?= json_encode(url('/reportes/eliminar_pago')) ?>;
window.PAGO_CSRF = <?= json_encode(csrf_token()) ?>;

function abrirPagoModal(datos) {
    document.getElementById('f-tipo').value = datos.tipo;
    document.getElementById('f-asignacion-id').value = datos.id;
    document.getElementById('f-valor-esp').textContent = Number(datos.valor_esperado).toLocaleString('es-CO');

    if (datos.pago) {
        // Editar pago existente
        document.getElementById('pago-modal-titulo').textContent = '✏️ Editar pago';
        document.getElementById('f-pago-id').value = datos.pago.id;
        document.getElementById('f-valor-pagado').value = datos.pago.valor_pagado;
        document.getElementById('f-fecha-pago').value = datos.pago.fecha_pago;
        document.getElementById('f-referencia').value = datos.pago.referencia || '';
        document.getElementById('f-observacion').value = datos.pago.observacion || '';
    } else {
        // Registrar pago nuevo
        document.getElementById('pago-modal-titulo').textContent = '✓ Registrar pago';
        document.getElementById('f-pago-id').value = '';
        document.getElementById('f-valor-pagado').value = datos.valor_esperado;
        document.getElementById('f-referencia').value = '';
        document.getElementById('f-observacion').value = '';
    }

    document.getElementById('pago-modal-subt').textContent =
        (datos.tipo === 'celda' ? '🚗 Celda ' : '🔑 Cuarto ') + datos.elemento +
        ' — Apto ' + datos.apto;

    document.getElementById('pago-modal').classList.add('mostrar');
}

function cerrarPagoModal() {
    document.getElementById('pago-modal').classList.remove('mostrar');
}

function eliminarPago(pagoId) {
    if (!confirm('¿Eliminar este registro de pago? La asignación volverá al estado "Pendiente".')) return;
    var f = document.createElement('form');
    f.method = 'POST'; f.action = window.PAGO_ELIM_URL;
    f.innerHTML = '<input type="hidden" name="_csrf" value="'+window.PAGO_CSRF+'">' +
                  '<input type="hidden" name="pago_id" value="'+pagoId+'">' +
                  '<input type="hidden" name="return_url" value="'+window.location.pathname+window.location.search+'">';
    document.body.appendChild(f); f.submit();
}

// Cerrar modal con Esc
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') cerrarPagoModal();
});
</script>
<?php endif; ?>

<div style="margin-top:20px;padding:12px 16px;background:#f8fafc;border-radius:6px;font-size:12px;color:#6b7280;line-height:1.6">
    💡 <strong>Uso recomendado:</strong>
    Cada mes selecciona <?= e($meses[$f_mes]) ?> <?= $f_anio ?> y ve marcando los pagos que van llegando con
    el botón <strong>✓ Marcar cobrado</strong>. Al final del mes verás qué está pagado (verde) y qué queda
    pendiente/vencido. Los pagos se guardan por mes+año así que puedes navegar meses anteriores
    sin perder datos. Para ver morosidad acumulada: <a href="<?= url('/reportes/morosidad') ?>">📊 Reporte de morosidad</a>.
</div>

<style media="print">
    .toolbar, .filters, .aviso-modo, .sidebar, header, footer { display:none !important; }
    .rep-head { background:#059669 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .kpi-card { break-inside: avoid; }
    .tabla-alq { font-size:11px; }
    .tabla-alq th { background:#e5e7eb !important; -webkit-print-color-adjust:exact; }
</style>

<?php include INCLUDES_PATH . '/footer.php'; ?>
