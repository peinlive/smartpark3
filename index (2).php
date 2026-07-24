<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/index.php
// v2.0 (3U): Lista de revistas con acciones masivas de eliminación.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','ronda','porteria');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

$f_nivel   = clean_string($_GET['nivel']   ?? '', 10);
$f_estado  = in_array($_GET['estado'] ?? '', ['en_curso','terminada','cancelada'], true) ? $_GET['estado'] : '';
$f_usuario = (int)($_GET['usuario_id'] ?? 0);
$f_desde   = clean_string($_GET['desde'] ?? '', 10);
$f_hasta   = clean_string($_GET['hasta'] ?? '', 10);
$f_placa   = strtoupper(clean_string($_GET['placa'] ?? '', 15));   // v7.57
$f_apto    = clean_string($_GET['apto'] ?? '', 20);                // v7.57

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset    = ($pagina - 1) * $porPagina;

$where  = ['r.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];
if ($f_nivel  !== '') { $where[] = 'r.nivel = :nv';       $params[':nv'] = $f_nivel; }
if ($f_estado !== '') { $where[] = 'r.estado = :es';      $params[':es'] = $f_estado; }
if ($f_usuario > 0)   { $where[] = 'r.usuario_id = :us';  $params[':us'] = $f_usuario; }
if ($f_desde  !== '') { $where[] = 'DATE(r.iniciado_en) >= :fd'; $params[':fd'] = $f_desde; }
if ($f_hasta  !== '') { $where[] = 'DATE(r.iniciado_en) <= :fh'; $params[':fh'] = $f_hasta; }
// v7.57: filtrar revistas que CONTENGAN una placa
if ($f_placa !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM revistas_detalle rdp
                         JOIN vehiculos vp ON vp.id = rdp.vehiculo_id
                        WHERE rdp.revista_id = r.id AND UPPER(vp.placa) LIKE :pl)';
    $params[':pl'] = '%' . $f_placa . '%';
}
// v7.59: filtrar revistas por apto — por 3 vías: dueño de la celda,
// usuario (autorizado) de la celda, o apto del VEHÍCULO registrado en la celda.
if ($f_apto !== '') {
    $where[] = 'EXISTS (
        SELECT 1 FROM revistas_detalle rda
          JOIN celdas ca ON ca.id = rda.celda_id
          LEFT JOIN apartamentos aad ON aad.id = ca.apto_dueno_id
          LEFT JOIN asignaciones_celdas asc2 ON asc2.celda_id = ca.id AND asc2.activa = 1 AND asc2.archivado_en IS NULL
          LEFT JOIN apartamentos aau ON aau.id = asc2.apto_usuario_id
          LEFT JOIN vehiculos vva ON vva.id = rda.vehiculo_id
          LEFT JOIN apartamentos aav ON aav.id = vva.apartamento_id
         WHERE rda.revista_id = r.id
           AND (aad.numero_visible LIKE :ap
             OR aau.numero_visible LIKE :ap2
             OR aav.numero_visible LIKE :ap3))';
    $params[':ap']  = '%' . $f_apto . '%';
    $params[':ap2'] = '%' . $f_apto . '%';
    $params[':ap3'] = '%' . $f_apto . '%';
}
$whereSql = implode(' AND ', $where);

$stC = $pdo->prepare("SELECT COUNT(*) FROM revistas r WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

$kpi = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado = 'en_curso'  THEN 1 ELSE 0 END) AS en_curso,
        SUM(CASE WHEN estado = 'terminada' THEN 1 ELSE 0 END) AS terminadas,
        SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) AS canceladas
    FROM revistas WHERE conjunto_id = :c");
$kpi->execute([':c' => $conjuntoId]);
$kpi = $kpi->fetch();

$sql = "SELECT r.*, u.nombre_completo AS usuario_nombre, u.username AS usuario_username,
               (SELECT COUNT(*) FROM revistas_detalle rd WHERE rd.revista_id = r.id) AS registros
          FROM revistas r
     LEFT JOIN usuarios u ON u.id = r.usuario_id
         WHERE $whereSql
      ORDER BY r.iniciado_en DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$revistas = $st->fetchAll();

