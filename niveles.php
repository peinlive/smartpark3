<?php
// /home/myzonaco/smartpark.myzona360.com/modules/parqueadero/niveles.php
// v3n: gestión de niveles del parqueadero (sótanos, pisos, terraza).
//      Lista + crear inline + editar + activar/desactivar.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$errores = [];

// ───── POST: crear / editar / toggle activo / eliminar ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF flexible
    if (empty($_POST['_csrf'])) {
        if (!empty($_POST['csrf_token'])) $_POST['_csrf'] = $_POST['csrf_token'];
        elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['_csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    csrf_require();

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $codigo  = clean_string($_POST['codigo']  ?? '', 20);
        $nombre  = clean_string($_POST['nombre']  ?? '', 80);
        $tipo    = in_array($_POST['tipo'] ?? '', ['subterraneo','superficie','terraza'], true) ? $_POST['tipo'] : 'subterraneo';
        $orden   = max(1, (int)($_POST['orden'] ?? 1));
        $esVisit = !empty($_POST['es_visitante']) ? 1 : 0;

        if ($codigo === '') $errores[] = 'El código es obligatorio.';
        if (empty($errores)) {
            try {
                $ins = $pdo->prepare("INSERT INTO niveles_parqueadero
                        (conjunto_id, orden, codigo, nombre, tipo, es_visitante, activo)
                    VALUES (:c, :o, :cd, :n, :t, :v, 1)");
                $ins->execute([
                    ':c'  => $conjuntoId, ':o' => $orden, ':cd' => $codigo,
                    ':n'  => $nombre ?: null, ':t' => $tipo, ':v' => $esVisit,
                ]);
                if (function_exists('flash_set')) flash_set('ok', "Nivel '$codigo' creado.");
                redirect('/parqueadero/niveles');
            } catch (Exception $e) {
                $errores[] = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? "Ya existe un nivel con el código '$codigo' o el orden $orden."
                    : (APP_DEBUG ? $e->getMessage() : 'Error al crear.');
            }
        }
    }
    elseif ($accion === 'editar') {
        $id      = (int)($_POST['id'] ?? 0);
        $codigo  = clean_string($_POST['codigo']  ?? '', 20);
        $nombre  = clean_string($_POST['nombre']  ?? '', 80);
        $tipo    = in_array($_POST['tipo'] ?? '', ['subterraneo','superficie','terraza'], true) ? $_POST['tipo'] : 'subterraneo';
        $orden   = max(1, (int)($_POST['orden'] ?? 1));
        $esVisit = !empty($_POST['es_visitante']) ? 1 : 0;

        if ($id < 1)        $errores[] = 'ID inválido.';
        if ($codigo === '') $errores[] = 'El código es obligatorio.';

        if (empty($errores)) {
            try {
                $up = $pdo->prepare("UPDATE niveles_parqueadero
                        SET orden = :o, codigo = :cd, nombre = :n, tipo = :t, es_visitante = :v
                        WHERE id = :id AND conjunto_id = :c");
                $up->execute([
                    ':o'  => $orden, ':cd' => $codigo, ':n' => $nombre ?: null,
                    ':t'  => $tipo, ':v' => $esVisit,
                    ':id' => $id, ':c' => $conjuntoId,
                ]);
                if (function_exists('flash_set')) flash_set('ok', "Nivel actualizado.");
                redirect('/parqueadero/niveles');
            } catch (Exception $e) {
                $errores[] = APP_DEBUG ? $e->getMessage() : 'Error al actualizar.';
            }
        }
    }
    elseif ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id >= 1) {
            $pdo->prepare("UPDATE niveles_parqueadero
                            SET activo = 1 - activo
                          WHERE id = :id AND conjunto_id = :c")
                ->execute([':id' => $id, ':c' => $conjuntoId]);
            if (function_exists('flash_set')) flash_set('ok', 'Estado del nivel cambiado.');
        }
        redirect('/parqueadero/niveles');
    }
    elseif ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id >= 1) {
            try {
                $del = $pdo->prepare("DELETE FROM niveles_parqueadero
                                       WHERE id = :id AND conjunto_id = :c");
                $del->execute([':id' => $id, ':c' => $conjuntoId]);
                if (function_exists('flash_set')) flash_set('ok', 'Nivel eliminado.');
            } catch (Exception $e) {
                // Probable FK constraint (hay celdas asignadas a este nivel)
                if (function_exists('flash_set')) flash_set('error',
                    'No se puede eliminar este nivel porque tiene celdas asignadas. Primero elimina o reasigna esas celdas.');
            }
        }
        redirect('/parqueadero/niveles');
    }
}

