<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/editar_detalle.php
// v2.0 (3U.1): Editar un registro de revistas_detalle
//   - Cambiar estado, placa, borrar foto
//   - + NUEVO: editar apto del vehículo asociado
//   - + NUEVO: si placa nueva no está en BD, ofrece registrarla

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) redirect('/revistas');

$st = $pdo->prepare("SELECT rd.*, r.conjunto_id, r.id AS revista_id, r.nivel,
                            c.nombre_visible AS celda_nombre, c.numero_orden,
                            ad.numero_visible AS apto_dueno,
                            v.tipo AS veh_tipo, av.numero_visible AS veh_apto
                       FROM revistas_detalle rd
                       JOIN revistas r ON r.id = rd.revista_id
                       JOIN celdas c   ON c.id = rd.celda_id
                  LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
                  LEFT JOIN vehiculos v ON v.id = rd.vehiculo_id
                  LEFT JOIN apartamentos av ON av.id = v.apartamento_id
                      WHERE rd.id = :id AND r.conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$det = $st->fetch();
if (!$det) { flash_set('error', 'Registro no encontrado.'); redirect('/revistas'); }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $accion = $_POST['accion'] ?? '';

    // ── Borrar foto ──
    if ($accion === 'borrar_foto') {
        if ($det['foto_path']) {
            $full = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas/' . $det['foto_path'];
            if (is_file($full)) @unlink($full);
        }
        $pdo->prepare("UPDATE revistas_detalle SET foto_path = NULL WHERE id = :id")->execute([':id' => $id]);
        flash_set('ok', 'Foto borrada.');
        redirect('/revistas/editar_detalle?id=' . $id);
    }

    // ── v7.85: Subir / cambiar la foto de la celda ──
    // Sirve para recuperar fotos que no llegaron al sincronizar.
    if ($accion === 'subir_foto') {
        $okFoto = false;
        $msgErr = '';

        if (empty($_FILES['foto_nueva']) || ($_FILES['foto_nueva']['error'] ?? 9) !== UPLOAD_ERR_OK) {
            $msgErr = 'No se recibió ninguna imagen.';
        } else {
            $tmp  = $_FILES['foto_nueva']['tmp_name'];
            $size = (int)($_FILES['foto_nueva']['size'] ?? 0);

            // Validar que sea imagen de verdad (no confiar en la extensión)
            $info = @getimagesize($tmp);
            $mime = $info['mime'] ?? '';
            $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'jpg', 'image/webp' => 'jpg'];

            if (!$info || !isset($permitidos[$mime])) {
                $msgErr = 'El archivo no es una imagen válida (usá JPG o PNG).';
            } elseif ($size > 8 * 1024 * 1024) {
                $msgErr = 'La imagen supera los 8 MB.';
            } else {
                $revId = (int)$det['revista_id'];
                $base  = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads');
                $dir   = $base . '/revistas/' . $revId;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);

                $nombre = 'celda_' . (int)$det['celda_id'] . '_' . time() . '_' .
                          substr(bin2hex(random_bytes(4)), 0, 6) . '.jpg';
                $destino = $dir . '/' . $nombre;

                // Reencodar a JPG (normaliza formato y quita metadatos raros)
                $img = null;
                if ($mime === 'image/jpeg')      $img = @imagecreatefromjpeg($tmp);
                elseif ($mime === 'image/png')   $img = @imagecreatefrompng($tmp);
                elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp'))
                                                 $img = @imagecreatefromwebp($tmp);

                if ($img) {
                    // Limitar el ancho para que no pese de más
                    $w = imagesx($img); $h = imagesy($img);
                    $maxW = 1600;
                    if ($w > $maxW) {
                        $nh  = (int)round($h * ($maxW / $w));
                        $tmp2 = imagecreatetruecolor($maxW, $nh);
                        imagecopyresampled($tmp2, $img, 0,0,0,0, $maxW, $nh, $w, $h);
                        imagedestroy($img);
                        $img = $tmp2;
                    }

                    // v7.86: MARCA DE FECHA Y HORA (igual que en la revista:
                    // amarillo sobre negro, abajo a la derecha).
                    $W = imagesx($img); $H = imagesy($img);
                    $txt = date('d/m/Y  H:i:s');
                    $fs  = max(14, (int)floor($W * 0.028));   // tamaño de letra
                    $pad = (int)floor($fs * 0.45);
                    $mar = (int)floor($fs * 0.5);

                    // Ancho aproximado del texto con la fuente interna de GD
                    $fuenteGD = 5;
                    $anchoChar = imagefontwidth($fuenteGD);
                    $altoChar  = imagefontheight($fuenteGD);
                    $escala    = max(1, (int)round($fs / $altoChar));
                    $twTxt = strlen($txt) * $anchoChar * $escala;
                    $thTxt = $altoChar * $escala;

                    $cajaW = $twTxt + $pad * 2;
                    $cajaH = $thTxt + $pad * 2;
                    $x0 = $W - $cajaW - $mar;
                    $y0 = $H - $cajaH - $mar;
                    if ($x0 < 0) $x0 = 0;
                    if ($y0 < 0) $y0 = 0;

                    // Fondo negro semitransparente
                    $negro = imagecolorallocatealpha($img, 0, 0, 0, 45);
                    imagefilledrectangle($img, $x0, $y0, $x0 + $cajaW, $y0 + $cajaH, $negro);

                    // Texto amarillo (se dibuja escalado para que se vea grande)
                    $amarillo = imagecolorallocate($img, 255, 221, 0);
                    if ($escala > 1) {
                        // Dibujar en un lienzo chico y ampliarlo (GD no escala texto)
                        $tmpTxt = imagecreatetruecolor($twTxt / $escala, $thTxt / $escala);
                        $bgT = imagecolorallocate($tmpTxt, 0, 0, 0);
                        imagefill($tmpTxt, 0, 0, $bgT);
                        imagecolortransparent($tmpTxt, $bgT);
                        $amT = imagecolorallocate($tmpTxt, 255, 221, 0);
                        imagestring($tmpTxt, $fuenteGD, 0, 0, $txt, $amT);
                        imagecopyresized($img, $tmpTxt,
                            $x0 + $pad, $y0 + $pad, 0, 0,
                            $twTxt, $thTxt, $twTxt / $escala, $thTxt / $escala);
                        imagedestroy($tmpTxt);
                    } else {
                        imagestring($img, $fuenteGD, $x0 + $pad, $y0 + $pad, $txt, $amarillo);
                    }

                    $okFoto = imagejpeg($img, $destino, 85);
                    imagedestroy($img);
                } else {
                    // Sin GD disponible: copiar tal cual (sin marca)
                    $okFoto = @move_uploaded_file($tmp, $destino);
                }

                if ($okFoto) {
                    // Borrar la anterior si había
                    if (!empty($det['foto_path'])) {
                        $ant = $base . '/revistas/' . $det['foto_path'];
                        if (is_file($ant)) @unlink($ant);
                    }
                    $rel = $revId . '/' . $nombre;
                    $pdo->prepare("UPDATE revistas_detalle SET foto_path = :fp WHERE id = :id")
                        ->execute([':fp' => $rel, ':id' => $id]);
                    if (function_exists('audit_log')) {
                        audit_log('update_foto_revista', 'revistas_detalle', $id,
                                  'Subió/cambió la foto de la celda desde el editor', null,
                                  ['foto_path' => $rel]);
                    }
                } else {
                    $msgErr = 'No se pudo guardar la imagen en el servidor.';
                }
            }
        }

        flash_set($okFoto ? 'ok' : 'error',
                  $okFoto ? '📸 Foto actualizada.' : ('No se pudo subir la foto. ' . $msgErr));
        redirect('/revistas/editar_detalle?id=' . $id);
    }

    // ── Eliminar registro ──
    if ($accion === 'eliminar_registro') {
        if ($det['foto_path']) {
            $full = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas/' . $det['foto_path'];
            if (is_file($full)) @unlink($full);
        }
        $pdo->prepare("DELETE FROM revistas_detalle WHERE id = :id")->execute([':id' => $id]);
        _rev_recalcular2($pdo, (int)$det['revista_id']);
        flash_set('ok', 'Registro eliminado (celda queda pendiente).');
        redirect('/revistas/ver?id=' . $det['revista_id']);
    }

    // ── Actualizar apto del vehículo ──
    if ($accion === 'actualizar_apto_vehiculo') {
        if (!$det['vehiculo_id']) { $errores[] = 'No hay vehículo asociado a este registro.'; }
        else {
            $aptoNum = clean_string($_POST['apto_vehiculo'] ?? '', 20);
            $aptoId = null;
            if ($aptoNum !== '') {
                $stA = $pdo->prepare("SELECT id FROM apartamentos WHERE numero_visible = :n AND conjunto_id = :c LIMIT 1");
                $stA->execute([':n' => $aptoNum, ':c' => $conjuntoId]);
                $aptoId = (int)$stA->fetchColumn();
                if (!$aptoId) $errores[] = "Apto '{$aptoNum}' no existe.";
            }
            if (empty($errores)) {
                $pdo->prepare("UPDATE vehiculos SET apartamento_id = :a WHERE id = :v AND conjunto_id = :c")
                    ->execute([':a' => $aptoId, ':v' => (int)$det['vehiculo_id'], ':c' => $conjuntoId]);
                flash_set('ok', 'Apto del vehículo actualizado.');
                redirect('/revistas/editar_detalle?id=' . $id);
            }
        }
    }

    // ── Registrar vehículo nuevo (si la placa no está en BD) ──
    if ($accion === 'registrar_vehiculo') {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/i', '', clean_string($_POST['reg_placa'] ?? '', 15)));
        $tipo  = in_array($_POST['reg_tipo'] ?? '', ['carro','moto'], true) ? $_POST['reg_tipo'] : 'carro';
        $aptoNum = clean_string($_POST['reg_apto'] ?? '', 20);

        if ($placa === '') $errores[] = 'Placa vacía.';
        $stE = $pdo->prepare("SELECT id FROM vehiculos WHERE placa = :p AND conjunto_id = :c LIMIT 1");
        $stE->execute([':p' => $placa, ':c' => $conjuntoId]);
        if ($stE->fetchColumn()) $errores[] = 'La placa ya está registrada.';

        $aptoId = null;
        if (empty($errores) && $aptoNum !== '') {
            $stA = $pdo->prepare("SELECT id FROM apartamentos WHERE numero_visible = :n AND conjunto_id = :c LIMIT 1");
            $stA->execute([':n' => $aptoNum, ':c' => $conjuntoId]);
            $aptoId = (int)$stA->fetchColumn();
            if (!$aptoId) $errores[] = "Apto '{$aptoNum}' no existe.";
        }

        if (empty($errores)) {
            // v7.77: rol elegido — propietario / inquilino / visitante
            $rol = in_array($_POST['reg_rol'] ?? '', ['propietario','inquilino','visitante'], true)
                        ? $_POST['reg_rol'] : 'propietario';

            if ($rol === 'visitante') {
                // ── VISITANTE: va a la tabla de visitantes, no a vehiculos ──
                if (!$aptoId) {
                    $errores[] = 'Para registrar un visitante hay que indicar el apto que visita.';
                } else {
                    $insV = $pdo->prepare("INSERT INTO visitantes_vehiculos
                            (conjunto_id, apartamento_id, placa, tipo, visitas_count,
                             primera_visita, ultima_visita)
                         VALUES (:c, :a, :p, :t, 1, NOW(), NOW())");
                    $insV->execute([':c' => $conjuntoId, ':a' => $aptoId,
                                    ':p' => $placa, ':t' => $tipo]);
                    // El detalle guarda la placa; no hay vehiculo_id porque es visitante
                    $pdo->prepare("UPDATE revistas_detalle SET placa_detectada = :p WHERE id = :id")
                        ->execute([':p' => $placa, ':id' => $id]);
                    if (function_exists('audit_log')) {
                        audit_log('create_visitante', 'visitantes_vehiculos', (int)$pdo->lastInsertId(),
                                  'Registró visitante desde revista placa=' . $placa,
                                  null, ['placa'=>$placa,'apartamento_id'=>$aptoId]);
                    }
                    flash_set('ok', '✅ Visitante ' . $placa . ' registrado (visita al apto ' . $aptoNum . ').');
                    redirect('/revistas/editar_detalle?id=' . $id);
                }
            }

            // ── RESIDENTE (propietario o inquilino) ──
            $resId = null;
            if ($aptoId && ($rol === 'propietario' || $rol === 'inquilino')) {
                try {
                    $sr = $pdo->prepare("SELECT r.id FROM residentes r
                                           JOIN apartamentos a ON a.id = r.apartamento_id
                                          WHERE r.apartamento_id = :a AND a.conjunto_id = :c
                                            AND r.tipo = :t AND r.archivado_en IS NULL
                                            AND r.activo = 1
                                       ORDER BY r.id LIMIT 1");
                    $sr->execute([':a' => $aptoId, ':c' => $conjuntoId, ':t' => $rol]);
                    $rid = (int)$sr->fetchColumn();
                    if ($rid > 0) $resId = $rid;
                } catch (Throwable $e) { /* defensivo */ }
            }

            $ins = $pdo->prepare("INSERT INTO vehiculos (conjunto_id, apartamento_id, residente_id, placa, tipo)
                                   VALUES (:c, :a, :r, :p, :t)");
            $ins->execute([':c' => $conjuntoId, ':a' => $aptoId, ':r' => $resId,
                           ':p' => $placa, ':t' => $tipo]);
            $newVehId = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE revistas_detalle SET vehiculo_id = :v, placa_detectada = :p WHERE id = :id")
                ->execute([':v' => $newVehId, ':p' => $placa, ':id' => $id]);

            flash_set('ok', "Vehículo {$placa} registrado y vinculado.");
            redirect('/revistas/editar_detalle?id=' . $id);
        }
    }

    // ── Guardar cambios generales ──
    if ($accion === '' || $accion === 'guardar') {
        $estado = $_POST['estado'] ?? '';
        $placa  = strtoupper(preg_replace('/[^A-Z0-9]/i', '', clean_string($_POST['placa'] ?? '', 15)));
        $obs    = clean_string($_POST['observacion'] ?? '', 500);

        if (!in_array($estado, ['ocupada','vacia','pendiente'], true)) $errores[] = 'Estado inválido.';

        // ── FIX v3v: no permitir MISMA placa en dos celdas de la misma revista ──
        if (empty($errores) && $placa !== '' && $estado !== 'vacia') {
            $stDup = $pdo->prepare("SELECT c.nombre_visible AS celda_nombre
                                      FROM revistas_detalle rd
                                      JOIN celdas c ON c.id = rd.celda_id
                                     WHERE rd.revista_id = :rv
                                       AND rd.placa_detectada = :pl
                                       AND rd.celda_id != :cd
                                     LIMIT 1");
            $stDup->execute([':rv' => (int)$det['revista_id'], ':pl' => $placa, ':cd' => (int)$det['celda_id']]);
            $dupCelda = $stDup->fetchColumn();
            if ($dupCelda) $errores[] = "⚠️ La placa {$placa} ya está registrada en la celda {$dupCelda} de esta misma revista.";
        }

        if (empty($errores)) {
            $fotoNueva = $det['foto_path'];
            if ($estado === 'vacia' && $det['foto_path']) {
                $full = (defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads') . '/revistas/' . $det['foto_path'];
                if (is_file($full)) @unlink($full);
                $fotoNueva = null;
            }
            if ($estado === 'vacia') $placa = '';

            $vehiculoId = null;
            if ($placa !== '') {
                $stV = $pdo->prepare("SELECT id FROM vehiculos WHERE placa = :p AND conjunto_id = :c AND archivado_en IS NULL LIMIT 1");
                $stV->execute([':p' => $placa, ':c' => $conjuntoId]);
                $vehiculoId = (int)$stV->fetchColumn() ?: null;
            }

            try {
                $pdo->prepare("UPDATE revistas_detalle SET
                        estado = :st, placa_detectada = :pl, vehiculo_id = :v,
                        foto_path = :fp, observacion = :ob
                    WHERE id = :id")
                    ->execute([
                        ':st' => $estado,
                        ':pl' => $placa !== '' ? $placa : null,
                        ':v'  => $vehiculoId,
                        ':fp' => $fotoNueva,
                        ':ob' => $obs !== '' ? $obs : null,
                        ':id' => $id,
                    ]);

                _rev_recalcular2($pdo, (int)$det['revista_id']);
                flash_set('ok', 'Registro actualizado.');
                redirect('/revistas/ver?id=' . $det['revista_id']);
            } catch (Exception $ex) {
                $errores[] = (defined('APP_DEBUG') && APP_DEBUG) ? $ex->getMessage() : 'Error al actualizar.';
            }
        }
    }
}

function _rev_recalcular2($pdo, $revistaId) {
    $st = $pdo->prepare("SELECT
            SUM(CASE WHEN estado = 'ocupada' THEN 1 ELSE 0 END) AS oc,
            SUM(CASE WHEN estado = 'vacia' THEN 1 ELSE 0 END) AS vc,
            COUNT(*) AS rv
        FROM revistas_detalle WHERE revista_id = :r");
    $st->execute([':r' => $revistaId]);
    $c = $st->fetch();
    $pdo->prepare("UPDATE revistas SET celdas_revisadas = :rv, celdas_ocupadas = :oc, celdas_vacias = :vc WHERE id = :id")
        ->execute([':rv' => (int)$c['rv'], ':oc' => (int)$c['oc'], ':vc' => (int)$c['vc'], ':id' => $revistaId]);
}

$urlFoto = $det['foto_path'] ? url('/uploads/revistas/' . $det['foto_path']) : null;
$_pageTitle = 'Editar ' . $det['celda_nombre'];
include INCLUDES_PATH . '/header.php';
?>

<style>
.edit-panel{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;max-width:640px;}
.edit-panel .form-row{margin-bottom:12px;}
.edit-panel label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;}
.edit-panel select,.edit-panel input,.edit-panel textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;}
.edit-panel .info{background:#f8fafc;padding:10px;border-radius:6px;margin-bottom:14px;font-size:13px;}
.foto-preview{max-width:300px;border-radius:6px;margin:10px 0;display:block;cursor:zoom-in;}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;}
.foto-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:zoom-out;}
.foto-modal.mostrar{display:flex;}
.foto-modal img{max-width:100%;max-height:100%;}
.veh-box{background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:12px;margin:12px 0;}
.veh-box h4{margin:0 0 8px;font-size:14px;color:#1e3a8a;}
.veh-box .row-form{display:flex;gap:8px;align-items:end;flex-wrap:wrap;}
.veh-box .row-form > div{flex:1;min-width:150px;}
.veh-box .row-form button{padding:8px 14px;background:#1e6cff;color:#fff;border:none;border-radius:5px;cursor:pointer;}
.no-veh-box{background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:12px;margin:12px 0;}
.no-veh-box h4{margin:0 0 8px;font-size:14px;color:#92400e;}
</style>

<div class="page-head">
    <h1 class="page-head__title">✏️ Editar celda <?= e($det['celda_nombre']) ?></h1>
</div>

<div class="toolbar">
<a class="btn" href="#" onclick="window.history.back(); return false;">← Volver</a>
    <a class="btn" href="<?= url('/revistas/ejecutar?id=' . $det['revista_id']) ?>">▶️ Continuar revista</a>
</div>

<?php if (!empty($errores)): ?>
    <div class="flash flash--error">
        <ul style="margin:0 0 0 18px"><?php foreach ($errores as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="edit-panel">
    <div class="info">
        <strong>Revista:</strong> #<?= (int)$det['revista_id'] ?> — Nivel <?= e($det['nivel']) ?><br>
        <strong>Celda:</strong> <?= e($det['celda_nombre']) ?> (#<?= (int)$det['numero_orden'] ?>)<br>
        <?php if ($det['apto_dueno']): ?><strong>Apto dueño celda:</strong> <?= e($det['apto_dueno']) ?><br><?php endif; ?>
        <strong>Revisada:</strong> <?= e(date('d/m/Y H:i', strtotime($det['revisado_en']))) ?>
    </div>

    <!-- ── Formulario general ── -->
    <form method="POST">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="accion" value="guardar">

        <?php if ($urlFoto): ?>
            <div class="form-row">
                <label>Foto actual</label>
                <img src="<?= e($urlFoto) ?>" class="foto-preview" onclick="ampliarFoto(this.src)">
                <button type="button" onclick="borrarFotoConfirm()" class="btn"
                        style="background:#fee2e2;color:#991b1b">
                    🗑️ Borrar foto
                </button>
            </div>
        <?php else: ?>
            <div class="form-row">
                <label>Foto</label>
                <div style="background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;
                            padding:10px 12px;font-size:13.5px;color:#92400e">
                    ⚠️ Esta celda <b>no tiene foto</b>. Podés subirla abajo.
                </div>
            </div>
        <?php endif; ?>

        <div class="form-row">
            <label>Estado *</label>
            <select name="estado" required>
                <option value="ocupada"   <?= $det['estado'] === 'ocupada'   ? 'selected' : '' ?>>✅ Ocupada</option>
                <option value="vacia"     <?= $det['estado'] === 'vacia'     ? 'selected' : '' ?>>⭕ Vacía (borra foto y placa)</option>
                <option value="pendiente" <?= $det['estado'] === 'pendiente' ? 'selected' : '' ?>>❓ Pendiente</option>
            </select>
        </div>

        <div class="form-row">
            <label>Placa detectada</label>
            <input type="text" name="placa" maxlength="15" value="<?= e($det['placa_detectada']) ?>"
                   style="text-transform:uppercase;font-family:monospace;letter-spacing:2px;font-weight:700"
                   oninput="this.value=this.value.toUpperCase()">
            <small style="color:#6b7280">Si corriges la placa, se busca en BD al guardar.</small>
        </div>

        <div class="form-row">
            <label>Observación</label>
            <textarea name="observacion" maxlength="500" rows="2"><?= e($det['observacion']) ?></textarea>
        </div>

        <div class="actions">
            <button type="submit" class="btn btn--primary">💾 Guardar cambios</button>
        </div>
    </form>

    <!-- ── v7.85: Subir / cambiar la foto de la celda ──
         Sirve para recuperar fotos que no llegaron al sincronizar. -->
    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;
                padding:14px 16px;margin-top:14px">
        <h4 style="margin:0 0 8px;font-size:15px;color:#1e40af">
            📸 <?= $urlFoto ? 'Cambiar la foto' : 'Subir la foto que falta' ?>
        </h4>
        <p class="muted" style="font-size:12.5px;margin:0 0 10px">
            Elegí la imagen desde el computador o el celular.
            <?= $urlFoto ? 'Reemplaza la actual.' : '' ?>
        </p>
        <form method="post" enctype="multipart/form-data" id="form-foto"
              onsubmit="return confirm('<?= $urlFoto ? '¿Reemplazar la foto actual?' : '¿Subir esta foto?' ?>');">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="accion" value="subir_foto">

            <!-- v7.86: dos formas de poner la foto -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                <button type="button" class="btn" style="background:#1e6cff;color:#fff"
                        onclick="document.getElementById('foto-camara').click()">
                    📷 Tomar foto
                </button>
                <button type="button" class="btn"
                        onclick="document.getElementById('foto-archivo').click()">
                    📁 Elegir archivo
                </button>
            </div>

            <!-- Cámara (en el celular abre la cámara directamente) -->
            <input type="file" id="foto-camara" name="foto_nueva" accept="image/*"
                   capture="environment" hidden onchange="fotoElegida(this)">
            <!-- Archivo del equipo -->
            <input type="file" id="foto-archivo" name="foto_nueva" accept="image/*"
                   hidden onchange="fotoElegida(this)">

            <div id="foto-preview-nueva" style="display:none;margin-bottom:10px">
                <img id="foto-preview-img" alt="Vista previa"
                     style="max-width:260px;max-height:200px;border-radius:8px;border:1px solid #d1d5db">
                <div id="foto-nombre" class="muted" style="font-size:12px;margin-top:4px"></div>
            </div>

            <button type="submit" class="btn btn--primary" style="background:#0e7490" id="btn-subir-foto" disabled>
                📤 <?= $urlFoto ? 'Reemplazar foto' : 'Subir foto' ?>
            </button>
            <span class="muted" style="font-size:12px;margin-left:8px">
                Se le agrega la fecha y hora automáticamente.
            </span>
        </form>

        <script>
        // v7.86: solo se envía el input que el usuario usó (cámara o archivo)
        function fotoElegida(input) {
            var otro = (input.id === 'foto-camara')
                        ? document.getElementById('foto-archivo')
                        : document.getElementById('foto-camara');
            // Desactivar el otro para que no viaje vacío
            otro.disabled = true;
            input.disabled = false;

            var f = input.files && input.files[0];
            var box = document.getElementById('foto-preview-nueva');
            var img = document.getElementById('foto-preview-img');
            var nom = document.getElementById('foto-nombre');
            var btn = document.getElementById('btn-subir-foto');
            if (!f) { box.style.display = 'none'; btn.disabled = true; return; }

            img.src = URL.createObjectURL(f);
            nom.textContent = f.name + ' · ' + Math.round(f.size / 1024) + ' KB';
            box.style.display = 'block';
            btn.disabled = false;
        }
        </script>
    </div>

    <!-- ── NUEVO: Editor de apto del vehículo ── -->
    <?php if ($det['vehiculo_id']): ?>
        <div class="veh-box">
            <h4>🚗 Vehículo vinculado: <?= e($det['placa_detectada']) ?>
                <?= $det['veh_tipo'] === 'moto' ? '(🏍️ Moto)' : '(🚗 Carro)' ?>
            </h4>
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="accion" value="actualizar_apto_vehiculo">
                <div class="row-form">
                    <div>
                        <label style="font-size:13px;font-weight:500">Apto al que pertenece el vehículo</label>
                        <input type="text" name="apto_vehiculo" maxlength="20"
                               value="<?= e($det['veh_apto']) ?>" placeholder="ej: 1502">
                        <small style="color:#6b7280">Deja vacío para desvincular. Este cambio afecta la BD de vehículos.</small>
                    </div>
                    <button type="submit">💾 Actualizar apto</button>
                </div>
            </form>
        </div>
    <?php elseif ($det['placa_detectada']): ?>
        <!-- Placa detectada pero NO está en BD → ofrecer registrarla -->
        <div class="no-veh-box">
            <h4>⚠️ Placa <?= e($det['placa_detectada']) ?> NO está en la BD de vehículos</h4>
            <p style="margin:0 0 10px;font-size:13px">Puedes registrarla ahora:</p>
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="accion" value="registrar_vehiculo">
                <div class="row-form">
                    <div>
                        <label style="font-size:13px;font-weight:500">Placa</label>
                        <input type="text" name="reg_placa" maxlength="15" value="<?= e($det['placa_detectada']) ?>"
                               style="text-transform:uppercase;font-family:monospace;font-weight:700" required>
                    </div>
                    <div style="max-width:120px">
                        <label style="font-size:13px;font-weight:500">Tipo</label>
                        <select name="reg_tipo">
                            <option value="carro">🚗 Carro</option>
                            <option value="moto">🏍️ Moto</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:13px;font-weight:500">Apto</label>
                        <input type="text" name="reg_apto" maxlength="20" placeholder="ej: 1502">
                    </div>
                    <div style="max-width:150px">
                        <label style="font-size:13px;font-weight:500">Registrar como</label>
                        <select name="reg_rol">
                            <option value="propietario">🔑 Propietario</option>
                            <option value="inquilino">🏠 Inquilino</option>
                            <option value="visitante">👥 Visitante</option>
                        </select>
                    </div>
                    <button type="submit" style="background:#dc2626">📝 Registrar</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Eliminar todo el registro -->
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb">
        <form method="POST" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="accion" value="eliminar_registro">
            <button type="submit" class="btn" style="background:#dc2626;color:#fff"
                    onclick="return confirm('⚠️ ¿Eliminar este registro?\nLa celda quedará como PENDIENTE.\nLa foto también se borra.');">
                🗑️ Eliminar registro completo
            </button>
        </form>
    </div>
</div>

<!-- Form oculto para borrar foto -->
<form method="POST" id="form-borrar-foto" style="display:none">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="accion" value="borrar_foto">
</form>

<div class="foto-modal" id="foto-modal" onclick="cerrarFoto()">
    <img id="foto-modal-img" src="" alt="">
</div>

<script>
function ampliarFoto(src){ document.getElementById('foto-modal-img').src=src; document.getElementById('foto-modal').classList.add('mostrar'); }
function cerrarFoto(){ document.getElementById('foto-modal').classList.remove('mostrar'); }
function borrarFotoConfirm(){
    if (confirm('¿Borrar la foto? El registro queda sin foto.')) {
        document.getElementById('form-borrar-foto').submit();
    }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
