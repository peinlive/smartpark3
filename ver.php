<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/ver.php
// v3BB: schema real de tu BD (o.tipo, o.usuario_registra) + badges tipo residente
//   Defensivo: si la tabla observaciones_vehiculo tuviera columnas distintas,
//   la sección se oculta pero el resto de la página sigue funcionando.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDES_PATH . '/upload_helpers.php';

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/vehiculos'); }

$st = $pdo->prepare("
    SELECT v.*,
           a.numero_visible AS apto_numero, a.piso, a.estado_morosidad, a.meses_mora, a.bloqueo_comunes,
           t.numero AS torre_numero,
           r.id AS residente_id_link, r.nombre AS residente_nombre,
           r.celular AS residente_celular, r.tipo AS residente_tipo
      FROM vehiculos v
      JOIN apartamentos a ON a.id = v.apartamento_id
      JOIN torres t       ON t.id = a.torre_id
 LEFT JOIN residentes r   ON r.id = v.residente_id
     WHERE v.id = :id AND v.conjunto_id = :c
     LIMIT 1
");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'Vehículo no encontrado.'); redirect('/vehiculos'); }

// ── Celdas del apto (misma logica que el modal de consulta rapida) ──
// UNA query UNION: celda como DUENO + como USUARIO autorizado. Trae tambien
// el numero del apto dueno (util cuando es autorizada). Defensivo por si
// falta alguna tabla.
$celdasApto = [];
try {
    $aptoIdCelda = (int)$v['apartamento_id'];
    $stCeldas = $pdo->prepare(
        "SELECT c.nombre_visible AS codigo, c.tipo AS tipo,
                np.codigo AS nivel, 'dueno' AS relacion, NULL AS tipo_asig,
                ad.numero_visible AS apto_dueno
           FROM celdas c
      LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
      LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
          WHERE c.apto_dueno_id = :ad
         UNION
         SELECT c.nombre_visible AS codigo, c.tipo AS tipo,
                np.codigo AS nivel, 'usuario' AS relacion, ac.tipo AS tipo_asig,
                ad.numero_visible AS apto_dueno
           FROM asignaciones_celdas ac
           JOIN celdas c ON c.id = ac.celda_id
      LEFT JOIN niveles_parqueadero np ON np.id = c.nivel_id
      LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
          WHERE ac.apto_usuario_id = :au
            AND ac.activa = 1
            AND ac.archivado_en IS NULL
       ORDER BY codigo"
    );
    $stCeldas->execute([':ad' => $aptoIdCelda, ':au' => $aptoIdCelda]);
    $celdasApto = $stCeldas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* defensivo */ }

// ── Historial de revistas donde apareció este vehículo ──
$stHist = $pdo->prepare("SELECT rd.id AS rd_id, rd.revista_id, rd.estado,
                                rd.placa_detectada, rd.foto_path, rd.revisado_en, rd.vehiculo_id,
                                r.nivel AS revista_nivel, r.estado AS revista_estado,
                                c.nombre_visible AS celda_nombre
                           FROM revistas_detalle rd
                           JOIN revistas r ON r.id = rd.revista_id
                           JOIN celdas c  ON c.id = rd.celda_id
                          WHERE r.conjunto_id = :c
                            AND (rd.vehiculo_id = :v OR (rd.placa_detectada = :p AND rd.placa_detectada IS NOT NULL))
                       ORDER BY rd.revisado_en DESC
                          LIMIT 20");
$stHist->execute([':c' => $conjuntoId, ':v' => $id, ':p' => $v['placa']]);
$historial = $stHist->fetchAll();

$stCnt = $pdo->prepare("SELECT COUNT(*) FROM revistas_detalle rd
                          JOIN revistas r ON r.id = rd.revista_id
                         WHERE r.conjunto_id = :c
                           AND (rd.vehiculo_id = :v OR (rd.placa_detectada = :p AND rd.placa_detectada IS NOT NULL))");
$stCnt->execute([':c' => $conjuntoId, ':v' => $id, ':p' => $v['placa']]);
$historialTotal = (int)$stCnt->fetchColumn();

// ── v3BB: Novedades / observaciones del vehículo ──
// Schema correcto (verificado por Rafael):
//   observaciones_vehiculo: id, vehiculo_id, tipo, gravedad, descripcion,
//                           evidencia_url, creado_en, usuario_registra
// El conjunto_id se valida por JOIN con vehiculos.
// TODO envuelto en try/catch: si falla, la sección se oculta pero el resto NO se rompe.
$observaciones = [];
$obsError = null;
try {
    $stObs = $pdo->prepare("SELECT o.id, o.tipo, o.gravedad, o.descripcion, o.evidencia_url,
                                  o.creado_en,
                                  u.nombre_completo AS creado_por_nombre
                             FROM observaciones_vehiculo o
                             JOIN vehiculos v ON v.id = o.vehiculo_id
                        LEFT JOIN usuarios u ON u.id = o.usuario_registra
                            WHERE o.vehiculo_id = :v AND v.conjunto_id = :c
                         ORDER BY o.creado_en DESC
                            LIMIT 20");
    $stObs->execute([':v' => $id, ':c' => $conjuntoId]);
    $observaciones = $stObs->fetchAll();
} catch (Exception $e) {
    // Solo en modo debug se muestra el mensaje
    $obsError = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null;
    $observaciones = [];
}

// Evidencias adicionales por observación
$evidenciasPorObs = [];
if (!empty($observaciones)) {
    $obsIds = array_map(function($o){ return (int)$o['id']; }, $observaciones);
    $inList = implode(',', $obsIds);
    try {
        $stEvi = $pdo->query("SELECT observacion_id, archivo_url, tipo
                                FROM observaciones_evidencias
                               WHERE observacion_id IN ($inList)
                            ORDER BY creado_en ASC");
        foreach ($stEvi->fetchAll() as $evi) {
            $evidenciasPorObs[(int)$evi['observacion_id']][] = $evi;
        }
    } catch (Exception $e) { /* tabla opcional */ }
}

$_pageTitle = 'Vehículo ' . $v['placa'];
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">
        <?= $v['tipo'] === 'moto' ? '🏍️' : '🚗' ?> <?= e($v['placa']) ?>
    </h1>
    <p class="page-head__sub">
        Apto <strong><?= e($v['apto_numero']) ?></strong> · Torre <?= (int)$v['torre_numero'] ?> · Piso <?= (int)$v['piso'] ?>
    </p>
</div>

<div class="toolbar">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>

    <?php if (!$v['archivado_en']): ?>
        <?php /* v7.66: ronda puede editar */ ?>
        <a class="btn btn--primary" href="<?= url('/vehiculos/editar?id=' . $id) ?>">✏️ Editar</a>
        <?php if (auth_has_role('super_admin','admin','supervisor','ronda')): /* v7.66 */ ?>
            <a class="btn btn--danger" href="<?= url('/vehiculos/archivar?id=' . $id) ?>">📁 Archivar</a>
        <?php endif; ?>
    <?php else: ?>
        <?php if (auth_has_role('super_admin','admin')): ?>
            <form method="post" action="<?= url('/vehiculos/restaurar') ?>" style="display:inline"
                  onsubmit="return confirm('¿Restaurar este vehículo del archivo?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn">Restaurar del archivo</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <a class="btn" href="<?= url('/vehiculos/historial_revistas?id=' . $id) ?>"
       style="background:#eff6ff;color:#1e40af">
        📋 Historial completo<?= $historialTotal > 0 ? ' (' . $historialTotal . ')' : '' ?>
    </a>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3 class="detail-card__title">Datos del vehículo</h3>
        <?php if (!empty($v['foto_principal'])): ?>
            <img src="<?= e(url_foto($v['foto_principal'])) ?>" alt="Foto" class="detail-photo">
        <?php endif; ?>
        <dl class="detail-list">
            <dt>Placa</dt><dd><strong><?= e($v['placa']) ?></strong></dd>
            <dt>Tipo</dt><dd><?= $v['tipo'] === 'moto' ? 'Moto' : 'Carro' ?></dd>
            <dt>Marca</dt><dd><?= e($v['marca'] ?: '—') ?></dd>
            <dt>Línea</dt><dd><?= e($v['linea'] ?: '—') ?></dd>
            <dt>Color</dt><dd><?= e($v['color'] ?: '—') ?></dd>
            <dt>Año</dt><dd><?= e($v['modelo_anio'] ?: '—') ?></dd>
            <dt>Estado</dt>
            <dd>
                <?php if ($v['archivado_en']): ?>
                    <span class="pill pill--muted">📁 Archivado</span><br>
                    <small class="t-muted">
                        Desde <?= e(fecha_humana($v['archivado_en'])) ?><br>
                        <?php if (!empty($v['archivado_motivo'])): ?>
                            Motivo: <?= e($v['archivado_motivo']) ?>
                        <?php endif; ?>
                    </small>
                <?php else: ?>
                    <span class="pill pill--ok">Activo</span>
                <?php endif; ?>
            </dd>
            <dt>Registrado</dt><dd><?= e(fecha_humana($v['creado_en'])) ?></dd>
        </dl>
        <?php if (!empty($v['observaciones'])): ?>
            <div class="detail-notes">
                <strong>Observaciones:</strong><br><?= nl2br(e($v['observaciones'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="detail-card">
        <h3 class="detail-card__title">Apartamento y residente</h3>
        <dl class="detail-list">
            <dt>Apartamento</dt><dd><?= e($v['apto_numero']) ?></dd>
            <dt>Torre</dt><dd><?= (int)$v['torre_numero'] ?></dd>
            <dt>Piso</dt><dd><?= (int)$v['piso'] ?></dd>
            <dt>Celda</dt>
            <dd>
                <?php if (empty($celdasApto)): ?>
                    <span class="t-muted">Sin celda asignada</span>
                <?php else: foreach ($celdasApto as $cel):
                    $esPropia = ($cel['relacion'] === 'dueno');
                    $asigLabels = ['uso_propio'=>'Uso propio','prestamo_gratis'=>'🤝 Autorizado','alquiler'=>'💰 Alquiler'];
                    $etq = $esPropia ? 'PROPIA' : ($asigLabels[$cel['tipo_asig']] ?? 'Autorizado');
                    $bg  = $esPropia ? '#166534' : '#1e40af';
                ?>
                    <div style="display:inline-block;background:<?= $esPropia?'#dcfce7':'#dbeafe' ?>;border:1px solid <?= $esPropia?'#86efac':'#93c5fd' ?>;border-radius:8px;padding:6px 10px;margin:2px 4px 2px 0;font-size:13px">
                        <b style="font-family:ui-monospace,monospace">🅿️ <?= e($cel['codigo']) ?></b>
                        <span style="background:<?= $bg ?>;color:#fff;font-size:10px;padding:2px 6px;border-radius:8px;margin-left:6px;font-weight:600"><?= e($etq) ?></span>
                        <?php if (!$esPropia && !empty($cel['apto_dueno'])): ?>
                            <br><span style="color:#1e40af;font-size:12px;font-weight:600">Dueño: apto <b style="font-family:ui-monospace,monospace"><?= e($cel['apto_dueno']) ?></b></span>
                        <?php endif; ?>
                        <?php if (!empty($cel['nivel'])): ?>
                            <br><small style="color:#6b7280">Nivel <?= e($cel['nivel']) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </dd>
            <dt>Estado pago</dt>
            <dd>
                <?php if ($v['estado_morosidad'] === 'moroso'): ?>
                    <span class="pill pill--warn">Moroso · <?= (int)$v['meses_mora'] ?> meses</span>
                <?php else: ?>
                    <span class="pill pill--ok">Al día</span>
                <?php endif; ?>
            </dd>
            <?php if ((int)$v['bloqueo_comunes'] === 1): ?>
                <dt>Bloqueo</dt>
                <dd><span class="pill pill--danger">Sin acceso a celdas comunes</span></dd>
            <?php endif; ?>
            <dt>Residente</dt>
            <dd>
                <?php if ($v['residente_nombre']): ?>
                    <a href="<?= url('/residentes/ver?id=' . (int)$v['residente_id_link']) ?>">
                        <?= e($v['residente_nombre']) ?>
                    </a>
                    <?php
                    // v3BA/BB: badge de tipo residente
                    if (!empty($v['residente_tipo'])):
                        $tipoLower = strtolower($v['residente_tipo']);
                        $tipoLabels = [
                            'propietario' => ['🏘️', '#dbeafe', '#1e40af', 'PROPIETARIO'],
                            'inquilino'   => ['🏠', '#fef3c7', '#92400e', 'INQUILINO'],
                            'visitante'   => ['👥', '#e0e7ff', '#4c1d95', 'VISITANTE'],
                            'familiar'    => ['👨‍👩‍👧', '#fce7f3', '#9f1239', 'FAMILIAR'],
                            'otro'        => ['❓', '#f3f4f6', '#374151', 'OTRO'],
                        ];
                        $t = $tipoLabels[$tipoLower] ?? ['👤', '#f3f4f6', '#374151', strtoupper($v['residente_tipo'])];
                    ?>
                        <span style="display:inline-block;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:600;background:<?= $t[1] ?>;color:<?= $t[2] ?>;margin-left:6px;vertical-align:middle">
                            <?= $t[0] ?> <?= e($t[3]) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($v['residente_celular']) && !$esRonda): ?>
                        <br><span class="t-muted"><?= e($v['residente_celular']) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="t-muted">No asignado</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>

<?php // ── v3BB: Novedades / observaciones del vehículo ── ?>
<div style="margin-top:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
        <h2 style="margin:0;font-size:18px;color:#1f2937">
            ⚠️ Novedades / observaciones
            <small style="color:#6b7280;font-weight:500;font-size:13px">
                (<?= count($observaciones) ?>)
            </small>
        </h2>
        <?php if (!$v['archivado_en']): ?>
            <a class="btn btn--sm" href="<?= url('/consultas?q=' . urlencode($v['placa'])) ?>"
               style="background:#fef3c7;color:#92400e">
                + Nueva desde consulta
            </a>
        <?php endif; ?>
    </div>

    <?php if ($obsError): ?>
        <div class="notice notice--warn" style="margin:0;background:#fef3c7;border-left:4px solid #f59e0b;color:#78350f;padding:10px 14px;font-size:12px">
            ⚠️ Aviso técnico (solo se muestra en modo debug): <?= e($obsError) ?>
        </div>
    <?php elseif (empty($observaciones)): ?>
        <div class="notice notice--info" style="margin:0">
            Sin novedades / observaciones registradas para este vehículo.
        </div>
    <?php else: ?>
        <div style="display:grid;gap:10px">
            <?php foreach ($observaciones as $obs):
                $gravColors = [
                    'leve'  => ['#dcfce7','#166534','🟢'],
                    'media' => ['#fef3c7','#92400e','🟡'],
                    'grave' => ['#fee2e2','#991b1b','🔴'],
                ];
                $g = $gravColors[$obs['gravedad'] ?? ''] ?? ['#e5e7eb','#374151','⚪'];
                $obsId = (int)$obs['id'];

                // Todas las fotos: principal + adicionales
                $todasFotos = [];
                if (!empty($obs['evidencia_url'])) {
                    $urlFoto = strpos($obs['evidencia_url'], 'http') === 0 ? $obs['evidencia_url'] : url_foto($obs['evidencia_url']);
                    $todasFotos[] = ['url' => $urlFoto, 'label' => 'Principal'];
                }
                if (!empty($evidenciasPorObs[$obsId])) {
                    foreach ($evidenciasPorObs[$obsId] as $evi) {
                        if (($evi['tipo'] ?? '') === 'foto') {
                            $eurl = strpos($evi['archivo_url'], 'http') === 0 ? $evi['archivo_url'] : url_foto($evi['archivo_url']);
                            $todasFotos[] = ['url' => $eurl, 'label' => 'Adicional'];
                        }
                    }
                }
            ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid <?= $g[1] ?>;border-radius:8px;padding:14px 16px">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
                        <div style="flex:1;min-width:200px">
                            <span style="display:inline-block;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:600;background:#eff6ff;color:#1e40af">
                                <?= e(ucfirst(str_replace('_',' ', $obs['tipo'] ?? 'otro'))) ?>
                            </span>
                            <span style="display:inline-block;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:600;background:<?= $g[0] ?>;color:<?= $g[1] ?>;margin-left:4px">
                                <?= $g[2] ?> Gravedad <?= e($obs['gravedad'] ?? '—') ?>
                            </span>
                        </div>
                        <div style="font-family:monospace;font-size:11px;color:#6b7280;white-space:nowrap">
                            🕐 <?= e(date('d/m/Y H:i:s', strtotime($obs['creado_en']))) ?>
                        </div>
                    </div>
                    <?php if (!empty($obs['descripcion'])): ?>
                        <div style="font-size:14px;color:#111827;line-height:1.5;margin:6px 0 8px">
                            <?= nl2br(e($obs['descripcion'])) ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:#6b7280">
                        🏳️ Registrada por: <strong><?= e($obs['creado_por_nombre'] ?? '—') ?></strong>
                    </div>
                    <?php if (!empty($todasFotos)): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
                            <?php foreach ($todasFotos as $ft): ?>
                                <div onclick="vehAmpliarFoto('<?= e($ft['url']) ?>')"
                                     style="width:110px;height:80px;background:#f3f4f6;border-radius:6px;overflow:hidden;cursor:zoom-in;border:1px solid #e5e7eb"
                                     title="Clic para ampliar">
                                    <img src="<?= e($ft['url']) ?>" alt="<?= e($ft['label']) ?>"
                                         onerror="this.style.display='none';this.parentNode.innerHTML='<span style=\'font-size:10px;color:#9ca3af;padding:8px\'>foto no disp.</span>'"
                                         style="width:100%;height:100%;object-fit:cover">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php // ── v3W.1: Historial de revistas del vehículo ── ?>
<div style="margin-top:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
        <h2 style="margin:0;font-size:18px;color:#1f2937">
            📋 Últimas revistas donde apareció
            <?php if ($historialTotal > 0): ?>
                <small style="color:#6b7280;font-weight:500;font-size:13px">
                    (<?= min(20, $historialTotal) ?> más recientes de <?= $historialTotal ?>)
                </small>
            <?php endif; ?>
        </h2>
        <?php if ($historialTotal > 20): ?>
            <a class="btn btn--sm" href="<?= url('/vehiculos/historial_revistas?id=' . $id) ?>">
                Ver historial completo →
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($historial)): ?>
        <div class="notice notice--info" style="margin:0">
            Este vehículo aún no aparece en ninguna revista de parqueadero. Aparecerá aquí en cuanto
            sea detectado durante una revisión de celdas.
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data-table" style="width:100%">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Revista</th>
                    <th>Nivel</th>
                    <th>Celda</th>
                    <th>Estado</th>
                    <th>Placa detectada</th>
                    <th>Foto</th>
                    <th>Vínculo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial as $h):
                    $urlFoto = $h['foto_path'] ? url('/uploads/revistas/' . $h['foto_path']) : null;
                    $matchVeh = (int)$h['vehiculo_id'] === $id;
                    $estCol = ['ocupada' => ['#dcfce7','#166534','✅ Ocupada'],
                               'vacia'   => ['#fef3c7','#92400e','⭕ Vacía'],
                               'pendiente'=>['#fee2e2','#991b1b','❓ Pendiente']];
                    $ec = $estCol[$h['estado']] ?? ['#e5e7eb','#374151',$h['estado']];
                ?>
                    <tr>
                        <td>
                            <strong><?= e(date('d/m/Y', strtotime($h['revisado_en']))) ?></strong>
                            <br><small class="t-muted"><?= e(date('H:i:s', strtotime($h['revisado_en']))) ?></small>
                        </td>
                        <td>
                            <a href="<?= url('/revistas/ver?id=' . (int)$h['revista_id']) ?>">
                                #<?= (int)$h['revista_id'] ?>
                            </a>
                        </td>
                        <td><strong><?= e($h['revista_nivel']) ?></strong></td>
                        <td><strong style="font-family:monospace"><?= e($h['celda_nombre']) ?></strong></td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?= $ec[0] ?>;color:<?= $ec[1] ?>">
                                <?= $ec[2] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($h['placa_detectada']): ?>
                                <code style="font-weight:700"><?= e($h['placa_detectada']) ?></code>
                            <?php else: ?><span class="t-muted">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($urlFoto): ?>
                                <div onclick="vehAmpliarFoto('<?= e($urlFoto) ?>')"
                                     style="width:56px;height:42px;background:#f3f4f6;border-radius:4px;overflow:hidden;cursor:zoom-in">
                                    <img src="<?= e($urlFoto) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                                </div>
                            <?php else: ?>
                                <span class="t-muted" style="font-size:18px">📷</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($matchVeh): ?>
                                <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">
                                    🔗 vinculado
                                </span>
                            <?php else: ?>
                                <span style="background:#fed7aa;color:#9a3412;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600"
                                      title="La placa coincide pero no estaba vinculado al momento">
                                    🔍 solo placa
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal foto ampliada -->
<div id="veh-foto-modal" onclick="vehCerrarFoto()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:zoom-out">
    <button onclick="vehCerrarFoto()"
            style="position:absolute;top:20px;right:20px;background:rgba(255,255,255,.9);border:none;border-radius:50%;width:44px;height:44px;font-size:22px;cursor:pointer">✕</button>
    <img id="veh-foto-modal-img" src="" alt="" style="max-width:100%;max-height:100%;border-radius:6px">
</div>

<script>
function vehAmpliarFoto(src) {
    document.getElementById('veh-foto-modal-img').src = src;
    document.getElementById('veh-foto-modal').style.display = 'flex';
}
function vehCerrarFoto() {
    document.getElementById('veh-foto-modal').style.display = 'none';
}
</script>

<!-- v3BB -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