// ───── GET: listar y mostrar form ─────
$st = $pdo->prepare("SELECT n.*,
                            (SELECT COUNT(*) FROM celdas c WHERE c.nivel_id = n.id) AS num_celdas
                       FROM niveles_parqueadero n
                      WHERE n.conjunto_id = :c
                      ORDER BY n.orden ASC, n.codigo ASC");
$st->execute([':c' => $conjuntoId]);
$niveles = $st->fetchAll();

// Cargar nivel a editar si se pidió ?editar=ID
$nivelEdit = null;
$editarId  = (int)($_GET['editar'] ?? 0);
if ($editarId > 0) {
    $st2 = $pdo->prepare("SELECT * FROM niveles_parqueadero WHERE id = :id AND conjunto_id = :c LIMIT 1");
    $st2->execute([':id' => $editarId, ':c' => $conjuntoId]);
    $nivelEdit = $st2->fetch();
}

$_pageTitle = 'Niveles del parqueadero';
include INCLUDES_PATH . '/header.php';
?>

<style>
.niveles-grid{display:grid;grid-template-columns:1fr 360px;gap:24px;margin-top:12px;}
@media (max-width:900px){.niveles-grid{grid-template-columns:1fr;}}
.niveles-form{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:18px;align-self:start;position:sticky;top:80px;}
.niveles-form h3{margin:0 0 12px 0;font-size:15px;}
.niveles-form .form-row{margin-bottom:10px;}
.niveles-form label{display:block;font-size:12px;color:#374151;margin-bottom:3px;font-weight:500;}
.niveles-form input,.niveles-form select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;}
.niveles-form .form-grid{display:grid;grid-template-columns:90px 1fr;gap:8px;}
.niveles-form .btn-row{display:flex;gap:8px;margin-top:10px;}
.pill--ok{background:#dcfce7;color:#166534;}
.pill--off{background:#f3f4f6;color:#6b7280;}
.tipo-icon{font-size:16px;margin-right:4px;}
.acciones-fila{display:inline-flex;gap:4px;}
</style>

<div class="page-head">
    <h1 class="page-head__title">Niveles del parqueadero</h1>
    <p class="page-head__sub">Sótanos, pisos y terraza. <?= count($niveles) ?> nivel(es).</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/parqueadero') ?>">← Volver a celdas</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="niveles-grid">
    <!-- Lista de niveles -->
    <div>
    <?php if (empty($niveles)): ?>
        <div class="notice notice--info">Aún no hay niveles. Crea el primero en el formulario de la derecha.</div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Orden</th>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Visitantes</th>
                    <th>Celdas</th>
                    <th>Estado</th>
                    <th class="t-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($niveles as $n):
                $iconos = ['subterraneo' => '🅿️', 'superficie' => '🏠', 'terraza' => '🌤️'];
                $icon = $iconos[$n['tipo']] ?? '📍';
            ?>
                <tr>
                    <td><?= (int)$n['orden'] ?></td>
                    <td><strong><?= e($n['codigo']) ?></strong></td>
                    <td><?= e($n['nombre'] ?: '—') ?></td>
                    <td><span class="tipo-icon"><?= $icon ?></span><?= e(ucfirst($n['tipo'])) ?></td>
                    <td><?= (int)$n['es_visitante'] === 1 ? '👋 Sí' : '—' ?></td>
                    <td><?= (int)$n['num_celdas'] ?></td>
                    <td>
                        <?php if ((int)$n['activo'] === 1): ?>
                            <span class="pill pill--ok">Activo</span>
                        <?php else: ?>
                            <span class="pill pill--off">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="t-right">
                        <div class="acciones-fila">
                            <a class="btn btn--sm" href="<?= url('/parqueadero/niveles?editar=' . (int)$n['id']) ?>">✏️</a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('¿Cambiar estado activo/inactivo?');">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="btn btn--sm">🔄</button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('⚠️ ¿Eliminar PERMANENTEMENTE este nivel?\n\nSi tiene celdas asignadas, fallará.');">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" class="btn btn--sm" style="background:#fee2e2;color:#991b1b">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>

    <!-- Form crear/editar -->
    <form method="POST" class="niveles-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="accion" value="<?= $nivelEdit ? 'editar' : 'crear' ?>">
        <?php if ($nivelEdit): ?><input type="hidden" name="id" value="<?= (int)$nivelEdit['id'] ?>"><?php endif; ?>

        <h3><?= $nivelEdit ? '✏️ Editar nivel #' . (int)$nivelEdit['id'] : '+ Nuevo nivel' ?></h3>

        <div class="form-grid">
            <div class="form-row">
                <label>Orden</label>
                <input type="number" name="orden" min="1" max="99" required
                       value="<?= e($nivelEdit['orden'] ?? (count($niveles) + 1)) ?>">
            </div>
            <div class="form-row">
                <label>Código *</label>
                <input type="text" name="codigo" maxlength="20" required placeholder="N1, S1, T"
                       value="<?= e($nivelEdit['codigo'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <label>Nombre (opcional)</label>
            <input type="text" name="nombre" maxlength="80" placeholder="Sótano 1, Terraza..."
                   value="<?= e($nivelEdit['nombre'] ?? '') ?>">
        </div>
        <div class="form-row">
            <label>Tipo</label>
            <select name="tipo">
                <?php foreach (['subterraneo'=>'🅿️ Subterráneo','superficie'=>'🏠 Superficie','terraza'=>'🌤️ Terraza'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= (($nivelEdit['tipo'] ?? 'subterraneo') === $k) ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label style="display:flex;align-items:center;gap:6px;font-size:14px">
                <input type="checkbox" name="es_visitante" value="1" style="width:auto"
                       <?= (int)($nivelEdit['es_visitante'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>👋 Nivel destinado a visitantes</span>
            </label>
        </div>

        <div class="btn-row">
            <button type="submit" class="btn btn--primary"><?= $nivelEdit ? 'Guardar cambios' : 'Crear nivel' ?></button>
            <?php if ($nivelEdit): ?>
                <a class="btn" href="<?= url('/parqueadero/niveles') ?>">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
