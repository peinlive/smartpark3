<?php
// /modules/importaciones/diag.php
// v7.5 — Diagnóstico de la importación. Dice EXACTAMENTE por qué no confirma.
//
// EL SÍNTOMA: cargás la planilla de vehículos, previsualiza bien, das
// confirmar... y no pasa nada. Sin error, sin mensaje.
//
// LA CAUSA MÁS PROBABLE: confirmar.php hace esto:
//
//     $imp = $_SESSION['import'] ?? null;
//     if (!$imp || empty($imp['analisis'])) {
//         flash_set('error', 'No hay datos de importación.');
//         redirect('/importaciones');      // <-- se va SIN HACER NADA
//     }
//
// Si la sesión no conserva $_SESSION['import'] entre el preview y el
// confirm, te devuelve al inicio en silencio.
//
// Este script verifica CADA eslabón.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin', 'admin');

$pdo = db();
$u   = auth_user();
$cj  = (int)($u['conjunto_id'] ?? 1);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico importación</title>
<style>
body{font:14px system-ui;background:#0f1115;color:#e6e8ec;padding:18px;max-width:900px;margin:0 auto}
h1{font-size:19px;margin-bottom:6px}
h2{font-size:15px;margin:20px 0 8px;color:#93a1b5;border-bottom:1px solid #262b35;padding-bottom:5px}
.chk{display:flex;gap:9px;padding:9px;border-bottom:1px solid #262b35;align-items:flex-start}
.chk b{flex:1;font-weight:500}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#ffd44d}
small{display:block;color:#7d8798;margin-top:3px;font-size:12px;font-family:ui-monospace,monospace}
pre{background:#161a21;padding:11px;border-radius:8px;font-size:12px;overflow:auto}
code{background:#232936;padding:2px 6px;border-radius:4px;font-size:12px}
</style></head><body>
<h1>🔧 Diagnóstico · Importación de vehículos</h1>
<p style="color:#7d8798;font-size:13px">Por qué la confirmación no hace nada.</p>

<h2>1 · Sesión de PHP</h2>
<?php
$sesOk   = session_status() === PHP_SESSION_ACTIVE;
$savePath = session_save_path();
$escrib  = $savePath && is_dir($savePath) && is_writable($savePath);
?>
<div class="chk"><span class="<?= $sesOk ? 'ok' : 'err' ?>"><?= $sesOk ? '✅' : '❌' ?></span><b>
  Sesión activa<small>id: <?= session_id() ?: '(ninguna)' ?></small></b></div>

<div class="chk"><span class="<?= $escrib ? 'ok' : 'err' ?>"><?= $escrib ? '✅' : '❌' ?></span><b>
  Directorio de sesiones escribible
  <small><?= htmlspecialchars($savePath ?: '(por defecto de PHP)') ?></small>
  <?php if (!$escrib): ?>
    <small style="color:#f87171">⚠️ SI ESTO FALLA, LA IMPORTACIÓN NO PUEDE FUNCIONAR.<br>
    El preview guarda los datos en $_SESSION y el confirm los lee de ahí.<br>
    Creá /storage/sessions con permisos 755 en el File Manager.</small>
  <?php endif; ?>
</b></div>

<?php
$vida = ini_get('session.gc_maxlifetime');
?>
<div class="chk"><span class="ok">ℹ️</span><b>
  Vida de la sesión<small><?= number_format((int)$vida) ?> s
  (<?= round((int)$vida / 86400, 1) ?> días)</small></b></div>

<h2>2 · ¿Hay una importación en curso?</h2>
<?php
$imp = $_SESSION['import'] ?? null;
if (!$imp) {
    echo '<div class="chk"><span class="warn">⚠️</span><b>No hay ninguna importación en $_SESSION'
       . '<small>Normal si todavía no subiste un archivo.<br>'
       . 'Subí la planilla en /importaciones/nueva?tipo=vehiculos, llegá al preview, '
       . 'y volvé ACÁ sin darle confirmar. Si acá sigue diciendo "no hay", '
       . 'ESE es el bug: la sesión no conserva los datos.</small></b></div>';
} else {
    $tipo  = $imp['tipo'] ?? '?';
    $filas = $imp['analisis']['filas'] ?? [];
    $peso  = strlen(serialize($imp));
    echo '<div class="chk"><span class="ok">✅</span><b>Importación encontrada'
       . '<small>tipo: ' . htmlspecialchars($tipo)
       . ' · filas: ' . count($filas)
       . ' · peso en sesión: ' . number_format($peso / 1024, 1) . ' KB</small></b></div>';

    if ($peso > 1024 * 1024) {
        echo '<div class="chk"><span class="err">❌</span><b>LA SESIÓN ES DEMASIADO GRANDE'
           . '<small>' . number_format($peso / 1024 / 1024, 1) . ' MB. PHP puede no estar '
           . 'guardándola completa. Este es probablemente TU BUG.</small></b></div>';
    }

    // contar estados
    $est = [];
    foreach ($filas as $f) {
        $e = $f['estado'] ?? '?';
        $est[$e] = ($est[$e] ?? 0) + 1;
    }
    echo '<div class="chk"><span class="ok">ℹ️</span><b>Estados de las filas<small>';
    foreach ($est as $k => $v) echo htmlspecialchars($k) . ': ' . $v . ' · ';
    echo '</small></b></div>';

    if (($est['error'] ?? 0) === count($filas)) {
        echo '<div class="chk"><span class="err">❌</span><b>TODAS las filas tienen error'
           . '<small>Por eso no inserta nada. Mirá los motivos abajo.</small></b></div>';
    }

    echo '<h2>3 · Primeras 5 filas (tal como quedaron)</h2><pre>'
       . htmlspecialchars(print_r(array_slice($filas, 0, 5), true)) . '</pre>';
}
?>

<h2>4 · Tabla vehiculos: columnas reales</h2>
<?php
try {
    $st = $pdo->query("SHOW COLUMNS FROM vehiculos");
    $cols = [];
    while ($r = $st->fetch()) {
        $cols[] = $r['Field'] . ' <span style="color:#7d8798">(' . $r['Type']
                . ($r['Null'] === 'NO' ? ', NOT NULL' : '') . ')</span>';
    }
    echo '<div class="chk"><span class="ok">✅</span><b>vehiculos<small>'
       . implode('<br>', $cols) . '</small></b></div>';
} catch (Throwable $e) {
    echo '<div class="chk"><span class="err">❌</span><b>' . htmlspecialchars($e->getMessage()) . '</b></div>';
}
?>

<h2>5 · Permisos de tu usuario</h2>
<div class="chk"><span class="ok">👤</span><b>
  <?= htmlspecialchars($u['username'] ?? '?') ?> · rol:
  <code><?= htmlspecialchars($u['rol'] ?? '?') ?></code>
  <small>confirmar.php exige: super_admin, admin o supervisor</small>
</b></div>
<?php
$rolOk = in_array($u['rol'] ?? '', ['super_admin','admin','supervisor'], true);
if (!$rolOk) {
    echo '<div class="chk"><span class="err">❌</span><b>TU ROL NO PUEDE IMPORTAR'
       . '<small>confirmar.php te va a rechazar con 403.</small></b></div>';
}
?>

<h2>6 · Prueba de escritura en sesión</h2>
<p style="font-size:13px;color:#7d8798">
  Guarda un dato de prueba, recargá la página, y verificá que siga ahí.
  Si desaparece, la sesión NO persiste y por eso la importación falla.
</p>
<?php
if (isset($_GET['probar'])) {
    $_SESSION['_diag_test'] = ['t' => time(), 'dato' => str_repeat('X', 1000)];
    echo '<div class="chk"><span class="ok">✅</span><b>Dato de prueba guardado. '
       . '<a href="' . url('/importaciones/diag') . '" style="color:#60a5fa">Recargá ahora</a></b></div>';
} elseif (isset($_SESSION['_diag_test'])) {
    $t = $_SESSION['_diag_test'];
    $seg = time() - $t['t'];
    echo '<div class="chk"><span class="ok">✅</span><b>LA SESIÓN PERSISTE'
       . '<small>El dato sigue ahí después de ' . $seg . ' s. '
       . 'Tamaño: ' . strlen($t['dato']) . ' bytes.<br>'
       . 'La sesión funciona: el bug está en otro lado.</small></b></div>';
    unset($_SESSION['_diag_test']);
} else {
    echo '<p><a href="' . url('/importaciones/diag?probar=1') . '" '
       . 'style="background:#1e6cff;color:#fff;padding:11px 17px;border-radius:8px;'
       . 'text-decoration:none;display:inline-block">▶ Guardar dato de prueba</a></p>';
}
?>

<h2>7 · Qué hacer</h2>
<pre style="line-height:1.8">Si el paso 1 falla (directorio no escribible):
   → Creá /storage/sessions con permisos 755 en cPanel File Manager.
     SIN eso, NINGUNA importación puede funcionar.

Si el paso 2 dice "no hay importación" DESPUÉS de haber hecho el preview:
   → La sesión no conserva los datos. Es el bug.

Si el paso 2 dice "sesión demasiado grande":
   → La planilla tiene demasiadas filas para caber en la sesión.
     Partila en archivos más chicos (200-300 filas c/u).

Si todas las filas tienen estado "error":
   → El problema es la planilla, no el código. Mirá los motivos
     en el bloque de las 5 filas.
</pre>

<p style="margin-top:20px">
  <a href="<?= url('/importaciones') ?>" style="color:#60a5fa">← Volver a importaciones</a>
</p>
</body></html>
