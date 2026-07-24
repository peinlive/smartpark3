<?php
// /home/myzonaco/smartpark.myzona360.com/modules/observaciones/index.php
// v1.0 (3V): Lista de observaciones sobre vehículos.
//            Filtra por tipo, gravedad, fechas, placa.
//            Soporta eliminación individual y masiva.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);
$esRonda    = auth_has_role('ronda') && !auth_has_role('super_admin','admin','supervisor','porteria');

$f_tipo     = in_array($_GET['tipo']     ?? '', ['mal_parqueo','advertencia','reincidencia','queja','otro'], true) ? $_GET['tipo']     : '';
$f_gravedad = in_array($_GET['gravedad'] ?? '', ['leve','media','grave'], true)                                     ? $_GET['gravedad'] : '';
$f_desde    = clean_string($_GET['desde'] ?? '', 10);
$f_hasta    = clean_string($_GET['hasta'] ?? '', 10);
$f_placa    = strtoupper(clean_string($_GET['placa'] ?? '', 15));

$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
$offset    = ($pagina - 1) * $porPagina;

// La tabla observaciones_vehiculo NO tiene conjunto_id → filtramos via JOIN con vehiculos
$where  = ['v.conjunto_id = :cid'];
$params = [':cid' => $conjuntoId];
if ($f_tipo     !== '') { $where[] = 'o.tipo = :tp';                 $params[':tp'] = $f_tipo; }
if ($f_gravedad !== '') { $where[] = 'o.gravedad = :gr';             $params[':gr'] = $f_gravedad; }
if ($f_desde    !== '') { $where[] = 'DATE(o.creado_en) >= :fd';     $params[':fd'] = $f_desde; }
if ($f_hasta    !== '') { $where[] = 'DATE(o.creado_en) <= :fh';     $params[':fh'] = $f_hasta; }
if ($f_placa    !== '') { $where[] = 'v.placa LIKE :pl';             $params[':pl'] = '%' . $f_placa . '%'; }
$whereSql = implode(' AND ', $where);

$stC = $pdo->prepare("SELECT COUNT(*) FROM observaciones_vehiculo o
                       JOIN vehiculos v ON v.id = o.vehiculo_id WHERE $whereSql");
$stC->execute($params);
$total = (int)$stC->fetchColumn();
$totalPag = max(1, (int)ceil($total / $porPagina));

// KPIs
$kpiSt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN o.gravedad = 'leve'  THEN 1 ELSE 0 END) AS leves,
        SUM(CASE WHEN o.gravedad = 'media' THEN 1 ELSE 0 END) AS medias,
        SUM(CASE WHEN o.gravedad = 'grave' THEN 1 ELSE 0 END) AS graves,
        SUM(CASE WHEN DATE(o.creado_en) = CURDATE() THEN 1 ELSE 0 END) AS hoy
    FROM observaciones_vehiculo o
    JOIN vehiculos v ON v.id = o.vehiculo_id
    WHERE v.conjunto_id = :c");
$kpiSt->execute([':c' => $conjuntoId]);
$kpi = $kpiSt->fetch();

$sql = "SELECT o.*, v.placa, v.tipo AS veh_tipo,
               a.numero_visible AS apto,
               u.nombre_completo AS usuario_nombre
          FROM observaciones_vehiculo o
          JOIN vehiculos v ON v.id = o.vehiculo_id
     LEFT JOIN apartamentos a ON a.id = v.apartamento_id
     LEFT JOIN usuarios u ON u.id = o.usuario_registra
         WHERE $whereSql
      ORDER BY o.creado_en DESC
         LIMIT $porPagina OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$obs = $st->fetchAll();

function tipoObsBadge($t) {
    $map = [
        'mal_parqueo'  => ['🚧 Mal parqueo',  '#fef3c7', '#92400e'],
        'advertencia'  => ['⚠️ Advertencia',  '#dbeafe', '#1e40af'],
        'reincidencia' => ['🔁 Reincidencia', '#fed7aa', '#9a3412'],
        'queja'        => ['📢 Queja',        '#fee2e2', '#991b1b'],
        'otro'         => ['📌 Otro',         '#e5e7eb', '#374151'],
    ];
    $x = $map[$t] ?? [$t, '#e5e7eb', '#374151'];
    return "<span style=\"display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:{$x[1]};color:{$x[2]}\">{$x[0]}</span>";
}

