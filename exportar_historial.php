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
           <?= $veh['residente_nombre'] ? ' · ' . e($veh['residente_nombre']) : '' ?>
           <?php // v3BA: mostrar tipo en cabecera
           if (!empty($veh['residente_tipo'])):
               $tHdr = strtolower($veh['residente_tipo']);
               $emoHdr = ['propietario'=>'🏘️','inquilino'=>'🏠','visitante'=>'👥','familiar'=>'👨‍👩‍👧'][$tHdr] ?? '👤';
           ?>
               (<?= $emoHdr ?> <?= e(strtoupper($veh['residente_tipo'])) ?>)
           <?php endif; ?>
        </p>
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

// ── Revistas / rondas de parqueo ──
// v3AT: Búsqueda TRIPLE en tres tablas posibles.
//   FUENTE A: `rondas_detalle` (esquema nuevo)
//   FUENTE B: `lecturas_placas` con revista_id o nivel+celda
//   FUENTE C: `revistas_detalle` (esquema del módulo de revistas de Rafael)
//             La que usan /revistas/api_guardar_paso.php, /vehiculos/ver, etc.
$revistas = [];
$errorRevistas = null;
$diagnostico = null;
if ($tipo === 'ambas' || $tipo === 'revistas') {
    try {
        // Diagnóstico TRIPLE
        $stDiag = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM lecturas_placas
                  WHERE conjunto_id = :c1
                    AND (vehiculo_id = :v1 OR placa_detectada = :p1)) AS total_lecturas,
                (SELECT COUNT(*) FROM lecturas_placas
                  WHERE conjunto_id = :c2
                    AND (vehiculo_id = :v2 OR placa_detectada = :p2)
                    AND revista_id IS NOT NULL) AS lecturas_con_revista_id,
                (SELECT COUNT(*) FROM lecturas_placas
                  WHERE conjunto_id = :c3
                    AND (vehiculo_id = :v3 OR placa_detectada = :p3)
                    AND nivel IS NOT NULL AND celda IS NOT NULL) AS lecturas_con_nivel_celda,
                (SELECT COUNT(*) FROM rondas_detalle rd
                  JOIN rondas r ON r.id = rd.ronda_id
                  WHERE r.conjunto_id = :c4
                    AND (rd.vehiculo_id = :v4 OR rd.placa_libre = :p4)) AS total_rondas_detalle,
                (SELECT COUNT(*) FROM revistas_detalle rvd
                  JOIN revistas rv ON rv.id = rvd.revista_id
                  WHERE rv.conjunto_id = :c5
                    AND (rvd.vehiculo_id = :v5 OR rvd.placa_detectada = :p5)) AS total_revistas_detalle");
        $stDiag->execute([
            ':c1' => $conjuntoId, ':v1' => $id, ':p1' => $veh['placa'],
            ':c2' => $conjuntoId, ':v2' => $id, ':p2' => $veh['placa'],
            ':c3' => $conjuntoId, ':v3' => $id, ':p3' => $veh['placa'],
            ':c4' => $conjuntoId, ':v4' => $id, ':p4' => $veh['placa'],
            ':c5' => $conjuntoId, ':v5' => $id, ':p5' => $veh['placa'],
        ]);
        $diagnostico = $stDiag->fetch();

        $desdeSql = $desde ? $desde . ' 00:00:00' : null;
        $hastaSql = $hasta ? $hasta . ' 23:59:59' : null;

        // ── FUENTE A: rondas_detalle ──
        $whereA = ["(rd.vehiculo_id = :va OR rd.placa_libre = :pa)", "r.conjunto_id = :ca"];
        $paramsA = [':va' => $id, ':pa' => $veh['placa'], ':ca' => $conjuntoId];
        if ($desdeSql) { $whereA[] = "rd.creado_en >= :fda"; $paramsA[':fda'] = $desdeSql; }
        if ($hastaSql) { $whereA[] = "rd.creado_en <= :fha"; $paramsA[':fha'] = $hastaSql; }

        $sqlA = "SELECT 'rondas' AS fuente_row, rd.id AS id_row,
                        rd.ronda_id AS revista_ronda_id, rd.creado_en,
                        rd.placa_libre, rd.consistente, rd.observacion,
                        rd.vehiculo_id AS veh_id_row,
                        c.nombre_visible AS celda, np.codigo AS nivel, np.nombre AS nivel_nombre,
                        r.fecha_hora_inicio AS ronda_iniciada, r.estado AS ronda_estado,
                        up.nombre_completo AS usuario_nombre,
                        (SELECT lp.foto_path FROM lecturas_placas lp
                          WHERE lp.conjunto_id = r.conjunto_id
                            AND (lp.placa_detectada = rd.placa_libre
                                 OR (lp.vehiculo_id IS NOT NULL AND lp.vehiculo_id = rd.vehiculo_id))
                            AND ABS(TIMESTAMPDIFF(MINUTE, lp.creado_en, rd.creado_en)) < 10
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, lp.creado_en, rd.creado_en)) ASC
                          LIMIT 1) AS foto_path
                   FROM rondas_detalle rd
                   JOIN rondas r ON r.id = rd.ronda_id
              LEFT JOIN celdas c ON c.id = rd.celda_id
              LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
              LEFT JOIN usuarios up ON up.id = r.usuario_ronda_id
                  WHERE " . implode(' AND ', $whereA);
        $stA = $pdo->prepare($sqlA);
        $stA->execute($paramsA);
        $rowsA = $stA->fetchAll();

        // ── FUENTE B: lecturas_placas con revista_id o nivel+celda ──
        $whereB = [
            "(lp.vehiculo_id = :vb OR lp.placa_detectada = :pb)",
            "lp.conjunto_id = :cb",
            "(lp.revista_id IS NOT NULL OR (lp.nivel IS NOT NULL AND lp.celda IS NOT NULL))",
        ];
        $paramsB = [':vb' => $id, ':pb' => $veh['placa'], ':cb' => $conjuntoId];
        if ($desdeSql) { $whereB[] = "lp.creado_en >= :fdb"; $paramsB[':fdb'] = $desdeSql; }
        if ($hastaSql) { $whereB[] = "lp.creado_en <= :fhb"; $paramsB[':fhb'] = $hastaSql; }

        $sqlB = "SELECT 'lecturas' AS fuente_row, lp.id AS id_row,
                        lp.revista_id AS revista_ronda_id, lp.creado_en,
                        lp.placa_detectada AS placa_libre, 1 AS consistente,
                        lp.observaciones AS observacion,
                        lp.vehiculo_id AS veh_id_row,
                        lp.celda, lp.nivel, NULL AS nivel_nombre,
                        NULL AS ronda_iniciada, NULL AS ronda_estado,
                        up.nombre_completo AS usuario_nombre, lp.foto_path
                   FROM lecturas_placas lp
              LEFT JOIN usuarios up ON up.id = lp.usuario_id
                  WHERE " . implode(' AND ', $whereB);
        $stB = $pdo->prepare($sqlB);
        $stB->execute($paramsB);
        $rowsB = $stB->fetchAll();

        // ── FUENTE C: revistas_detalle (LA TABLA QUE FALTABA) ──
        // Los registros que ves en /vehiculos/ver vienen de aquí.
        // Estructura: id, revista_id, celda_id, estado ('ocupada'/'vacia'),
        //             placa_detectada, vehiculo_id, foto_path
        // Sin creado_en propio → usamos rv.iniciado_en como fecha del registro
        $whereC = ["(rvd.vehiculo_id = :vc OR rvd.placa_detectada = :pc)", "rv.conjunto_id = :cc"];
        $paramsC = [':vc' => $id, ':pc' => $veh['placa'], ':cc' => $conjuntoId];
        if ($desdeSql) { $whereC[] = "rv.iniciado_en >= :fdc"; $paramsC[':fdc'] = $desdeSql; }
        if ($hastaSql) { $whereC[] = "rv.iniciado_en <= :fhc"; $paramsC[':fhc'] = $hastaSql; }

        $sqlC = "SELECT 'revistas' AS fuente_row, rvd.id AS id_row,
                        rvd.revista_id AS revista_ronda_id,
                        rv.iniciado_en AS creado_en,
                        rvd.placa_detectada AS placa_libre,
                        (CASE WHEN rvd.estado = 'ocupada' THEN 1 ELSE 0 END) AS consistente,
                        NULL AS observacion,
                        rvd.vehiculo_id AS veh_id_row,
                        c.nombre_visible AS celda,
                        np.codigo AS nivel, np.nombre AS nivel_nombre,
                        rv.iniciado_en AS ronda_iniciada,
                        rv.estado AS ronda_estado,
                        usr.nombre_completo AS usuario_nombre,
                        rvd.foto_path
                   FROM revistas_detalle rvd
                   JOIN revistas rv ON rv.id = rvd.revista_id
              LEFT JOIN celdas c ON c.id = rvd.celda_id
              LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
              LEFT JOIN usuarios usr ON usr.id = rv.usuario_id
                  WHERE " . implode(' AND ', $whereC);
        $stC = $pdo->prepare($sqlC);
        $stC->execute($paramsC);
        $rowsC = $stC->fetchAll();

        // Combinar las tres fuentes y ordenar por fecha desc
        $revistas = array_merge($rowsA, $rowsB, $rowsC);
        usort($revistas, function($a, $b){
            return strcmp($b['creado_en'] ?? '', $a['creado_en'] ?? '');
        });

        // Deduplicar por revista_id + placa
        $seen = [];
        $revistas = array_filter($revistas, function($r) use (&$seen){
            $key = ($r['revista_ronda_id'] ?? 'x') . '|' . ($r['placa_libre'] ?? 'x') . '|' . ($r['celda'] ?? 'x');
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        });
        $revistas = array_slice(array_values($revistas), 0, 200);
    } catch (Exception $ex) {
        $errorRevistas = $ex->getMessage();
    }
}

