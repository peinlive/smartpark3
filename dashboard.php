<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/dashboard.php
// v1.0 (3AC): Dashboard ejecutivo — vista consolidada del conjunto
//   KPIs en tiempo real + gráficas Chart.js + widgets de actividad reciente.
//   Solo LECTURA — no escribe en BD. Sin schema nuevo.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// ────────────────────────────────────────────────────────────────
// PERIODO — mes actual (para KPIs mensuales)
// ────────────────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$mesInicio   = date('Y-m-01');
$mesFin      = date('Y-m-t');
$mesActual   = (int)date('n');
$anioActual  = (int)date('Y');
$diasMes     = (int)date('t');

$meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
          7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// ────────────────────────────────────────────────────────────────
// KPI 1 — Vehículos residentes activos
// ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT COUNT(*) FROM vehiculos WHERE conjunto_id = :c AND archivado_en IS NULL");
$st->execute([':c' => $conjuntoId]);
$kpiVehiculos = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM vehiculos WHERE conjunto_id = :c AND archivado_en IS NULL AND tipo = 'moto'");
$st->execute([':c' => $conjuntoId]);
$kpiMotos = (int)$st->fetchColumn();
$kpiCarros = $kpiVehiculos - $kpiMotos;

// ────────────────────────────────────────────────────────────────
// KPI 2 — Apartamentos
// ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT COUNT(*) FROM apartamentos WHERE conjunto_id = :c");
$st->execute([':c' => $conjuntoId]);
$kpiAptos = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM apartamentos WHERE conjunto_id = :c AND estado_morosidad = 'moroso'");
$st->execute([':c' => $conjuntoId]);
$kpiAptosMorosos = (int)$st->fetchColumn();

// ────────────────────────────────────────────────────────────────
// KPI 3 — Revistas del mes
// ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT COUNT(*) FROM revistas
                      WHERE conjunto_id = :c
                        AND estado = 'terminada'
                        AND terminado_en BETWEEN :ini AND :fin");
$st->execute([':c' => $conjuntoId, ':ini' => $mesInicio . ' 00:00:00', ':fin' => $mesFin . ' 23:59:59']);
$kpiRevistas = (int)$st->fetchColumn();

// ────────────────────────────────────────────────────────────────
// KPI 4 — Observaciones últimos 30 días
// ────────────────────────────────────────────────────────────────
$hace30 = date('Y-m-d', strtotime('-30 days'));
$st = $pdo->prepare("SELECT COUNT(*) FROM observaciones_vehiculo o
                       JOIN vehiculos v ON v.id = o.vehiculo_id
                      WHERE v.conjunto_id = :c
                        AND o.creado_en >= :d");
$st->execute([':c' => $conjuntoId, ':d' => $hace30 . ' 00:00:00']);
$kpiObs30 = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM observaciones_vehiculo o
                       JOIN vehiculos v ON v.id = o.vehiculo_id
                      WHERE v.conjunto_id = :c
                        AND o.creado_en >= :d
                        AND o.gravedad = 'grave'");
$st->execute([':c' => $conjuntoId, ':d' => $hace30 . ' 00:00:00']);
$kpiObsGraves = (int)$st->fetchColumn();

// ────────────────────────────────────────────────────────────────
// KPI 5-6 — Cobros del mes (alquileres)
// ────────────────────────────────────────────────────────────────
// Suma de valor_mensual de asignaciones activas de alquiler (celdas + cuartos)
$st = $pdo->prepare("SELECT COALESCE(SUM(ac.valor_mensual), 0)
                      FROM asignaciones_celdas ac
                      JOIN celdas c ON c.id = ac.celda_id
                     WHERE c.conjunto_id = :c
                       AND ac.tipo = 'alquiler'
                       AND ac.activa = 1
                       AND ac.archivado_en IS NULL");
$st->execute([':c' => $conjuntoId]);
$totalEsperadoCeldas = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(ac.valor_mensual), 0)
                      FROM asignaciones_cuartos ac
                      JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
                     WHERE cu.conjunto_id = :c
                       AND ac.tipo = 'alquiler'
                       AND ac.activa = 1
                       AND ac.archivado_en IS NULL");
$st->execute([':c' => $conjuntoId]);
$totalEsperadoCuartos = (float)$st->fetchColumn();
$totalEsperadoMes = $totalEsperadoCeldas + $totalEsperadoCuartos;

// Cobrado del mes (según tabla pagos_alquileres, si existe)
$totalCobradoMes = 0;
$totalPagosMes   = 0;
try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(valor_pagado), 0), COUNT(*)
                          FROM pagos_alquileres
                         WHERE conjunto_id = :c AND mes = :m AND anio = :a");
    $st->execute([':c' => $conjuntoId, ':m' => $mesActual, ':a' => $anioActual]);
    $row = $st->fetch(PDO::FETCH_NUM);
    $totalCobradoMes = (float)$row[0];
    $totalPagosMes   = (int)$row[1];
} catch (Exception $ex) {
    // Tabla no existe (aún no ejecutó migración 3Z). Ignorar.
}
$totalPendienteMes = max(0, $totalEsperadoMes - $totalCobradoMes);

