<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/mover_visitante.php
// v1.1 (3BG.1): Migra un vehículo de la tabla `vehiculos` a `visitantes_vehiculos`.
//   Flujo:
//     GET  → muestra formulario con datos pre-cargados + confirmación
//     POST → hace INSERT en visitantes_vehiculos + ARCHIVA el vehículo original
//   Los visitantes tienen su propio módulo (/visitantes) con lógica distinta
//   (recurrencia, conteo de visitas, primera/última visita, parentesco).

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

// v3BG.1: cargar helpers que definen normalizar_celular, upload_foto_*, etc.
// Igual que hacen crear.php y editar.php
if (file_exists(INCLUDES_PATH . '/upload_helpers.php')) require_once INCLUDES_PATH . '/upload_helpers.php';
if (file_exists(INCLUDES_PATH . '/csv_helpers.php'))    require_once INCLUDES_PATH . '/csv_helpers.php';

// Fallback defensivo: si normalizar_celular no existe (helpers no cargados o
// función en otro archivo), definirla localmente. Solo deja dígitos.
if (!function_exists('normalizar_celular')) {
    function normalizar_celular($s) {
        $s = (string)$s;
        return preg_replace('/\D+/', '', $s);
    }
}

auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = $u['conjunto_id'] ?? 1;

$id = clean_int($_GET['id'] ?? $_POST['id'] ?? null, 1);
if (!$id) { flash_set('error', 'ID inválido.'); redirect('/vehiculos'); }

