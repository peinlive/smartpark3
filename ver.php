<?php
// /home/myzonaco/smartpark.myzona360.com/modules/observaciones/ver.php
// v1.0 (3AG): Vista completa de una observación con todas sus evidencias.
//   Aditivo: no modifica el módulo /observaciones existente.
//   Se puede enlazar desde /observaciones/index.php agregando un botón:
//     <a class="btn btn--sm" href="<?= url('/observaciones/ver?id=' . $obs['id']) ?>">👁️ Ver</a>

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esSuperAdmin = auth_has_role('super_admin');

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'ID inválido'); redirect('/observaciones'); }

$labelsTipo = [
    'mal_parqueo'  => '🚧 Mal parqueo',
    'advertencia'  => '⚠️ Advertencia',
    'reincidencia' => '🔁 Reincidencia',
    'queja'        => '📢 Queja',
    'otro'         => '📌 Otro',
];

// Cargar observación
$st = $pdo->prepare("SELECT o.*, v.placa, v.tipo AS veh_tipo, v.foto_principal AS veh_foto,
                            a.numero_visible AS apto, a.piso, t.numero AS torre,
                            r.nombre AS residente_nombre, r.celular AS residente_celular,
                            up.nombre_completo AS registrado_por
                       FROM observaciones_vehiculo o
                       JOIN vehiculos v      ON v.id = o.vehiculo_id
                  LEFT JOIN apartamentos a   ON a.id = v.apartamento_id
                  LEFT JOIN torres t         ON t.id = a.torre_id
                  LEFT JOIN residentes r     ON r.id = v.residente_id
                  LEFT JOIN usuarios up      ON up.id = o.usuario_registra
                      WHERE o.id = :id AND v.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$o = $st->fetch();
if (!$o) { flash_set('error', 'Observación no encontrada'); redirect('/observaciones'); }

// Evidencias adicionales
$evidencias = [];
try {
    $stE = $pdo->prepare("SELECT id, tipo, archivo_url, creado_en FROM observaciones_evidencias
                           WHERE observacion_id = :o ORDER BY creado_en ASC");
    $stE->execute([':o' => $id]);
    $evidencias = $stE->fetchAll();
} catch (Exception $ex) { /* tabla no existe */ }

$fotoOcr = $o['evidencia_url'] ? (strpos($o['evidencia_url'], 'http') === 0 ? $o['evidencia_url'] : '/uploads/' . ltrim($o['evidencia_url'], '/')) : null;

$_pageTitle = 'Detalle de observación';
include INCLUDES_PATH . '/header.php';

$colorGrav = $o['gravedad'] === 'grave' ? '#991b1b' : ($o['gravedad'] === 'media' ? '#92400e' : '#166534');
$bgGrav    = $o['gravedad'] === 'grave' ? '#fee2e2' : ($o['gravedad'] === 'media' ? '#fef3c7' : '#dcfce7');
?>

<style>
.obs-view-head{background:linear-gradient(135deg,<?= $colorGrav ?>, #4c1d95);color:#fff;border-radius:10px;padding:18px 22px;margin-top:12px;}
.obs-view-head h1{margin:0;font-size:20px;}
.obs-view-head .meta{margin-top:6px;font-size:12px;opacity:.9;}

.obs-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px 22px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.03);}
.obs-card h3{margin:0 0 10px;font-size:15px;color:#111827;padding-bottom:6px;border-bottom:2px solid #f3f4f6;}

.grav-badge{display:inline-block;padding:6px 14px;background:<?= $bgGrav ?>;color:<?= $colorGrav ?>;border-radius:8px;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;}
.info-item{background:#f8fafc;padding:8px 12px;border-radius:6px;font-size:13px;}
.info-item strong{display:block;color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;}
@media(max-width:600px){.info-grid{grid-template-columns:1fr}}

.evi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-top:10px;}
.evi-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:8px;text-align:center;cursor:zoom-in;transition:transform .1s;}
.evi-card:hover{transform:translateY(-2px);border-color:#0e7490;}
.evi-card img{width:100%;height:120px;object-fit:cover;border-radius:4px;}
.evi-card .label{font-size:10px;color:#6b7280;margin-top:4px;}

.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:zoom-out;}
.lightbox.abierto{display:flex;}
.lightbox img{max-width:100%;max-height:100vh;object-fit:contain;}
.lightbox-close{position:absolute;top:15px;right:20px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:24px;width:40px;height:40px;border-radius:50%;cursor:pointer;}
</style>

<div class="obs-view-head">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <h1>👁️ Detalle de observación #<?= (int)$o['id'] ?></h1>
    <div class="meta">🕐 <?= e(date('d/m/Y H:i:s', strtotime($o['creado_en']))) ?>
        <?php if ($o['registrado_por']): ?> · Registrada por: <strong><?= e($o['registrado_por']) ?></strong><?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/observaciones') ?>">← Volver a observaciones</a>
    <a class="btn" href="<?= url('/vehiculos/ver?id=' . (int)$o['vehiculo_id']) ?>">🚗 Ver vehículo</a>
    <button type="button" class="btn" onclick="window.print()">🖨️ Imprimir</button>
</div>

<div class="obs-card">
    <h3>📋 Datos del vehículo y apartamento</h3>
    <div class="info-grid">
        <div class="info-item"><strong>Placa</strong><span style="font-family:monospace;font-size:16px;font-weight:700"><?= e($o['placa']) ?></span></div>
        <div class="info-item"><strong>Tipo</strong><?= $o['veh_tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></div>
        <div class="info-item"><strong>Apartamento</strong><?= e($o['apto'] ?: '—') ?> <span class="t-muted">(Torre <?= (int)$o['torre'] ?>)</span></div>
        <div class="info-item"><strong>Residente</strong><?= e($o['residente_nombre'] ?: '—') ?>
            <?php if ($o['residente_celular']): ?><br><small>📞 <?= e($o['residente_celular']) ?></small><?php endif; ?>
        </div>
    </div>
</div>

<div class="obs-card">
    <h3>⚠️ Observación</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <span class="grav-badge"><?= e($labelsTipo[$o['tipo']] ?? $o['tipo']) ?></span>
        <span class="grav-badge">Gravedad: <?= strtoupper(e($o['gravedad'])) ?></span>
    </div>
    <div style="background:#f8fafc;padding:14px;border-radius:6px;font-size:14px;color:#1f2937;white-space:pre-wrap;line-height:1.6">
        <?= e($o['descripcion']) ?>
    </div>
</div>

<?php
$todasEvi = [];
if ($fotoOcr) $todasEvi[] = ['url' => $fotoOcr, 'tipo' => 'foto', 'label' => 'Foto principal'];
foreach ($evidencias as $e) {
    $todasEvi[] = [
        'url'   => strpos($e['archivo_url'], 'http') === 0 ? $e['archivo_url'] : '/uploads/' . ltrim($e['archivo_url'], '/'),
        'tipo'  => $e['tipo'],
        'label' => 'Adicional · ' . date('d/m/Y H:i', strtotime($e['creado_en'])),
    ];
}
?>

<div class="obs-card">
    <h3>📎 Evidencias (<?= count($todasEvi) ?>)</h3>
    <?php if (empty($todasEvi)): ?>
        <div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px">Sin evidencias adjuntas para esta observación.</div>
    <?php else: ?>
        <div class="evi-grid">
            <?php foreach ($todasEvi as $ev): ?>
                <?php if ($ev['tipo'] === 'foto'): ?>
                    <div class="evi-card" onclick="obsAbrirLightbox('<?= e($ev['url']) ?>', '<?= e($ev['label']) ?>')">
                        <img src="<?= e($ev['url']) ?>" alt="<?= e($ev['label']) ?>" onerror="this.style.display='none'">
                        <div class="label">📸 <?= e($ev['label']) ?></div>
                    </div>
                <?php else: ?>
                    <a href="<?= e($ev['url']) ?>" target="_blank" class="evi-card" style="display:flex;flex-direction:column;justify-content:center;text-decoration:none;color:inherit">
                        <div style="font-size:48px">🎬</div>
                        <div class="label">Video · <?= e($ev['label']) ?></div>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="lightbox" id="obs-lightbox" onclick="obsCerrarLightbox()">
    <img id="obs-lightbox-img" src="" alt="">
    <div id="obs-lightbox-label" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.7);color:#fff;padding:8px 16px;border-radius:6px;font-family:monospace;font-size:13px"></div>
    <button class="lightbox-close" onclick="obsCerrarLightbox()">✕</button>
</div>

<script>
function obsAbrirLightbox(url, label) {
    document.getElementById('obs-lightbox-img').src = url;
    document.getElementById('obs-lightbox-label').textContent = label || '';
    document.getElementById('obs-lightbox').classList.add('abierto');
}
function obsCerrarLightbox() {
    document.getElementById('obs-lightbox').classList.remove('abierto');
    document.getElementById('obs-lightbox-img').src = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') obsCerrarLightbox(); });
</script>

<style media="print">
    .toolbar, .lightbox, header, footer, .sidebar { display:none !important; }
    .obs-view-head { background:#4c1d95 !important; -webkit-print-color-adjust:exact; }
    .evi-card img { max-height:200px; }
</style>

<?php include INCLUDES_PATH . '/footer.php'; ?>
