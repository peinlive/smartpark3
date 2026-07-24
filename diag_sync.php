<?php
// /modules/lectura_prueba/diag_sync.php
// v6.1 — Diagnostico del sync offline. Dice EXACTAMENTE por que falla la subida.
if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$u   = auth_user();
$cj  = (int)($u['conjunto_id'] ?? 1);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico Sync</title>
<style>
body{font:14px system-ui;background:#0f1115;color:#e6e8ec;padding:18px;max-width:900px;margin:0 auto}
h1{font-size:19px;margin-bottom:12px}
h2{font-size:15px;margin:20px 0 8px;color:#93a1b5}
.chk{display:flex;gap:9px;padding:9px;border-bottom:1px solid #262b35;align-items:flex-start}
.chk b{flex:1;font-weight:500}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#ffd44d}
small{display:block;color:#7d8798;margin-top:3px;font-size:12px;font-family:ui-monospace,monospace}
pre{background:#161a21;padding:11px;border-radius:8px;font-size:12px;overflow:auto}
code{background:#232936;padding:2px 6px;border-radius:4px;font-size:12px}
</style></head><body>
<h1>Diagnóstico del Sync offline</h1>

<h2>1 · Tabla sync_procesados (idempotencia)</h2>
<?php
$tieneTabla = false;
try {
    $pdo->query("SELECT 1 FROM sync_procesados LIMIT 1");
    $tieneTabla = true;
    $n = (int)$pdo->query("SELECT COUNT(*) FROM sync_procesados")->fetchColumn();
    echo '<div class="chk"><span class="ok">✅</span><b>Existe'
       . '<small>' . $n . ' uids registrados</small></b></div>';
} catch (Throwable $e) {
    echo '<div class="chk"><span class="err">❌</span><b>NO EXISTE'
       . '<small>' . htmlspecialchars($e->getMessage()) . '</small>'
       . '<small style="color:#ffd44d">Ejecuta /sql/v6_sync_procesados.sql en phpMyAdmin.<br>'
       . 'Sin ella, reenviar un lote DUPLICA registros.</small></b></div>';
}
?>

<h2>2 · Columnas que usa el sync</h2>
<?php
$checks = [
    'revistas'                 => ['conjunto_id','nivel','usuario_id','total_celdas','estado','iniciado_en'],
    'revistas_detalle'         => ['revista_id','celda_id','estado','placa_detectada','vehiculo_id','foto_path'],
    'observaciones_vehiculo'   => ['vehiculo_id','tipo','gravedad','descripcion','evidencia_url','usuario_registra'],
    'observaciones_evidencias' => ['observacion_id','tipo','archivo_url','mime','tamano_bytes','subido_por'],
    'visitantes_vehiculos'     => ['conjunto_id','apartamento_id','placa','tipo','nombre_visitante','parentesco'],
    'vehiculos'                => ['conjunto_id','apartamento_id','placa','tipo','marca','color'],
    'niveles_parqueadero'      => ['id','codigo','nombre','conjunto_id'],
    'celdas'                   => ['id','nivel_id','tipo','activa','numero_orden'],
];
foreach ($checks as $tabla => $cols) {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `$tabla`");
        $reales = [];
        while ($r = $st->fetch()) $reales[] = $r['Field'];
        $faltan = array_diff($cols, $reales);
        if (!$faltan) {
            echo '<div class="chk"><span class="ok">✅</span><b>' . $tabla . '</b></div>';
        } else {
            echo '<div class="chk"><span class="err">❌</span><b>' . $tabla
               . '<small style="color:#f87171">FALTAN: ' . implode(', ', $faltan) . '</small>'
               . '<small>columnas reales: ' . implode(', ', $reales) . '</small></b></div>';
        }
    } catch (Throwable $e) {
        echo '<div class="chk"><span class="err">❌</span><b>' . $tabla
           . '<small>' . htmlspecialchars($e->getMessage()) . '</small></b></div>';
    }
}
?>

<h2>3 · Niveles y celdas del conjunto</h2>
<?php
try {
    $st = $pdo->prepare("SELECT n.id, n.codigo, n.nombre,
              (SELECT COUNT(*) FROM celdas c WHERE c.nivel_id=n.id AND c.activa=1) tot
            FROM niveles_parqueadero n WHERE n.conjunto_id=:c ORDER BY n.codigo");
    $st->execute([':c'=>$cj]);
    $ns = $st->fetchAll();
    if (!$ns) {
        echo '<div class="chk"><span class="err">❌</span><b>Sin niveles en este conjunto</b></div>';
    } else {
        foreach ($ns as $n) {
            $cls = $n['tot'] > 0 ? 'ok' : 'warn';
            echo '<div class="chk"><span class="' . $cls . '">'
               . ($n['tot'] > 0 ? '✅' : '⚠️') . '</span><b>'
               . htmlspecialchars($n['codigo']) . ' · ' . htmlspecialchars($n['nombre'])
               . '<small>' . $n['tot'] . ' celdas activas</small></b></div>';
        }
    }
} catch (Throwable $e) {
    echo '<div class="chk"><span class="err">❌</span><b>' . htmlspecialchars($e->getMessage()) . '</b></div>';
}
?>

<h2>4 · Carpeta de uploads (para las fotos)</h2>
<?php
$base = defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads';
foreach (['revistas', 'observaciones'] as $sub) {
    $d = $base . '/' . $sub;
    $existe = is_dir($d);
    $escrib = $existe ? is_writable($d) : is_writable($base);
    $ok = $existe && $escrib;
    echo '<div class="chk"><span class="' . ($ok ? 'ok' : 'err') . '">'
       . ($ok ? '✅' : '❌') . '</span><b>/uploads/' . $sub
       . '<small>' . htmlspecialchars($d) . '</small>'
       . '<small>' . ($existe ? 'existe' : 'NO existe')
       . ' · ' . ($escrib ? 'escribible' : 'NO ESCRIBIBLE (chmod 755)') . '</small></b></div>';
}
?>

<h2>5 · Rol actual</h2>
<div class="chk"><span class="ok">👤</span><b>
  <?= htmlspecialchars($u['username'] ?? '?') ?> ·
  rol: <code><?= htmlspecialchars($u['rol'] ?? '?') ?></code>
  <small>conjunto_id: <?= $cj ?> · user_id: <?= (int)($u['id'] ?? 0) ?></small>
</b></div>

<h2>6 · Probar el endpoint</h2>
<button onclick="probar()" style="background:#1e6cff;color:#fff;border:0;padding:11px 17px;
  border-radius:8px;font-size:14px;font-weight:600;cursor:pointer">▶ Enviar lote de prueba</button>
<pre id="out">…</pre>

<script>
function probar() {
  var o = document.getElementById('out');
  o.textContent = '⏳ enviando…';
  var fd = new FormData();
  fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
  fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
  // lote VACIO: solo probamos que el endpoint responde y el CSRF pasa
  fd.append('lote', JSON.stringify([]));
  fetch(<?= json_encode(url('/api/sync_lote')) ?>, {
    method: 'POST', body: fd, credentials: 'same-origin'
  })
  .then(function (r) { return r.text().then(function (t) {
      return { status: r.status, ct: r.headers.get('content-type'), body: t };
  }); })
  .then(function (d) {
    o.textContent = 'HTTP ' + d.status + '\n' + d.ct + '\n\n' + d.body;
  })
  .catch(function (e) { o.textContent = 'ERROR: ' + e.message; });
}

// mostrar lo que hay en la cola del celular
if (window.indexedDB) {
  var rq = indexedDB.open('smartpark_lote', 1);
  rq.onsuccess = function (e) {
    var db = e.target.result;
    if (!db.objectStoreNames.contains('items')) return;
    var out = [];
    var c = db.transaction('items','readonly').objectStore('items').openCursor();
    c.onsuccess = function (ev) {
      var cur = ev.target.result;
      if (cur) {
        var v = cur.value;
        out.push({ uid: v.uid, tipo: v.tipo, revista: v.revista_id,
                   celda: v.celda_id, placa: v.placa, nivel: v.nivel,
                   foto: v.foto ? (Math.round(v.foto.length/1024) + ' KB') : '-' });
        cur.continue();
      } else {
        var pre = document.createElement('pre');
        pre.textContent = 'COLA EN EL CELULAR (' + out.length + ' items):\n' +
                          JSON.stringify(out, null, 1);
        document.body.appendChild(pre);
      }
    };
  };
}
</script>
</body></html>