$fechaExport = date('d/m/Y H:i:s');
// v3AT.1: helper mejorado — sabe qué subcarpeta usar según de qué tabla viene
//   - revistas_detalle → /uploads/revistas/xxx.jpg
//   - lecturas_placas  → /uploads/ocr/xxx.jpg
//   - rondas (con subquery a lecturas_placas) → igual que lecturas
//   - evidencias/observaciones → tal cual (ya vienen con prefijo)
$fotoUrl = function($url, $fuente = null) {
    if (!$url) return null;
    if (strpos($url, 'http') === 0) return $url;         // URL absoluta
    if (strpos($url, '/') === 0) return $url;             // Empieza con "/"
    // Si ya viene con prefijo de subcarpeta conocida, respetar
    if (preg_match('#^(ocr|revistas|evidencias|observaciones|uploads)/#', $url)) {
        return '/uploads/' . $url;
    }
    // Sin prefijo → decidir según origen
    if ($fuente === 'revistas') return '/uploads/revistas/' . $url;
    if ($fuente === 'rondas' || $fuente === 'lecturas') return '/uploads/ocr/' . $url;
    // Fallback: /uploads/ directo (comportamiento antiguo)
    return '/uploads/' . $url;
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
        <button type="button" class="btn" onclick="expCompartirWhatsApp()" style="background:#25D366;color:#fff">📱 Compartir</button>
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

    <?php
    $fotoVehiculo = $veh['foto_principal'] ?? null;
    if ($fotoVehiculo) {
        $fotoVehiculo = strpos($fotoVehiculo, 'http') === 0 ? $fotoVehiculo : '/uploads/' . ltrim($fotoVehiculo, '/');
    }
    ?>
    <div class="info-block" style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
        <?php if ($fotoVehiculo): ?>
            <div style="flex-shrink:0">
                <img id="foto-veh" src="<?= e($fotoVehiculo) ?>"
                     alt="Foto del vehículo"
                     onclick="expAbrirLightbox('<?= e($fotoVehiculo) ?>', 'Foto de <?= e($veh['placa']) ?>')"
                     onerror="this.style.display='none'"
                     style="width:120px;height:90px;object-fit:cover;border-radius:6px;border:2px solid #1e40af;cursor:zoom-in;transition:transform .1s"
                     onmouseover="this.style.transform='scale(1.03)'"
                     onmouseout="this.style.transform='scale(1)'"
                     title="Clic para ampliar">
                <div style="font-size:9px;color:#6b7280;text-align:center;margin-top:2px">🔍 Clic para ampliar</div>
            </div>
        <?php endif; ?>
        <div class="info-grid" style="flex:1;min-width:280px">
            <div class="info-item"><strong>Placa</strong><span style="font-family:monospace;font-weight:700;font-size:15px"><?= e($veh['placa']) ?></span></div>
            <div class="info-item"><strong>Tipo</strong><?= $veh['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></div>
            <div class="info-item"><strong>Apartamento</strong><?= e($veh['apto']) ?> — Torre <?= (int)$veh['torre'] ?><?= $veh['piso'] ? ' · Piso ' . (int)$veh['piso'] : '' ?></div>
            <div class="info-item"><strong>Marca / Color</strong><?= e(trim(($veh['marca'] ?? '') . ' ' . ($veh['color'] ?? '')) ?: '—') ?></div>
            <?php
            // v3BA: badge de tipo residente en el reporte
            $tipoLower = strtolower($veh['residente_tipo'] ?? '');
            $tipoLabelsRep = [
                'propietario' => ['🏘️', '#dbeafe', '#1e40af', 'PROPIETARIO'],
                'inquilino'   => ['🏠', '#fef3c7', '#92400e', 'INQUILINO'],
                'visitante'   => ['👥', '#e0e7ff', '#4c1d95', 'VISITANTE'],
                'familiar'    => ['👨‍👩‍👧', '#fce7f3', '#9f1239', 'FAMILIAR'],
            ];
            $tRep = $tipoLabelsRep[$tipoLower] ?? null;
            $badgeHtml = '';
            if ($veh['residente_tipo']) {
                if ($tRep) {
                    $badgeHtml = '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;background:'
                               . $tRep[1] . ';color:' . $tRep[2] . ';margin-left:4px;vertical-align:middle">'
                               . $tRep[0] . ' ' . e($tRep[3]) . '</span>';
                } else {
                    $badgeHtml = '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;background:#f3f4f6;color:#374151;margin-left:4px;vertical-align:middle">'
                               . e(strtoupper($veh['residente_tipo'])) . '</span>';
                }
            }
            ?>
            <div class="info-item"><strong>Residente</strong><?= e($veh['residente_nombre'] ?: '—') ?><?= $badgeHtml ?></div>
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
        <?php if ($errorRevistas): ?>
            <div style="padding:14px;background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;font-size:12px;font-family:monospace">
                ⚠️ Error al consultar registros de parqueo:<br><?= e($errorRevistas) ?>
            </div>
        <?php elseif (empty($revistas)): ?>
            <div class="sin-datos">Sin apariciones en revistas registradas.</div>
            <?php if ($diagnostico && !$desde && !$hasta): ?>
                <div style="margin-top:8px;padding:10px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:11px;color:#0c4a6e">
                    🔎 <strong>Diagnóstico</strong> (para saber por qué no aparece nada):<br>
                    Total lecturas en lecturas_placas: <?= (int)($diagnostico['total_lecturas'] ?? 0) ?><br>
                    &nbsp;&nbsp;→ Con revista_id no nulo: <?= (int)($diagnostico['lecturas_con_revista_id'] ?? 0) ?><br>
                    &nbsp;&nbsp;→ Con nivel + celda: <?= (int)($diagnostico['lecturas_con_nivel_celda'] ?? 0) ?><br>
                    Registros en rondas_detalle: <?= (int)($diagnostico['total_rondas_detalle'] ?? 0) ?><br>
                    <strong>Registros en revistas_detalle: <?= (int)($diagnostico['total_revistas_detalle'] ?? 0) ?></strong><br>
                    <small style="color:#075985">v3AT.1 busca en 3 fuentes (rondas_detalle + lecturas_placas + revistas_detalle).</small>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <table class="rev-tabla">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Ronda</th>
                        <th>Nivel</th>
                        <th>Celda</th>
                        <th>Estado</th>
                        <th>Placa</th>
                        <?php if ($conFotos): ?><th>Foto</th><?php endif; ?>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revistas as $r):
                        $estadoLbl = (int)$r['consistente'] === 1 ? '✓ Consistente' : '⚠️ Inconsistente';
                        $fotoAbs = $fotoUrl($r['foto_path'], $r['fuente_row'] ?? null);
                        $placaMostrar = $r['placa_libre'] ?: $veh['placa'];
                    ?>
                        <tr>
                            <td style="font-family:monospace;font-size:11px;color:#374151;white-space:nowrap">
                                <strong><?= e(date('d/m/Y', strtotime($r['creado_en']))) ?></strong><br>
                                <span style="color:#6b7280"><?= e(date('H:i:s', strtotime($r['creado_en']))) ?></span>
                            </td>
                            <td style="font-family:monospace;font-size:11px">
                                #<?= (int)$r['revista_ronda_id'] ?>
                                <?php if ($r['ronda_iniciada']): ?>
                                    <br><small style="color:#6b7280"><?= e(date('d/m', strtotime($r['ronda_iniciada']))) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($r['nivel'] ?: '—') ?></strong>
                                <?php if ($r['nivel_nombre']): ?><br><small style="color:#6b7280"><?= e($r['nivel_nombre']) ?></small><?php endif; ?>
                            </td>
                            <td><?= e($r['celda'] ?: '—') ?></td>
                            <td><?= $estadoLbl ?></td>
                            <td style="font-family:monospace"><?= e($placaMostrar) ?>
                                <?php if (empty($r['veh_id_row'])): ?>
                                    <br><small style="color:#6b7280">solo placa</small>
                                <?php endif; ?>
                            </td>
                            <?php if ($conFotos): ?>
                                <td>
                                    <?php if ($fotoAbs): ?>
                                        <img src="<?= e($fotoAbs) ?>"
                                             style="width:80px;height:60px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;cursor:zoom-in"
                                             onclick="expAbrirLightbox('<?= e($fotoAbs) ?>', 'Revista #<?= (int)$r['revista_ronda_id'] ?> · <?= e($r['nivel'] ?: '') ?>/<?= e($r['celda'] ?: '') ?> · <?= e($placaMostrar) ?>')"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='inline'"
                                             title="Clic para ampliar">
                                        <span style="display:none;color:#9ca3af;font-size:10px">foto no disponible</span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-size:10px">sin foto</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="font-size:11px"><?= e($r['usuario_nombre'] ?? '—') ?></td>
                        </tr>
                        <?php if (!empty($r['observacion'])): ?>
                            <tr>
                                <td colspan="<?= $conFotos ? 8 : 7 ?>" style="background:#f8fafc;font-size:11px;color:#6b7280;padding:6px 12px">
                                    📝 <?= e($r['observacion']) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <div class="footer-print">
        Generado desde SmartPark v3AT.1 — <?= e($fechaExport) ?> · Conjunto ID <?= (int)$conjuntoId ?> · Usuario: <?= e($u['nombre_completo'] ?? $u['username'] ?? '—') ?>
    </div>
</div>

<!-- v3AK.3: Lightbox flotante para ampliar fotos -->
<div id="exp-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:zoom-out" onclick="expCerrarLightbox()">
    <img id="exp-lightbox-img" src="" alt="" style="max-width:100%;max-height:100vh;object-fit:contain;border-radius:6px;box-shadow:0 10px 40px rgba(0,0,0,.5)">
    <div id="exp-lightbox-info" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.75);color:#fff;padding:8px 16px;border-radius:6px;font-family:monospace;font-size:13px"></div>
    <button type="button" onclick="expCerrarLightbox()" style="position:absolute;top:15px;right:20px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:24px;width:40px;height:40px;border-radius:50%;cursor:pointer">✕</button>
