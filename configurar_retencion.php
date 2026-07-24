<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/configurar_retencion.php
// v2.0 (3W.2): Retención de fotos EN DOS ÁMBITOS:
//   - Revistas de parqueadero (revistas_detalle.foto_path)
//   - Lecturas de placa OCR (lecturas_placas.foto_path)
// NO toca fotos de vehículos (esas son perfil permanente).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$baseUploads = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';
$configDir   = $baseUploads . '/config';
$configFile  = $configDir . '/retencion_fotos_' . $conjuntoId . '.json';
$configFileViejo = $configDir . '/retencion_revistas_' . $conjuntoId . '.json'; // compat v3W

// ── Config ──
function cargarConfig(string $file, string $fileViejo): array {
    $def = [
        'revistas' => ['activa' => false, 'mantener' => 15, 'ultima_ejecucion' => null],
        'lecturas' => ['activa' => false, 'mantener' => 100, 'ultima_ejecucion' => null],
    ];

    // Migración desde el archivo viejo (v3W solo revistas)
    if (!is_file($file) && is_file($fileViejo)) {
        $j = @file_get_contents($fileViejo);
        $d = @json_decode($j, true);
        if (is_array($d)) {
            $def['revistas'] = [
                'activa'          => !empty($d['activa']),
                'mantener'        => max(1, (int)($d['mantener'] ?? 15)),
                'ultima_ejecucion'=> $d['ultima_ejecucion'] ?? null,
            ];
        }
        return $def;
    }
    if (!is_file($file)) return $def;

    $j = @file_get_contents($file);
    if (!$j) return $def;
    $d = @json_decode($j, true);
    if (!is_array($d)) return $def;

    foreach (['revistas','lecturas'] as $k) {
        if (isset($d[$k]) && is_array($d[$k])) {
            $def[$k] = [
                'activa'          => !empty($d[$k]['activa']),
                'mantener'        => max(1, (int)($d[$k]['mantener'] ?? $def[$k]['mantener'])),
                'ultima_ejecucion'=> $d[$k]['ultima_ejecucion'] ?? null,
            ];
        }
    }
    return $def;
}

function guardarConfig(string $dir, string $file, array $c): bool {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return (bool) @file_put_contents($file, json_encode($c, JSON_PRETTY_PRINT));
}