// Cargar el vehículo actual
$st = $pdo->prepare("
    SELECT v.*, a.numero_visible AS apto_numero,
           r.nombre AS residente_nombre, r.celular AS residente_celular, r.tipo AS residente_tipo
      FROM vehiculos v
      JOIN apartamentos a ON a.id = v.apartamento_id
 LEFT JOIN residentes r   ON r.id = v.residente_id
     WHERE v.id = :id AND v.conjunto_id = :c LIMIT 1
");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$veh = $st->fetch();
if (!$veh) { flash_set('error', 'Vehículo no encontrado.'); redirect('/vehiculos'); }
if ($veh['archivado_en']) {
    flash_set('warn', 'Este vehículo ya está archivado.');
    redirect('/vehiculos/ver?id=' . $id);
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $nombre_visitante = clean_string($_POST['nombre_visitante'] ?? '', 150);
    $parentesco       = clean_string($_POST['parentesco'] ?? '', 80);
    $celular          = normalizar_celular(clean_string($_POST['celular'] ?? '', 30));
    $recurrente       = isset($_POST['recurrente']) ? 1 : 0;
    $observaciones    = clean_string($_POST['observaciones'] ?? '', 500);
    $confirmar        = isset($_POST['confirmar']);

    if (!$confirmar) $errores[] = 'Debes marcar la casilla de confirmación.';
    if ($nombre_visitante === '' && !$veh['residente_nombre']) {
        // Si no hay nombre en el residente actual, pedir nombre
        $nombre_visitante = 'Visitante Apto ' . $veh['apto_numero'];
    }

    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

            // 1) Verificar que no exista ya en visitantes_vehiculos con esa placa activa
            $stChk = $pdo->prepare("SELECT id FROM visitantes_vehiculos
                                     WHERE conjunto_id = :c AND placa = :p
                                       AND archivado_en IS NULL LIMIT 1");
            $stChk->execute([':c' => $conjuntoId, ':p' => $veh['placa']]);
            $existeVisId = (int)$stChk->fetchColumn();

            if ($existeVisId > 0) {
                // Ya existe en visitantes → actualizar visitas_count + ultima_visita
                $pdo->prepare("UPDATE visitantes_vehiculos
                    SET visitas_count = visitas_count + 1,
                        ultima_visita = NOW(),
                        recurrente = 1,
                        apartamento_id = :a,
                        nombre_visitante = COALESCE(NULLIF(:n,''), nombre_visitante),
                        parentesco = COALESCE(NULLIF(:pa,''), parentesco),
                        celular = COALESCE(NULLIF(:cel,''), celular),
                        marca = COALESCE(NULLIF(:m,''), marca),
                        color = COALESCE(NULLIF(:col,''), color),
                        foto_principal = COALESCE(NULLIF(:fp,''), foto_principal),
                        observaciones = COALESCE(NULLIF(:o,''), observaciones)
                    WHERE id = :id AND conjunto_id = :c2")
                    ->execute([
                        ':a'  => (int)$veh['apartamento_id'],
                        ':n'  => $nombre_visitante ?: '',
                        ':pa' => $parentesco ?: '',
                        ':cel'=> $celular ?: '',
                        ':m'  => $veh['marca'] ?: '',
                        ':col'=> $veh['color'] ?: '',
                        ':fp' => $veh['foto_principal'] ?: '',
                        ':o'  => $observaciones ?: '',
                        ':id' => $existeVisId, ':c2' => $conjuntoId,
                    ]);
                $nuevoVisId = $existeVisId;
            } else {
                // Crear en visitantes_vehiculos
                $ins = $pdo->prepare("INSERT INTO visitantes_vehiculos
                        (conjunto_id, apartamento_id, placa, tipo,
                         nombre_visitante, parentesco, celular,
                         recurrente, visitas_count, primera_visita, ultima_visita,
                         marca, color, foto_principal, observaciones, registrado_por)
                     VALUES
                        (:c, :a, :p, :t,
                         :n, :pa, :cel,
                         :rec, 1, NOW(), NOW(),
                         :m, :col, :fp, :o, :ru)");
                $ins->execute([
                    ':c'   => $conjuntoId,
                    ':a'   => (int)$veh['apartamento_id'],
                    ':p'   => $veh['placa'],
                    ':t'   => in_array($veh['tipo'], ['carro','moto'], true) ? $veh['tipo'] : 'carro',
                    ':n'   => $nombre_visitante ?: ($veh['residente_nombre'] ?: 'Visitante'),
                    ':pa'  => $parentesco ?: null,
                    ':cel' => $celular ?: ($veh['residente_celular'] ?: null),
                    ':rec' => $recurrente,
                    ':m'   => $veh['marca'] ?: null,
                    ':col' => $veh['color'] ?: null,
                    ':fp'  => $veh['foto_principal'] ?: null,
                    ':o'   => $observaciones ?: ($veh['observaciones'] ?: null),
                    ':ru'  => (int)$u['id'],
                ]);
                $nuevoVisId = (int)$pdo->lastInsertId();
            }

            // 2) Archivar el vehículo original (NO se elimina, queda como registro histórico)
            $pdo->prepare("UPDATE vehiculos
                SET archivado_en = NOW(),
                    archivado_motivo = CONCAT(COALESCE(archivado_motivo,''),
                                              CASE WHEN COALESCE(archivado_motivo,'')='' THEN '' ELSE ' | ' END,
                                              :m)
                WHERE id = :id AND conjunto_id = :c")
                ->execute([
                    ':m'  => "Movido a visitantes_vehiculos #{$nuevoVisId} por " . ($u['nombre_completo'] ?? $u['username'] ?? 'usuario'),
                    ':id' => $id,
                    ':c'  => $conjuntoId,
                ]);

            // Audit
            if (function_exists('audit_log')) {
                audit_log('mover_a_visitantes', 'vehiculos', $id,
                          "Vehículo {$veh['placa']} movido a visitantes_vehiculos #{$nuevoVisId}",
                          ['origen' => 'vehiculos'],
                          ['destino' => 'visitantes_vehiculos', 'nuevo_id' => $nuevoVisId]);
            }

            $pdo->commit();
            flash_set('ok', "✅ Vehículo {$veh['placa']} movido a visitantes correctamente. El registro original quedó archivado.");
            redirect('/visitantes/ver?id=' . $nuevoVisId);
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al mover.';
        }
    }
}

$_pageTitle = 'Mover a visitantes: ' . $veh['placa'];
include INCLUDES_PATH . '/header.php';
?>

<style>
.mv-head{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:10px;padding:20px 24px;margin-top:12px}
.mv-head h1{margin:0;font-size:22px}
.mv-head p{margin:6px 0 0;font-size:13px;opacity:.9}
.mv-warn{background:#fef3c7;border-left:4px solid #f59e0b;color:#78350f;padding:14px 18px;border-radius:6px;margin:14px 0;font-size:13px;line-height:1.6}
.mv-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px 26px;margin:14px 0}
.mv-card h3{margin:0 0 12px;font-size:15px;color:#7c3aed;padding-bottom:8px;border-bottom:2px solid #ede9fe}
.mv-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:700px){.mv-grid{grid-template-columns:1fr}}
.mv-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.mv-btn{padding:12px 22px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.mv-btn--primary{background:#7c3aed;color:#fff}
.mv-btn--primary:hover{background:#5b21b6}
.mv-btn--primary:disabled{background:#9ca3af;cursor:not-allowed}
.mv-btn--secondary{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
</style>

<div class="mv-head">
    <h1>👥 Mover a visitantes</h1>
    <p>Migra este vehículo de "vehículos residentes" a "visitantes de apto".</p>
</div>

<div class="toolbar">
    <a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <a class="btn" href="<?= url('/vehiculos/editar?id=' . $id) ?>">✏️ Editar en su lugar</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $er) echo '<li>' . e($er) . '</li>'; ?></ul>
    </div>
<?php endif; ?>

<div class="mv-warn">
    <strong>⚠️ ¿Qué hará esta acción?</strong><br>
    1) Se creará un registro en la tabla <strong>visitantes_vehiculos</strong> con los datos
       de este vehículo (placa, apto, marca, color, foto, etc.).<br>
    2) El vehículo actual quedará <strong>archivado</strong> en <code>vehiculos</code> con
       el motivo "Movido a visitantes_vehiculos #X".<br>
    3) Podrás verlo y editarlo desde <code>/visitantes/</code>.<br>
    <br>
    <em>Si ya existe un visitante con la misma placa activa, se actualiza su
    contador de visitas (+1) y se marca como recurrente.</em>
</div>

<form method="post" action="<?= url('/vehiculos/mover_visitante?id=' . $id) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="mv-card">
        <h3>🚗 Datos del vehículo actual</h3>
        <p style="font-size:13px;color:#6b7280">Estos datos se conservan al mover:</p>
        <div class="mv-grid">
            <div><strong>Placa:</strong> <code><?= e($veh['placa']) ?></code></div>
            <div><strong>Tipo:</strong> <?= $veh['tipo'] === 'moto' ? '🏍️ Moto' : '🚗 Carro' ?></div>
            <div><strong>Apto:</strong> <?= e($veh['apto_numero']) ?></div>
            <div><strong>Marca / Color:</strong> <?= e($veh['marca'] ?: '—') ?> / <?= e($veh['color'] ?: '—') ?></div>
        </div>
    </div>

    <div class="mv-card">
        <h3>👥 Datos como visitante</h3>
        <div class="mv-grid">
            <label class="field">
                <span>Nombre del visitante</span>
                <input type="text" name="nombre_visitante" maxlength="150"
                       value="<?= e($_POST['nombre_visitante'] ?? $veh['residente_nombre'] ?? '') ?>"
                       placeholder="Vacío = 'Visitante Apto <?= e($veh['apto_numero']) ?>'">
            </label>
            <label class="field">
                <span>Parentesco / relación</span>
                <input type="text" name="parentesco" maxlength="80"
                       value="<?= e($_POST['parentesco'] ?? '') ?>"
                       placeholder="Hijo, amigo, familiar, invitado...">
            </label>
            <label class="field">
                <span>Celular</span>
                <input type="tel" name="celular" maxlength="30"
                       value="<?= e($_POST['celular'] ?? $veh['residente_celular'] ?? '') ?>"
                       inputmode="numeric">
            </label>
            <label class="field" style="display:flex;align-items:center;gap:8px;padding-top:20px">
                <input type="checkbox" name="recurrente" value="1"
                       <?= !empty($_POST['recurrente']) ? 'checked' : '' ?>
                       style="width:18px;height:18px">
                <span>Es visitante recurrente</span>
            </label>
        </div>
        <label class="field" style="margin-top:12px">
            <span>Observaciones</span>
            <textarea name="observaciones" maxlength="500" rows="2"><?= e($_POST['observaciones'] ?? $veh['observaciones'] ?? '') ?></textarea>
        </label>
    </div>

    <div class="mv-card" style="border-color:#f59e0b;background:#fffbeb">
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="checkbox" name="confirmar" value="1"
                   <?= !empty($_POST['confirmar']) ? 'checked' : '' ?>
                   style="width:20px;height:20px;margin-top:2px">
            <span style="font-size:14px;color:#78350f">
                <strong>Confirmo</strong> que quiero mover el vehículo <code><?= e($veh['placa']) ?></code>
                (apto <?= e($veh['apto_numero']) ?>) de la tabla vehículos residentes a la tabla
                visitantes. El vehículo original quedará archivado.
            </span>
        </label>

        <div class="mv-actions">
            <button type="submit" class="mv-btn mv-btn--primary">
                👥 Mover a visitantes
            </button>
            <a class="mv-btn mv-btn--secondary" href="<?= url('/vehiculos/ver?id=' . $id) ?>">
                ← Cancelar
            </a>
        </div>
    </div>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