// ────────────────────────────────────────────────────────────────
// GRÁFICA 1 — Revistas por día del mes actual
// ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT DAY(terminado_en) AS dia, COUNT(*) AS n
                      FROM revistas
                     WHERE conjunto_id = :c
                       AND estado = 'terminada'
                       AND terminado_en BETWEEN :ini AND :fin
                     GROUP BY DAY(terminado_en)
                     ORDER BY dia");
$st->execute([':c' => $conjuntoId, ':ini' => $mesInicio . ' 00:00:00', ':fin' => $mesFin . ' 23:59:59']);
$revistasPorDia = array_fill(1, $diasMes, 0);
foreach ($st->fetchAll() as $r) $revistasPorDia[(int)$r['dia']] = (int)$r['n'];

// ────────────────────────────────────────────────────────────────
// GRÁFICA 2 — Observaciones por tipo (últimos 30 días)
// ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT o.tipo, COUNT(*) AS n
                      FROM observaciones_vehiculo o
                      JOIN vehiculos v ON v.id = o.vehiculo_id
                     WHERE v.conjunto_id = :c
                       AND o.creado_en >= :d
                     GROUP BY o.tipo
                     ORDER BY n DESC");
$st->execute([':c' => $conjuntoId, ':d' => $hace30 . ' 00:00:00']);
$obsPorTipo = $st->fetchAll();

$labelsTipo = [
    'mal_parqueo'  => 'Mal parqueo',
    'advertencia'  => 'Advertencia',
    'reincidencia' => 'Reincidencia',
    'queja'        => 'Queja',
    'otro'         => 'Otro',
];
$coloresTipo = [
    'mal_parqueo'  => '#dc2626',
    'advertencia'  => '#d97706',
    'reincidencia' => '#7c2d12',
    'queja'        => '#6d28d9',
    'otro'         => '#4b5563',
];

