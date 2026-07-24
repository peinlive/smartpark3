<?php
// /home/myzonaco/smartpark.myzona360.com/modules/revistas/ejecutar.php
// v3.0 (3U.1):
//   - OCR más robusto: acepta muchos formatos de respuesta, logs para debug
//   - Foto de mayor calidad (mejor lectura OCR)
//   - Celda YA revisada: edición INLINE (placa, estado, foto, apto vehículo)
//   - Botón "Reintentar OCR" si no leyó bien

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin','admin','supervisor','porteria','ronda');

$pdo = db();
$u   = auth_user();
$uid = (int)($u['id'] ?? 0);
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'ID inválido.'); redirect('/revistas'); }

$st = $pdo->prepare("SELECT * FROM revistas WHERE id = :id AND conjunto_id = :c LIMIT 1");
$st->execute([':id' => $id, ':c' => $conjuntoId]);
$rev = $st->fetch();
if (!$rev) { flash_set('error', 'Revista no encontrada.'); redirect('/revistas'); }
if ($rev['estado'] !== 'en_curso') { flash_set('warn', 'Ya no está en curso.'); redirect('/revistas/ver?id=' . $id); }

$stN = $pdo->prepare("SELECT id, codigo, nombre FROM niveles_parqueadero
                       WHERE conjunto_id = :c AND codigo = :cd LIMIT 1");
$stN->execute([':c' => $conjuntoId, ':cd' => $rev['nivel']]);
$nivel = $stN->fetch();
if (!$nivel) { flash_set('error', "El nivel '{$rev['nivel']}' ya no existe."); redirect('/revistas'); }

$stC = $pdo->prepare("SELECT c.id, c.nombre_visible, c.numero_orden, c.tipo,
        c.permite_carro, c.permite_moto,
        ad.numero_visible AS apto_dueno,
        (SELECT ac.apto_usuario_id FROM asignaciones_celdas ac
          WHERE ac.celda_id = c.id AND ac.activa = 1
       ORDER BY ac.fecha_inicio DESC LIMIT 1) AS apto_usuario_id
    FROM celdas c
    LEFT JOIN apartamentos ad ON ad.id = c.apto_dueno_id
    WHERE c.nivel_id = :nv AND c.conjunto_id = :c AND c.activa = 1
    ORDER BY CAST(REGEXP_REPLACE(c.nombre_visible, '[^0-9]', '') AS UNSIGNED) ASC, c.nombre_visible ASC");
$stC->execute([':nv' => $nivel['id'], ':c' => $conjuntoId]);
$celdas = $stC->fetchAll();

$stD = $pdo->prepare("SELECT celda_id, estado, placa_detectada, foto_path, vehiculo_id
                       FROM revistas_detalle WHERE revista_id = :rv");
$stD->execute([':rv' => $id]);
$yaRevisadas = [];
foreach ($stD->fetchAll() as $d) $yaRevisadas[(int)$d['celda_id']] = $d;

$aptoUsuarios = array_filter(array_column($celdas, 'apto_usuario_id'));
$vehsPorApto = []; $aptoNums = [];
if (!empty($aptoUsuarios)) {
    $inPh = []; $inPr = [':c' => $conjuntoId];
    foreach ($aptoUsuarios as $i => $aid) { $k = ':a' . $i; $inPh[] = $k; $inPr[$k] = (int)$aid; }
    $inSql = implode(',', $inPh);
    $stV = $pdo->prepare("SELECT apartamento_id, placa, tipo FROM vehiculos
                          WHERE apartamento_id IN ($inSql) AND conjunto_id = :c AND archivado_en IS NULL");
    $stV->execute($inPr);
    foreach ($stV->fetchAll() as $v) $vehsPorApto[$v['apartamento_id']][] = $v;
    $stA = $pdo->prepare("SELECT id, numero_visible FROM apartamentos WHERE id IN ($inSql) AND conjunto_id = :c");
    $stA->execute($inPr);
    foreach ($stA->fetchAll() as $a) $aptoNums[(int)$a['id']] = $a['numero_visible'];
}

$celdasJs = [];
foreach ($celdas as $c) {
    $cid = (int)$c['id'];
    $vhs = $c['apto_usuario_id'] ? ($vehsPorApto[(int)$c['apto_usuario_id']] ?? []) : [];
    $vhsStr = [];
    foreach ($vhs as $v) $vhsStr[] = ['placa' => $v['placa'], 'tipo' => $v['tipo']];
    $existe = $yaRevisadas[$cid] ?? null;
    $celdasJs[] = [
        'id'         => $cid,
        'nombre'     => $c['nombre_visible'],
        'orden'      => (int)$c['numero_orden'],
        'tipo'       => $c['tipo'],
        'apto_dueno' => $c['apto_dueno'],
        'apto_uso'   => $c['apto_usuario_id'] ? ($aptoNums[(int)$c['apto_usuario_id']] ?? null) : null,
        'vehiculos'  => $vhsStr,
        'estado'     => $existe['estado'] ?? null,
        'placa'      => $existe['placa_detectada'] ?? null,
        'foto'       => $existe['foto_path'] ?? null,
        'vehiculo_id'=> !empty($existe['vehiculo_id']) ? (int)$existe['vehiculo_id'] : null,
    ];
}

$_pageTitle = 'Revista ' . $rev['nivel'];
include INCLUDES_PATH . '/header.php';
?>

<style>
.rev-header{background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-top:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
.rev-header h2{margin:0;color:#1e3a8a;font-size:16px;}
.rev-progress{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin:12px 0;}
.rev-progress-bar{background:#e5e7eb;border-radius:6px;overflow:hidden;height:8px;margin-top:8px;}
.rev-progress-bar>span{background:#1e6cff;display:block;height:100%;transition:width .3s;}
.rev-contadores{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.rev-c{background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;flex:1;min-width:100px;text-align:center;}
.rev-c strong{display:block;font-size:18px;line-height:1;}
.rev-c.ok strong{color:#15803d;} .rev-c.vc strong{color:#d97706;} .rev-c.pd strong{color:#dc2626;}

.celda-panel{background:#fff;border:2px solid #1e6cff;border-radius:10px;padding:20px;margin:12px 0;}
.celda-panel.revisada{border-color:#15803d;}
.celda-panel h3{margin:0 0 8px;font-size:20px;color:#1e3a8a;}
.celda-info{background:#f8fafc;border-radius:6px;padding:10px;margin-bottom:12px;font-size:13px;}
.celda-info .row{display:flex;justify-content:space-between;padding:3px 0;}
.celda-info .row span:first-child{color:#6b7280;}
.badge-tipo{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#e5e7eb;color:#374151;}
.vhs-list{margin-top:6px;font-size:12px;}
.vhs-list .vh{display:inline-block;background:#dbeafe;color:#1e3a8a;padding:2px 8px;border-radius:4px;margin:2px 3px 2px 0;font-family:monospace;}

.camara-box{background:#f3f4f6;border:2px dashed #9ca3af;border-radius:8px;padding:30px 20px;text-align:center;margin-top:10px;}
.camara-box.hidden{display:none;}
.btn-camara{background:#1e6cff;color:#fff;padding:16px 28px;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;}
.btn-camara:hover{background:#1858d8;}
.btn-camara input[type=file]{display:none;}

.resultado-box{background:#f0fdf4;border:2px solid #86efac;border-radius:8px;padding:16px;margin-top:10px;display:none;}
.resultado-box.mostrar{display:block;}
.resultado-box.no-encontrada{background:#fffbeb;border-color:#fbbf24;}
.resultado-box img.preview{max-width:100%;max-height:280px;border-radius:6px;display:block;margin:0 auto 10px;cursor:zoom-in;}
.placa-detectada{display:flex;gap:8px;align-items:center;justify-content:center;margin:10px 0;}
.placa-input{padding:10px 14px;border:2px solid #1e6cff;border-radius:6px;font-size:24px;font-family:monospace;text-transform:uppercase;text-align:center;letter-spacing:3px;width:200px;font-weight:700;}
.hint-encontrada{background:#dcfce7;color:#166534;padding:8px 12px;border-radius:5px;font-size:13px;text-align:center;margin:8px 0;}
.hint-no-encontrada{background:#fef3c7;color:#92400e;padding:8px 12px;border-radius:5px;font-size:13px;text-align:center;margin:8px 0;}
.hint-error{background:#fee2e2;color:#991b1b;padding:8px 12px;border-radius:5px;font-size:13px;text-align:center;margin:8px 0;}
.acciones{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:12px;}
.acciones button{padding:10px 16px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-weight:500;}
.acciones button.primary{background:#15803d;color:#fff;border-color:#15803d;}
.acciones button.info{background:#1e6cff;color:#fff;border-color:#1e6cff;}
.acciones button.warn{background:#d97706;color:#fff;border-color:#d97706;}
.acciones button.danger{background:#dc2626;color:#fff;border-color:#dc2626;}
.acciones button.muted{background:#6b7280;color:#fff;border-color:#6b7280;}

.nav-buttons{display:flex;justify-content:space-between;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #e5e7eb;}
.list-mini{margin-top:14px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px;}
.list-mini h4{margin:0 0 8px;font-size:13px;color:#374151;}
.list-mini-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(60px,1fr));gap:4px;}
.list-mini-cell{padding:4px 6px;border:1px solid #e5e7eb;border-radius:4px;text-align:center;font-size:11px;font-family:monospace;cursor:pointer;background:#fff;}
.list-mini-cell:hover{background:#f3f4f6;}
.list-mini-cell.actual{background:#1e6cff;color:#fff;border-color:#1e6cff;font-weight:700;}
.list-mini-cell.st-ocupada{background:#dcfce7;color:#166534;border-color:#86efac;}
.list-mini-cell.st-vacia{background:#fef3c7;color:#92400e;border-color:#fbbf24;}
.list-mini-cell.st-pendiente{background:#fee2e2;color:#991b1b;border-color:#f87171;}

.foto-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:20px;cursor:zoom-out;}
.foto-modal.mostrar{display:flex;}
.foto-modal img{max-width:100%;max-height:100%;border-radius:6px;}
.foto-modal .cerrar{position:absolute;top:20px;right:20px;background:rgba(255,255,255,.9);border:none;border-radius:50%;width:44px;height:44px;font-size:22px;cursor:pointer;}

.modal-reg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998;align-items:center;justify-content:center;padding:20px;}
.modal-reg.mostrar{display:flex;}
.modal-reg-box{background:#fff;border-radius:10px;padding:24px;max-width:400px;width:100%;}
.modal-reg-box h3{margin:0 0 14px;color:#1e3a8a;}
.modal-reg-box label{display:block;font-size:13px;color:#374151;margin-bottom:4px;font-weight:500;margin-top:10px;}
.modal-reg-box input,.modal-reg-box select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;}
.modal-reg-box .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;}

.apto-editor{background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:10px;margin-top:10px;font-size:13px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:center;}
.apto-editor input{padding:6px 10px;border:1px solid #93c5fd;border-radius:4px;font-family:monospace;font-weight:600;width:110px;text-align:center;}
.apto-editor button{padding:5px 10px;background:#1e6cff;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:12px;}

.estado-selector{display:flex;gap:6px;justify-content:center;margin:10px 0;flex-wrap:wrap;}
.estado-selector label{padding:6px 12px;border:1.5px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;}
.estado-selector label.sel{background:#1e6cff;color:#fff;border-color:#1e6cff;}
.estado-selector input[type=radio]{display:none;}

.debug-panel{background:#1f2937;color:#e5e7eb;border-radius:6px;padding:10px;margin-top:10px;font-family:monospace;font-size:11px;max-height:200px;overflow:auto;display:none;white-space:pre-wrap;}
.debug-panel.mostrar{display:block;}
.debug-toggle{color:#6b7280;font-size:11px;cursor:pointer;text-decoration:underline;display:inline-block;margin-top:5px;}
</style>

<div class="rev-header">
    <div>
        <h2>▶️ Revista #<?= $id ?> — <?= e($rev['nivel']) ?><?= $nivel['nombre'] ? ' — ' . e($nivel['nombre']) : '' ?></h2>
        <div style="font-size:12px;color:#1e40af">
            Iniciada <?= e(date('d/m/Y H:i', strtotime($rev['iniciado_en']))) ?>
        </div>
    </div>
    <div>
        <a class="btn" href="<?= url('/revistas') ?>">← Salir</a>
    </div>
</div>

<div class="rev-progress">
    <div style="display:flex;justify-content:space-between;font-size:14px;">
        <strong id="lbl-progreso">0 / <?= count($celdas) ?></strong>
        <span id="lbl-pct">0%</span>
    </div>
    <div class="rev-progress-bar"><span id="bar-progreso" style="width:0%"></span></div>
    <div class="rev-contadores">
        <div class="rev-c ok"><strong id="cnt-ok">0</strong><span>✅ Ocupadas</span></div>
        <div class="rev-c vc"><strong id="cnt-vc">0</strong><span>⭕ Vacías</span></div>
        <div class="rev-c pd"><strong id="cnt-pd">0</strong><span>❓ Pendientes</span></div>
    </div>
</div>

<div class="celda-panel" id="panel-celda">
    <h3>Celda <span id="celda-nombre">—</span></h3>
    <div class="celda-info" id="celda-info"></div>

    <div class="camara-box" id="camara-box">
        <label class="btn-camara" for="cam-input">
            📸 Tomar foto de la celda
            <input type="file" id="cam-input" accept="image/*" capture="environment">
        </label>
        <div style="margin-top:10px;font-size:12px;color:#6b7280">O si la celda está vacía:</div>
        <div style="margin-top:6px">
            <button type="button" onclick="marcarVacia()"
                    style="padding:8px 16px;background:#d97706;color:#fff;border:none;border-radius:5px;cursor:pointer">
                ⭕ Marcar como VACÍA
            </button>
        </div>
    </div>

    <div class="resultado-box" id="resultado-box">
        <img id="preview-img" class="preview" src="" alt="" onclick="ampliarFoto(this.src)" onerror="onImgError()">
        <div id="no-foto-placeholder" style="display:none;text-align:center;padding:36px 20px;background:#fef3c7;border:2px dashed #f59e0b;border-radius:8px;color:#92400e;margin-bottom:10px;">
            <div style="font-size:48px;line-height:1;">📷</div>
            <div style="margin-top:8px;font-weight:600;font-size:14px;">Esta celda no tiene foto guardada</div>
            <div style="font-size:12px;margin-top:4px;color:#78350f;">Usa "📸 Cambiar foto" abajo para agregar una y comparar con el dato registrado.</div>
        </div>

        <div id="hint-loading" style="text-align:center;padding:10px;color:#6b7280;display:none">
            ⏳ Detectando placa...
        </div>

        <div id="placa-editor" style="display:none">
            <div class="estado-selector" id="estado-sel">
                <label data-val="ocupada"><input type="radio" name="estado-r" value="ocupada"> ✅ Ocupada</label>
                <label data-val="vacia"><input type="radio" name="estado-r" value="vacia"> ⭕ Vacía</label>
                <label data-val="pendiente"><input type="radio" name="estado-r" value="pendiente"> ❓ Pendiente</label>
            </div>

            <div style="text-align:center;font-size:13px;color:#374151">Placa:</div>
            <div class="placa-detectada">
                <input type="text" id="placa-input" class="placa-input" maxlength="10" oninput="this.value=this.value.toUpperCase()">
            </div>
            <div id="hint-lookup"></div>

            <div class="apto-editor" id="apto-editor" style="display:none">
                <span>Apto vehículo:</span>
                <input type="text" id="apto-input" maxlength="20" placeholder="ej: 1502">
                <button type="button" onclick="actualizarAptoVehiculo()">💾 Cambiar apto</button>
            </div>

            <div class="acciones">
                <button type="button" class="primary" onclick="guardarPaso()">💾 Guardar y siguiente</button>
                <button type="button" class="info"    onclick="verificarPlaca()">🔍 Verificar en BD</button>
                <button type="button" class="warn"    onclick="reintentarOcr()" id="btn-reintentar" style="display:none">🔄 Reintentar OCR</button>
                <button type="button" class="muted"   onclick="cambiarFoto()">📸 Cambiar foto</button>
            </div>
            <div class="acciones" id="acciones-extra" style="display:none">
                <button type="button" class="danger" onclick="abrirRegistrar()">📝 Registrar vehículo nuevo</button>
            </div>
            <!-- v7.70: registrar novedad SIN salir de la revista -->
            <div class="acciones" id="acciones-novedad" style="display:none">
                <button type="button" class="warn" onclick="abrirNovedad()"
                        style="background:#92400e;color:#fff">⚠️ Registrar novedad</button>
            </div>

            <span class="debug-toggle" onclick="toggleDebug()">⚙️ Ver respuesta del OCR (debug)</span>
            <div class="debug-panel" id="debug-panel"></div>
        </div>
    </div>

    <div class="nav-buttons">
        <button class="btn" onclick="irCelda(idxActual - 1)" id="btn-anterior">← Anterior</button>
        <button class="btn" onclick="irCelda(idxActual + 1)" id="btn-siguiente">Siguiente →</button>
    </div>
</div>

<div class="list-mini">
    <h4>Todas las celdas · click para editar cualquiera · <span style="color:#dc2626">rojas=pendientes</span> · <span style="color:#166534">verdes=ocupadas</span> · <span style="color:#92400e">amarillas=vacías</span></h4>
    <div class="list-mini-grid" id="mini-grid"></div>
</div>

<div style="margin-top:20px;text-align:center;padding:16px;background:#f8fafc;border-radius:8px;">
    <button type="button" class="btn btn--primary" style="padding:12px 24px;font-size:16px" onclick="terminarRevista()">
        ✅ Terminar revista
    </button>
</div>

<div class="foto-modal" id="foto-modal" onclick="cerrarFoto()">
    <button class="cerrar" onclick="cerrarFoto()">✕</button>
    <img id="foto-modal-img" src="" alt="">
</div>

<div class="modal-reg" id="modal-reg">
    <div class="modal-reg-box">
        <h3>📝 Registrar vehículo nuevo</h3>

        <label>Placa</label>
        <input type="text" id="reg-placa" maxlength="10" style="text-transform:uppercase;font-family:monospace">

        <label>Tipo de vehículo</label>
        <select id="reg-tipo">
            <option value="carro">🚗 Carro</option>
            <option value="moto">🏍️ Moto</option>
        </select>

        <label>¿A qué corresponde?</label>
        <!-- v7.69: se puede elegir propietario / inquilino / visitante -->
        <div style="display:flex;gap:6px;margin-top:2px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:4px;flex:1;min-width:110px;padding:8px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;background:#eff6ff" id="tr-res-lbl">
                <input type="radio" name="reg-tipo-registro" value="propietario" checked onchange="regTipoRegistroCambio()"> 🔑 Propietario
            </label>
            <label style="display:flex;align-items:center;gap:4px;flex:1;min-width:110px;padding:8px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer" id="tr-inq-lbl">
                <input type="radio" name="reg-tipo-registro" value="inquilino" onchange="regTipoRegistroCambio()"> 🏠 Inquilino
            </label>
            <label style="display:flex;align-items:center;gap:4px;flex:1;min-width:110px;padding:8px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer" id="tr-vis-lbl">
                <input type="radio" name="reg-tipo-registro" value="visitante" onchange="regTipoRegistroCambio()"> 👥 Visitante
            </label>
        </div>

        <label id="reg-apto-lbl">Apto dueño (opcional)</label>
        <input type="text" id="reg-apto" placeholder="ej: 1502" oninput="regAptoCambio()" onblur="regCargarResidentes()">
        <small id="reg-apto-hint" style="color:#6b7280;font-size:11px;display:block;margin-top:2px"></small>

        <div id="reg-res-wrap" style="display:none;margin-top:10px">
            <label>Residente asignado (opcional)</label>
            <select id="reg-residente">
                <option value="">— Sin residente específico —</option>
            </select>
            <small style="color:#6b7280;font-size:11px;display:block;margin-top:2px">Si el apto tiene varios residentes, elige a cuál pertenece el vehículo.</small>
        </div>

        <div class="actions">
            <button type="button" class="btn" onclick="cerrarModalReg()">Cancelar</button>
            <button type="button" class="btn btn--primary" onclick="registrarVehiculo()">Registrar</button>
        </div>
    </div>
</div>

<!-- v7.70: Modal para registrar una NOVEDAD durante la revista -->
<div class="modal" id="modal-novedad" style="display:none">
    <div class="modal-box">
        <h3>⚠️ Registrar novedad</h3>
        <p style="font-size:13px;color:#6b7280;margin:-6px 0 10px">
            Placa: <b id="nov-placa-txt" style="font-family:monospace"></b>
            · Celda: <b id="nov-celda-txt"></b>
        </p>

        <label>Tipo de novedad</label>
        <select id="nov-tipo">
            <option value="mal_parqueo">🚫 Mal parqueo</option>
            <option value="advertencia">⚠️ Advertencia</option>
            <option value="reincidencia">🔁 Reincidencia</option>
            <option value="queja">📢 Queja</option>
            <option value="otro" selected>📌 Otro</option>
        </select>

        <label>Gravedad</label>
        <select id="nov-gravedad">
            <option value="ninguna" selected>⚪ Ninguna</option>
            <option value="leve">🟢 Leve</option>
            <option value="media">🟡 Media</option>
            <option value="grave">🔴 Grave</option>
        </select>

        <label>Descripción *</label>
        <textarea id="nov-desc" rows="3" maxlength="1000"
                  placeholder="Ej: luces encendidas, mal parqueado, alarma sonando..."></textarea>

        <div id="nov-msg" style="display:none;padding:8px 12px;border-radius:6px;margin-top:8px;font-size:13px"></div>

        <div class="actions">
            <button type="button" class="btn" onclick="cerrarNovedad()">Cancelar</button>
            <button type="button" class="btn btn--primary" id="nov-btn-guardar"
                    onclick="guardarNovedad()" style="background:#92400e">Guardar novedad</button>
        </div>
    </div>
</div>

<!-- v4a (OFFLINE): OCR local + padron en IndexedDB.
     Si algo de esto falla, el sistema cae al servidor como antes: cero regresion. -->
<script src="<?= url('/assets/ocr/vendor/ort/ort.min.js') ?>"></script>
<script src="<?= url('/assets/ocr/sp_ocr.js') ?>"></script>
<script src="<?= url('/assets/js/sp_padron.js') ?>"></script>
<script src="<?= url('/assets/js/sp_cola.js') ?>"></script>

<script>
window.REV_ID = <?= $id ?>;
window.REV_CSRF = <?= json_encode(csrf_token()) ?>;
window.API_GUARDAR   = <?= json_encode(url('/revistas/api_guardar_paso')) ?>;
window.API_LOOKUP    = <?= json_encode(url('/revistas/api_placa_lookup')) ?>;
window.API_REGISTRAR = <?= json_encode(url('/revistas/api_registrar_vehiculo')) ?>;
window.API_APTO      = <?= json_encode(url('/revistas/api_actualizar_apto')) ?>;
window.API_RES_APTO  = <?= json_encode(url('/revistas/api_residentes_apto')) ?>;
window.API_OCR       = <?= json_encode(url('/api/ocr_placa')) ?>;
window.API_PADRON    = <?= json_encode(url('/revistas/api_padron')) ?>;   // v4a
window.API_NOVEDAD_REV = <?= json_encode(url('/consultas/registrar_novedad')) ?>;  // v7.70
window.CSRF_TOKEN      = <?= json_encode(csrf_token()) ?>;                          // v7.70
window.OCR_BASE      = <?= json_encode(url('/assets/ocr/')) ?>;           // v4a
window.URL_UPLOADS_REV = <?= json_encode(url('/uploads/revistas')) ?>;

var CELDAS = <?= json_encode($celdasJs, JSON_UNESCAPED_UNICODE) ?>;
var idxActual = 0;
var fotoBase64Actual = null;
var canvasLimpioActual = null;   // v4a: canvas SIN timestamp, el que ve el OCR
var vehiculoIdActual = null;
var ultimaRespuestaOcr = null;

for (var i = 0; i < CELDAS.length; i++) {
    if (!CELDAS[i].estado) { idxActual = i; break; }
}

function tipoLabel(t) {
    var m = {comun:'🅿️ Común', privada:'🔒 Privada', moto_comun:'🏍️ Moto', libre:'🅿️ Libre', movilidad_reducida:'♿ Mov.Red.'};
    return m[t] || t;
}

function selectEstado(val) {
    document.querySelectorAll('#estado-sel label').forEach(function(l){
        l.classList.remove('sel');
        if (l.getAttribute('data-val') === val) l.classList.add('sel');
        var r = l.querySelector('input[type=radio]');
        if (r) r.checked = (l.getAttribute('data-val') === val);
    });
}
function getEstadoSel() {
    var el = document.querySelector('#estado-sel label.sel');
    return el ? el.getAttribute('data-val') : 'ocupada';
}
document.querySelectorAll('#estado-sel label').forEach(function(l){
    l.addEventListener('click', function(){ selectEstado(l.getAttribute('data-val')); });
});

function renderCelda() {
    if (idxActual < 0) idxActual = 0;
    if (idxActual >= CELDAS.length) idxActual = CELDAS.length - 1;

    var c = CELDAS[idxActual];
    document.getElementById('celda-nombre').textContent = c.nombre + ' (' + (idxActual+1) + ' de ' + CELDAS.length + ')';

    var html = '<div class="row"><span>Tipo:</span><span><span class="badge-tipo">' + tipoLabel(c.tipo) + '</span></span></div>';
    if (c.apto_dueno) html += '<div class="row"><span>Apto dueño:</span><span><strong>' + c.apto_dueno + '</strong></span></div>';
    if (c.apto_uso && c.apto_uso !== c.apto_dueno) html += '<div class="row"><span>Usado por:</span><span>Apto ' + c.apto_uso + '</span></div>';
    if (c.vehiculos && c.vehiculos.length > 0) {
        html += '<div class="row"><span>Vehículos asignados:</span><span class="vhs-list">';
        c.vehiculos.forEach(function(v){
            html += '<span class="vh">' + (v.tipo === 'moto' ? '🏍️' : '🚗') + ' ' + v.placa + '</span>';
        });
        html += '</span></div>';
    }
    document.getElementById('celda-info').innerHTML = html;

    document.getElementById('camara-box').classList.remove('hidden');
    document.getElementById('resultado-box').classList.remove('mostrar','no-encontrada');
    document.getElementById('placa-editor').style.display = 'none';
    document.getElementById('hint-loading').style.display = 'none';
    document.getElementById('hint-lookup').innerHTML = '';
    document.getElementById('acciones-extra').style.display = 'none';
    document.getElementById('apto-editor').style.display = 'none';
    document.getElementById('debug-panel').classList.remove('mostrar');
    document.getElementById('btn-reintentar').style.display = 'none';
    document.getElementById('no-foto-placeholder').style.display = 'none';
    // Reset del contenido del placeholder por si onImgError lo cambió
    document.getElementById('no-foto-placeholder').innerHTML =
        '<div style="font-size:48px;line-height:1;">📷</div>' +
        '<div style="margin-top:8px;font-weight:600;font-size:14px;">Esta celda no tiene foto guardada</div>' +
        '<div style="font-size:12px;margin-top:4px;color:#78350f;">Usa "📸 Cambiar foto" abajo para agregar una y comparar con el dato registrado.</div>';
    fotoBase64Actual = null;
    vehiculoIdActual = null;
    ultimaRespuestaOcr = null;
    document.getElementById('panel-celda').classList.remove('revisada');

    if (c.estado) {
        // Ya revisada: modo EDICIÓN INLINE
        document.getElementById('camara-box').classList.add('hidden');
        document.getElementById('panel-celda').classList.add('revisada');
        document.getElementById('resultado-box').classList.add('mostrar');
        document.getElementById('placa-editor').style.display = 'block';

        if (c.foto) {
            document.getElementById('preview-img').src = window.URL_UPLOADS_REV + '/' + c.foto;
            document.getElementById('preview-img').style.display = 'block';
            document.getElementById('no-foto-placeholder').style.display = 'none';
        } else {
            document.getElementById('preview-img').style.display = 'none';
            // Mostrar placeholder si la celda está marcada como OCUPADA o PENDIENTE
            // (en VACÍA no tiene sentido pedir foto)
            document.getElementById('no-foto-placeholder').style.display =
                (c.estado === 'vacia') ? 'none' : 'block';
        }
        document.getElementById('placa-input').value = c.placa || '';
        selectEstado(c.estado);
        vehiculoIdActual = c.vehiculo_id;
        if (c.placa) verificarPlaca();
    } else {
        selectEstado('ocupada');
        document.getElementById('preview-img').style.display = 'none';
        document.getElementById('no-foto-placeholder').style.display = 'none';
    }

    document.getElementById('btn-anterior').disabled = (idxActual === 0);
    document.getElementById('btn-siguiente').disabled = (idxActual === CELDAS.length - 1);

    renderMini();
    renderContadores();
}

function renderContadores() {
    var ok=0, vc=0, pd=0;
    CELDAS.forEach(function(c){
        if (c.estado === 'ocupada')   ok++;
        else if (c.estado === 'vacia')     vc++;
        else if (c.estado === 'pendiente') pd++;
    });
    var rev = ok + vc + pd;
    var pct = CELDAS.length ? Math.round(rev * 100 / CELDAS.length) : 0;
    document.getElementById('cnt-ok').textContent = ok;
    document.getElementById('cnt-vc').textContent = vc;
    document.getElementById('cnt-pd').textContent = pd;
    document.getElementById('lbl-progreso').textContent = rev + ' / ' + CELDAS.length;
    document.getElementById('lbl-pct').textContent = pct + '%';
    document.getElementById('bar-progreso').style.width = pct + '%';
}

function renderMini() {
    var g = document.getElementById('mini-grid');
    g.innerHTML = '';
    CELDAS.forEach(function(c, i){
        var d = document.createElement('div');
        d.className = 'list-mini-cell';
        if (c.estado) d.classList.add('st-' + c.estado);
        if (i === idxActual) d.classList.add('actual');
        d.textContent = c.nombre;
        d.onclick = function(){ irCelda(i); };
        g.appendChild(d);
    });
}

function irCelda(i) {
    if (i < 0 || i >= CELDAS.length) return;
    idxActual = i;
    renderCelda();
    window.scrollTo({top:0, behavior:'smooth'});
}

function cambiarFoto() { document.getElementById('cam-input').click(); }

document.getElementById('cam-input').addEventListener('change', function(ev){
    var file = ev.target.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var img = new Image();
        img.onload = function() {
            var canvas = document.createElement('canvas');
            var maxW = 1600; // ↑ resolución para OCR
            var scale = Math.min(1, maxW / img.width);
            canvas.width = img.width * scale;
            canvas.height = img.height * scale;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            // ── v4a: el OCR corre sobre la imagen LIMPIA ──
            // ANTES se estampaba la fecha y RECIEN despues se hacia el OCR; el
            // recuadro negro del timestamp confundia al detector (leia "2026").
            // AHORA: 1) OCR limpio  2) estampar fecha  3) guardar. Igual visualmente.
            canvasLimpioActual = canvas;
            hacerOcr(canvas);

            estamparFecha(ctx, canvas.width, canvas.height);
            fotoBase64Actual = canvas.toDataURL('image/jpeg', 0.92); // ↑ calidad
            mostrarFotoTomada();
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
    ev.target.value = '';
});

// Estampa la fecha/hora actual sobre el canvas, abajo a la derecha.
function estamparFecha(ctx, W, H) {
    var d = new Date();
    var pad = function(n){ return n < 10 ? '0' + n : '' + n; };
    var texto = pad(d.getDate()) + '/' + pad(d.getMonth()+1) + '/' + d.getFullYear() + '  ' +
                pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());

    // Tamaño relativo al ancho de la foto (para que se vea igual en móvil y desktop)
    var fontSize = Math.max(22, Math.floor(W * 0.032));
    var pad2 = Math.floor(fontSize * 0.4);
    var margen = Math.floor(fontSize * 0.5);

    ctx.font = 'bold ' + fontSize + 'px monospace';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'bottom';

    var textW = ctx.measureText(texto).width;
    // Caja negra semitransparente
    ctx.fillStyle = 'rgba(0,0,0,0.65)';
    ctx.fillRect(
        W - textW - pad2*2 - margen,
        H - fontSize - pad2*2 - margen,
        textW + pad2*2,
        fontSize + pad2*2
    );
    // Texto en amarillo (típico de cámaras de seguridad)
    ctx.fillStyle = '#fef08a';
    // Sombra para más legibilidad
    ctx.shadowColor = 'rgba(0,0,0,0.9)';
    ctx.shadowBlur = 3;
    ctx.fillText(texto, W - margen - pad2, H - margen - pad2);
    ctx.shadowBlur = 0;
}

function mostrarFotoTomada() {
    document.getElementById('camara-box').classList.add('hidden');
    document.getElementById('preview-img').src = fotoBase64Actual;
    document.getElementById('preview-img').style.display = 'block';
    document.getElementById('no-foto-placeholder').style.display = 'none';
    document.getElementById('resultado-box').classList.add('mostrar');
    document.getElementById('placa-editor').style.display = 'block';
    document.getElementById('btn-reintentar').style.display = 'inline-block';
    selectEstado('ocupada');
}

// Se dispara si la imagen no cargó (path malo, archivo borrado, permisos)
function onImgError() {
    var img = document.getElementById('preview-img');
    // Solo mostrar placeholder si estamos en modo edición (celda ya revisada)
    if (CELDAS[idxActual] && CELDAS[idxActual].estado && CELDAS[idxActual].estado !== 'vacia') {
        img.style.display = 'none';
        var ph = document.getElementById('no-foto-placeholder');
        ph.innerHTML = '<div style="font-size:48px;line-height:1;">⚠️</div>' +
            '<div style="margin-top:8px;font-weight:600;font-size:14px;">La foto guardada no se pudo cargar</div>' +
            '<div style="font-size:12px;margin-top:4px;color:#78350f;">Puede que el archivo haya sido borrado. Usa "📸 Cambiar foto" para agregar una nueva.</div>';
        ph.style.display = 'block';
    }
}

// v4a (OFFLINE): OCR LOCAL primero; el servidor queda como respaldo.
function hacerOcr(canvasLimpio) {
    var canvas = canvasLimpio || canvasLimpioActual;
    document.getElementById('hint-loading').style.display = 'block';
    document.getElementById('hint-lookup').innerHTML = '';
    document.getElementById('placa-input').value = '';

    if (!canvas || typeof SPOCR === 'undefined' || !SPOCR.listo()) {
        return hacerOcrServidor();   // sin OCR local -> comportamiento de siempre
    }

    var pPadron = (typeof SPPadron !== 'undefined')
        ? SPPadron.placas().catch(function(){ return []; })
        : Promise.resolve([]);

    pPadron.then(function (padron) {
        return SPOCR.leer(canvas, { padron: padron, moto: false });
    })
    .then(function (r) {
        document.getElementById('hint-loading').style.display = 'none';
        ultimaRespuestaOcr = { local: true, data: r };
        console.log('OCR local:', r);

        if (r.zona === 'VERDE' && r.placa) {          // lectura exacta y confirmada
            document.getElementById('placa-input').value = r.placa;
            verificarPlaca();
            return;
        }
        if (r.zona === 'AMARILLA' && r.sugerencias.length) {   // hay duda -> se PREGUNTA
            mostrarOpcionesOcr(r.sugerencias);
            return;
        }
        if (navigator.onLine) return hacerOcrServidor();        // ultimo recurso
        document.getElementById('hint-lookup').innerHTML =
            '<div class="hint-no-encontrada">⚠️ No se pudo leer. Escribe la placa manualmente.</div>';
        document.getElementById('placa-input').focus();
    })
    .catch(function (e) {
        console.warn('OCR local fallo:', e);
        document.getElementById('hint-loading').style.display = 'none';
        if (navigator.onLine) return hacerOcrServidor();
        document.getElementById('hint-lookup').innerHTML =
            '<div class="hint-no-encontrada">⚠️ No se pudo leer. Escribe la placa manualmente.</div>';
    });
}

// Botones de eleccion cuando el OCR no esta seguro. El sistema NUNCA elige solo.
function mostrarOpcionesOcr(sugerencias) {
    var h = '<div class="hint-no-encontrada" style="margin-bottom:8px">⚠️ Confirma la placa:</div>'
          + '<div style="display:flex;flex-wrap:wrap;gap:6px">';
    sugerencias.forEach(function (s) {
        var pl = (typeof s === 'string') ? s : s.placa;
        var nv = (typeof s === 'object') && s.nuevo;
        h += '<button type="button" class="btn-ocr-opt" data-placa="' + pl + '"'
           + ' style="flex:1;min-width:105px;padding:10px;border-radius:8px;cursor:pointer;background:#fff;'
           + 'border:2px solid ' + (nv ? '#1e6cff' : '#d1d5db') + '">'
           + '<b style="font-size:16px;letter-spacing:1px">' + pl + '</b>'
           + '<br><small style="color:#6b7280">' + (nv ? 'vehículo nuevo' : 'en la BD') + '</small></button>';
    });
    h += '<button type="button" class="btn-ocr-opt" data-placa=""'
       + ' style="flex:1;min-width:105px;padding:10px;border-radius:8px;cursor:pointer;background:#fff;'
       + 'border:2px dashed #9ca3af"><b>Ninguna</b><br><small style="color:#6b7280">escribir a mano</small></button></div>';
    document.getElementById('hint-lookup').innerHTML = h;

    Array.prototype.forEach.call(document.querySelectorAll('.btn-ocr-opt'), function (b) {
        b.onclick = function () {
            var pl = this.getAttribute('data-placa');
            if (pl) {
                document.getElementById('placa-input').value = pl;
                verificarPlaca();
            } else {
                document.getElementById('hint-lookup').innerHTML = '';
                document.getElementById('placa-input').focus();
            }
        };
    });
}

// OCR del servidor — el comportamiento original, ahora como respaldo.
function hacerOcrServidor() {
    if (!fotoBase64Actual) { document.getElementById('hint-loading').style.display = 'none'; return; }
    if (!navigator.onLine) {
        document.getElementById('hint-loading').style.display = 'none';
        document.getElementById('hint-lookup').innerHTML =
            '<div class="hint-no-encontrada">📶 Sin conexión. Escribe la placa manualmente.</div>';
        return;
    }
    document.getElementById('hint-loading').style.display = 'block';

    var fd = new FormData();
    var blob = dataURLtoBlob(fotoBase64Actual);
    // Enviar con VARIOS nombres por si el endpoint espera uno específico
    fd.append('foto',   blob, 'placa.jpg');
    fd.append('imagen', blob, 'placa.jpg');
    fd.append('image',  blob, 'placa.jpg');
    fd.append('file',   blob, 'placa.jpg');
    fd.append('_csrf', window.REV_CSRF);
    fd.append('csrf_token', window.REV_CSRF);

    fetch(window.API_OCR, {method:'POST', body: fd, credentials:'same-origin'})
        .then(function(r){
            return r.text().then(function(txt){
                var data = null;
                try { data = JSON.parse(txt); } catch(e) { data = { _raw: txt }; }
                return { status: r.status, data: data, raw: txt };
            });
        })
        .then(function(resp){
            document.getElementById('hint-loading').style.display = 'none';
            ultimaRespuestaOcr = resp;
            console.log('OCR response:', resp);

            var d = resp.data || {};
            // Aceptar MUCHOS formatos comunes
            var placa = d.placa || d.plate || d.text || d.result || d.detected
                     || d.license_plate || d.licensePlate || d.value || d.output
                     || d.reading || d.reconocida || d.readed
                     || (d.data && (d.data.placa || d.data.plate || d.data.text))
                     || (d.results && d.results[0] && (d.results[0].placa || d.results[0].plate || d.results[0].text))
                     || '';
            if (typeof placa === 'object' && placa !== null) {
                placa = placa.placa || placa.plate || placa.value || placa.text || '';
            }
            placa = (placa || '').toString().toUpperCase().replace(/[^A-Z0-9]/g,'');

            if (placa) {
                document.getElementById('placa-input').value = placa;
                verificarPlaca();
            } else {
                document.getElementById('hint-lookup').innerHTML =
                    '<div class="hint-no-encontrada">⚠️ El OCR no devolvió una placa. Escríbela manualmente, reintenta o revisa el debug ⚙️.</div>';
                mostrarDebug();
                document.getElementById('debug-panel').classList.add('mostrar');
            }
        })
        .catch(function(err){
            document.getElementById('hint-loading').style.display = 'none';
            console.error('OCR error:', err);
            document.getElementById('hint-lookup').innerHTML =
                '<div class="hint-error">❌ Error al llamar OCR: ' + err.message + '. Escribe la placa a mano.</div>';
        });
}

function reintentarOcr() {
    if (!fotoBase64Actual) { alert('No hay foto para reintentar. Toma una primero.'); return; }
    hacerOcr();
}

function toggleDebug() {
    document.getElementById('debug-panel').classList.toggle('mostrar');
    mostrarDebug();
}
function mostrarDebug() {
    var el = document.getElementById('debug-panel');
    el.textContent = ultimaRespuestaOcr
        ? 'HTTP ' + ultimaRespuestaOcr.status + '\n\nRAW:\n' + ultimaRespuestaOcr.raw + '\n\nPARSED:\n' + JSON.stringify(ultimaRespuestaOcr.data, null, 2)
        : '(sin respuesta OCR aún)';
}

function verificarPlaca() {
    var placa = document.getElementById('placa-input').value.trim().toUpperCase();
    document.getElementById('apto-editor').style.display = 'none';
    if (!placa) { document.getElementById('hint-lookup').innerHTML = '<div class="hint-no-encontrada">⚠️ Escribe una placa</div>'; return; }

    document.getElementById('hint-lookup').innerHTML = '<div style="text-align:center;color:#6b7280">⏳ Buscando...</div>';

    // v4a: primero la copia LOCAL (funciona sin señal); el servidor solo refuerza.
    var pLocal = (typeof SPPadron !== 'undefined')
        ? SPPadron.buscar(placa).catch(function(){ return { encontrada:false }; })
        : Promise.resolve({ encontrada:false });

    pLocal.then(function (d) {
        if (d.encontrada) return d;
        if (!navigator.onLine) return d;
        return fetch(window.API_LOOKUP + '?placa=' + encodeURIComponent(placa), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .catch(function(){ return d; });
    })
        .then(function(d){
            if (d.encontrada) {
                // ── Badge estado de pago (online y offline) ──
                var ep = d.estado_pago || 'al_dia';
                var mm = d.meses_mora  || 0;
                var moraBadge;
                if (ep && ep !== 'al_dia') {
                    var mesesTxt = mm > 0 ? ' · ' + mm + ' mes' + (mm > 1 ? 'es' : '') : '';
                    moraBadge = '<div style="margin:6px 0 2px;display:inline-flex;align-items:center;'
                              + 'gap:6px;background:#fef2f2;border:2px solid #f87171;border-radius:8px;'
                              + 'padding:7px 16px;font-weight:700;font-size:14px;color:#991b1b">'
                              + '🔴 MOROSO' + mesesTxt + '</div>';
                } else {
                    moraBadge = '<div style="margin:6px 0 2px;display:inline-flex;align-items:center;'
                              + 'gap:6px;background:#f0fdf4;border:2px solid #86efac;border-radius:8px;'
                              + 'padding:7px 16px;font-weight:700;font-size:14px;color:#166534">'
                              + '🟢 AL DÍA</div>';
                }

                var msg = '<div class="hint-encontrada">✅ <strong>' + placa + '</strong> registrada';
                if (d.apto) msg += ' — Apto ' + d.apto;
                if (d.tipo) msg += ' (' + (d.tipo === 'moto' ? '🏍️ Moto' : '🚗 Carro') + ')';
                msg += '</div>';
                msg += '<div style="text-align:center">' + moraBadge + '</div>';

                document.getElementById('hint-lookup').innerHTML = msg;
                document.getElementById('acciones-extra').style.display = 'none';
                document.getElementById('resultado-box').classList.remove('no-encontrada');
                vehiculoIdActual = d.vehiculo_id;
                // v7.70: con vehículo identificado se puede registrar novedad
                var _bn = document.getElementById('acciones-novedad');
                if (_bn) _bn.style.display = vehiculoIdActual ? 'flex' : 'none';
                document.getElementById('apto-editor').style.display = 'flex';
                document.getElementById('apto-input').value = d.apto || '';
            } else {
                document.getElementById('hint-lookup').innerHTML =
                    '<div class="hint-no-encontrada">⚠️ Placa <strong>' + placa + '</strong> NO registrada</div>';
                document.getElementById('acciones-extra').style.display = 'flex';
                document.getElementById('resultado-box').classList.add('no-encontrada');
                vehiculoIdActual = null;
                var _bn2 = document.getElementById('acciones-novedad');
                if (_bn2) _bn2.style.display = 'none';
            }
        })
        .catch(function(){
            document.getElementById('hint-lookup').innerHTML = '<div class="hint-error">Error al verificar</div>';
        });
}

function actualizarAptoVehiculo() {
    if (!vehiculoIdActual) { alert('No hay vehículo asociado.'); return; }
    var apto = document.getElementById('apto-input').value.trim();

    var fd = new FormData();
    fd.append('_csrf', window.REV_CSRF);
    fd.append('vehiculo_id', vehiculoIdActual);
    fd.append('apto', apto);

    fetch(window.API_APTO, {method:'POST', body: fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                alert('✅ Apto actualizado: ' + (apto || '(sin apto)'));
                verificarPlaca();
            } else {
                alert('Error: ' + (d.error || 'no se pudo actualizar'));
            }
        });
}

// v4b (OFFLINE): el guardado pasa por SPCola.
// CON red  -> va directo al servidor (igual que siempre).
// SIN red  -> se encola en IndexedDB y se envia solo al reconectar.
// api_guardar_paso es IDEMPOTENTE (UPDATE si existe / INSERT si no), asi que
// reenviar el mismo item nunca duplica nada.
function guardarPaso() {
    var estado = getEstadoSel();
    var placa = document.getElementById('placa-input').value.trim().toUpperCase();

    if (estado === 'ocupada' && !placa) {
        if (!confirm('No hay placa. ¿Guardar igualmente como OCUPADA sin placa?')) return;
    }

    var c = CELDAS[idxActual];

    var campos = {
        _csrf: window.REV_CSRF,
        revista_id: window.REV_ID,
        celda_id: c.id,
        estado: estado
    };
    if (placa) campos.placa = placa;
    if (fotoBase64Actual) campos.foto = fotoBase64Actual;

    // Sin SPCola (script no cargo) -> comportamiento original, cero regresion.
    if (typeof SPCola === 'undefined') {
        var fd = new FormData();
        Object.keys(campos).forEach(function(k){ fd.append(k, campos[k]); });
        return fetch(window.API_GUARDAR, {method:'POST', body: fd, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(d){ aplicarGuardado(d, c, estado, placa); });
    }

    // clave: una sola entrada en cola por celda. Si la ronda corrige la misma
    // celda 3 veces sin señal, se guarda la ULTIMA version, no las 3.
    var clave = 'rev' + window.REV_ID + '-celda' + c.id;

    SPCola.enviar(window.API_GUARDAR, campos, { clave: clave })
        .then(function (d) { aplicarGuardado(d, c, estado, placa); })
        .catch(function (e) { alert('Error al guardar: ' + (e.message || e)); });
}

// Aplica el resultado del guardado (directo o encolado) y avanza de celda.
function aplicarGuardado(d, c, estado, placa) {
    // Error de negocio real (placa duplicada, CSRF, validacion): NO avanzar.
    if (d && d.ok === false) {
        alert('Error al guardar: ' + (d.error || 'desconocido'));
        return;
    }

    c.estado = estado;
    c.placa = placa || null;
    c.foto = (d && d.foto_path) || c.foto || null;
    c.vehiculo_id = vehiculoIdActual;

    // Se guardo offline: avisar sin frenar el trabajo.
    if (d && d.encolado) {
        c.pendiente_sync = true;
        mostrarAvisoCola();
    }

    var sig = -1;
    for (var i = idxActual + 1; i < CELDAS.length; i++) {
        if (!CELDAS[i].estado) { sig = i; break; }
    }
    if (sig >= 0) irCelda(sig);
    else { renderCelda(); alert('✅ Todas las celdas revisadas. Puedes terminar la revista.'); }
}

// Aviso flotante: cuantas celdas esperan subir.
function mostrarAvisoCola() {
    if (typeof SPCola === 'undefined') return;
    SPCola.pendientes().then(function (n) {
        var el = document.getElementById('sp-cola-badge');
        if (!n) { if (el) el.remove(); return; }
        if (!el) {
            el = document.createElement('div');
            el.id = 'sp-cola-badge';
            el.style.cssText = 'position:fixed;bottom:8px;right:8px;z-index:999;font-size:12px;'
                             + 'padding:6px 12px;border-radius:12px;font-family:system-ui;'
                             + 'background:#fef3c7;color:#92400e;cursor:pointer;font-weight:600;'
                             + 'box-shadow:0 2px 8px rgba(0,0,0,.15)';
            el.onclick = function () {
                if (!navigator.onLine) { alert('Sin señal. Se enviará automáticamente al reconectar.'); return; }
                el.textContent = '⏳ Enviando...';
                SPCola.sincronizar(true).then(function (r) {
                    alert('Enviadas: ' + (r.enviadas || 0) + ' · Pendientes: ' + (r.pendientes || 0));
                    mostrarAvisoCola();
                });
            };
            document.body.appendChild(el);
        }
        el.textContent = '⏳ ' + n + ' sin subir · toca para enviar';
    });
}

function marcarVacia() {
    fotoBase64Actual = null;
    var c = CELDAS[idxActual];
    var fd = new FormData();
    fd.append('_csrf', window.REV_CSRF);
    fd.append('revista_id', window.REV_ID);
    fd.append('celda_id', c.id);
    fd.append('estado', 'vacia');

    fetch(window.API_GUARDAR, {method:'POST', body: fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                c.estado = 'vacia'; c.placa = null; c.foto = null;
                var sig = -1;
                for (var i = idxActual + 1; i < CELDAS.length; i++) {
                    if (!CELDAS[i].estado) { sig = i; break; }
                }
                if (sig >= 0) irCelda(sig); else renderCelda();
            } else alert('Error: ' + (d.error || 'desconocido'));
        });
}

/* ═══ v7.70: NOVEDAD durante la revista (sin cancelarla) ═══ */
function abrirNovedad() {
    if (!vehiculoIdActual) { alert('Primero identificá el vehículo (Verificar en BD).'); return; }
    var placaEl = document.getElementById('placa-input');
    document.getElementById('nov-placa-txt').textContent = placaEl ? (placaEl.value || '—') : '—';
    var c = CELDAS[idx] || {};
    document.getElementById('nov-celda-txt').textContent = c.nombre || '—';
    document.getElementById('nov-tipo').value = 'otro';
    document.getElementById('nov-gravedad').value = 'ninguna';
    document.getElementById('nov-desc').value = '';
    novMsg('', '');
    document.getElementById('modal-novedad').style.display = 'flex';
    setTimeout(function(){ document.getElementById('nov-desc').focus(); }, 80);
}

function cerrarNovedad() {
    document.getElementById('modal-novedad').style.display = 'none';
}

function novMsg(tipo, texto) {
    var el = document.getElementById('nov-msg');
    if (!el) return;
    if (!texto) { el.style.display = 'none'; return; }
    var ok = (tipo === 'ok');
    el.style.background = ok ? '#dcfce7' : '#fee2e2';
    el.style.color      = ok ? '#166534' : '#991b1b';
    el.style.border     = '1px solid ' + (ok ? '#86efac' : '#fca5a5');
    el.textContent = texto;
    el.style.display = 'block';
}

function guardarNovedad() {
    var desc = (document.getElementById('nov-desc').value || '').trim();
    if (!desc) { novMsg('error', 'La descripción es obligatoria.'); return; }
    if (!vehiculoIdActual) { novMsg('error', 'No hay vehículo asociado.'); return; }

    var btn = document.getElementById('nov-btn-guardar');
    var txt = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Guardando...';

    var fd = new FormData();
    fd.append('_csrf', window.CSRF_TOKEN || '');
    fd.append('formato', 'json');
    fd.append('vehiculo_id', vehiculoIdActual);
    fd.append('tipo', document.getElementById('nov-tipo').value);
    fd.append('gravedad', document.getElementById('nov-gravedad').value);
    fd.append('descripcion', desc);

    fetch(window.API_NOVEDAD_REV, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false; btn.textContent = txt;
            if (d && d.ok) {
                novMsg('ok', '✅ Novedad registrada. La revista sigue abierta.');
                setTimeout(cerrarNovedad, 1200);
            } else {
                novMsg('error', (d && d.error) ? d.error : 'No se pudo guardar');
            }
        })
        .catch(function(){
            btn.disabled = false; btn.textContent = txt;
            novMsg('error', 'Error de red');
        });
}

function abrirRegistrar() {
    document.getElementById('reg-placa').value = document.getElementById('placa-input').value.trim().toUpperCase();
    document.getElementById('reg-tipo').value = 'carro';
    document.getElementById('reg-apto').value = '';
    document.getElementById('reg-apto-hint').textContent = '';
    // v3AO: reset del tipo de registro (default: residente) y limpieza del selector
    var radios = document.getElementsByName('reg-tipo-registro');
    for (var i=0; i<radios.length; i++) radios[i].checked = (radios[i].value === 'propietario');
    document.getElementById('reg-residente').innerHTML = '<option value="">— Sin residente específico —</option>';
    document.getElementById('reg-res-wrap').style.display = 'none';
    regTipoRegistroCambio();
    document.getElementById('modal-reg').classList.add('mostrar');
}
function cerrarModalReg() { document.getElementById('modal-reg').classList.remove('mostrar'); }

// v3AO: cambio de tipo residente/visitante (visual + label del apto)
function regTipoRegistroCambio() {
    // v7.69: 3 opciones — propietario / inquilino / visitante
    var tipoReg = _regTipoRegistroSel();
    var lbls = {
        propietario: document.getElementById('tr-res-lbl'),
        inquilino:   document.getElementById('tr-inq-lbl'),
        visitante:   document.getElementById('tr-vis-lbl')
    };
    // resaltar el elegido
    for (var k in lbls) {
        if (!lbls[k]) continue;
        var act = (k === tipoReg) || (tipoReg === 'residente' && k === 'propietario');
        lbls[k].style.background  = act ? '#eff6ff' : '';
        lbls[k].style.borderColor = act ? '#3b82f6' : '#d1d5db';
    }
    // etiqueta del apto
    var lblApto = document.getElementById('reg-apto-lbl');
    if (tipoReg === 'visitante') {
        lblApto.textContent = 'Apto que visita (obligatorio)';
    } else {
        lblApto.textContent = 'Apto dueño (obligatorio)';
    }
    // el selector de residente NO aplica a visitante
    var wrap = document.getElementById('reg-res-wrap');
    if (tipoReg === 'visitante') {
        wrap.style.display = 'none';
    } else if (document.getElementById('reg-apto').value.trim()) {
        wrap.style.display = 'block';
    }
}

function _regTipoRegistroSel() {
    var radios = document.getElementsByName('reg-tipo-registro');
    for (var i=0; i<radios.length; i++) if (radios[i].checked) return radios[i].value;
    return 'residente';
}

// v3AO: debouncer para no llamar al backend en cada tecla
var _regAptoTimer = null;
function regAptoCambio() {
    document.getElementById('reg-residente').innerHTML = '<option value="">— Sin residente específico —</option>';
    if (_regAptoTimer) clearTimeout(_regAptoTimer);
    _regAptoTimer = setTimeout(regCargarResidentes, 500);
}

// v3AO: cargar residentes del apto elegido (opcional, solo si tipo=residente)
function regCargarResidentes() {
    var apto = document.getElementById('reg-apto').value.trim();
    var wrap = document.getElementById('reg-res-wrap');
    var sel  = document.getElementById('reg-residente');
    var hint = document.getElementById('reg-apto-hint');

    if (!apto || _regTipoRegistroSel() === 'visitante') {
        wrap.style.display = 'none';
        hint.textContent = '';
        return;
    }

    hint.textContent = '🔍 Verificando apto...';

    fetch(window.API_RES_APTO + '?apto=' + encodeURIComponent(apto), {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                hint.innerHTML = '<span style="color:#dc2626">⚠️ ' + (d.error || 'Apto no encontrado') + '</span>';
                wrap.style.display = 'none';
                return;
            }
            hint.innerHTML = '<span style="color:#166534">✓ Apto ' + d.apto_num + ' encontrado (' + d.residentes.length + ' residente(s))</span>';
            sel.innerHTML = '<option value="">— Sin residente específico —</option>';
            (d.residentes || []).forEach(function(r){
                var opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.nombre + (r.tipo ? ' (' + r.tipo + ')' : '') + (r.celular ? ' — ' + r.celular : '');
                sel.appendChild(opt);
            });
            wrap.style.display = (d.residentes.length > 0) ? 'block' : 'none';
        })
        .catch(function(){ hint.innerHTML = '<span style="color:#dc2626">⚠️ Error al verificar apto</span>'; });
}

function registrarVehiculo() {
    var placa   = document.getElementById('reg-placa').value.trim().toUpperCase();
    var tipo    = document.getElementById('reg-tipo').value;
    var apto    = document.getElementById('reg-apto').value.trim();
    var tipoReg = _regTipoRegistroSel();
    var resId   = document.getElementById('reg-residente').value;

    if (!placa) { alert('Escribe la placa.'); return; }
    if (tipoReg === 'visitante' && !apto) {
        alert('Para visitante debes indicar a qué apto va a visitar.');
        return;
    }

    var fd = new FormData();
    fd.append('_csrf', window.REV_CSRF);
    fd.append('placa', placa);
    fd.append('tipo', tipo);
    fd.append('apto', apto);
    fd.append('tipo_registro', tipoReg);
    if (resId) fd.append('residente_id', resId);

    fetch(window.API_REGISTRAR, {method:'POST', body: fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                cerrarModalReg();
                document.getElementById('placa-input').value = placa;
                verificarPlaca();
            } else {
                alert('Error: ' + (d.error || 'no se pudo registrar'));
            }
        })
        .catch(function(err){ alert('Error de red: ' + err); });
}

function ampliarFoto(src) {
    document.getElementById('foto-modal-img').src = src;
    document.getElementById('foto-modal').classList.add('mostrar');
}
function cerrarFoto() { document.getElementById('foto-modal').classList.remove('mostrar'); }

function terminarRevista() {
    var pendientes = 0;
    CELDAS.forEach(function(c){ if (!c.estado) pendientes++; });
    var msg = pendientes > 0 ? 'Quedan ' + pendientes + ' celdas sin revisar. ¿Terminar de todos modos?' : '¿Terminar la revista?';
    if (!confirm(msg)) return;

    var f = document.createElement('form');
    f.method = 'POST';
    f.action = <?= json_encode(url('/revistas/terminar')) ?>;
    f.innerHTML = '<input type="hidden" name="_csrf" value="' + window.REV_CSRF + '">' +
                  '<input type="hidden" name="id" value="' + window.REV_ID + '">';
    document.body.appendChild(f);
    f.submit();
}

function dataURLtoBlob(dataurl) {
    var arr = dataurl.split(',');
    var mime = arr[0].match(/:(.*?);/)[1];
    var bstr = atob(arr[1]);
    var n = bstr.length;
    var u8 = new Uint8Array(n);
    while(n--) u8[n] = bstr.charCodeAt(n);
    return new Blob([u8], {type: mime});
}

renderCelda();
// ── v4a (OFFLINE): arranque del OCR local y del padron. NO BLOQUEANTE. ──
(function () {
    if (typeof SPPadron !== 'undefined') {
        SPPadron.init()
            .then(function () { return SPPadron.autoSync(12); })
            .then(function (e) { pintarEstadoOffline(e && e.total ? e.total : 0); })
            .catch(function (err) { console.warn('Padron no disponible:', err); });
    }
    if (typeof SPOCR !== 'undefined' && typeof ort !== 'undefined') {
        SPOCR.init({ base: window.OCR_BASE || '/assets/ocr/' })
            .then(function () { console.log('OCR local listo (' + SPOCR.version + ')'); })
            .catch(function (err) { console.warn('OCR local NO disponible:', err.message); });
    }
    // v4b: cola de escritura
    if (typeof SPCola !== 'undefined') {
        SPCola.init().then(function () {
            SPCola.onCambio(function (st) {
                if (st.pendientes) mostrarAvisoCola();
                else { var e = document.getElementById('sp-cola-badge'); if (e) e.remove(); }
            });
            mostrarAvisoCola();          // por si quedaron pendientes de una sesion previa
        }).catch(function (e) { console.warn('Cola no disponible:', e); });
    }
})();

function pintarEstadoOffline(total) {
    var el = document.getElementById('sp-offline-badge');
    if (!el) {
        el = document.createElement('div');
        el.id = 'sp-offline-badge';
        el.style.cssText = 'position:fixed;bottom:8px;left:8px;z-index:999;font-size:11px;'
                         + 'padding:4px 9px;border-radius:12px;font-family:system-ui;opacity:.9';
        document.body.appendChild(el);
    }
    if (!total) {
        el.style.background = '#fee2e2'; el.style.color = '#991b1b';
        el.textContent = '⚠ Padrón vacío — conéctate para sincronizar';
    } else if (navigator.onLine) {
        el.style.background = '#dcfce7'; el.style.color = '#166534';
        el.textContent = '● En línea · ' + total + ' placas en caché';
    } else {
        el.style.background = '#fef3c7'; el.style.color = '#92400e';
        el.textContent = '📶 Sin señal · ' + total + ' placas en caché';
    }
}
function refrescarBadge() {
    if (typeof SPPadron === 'undefined') return;
    SPPadron.estado().then(function(e){ pintarEstadoOffline(e.total); });
}
window.addEventListener('online',  refrescarBadge);
window.addEventListener('offline', refrescarBadge);
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>