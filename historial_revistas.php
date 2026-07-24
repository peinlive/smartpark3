<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/exportar_historial.php
// v1.0 (3AI): Exportar historial completo de un vehículo como HTML imprimible
//   (compatible con "Guardar como PDF" del navegador).
//   Parámetros GET:
//     id           → vehículo (requerido)
//     tipo         → novedades | revistas | ambas (default: ambas)
//     fotos        → 1 para incluir fotos (default: 1)
//     desde/hasta  → filtro de fechas (opcional, formato YYYY-MM-DD)
//     imprimir     → 1 muestra el reporte listo para imprimir; sin este param
//                    muestra el formulario de opciones

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'ID de vehículo inválido'); redirect('/consultas'); }

// Cargar vehículo con contexto
$st = $pdo->prepare("SELECT v.*, a.numero_visible AS apto, a.piso, t.numero AS torre, t.nombre AS torre_nombre,
                            r.nombre AS residente_nombre, r.celular AS residente_celular, r.tipo AS residente_tipo
                       FROM vehiculos v
                       JOIN apartamentos a ON a.id = v.apartamento_id
                       JOIN torres t       ON t.id = a.torre_id
                  LEFT JOIN residentes r   ON r.id = v.residente_id
                      WHERE v.id = :id AND v.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$veh = $st->fetch();
if (!$veh) { flash_set('error', 'Vehículo no encontrado'); redirect('/consultas'); }

$imprimir = ($_GET['imprimir'] ?? '') === '1';

if (!$imprimir) {
    // ═══════════════════════════════════════════════════════════════
    // Modo 1: Formulario de opciones
    // ═══════════════════════════════════════════════════════════════
    $_pageTitle = 'Exportar historial: ' . $veh['placa'];
    include INCLUDES_PATH . '/header.php';
    ?>
    <style>
    .exp-head{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;border-radius:10px;padding:18px 22px;margin-top:12px;}
    .exp-head h1{margin:0;font-size:20px;}
    .exp-head p{margin:6px 0 0;font-size:13px;opacity:.95;}
    .exp-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:22px 26px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.03);}
    .exp-card h3{margin:0 0 10px;font-size:15px;color:#111827;padding-bottom:6px;border-bottom:2px solid #f3f4f6;}
    .exp-radio-group{display:flex;flex-direction:column;gap:6px;margin-top:8px;}
    .exp-radio{display:flex;align-items:center;gap:8px;padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;transition:all .1s;}
    .exp-radio:hover{background:#f1f5f9;border-color:#93c5fd;}
    .exp-radio input{margin:0;transform:scale(1.2);}
    .exp-radio.selected{background:#eff6ff;border-color:#3b82f6;}
    </style>

    <div class="exp-head">
        <h1>📄 Exportar historial: <?= e($veh['placa']) ?></h1>
        <p><?= $veh['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?> · Apto <?= e($veh['apto']) ?> (Torre <?= (int)$veh['torre'] ?>)
           <?= $veh['residente_nombre'] ? ' · ' . e($veh['residente_nombre']) : '' ?></p>
    </div>

    <div class="toolbar">
        <a class="btn" href="<?= url('/consultas') ?>">← Volver</a>
    </div>

    <form method="get" action="<?= url('/vehiculos/exportar_historial') ?>" target="_blank">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="imprimir" value="1">

        <div class="exp-card">
            <h3>📋 ¿Qué información incluir?</h3>
            <div class="exp-radio-group">
                <label class="exp-radio selected">
                    <input type="radio" name="tipo" value="ambas" checked>
                    <div>
                        <strong>📋 Historial completo</strong><br>
                        <small style="color:#6b7280">Novedades/observaciones + apariciones en revistas de parqueo</small>
                    </div>
                </label>
                <label class="exp-radio">
                    <input type="radio" name="tipo" value="novedades">
                    <div>
                        <strong>⚠️ Solo novedades</strong><br>
                        <small style="color:#6b7280">Observaciones registradas: mal parqueo, advertencias, quejas, etc.</small>
                    </div>
                </label>
                <label class="exp-radio">
                    <input type="radio" name="tipo" value="revistas">
                    <div>
                        <strong>📊 Solo registros de parqueo</strong><br>
                        <small style="color:#6b7280">Historial de apariciones en las revistas de parqueadero</small>
                    </div>
                </label>
            </div>
        </div>

        <div class="exp-card">
            <h3>📸 Fotos</h3>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:10px;background:#f8fafc;border-radius:6px;cursor:pointer">
                <input type="checkbox" name="fotos" value="1" checked style="transform:scale(1.3)">
                <span>Incluir fotos de evidencia (foto principal + evidencias adicionales de cada novedad)</span>
            </label>
            <small style="color:#6b7280;display:block;margin-top:6px">
                Sin esta opción el reporte queda más liviano y rápido de imprimir.
            </small>
        </div>

        <div class="exp-card">
            <h3>📅 Rango de fechas (opcional)</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <label style="display:block">
                    <span style="font-size:12px;font-weight:600;color:#374151">Desde</span>
                    <input type="date" name="desde" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:5px">
                </label>
                <label style="display:block">
                    <span style="font-size:12px;font-weight:600;color:#374151">Hasta</span>
                    <input type="date" name="hasta" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:5px">
                </label>
            </div>
            <small style="color:#6b7280;display:block;margin-top:6px">
                Si dejas ambos vacíos, se muestra el historial completo.
            </small>
        </div>

        <div style="text-align:right;margin:20px 0">
            <a class="btn" href="<?= url('/consultas') ?>">Cancelar</a>
            <button type="submit" class="btn btn--primary" style="background:#1e40af">📄 Generar reporte</button>
        </div>
    </form>

    <script>
    // Marcar visualmente el radio seleccionado
    document.querySelectorAll('input[type=radio][name=tipo]').forEach(function(r){
        r.addEventListener('change', function(){
            document.querySelectorAll('.exp-radio').forEach(function(l){ l.classList.remove('selected'); });
            r.closest('.exp-radio').classList.add('selected');
        });
    });
    </script>

    <?php
    include INCLUDES_PATH . '/footer.php';
    exit;
}

// ═══════════════════════════════════════════════════════════════
// Modo 2: Reporte imprimible
// ═══════════════════════════════════════════════════════════════
$tipo  = in_array($_GET['tipo'] ?? 'ambas', ['novedades','revistas','ambas'], true) ? $_GET['tipo'] : 'ambas';
$conFotos = ($_GET['fotos'] ?? '') === '1';
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';

$labelsTipo = [
    'mal_parqueo'  => 'Mal parqueo',
    'advertencia'  => 'Advertencia',
    'reincidencia' => 'Reincidencia',
    'queja'        => 'Queja',
    'otro'         => 'Otro',
];

// ── Novedades ──
$novedades = [];
if ($tipo === 'ambas' || $tipo === 'novedades') {
    $whereF = ["o.vehiculo_id = :v"];
    $paramsF = [':v' => $id];
    if ($desde) { $whereF[] = "o.creado_en >= :fd"; $paramsF[':fd'] = $desde . ' 00:00:00'; }
    if ($hasta) { $whereF[] = "o.creado_en <= :fh"; $paramsF[':fh'] = $hasta . ' 23:59:59'; }
    $sqlN = "SELECT o.*, up.nombre_completo AS registrado_por
               FROM observaciones_vehiculo o
          LEFT JOIN usuarios up ON up.id = o.usuario_registra
              WHERE " . implode(' AND ', $whereF) . "
           ORDER BY o.creado_en DESC";
    $stN = $pdo->prepare($sqlN);
    $stN->execute($paramsF);
    $novedades = $stN->fetchAll();

    // Cargar evidencias adicionales
    if ($conFotos && !empty($novedades)) {
        try {
            $ids = array_column($novedades, 'id');
            $ph = []; $pE = [];
            foreach ($ids as $i => $oid) { $k = ':o' . $i; $ph[] = $k; $pE[$k] = $oid; }
            $sqlE = "SELECT observacion_id, tipo, archivo_url FROM observaciones_evidencias
                      WHERE observacion_id IN (" . implode(',', $ph) . ") ORDER BY creado_en ASC";
            $stE = $pdo->prepare($sqlE);
            $stE->execute($pE);
            $porObs = [];
            foreach ($stE->fetchAll() as $e) {
                $oid = (int)$e['observacion_id'];
                if (!isset($porObs[$oid])) $porObs[$oid] = [];
                $porObs[$oid][] = $e;
            }
            foreach ($novedades as &$n) { $n['evidencias'] = $porObs[(int)$n['id']] ?? []; }
            unset($n);
        } catch (Exception $ex) { /* tabla no existe */ }
    }
}

// ── Revistas / lecturas de parqueo ──
// v3AK.1: usar lecturas_placas donde revista_id IS NOT NULL o fuente='revista'
$revistas = [];
if ($tipo === 'ambas' || $tipo === 'revistas') {
    $whereR = ["lp.vehiculo_id = :v", "lp.conjunto_id = :c",
               "(lp.revista_id IS NOT NULL OR lp.fuente = 'revista')"];
    $paramsR = [':v' => $id, ':c' => $conjuntoId];
    if ($desde) { $whereR[] = "lp.creado_en >= :fd"; $paramsR[':fd'] = $desde . ' 00:00:00'; }
    if ($hasta) { $whereR[] = "lp.creado_en <= :fh"; $paramsR[':fh'] = $hasta . ' 23:59:59'; }
    try {
        $sqlR = "SELECT lp.id, lp.creado_en, lp.nivel, lp.celda, lp.placa_detectada,
                        lp.foto_path, lp.observaciones, lp.celda_vacia, lp.placa_manual,
                        lp.tipo_resultado, lp.confidence, lp.revista_id,
                        r.estado AS estado_revista, r.iniciado_en AS revista_iniciada,
                        r.terminado_en AS revista_terminada,
                        up.nombre_completo AS usuario_nombre
                   FROM lecturas_placas lp
              LEFT JOIN revistas r     ON r.id = lp.revista_id
              LEFT JOIN usuarios up    ON up.id = lp.usuario_id
                  WHERE " . implode(' AND ', $whereR) . "
               ORDER BY lp.creado_en DESC LIMIT 200";
        $stR = $pdo->prepare($sqlR);
        $stR->execute($paramsR);
        $revistas = $stR->fetchAll();
    } catch (Exception $ex) {
        // Log del error si está activado el debug
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $revistas = [];
            $errorRevistas = $ex->getMessage();
        }
    }
}

$fechaExport = date('d/m/Y H:i:s');
$fotoUrl = function($url) {
    if (!$url) return null;
    return strpos($url, 'http') === 0 ? $url : '/uploads/' . ltrim($url, '/');
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Historial <?= e($veh['placa']) ?> — SmartPark</title>
<style>
body{font-family:'Segoe UI',Roboto,sans-serif;margin:0;padding:20px;color:#1f2937;background:#f9fafb;}
.exp-toolbar{background:#1e40af;color:#fff;padding:12px 20px;border-radius:8px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
.exp-toolbar .btn{background:#fff;color:#1e40af;border:none;padding:6px 14px;border-radius:5px;cursor:pointer;font-weight:600;text-decoration:none;font-size:13px;}
.exp-toolbar .btn:hover{background:#dbeafe;}
.exp-container{max-width:900px;margin:0 auto;background:#fff;padding:30px 40px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.05);}
.exp-header{border-bottom:3px solid #1e40af;padding-bottom:14px;margin-bottom:20px;}
.exp-header h1{margin:0;font-size:22px;color:#1e40af;}
.exp-header .subtitle{color:#6b7280;font-size:13px;margin-top:4px;}
.info-block{background:#f8fafc;border-left:4px solid #1e40af;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:13px;}
.info-block strong{color:#1e40af;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;margin-top:8px;}
.info-item{font-size:13px;}
.info-item strong{display:block;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;font-weight:600;}
h2.section-title{font-size:16px;color:#1e40af;border-bottom:2px solid #dbeafe;padding-bottom:6px;margin:24px 0 12px;}
.novedad{background:#fff;border:1px solid #e5e7eb;border-left:4px solid;border-radius:6px;padding:12px 16px;margin-bottom:10px;page-break-inside:avoid;}
.novedad.grave{border-left-color:#dc2626;background:#fef2f2;}
.novedad.media{border-left-color:#d97706;background:#fffbeb;}
.novedad.leve{border-left-color:#16a34a;background:#f0fdf4;}
.novedad-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:6px;font-size:13px;flex-wrap:wrap;}
.novedad-head .fecha{color:#6b7280;font-family:monospace;font-size:11px;}
.novedad-desc{color:#1f2937;font-size:13px;white-space:pre-wrap;line-height:1.5;margin:6px 0;}
.novedad-meta{font-size:11px;color:#6b7280;margin-top:6px;}
.evi-fotos{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.evi-fotos img{width:120px;height:120px;object-fit:cover;border-radius:6px;border:1px solid #d1d5db;page-break-inside:avoid;}
.rev-tabla{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;}
.rev-tabla th{background:#dbeafe;color:#1e40af;padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid #93c5fd;}
.rev-tabla td{padding:6px 8px;border-bottom:1px solid #f3f4f6;}
.rev-tabla tr:hover{background:#f8fafc;}
.grav-pill{display:inline-block;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
.grav-pill.grave{background:#fee2e2;color:#991b1b;}
.grav-pill.media{background:#fef3c7;color:#92400e;}
.grav-pill.leve{background:#dcfce7;color:#166534;}
.tipo-pill{display:inline-block;padding:2px 8px;border-radius:8px;background:#e0e7ff;color:#4c1d95;font-size:11px;font-weight:600;}
.sin-datos{padding:16px;text-align:center;color:#9ca3af;font-size:12px;background:#f8fafc;border-radius:6px;}
.footer-print{margin-top:30px;padding-top:14px;border-top:1px solid #e5e7eb;font-size:11px;color:#6b7280;text-align:center;}

@media print {
    body{background:#fff;padding:0;}
    .exp-toolbar{display:none;}
    .exp-container{box-shadow:none;padding:0;max-width:none;border-radius:0;}
    .novedad{page-break-inside:avoid;}
    .evi-fotos img{width:100px;height:100px;}
    @page{margin:1.5cm;}
}
</style>
</head>
<body>

<div class="exp-toolbar">
    <div>📄 <strong>Historial de vehículo</strong> — SmartPark</div>
    <div>
        <button type="button" class="btn" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
        <a href="<?= url('/vehiculos/exportar_historial?id=' . $id) ?>" class="btn">⚙️ Cambiar opciones</a>
    </div>
</div>

<div class="exp-container">

    <div class="exp-header">
        <h1><?= $veh['tipo'] === 'moto' ? '🏍️' : '🚗' ?> Historial completo — Placa <?= e($veh['placa']) ?></h1>
        <div class="subtitle">Reporte generado el <?= e($fechaExport) ?>
            <?php if ($desde || $hasta): ?>
                · Rango: <?= $desde ? e($desde) : 'inicio' ?> a <?= $hasta ? e($hasta) : 'hoy' ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="info-block">
        <div class="info-grid">
            <div class="info-item"><strong>Placa</strong><span style="font-family:monospace;font-weight:700;font-size:15px"><?= e($veh['placa']) ?></span></div>
            <div class="info-item"><strong>Tipo</strong><?= $veh['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></div>
            <div class="info-item"><strong>Apartamento</strong><?= e($veh['apto']) ?> — Torre <?= (int)$veh['torre'] ?><?= $veh['piso'] ? ' · Piso ' . (int)$veh['piso'] : '' ?></div>
            <div class="info-item"><strong>Marca / Color</strong><?= e(trim(($veh['marca'] ?? '') . ' ' . ($veh['color'] ?? '')) ?: '—') ?></div>
            <div class="info-item"><strong>Residente</strong><?= e($veh['residente_nombre'] ?: '—') ?><?= $veh['residente_tipo'] ? ' <small>(' . e($veh['residente_tipo']) . ')</small>' : '' ?></div>
            <div class="info-item"><strong>Celular</strong><?= e($veh['residente_celular'] ?: '—') ?></div>
        </div>
    </div>

    <?php if ($tipo === 'ambas' || $tipo === 'novedades'): ?>
        <h2 class="section-title">⚠️ Novedades / observaciones (<?= count($novedades) ?>)</h2>
        <?php if (empty($novedades)): ?>
            <div class="sin-datos">Sin novedades registradas en el rango seleccionado.</div>
        <?php else: foreach ($novedades as $n): ?>
            <div class="novedad <?= e($n['gravedad']) ?>">
                <div class="novedad-head">
                    <div>
                        <span class="tipo-pill"><?= e($labelsTipo[$n['tipo']] ?? $n['tipo']) ?></span>
                        <span class="grav-pill <?= e($n['gravedad']) ?>">Gravedad <?= strtoupper(e($n['gravedad'])) ?></span>
                    </div>
                    <span class="fecha">🕐 <?= e(date('d/m/Y H:i:s', strtotime($n['creado_en']))) ?></span>
                </div>
                <div class="novedad-desc"><?= e($n['descripcion']) ?></div>
                <?php if ($n['registrado_por']): ?>
                    <div class="novedad-meta">📝 Registrada por: <strong><?= e($n['registrado_por']) ?></strong></div>
                <?php endif; ?>
                <?php if ($conFotos):
                    $fotos = [];
                    if ($n['evidencia_url']) $fotos[] = ['url' => $fotoUrl($n['evidencia_url']), 'label' => 'Principal'];
                    if (!empty($n['evidencias'])) {
                        foreach ($n['evidencias'] as $e) {
                            if ($e['tipo'] === 'foto') $fotos[] = ['url' => $fotoUrl($e['archivo_url']), 'label' => 'Adicional'];
                        }
                    }
                    if (!empty($fotos)): ?>
                        <div class="evi-fotos">
                            <?php foreach ($fotos as $f): ?>
                                <img src="<?= e($f['url']) ?>" alt="<?= e($f['label']) ?>" onerror="this.style.display='none'">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    <?php endif; ?>

    <?php if ($tipo === 'ambas' || $tipo === 'revistas'): ?>
        <h2 class="section-title">📊 Registros en revistas de parqueo (<?= count($revistas) ?>)</h2>
        <?php if (empty($revistas)): ?>
            <div class="sin-datos">Sin apariciones en revistas registradas.</div>
        <?php else: ?>
            <table class="rev-tabla">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Revista</th>
                        <th>Nivel</th>
                        <th>Celda</th>
                        <th>Estado</th>
                        <th>Placa detectada</th>
                        <?php if ($conFotos): ?><th>Foto</th><?php endif; ?>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revistas as $r):
                        $estadoLbl = (int)$r['celda_vacia'] === 1 ? '⚪ Vacía' : '🟢 Ocupada';
                        $fotoAbs = $fotoUrl($r['foto_path']);
                    ?>
                        <tr>
                            <td style="font-family:monospace;font-size:11px;color:#374151;white-space:nowrap">
                                <strong><?= e(date('d/m/Y', strtotime($r['creado_en']))) ?></strong><br>
                                <span style="color:#6b7280"><?= e(date('H:i:s', strtotime($r['creado_en']))) ?></span>
                            </td>
                            <td style="font-family:monospace;font-size:11px">
                                <?= $r['revista_id'] ? '#' . (int)$r['revista_id'] : '—' ?>
                                <?php if ($r['revista_iniciada']): ?>
                                    <br><small style="color:#6b7280"><?= e(date('d/m', strtotime($r['revista_iniciada']))) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($r['nivel'] ?? '—') ?></strong></td>
                            <td><?= e($r['celda'] ?? '—') ?></td>
                            <td><?= $estadoLbl ?></td>
                            <td style="font-family:monospace"><?= e($r['placa_detectada']) ?>
                                <?php if ((int)$r['placa_manual'] === 1): ?>
                                    <br><small style="color:#6b7280">manual</small>
                                <?php endif; ?>
                            </td>
                            <?php if ($conFotos): ?>
                                <td>
                                    <?php if ($fotoAbs): ?>
                                        <img src="<?= e($fotoAbs) ?>" style="width:80px;height:60px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-size:10px">sin foto</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="font-size:11px"><?= e($r['usuario_nombre'] ?? '—') ?></td>
                        </tr>
                        <?php if (!empty($r['observaciones'])): ?>
                            <tr>
                                <td colspan="<?= $conFotos ? 8 : 7 ?>" style="background:#f8fafc;font-size:11px;color:#6b7280;padding:6px 12px">
                                    📝 <?= e($r['observaciones']) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <div class="footer-print">
        Generado desde SmartPark — <?= e($fechaExport) ?> · Conjunto ID <?= (int)$conjuntoId ?> · Usuario: <?= e($u['nombre_completo'] ?? $u['usuario'] ?? '—') ?>
    </div>
</div>

</body>
</html>
<?php exit; ?>