</div>

<script>
function expAbrirLightbox(url, label) {
    document.getElementById('exp-lightbox-img').src = url;
    document.getElementById('exp-lightbox-info').textContent = label || '';
    document.getElementById('exp-lightbox').style.display = 'flex';
}
function expCerrarLightbox() {
    document.getElementById('exp-lightbox').style.display = 'none';
    document.getElementById('exp-lightbox-img').src = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') expCerrarLightbox(); });

// v3AL: Compartir el reporte por WhatsApp (o cualquier app que soporte Web Share API en móvil)
function expCompartirWhatsApp() {
    var placa = <?= json_encode($veh['placa']) ?>;
    var apto  = <?= json_encode($veh['apto']) ?>;
    var torre = <?= json_encode((int)$veh['torre']) ?>;
    var totalNov = <?= (int)count($novedades) ?>;
    var totalRev = <?= (int)count($revistas) ?>;
    var url = window.location.href;

    var texto = "📄 *Historial de vehículo — SmartPark*\n\n" +
                "🚗 *Placa:* " + placa + "\n" +
                "🏠 *Apto:* " + apto + " (Torre " + torre + ")\n" +
                "⚠️ *Novedades:* " + totalNov + "\n" +
                "📊 *Registros de parqueo:* " + totalRev + "\n\n" +
                "Ver reporte completo (requiere login):\n" + url;

    // Móvil: usar Web Share API si está disponible (deja elegir WhatsApp, correo, etc.)
    if (navigator.share) {
        navigator.share({
            title: 'Historial ' + placa + ' — SmartPark',
            text: texto,
            url: url
        }).catch(function(err){
            // Si el usuario cancela o falla, caer al fallback
            if (err && err.name !== 'AbortError') {
                _expAbrirWA(texto);
            }
        });
    } else {
        // Escritorio: abrir WhatsApp Web SIN número — el usuario elige el destinatario
        _expAbrirWA(texto);
    }
}

function _expAbrirWA(texto) {
    var waUrl = 'https://wa.me/?text=' + encodeURIComponent(texto);
    window.open(waUrl, '_blank');
}

// Hacer clickeables todas las fotos de evidencia (novedades) y de revistas
document.addEventListener('DOMContentLoaded', function(){
    // Fotos de novedades (grid evi-fotos)
    document.querySelectorAll('.evi-fotos img').forEach(function(img){
        img.style.cursor = 'zoom-in';
        img.addEventListener('click', function(){ expAbrirLightbox(img.src, img.alt || 'Evidencia'); });
    });
    // Fotos de revistas
    document.querySelectorAll('.rev-tabla img').forEach(function(img){
        img.style.cursor = 'zoom-in';
        img.addEventListener('click', function(){ expAbrirLightbox(img.src, 'Foto de revista'); });
    });
});
</script>

</body>
</html>
<?php exit; ?>