function gravBadge($g) {
    $map = [
        'leve'  => ['🟢 Leve',  '#dcfce7', '#166534'],
        'media' => ['🟡 Media', '#fef3c7', '#92400e'],
        'grave' => ['🔴 Grave', '#fee2e2', '#991b1b'],
    ];
    $x = $map[$g] ?? [$g, '#e5e7eb', '#374151'];
    return "<span style=\"display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:{$x[1]};color:{$x[2]}\">{$x[0]}</span>";
}

$_pageTitle = 'Observaciones';
include INCLUDES_PATH . '/header.php';
?>

<style>
.kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;min-width:110px;flex:1;}
.kpi-card strong{display:block;font-size:20px;}
.kpi-card span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.kpi-card.hoy strong{color:#1e6cff;}
.kpi-card.lv strong{color:#15803d;}
.kpi-card.md strong{color:#d97706;}
.kpi-card.gv strong{color:#dc2626;}
.bulk-bar{position:sticky;bottom:0;background:#1f2937;color:white;padding:14px 20px;margin:16px -16px 0;display:none;align-items:center;gap:12px;box-shadow:0 -3px 10px rgba(0,0,0,.15);border-top:3px solid #dc2626;z-index:100;}
.bulk-bar.visible{display:flex;}
.bulk-elim{background:#dc2626;padding:8px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:500;color:white;}
.col-check{width:32px;text-align:center;}
.descripcion-cell{max-width:280px;font-size:13px;color:#374151;}
</style>

<div class="page-head">
    <h1 class="page-head__title">⚠️ Observaciones de vehículos</h1>
    <p class="page-head__sub"><?= $total ?> registro<?= $total === 1 ? '' : 's' ?>.</p>
</div>
<!-- v7.5: exportar a Excel/CSV -->
<div style="margin:-6px 0 14px">
  <a href="<?= url('/exportar?t=observaciones') ?>" class="btn btn--sm"
     style="background:#065f46;color:#fff;text-decoration:none;display:inline-flex;
            align-items:center;gap:5px">
    📊 Exportar a Excel
  </a>
  <small style="color:#9ca3af;margin-left:7px">
    Descarga un CSV con todo. Sirve de respaldo y para revisar en Excel.
  </small>
</div>


<div class="kpi-row">
    <div class="kpi-card"><strong><?= (int)$kpi['total'] ?></strong><span>Total</span></div>
    <div class="kpi-card hoy"><strong><?= (int)$kpi['hoy'] ?></strong><span>Hoy</span></div>
    <div class="kpi-card lv"><strong><?= (int)$kpi['leves'] ?></strong><span>🟢 Leves</span></div>
    <div class="kpi-card md"><strong><?= (int)$kpi['medias'] ?></strong><span>🟡 Medias</span></div>
    <div class="kpi-card gv"><strong><?= (int)$kpi['graves'] ?></strong><span>🔴 Graves</span></div>
</div>

<div class="toolbar">
    <a class="btn btn--primary" href="<?= url('/observaciones/crear') ?>">+ Nueva observación</a>
</div>

<form method="get" action="<?= url('/observaciones') ?>" class="filters">
    <select name="tipo">
        <option value="">Todos los tipos</option>
        <option value="mal_parqueo"  <?= $f_tipo === 'mal_parqueo'  ? 'selected' : '' ?>>🚧 Mal parqueo</option>
        <option value="advertencia"  <?= $f_tipo === 'advertencia'  ? 'selected' : '' ?>>⚠️ Advertencia</option>
        <option value="reincidencia" <?= $f_tipo === 'reincidencia' ? 'selected' : '' ?>>🔁 Reincidencia</option>
        <option value="queja"        <?= $f_tipo === 'queja'        ? 'selected' : '' ?>>📢 Queja</option>
        <option value="otro"         <?= $f_tipo === 'otro'         ? 'selected' : '' ?>>📌 Otro</option>
    </select>
    <select name="gravedad">
        <option value="">Todas las gravedades</option>
        <option value="leve"  <?= $f_gravedad === 'leve'  ? 'selected' : '' ?>>🟢 Leve</option>
        <option value="media" <?= $f_gravedad === 'media' ? 'selected' : '' ?>>🟡 Media</option>
        <option value="grave" <?= $f_gravedad === 'grave' ? 'selected' : '' ?>>🔴 Grave</option>
    </select>
    <input type="text" name="placa" placeholder="Placa" value="<?= e($f_placa) ?>" maxlength="15" style="text-transform:uppercase">
    <input type="date" name="desde" value="<?= e($f_desde) ?>">
    <input type="date" name="hasta" value="<?= e($f_hasta) ?>">
    <button type="submit" class="btn btn--primary">Filtrar</button>
    <a class="btn" href="<?= url('/observaciones') ?>">Limpiar</a>
</form>

<?php if (empty($obs)): ?>
    <?php if ((int)$kpi['total'] === 0): ?>
        <div class="notice notice--info">
            No hay observaciones registradas.
            <a href="<?= url('/observaciones/crear') ?>"><strong>+ Registrar primera</strong></a>.
        </div>
    <?php else: ?>
        <div class="notice notice--info">Sin resultados para los filtros aplicados.</div>
    <?php endif; ?>
<?php else: ?>
    <form id="bulk-form" method="POST" action="<?= url('/observaciones/acciones_batch') ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? '/observaciones') ?>">

        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" onchange="obsToggleAll(this)"></th>
                    <th>Fecha</th><th>Vehículo</th><th>Apto</th><th>Tipo</th><th>Grav.</th>
                    <th>Descripción</th><th>Usuario</th>
                    <th class="t-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($obs as $o):
                $id = (int)$o['id'];
            ?>
                <tr>
                    <td class="col-check">
                        <input type="checkbox" name="seleccion[]" value="<?= $id ?>" onchange="obsUpdateBulk()">
                    </td>
                    <td>
                        <?= e(date('d/m/Y', strtotime($o['creado_en']))) ?>
                        <br><small class="t-muted"><?= e(date('H:i', strtotime($o['creado_en']))) ?></small>
                    </td>
                    <td>
                        <strong style="font-family:monospace"><?= e($o['placa']) ?></strong>
                        <br><small class="t-muted"><?= $o['veh_tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></small>
                    </td>
                    <td><?= $o['apto'] ? e($o['apto']) : '<span class="t-muted">—</span>' ?></td>
                    <td><?= tipoObsBadge($o['tipo']) ?></td>
                    <td><?= gravBadge($o['gravedad']) ?></td>
                    <td class="descripcion-cell"><?= e(mb_strimwidth($o['descripcion'], 0, 100, '…')) ?></td>
                    <td><?= $o['usuario_nombre'] ? e($o['usuario_nombre']) : '<span class="t-muted">—</span>' ?></td>
                    <td class="t-right">
                        <!-- v6.7: ver la novedad SIN entrar a editar -->
                        <button type="button" class="btn btn--sm" style="background:#dbeafe;color:#1e40af"
                                onclick="obsVer(<?= $id ?>)" title="Ver detalle y evidencias">👁️</button>
                        <?php if (!$esRonda): ?>
                        <a class="btn btn--sm" href="<?= url('/observaciones/editar?id=' . $id) ?>" title="Editar">✏️</a>
                        <button type="button" class="btn btn--sm" style="background:#fee2e2;color:#991b1b"
                                onclick="obsElimOne(<?= $id ?>)" title="Eliminar">🗑️</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if (!$esRonda): ?>
        <div id="bulk-bar" class="bulk-bar">
            <span id="bulk-count" style="flex:1;font-weight:600">0 seleccionada(s)</span>
            <button type="submit" name="accion" value="eliminar" class="bulk-elim"
                    onclick="return confirm('¿Eliminar las observaciones seleccionadas?');">🗑️ Eliminar seleccionadas</button>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($totalPag > 1): ?>
        <nav class="pager">
            <?php $qs = $_GET; unset($qs['p']); $base = url('/observaciones') . '?' . http_build_query($qs); $sep = $qs ? '&' : '';
            for ($i = 1; $i <= $totalPag; $i++):
                if ($i === $pagina): ?><span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?><a class="pager__item" href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
                <?php endif;
            endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
window.OBS_CSRF = <?= json_encode(csrf_token()) ?>;
window.OBS_ELIM_URL = <?= json_encode(url('/observaciones/eliminar')) ?>;
function obsToggleAll(cb){ document.querySelectorAll('input[name="seleccion[]"]').forEach(function(c){c.checked=cb.checked;}); obsUpdateBulk(); }
function obsUpdateBulk(){
    var n = document.querySelectorAll('input[name="seleccion[]"]:checked').length;
    var bar = document.getElementById('bulk-bar'); if(!bar) return;
    if (n > 0) { bar.classList.add('visible'); document.getElementById('bulk-count').textContent = n + ' seleccionada(s)'; }
    else bar.classList.remove('visible');
}
function obsElimOne(id){
    if (!confirm('¿Eliminar esta observación?')) return;
    var f = document.createElement('form');
    f.method = 'POST'; f.action = window.OBS_ELIM_URL;
    f.innerHTML = '<input type="hidden" name="_csrf" value="'+window.OBS_CSRF+'">' +
                  '<input type="hidden" name="id" value="'+id+'">' +
                  '<input type="hidden" name="return_url" value="'+window.location.pathname+window.location.search+'">';
    document.body.appendChild(f); f.submit();
}
</script>

<!-- ══════════ v6.7: MODAL VER OBSERVACION ══════════ -->
<div id="obs-modal" style="display:none;position:fixed;inset:0;z-index:2000;
     background:rgba(15,23,42,.72);padding:18px;overflow-y:auto">
  <div style="max-width:640px;margin:16px auto;background:#fff;border-radius:14px;
              box-shadow:0 20px 50px rgba(0,0,0,.35)">

    <div style="display:flex;align-items:center;gap:10px;padding:15px 18px;
                border-bottom:1px solid #e5e7eb">
      <h3 style="flex:1;margin:0;font-size:17px">⚠️ Detalle de la novedad</h3>
      <button onclick="obsCerrar()" style="border:0;background:#f3f4f6;width:32px;height:32px;
              border-radius:50%;cursor:pointer;font-size:17px;line-height:1;color:#6b7280">×</button>
    </div>

    <div id="obs-body" style="padding:18px">
      <p style="text-align:center;color:#9ca3af;padding:26px">⏳ Cargando…</p>
    </div>

    <div style="padding:13px 18px;border-top:1px solid #e5e7eb;display:flex;gap:8px;
                justify-content:flex-end;flex-wrap:wrap">
      <a id="obs-link" href="#" class="btn btn--sm" style="text-decoration:none">🔗 Abrir en página</a>
      <a id="obs-edit" href="#" class="btn btn--sm" style="text-decoration:none">✏️ Editar</a>
      <button onclick="obsCerrar()" class="btn btn--sm btn--primary">Cerrar</button>
    </div>
  </div>
</div>

<!-- visor de la foto en grande -->
<div id="obs-lightbox" style="display:none;position:fixed;inset:0;z-index:2100;
     background:rgba(0,0,0,.92);cursor:zoom-out" onclick="this.style.display='none'">
  <img id="obs-lightbox-img" src="" alt=""
       style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
              max-width:96vw;max-height:96vh;border-radius:6px">
</div>

<style>
#obs-body table{width:100%;border-collapse:collapse;font-size:14px}
#obs-body th{text-align:left;color:#6b7280;font-weight:600;font-size:12px;
  padding:7px 9px 7px 0;width:100px;vertical-align:top}
#obs-body td{padding:7px 0;border-bottom:1px solid #f3f4f6}
#obs-body .plc{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;
  letter-spacing:1.5px;font-size:17px}
#obs-body .gp{font-size:11px;padding:3px 9px;border-radius:10px;font-weight:700;
  display:inline-block}
#obs-body .ev{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
#obs-body .ev img{width:104px;height:104px;object-fit:cover;border-radius:8px;
  cursor:zoom-in;border:1px solid #e5e7eb}
#obs-body .ev video{width:150px;border-radius:8px}
</style>

<script>
/* v6.7 — Modal para VER una novedad sin tener que entrar a editarla.
   Muestra: vehiculo, apto, tipo, gravedad, descripcion, quien y cuando,
   la foto del OCR y TODAS las evidencias adicionales. */
var OBS_GRAV = {
  grave:   ['#fee2e2', '#991b1b', '🔴'],
  media:   ['#fef3c7', '#92400e', '🟡'],
  leve:    ['#dcfce7', '#166534', '🟢'],
  ninguna: ['#f3f4f6', '#6b7280', '⚪']
};
var OBS_TIPO = {
  mal_parqueo:  '🚫 Mal parqueo',
  advertencia:  '⚠️ Advertencia',
  reincidencia: '🔁 Reincidencia',
  queja:        '📢 Queja',
  otro:         '📌 Otro'
};

function obsEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
  });
}
function obsZoom(u) {
  document.getElementById('obs-lightbox-img').src = u;
  document.getElementById('obs-lightbox').style.display = 'block';
}
function obsCerrar() {
  document.getElementById('obs-modal').style.display = 'none';
  document.body.style.overflow = '';
}

function obsVer(id) {
  var m = document.getElementById('obs-modal');
  var b = document.getElementById('obs-body');
  m.style.display = 'block';
  document.body.style.overflow = 'hidden';
  b.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:26px">⏳ Cargando…</p>';

  document.getElementById('obs-edit').href = '<?= url('/observaciones/editar?id=') ?>' + id;
  document.getElementById('obs-link').href = '<?= url('/observaciones/ver?id=') ?>' + id;

  fetch('<?= url('/observaciones/api_ver') ?>?id=' + id, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) throw new Error((d && d.error) || 'No se pudo cargar');
      var o = d.obs;
      var g = OBS_GRAV[o.gravedad] || ['#f3f4f6', '#6b7280', ''];

      var h = '<table>' +
        '<tr><th>Vehículo</th><td>' +
          '<span class="plc">' + obsEsc(o.placa) + '</span> ' +
          (o.veh_tipo === 'moto' ? '🏍️' : '🚗') +
          (o.marca || o.color
             ? '<br><span style="color:#6b7280;font-size:13px">' +
               obsEsc(o.marca) + ' ' + obsEsc(o.color) + '</span>' : '') +
        '</td></tr>' +
        '<tr><th>Apartamento</th><td>' +
          (o.apto ? '<b>' + obsEsc(o.apto) + '</b>' +
             (o.torre ? ' · Torre ' + obsEsc(o.torre) : '') +
             (o.piso ? ' · Piso ' + obsEsc(o.piso) : '')
           : '<span style="color:#9ca3af">—</span>') +
        '</td></tr>' +
        '<tr><th>Tipo</th><td>' + obsEsc(OBS_TIPO[o.tipo] || o.tipo) + '</td></tr>' +
        '<tr><th>Gravedad</th><td>' +
          '<span class="gp" style="background:' + g[0] + ';color:' + g[1] + '">' +
          g[2] + ' ' + obsEsc(o.gravedad).toUpperCase() +
          (o.gravedad === 'ninguna' ? ' · informativa' : '') + '</span>' +
        '</td></tr>' +
        '<tr><th>Descripción</th><td style="white-space:pre-wrap">' +
          obsEsc(o.descripcion) + '</td></tr>' +
        '<tr><th>Registró</th><td>' + obsEsc(o.usuario || '—') +
          '<br><span style="color:#6b7280;font-size:13px">' +
          obsEsc(o.creado) + '</span></td></tr>' +
        '</table>';

      // foto principal (la del OCR / la de la revista)
      if (o.foto_ocr) {
        h += '<p style="margin:15px 0 5px;font-size:13px;font-weight:600;color:#374151">' +
             '📷 Foto de la novedad</p>' +
             '<img src="' + o.foto_ocr + '" onclick="obsZoom(this.src)" ' +
             'style="width:100%;max-height:300px;object-fit:contain;border-radius:9px;' +
             'cursor:zoom-in;background:#111">';
      }

      // evidencias adicionales
      if (d.evidencias && d.evidencias.length) {
        h += '<p style="margin:15px 0 5px;font-size:13px;font-weight:600;color:#374151">' +
             '📎 Evidencias adicionales (' + d.evidencias.length + ')</p><div class="ev">';
        d.evidencias.forEach(function (e) {
          if ((e.mime || '').indexOf('video') === 0 || e.tipo === 'video') {
            h += '<video src="' + e.url + '" controls preload="metadata"></video>';
          } else {
            h += '<img src="' + e.url + '" onclick="obsZoom(this.src)" alt="evidencia">';
          }
        });
        h += '</div>';
      }

      if (!o.foto_ocr && (!d.evidencias || !d.evidencias.length)) {
        h += '<p style="margin-top:15px;color:#9ca3af;font-size:13px;text-align:center;' +
             'padding:14px;background:#f9fafb;border-radius:8px">Sin evidencias</p>';
      }

      b.innerHTML = h;
    })
    .catch(function (e) {
      b.innerHTML = '<p style="color:#991b1b;text-align:center;padding:22px">❌ ' +
                    obsEsc(e.message) + '</p>';
    });
}

// cerrar con Escape o clic fuera
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  if (document.getElementById('obs-lightbox').style.display === 'block') {
    document.getElementById('obs-lightbox').style.display = 'none';
  } else if (document.getElementById('obs-modal').style.display === 'block') {
    obsCerrar();
  }
});
document.getElementById('obs-modal').addEventListener('click', function (e) {
  if (e.target === this) obsCerrar();
});
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
