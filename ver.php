<?php
// /home/myzonaco/smartpark.myzona360.com/modules/residentes/ver.php
// Detalle de residente + listado de sus vehículos.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

$id = clean_int($_GET['id'] ?? null, 1);
if (!$id) {
    flash_set('error', 'ID inválido.');
    redirect('/residentes');
}

$st = $pdo->prepare("
    SELECT r.*, a.numero_visible AS apto_numero, a.piso, t.numero AS torre_numero
      FROM residentes r
      JOIN apartamentos a ON a.id = r.apartamento_id
      JOIN torres t       ON t.id = a.torre_id
     WHERE r.id = :id AND a.conjunto_id = :c
     LIMIT 1
");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$r = $st->fetch();
if (!$r) {
    flash_set('error', 'Residente no encontrado.');
    redirect('/residentes');
}

// Vehículos del residente
$veh = $pdo->prepare("
    SELECT id, placa, tipo, marca, color, archivado_en
      FROM vehiculos
     WHERE residente_id = :r
  ORDER BY archivado_en IS NULL DESC, placa
");
$veh->execute([':r' => $id]);
$vehiculos = $veh->fetchAll();

// Otros residentes del mismo apto
$otros = $pdo->prepare("
    SELECT id, nombre, tipo, vive_en_apto, archivado_en
      FROM residentes
     WHERE apartamento_id = :a AND id <> :id
  ORDER BY archivado_en IS NULL DESC, tipo, nombre
");
$otros->execute([':a' => $r['apartamento_id'], ':id' => $id]);
$otros = $otros->fetchAll();

$_pageTitle = $r['nombre'];
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title"><?= e($r['nombre']) ?></h1>
    <p class="page-head__sub">
        Apto <strong><?= e($r['apto_numero']) ?></strong> · Torre <?= (int)$r['torre_numero'] ?> · Piso <?= (int)$r['piso'] ?>
    </p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/residentes') ?>">← Volver</a>

    <?php if (!$r['archivado_en']): ?>
        <a class="btn btn--primary" href="<?= url('/residentes/editar?id=' . $id) ?>">✏️ Editar</a>

        <?php if (auth_has_role('super_admin','admin','supervisor')): ?>
            <a class="btn btn--danger" href="<?= url('/residentes/mudanza?id=' . $id) ?>">
                🚚 Registrar mudanza
            </a>
        <?php endif; ?>
    <?php else: ?>
        <?php if (auth_has_role('super_admin','admin')): ?>
            <form method="post" action="<?= url('/residentes/restaurar') ?>" style="display:inline"
                  onsubmit="return confirm('¿Restaurar este residente y sacarlo del archivo?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn">Restaurar del archivo</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <h3 class="detail-card__title">Datos personales</h3>
        <dl class="detail-list">
            <dt>Nombre</dt><dd><?= e($r['nombre']) ?></dd>
            <dt>Tipo</dt>
            <dd>
                <?php if ($r['tipo'] === 'propietario'): ?>
                    <span class="pill pill--info">Propietario</span>
                <?php elseif ($r['tipo'] === 'inquilino'): ?>
                    <span class="pill pill--ok">Inquilino</span>
                <?php elseif ($r['tipo'] === 'familiar'): ?>
                    <span class="pill pill--muted">Familiar</span>
                <?php else: ?>
                    <span class="pill pill--muted">Otro</span>
                <?php endif; ?>
            </dd>
            <?php if (!$esRonda): ?>
            <dt>Celular</dt><dd><?= e($r['celular'] ?: '—') ?></dd>
            <?php endif; ?>
            <dt>Documento</dt><dd><?= e($r['documento'] ?: '—') ?></dd>
            <dt>Email</dt><dd><?= e($r['email'] ?: '—') ?></dd>
            <dt>Estado</dt>
            <dd>
                <?php if ($r['archivado_en']): ?>
                    <span class="pill pill--muted">📁 Archivado</span><br>
                    <small class="t-muted">
                        Desde <?= e(fecha_humana($r['archivado_en'])) ?><br>
                        <?php if (!empty($r['archivado_motivo'])): ?>
                            Motivo: <?= e($r['archivado_motivo']) ?>
                        <?php endif; ?>
                    </small>
                <?php elseif ((int)$r['vive_en_apto'] === 0): ?>
                    <span class="pill pill--warn">🏠 Propietario que no vive aquí</span>
                <?php else: ?>
                    <span class="pill pill--ok">Activo · Vive en el apto</span>
                <?php endif; ?>
            </dd>
            <dt>Registrado</dt><dd><?= e(fecha_humana($r['creado_en'])) ?></dd>
        </dl>
    </div>

    <div class="detail-card">
        <h3 class="detail-card__title">Otros residentes del apto</h3>
        <?php if (empty($otros)): ?>
            <p class="t-muted">Sin otros residentes.</p>
        <?php else: ?>
            <ul style="list-style:none;padding:0;margin:0">
            <?php foreach ($otros as $o): ?>
                <li style="padding:6px 0;border-bottom:1px solid var(--color-border)">
                    <a href="<?= url('/residentes/ver?id=' . (int)$o['id']) ?>"><?= e($o['nombre']) ?></a>
                    · <span class="t-muted"><?= e($o['tipo']) ?></span>
                    <?php if ($o['archivado_en']): ?>
                        <span class="pill pill--muted">archivado</span>
                    <?php elseif ((int)$o['vive_en_apto'] === 0): ?>
                        <span class="pill pill--warn">no vive aquí</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="detail-card detail-card--full">
    <h3 class="detail-card__title">Vehículos asociados (<?= count($vehiculos) ?>)</h3>
    <?php if (empty($vehiculos)): ?>
        <p class="t-muted">Sin vehículos registrados a nombre de este residente.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr><th>Placa</th><th>Tipo</th><th>Marca/Color</th><th>Estado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($vehiculos as $v): ?>
                    <tr>
                        <td><strong><?= e($v['placa']) ?></strong></td>
                        <td><?= $v['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></td>
                        <td><?= e(trim(($v['marca'] ?? '') . ' ' . ($v['color'] ?? ''))) ?: '—' ?></td>
                        <td>
                            <?php if ($v['archivado_en']): ?>
                                <span class="pill pill--muted">Archivado</span>
                            <?php else: ?>
                                <span class="pill pill--ok">Activo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
