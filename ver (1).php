<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/ver.php

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
require_once INCLUDES_PATH . '/upload_helpers.php';
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db(); $u = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/visitantes'); }

$st = $pdo->prepare("
    SELECT v.*, a.numero_visible AS apto_numero, a.piso, t.numero AS torre_numero
      FROM visitantes_vehiculos v
      JOIN apartamentos a ON a.id = v.apartamento_id
      JOIN torres t ON t.id = a.torre_id
     WHERE v.id = :id AND v.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$v = $st->fetch();
if (!$v) { flash_set('error', 'No encontrado.'); redirect('/visitantes'); }

$_pageTitle = 'Visitante ' . $v['placa'];
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title"><?= $v['tipo'] === 'moto' ? '🏍️' : '🚗' ?> <?= e($v['placa']) ?> (visitante)</h1>
    <p class="page-head__sub">Visita a apto <strong><?= e($v['apto_numero']) ?></strong> · Torre <?= (int)$v['torre_numero'] ?></p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/visitantes') ?>">← Volver</a>
    <?php if (!$v['archivado_en']): ?>
        <a class="btn btn--primary" href="<?= url('/visitantes/editar?id=' . $id) ?>">✏️ Editar</a>
        <form method="post" action="<?= url('/visitantes/visita_mas') ?>" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn">+1 visita</button>
        </form>
        <?php if (auth_has_role('super_admin','admin','supervisor')): ?>
            <a class="btn btn--danger" href="<?= url('/visitantes/archivar?id=' . $id) ?>">📁 Archivar</a>
        <?php endif; ?>
    <?php else: ?>
        <?php if (auth_has_role('super_admin','admin')): ?>
            <form method="post" action="<?= url('/visitantes/restaurar') ?>" style="display:inline"
                  onsubmit="return confirm('¿Restaurar?');">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn">Restaurar</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3 class="detail-card__title">Datos del visitante</h3>
        <?php if (!empty($v['foto_principal'])): ?>
            <img src="<?= e(url_foto($v['foto_principal'])) ?>" alt="Foto" class="detail-photo">
        <?php endif; ?>
        <dl class="detail-list">
            <dt>Placa</dt><dd><strong><?= e($v['placa']) ?></strong></dd>
            <dt>Tipo</dt><dd><?= $v['tipo'] === 'moto' ? 'Moto' : 'Carro' ?></dd>
            <dt>Nombre</dt><dd><?= e($v['nombre_visitante'] ?: '—') ?></dd>
            <dt>Parentesco</dt><dd><?= e($v['parentesco'] ?: '—') ?></dd>
            <dt>Celular</dt><dd><?= e($v['celular'] ?: '—') ?></dd>
            <dt>Marca</dt><dd><?= e($v['marca'] ?: '—') ?></dd>
            <dt>Color</dt><dd><?= e($v['color'] ?: '—') ?></dd>
            <dt>Estado</dt><dd>
                <?php if ($v['archivado_en']): ?><span class="pill pill--muted">📁 Archivado</span>
                <?php elseif ((int)$v['recurrente'] === 1): ?><span class="pill pill--info">⭐ Recurrente</span>
                <?php else: ?><span class="pill pill--ok">Activo</span><?php endif; ?>
            </dd>
        </dl>
    </div>
    <div class="detail-card">
        <h3 class="detail-card__title">Historial de visitas</h3>
        <dl class="detail-list">
            <dt>Total visitas</dt><dd><strong style="font-size:24px"><?= (int)$v['visitas_count'] ?></strong></dd>
            <dt>Primera visita</dt><dd><?= e(fecha_humana($v['primera_visita'])) ?></dd>
            <dt>Última visita</dt><dd><?= e(fecha_humana($v['ultima_visita'])) ?></dd>
            <dt>Apto</dt><dd><?= e($v['apto_numero']) ?> (T<?= (int)$v['torre_numero'] ?>)</dd>
        </dl>
        <?php if (!empty($v['observaciones'])): ?>
            <div class="detail-notes"><strong>Observaciones:</strong><br><?= nl2br(e($v['observaciones'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