// ── Aplicar retención de REVISTAS ──
function aplicarRetencionRevistas(PDO $pdo, int $conjuntoId, int $mantener, string $baseUploads): array {
    $st = $pdo->prepare("SELECT id FROM revistas WHERE conjunto_id = :c ORDER BY iniciado_en DESC");
    $st->execute([':c' => $conjuntoId]);
    $todasIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (count($todasIds) <= $mantener) {
        return ['afectadas' => 0, 'fotos_borradas' => 0, 'total' => count($todasIds)];
    }

    $idsAlimpiar = array_slice($todasIds, $mantener);
    if (empty($idsAlimpiar)) return ['afectadas' => 0, 'fotos_borradas' => 0, 'total' => count($todasIds)];

    $inList = implode(',', array_map('intval', $idsAlimpiar));

    $stF = $pdo->prepare("SELECT foto_path FROM revistas_detalle
                            WHERE revista_id IN ($inList) AND foto_path IS NOT NULL");
    $stF->execute();

    $baseDir = $baseUploads . '/revistas';
    $fotosBorradas = 0;
    foreach ($stF->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $full = $baseDir . '/' . $fp;
        if (is_file($full)) { @unlink($full); $fotosBorradas++; }
    }

    $pdo->prepare("UPDATE revistas_detalle SET foto_path = NULL
                    WHERE revista_id IN ($inList)")->execute();

    // Carpetas vacías
    foreach ($idsAlimpiar as $rid) {
        $revDir = $baseDir . '/' . $rid;
        if (is_dir($revDir)) {
            $files = @scandir($revDir);
            if ($files !== false) {
                $vacio = true;
                foreach ($files as $f) if ($f !== '.' && $f !== '..') { $vacio = false; break; }
                if ($vacio) @rmdir($revDir);
            }
        }
    }

    return [
        'afectadas'     => count($idsAlimpiar),
        'fotos_borradas'=> $fotosBorradas,
        'total'         => count($todasIds),
    ];
}

// ── Aplicar retención de LECTURAS OCR ──
// Mantiene las N lecturas más recientes CON FOTO. A las anteriores se les borra la foto.
function aplicarRetencionLecturas(PDO $pdo, int $conjuntoId, int $mantener, string $baseUploads): array {
    // Ordenar por fecha DESC, sólo las que tienen foto
    $st = $pdo->prepare("SELECT id FROM lecturas_placas
                          WHERE conjunto_id = :c AND foto_path IS NOT NULL
                       ORDER BY creado_en DESC");
    $st->execute([':c' => $conjuntoId]);
    $idsConFoto = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (count($idsConFoto) <= $mantener) {
        return ['afectadas' => 0, 'fotos_borradas' => 0, 'total' => count($idsConFoto)];
    }

    $idsAlimpiar = array_slice($idsConFoto, $mantener);
    if (empty($idsAlimpiar)) return ['afectadas' => 0, 'fotos_borradas' => 0, 'total' => count($idsConFoto)];

    $inList = implode(',', array_map('intval', $idsAlimpiar));

    // Traer foto_path para borrar del disco
    $stF = $pdo->prepare("SELECT foto_path FROM lecturas_placas
                            WHERE id IN ($inList) AND foto_path IS NOT NULL");
    $stF->execute();

    $fotosBorradas = 0;
    foreach ($stF->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        // foto_path es relativa a /uploads (según comentario del schema)
        $full = $baseUploads . '/' . ltrim($fp, '/');
        if (is_file($full)) { @unlink($full); $fotosBorradas++; }
    }

    // Limpiar foto_path en BD (conservar el registro de la lectura)
    $pdo->prepare("UPDATE lecturas_placas SET foto_path = NULL WHERE id IN ($inList)")->execute();

    return [
        'afectadas'     => count($idsAlimpiar),
        'fotos_borradas'=> $fotosBorradas,
        'total'         => count($idsConFoto),
    ];
}

$cfg = cargarConfig($configFile, $configFileViejo);
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $accion = $_POST['accion'] ?? '';
    $ambito = $_POST['ambito'] ?? '';

    if (!in_array($ambito, ['revistas','lecturas'], true)) {
        $errores[] = 'Ámbito inválido.';
    } else {
        $mantener = max(1, min(10000, (int)($_POST['mantener'] ?? $cfg[$ambito]['mantener'])));
        $activa   = isset($_POST['activa']) ? true : false;

        if ($accion === 'guardar') {
            $cfg[$ambito]['mantener'] = $mantener;
            $cfg[$ambito]['activa']   = $activa;
            if (guardarConfig($configDir, $configFile, $cfg)) {
                flash_set('ok', 'Configuración de ' . ($ambito === 'revistas' ? 'revistas' : 'lecturas OCR') . ' guardada.');
                redirect('/revistas/configurar_retencion');
            } else {
                $errores[] = "No se pudo escribir el archivo de config en {$configDir}. Verifica permisos.";
            }
        }

        if ($accion === 'aplicar_ahora') {
            try {
                $res = ($ambito === 'revistas')
                    ? aplicarRetencionRevistas($pdo, $conjuntoId, $mantener, $baseUploads)
                    : aplicarRetencionLecturas($pdo, $conjuntoId, $mantener, $baseUploads);
                $cfg[$ambito]['mantener'] = $mantener;
                $cfg[$ambito]['ultima_ejecucion'] = date('Y-m-d H:i:s');
                guardarConfig($configDir, $configFile, $cfg);
                $etiqueta = $ambito === 'revistas' ? 'Revistas' : 'Lecturas OCR';
                flash_set('ok', "✅ [$etiqueta] Limpieza aplicada: se conservan las últimas {$mantener}. " .
                                "Se afectaron {$res['afectadas']} registros más antiguos y se borraron {$res['fotos_borradas']} fotos del disco.");
                redirect('/revistas/configurar_retencion');
            } catch (Exception $ex) {
                $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al aplicar la limpieza.';
            }
        }
    }
}

// ── Estadísticas actuales ──
// Revistas
$stRevs = $pdo->prepare("SELECT COUNT(*) FROM revistas WHERE conjunto_id = :c");
$stRevs->execute([':c' => $conjuntoId]); $totalRevs = (int)$stRevs->fetchColumn();

$stRevsF = $pdo->prepare("SELECT COUNT(DISTINCT rd.revista_id) AS con_fotos, COUNT(*) AS total_fotos
                           FROM revistas_detalle rd
                           JOIN revistas r ON r.id = rd.revista_id
                          WHERE r.conjunto_id = :c AND rd.foto_path IS NOT NULL");
$stRevsF->execute([':c' => $conjuntoId]); $statsRev = $stRevsF->fetch();

// Lecturas
$stLec = $pdo->prepare("SELECT COUNT(*) FROM lecturas_placas WHERE conjunto_id = :c");
$stLec->execute([':c' => $conjuntoId]); $totalLec = (int)$stLec->fetchColumn();

$stLecF = $pdo->prepare("SELECT COUNT(*) FROM lecturas_placas
                          WHERE conjunto_id = :c AND foto_path IS NOT NULL");
$stLecF->execute([':c' => $conjuntoId]); $totalLecFotos = (int)$stLecF->fetchColumn();

$_pageTitle = 'Retención de fotos';
include INCLUDES_PATH . '/header.php';
?>

<style>
.cfg-panel{max-width:760px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;}
.cfg-panel.rev{border-left:4px solid #1e6cff;}
.cfg-panel.lec{border-left:4px solid #d97706;}
.cfg-panel h3{margin:0 0 10px;font-size:16px;}
.cfg-panel.rev h3{color:#1e40af;}
.cfg-panel.lec h3{color:#92400e;}
.cfg-panel label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;margin-top:14px;}
.cfg-panel input[type=number]{width:140px;padding:8px 12px;border:1px solid #d1d5db;border-radius:5px;font-size:15px;text-align:center;}
.stats-hoy{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin:14px 0;}
.stat-c{border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;text-align:center;}
.stat-c.rev{background:#eff6ff;border-color:#93c5fd;}
.stat-c.lec{background:#fef3c7;border-color:#fbbf24;}
.stat-c strong{display:block;font-size:22px;line-height:1;}
.stat-c.rev strong{color:#1e40af;}
.stat-c.lec strong{color:#92400e;}
.stat-c span{font-size:11px;color:#6b7280;text-transform:uppercase;}
.explicacion{background:#f8fafc;padding:12px 14px;border-radius:6px;font-size:13px;color:#374151;margin-bottom:10px;line-height:1.5;}
.warn-box{background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:12px 14px;font-size:13px;color:#92400e;margin-top:14px;}
.warn-box h4{margin:0 0 6px;}
.acciones{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}
.no-tocar{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px 18px;margin-top:20px;font-size:13px;color:#166534;}
.no-tocar h4{margin:0 0 6px;color:#15803d;}
.section-divider{margin:24px 0 8px;padding:8px 0;border-top:2px dashed #e5e7eb;font-size:12px;color:#6b7280;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:1px;}
</style>

<div class="page-head">
    <h1 class="page-head__title">🖼️ Retención de fotos</h1>
    <p class="page-head__sub">Control del espacio en disco: cuántas fotos de cada tipo conservar.</p>
</div>

<div class="toolbar">
    <a class="btn" href="<?= url('/revistas') ?>">← Volver a revistas</a>
    <a class="btn" href="<?= url('/lecturas') ?>">🔎 Ir a lecturas OCR</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- SECCIÓN 1: REVISTAS DE PARQUEADERO                         -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="section-divider">📋 Sección 1 · Revistas de parqueadero</div>

<div class="cfg-panel rev">
    <h3>📊 Estado actual — Revistas</h3>
    <div class="stats-hoy">
        <div class="stat-c rev"><strong><?= $totalRevs ?></strong><span>Revistas totales</span></div>
        <div class="stat-c rev"><strong><?= (int)$statsRev['con_fotos'] ?></strong><span>Con fotos</span></div>
        <div class="stat-c rev"><strong><?= (int)$statsRev['total_fotos'] ?></strong><span>Fotos en disco</span></div>
    </div>

    <div class="explicacion">
        Cada foto de revista pesa ~200-400 KB. Con esta opción mantienes SOLO las N revistas más recientes
        con sus fotos. Las anteriores conservan sus registros de placas, celdas y estados, pero pierden las imágenes.
    </div>
</div>

<form method="POST" class="cfg-panel rev">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="ambito" value="revistas">

    <h3>⚙️ Configuración — Revistas</h3>

    <label>Revistas más recientes a conservar con fotos</label>
    <input type="number" name="mantener" min="1" max="10000" value="<?= (int)$cfg['revistas']['mantener'] ?>" required>

    <label style="margin-top:16px">
        <input type="checkbox" name="activa" value="1" <?= $cfg['revistas']['activa'] ? 'checked' : '' ?>>
        Recordar esta configuración
    </label>

    <?php if ($cfg['revistas']['ultima_ejecucion']): ?>
        <div style="margin-top:14px;padding:8px 12px;background:#f0fdf4;border-radius:5px;font-size:13px;color:#166534">
            🕒 Última limpieza de revistas: <?= e(date('d/m/Y H:i:s', strtotime($cfg['revistas']['ultima_ejecucion']))) ?>
        </div>
    <?php endif; ?>

    <div class="acciones">
        <button type="submit" name="accion" value="guardar" class="btn btn--primary">💾 Guardar</button>
        <button type="submit" name="accion" value="aplicar_ahora" class="btn"
                style="background:#1e6cff;color:#fff"
                onclick="return confirm('⚠️ ¿Aplicar limpieza de REVISTAS ahora?\n\nSe conservan las últimas revistas que indicas y las anteriores pierden sus fotos.\n\nLos registros se conservan intactos.\n\nNo se puede deshacer.');">
            🧹 Aplicar limpieza a REVISTAS ahora
        </button>
    </div>
</form>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- SECCIÓN 2: LECTURAS DE PLACA OCR                           -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="section-divider">🔎 Sección 2 · Lecturas de placa (OCR)</div>

<div class="cfg-panel lec">
    <h3>📊 Estado actual — Lecturas OCR</h3>
    <div class="stats-hoy">
        <div class="stat-c lec"><strong><?= $totalLec ?></strong><span>Lecturas totales</span></div>
        <div class="stat-c lec"><strong><?= $totalLecFotos ?></strong><span>Con fotos</span></div>
        <div class="stat-c lec"><strong><?= $totalLec - $totalLecFotos ?></strong><span>Sin fotos</span></div>
    </div>

    <div class="explicacion">
        Las lecturas OCR (consulta rápida, porteríía, etc.) también guardan fotos que se acumulan rápido.
        Aquí mantienes SOLO las N lecturas más recientes con sus imágenes. Las anteriores conservan la placa
        detectada, resultado y fecha; solo se borran las fotos del disco.
    </div>
</div>

<form method="POST" class="cfg-panel lec">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="ambito" value="lecturas">

    <h3>⚙️ Configuración — Lecturas OCR</h3>

    <label>Lecturas más recientes a conservar con fotos</label>
    <input type="number" name="mantener" min="1" max="10000" value="<?= (int)$cfg['lecturas']['mantener'] ?>" required>
    <small style="color:#6b7280;display:block;margin-top:4px">
        Recomendado: 50-200 (dependiendo de cuántas consultas OCR haces al día).
    </small>

    <label style="margin-top:16px">
        <input type="checkbox" name="activa" value="1" <?= $cfg['lecturas']['activa'] ? 'checked' : '' ?>>
        Recordar esta configuración
    </label>

    <?php if ($cfg['lecturas']['ultima_ejecucion']): ?>
        <div style="margin-top:14px;padding:8px 12px;background:#f0fdf4;border-radius:5px;font-size:13px;color:#166534">
            🕒 Última limpieza de lecturas: <?= e(date('d/m/Y H:i:s', strtotime($cfg['lecturas']['ultima_ejecucion']))) ?>
        </div>
    <?php endif; ?>

    <div class="acciones">
        <button type="submit" name="accion" value="guardar" class="btn btn--primary">💾 Guardar</button>
        <button type="submit" name="accion" value="aplicar_ahora" class="btn"
                style="background:#d97706;color:#fff"
                onclick="return confirm('⚠️ ¿Aplicar limpieza de LECTURAS OCR ahora?\n\nSe conservan las últimas lecturas que indicas y las anteriores pierden sus fotos.\n\nLos registros (placa detectada, resultado) se conservan intactos.\n\nNo se puede deshacer.');">
            🧹 Aplicar limpieza a LECTURAS ahora
        </button>
    </div>
</form>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- SECCIÓN 3: LO QUE NO SE TOCA                               -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="no-tocar">
    <h4>✅ Lo que NO se toca en NINGÚN caso</h4>
    <ul style="margin:0 0 0 18px;padding:0">
        <li><strong>Fotos de perfil de vehículos</strong> (foto_principal). Son parte del registro del vehículo.</li>
        <li><strong>Fotos de residentes</strong> (si las tienes).</li>
        <li><strong>Fotos de apartamentos</strong>.</li>
        <li><strong>Cualquier foto fuera de las tablas revistas_detalle y lecturas_placas</strong>.</li>
    </ul>
    <p style="margin:8px 0 0;font-size:12px;color:#374151">
        Esta herramienta SOLO limpia foto_path en las tablas <code>revistas_detalle</code> y <code>lecturas_placas</code>,
        y borra los archivos JPG correspondientes del disco.
    </p>
</div>

<div style="margin-top:24px;padding:14px;background:#f8fafc;border-radius:6px;font-size:12px;color:#6b7280">
    💡 <strong>Tip:</strong> Si quieres que la limpieza sea automática al terminar cada revista o al hacer nuevas
    lecturas, dime y agrego un hook. Por ahora es manual (más seguro).
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