// ────────────────────────────────────────────────────────────────
// TOP 5 morosos de alquileres
// ────────────────────────────────────────────────────────────────
$topMorosos = [];
try {
    // Últimos 6 meses excluyendo el actual
    $primerDiaAnalisis = date('Y-m-01', strtotime('-6 months'));

    // Consulta: por cada asignación de alquiler vigente en el período, cuántos meses sin pago tuvo
    $sqlMor = "SELECT alq.tipo AS categoria, alq.id AS asignacion_id, alq.valor_mensual,
                      alq.fecha_inicio, alq.fecha_fin, alq.archivado_en,
                      alq.torre, alq.apto, alq.residente
                 FROM (
                     SELECT 'celda' AS tipo, ac.id, ac.valor_mensual, ac.fecha_inicio, ac.fecha_fin, ac.archivado_en,
                            t.numero AS torre, a.numero_visible AS apto,
                            (SELECT r.nombre FROM residentes r WHERE r.apartamento_id = a.id AND r.activo=1
                              ORDER BY (r.tipo='propietario') DESC, r.id LIMIT 1) AS residente
                       FROM asignaciones_celdas ac
                       JOIN celdas c ON c.id = ac.celda_id
                  LEFT JOIN apartamentos a ON a.id = ac.apto_usuario_id
                  LEFT JOIN torres t ON t.id = a.torre_id
                      WHERE c.conjunto_id = :c1
                        AND ac.tipo = 'alquiler'
                        AND ac.fecha_inicio <= :fin1
                     UNION ALL
                     SELECT 'cuarto', ac.id, ac.valor_mensual, ac.fecha_inicio, ac.fecha_fin, ac.archivado_en,
                            t.numero, a.numero_visible,
                            (SELECT r.nombre FROM residentes r WHERE r.apartamento_id = a.id AND r.activo=1
                              ORDER BY (r.tipo='propietario') DESC, r.id LIMIT 1)
                       FROM asignaciones_cuartos ac
                       JOIN cuartos_utiles cu ON cu.id = ac.cuarto_id
                  LEFT JOIN apartamentos a ON a.id = ac.apto_usuario_id
                  LEFT JOIN torres t ON t.id = a.torre_id
                      WHERE cu.conjunto_id = :c2
                        AND ac.tipo = 'alquiler'
                        AND ac.fecha_inicio <= :fin2
                 ) alq";
    $stM = $pdo->prepare($sqlMor);
    $stM->execute([
        ':c1' => $conjuntoId, ':fin1' => $mesFin,
        ':c2' => $conjuntoId, ':fin2' => $mesFin,
    ]);
    $asigsAlq = $stM->fetchAll();

    // Cargar pagos del rango
    $stPag = $pdo->prepare("SELECT asignacion_tipo, asignacion_id, mes, anio FROM pagos_alquileres
                             WHERE conjunto_id = :c
                               AND CONCAT(anio,'-',LPAD(mes,2,'0'),'-01') >= :fi");
    $stPag->execute([':c' => $conjuntoId, ':fi' => $primerDiaAnalisis]);
    $pagosMap = [];
    foreach ($stPag->fetchAll() as $p) {
        $k = $p['asignacion_tipo'] . '-' . (int)$p['asignacion_id'] . '-' . (int)$p['anio'] . '-' . (int)$p['mes'];
        $pagosMap[$k] = true;
    }

    // Generar períodos últimos 6 meses SIN el actual
    $periodos = [];
    for ($i = 6; $i >= 1; $i--) {
        $ts = strtotime($mesInicio . ' -' . $i . ' month');
        $periodos[] = [
            'mes' => (int)date('n', $ts), 'anio' => (int)date('Y', $ts),
            'ini' => date('Y-m-01', $ts), 'fin' => date('Y-m-t', $ts),
        ];
    }

    // Calcular deuda por asignación
    $deudores = [];
    foreach ($asigsAlq as $a) {
        $totalDebe   = 0;
        $mesesDeuda  = 0;
        foreach ($periodos as $p) {
            $vigente = strtotime($a['fecha_inicio']) <= strtotime($p['fin'])
                    && (empty($a['fecha_fin']) || strtotime($a['fecha_fin']) >= strtotime($p['ini']))
                    && (empty($a['archivado_en']) || strtotime($a['archivado_en']) >= strtotime($p['ini']));
            if (!$vigente) continue;
            $k = $a['categoria'] . '-' . (int)$a['asignacion_id'] . '-' . $p['anio'] . '-' . $p['mes'];
            if (!isset($pagosMap[$k])) {
                $totalDebe  += (float)$a['valor_mensual'];
                $mesesDeuda++;
            }
        }
        if ($totalDebe > 0) {
            $deudores[] = [
                'apto'       => $a['apto'] ?: '—',
                'torre'      => $a['torre'] ?: '—',
                'residente'  => $a['residente'] ?: '—',
                'categoria'  => $a['categoria'],
                'total_debe' => $totalDebe,
                'meses'      => $mesesDeuda,
            ];
        }
    }
    usort($deudores, function($x, $y) { return $y['total_debe'] <=> $x['total_debe']; });
    $topMorosos = array_slice($deudores, 0, 5);
} catch (Exception $ex) {
    // Tabla pagos no existe
}

// ────────────────────────────────────────────────────────────────
// Widgets de actividad reciente
// ────────────────────────────────────────────────────────────────
// Últimas 5 revistas terminadas
$st = $pdo->prepare("SELECT r.id, r.nivel, r.celdas_ocupadas, r.celdas_vacias, r.terminado_en,
                            up.nombre_completo AS usuario
                       FROM revistas r
                  LEFT JOIN usuarios up ON up.id = r.usuario_id
                      WHERE r.conjunto_id = :c AND r.estado = 'terminada'
                   ORDER BY r.terminado_en DESC LIMIT 5");
$st->execute([':c' => $conjuntoId]);
$ultimasRevistas = $st->fetchAll();

// Últimas 5 observaciones
$st = $pdo->prepare("SELECT o.id, o.tipo, o.gravedad, o.descripcion, o.creado_en,
                            v.placa, a.numero_visible AS apto
                       FROM observaciones_vehiculo o
                       JOIN vehiculos v ON v.id = o.vehiculo_id
                  LEFT JOIN apartamentos a ON a.id = v.apartamento_id
                      WHERE v.conjunto_id = :c
                   ORDER BY o.creado_en DESC LIMIT 5");
$st->execute([':c' => $conjuntoId]);
$ultimasObs = $st->fetchAll();

// Últimos 5 pagos (si tabla existe)
$ultimosPagos = [];
try {
    $st = $pdo->prepare("SELECT p.id, p.asignacion_tipo, p.valor_pagado, p.fecha_pago, p.mes, p.anio,
                                p.referencia
                          FROM pagos_alquileres p
                         WHERE p.conjunto_id = :c
                      ORDER BY p.creado_en DESC LIMIT 5");
    $st->execute([':c' => $conjuntoId]);
    $ultimosPagos = $st->fetchAll();
} catch (Exception $ex) { /* sin tabla */ }

$_pageTitle = 'Dashboard';
include INCLUDES_PATH . '/header.php';
?>

<style>
.dash-head{background:linear-gradient(135deg,#1e40af,#0e7490);color:#fff;border-radius:10px;padding:18px 22px;margin-top:12px;}
.dash-head h1{margin:0;font-size:22px;}
.dash-head p{margin:6px 0 0;font-size:13px;opacity:.95;}

.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;margin:14px 0;}
.kpi-box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.03);position:relative;overflow:hidden;}
.kpi-box strong{display:block;font-size:28px;color:#1f2937;line-height:1;font-family:monospace;margin-bottom:6px;}
.kpi-box .titulo{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.kpi-box .detalle{font-size:11px;color:#6b7280;margin-top:4px;}
.kpi-box::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;}
.kpi-box.k-veh::before{background:#1e40af;} .kpi-box.k-veh strong{color:#1e40af;}
.kpi-box.k-apto::before{background:#0e7490;} .kpi-box.k-apto strong{color:#0e7490;}
.kpi-box.k-rev::before{background:#7c3aed;} .kpi-box.k-rev strong{color:#7c3aed;}
.kpi-box.k-obs::before{background:#dc2626;} .kpi-box.k-obs strong{color:#dc2626;}
.kpi-box.k-cob::before{background:#15803d;} .kpi-box.k-cob strong{color:#15803d;}
.kpi-box.k-pen::before{background:#d97706;} .kpi-box.k-pen strong{color:#d97706;}
.kpi-box .emoji{position:absolute;right:12px;top:12px;font-size:26px;opacity:.15;}

.dash-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
@media(max-width:900px){.dash-row{grid-template-columns:1fr;}}
.dash-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.dash-card h3{margin:0 0 12px;font-size:15px;color:#111827;padding-bottom:8px;border-bottom:2px solid #f3f4f6;}
.dash-card h3 .aside{float:right;font-size:11px;color:#6b7280;font-weight:500;text-transform:none;letter-spacing:0;}
.dash-card .chart-holder{position:relative;height:220px;}

.item-list{list-style:none;padding:0;margin:0;}
.item-list li{padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px;display:flex;justify-content:space-between;gap:8px;align-items:start;}
.item-list li:last-child{border-bottom:none;}
.item-list .meta{font-size:11px;color:#6b7280;white-space:nowrap;}
.pill-mini{display:inline-block;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;}
.pill-mini.grave{background:#fee2e2;color:#991b1b;}
.pill-mini.media{background:#fef3c7;color:#92400e;}
.pill-mini.leve{background:#dcfce7;color:#166534;}
.pill-mini.tipo{background:#eff6ff;color:#1e40af;}

.top-morosos{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;}
.top-morosos h3{margin:0 0 10px;font-size:15px;color:#991b1b;}
.top-morosos table{width:100%;border-collapse:collapse;font-size:13px;}
.top-morosos th{background:#fee2e2;color:#7f1d1d;padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;}
.top-morosos td{padding:6px 8px;border-bottom:1px solid #fef2f2;}
.top-morosos .deuda{color:#991b1b;font-family:monospace;font-weight:700;text-align:right;}

.sin-datos{text-align:center;padding:30px 10px;color:#9ca3af;font-size:12px;}
.aviso-tabla{background:#fef3c7;border:1px solid #fbbf24;color:#92400e;padding:10px 14px;border-radius:6px;font-size:12px;}

.quick-links{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0;}
.quick-links .qlink{background:#f8fafc;border:1px solid #e5e7eb;color:#374151;padding:8px 14px;border-radius:8px;font-size:12px;text-decoration:none;font-weight:500;}
.quick-links .qlink:hover{background:#eff6ff;border-color:#93c5fd;}
</style>

<div class="dash-head">
    <h1>📊 Dashboard ejecutivo</h1>
    <p>Vista consolidada del conjunto · <?= e($meses[$mesActual]) ?> <?= $anioActual ?> · Actualizado <?= date('d/m/Y H:i') ?></p>
</div>

<div class="quick-links">
    <a class="qlink" href="<?= url('/reportes/alquileres') ?>">💰 Alquileres</a>
    <a class="qlink" href="<?= url('/reportes/morosidad') ?>">📊 Morosidad</a>
    <a class="qlink" href="<?= url('/reportes/planilla_parqueo') ?>">📋 Planilla parqueo</a>
    <a class="qlink" href="<?= url('/revistas') ?>">🚗 Revistas</a>
    <a class="qlink" href="<?= url('/observaciones') ?>">⚠️ Observaciones</a>
    <a class="qlink" href="<?= url('/consultas') ?>">🔍 Consulta rápida</a>
</div>

<!-- ═════════ KPIs ═════════ -->
<div class="kpi-grid">
    <div class="kpi-box k-veh">
        <span class="emoji">🚗</span>
        <strong><?= number_format($kpiVehiculos, 0, ',', '.') ?></strong>
        <div class="titulo">Vehículos residentes</div>
        <div class="detalle">🚗 <?= $kpiCarros ?> carros · 🏍️ <?= $kpiMotos ?> motos</div>
    </div>
    <div class="kpi-box k-apto">
        <span class="emoji">🏠</span>
        <strong><?= number_format($kpiAptos, 0, ',', '.') ?></strong>
        <div class="titulo">Apartamentos</div>
        <div class="detalle">
            <?php if ($kpiAptosMorosos > 0): ?>
                <span style="color:#dc2626">⚠️ <?= $kpiAptosMorosos ?> morosos</span>
            <?php else: ?>
                <span style="color:#15803d">✓ Todos al día</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="kpi-box k-rev">
        <span class="emoji">📋</span>
        <strong><?= number_format($kpiRevistas, 0, ',', '.') ?></strong>
        <div class="titulo">Revistas del mes</div>
        <div class="detalle">Terminadas en <?= e($meses[$mesActual]) ?></div>
    </div>
    <div class="kpi-box k-obs">
        <span class="emoji">⚠️</span>
        <strong><?= number_format($kpiObs30, 0, ',', '.') ?></strong>
        <div class="titulo">Observaciones (30 días)</div>
        <div class="detalle">
            <?php if ($kpiObsGraves > 0): ?>
                <span style="color:#dc2626">🔴 <?= $kpiObsGraves ?> graves</span>
            <?php else: ?>
                Sin observaciones graves
            <?php endif; ?>
        </div>
    </div>
    <div class="kpi-box k-cob">
        <span class="emoji">💰</span>
        <strong>$<?= number_format($totalCobradoMes, 0, ',', '.') ?></strong>
        <div class="titulo">Cobrado este mes</div>
        <div class="detalle"><?= $totalPagosMes ?> pago(s) · de $<?= number_format($totalEsperadoMes, 0, ',', '.') ?> esperados</div>
    </div>
    <div class="kpi-box k-pen">
        <span class="emoji">⏳</span>
        <strong>$<?= number_format($totalPendienteMes, 0, ',', '.') ?></strong>
        <div class="titulo">Pendiente este mes</div>
        <div class="detalle">
            <?php $pct = $totalEsperadoMes > 0 ? round($totalCobradoMes / $totalEsperadoMes * 100) : 0; ?>
            <?= $pct ?>% cobrado
        </div>
    </div>
</div>

<!-- ═════════ Gráficas ═════════ -->
<div class="dash-row">
    <div class="dash-card">
        <h3>📈 Revistas por día
            <span class="aside"><?= e($meses[$mesActual]) ?> <?= $anioActual ?></span>
        </h3>
        <div class="chart-holder">
            <?php if (array_sum($revistasPorDia) > 0): ?>
                <canvas id="chart-revistas"></canvas>
            <?php else: ?>
                <div class="sin-datos">Aún no hay revistas terminadas en este mes.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-card">
        <h3>📊 Observaciones por tipo
            <span class="aside">últimos 30 días</span>
        </h3>
        <div class="chart-holder">
            <?php if (!empty($obsPorTipo)): ?>
                <canvas id="chart-obs"></canvas>
            <?php else: ?>
                <div class="sin-datos">Sin observaciones en los últimos 30 días. 🎉</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═════════ Top morosos + Últimos pagos ═════════ -->
<div class="dash-row">
    <div class="top-morosos">
        <h3>🔴 Top 5 morosos de alquileres <span class="aside" style="font-weight:500;font-size:11px;color:#6b7280">últimos 6 meses</span></h3>
        <?php if (empty($topMorosos)): ?>
            <div class="sin-datos" style="color:#15803d">✅ Sin morosos en los últimos 6 meses</div>
            <?php if ($totalPagosMes === 0 && $totalCobradoMes === 0): ?>
                <div class="aviso-tabla" style="margin-top:10px">
                    ℹ️ Si aún no has ejecutado la migración de pagos (entrega 3Z),
                    el sistema no puede saber quién debe. Ejecuta el SQL de
                    <code>sql/migracion_3z_pagos_alquileres.sql</code>.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Torre / Apto</th>
                        <th>Residente</th>
                        <th>Tipo</th>
                        <th>Meses</th>
                        <th class="t-right">Deuda</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topMorosos as $m): ?>
                        <tr>
                            <td><strong>T<?= e($m['torre']) ?> · <?= e($m['apto']) ?></strong></td>
                            <td><?= e(mb_substr($m['residente'], 0, 22)) ?></td>
                            <td>
                                <?= $m['categoria'] === 'celda'
                                    ? '<span class="pill-mini tipo">🚗</span>'
                                    : '<span class="pill-mini tipo">🔑</span>' ?>
                            </td>
                            <td><?= $m['meses'] ?></td>
                            <td class="deuda">$<?= number_format($m['total_debe'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:8px;text-align:right"><a href="<?= url('/reportes/morosidad') ?>" style="font-size:11px;color:#dc2626">Ver reporte completo →</a></div>
        <?php endif; ?>
    </div>

    <div class="dash-card">
        <h3>💵 Últimos pagos recibidos</h3>
        <?php if (empty($ultimosPagos)): ?>
            <div class="sin-datos">Aún no hay pagos registrados.</div>
        <?php else: ?>
            <ul class="item-list">
                <?php foreach ($ultimosPagos as $p): ?>
                    <li>
                        <div>
                            <?= $p['asignacion_tipo'] === 'celda' ? '🚗' : '🔑' ?>
                            <strong>$<?= number_format((float)$p['valor_pagado'], 0, ',', '.') ?></strong>
                            <small class="t-muted">(<?= str_pad($p['mes'], 2, '0', STR_PAD_LEFT) ?>/<?= $p['anio'] ?>)</small>
                            <?php if ($p['referencia']): ?>
                                <br><small class="t-muted">Ref: <?= e(mb_substr($p['referencia'], 0, 30)) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="meta"><?= e(date('d/m/Y', strtotime($p['fecha_pago']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top:8px;text-align:right"><a href="<?= url('/reportes/alquileres') ?>" style="font-size:11px;color:#15803d">Ir a alquileres →</a></div>
        <?php endif; ?>
    </div>
</div>

<!-- ═════════ Actividad reciente ═════════ -->
<div class="dash-row">
    <div class="dash-card">
        <h3>📋 Últimas revistas terminadas</h3>
        <?php if (empty($ultimasRevistas)): ?>
            <div class="sin-datos">Aún no hay revistas terminadas.</div>
        <?php else: ?>
            <ul class="item-list">
                <?php foreach ($ultimasRevistas as $r): ?>
                    <li>
                        <div>
                            <strong>Nivel <?= e($r['nivel']) ?></strong>
                            <span class="t-muted">— <?= (int)$r['celdas_ocupadas'] ?> ocupadas · <?= (int)$r['celdas_vacias'] ?> vacías</span>
                            <?php if ($r['usuario']): ?><br><small class="t-muted">👤 <?= e($r['usuario']) ?></small><?php endif; ?>
                        </div>
                        <span class="meta"><?= e(date('d/m H:i', strtotime($r['terminado_en']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top:8px;text-align:right"><a href="<?= url('/revistas') ?>" style="font-size:11px;color:#7c3aed">Ver todas →</a></div>
        <?php endif; ?>
    </div>

    <div class="dash-card">
        <h3>⚠️ Últimas observaciones</h3>
        <?php if (empty($ultimasObs)): ?>
            <div class="sin-datos">Sin observaciones registradas.</div>
        <?php else: ?>
            <ul class="item-list">
                <?php foreach ($ultimasObs as $o): ?>
                    <li>
                        <div>
                            <span class="pill-mini <?= e($o['gravedad']) ?>"><?= e($labelsTipo[$o['tipo']] ?? $o['tipo']) ?></span>
                            <strong style="font-family:monospace"><?= e($o['placa']) ?></strong>
                            <?php if ($o['apto']): ?><small class="t-muted">· Apto <?= e($o['apto']) ?></small><?php endif; ?>
                            <br><small style="color:#4b5563"><?= e(mb_substr($o['descripcion'], 0, 80)) ?><?= mb_strlen($o['descripcion']) > 80 ? '…' : '' ?></small>
                        </div>
                        <span class="meta"><?= e(date('d/m H:i', strtotime($o['creado_en']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top:8px;text-align:right"><a href="<?= url('/observaciones') ?>" style="font-size:11px;color:#dc2626">Ver todas →</a></div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    // Gráfica 1 — Revistas por día
    var canvasRev = document.getElementById('chart-revistas');
    if (canvasRev) {
        new Chart(canvasRev, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($revistasPorDia)) ?>,
                datasets: [{
                    label: 'Revistas',
                    data: <?= json_encode(array_values($revistasPorDia)) ?>,
                    backgroundColor: '#7c3aed',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Gráfica 2 — Observaciones por tipo (doughnut)
    var canvasObs = document.getElementById('chart-obs');
    if (canvasObs) {
        var obsLabels = <?= json_encode(array_map(function($o) use ($labelsTipo){ return $labelsTipo[$o['tipo']] ?? $o['tipo']; }, $obsPorTipo)) ?>;
        var obsData   = <?= json_encode(array_map(function($o){ return (int)$o['n']; }, $obsPorTipo)) ?>;
        var obsColors = <?= json_encode(array_map(function($o) use ($coloresTipo){ return $coloresTipo[$o['tipo']] ?? '#6b7280'; }, $obsPorTipo)) ?>;
        new Chart(canvasObs, {
            type: 'doughnut',
            data: {
                labels: obsLabels,
                datasets: [{ data: obsData, backgroundColor: obsColors, borderWidth: 2, borderColor: '#fff' }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { font: { size: 11 } } } }
            }
        });
    }
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
