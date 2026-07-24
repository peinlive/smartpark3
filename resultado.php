<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/resultado.php

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

if (session_status() === PHP_SESSION_NONE) session_start();
$res = $_SESSION['import_result'] ?? null;
if (!$res) redirect('/importaciones');

$tipo = $res['tipo'];
$_pageTitle = 'Resultado de importación';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">Importación finalizada</h1>
    <p class="page-head__sub">Tipo: <?= e($tipo) ?></p>
</div>

<div class="cards">
    <div class="card card--accent">
        <div class="card__label">Total filas</div>
        <div class="card__value"><?= (int)$res['total'] ?></div>
    </div>
    <div class="card">
        <div class="card__label">Importadas</div>
        <div class="card__value"><?= (int)$res['totales']['ok'] ?></div>
    </div>
    <?php if (!empty($res['totales']['actualizados'])): ?>
    <div class="card">
        <div class="card__label">Actualizadas</div>
        <div class="card__value"><?= (int)$res['totales']['actualizados'] ?></div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card__label">Duplicadas</div>
        <div class="card__value"><?= (int)($res['totales']['duplicado'] ?? 0) ?></div>
    </div>
    <div class="card <?= ((int)$res['totales']['error']>0?'card--warn':'') ?>">
        <div class="card__label">Con errores</div>
        <div class="card__value"><?= (int)$res['totales']['error'] ?></div>
    </div>
</div>

<?php if (!empty($res['errores'])): ?>
    <div class="detail-card detail-card--full">
        <h3 class="detail-card__title">Filas con errores</h3>
        <p class="t-muted">No se importaron. Corrígelas en el Excel y vuelve a subir.</p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fila</th>
                    <th>Apto</th>
                    <?php if ($tipo === 'residentes'): ?>
                        <th>Nombre</th>
                    <?php else: ?>
                        <th>Placa</th>
                    <?php endif; ?>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($res['errores'] as $err): ?>
                <tr>
                    <td><?= (int)$err['linea'] ?></td>
                    <td><?= e($err['apto'] ?? '') ?></td>
                    <td><?= e($err['nombre'] ?? $err['placa'] ?? '') ?></td>
                    <td class="t-error"><?= e($err['motivo']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="toolbar" style="margin-top:24px">
    <a class="btn btn--primary" href="<?= url('/' . $tipo) ?>">Ver <?= e($tipo) ?></a>
    <a class="btn" href="<?= url('/importaciones/nueva?tipo=' . $tipo) ?>">Otra importación</a>
    <a class="btn" href="<?= url('/importaciones') ?>">Histórico</a>
</div>

<?php
unset($_SESSION['import_result']);
include INCLUDES_PATH . '/footer.php';
?>
