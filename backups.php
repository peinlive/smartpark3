<?php
// /home/myzonaco/smartpark.myzona360.com/modules/configuracion/backups.php
// v7.3 — Copias de seguridad de la base de datos.
//
// POR QUE EN PHP PURO Y NO mysqldump:
//   En un cPanel compartido, exec()/shell_exec() suelen estar bloqueados y no
//   hay terminal. Asi que el dump se genera recorriendo las tablas con PDO.
//   Tu BD pesa ~700 KB (27 tablas), asi que esto es de sobra: no hay riesgo
//   de timeout ni de reventar la memoria.
//
// QUE HACE:
//   - Backup manual (boton)
//   - Backup automatico (via cron, ver instrucciones abajo)
//   - Listar / descargar / borrar
//   - RESTAURAR (con backup previo automatico y confirmacion escrita)
//   - Subir un .sql externo y restaurarlo
//
// NO incluye /uploads (las fotos). Motivo: se borran a los 15 dias igual, y
// cPanel ya hace backup del filesystem. Meterlas aca haria el archivo enorme.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin');          // SOLO super_admin: esto puede destruir la BD

$pdo = db();
$DIR = dirname(__DIR__, 2) . '/storage/backups';

if (!is_dir($DIR)) @mkdir($DIR, 0700, true);
// proteger la carpeta del acceso web
if (is_dir($DIR) && !is_file($DIR . '/.htaccess')) {
    @file_put_contents($DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}

$msg = $err = null;

// ═══════════════════════════════════════════════════════════════
//  GENERAR EL DUMP
// ═══════════════════════════════════════════════════════════════
function bk_generar(PDO $pdo, string $dir, string $motivo = 'manual'): array
{
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('La carpeta de backups no existe o no es escribible: ' . $dir);
    }

    $nombre = 'smartpark_' . date('Ymd_His') . '_' . $motivo . '.sql';
    $ruta   = $dir . '/' . $nombre;
    $fh     = fopen($ruta, 'w');
    if (!$fh) throw new RuntimeException('No se pudo crear el archivo.');

    fwrite($fh, "-- SmartPark backup\n");
    fwrite($fh, "-- Fecha: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "-- Motivo: {$motivo}\n");
    fwrite($fh, "-- ATENCION: restaurar esto REEMPLAZA la base de datos.\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET NAMES utf8mb4;\n\n");

    $tablas = [];
    $st = $pdo->query("SHOW TABLES");
    while ($r = $st->fetch(PDO::FETCH_NUM)) $tablas[] = $r[0];

    $totFilas = 0;
    foreach ($tablas as $t) {
        // estructura
        $c = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "\n-- ─────────── {$t} ───────────\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n");
        fwrite($fh, $c[1] . ";\n\n");

        // datos, de a 200 filas por INSERT
        $q = $pdo->query("SELECT * FROM `{$t}`");
        $buf = [];
        $n = 0;
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null)      $vals[] = 'NULL';
                elseif (is_int($v))   $vals[] = (string)$v;
                elseif (is_numeric($v) && !preg_match('/^0\d/', (string)$v)) $vals[] = $v;
                else                  $vals[] = $pdo->quote((string)$v);
            }
            $buf[] = '(' . implode(',', $vals) . ')';
            $n++; $totFilas++;

            if (count($buf) >= 200) {
                $cols = '`' . implode('`,`', array_keys($row)) . '`';
                fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $buf) . ";\n");
                $buf = [];
            }
        }
        if ($buf) {
            $q2   = $pdo->query("SELECT * FROM `{$t}` LIMIT 1");
            $r1   = $q2->fetch(PDO::FETCH_ASSOC);
            $cols = '`' . implode('`,`', array_keys($r1)) . '`';
            fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $buf) . ";\n");
        }
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    // comprimir si se puede: pasa de ~700 KB a ~80 KB
    if (function_exists('gzencode')) {
        $raw = file_get_contents($ruta);
        $gz  = gzencode($raw, 9);
        if ($gz !== false && file_put_contents($ruta . '.gz', $gz) !== false) {
            @unlink($ruta);
            $ruta   = $ruta . '.gz';
            $nombre = $nombre . '.gz';
        }
    }

    return [
        'archivo' => $nombre,
        'ruta'    => $ruta,
        'peso'    => filesize($ruta),
        'tablas'  => count($tablas),
        'filas'   => $totFilas,
    ];
}