$niveles = $pdo->prepare("SELECT codigo, nombre FROM niveles_parqueadero
                           WHERE conjunto_id = :c AND activo = 1 ORDER BY orden");
$niveles->execute([':c' => $conjuntoId]);
$niveles = $niveles->fetchAll();

$usuarios = $pdo->prepare("SELECT DISTINCT u.id, u.nombre_completo
                            FROM revistas r JOIN usuarios u ON u.id = r.usuario_id
                           WHERE r.conjunto_id = :c
                        ORDER BY u.nombre_completo");
$usuarios->execute([':c' => $conjuntoId]);
$usuarios = $usuarios->fetchAll();

function _revDur($ini, $fin) {
    if (!$ini || !$fin) return '—';
    $seg = strtotime($fin) - strtotime($ini);
    if ($seg < 0) return '—';
    if ($seg < 60) return $seg . 's';
    if ($seg < 3600) return floor($seg/60) . 'm';
    return floor($seg/3600) . 'h ' . floor(($seg%3600)/60) . 'm';
}

$_pageTitle = 'Revistas de parqueadero';
include INCLUDES_PATH . '/header.php';
?>

<style>
.kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;min-width:110px;}
.kpi-card strong{display:block;font-size:20px;color:#1f2937;}
.kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.kpi-card.encurso strong{color:#1e6cff;}
.kpi-card.term strong{color:#15803d;}
.kpi-card.canc strong{color:#dc2626;}
.pill--curso{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.pill--term{background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.pill--canc{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.acciones-fila{display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;}
.acciones-fila .btn--sm{padding:4px 8px;}
.progress{background:#e5e7eb;border-radius:6px;overflow:hidden;height:6px;width:120px;}
.progress > span{background:#1e6cff;display:block;height:100%;}
.bulk-bar{position:sticky;bottom:0;background:#1f2937;color:white;padding:14px 20px;margin:16px -16px 0;display:none;align-items:center;gap:12px;box-shadow:0 -3px 10px rgba(0,0,0,.15);border-top:3px solid #dc2626;z-index:100;flex-wrap:wrap;}
.bulk-bar.visible{display:flex;}
.bulk-count{font-weight:600;flex:1;min-width:140px;}
.bulk-elim{background:#dc2626;padding:8px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:500;color:white;}
.col-check{width:32px;text-align:center;}
</style>

<div class="page-head">
    <h1 class="page-head__title">📋 Revistas de parqueadero</h1>
    <p class="page-head__sub"><?= $total ?> revista<?= $total === 1 ? '' : 's' ?>.</p>
</div>

<div class="kpi-row">
    <div class="kpi-card"><strong><?= (int)$kpi['total'] ?></strong><span>Total</span></div>
    <div class="kpi-card encurso"><strong><?= (int)$kpi['en_curso'] ?></strong><span>En curso</span></div>
    <div class="kpi-card term"><strong><?= (int)$kpi['terminadas'] ?></strong><span>Terminadas</span></div>
    <div class="kpi-card canc"><strong><?= (int)$kpi['canceladas'] ?></strong><span>Canceladas</span></div>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/revistas/nueva') ?>">+ Nueva revista</a>
    <a class="btn" href="<?= url('/parqueadero') ?>">🅿️ Parqueadero</a>
    <a class="btn" href="<?= url('/revistas/configurar_retencion') ?>" style="background:#eff6ff;color:#1e40af">⚙️ Retención de fotos</a>
    <a class="btn" href="<?= url('/reportes/planilla_parqueo') ?>" style="background:#dcfce7;color:#166534">📊 Planilla mensual (Excel)</a>
</div>

<form method="get" action="<?= url('/revistas') ?>" class="filters">
    <select name="nivel">
        <option value="">Todos los niveles</option>
        <?php foreach ($niveles as $n): ?>
            <option value="<?= e($n['codigo']) ?>" <?= $f_nivel === $n['codigo'] ? 'selected' : '' ?>>
                <?= e($n['codigo']) ?><?= $n['nombre'] ? ' — ' . e($n['nombre']) : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="estado">
        <option value="">Todos los estados</option>
        <option value="en_curso"  <?= $f_estado === 'en_curso'  ? 'selected' : '' ?>>🟦 En curso</option>
        <option value="terminada" <?= $f_estado === 'terminada' ? 'selected' : '' ?>>✅ Terminadas</option>
        <option value="cancelada" <?= $f_estado === 'cancelada' ? 'selected' : '' ?>>❌ Canceladas</option>
    </select>
    <select name="usuario_id">
        <option value="0">Cualquier usuario</option>
        <?php foreach ($usuarios as $us): ?>
            <option value="<?= (int)$us['id'] ?>" <?= $f_usuario === (int)$us['id'] ? 'selected' : '' ?>>
                <?= e($us['nombre_completo']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="desde" value="<?= e($f_desde) ?>">
    <input type="date" name="hasta" value="<?= e($f_hasta) ?>">
    <input type="text" name="placa" value="<?= e($f_placa) ?>" placeholder="🔤 Placa" maxlength="15" style="text-transform:uppercase">
    <input type="text" name="apto" value="<?= e($f_apto) ?>" placeholder="🏠 Apto" maxlength="20">
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/revistas') ?>">Limpiar</a>
</form>

<?php if (empty($revistas)): ?>
    <?php if ((int)$kpi['total'] === 0): ?>
        <div class="notice notice--info">
            📋 Aún no hay revistas.
            Comienza con <a href="<?= url('/revistas/nueva') ?>"><strong>+ Nueva revista</strong></a>.
        </div>
    <?php else: ?>
        <div class="notice notice--info">No hay revistas que coincidan con los filtros.</div>
    <?php endif; ?>
<?php else: ?>
    <form id="bulk-form" method="POST" action="<?= url('/revistas/acciones_batch') ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '/revistas') ?>">

        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" onchange="revToggleAll(this)"></th>
                    <th>#</th><th>Nivel</th><th>Usuario</th><th>Inicio</th><th>Duración</th>
                    <th>Progreso</th><th>Registros</th><th>Estado</th>
                    <th class="t-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $diaActual = null;
            foreach ($revistas as $r):
                $id = (int)$r['id'];
                $total_c   = (int)$r['total_celdas'];
                $revisadas = (int)$r['celdas_revisadas'];
                $pct = $total_c > 0 ? round($revisadas * 100 / $total_c) : 0;
                // v7.57: agrupar por día
                $diaR = $r['iniciado_en'] ? date('Y-m-d', strtotime($r['iniciado_en'])) : 'sin-fecha';
                if ($diaR !== $diaActual):
                    $diaActual = $diaR;
                    $diaLabel = $r['iniciado_en'] ? date('d/m/Y', strtotime($r['iniciado_en'])) : 'Sin fecha';
                    $diaKey = 'dia-' . preg_replace('/[^0-9]/', '', $diaR);
            ?>
                <tr class="rev-dia-head" data-dia="<?= $diaKey ?>"
                    style="cursor:pointer;background:#eff6ff">
                    <td colspan="10" style="font-weight:800;color:#1e40af;padding:10px 12px">
                        <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin-right:10px"
                               onclick="event.stopPropagation()">
                            <input type="checkbox" onchange="revToggleDiaSel('<?= $diaKey ?>', this)"
                                   title="Seleccionar todas las de este día">
                            <span style="font-size:12px;font-weight:600;color:#1e40af">todas</span>
                        </label>
                        <span onclick="revToggleDia('<?= $diaKey ?>')" style="cursor:pointer">
                            <span class="rev-dia-arrow" id="arrow-<?= $diaKey ?>">▶</span>
                            📅 <?= e($diaLabel) ?>
                            <span class="muted" style="font-weight:600;font-size:12px">(clic para abrir)</span>
                        </span>
                    </td>
                </tr>
            <?php endif; ?>
                <tr class="rev-fila <?= $diaKey ?>" style="display:none">
                    <td class="col-check">
                        <input type="checkbox" name="seleccion[]" value="<?= $id ?>" onchange="revUpdateBulkBar()">
                    </td>
                    <td>#<?= $id ?></td>
                    <td><strong><?= e($r['nivel']) ?></strong></td>
                    <td>
                        <?php if ($r['usuario_nombre']): ?>
                            <?= e($r['usuario_nombre']) ?>
                            <br><small class="t-muted"><?= e($r['usuario_username']) ?></small>
                        <?php else: ?><span class="t-muted">— borrado —</span><?php endif; ?>
                    </td>
                    <td>
                        <?= $r['iniciado_en'] ? e(date('d/m/Y H:i', strtotime($r['iniciado_en']))) : '—' ?>
                    </td>
                    <td><?= e(_revDur($r['iniciado_en'], $r['terminado_en'] ?: date('Y-m-d H:i:s'))) ?></td>
                    <td>
                        <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
                        <small><?= $revisadas ?>/<?= $total_c ?> (<?= $pct ?>%)</small>
                    </td>
                    <td><?= (int)$r['registros'] ?></td>
                    <td>
                        <?php if ($r['estado'] === 'en_curso'): ?>
                            <span class="pill--curso">🟦 En curso</span>
                        <?php elseif ($r['estado'] === 'terminada'): ?>
                            <span class="pill--term">✅ Terminada</span>
                        <?php else: ?>
                            <span class="pill--canc">❌ Cancelada</span>
                        <?php endif; ?>
                    </td>
                    <td class="t-right">
                        <div class="acciones-fila">
                            <a class="btn btn--sm" href="<?= url('/revistas/ver?id=' . $id) ?>" title="Ver">👁️</a>
                            <?php if ($r['estado'] === 'en_curso'): ?>
                                <a class="btn btn--sm" style="background:#1e6cff;color:#fff"
                                   href="<?= url('/revistas/ejecutar?id=' . $id) ?>" title="Continuar">▶️</a>
                                <button type="button" class="btn btn--sm" style="background:#fef3c7;color:#92400e"
                                        onclick="revCancelarUna(<?= $id ?>)" title="Cancelar">⏹️</button>
                            <?php endif; ?>
                            <?php if (!$esRonda): ?>
                            <button type="button" class="btn btn--sm" style="background:#fee2e2;color:#991b1b"
                                    onclick="revEliminarUna(<?= $id ?>)" title="Eliminar">🗑️</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if (!$esRonda): ?>
        <div id="bulk-bar" class="bulk-bar">
            <span id="bulk-count" class="bulk-count">0 seleccionada(s)</span>
            <button type="submit" name="accion" value="eliminar_imagenes" class="bulk-elim" style="background:#d97706"
                    onclick="return revConfirmarEliminarImagenes()">🖼️ Eliminar solo imágenes</button>
            <button type="submit" name="accion" value="eliminar" class="bulk-elim"
                    onclick="return revConfirmarEliminar()">🗑️ Eliminar seleccionadas</button>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']); $base = url('/revistas') . '?' . http_build_query($qs); $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?><span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?><a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif;
            endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
// v7.64: si hay filtros activos, abrir los días para ver los resultados
(function(){
  var hayFiltro = <?= (($f_nivel !== '' || $f_estado !== '' || $f_usuario > 0 ||
                        $f_desde !== '' || $f_hasta !== '' || $f_placa !== '' || $f_apto !== '')
                       ? 'true' : 'false') ?>;
  if (!hayFiltro) return;
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.rev-fila').forEach(function(f){ f.style.display=''; });
    document.querySelectorAll('.rev-dia-arrow').forEach(function(a){ a.textContent='▼'; });
  });
})();

// v7.64: seleccionar/deseleccionar todas las revistas de un día
function revToggleDiaSel(diaKey, cb){
    var filas = document.querySelectorAll('.rev-fila.' + diaKey);
    filas.forEach(function(f){
        var c = f.querySelector('input[type=checkbox][name="seleccion[]"]');
        if (c) c.checked = cb.checked;
    });
    if (typeof revUpdateBulkBar === 'function') revUpdateBulkBar();
}

// v7.57: colapsar/expandir revistas por día
function revToggleDia(diaKey){
    var filas = document.querySelectorAll('.rev-fila.' + diaKey);
    var arrow = document.getElementById('arrow-' + diaKey);
    var oculto = false;
    filas.forEach(function(f){
        if (f.style.display === 'none') { f.style.display = ''; }
        else { f.style.display = 'none'; oculto = true; }
    });
    if (arrow) arrow.textContent = oculto ? '▶' : '▼';
}
window.REV_CSRF = <?= json_encode(csrf_token()) ?>;
window.REV_ELIM_URL = <?= json_encode(url('/revistas/eliminar')) ?>;
window.REV_CANC_URL = <?= json_encode(url('/revistas/cancelar')) ?>;

function revToggleAll(cb){
    document.querySelectorAll('input[name="seleccion[]"]').forEach(function(c){c.checked=cb.checked;});
    revUpdateBulkBar();
}
function revUpdateBulkBar(){
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    var bar = document.getElementById('bulk-bar');
    if (!bar) return;
    if (n > 0) { bar.classList.add('visible'); document.getElementById('bulk-count').textContent = n + ' seleccionada(s)'; }
    else bar.classList.remove('visible');
}
function revConfirmarEliminar(){
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    if (n === 0) { alert('No seleccionaste ninguna revista.'); return false; }
    if (!confirm('⚠️ ¿ELIMINAR PERMANENTEMENTE ' + n + ' revista(s)?\n\nSe borrarán también sus fotos y registros.\nNO se puede deshacer.')) return false;
    return confirm('Confirma una segunda vez: ¿borrar ' + n + ' revista(s) para SIEMPRE?');
}
function revConfirmarEliminarImagenes(){
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    if (n === 0) { alert('No seleccionaste ninguna revista.'); return false; }
    return confirm('🖼️ ¿Eliminar SOLO las imágenes de ' + n + ' revista(s)?\n\n' +
                   'Las revistas y sus registros SE CONSERVAN. Solo se borran las fotos del disco para liberar espacio.\n\n' +
                   'Las fotos NO se pueden recuperar después.');
}
function revEliminarUna(id){
    if (!confirm('⚠️ ¿ELIMINAR PERMANENTEMENTE esta revista?\n\nSe borrarán también sus fotos y registros.')) return;
    if (!confirm('Confirma una segunda vez: ¿borrar la revista #' + id + ' para SIEMPRE?')) return;
    var f = document.createElement('form');
    f.method = 'POST'; f.action = window.REV_ELIM_URL;
    f.innerHTML = '<input type="hidden" name="_csrf" value="'+window.REV_CSRF+'">' +
                  '<input type="hidden" name="id" value="'+id+'">' +
                  '<input type="hidden" name="return_url" value="'+window.location.pathname+window.location.search+'">';
    document.body.appendChild(f); f.submit();
}
function revCancelarUna(id){
    if (!confirm('¿Cancelar esta revista?')) return;
    var f = document.createElement('form');
    f.method = 'POST'; f.action = window.REV_CANC_URL;
    f.innerHTML = '<input type="hidden" name="_csrf" value="'+window.REV_CSRF+'">' +
                  '<input type="hidden" name="id" value="'+id+'">' +
                  '<input type="hidden" name="return_url" value="'+window.location.pathname+window.location.search+'">';
    document.body.appendChild(f); f.submit();
}
</script>

<!-- v7.48: botón volver arriba (módulo largo) -->
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
