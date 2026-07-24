<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/ver.php
// v2.0 (3U): Detalle de revista con todos los registros, fotos ampliables
//            y botón editar por celda.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) redirect('/revistas');

$st = $pdo->prepare("SELECT r.*, u.nombre_completo AS usuario_nombre, u.username AS usuario_username,
                            n.nombre AS nivel_nombre
                       FROM revistas r
                  LEFT JOIN usuarios u ON u.id = r.usuario_id
                  LEFT JOIN niveles_parqueadero n ON n.codigo = r.nivel AND n.conjunto_id = r.conjunto_id
                      WHERE r.id = :id AND r.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$rev = $st->fetch();
if (!$rev) { flash_set('error', 'Revista no encontrada.'); redirect('/revistas'); }

// v7.68: revistas del MISMO DÍA (para saltar entre ellas sin volver al listado)
$revistasDia = [];
try {
    $stDia = $pdo->prepare("
        SELECT r.id, r.nivel, r.estado, r.iniciado_en,
               u.nombre_completo AS usuario_nombre,
               (SELECT COUNT(*) FROM revistas_detalle rdx WHERE rdx.revista_id = r.id) AS n_reg
          FROM revistas r
     LEFT JOIN usuarios u ON u.id = r.usuario_id
         WHERE r.conjunto_id = :c
           AND DATE(r.iniciado_en) = DATE(:f)
      ORDER BY r.iniciado_en DESC");
    $stDia->execute([':c' => $conjuntoId, ':f' => $rev['iniciado_en']]);
    $revistasDia = $stDia->fetchAll();
} catch (Throwable $e) { /* defensivo: si falla, no se muestra el selector */ }

// Detalles con info de celda y vehículo
$stD = $pdo->prepare("SELECT rd.*, c.nombre_visible AS celda_nombre, c.numero_orden, c.tipo AS celda_tipo,
        ad.numero_visible AS apto_dueno,
        v.id AS veh_encontrado_id, v.placa AS vehiculo_placa, v.tipo AS vehiculo_tipo,
        v.archivado_en AS veh_archivado,
        vv.id AS vis_encontrado_id, vv.nombre_visitante AS vis_nombre,
        avv.numero_visible AS vis_apto,
        av.numero_visible AS vehiculo_apto,
        av.estado_morosidad AS veh_apto_moroso,
        ad.estado_morosidad AS celda_apto_moroso
    FROM revistas_detalle rd
    JOIN celdas c ON c.id = rd.celda_id
    LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
    LEFT JOIN vehiculos v ON (v.id = rd.vehiculo_id
                          OR (rd.vehiculo_id IS NULL
                              AND rd.placa_detectada IS NOT NULL
                              AND rd.placa_detectada <> ''
                              AND UPPER(v.placa) = UPPER(rd.placa_detectada)
                              AND v.conjunto_id = :cj))
    LEFT JOIN visitantes_vehiculos vv ON (rd.vehiculo_id IS NULL
                              AND v.id IS NULL
                              AND rd.placa_detectada IS NOT NULL
                              AND rd.placa_detectada <> ''
                              AND UPPER(vv.placa) = UPPER(rd.placa_detectada)
                              AND vv.conjunto_id = :cj2)
    LEFT JOIN apartamentos av ON av.id = v.apartamento_id
    LEFT JOIN apartamentos avv ON avv.id = vv.apartamento_id
    WHERE rd.revista_id = :rv
    ORDER BY CAST(REGEXP_REPLACE(c.nombre_visible, '[^0-9]', '') AS UNSIGNED) ASC, c.nombre_visible ASC");
$stD->execute([':rv' => $id, ':cj' => $conjuntoId, ':cj2' => $conjuntoId]);
$detalles = $stD->fetchAll();

// v7.70: novedades registradas DURANTE esta revista (por rango de tiempo y
// sobre vehículos que aparecen en el detalle). Defensivo: si falla, no se muestra.
$novedades = [];
try {
    $idsVeh = [];
    foreach ($detalles as $__d) {
        // v7.79: contar también los que se registraron DESPUÉS (encontrados por placa)
        if (!empty($__d['vehiculo_id']))        $idsVeh[] = (int)$__d['vehiculo_id'];
        elseif (!empty($__d['veh_encontrado_id'])) $idsVeh[] = (int)$__d['veh_encontrado_id'];
    }
    $idsVeh = array_values(array_unique($idsVeh));
    if ($idsVeh) {
        $inV  = implode(',', array_map('intval', $idsVeh));
        $hasta = $rev['terminado_en'] ?: date('Y-m-d H:i:s');
        $stN = $pdo->prepare("
            SELECT o.id, o.vehiculo_id, o.tipo, o.gravedad, o.descripcion, o.creado_en,
                   v.placa, u.nombre_completo AS usuario_nombre
              FROM observaciones_vehiculo o
              JOIN vehiculos v ON v.id = o.vehiculo_id
         LEFT JOIN usuarios u ON u.id = o.usuario_registra
             WHERE o.vehiculo_id IN ($inV)
               AND o.creado_en BETWEEN :ini AND :fin
          ORDER BY o.creado_en DESC");
        $stN->execute([':ini' => $rev['iniciado_en'], ':fin' => $hasta]);
        $novedades = $stN->fetchAll();
    }
} catch (Throwable $e) { /* tabla o columnas distintas: no romper la página */ }

// v7.71: índice vehículo -> nº de novedades (para marcar y filtrar las tarjetas)
$novPorVeh = [];
foreach ($novedades as $__n) {
    $vid = (int)($__n['vehiculo_id'] ?? 0);
    if ($vid > 0) $novPorVeh[$vid] = ($novPorVeh[$vid] ?? 0) + 1;
}

function _durHuman($ini, $fin) {
    if (!$ini || !$fin) return '—';
    $seg = strtotime($fin) - strtotime($ini);
    if ($seg < 0) return '—';
    if ($seg < 60) return $seg . ' seg';
    if ($seg < 3600) return floor($seg/60) . ' min';
    $h = floor($seg/3600); $m = floor(($seg%3600)/60);
    return $h . 'h ' . $m . 'm';
}

$total  = (int)$rev['total_celdas'];
$revisa = (int)$rev['celdas_revisadas'];
$ocupa  = (int)$rev['celdas_ocupadas'];
$vacias = (int)$rev['celdas_vacias'];
$pdte   = max(0, $revisa - $ocupa - $vacias);
$pctRev = $total > 0 ? round($revisa * 100 / $total) : 0;

$_pageTitle = "Revista #{$id}";
include INCLUDES_PATH . '/header.php';
?>

<style>
.ver-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;margin-top:12px;}
.ver-head h2{margin:0;font-size:18px;color:#1f2937;}
.ver-cards{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0;}
.ver-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 18px;min-width:110px;flex:1;text-align:center;}
.ver-card strong{display:block;font-size:24px;line-height:1;margin-bottom:4px;}
.ver-card span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.ver-card.total strong{color:#1f2937;} .ver-card.rev strong{color:#1e6cff;}
.ver-card.oc strong{color:#15803d;} .ver-card.vc strong{color:#d97706;} .ver-card.pd strong{color:#dc2626;}
.pill--curso{background:#dbeafe;color:#1e40af;padding:4px 12px;border-radius:12px;font-weight:600;font-size:13px;}
.pill--term{background:#dcfce7;color:#166534;padding:4px 12px;border-radius:12px;font-weight:600;font-size:13px;}
.pill--canc{background:#fee2e2;color:#991b1b;padding:4px 12px;border-radius:12px;font-weight:600;font-size:13px;}
.ver-info{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;font-size:14px;margin-bottom:14px;}
.ver-info .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #f3f4f6;}
.ver-info .row:last-child{border-bottom:none;}
.det-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:10px;}
.det-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;position:relative;}
.det-card.st-ocupada{border-left:4px solid #15803d;}
.det-card.st-vacia{border-left:4px solid #d97706;}
.det-card.st-pendiente{border-left:4px solid #dc2626;}
.det-card h4{margin:0 0 8px;font-size:15px;display:flex;justify-content:space-between;align-items:center;}
.det-card .thumb{width:100%;height:140px;background:#f3f4f6;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#9ca3af;cursor:zoom-in;}
.det-card .thumb img{width:100%;height:100%;object-fit:cover;}
.det-info{font-size:13px;margin-top:8px;color:#374151;}
.det-info .kv{display:flex;justify-content:space-between;padding:2px 0;}
.det-info .kv span:first-child{color:#6b7280;}
.det-badge{display:inline-block;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.det-badge.ocupada{background:#dcfce7;color:#166534;}
.det-badge.vacia{background:#fef3c7;color:#92400e;}
.det-badge.pendiente{background:#fee2e2;color:#991b1b;}
.det-card .editar{position:absolute;top:8px;right:8px;background:#eff6ff;color:#1e6cff;padding:3px 8px;border-radius:4px;font-size:11px;text-decoration:none;font-weight:600;}
.det-card .editar:hover{background:#dbeafe;}
.no-placa{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;}
.foto-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:zoom-out;}
.foto-modal.mostrar{display:flex;}
.foto-modal img{max-width:100%;max-height:100%;border-radius:6px;}
.foto-modal .cerrar{position:absolute;top:20px;right:20px;background:rgba(255,255,255,.9);border:none;border-radius:50%;width:44px;height:44px;font-size:22px;cursor:pointer;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📋 Revista #<?= $id ?> — <?= e($rev['nivel']) ?><?= $rev['nivel_nombre'] ? ' — ' . e($rev['nivel_nombre']) : '' ?></h1>
</div>

<div class="toolbar">
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:4px">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <?php if (count($revistasDia) > 1): ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:13.5px;color:#374151">
            📅 Revistas del <b><?= e(date('d/m/Y', strtotime($rev['iniciado_en']))) ?></b>:
            <select onchange="if(this.value) location.href=this.value"
                    style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;max-width:340px">
                <?php foreach ($revistasDia as $rd):
                    $sel = ((int)$rd['id'] === $id) ? ' selected' : '';
                    $ic  = $rd['estado'] === 'terminada' ? '✅' : ($rd['estado'] === 'cancelada' ? '❌' : '🟦');
                ?>
                    <option value="<?= url('/revistas/ver?id=' . (int)$rd['id']) ?>"<?= $sel ?>>
                        <?= $ic ?> #<?= (int)$rd['id'] ?> · <?= e($rd['nivel']) ?>
                        · <?= e(date('H:i', strtotime($rd['iniciado_en']))) ?>
                        · <?= (int)$rd['n_reg'] ?> reg.
                        <?= $rd['usuario_nombre'] ? '· ' . e($rd['usuario_nombre']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
</div>
    <?php if ($rev['estado'] === 'en_curso'): ?>
        <a class="btn btn--primary" href="<?= url('/revistas/ejecutar?id=' . $id) ?>">▶️ Continuar revista</a>
    <?php endif; ?>
</div>

<div class="ver-head">
    <h2>Revista de parqueadero</h2>
    <div>
        <?php if ($rev['estado'] === 'en_curso'): ?><span class="pill--curso">🟦 En curso</span>
        <?php elseif ($rev['estado'] === 'terminada'): ?><span class="pill--term">✅ Terminada</span>
        <?php else: ?><span class="pill--canc">❌ Cancelada</span><?php endif; ?>
    </div>
</div>

<?php
// v7.67: cuántas celdas tienen apto en mora (para la tarjeta de filtro)
$nMorosos = 0;
foreach ($detalles as $__d) {
    if ((($__d['veh_apto_moroso'] ?? '') === 'moroso') || (($__d['celda_apto_moroso'] ?? '') === 'moroso')) $nMorosos++;
}
?>
<div class="ver-cards">
    <div class="ver-card total ver-filtro" data-f="todas" onclick="verFiltrarEstado('todas')" style="cursor:pointer" title="Ver todas"><strong><?= $total ?></strong><span>Total celdas</span></div>
    <div class="ver-card rev ver-filtro" data-f="revisadas" onclick="verFiltrarEstado('revisadas')" style="cursor:pointer" title="Ver las revisadas"><strong><?= $revisa ?></strong><span>Revisadas (<?= $pctRev ?>%)</span></div>
    <div class="ver-card oc ver-filtro" data-f="ocupada" onclick="verFiltrarEstado('ocupada')" style="cursor:pointer" title="Ver solo ocupadas"><strong><?= $ocupa ?></strong><span>✅ Ocupadas</span></div>
    <div class="ver-card vc ver-filtro" data-f="vacia" onclick="verFiltrarEstado('vacia')" style="cursor:pointer" title="Ver solo vacías"><strong><?= $vacias ?></strong><span>⭕ Vacías</span></div>
    <div class="ver-card pd ver-filtro" data-f="pendiente" onclick="verFiltrarEstado('pendiente')" style="cursor:pointer" title="Ver solo pendientes"><strong><?= $pdte ?></strong><span>❓ Pendientes</span></div>
    <?php if ($nMorosos > 0): ?>
    <div class="ver-card ver-filtro" data-f="moroso" onclick="verFiltrarEstado('moroso')" style="cursor:pointer;border-left:4px solid #dc2626" title="Ver solo apartamentos en mora">
        <strong style="color:#dc2626"><?= $nMorosos ?></strong><span>🔴 En mora</span>
    </div>
    <?php endif; ?>
    <?php if (!empty($novPorVeh)): ?>
    <div class="ver-card ver-filtro" data-f="novedad" onclick="verFiltrarEstado('novedad')" style="cursor:pointer;border-left:4px solid #92400e" title="Ver solo los que tienen novedad">
        <strong style="color:#92400e"><?= count($novPorVeh) ?></strong><span>⚠️ Con novedad</span>
    </div>
    <?php endif; ?>
</div>
<p class="muted" style="font-size:12.5px;margin:-6px 0 10px">
    Tocá una tarjeta para filtrar el detalle de abajo.
</p>

<div class="ver-info">
    <div class="row"><span>Usuario:</span> <span><?= $rev['usuario_nombre'] ? e($rev['usuario_nombre']) : '—' ?></span></div>
    <div class="row"><span>Nivel:</span> <span><?= e($rev['nivel']) ?></span></div>
    <div class="row"><span>Iniciada:</span> <span><?= $rev['iniciado_en'] ? e(date('d/m/Y H:i:s', strtotime($rev['iniciado_en']))) : '—' ?></span></div>
    <div class="row"><span>Terminada:</span> <span><?= $rev['terminado_en'] ? e(date('d/m/Y H:i:s', strtotime($rev['terminado_en']))) : '<span style="color:#6b7280">— en curso —</span>' ?></span></div>
    <div class="row"><span>Duración:</span> <span><?= e(_durHuman($rev['iniciado_en'], $rev['terminado_en'] ?: date('Y-m-d H:i:s'))) ?></span></div>
    <?php if ($rev['observaciones']): ?>
        <div class="row"><span>Observaciones:</span> <span><?= e($rev['observaciones']) ?></span></div>
    <?php endif; ?>
</div>

<?php if (!empty($novedades)): ?>
<!-- v7.70: novedades registradas durante esta revista -->
<h3 style="margin-top:18px">⚠️ Novedades registradas (<?= count($novedades) ?>)</h3>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;margin:10px 0 18px">
    <?php
    $gravCol = ['grave'=>['#fee2e2','#991b1b'],'media'=>['#fef3c7','#92400e'],
                'leve'=>['#dcfce7','#166534'],'ninguna'=>['#f3f4f6','#374151']];
    $tipoTxt = ['mal_parqueo'=>'🚫 Mal parqueo','advertencia'=>'⚠️ Advertencia',
                'reincidencia'=>'🔁 Reincidencia','queja'=>'📢 Queja','otro'=>'📌 Otro'];
    foreach ($novedades as $nv):
        $gc = $gravCol[$nv['gravedad']] ?? $gravCol['ninguna'];
    ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid <?= $gc[1] ?>;
                    border-radius:8px;padding:11px 13px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-family:monospace;font-weight:700;font-size:14px"><?= e($nv['placa']) ?></span>
                <span style="background:<?= $gc[0] ?>;color:<?= $gc[1] ?>;padding:2px 8px;
                             border-radius:10px;font-size:11px;font-weight:700">
                    <?= e(ucfirst($nv['gravedad'])) ?>
                </span>
            </div>
            <div style="font-size:12.5px;color:#374151;margin-top:5px">
                <?= e($tipoTxt[$nv['tipo']] ?? $nv['tipo']) ?>
            </div>
            <div style="font-size:13px;color:#111827;margin-top:5px"><?= e($nv['descripcion']) ?></div>
            <div style="font-size:11.5px;color:#6b7280;margin-top:6px">
                <?= e(date('d/m/Y H:i', strtotime($nv['creado_en']))) ?>
                <?= $nv['usuario_nombre'] ? ' · ' . e($nv['usuario_nombre']) : '' ?>
                · <a href="<?= url('/observaciones/ver?id=' . (int)$nv['id']) ?>">ver</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h3>Detalle celda por celda (<?= count($detalles) ?>)</h3>

<?php if (!empty($detalles)): ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">
    <input type="text" id="fVerCelda" placeholder="🅿️ Filtrar celda o apto" oninput="verFiltrar()"
           style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
    <input type="text" id="fVerPlaca" placeholder="🔤 Filtrar placa" oninput="verFiltrar()"
           style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;text-transform:uppercase">
    <button type="button" onclick="verLimpiar()" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer">Limpiar</button>
    <span id="fVerCount" style="align-self:center;color:#6b7280;font-size:13px"></span>
</div>
<?php endif; ?>

<?php if (empty($detalles)): ?>
    <div class="notice notice--info">Aún no hay celdas revisadas para esta revista.</div>
<?php else: ?>
    <div class="det-grid">
        <?php foreach ($detalles as $d):
            $urlFoto = $d['foto_path'] ? url('/uploads/revistas/' . $d['foto_path']) : null;
        ?>
            <?php
              // v7.67: ¿el vehículo o el apto dueño está en mora?
              $esMoroso = (($d['veh_apto_moroso'] ?? '') === 'moroso')
                       || (($d['celda_apto_moroso'] ?? '') === 'moroso');
              // v7.71: ¿este vehículo tiene novedades en esta revista?
              $__vidNov = (int)($d['vehiculo_id'] ?? 0) ?: (int)($d['veh_encontrado_id'] ?? 0);
              $nNov = (int)($novPorVeh[$__vidNov] ?? 0);
            ?>
            <div class="det-card st-<?= e($d['estado']) ?>"
                 data-estado="<?= e($d['estado']) ?>"
                 data-moroso="<?= $esMoroso ? '1' : '0' ?>"
                 data-novedad="<?= $nNov > 0 ? '1' : '0' ?>"
                 data-celda="<?= e(strtoupper($d['celda_nombre'] . ' ' . ($d['apto_dueno'] ?? '') . ' ' . ($d['vehiculo_apto'] ?? ''))) ?>"
                 data-placa="<?= e(strtoupper((string)($d['placa_detectada'] ?? '') . ' ' . (string)($d['vehiculo_placa'] ?? ''))) ?>">
                <h4>
                    <span><?= e($d['celda_nombre']) ?> <small style="color:#6b7280">(#<?= (int)$d['numero_orden'] ?>)</small>
                        <?php if ($esMoroso): ?><span title="Apartamento en mora"
                              style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#dc2626;margin-left:4px"></span><?php endif; ?>
                        <?php if ($nNov > 0): ?><span title="<?= $nNov ?> novedad(es) en esta revista"
                              style="background:#fef3c7;color:#92400e;border-radius:9px;padding:1px 7px;font-size:10.5px;font-weight:700;margin-left:5px">⚠️ <?= $nNov ?></span><?php endif; ?>
                    </span>
                    <span class="det-badge <?= e($d['estado']) ?>">
                        <?= $d['estado'] === 'ocupada' ? '✅ Ocupada' : ($d['estado'] === 'vacia' ? '⭕ Vacía' : '❓ Pendiente') ?>
                    </span>
                </h4>

                <a class="editar" href="<?= url('/revistas/editar_detalle?id=' . (int)$d['id']) ?>">✏️ Editar</a>

                <div class="thumb" <?= $urlFoto ? 'onclick="ampliarFoto(\'' . e($urlFoto) . '\')"' : '' ?>>
                    <?php if ($urlFoto): ?>
                        <img src="<?= e($urlFoto) ?>" alt="Foto celda <?= e($d['celda_nombre']) ?>">
                    <?php else: ?>
                        <span style="font-size:32px">📷</span>
                    <?php endif; ?>
                </div>

                <div class="det-info">
                    <?php if ($d['placa_detectada']): ?>
                        <div class="kv"><span>Placa:</span>
                            <span style="font-family:monospace;font-weight:700;font-size:14px"><?= e($d['placa_detectada']) ?></span>
                        </div>
                        <?php
                          // v7.80: se busca el vehículo por 3 vías:
                          //   1. el vínculo guardado en la revista
                          //   2. la placa en la tabla de VEHÍCULOS (aunque esté archivado)
                          //   3. la placa en la tabla de VISITANTES
                          $esVeh       = !empty($d['vehiculo_id']) || !empty($d['veh_encontrado_id']);
                          $esVis       = !$esVeh && !empty($d['vis_encontrado_id']);
                          $vehOk       = $esVeh || $esVis;
                          $regDespues  = empty($d['vehiculo_id']) && $vehOk;
                          $estaArch    = $esVeh && !empty($d['veh_archivado']);
                          $aptoMostrar = $esVis ? ($d['vis_apto'] ?? '') : ($d['vehiculo_apto'] ?? '');
                        ?>
                        <?php if ($vehOk): ?>
                            <div class="kv"><span>Registrado:</span>
    <span>✅ Sí
        <?php if ($esVis): ?>
            <span title="Registrado como visitante"
                  style="background:#ede9fe;color:#5b21b6;border-radius:8px;padding:1px 6px;font-size:10.5px;font-weight:700">👥 visitante</span>
        <?php endif; ?>
        <?php if ($aptoMostrar): ?>
            (<a class="apto-link" data-apto="<?= e($aptoMostrar) ?>" href="<?= url('/consultas?apto=' . urlencode($aptoMostrar)) ?>" title="Ver detalles del apto" style="text-decoration:none; font-weight:600;">apto <?= e($aptoMostrar) ?></a>)
        <?php endif; ?>
        <?php if ($estaArch): ?>
            <span title="El vehículo está archivado"
                  style="background:#f3f4f6;color:#6b7280;border-radius:8px;padding:1px 6px;font-size:10.5px;font-weight:700">📁 archivado</span>
        <?php endif; ?>
        <?php if ($regDespues): ?>
            <span title="Se registró después de esta revista"
                  style="background:#dbeafe;color:#1e40af;border-radius:8px;padding:1px 6px;font-size:10.5px;font-weight:700">registrado luego</span>
        <?php endif; ?>
    </span>
</div>

                        <?php else: ?>
                            <div class="kv"><span>Registrado:</span> <span class="no-placa">⚠️ NO en BD</span></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($d['estado'] !== 'vacia'): ?>
                            <div class="kv"><span>Placa:</span> <span class="t-muted">—</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($d['apto_dueno']): ?>
                        <div class="kv"><span>Apto dueño celda:</span> 
    <span>
        <?php if ($d['apto_dueno']): ?>
            <a class="apto-link" data-apto="<?= e($d['apto_dueno']) ?>" href="<?= url('/consultas?apto=' . urlencode($d['apto_dueno'])) ?>" title="Ver detalles del apto" style="text-decoration:none; font-weight:600;">
                <?= e($d['apto_dueno']) ?>
            </a>
        <?php else: ?>
            <span class="t-muted">—</span>
        <?php endif; ?>
    </span>
</div>

                    <?php endif; ?>
                    <div class="kv"><span>Revisada:</span> <span><?= e(date('d/m H:i', strtotime($d['revisado_en']))) ?></span></div>
                    <?php if ($d['observacion']): ?>
                        <div style="margin-top:6px;padding:6px;background:#f8fafc;border-radius:4px;font-size:12px"><?= e($d['observacion']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal foto ampliada -->
<div class="foto-modal" id="foto-modal" onclick="cerrarFoto()">
    <button class="cerrar" onclick="cerrarFoto()">✕</button>
    <img id="foto-modal-img" src="" alt="">
</div>

<script>
// v7.67: filtro combinado — texto (celda/apto/placa) + estado + morosos
var VER_FILTRO_ESTADO = 'todas';

function verFiltrarEstado(f){
    VER_FILTRO_ESTADO = (VER_FILTRO_ESTADO === f && f !== 'todas') ? 'todas' : f;
    document.querySelectorAll('.ver-filtro').forEach(function(t){
        var act = t.getAttribute('data-f') === VER_FILTRO_ESTADO;
        t.style.outline = act ? '3px solid #1e6cff' : '';
        t.style.outlineOffset = act ? '-3px' : '';
    });
    verFiltrar();
}

function verFiltrar(){
    var fc = (document.getElementById('fVerCelda').value || '').toUpperCase().trim();
    var fp = (document.getElementById('fVerPlaca').value || '').toUpperCase().trim();
    var cards = document.querySelectorAll('.det-card');
    var visibles = 0;
    cards.forEach(function(c){
        var dc = (c.getAttribute('data-celda') || '');
        var dp = (c.getAttribute('data-placa') || '');
        var de = (c.getAttribute('data-estado') || '');
        var dm = (c.getAttribute('data-moroso') || '0');
        var okC = !fc || dc.indexOf(fc) >= 0;
        var okP = !fp || dp.indexOf(fp) >= 0;
        var okE = true;
        if (VER_FILTRO_ESTADO === 'ocupada')        okE = (de === 'ocupada');
        else if (VER_FILTRO_ESTADO === 'vacia')     okE = (de === 'vacia');
        else if (VER_FILTRO_ESTADO === 'pendiente') okE = (de === 'pendiente');
        else if (VER_FILTRO_ESTADO === 'revisadas') okE = (de === 'ocupada' || de === 'vacia');
        else if (VER_FILTRO_ESTADO === 'moroso')    okE = (dm === '1');
        else if (VER_FILTRO_ESTADO === 'novedad')   okE = ((c.getAttribute('data-novedad') || '0') === '1');
        if (okC && okP && okE){ c.style.display=''; visibles++; }
        else { c.style.display='none'; }
    });
    var cnt = document.getElementById('fVerCount');
    var hayFiltro = fc || fp || VER_FILTRO_ESTADO !== 'todas';
    if (cnt) cnt.textContent = hayFiltro ? (visibles + ' celda(s)') : '';
}
function verLimpiar(){
    document.getElementById('fVerCelda').value='';
    document.getElementById('fVerPlaca').value='';
    VER_FILTRO_ESTADO = 'todas';
    document.querySelectorAll('.ver-filtro').forEach(function(t){ t.style.outline=''; });
    verFiltrar();
}

function ampliarFoto(src) {
    document.getElementById('foto-modal-img').src = src;
    document.getElementById('foto-modal').classList.add('mostrar');
}
function cerrarFoto() { document.getElementById('foto-modal').classList.remove('mostrar'); }
</script>
<!-- Estilos base requeridos para el diseño del popover de apartamentos -->
<style>
.apto-link {
    color: #1e40af;
    text-decoration: none;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 1px dotted #93c5fd;
    padding-bottom: 1px;
}
.apto-link:hover {
    color: #1d4ed8;
    border-bottom-style: solid;
    background: #eff6ff;
}
</style>

<!-- Helper nativo del sistema que genera la tarjeta flotante completa -->
<script src="<?= url('/public/js/apto_popover.js') ?>?v=3bh"></script>

<!-- v7.64: botón volver arriba -->
<script>
(function(){
  if (document.getElementById("sp-back-to-top") || window.__SP_UI_INIT) return;
  var b=document.createElement("button");
  b.id="sp-back-to-top"; b.type="button"; b.innerHTML="↑"; b.title="Volver arriba";
  b.style.cssText="position:fixed;bottom:20px;right:20px;width:46px;height:46px;border-radius:50%;background:#1e6cff;color:#fff;border:none;font-size:22px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);display:none;z-index:9998;opacity:.85";
  document.body.appendChild(b);
  b.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"})});
  window.addEventListener("scroll",function(){b.style.display=(window.scrollY>300)?"block":"none"},{passive:true});
})();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
