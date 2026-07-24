<?php
// /home/myzonaco/smartpark.myzona360.com/modules/rondas/ver.php
// Ver detalle de revista: grid de celdas con su estado + lecturas + acciones.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
require_once INCLUDES_PATH . '/upload_helpers.php';
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/rondas'); }

$st = $pdo->prepare("
    SELECT r.*, us.nombre_completo AS usuario_nombre
      FROM revistas r LEFT JOIN usuarios us ON us.id = r.usuario_id
     WHERE r.id = :id AND r.conjunto_id = :c");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$revista = $st->fetch();
if (!$revista) { flash_set('error', 'No encontrada.'); redirect('/rondas'); }

// Cargar todas las celdas del nivel + su lectura en esta revista
$celdas = $pdo->prepare("
    SELECT c.id, c.numero_celda, c.es_privada, c.asignada_a_apto, c.orden,
           l.id AS lectura_id, l.placa_detectada, l.confidence, l.celda_vacia,
           l.foto_path, l.tipo_resultado, l.placa_manual, l.creado_en AS lectura_at,
           CASE
              WHEN l.vehiculo_id IS NOT NULL THEN va.numero_visible
              WHEN l.visitante_id IS NOT NULL THEN via2.numero_visible
              ELSE NULL
           END AS apto_match
      FROM parqueadero_celdas c
 LEFT JOIN lecturas_placas l ON l.revista_id = :r AND l.celda = c.numero_celda
 LEFT JOIN vehiculos v ON v.id = l.vehiculo_id
 LEFT JOIN apartamentos va ON va.id = v.apartamento_id
 LEFT JOIN visitantes_vehiculos vi ON vi.id = l.visitante_id
 LEFT JOIN apartamentos via2 ON via2.id = vi.apartamento_id
     WHERE c.conjunto_id = :c AND c.nivel = :n
  ORDER BY c.orden, c.numero_celda
");
$celdas->execute([':r' => $id, ':c' => $conjuntoId, ':n' => $revista['nivel']]);
$celdas = $celdas->fetchAll();

$dur = '';
if ($revista['terminado_en']) {
    $dur = round((strtotime($revista['terminado_en']) - strtotime($revista['iniciado_en'])) / 60) . ' min';
} elseif ($revista['estado'] === 'en_curso') {
    $dur = round((time() - strtotime($revista['iniciado_en'])) / 60) . ' min (en curso)';
}

$_pageTitle = 'Revista ' . $revista['nivel'];
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">🌙 Revista <?= e($revista['nivel']) ?> #<?= $id ?></h1>
    <p class="page-head__sub">
        <?= e(fecha_humana($revista['iniciado_en'])) ?> · por <?= e($revista['usuario_nombre'] ?? '—') ?> · <?= e($dur) ?>
    </p>
</div>

<?php if (!empty($_GET['completa'])): ?>
    <div class="flash flash--ok">✓ ¡Revista terminada exitosamente!</div>
<?php endif; ?>

<div class="cards">
    <div class="card card--accent">
        <div class="card__label">Total celdas</div>
        <div class="card__value"><?= (int)$revista['total_celdas'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">Revisadas</div>
        <div class="card__value"><?= (int)$revista['celdas_revisadas'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">🚗 Ocupadas</div>
        <div class="card__value"><?= (int)$revista['celdas_ocupadas'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">🅿️ Vacías</div>
        <div class="card__value"><?= (int)$revista['celdas_vacias'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">Estado</div>
        <div class="card__value" style="font-size:16px">
            <?php if ($revista['estado'] === 'en_curso'): ?><span class="pill pill--warn">🔄 En curso</span>
            <?php elseif ($revista['estado'] === 'terminada'): ?><span class="pill pill--ok">✓ Terminada</span>
            <?php else: ?><span class="pill pill--muted">✗ Cancelada</span><?php endif; ?>
        </div>
    </div>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/rondas') ?>">← Volver al listado</a>
    <?php if ($revista['estado'] === 'en_curso' && (int)$revista['usuario_id'] === (int)$u['id']): ?>
        <a class="btn btn--primary" href="<?= url('/rondas/ejecutar?id=' . $id) ?>">↪ Continuar revista</a>
        <form method="post" action="<?= url('/rondas/terminar') ?>" style="display:inline"
              onsubmit="return confirm('¿Terminar ahora? Las celdas no revisadas quedarán pendientes.');">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn--primary">✓ Terminar revista</button>
        </form>
    <?php endif; ?>
</div>

<h2 style="margin-top:24px;font-size:16px">Mapa de celdas</h2>
<div class="celdas-grid">
    <?php foreach ($celdas as $c):
        $clase = 'is-pendiente';
        if (!empty($c['lectura_id'])) {
            if ((int)$c['celda_vacia'] === 1) $clase = 'is-vacia';
            elseif ($c['tipo_resultado'] === 'residente') $clase = 'is-residente';
            elseif ($c['tipo_resultado'] === 'visitante') $clase = 'is-visitante';
            else $clase = 'is-desconocido';
        }
    ?>
        <div class="celda-card <?= $clase ?>" <?php if (!empty($c['lectura_id'])): ?>
             onclick="mostrarFoto('<?= e(url_foto($c['foto_path'])) ?>', '<?= e($c['placa_detectada']) ?>', '<?= e($c['numero_celda']) ?>')"
             style="cursor:pointer"
        <?php endif; ?>>
            <div class="celda-num"><?= e($c['numero_celda']) ?></div>
            <?php if ((int)$c['es_privada'] === 1): ?>
                <div class="celda-tag">🔒</div>
            <?php endif; ?>
            <?php if (!empty($c['lectura_id'])): ?>
                <?php if ((int)$c['celda_vacia'] === 1): ?>
                    <div class="celda-plate">VACÍA</div>
                <?php else: ?>
                    <div class="celda-plate"><?= e($c['placa_detectada']) ?></div>
                    <?php if ($c['apto_match']): ?>
                        <div class="celda-apto">Apto <?= e($c['apto_match']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="celda-plate">pendiente</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal foto -->
<div id="fotoModal" class="foto-modal" onclick="cerrarFoto()">
    <div class="foto-modal-content">
        <h3 id="fotoModalTitle"></h3>
        <img id="fotoModalImg" alt="">
    </div>
</div>

<style>
.celdas-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(120px, 1fr));
    gap:8px;margin-top:12px;}
.celda-card{background:#fff;border:2px solid var(--color-border);border-radius:8px;
    padding:10px 8px;text-align:center;font-size:13px;transition:transform .1s;position:relative;}
.celda-card:hover{transform:translateY(-2px);}
.celda-num{font-weight:700;font-size:18px;}
.celda-plate{margin-top:6px;font-weight:600;font-size:13px;letter-spacing:1px;}
.celda-apto{font-size:11px;color:var(--color-muted);margin-top:2px;}
.celda-tag{position:absolute;top:4px;right:4px;font-size:11px;}

.celda-card.is-pendiente{background:#f9fafb;border-color:#e5e7eb;color:#9ca3af;}
.celda-card.is-residente{background:#dbeafe;border-color:#60a5fa;}
.celda-card.is-residente .celda-plate{color:#1e3a8a;}
.celda-card.is-visitante{background:#fef3c7;border-color:#fbbf24;}
.celda-card.is-visitante .celda-plate{color:#92400e;}
.celda-card.is-desconocido{background:#fee2e2;border-color:#fca5a5;}
.celda-card.is-desconocido .celda-plate{color:#991b1b;}
.celda-card.is-vacia{background:#f3f4f6;border-color:#d1d5db;}
.celda-card.is-vacia .celda-plate{color:#6b7280;}

.foto-modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;
    background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:pointer;}
.foto-modal.is-open{display:flex;}
.foto-modal-content{background:#fff;border-radius:12px;padding:20px;max-width:600px;width:100%;text-align:center;}
.foto-modal-content h3{margin:0 0 12px;}
.foto-modal-content img{max-width:100%;max-height:70vh;border-radius:8px;}
</style>

<script>
function mostrarFoto(url, placa, celda) {
    if (!url) return;
    document.getElementById('fotoModalImg').src = url;
    document.getElementById('fotoModalTitle').textContent = 'Celda ' + celda + ' · ' + placa;
    document.getElementById('fotoModal').classList.add('is-open');
}
function cerrarFoto() { document.getElementById('fotoModal').classList.remove('is-open'); }
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