// ═══════════════════════════════════════════════════════════════
//  RESTAURAR
// ═══════════════════════════════════════════════════════════════
function bk_restaurar(PDO $pdo, string $ruta): array
{
    if (!is_file($ruta)) throw new RuntimeException('El archivo no existe.');

    $sql = (substr($ruta, -3) === '.gz')
        ? gzdecode(file_get_contents($ruta))
        : file_get_contents($ruta);

    if ($sql === false || $sql === '') throw new RuntimeException('No se pudo leer el backup.');

    // Sanidad: que sea un dump de SmartPark, no cualquier .sql
    if (strpos($sql, 'CREATE TABLE') === false) {
        throw new RuntimeException('El archivo no parece un dump SQL válido.');
    }
    if (strpos($sql, '`usuarios`') === false && strpos($sql, '`vehiculos`') === false) {
        throw new RuntimeException('El archivo no parece un backup de SmartPark.');
    }

    // Partir en sentencias, respetando las comillas
    $stmts = [];
    $buf   = '';
    $inStr = false;
    $q     = '';
    $len   = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inStr) {
            $buf .= $ch;
            if ($ch === '\\') {                       // escape
                if ($i + 1 < $len) { $buf .= $sql[++$i]; }
                continue;
            }
            if ($ch === $q) $inStr = false;
            continue;
        }

        if ($ch === "'" || $ch === '"') { $inStr = true; $q = $ch; $buf .= $ch; continue; }

        // comentario de linea
        if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-' && ($buf === '' || substr($buf, -1) === "\n")) {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            continue;
        }

        if ($ch === ';') {
            $t = trim($buf);
            if ($t !== '') $stmts[] = $t;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $t = trim($buf);
    if ($t !== '') $stmts[] = $t;

    if (!$stmts) throw new RuntimeException('El backup no contiene sentencias.');

    $ok = $fail = 0;
    $errores = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($stmts as $s) {
        if (stripos($s, 'SET FOREIGN_KEY_CHECKS') === 0) continue;
        try { $pdo->exec($s); $ok++; }
        catch (Throwable $e) {
            $fail++;
            if (count($errores) < 5) $errores[] = substr($e->getMessage(), 0, 120);
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return ['ok' => $ok, 'fail' => $fail, 'errores' => $errores];
}

// ═══════════════════════════════════════════════════════════════
//  ACCIONES
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $acc = $_POST['accion'] ?? '';

    try {
        if ($acc === 'crear') {
            $r = bk_generar($pdo, $DIR, 'manual');
            $msg = "✅ Backup creado: {$r['archivo']} · "
                 . number_format($r['peso'] / 1024, 0) . " KB · "
                 . "{$r['tablas']} tablas · " . number_format($r['filas']) . " filas";
        }

        elseif ($acc === 'borrar') {
            $f = basename($_POST['archivo'] ?? '');
            $p = $DIR . '/' . $f;
            if ($f && is_file($p) && strpos($f, 'smartpark_') === 0) {
                @unlink($p);
                $msg = "🗑️ Backup eliminado: {$f}";
            } else {
                $err = 'Archivo inválido.';
            }
        }

        elseif ($acc === 'restaurar') {
            if (($_POST['confirmar'] ?? '') !== 'RESTAURAR') {
                throw new RuntimeException('Debes escribir RESTAURAR para confirmar.');
            }
            $f = basename($_POST['archivo'] ?? '');
            $p = $DIR . '/' . $f;
            if (!$f || !is_file($p)) throw new RuntimeException('Backup no encontrado.');

            // 1) backup de SEGURIDAD antes de tocar nada
            $prev = bk_generar($pdo, $DIR, 'previo-restauracion');

            // 2) restaurar
            $r = bk_restaurar($pdo, $p);

            $msg = "♻️ Restaurado desde {$f} · {$r['ok']} sentencias OK"
                 . ($r['fail'] ? " · ⚠️ {$r['fail']} con error" : '')
                 . "\n💾 Backup previo guardado: {$prev['archivo']}";
            if ($r['errores']) {
                $err = 'Errores: ' . implode(' | ', $r['errores']);
            }
        }

        elseif ($acc === 'subir' && !empty($_FILES['sql']['tmp_name'])) {
            $nom = basename($_FILES['sql']['name']);
            if (!preg_match('/\.(sql|gz)$/i', $nom)) {
                throw new RuntimeException('Solo se aceptan archivos .sql o .sql.gz');
            }
            $dest = $DIR . '/smartpark_' . date('Ymd_His') . '_subido.sql'
                  . (substr($nom, -3) === '.gz' ? '.gz' : '');
            if (!move_uploaded_file($_FILES['sql']['tmp_name'], $dest)) {
                throw new RuntimeException('No se pudo guardar el archivo.');
            }
            $msg = "📤 Backup subido: " . basename($dest)
                 . " · Ahora podés restaurarlo desde la lista.";
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// ── DESCARGAR ──
if (isset($_GET['bajar'])) {
    $f = basename($_GET['bajar']);
    $p = $DIR . '/' . $f;
    if (is_file($p) && strpos($f, 'smartpark_') === 0) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    }
    http_response_code(404);
    exit('No encontrado');
}

// ── listar ──
$backups = [];
if (is_dir($DIR)) {
    foreach (glob($DIR . '/smartpark_*.sql*') as $p) {
        $backups[] = [
            'archivo' => basename($p),
            'peso'    => filesize($p),
            'fecha'   => filemtime($p),
        ];
    }
    usort($backups, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
}
$pesoTotal = array_sum(array_column($backups, 'peso'));

$_pageTitle = 'Copias de seguridad';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
  <h1>💾 Copias de seguridad</h1>
  <p class="page-head__sub">Base de datos completa. No incluye las fotos (esas las respalda cPanel).</p>
</div>

<?php if ($msg): ?>
  <div class="card" style="background:#dcfce7;border-color:#86efac;color:#166534;white-space:pre-line">
    <b><?= e($msg) ?></b>
  </div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="card" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b">
    <b>❌ <?= e($err) ?></b>
  </div>
<?php endif; ?>

<?php if (!is_writable($DIR)): ?>
  <div class="card" style="background:#fef3c7;border-color:#fcd34d;color:#92400e">
    <b>⚠️ La carpeta de backups no es escribible.</b><br>
    Creá <code>/storage/backups</code> en el File Manager con permisos <b>755</b>.
  </div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="crear">
      <button type="submit" class="btn btn--primary">💾 Crear backup ahora</button>
    </form>

    <form method="post" enctype="multipart/form-data" style="margin:0;display:flex;gap:7px;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="subir">
      <input type="file" name="sql" accept=".sql,.gz" required
             style="padding:8px;border:1px solid #d1d5db;border-radius:7px;font-size:13px">
      <button type="submit" class="btn btn--sm">📤 Subir backup</button>
    </form>

    <span style="flex:1"></span>
    <span class="t-muted" style="font-size:13px">
      <?= count($backups) ?> backups · <?= number_format($pesoTotal / 1024, 0) ?> KB
    </span>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0">Backups guardados</h3>

  <?php if (!$backups): ?>
    <p class="t-muted" style="text-align:center;padding:24px">
      Todavía no hay backups. Pulsá <b>Crear backup ahora</b>.
    </p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
      <thead>
        <tr style="border-bottom:1px solid #e5e7eb">
          <th style="text-align:left;padding:9px;color:#6b7280;font-size:11px">ARCHIVO</th>
          <th style="text-align:left;padding:9px;color:#6b7280;font-size:11px">FECHA</th>
          <th style="text-align:left;padding:9px;color:#6b7280;font-size:11px">PESO</th>
          <th style="text-align:right;padding:9px;color:#6b7280;font-size:11px">ACCIONES</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($backups as $b):
        $auto = strpos($b['archivo'], '_auto') !== false;
        $prev = strpos($b['archivo'], '_previo') !== false;
      ?>
        <tr style="border-bottom:1px solid #f3f4f6">
          <td style="padding:9px;font-family:ui-monospace,monospace;font-size:12.5px">
            <?= e($b['archivo']) ?>
            <?php if ($auto): ?>
              <span style="background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:600">AUTO</span>
            <?php elseif ($prev): ?>
              <span style="background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:600">PRE-RESTAURACIÓN</span>
            <?php endif; ?>
          </td>
          <td style="padding:9px"><?= date('d/m/Y H:i', $b['fecha']) ?></td>
          <td style="padding:9px"><?= number_format($b['peso'] / 1024, 0) ?> KB</td>
          <td style="padding:9px;text-align:right;white-space:nowrap">
            <a class="btn btn--sm"
               href="<?= url('/configuracion/backups?bajar=' . urlencode($b['archivo'])) ?>">⬇️ Bajar</a>
            <button type="button" class="btn btn--sm"
                    style="background:#fef3c7;color:#92400e"
                    onclick="pedirRestaurar('<?= e($b['archivo']) ?>')">♻️ Restaurar</button>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('¿Eliminar <?= e($b['archivo']) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="borrar">
              <input type="hidden" name="archivo" value="<?= e($b['archivo']) ?>">
              <button type="submit" class="btn btn--sm" style="background:#fee2e2;color:#991b1b">🗑️</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ═══ MODAL DE RESTAURACIÓN ═══ -->
<div id="mod-rest" style="display:none;position:fixed;inset:0;z-index:2000;
     background:rgba(15,23,42,.75);padding:18px;overflow-y:auto">
  <div style="max-width:520px;margin:40px auto;background:#fff;border-radius:14px;padding:22px">
    <h3 style="margin:0 0 10px;color:#991b1b">⚠️ Restaurar backup</h3>

    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:9px;padding:12px;
                font-size:13.5px;color:#991b1b;line-height:1.7">
      <b>Esto REEMPLAZA la base de datos entera.</b><br>
      Todo lo que hayas hecho desde ese backup <b>se pierde</b>:
      revistas, novedades, residentes, vehículos.<br><br>
      Se hace un <b>backup automático antes</b> de restaurar, así podés volver.<br><br>
      <b>Si el proceso se corta a la mitad</b> (timeout de PHP), la base puede
      quedar incompleta. No cierres el navegador.
    </div>

    <p style="margin:13px 0 5px;font-size:13.5px">
      Vas a restaurar: <b id="rest-file" style="font-family:monospace"></b>
    </p>

    <form method="post" id="frm-rest">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="restaurar">
      <input type="hidden" name="archivo" id="rest-input">
      <label style="font-size:13px;color:#374151">
        Escribí <b>RESTAURAR</b> para confirmar:
      </label>
      <input type="text" name="confirmar" id="rest-conf" autocomplete="off"
             placeholder="RESTAURAR"
             style="width:100%;padding:11px;border:2px solid #fca5a5;border-radius:8px;
                    margin:7px 0 12px;font-size:16px;text-align:center;letter-spacing:2px">
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn" onclick="cerrarRest()">Cancelar</button>
        <button type="submit" class="btn" id="btn-rest" disabled
                style="background:#991b1b;color:#fff">♻️ Restaurar</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0">🕐 Backup automático</h3>
  <p style="font-size:13.5px;color:#374151;line-height:1.8">
    Para que se haga solo cada X días, agregá un <b>cron job</b> en cPanel:
  </p>
  <pre style="background:#111827;color:#a7f3d0;padding:12px;border-radius:8px;
              font-size:12.5px;overflow-x:auto">/usr/local/bin/php <?= dirname(__DIR__, 2) ?>/cron/backup_auto.php</pre>
  <p style="font-size:13px;color:#6b7280;line-height:1.8">
    <b>cPanel → Cron Jobs → Agregar</b><br>
    · Cada día a las 3 AM: <code>0 3 * * *</code><br>
    · Cada 7 días: <code>0 3 * * 0</code><br>
    · Cada 15 días: <code>0 3 1,16 * *</code><br><br>
    Los automáticos se marcan con <b>AUTO</b> y se conservan los últimos 10
    (los viejos se borran solos para no llenar el disco).
  </p>
</div>

<script>
function pedirRestaurar(f) {
  document.getElementById('rest-file').textContent = f;
  document.getElementById('rest-input').value = f;
  document.getElementById('rest-conf').value = '';
  document.getElementById('btn-rest').disabled = true;
  document.getElementById('mod-rest').style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function cerrarRest() {
  document.getElementById('mod-rest').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('rest-conf').oninput = function () {
  document.getElementById('btn-rest').disabled = (this.value.trim() !== 'RESTAURAR');
};
document.getElementById('frm-rest').onsubmit = function (e) {
  if (document.getElementById('rest-conf').value.trim() !== 'RESTAURAR') {
    e.preventDefault(); return;
  }
  document.getElementById('btn-rest').textContent = '⏳ Restaurando...';
  document.getElementById('btn-rest').disabled = true;
};
document.getElementById('mod-rest').onclick = function (e) {
  if (e.target === this) cerrarRest();
};
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
