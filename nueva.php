<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/nueva.php
// Paso 1: subir archivo Excel/CSV. Acepta tipo=residentes o tipo=vehiculos.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin','admin','supervisor');

$pdo = db();
$u   = auth_user();

$tipo = in_array($_GET['tipo'] ?? $_POST['tipo'] ?? '', ['residentes','vehiculos'], true)
          ? ($_GET['tipo'] ?? $_POST['tipo']) : 'residentes';

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $file = $_FILES['archivo'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errores[] = 'Debes seleccionar un archivo.';
    } elseif (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $errores[] = 'El archivo excede 5 MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv'], true)) {
            $errores[] = 'El archivo debe ser .xlsx o .csv';
        }
    }

    if (empty($errores)) {
        $importDir = UPLOADS_PATH . '/imports';
        if (!is_dir($importDir)) @mkdir($importDir, 0755, true);

        $token = bin2hex(random_bytes(8));
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $dest  = $importDir . '/' . $token . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errores[] = 'No se pudo guardar el archivo subido.';
        } else {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['import'] = [
                'tipo'         => $tipo,
                'token'        => $token,
                'ext'          => $ext,
                'archivo_orig' => $file['name'],
                'subido_en'    => time(),
            ];
            redirect('/importaciones/preview');
        }
    }
}

$cols_residentes = ['apto','tipo','nombre','celular'];
$cols_vehiculos  = ['apto','placa','usuario','observacion'];
$cols_actuales   = $tipo === 'vehiculos' ? $cols_vehiculos : $cols_residentes;

$_pageTitle = 'Nueva importación de ' . $tipo;
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">Nueva importación de <?= e($tipo) ?></h1>
    <p class="page-head__sub">Paso 1 de 3: Subir archivo Excel (.xlsx) o CSV.</p>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px">
            <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/importaciones/nueva') ?>" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">

    <div class="form-section">
        <h3 class="form-section__title">Archivo</h3>

        <label class="field">
            <span>Selecciona el archivo (máximo 5 MB)</span>
            <input type="file" name="archivo" accept=".xlsx,.csv" required>
            <small class="field__hint">
                Formatos: <strong>.xlsx</strong> (Excel) o <strong>.csv</strong> (UTF-8, ; o , como separador).
                Columnas esperadas:
                <?php foreach ($cols_actuales as $c): ?>
                    <code><?= e($c) ?></code>
                <?php endforeach; ?>
            </small>
        </label>

        <div class="notice notice--info" style="margin-top:14px">
            <strong>Reglas de importación:</strong>
            <?php if ($tipo === 'residentes'): ?>
                <ul style="margin:6px 0 0 18px">
                    <li>Si el apto no existe en SmartPark, la fila se rechaza.</li>
                    <li>Si ya existe un residente con el mismo apto + celular (o apto + nombre normalizado), se considera duplicado.</li>
                    <li>Tipo: <code>inquilino</code>/<code>inqu</code> → inquilino; <code>propietario</code>/<code>prop</code> → propietario.</li>
                </ul>
            <?php else: ?>
                <ul style="margin:6px 0 0 18px">
                    <li>Si el apto no existe, la fila se rechaza.</li>
                    <li>Si la placa ya existe activa, se considera duplicado (puedes elegir sobreescribir en el paso siguiente).</li>
                    <li>Tipo de vehículo (carro/moto) se detecta automáticamente por formato de placa.</li>
                    <li>Si la columna <code>usuario</code> trae nombre, se intenta vincular al residente existente del apto. Si no, queda sin residente y la <code>observacion</code> se guarda como nota.</li>
                </ul>
            <?php endif; ?>
        </div>

        <p style="margin-top:14px">
            ¿No tienes plantilla?
            <a href="<?= url('/importaciones/plantilla_' . $tipo) ?>">Descárgala aquí</a>.
        </p>
    </div>

    <div class="form-actions">
        <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>

        <a class="btn" href="<?= url('/importaciones') ?>">Cancelar</a>
        <button type="submit" class="btn btn--primary">Continuar →</button>
    </div>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
