<?php
// /home/myzonaco/smartpark.myzona360.com/modules/reportes/morosidad.php
// v1.0 (3Z): Reporte de morosidad — quién debe cuántos meses y cuánto en total.
//   Recorre los últimos N meses y detecta asignaciones alquiler sin pago registrado.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

// Cuántos meses hacia atrás analizar (default 6, hasta 24)
$mesesAtras = max(1, min(24, (int)($_GET['meses'] ?? 6)));
$hoy = date('Y-m-d');
$inicioMesActual = date('Y-m-01');

// Generar lista de (mes, anio) desde N meses atrás hasta el mes actual (excluyendo el actual porque aún no vence)
$periodos = []; // [{mes, anio, primerDia, ultimoDia}]
for ($i = $mesesAtras; $i >= 1; $i--) {
    $ts = strtotime($inicioMesActual . ' -' . $i . ' month');
    $periodos[] = [
        'mes'        => (int)date('n', $ts),
        'anio'       => (int)date('Y', $ts),
        'primerDia'  => date('Y-m-01', $ts),
        'ultimoDia'  => date('Y-m-t', $ts),
        'label'      => date('m/Y', $ts),
    ];
}

// Cargar TODOS los pagos del rango
$primerDiaRango = $periodos[0]['primerDia'] ?? $hoy;
$stPagos = $pdo->prepare("SELECT asignacion_tipo, asignacion_id, mes, anio, valor_pagado, fecha_pago
                           FROM pagos_alquileres
                          WHERE conjunto_id = :c AND CONCAT(anio,'-',LPAD(mes,2,'0'),'-01') >= :fi");
$stPagos->execute([':c' => $conjuntoId, ':fi' => $primerDiaRango]);
$pagosMap = []; // key = tipo-id-anio-mes
foreach ($stPagos->fetchAll() as $p) {
    $key = $p['asignacion_tipo'] . '-' . (int)$p['asignacion_id'] . '-' . (int)$p['anio'] . '-' . (int)$p['mes'];
    $pagosMap[$key] = $p;
}

// Traer todas las asignaciones alquiler que estuvieron vigentes en algún momento del rango
// Vigente en algún mes del rango: fecha_inicio <= último día del rango AND (fecha_fin IS NULL OR fecha_fin >= primer día del rango)
$ultimoDiaRango = end($periodos)['ultimoDia'] ?? $hoy;
reset($periodos);

// ── CELDAS ──
$sqlC = "SELECT ac.id, ac.valor_mensual, ac.fecha_inicio, ac.fecha_fin, ac.archivado_en,
                'celda' AS categoria,
                c.nombre_visible AS elemento_nombre,
                ad.numero_visible AS apto_dueno_num,
                au.numero_visible AS apto_usuario_num,
                tu.numero AS torre_usuario,
                ru.nombre AS residente_usuario,
                ru.celular AS residente_celular
           FROM asignaciones_celdas ac
           JOIN celdas c              ON c.id = ac.celda_id
      LEFT JOIN apartamentos ad       ON ad.id = ac.apto_dueno_id
      LEFT JOIN apartamentos au       ON au.id = ac.apto_usuario_id
      LEFT JOIN torres tu             ON tu.id = au.torre_id
      LEFT JOIN residentes ru ON ru.id = (SELECT r.id FROM residentes r WHERE r.apartamento_id = au.id AND r.activo = 1 ORDER BY (r.tipo='propietario') DESC, r.id LIMIT 1)
          WHERE c.conjunto_id = :cid
            AND ac.tipo = 'alquiler'
            AND ac.fecha_inicio <= :fin
            AND (ac.fecha_fin IS NULL OR ac.fecha_fin >= :ini)";
$stC = $pdo->prepare($sqlC);
$stC->execute([':cid' => $conjuntoId, ':fin' => $ultimoDiaRango, ':ini' => $primerDiaRango]);
$asigs = $stC->fetchAll();

$sqlQ = "SELECT ac.id, ac.valor_mensual, ac.fecha_inicio, ac.fecha_fin, ac.archivado_en,
                'cuarto' AS categoria,
                cu.codigo AS elemento_nombre,
                ad.numero_visible AS apto_dueno_num,
                au.numero_visible AS apto_usuario_num,
                tu.numero AS torre_usuario,
                ru.nombre AS residente_usuario,
                ru.celular AS residente_celular
           FROM asignaciones_cuartos ac
           JOIN cuartos_utiles cu     ON cu.id = ac.cuarto_id
      LEFT JOIN apartamentos ad       ON ad.id = ac.apto_dueno_id
      LEFT JOIN apartamentos au       ON au.id = ac.apto_usuario_id
      LEFT JOIN torres tu             ON tu.id = au.torre_id
      LEFT JOIN residentes ru ON ru.id = (SELECT r.id FROM residentes r WHERE r.apartamento_id = au.id AND r.activo = 1 ORDER BY (r.tipo='propietario') DESC, r.id LIMIT 1)
          WHERE cu.conjunto_id = :cid
            AND ac.tipo = 'alquiler'
            AND ac.fecha_inicio <= :fin
            AND (ac.fecha_fin IS NULL OR ac.fecha_fin >= :ini)";
$stQ = $pdo->prepare($sqlQ);
$stQ->execute([':cid' => $conjuntoId, ':fin' => $ultimoDiaRango, ':ini' => $primerDiaRango]);
foreach ($stQ->fetchAll() as $r) $asigs[] = $r;

// Para cada asignación, calcular meses adeudados
$deudores = []; // por asignación
foreach ($asigs as $a) {
    $mesesDeuda   = [];
    $mesesPagos   = 0;
    $totalDebe    = 0;
    $totalPagado  = 0;
    foreach ($periodos as $p) {
        // Estaba vigente ese mes?
        $vigente = strtotime($a['fecha_inicio']) <= strtotime($p['ultimoDia'])
                && (empty($a['fecha_fin']) || strtotime($a['fecha_fin']) >= strtotime($p['primerDia']))
                && (empty($a['archivado_en']) || strtotime($a['archivado_en']) >= strtotime($p['primerDia']));
        if (!$vigente) continue;

        $key = $a['categoria'] . '-' . (int)$a['id'] . '-' . $p['anio'] . '-' . $p['mes'];
        if (isset($pagosMap[$key])) {
            $mesesPagos++;
            $totalPagado += (float)$pagosMap[$key]['valor_pagado'];
        } else {
            $mesesDeuda[] = $p['label'];
            $totalDebe   += (float)$a['valor_mensual'];
        }
    }
    if (!empty($mesesDeuda)) {
        $deudores[] = [
            'asignacion'   => $a,
            'meses_deuda'  => $mesesDeuda,
            'total_debe'   => $totalDebe,
            'meses_pagos'  => $mesesPagos,
            'total_pagado' => $totalPagado,
        ];
    }
}

// Ordenar por mayor deuda primero
usort($deudores, function($x, $y) { return $y['total_debe'] <=> $x['total_debe']; });

// KPIs
$totalDeuda = array_sum(array_column($deudores, 'total_debe'));
$totalDeudoresRegs = count($deudores);
$totalMesesAtrasados = array_sum(array_map(function($d){ return count($d['meses_deuda']); }, $deudores));

$_pageTitle = 'Reporte de morosidad';
include INCLUDES_PATH . '/header.php';
?>

<style>
.mor-head{background:linear-gradient(135deg,#dc2626,#7c2d12);color:#fff;border-radius:10px;padding:18px 22px;margin-top:12px;}
.mor-head h1{margin:0;font-size:20px;}
.mor-head p{margin:6px 0 0;font-size:13px;opacity:.95;}

.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:12px 0 16px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;text-align:center;}
.kpi-card.rojo{border-left:5px solid #dc2626;background:#fee2e2;}
.kpi-card.rojo strong{color:#991b1b;font-size:26px;font-family:monospace;display:block;line-height:1;}
.kpi-card strong{display:block;font-size:22px;color:#1f2937;line-height:1;font-family:monospace;}
.kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;display:block;margin-top:4px;}

.mor-tabla{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.mor-tabla th{background:#7f1d1d;color:#fff;padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.3px;}
.mor-tabla td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.mor-tabla tr:hover{background:#fef2f2;}
.mor-tabla .valor{font-family:monospace;font-weight:700;color:#991b1b;text-align:right;white-space:nowrap;}
.meses-chips{display:flex;gap:3px;flex-wrap:wrap;}
.mes-chip{background:#fecaca;color:#7f1d1d;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;font-family:monospace;}
.categoria-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;}
.categoria-badge.celda{background:#dbeafe;color:#1e40af;}
.categoria-badge.cuarto{background:#fef3c7;color:#92400e;}
</style>

<div class="mor-head">
    <h1>📊 Reporte de morosidad</h1>
    <p>Alquileres con meses adeudados en los últimos <?= $mesesAtras ?> meses (sin contar el mes actual).</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/reportes/alquileres') ?>">← Volver a alquileres</a>
    <button type="button" class="btn" onclick="window.print()">🖨️ Imprimir</button>
</div>

<form method="get" action="<?= url('/reportes/morosidad') ?>" class="filters">
    <label style="font-size:13px;color:#374151;font-weight:500;display:flex;align-items:center;gap:8px">
        Analizar últimos:
        <select name="meses">
            <?php foreach ([3,6,9,12,18,24] as $m): ?>
                <option value="<?= $m ?>" <?= $mesesAtras === $m ? 'selected' : '' ?>><?= $m ?> meses</option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="btn btn--primary">Actualizar</button>
</form>

<div class="kpi-row">
    <div class="kpi-card rojo">
        <strong>$<?= number_format($totalDeuda, 0, ',', '.') ?></strong>
        <span>💰 Deuda total acumulada</span>
    </div>
    <div class="kpi-card">
        <strong><?= $totalDeudoresRegs ?></strong>
        <span>🧾 Alquileres con deuda</span>
    </div>
    <div class="kpi-card">
        <strong><?= $totalMesesAtrasados ?></strong>
        <span>📅 Meses sin pago (total)</span>
    </div>
</div>

<?php if (empty($deudores)): ?>
    <div class="notice notice--info" style="background:#f0fdf4;border-color:#86efac;color:#166534">
        ✅ ¡No hay alquileres con deudas en los últimos <?= $mesesAtras ?> meses! Todos los pagos están al día.
    </div>
<?php else: ?>
    <div class="table-wrap">
    <table class="mor-tabla">
        <thead>
            <tr>
                <th>Torre / Apto</th>
                <th>Residente</th>
                <th>Categoría</th>
                <th>Elemento</th>
                <th>Valor mensual</th>
                <th>Meses adeudados</th>
                <th>Pagos hechos</th>
                <th class="t-right">Deuda total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($deudores as $d): $a = $d['asignacion']; ?>
            <tr>
                <td>
                    <strong>T<?= e($a['torre_usuario'] ?: '?') ?> · Apto <?= e($a['apto_usuario_num']) ?></strong>
                </td>
                <td>
                    <?php if ($a['residente_usuario']): ?>
                        <?= e($a['residente_usuario']) ?>
                        <?php if ($a['residente_celular']): ?>
                            <br><small class="t-muted">📱 <?= e($a['residente_celular']) ?></small>
                        <?php endif; ?>
                    <?php else: ?><span class="t-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($a['categoria'] === 'celda'): ?>
                        <span class="categoria-badge celda">🚗 Celda</span>
                    <?php else: ?>
                        <span class="categoria-badge cuarto">🔑 Cuarto</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong style="font-family:monospace"><?= e($a['elemento_nombre']) ?></strong>
                    <?php if ($a['apto_dueno_num']): ?>
                        <br><small class="t-muted">dueño Apto <?= e($a['apto_dueno_num']) ?></small>
                    <?php endif; ?>
                </td>
                <td>$<?= number_format((float)$a['valor_mensual'], 0, ',', '.') ?></td>
                <td>
                    <div class="meses-chips">
                        <?php foreach ($d['meses_deuda'] as $m): ?>
                            <span class="mes-chip"><?= e($m) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <small class="t-muted"><?= count($d['meses_deuda']) ?> mes(es)</small>
                </td>
                <td>
                    <?php if ($d['meses_pagos'] > 0): ?>
                        <span style="color:#15803d;font-weight:600"><?= $d['meses_pagos'] ?> mes(es)</span>
                        <br><small class="t-muted">$<?= number_format($d['total_pagado'], 0, ',', '.') ?></small>
                    <?php else: ?>
                        <span class="t-muted">Ninguno</span>
                    <?php endif; ?>
                </td>
                <td class="valor">$<?= number_format($d['total_debe'], 0, ',', '.') ?></td>
            </tr>
        <?php endforeach; ?>
            <tr style="background:#dc2626;color:#fff;font-weight:700;font-size:14px">
                <td colspan="7" style="text-align:right">DEUDA TOTAL:</td>
                <td class="valor" style="color:#fff;font-size:16px">$<?= number_format($totalDeuda, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<div style="margin-top:20px;padding:12px 16px;background:#fef3c7;border-radius:6px;font-size:12px;color:#92400e;line-height:1.6">
    💡 <strong>Cómo se calcula:</strong> por cada asignación de tipo "alquiler", se revisan los últimos <?= $mesesAtras ?>
    meses (sin contar el mes actual). Si estaba vigente en ese mes (según fechas de inicio/fin/archivado) y NO tiene
    un pago registrado, se cuenta como "mes adeudado". Para ponerse al día: ve al
    <a href="<?= url('/reportes/alquileres') ?>">reporte mensual</a>, navega el mes correspondiente y marca como cobrado.
</div>

<style media="print">
    .toolbar, .filters, .sidebar, header, footer { display:none !important; }
    .mor-head { background:#dc2626 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
</style>

<?php include INCLUDES_PATH . '/footer.php'; ?>
