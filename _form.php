<?php
// /home/myzonaco/smartpark.myzona360.com/modules/vehiculos/_form.php
// Partial: formulario de vehículo. v3AY
//   v3AY: agrega selector "Tipo de usuario" (inquilino/propietario/visitante)
//         al bloque de "Crear nuevo residente". Si se elige visitante,
//         permite dejar el nombre vacío (se auto-genera "Visitante Apto XXXX").
// Variables: $vehiculo, $action, $submitLabel

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

$v = $vehiculo ?? [];
$placa       = e($_POST['placa']           ?? ($v['placa']       ?? ''));
$tipo        =   $_POST['tipo']            ?? ($v['tipo']        ?? 'carro');
$apto_num    = e($_POST['apto_numero']     ?? ($v['apto_numero'] ?? ''));
$marca       = e($_POST['marca']           ?? ($v['marca']       ?? ''));
$linea       = e($_POST['linea']           ?? ($v['linea']       ?? ''));
$color       = e($_POST['color']           ?? ($v['color']       ?? ''));
$anio        = e($_POST['modelo_anio']     ?? ($v['modelo_anio'] ?? ''));
$residente_id    = (int)($_POST['residente_id']      ?? ($v['residente_id']      ?? 0));
$residente_nuevo = e($_POST['residente_nuevo_nombre'] ?? '');
$residente_nuevo_cel = e($_POST['residente_nuevo_celular'] ?? '');

// v3BF: si estamos editando y el vehículo tiene residente vinculado,
// pre-seleccionar el radio con el tipo actual del residente.
$tipoResidenteActual = null;
if ($residente_id > 0) {
    try {
        $stTipo = db()->prepare("SELECT tipo FROM residentes WHERE id = :r LIMIT 1");
        $stTipo->execute([':r' => $residente_id]);
        $tipoResidenteActual = $stTipo->fetchColumn() ?: null;
    } catch (Exception $e) { /* defensivo */ }
}

// v3AY: tipo elegido para el residente nuevo (default: inquilino)
// v3BF: si hay tipo actual del residente vinculado, ese gana como default
$residente_nuevo_tipo = $_POST['residente_tipo_nuevo']
    ?? $tipoResidenteActual
    ?? 'inquilino';
if (!in_array($residente_nuevo_tipo, ['inquilino','propietario','visitante'], true)) {
    $residente_nuevo_tipo = 'inquilino';
}
$obs         = e($_POST['observaciones']   ?? ($v['observaciones'] ?? ''));
$foto        = $v['foto_principal'] ?? null;

// Si estamos editando y hay residente, cargar su info para mostrar en el select
$residentes_actuales = [];
if ($apto_num !== '') {
    $st = db()->prepare("
        SELECT r.id, r.nombre, r.tipo, r.celular
          FROM residentes r
          JOIN apartamentos a ON a.id = r.apartamento_id
         WHERE a.conjunto_id = :c AND a.numero_visible = :n AND r.archivado_en IS NULL
      ORDER BY r.tipo, r.nombre
    ");
    $st->execute([':c' => auth_user()['conjunto_id'] ?? 1, ':n' => $apto_num]);
    $residentes_actuales = $st->fetchAll();
}
?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>

    <div class="form-section">
        <h3 class="form-section__title">Datos básicos</h3>
        <div class="grid-2">
            <label class="field">
                <span>Placa *</span>
                <input type="text" name="placa" required maxlength="15"
                       value="<?= $placa ?>" style="text-transform:uppercase"
                       placeholder="Ej: ABC123 / ABC12D">
            </label>
            <label class="field">
                <span>Tipo *</span>
                <select name="tipo" required>
                    <option value="carro" <?= $tipo === 'carro' ? 'selected' : '' ?>>🚗 Carro</option>
                    <option value="moto"  <?= $tipo === 'moto'  ? 'selected' : '' ?>>🏍️ Moto</option>
                </select>
            </label>
        </div>

        <label class="field">
            <span>Apartamento *</span>
            <input type="text" name="apto_numero" required maxlength="10"
                   value="<?= $apto_num ?>"
                   id="aptoInput" autocomplete="off"
                   placeholder="Ej: 1024">
            <small class="field__hint" id="aptoHint"></small>
        </label>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Usuario del vehículo (opcional)</h3>
        <p class="t-muted" style="margin-top:0">
            Selecciona uno de los residentes registrados en este apartamento.
            Si no aparece quien usa el vehículo, puedes crear uno nuevo abajo
            (incluye la opción de <strong>visitante</strong>).
        </p>

        <label class="field">
            <span>Residente existente</span>
            <select name="residente_id" id="residenteSelect">
                <option value="">— Sin asignar / Elegir abajo —</option>
                <?php foreach ($residentes_actuales as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= $residente_id === (int)$r['id'] ? 'selected' : '' ?>>
                        <?= e($r['nombre']) ?>
                        (<?= e($r['tipo']) ?><?= $r['celular'] ? ' · ' . e($r['celular']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="field__hint" id="residenteHint">
                <?= $apto_num === '' ? 'Primero escribe el apartamento.' : 'Cambiar de apto recarga la lista.' ?>
            </small>
        </label>

        <details style="margin-top:10px" <?= $residente_nuevo !== '' || $residente_nuevo_tipo === 'visitante' ? 'open' : '' ?>>
            <summary style="cursor:pointer; color: var(--color-primary); font-size:13px">
                ➕ Crear un nuevo usuario para vincularlo <?= $residente_id > 0 ? '/ Cambiar tipo del actual' : '(residente o visitante)' ?>
            </summary>

            <!-- v3AY/BF: Selector de tipo de usuario (inquilino/propietario/visitante).
                 Si el vehículo YA tiene residente vinculado y solo cambias el radio,
                 se ACTUALIZA el tipo del residente actual (queda como el importador). -->
            <div class="field" style="margin-top:12px;background:#f0fdf4;border:1px solid #d1fae5;border-radius:6px;padding:12px">
                <span style="display:block;font-weight:600;color:#065f46;margin-bottom:8px">
                    Tipo de usuario *
                    <?php if ($residente_id > 0 && $tipoResidenteActual): ?>
                        <small style="color:#6b7280;font-weight:400;font-size:11px">
                            (actual: <strong style="color:#065f46"><?= e(strtoupper($tipoResidenteActual)) ?></strong>)
                        </small>
                    <?php endif; ?>
                </span>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <label style="flex:1;min-width:140px;cursor:pointer;background:#fff;border:2px solid <?= $residente_nuevo_tipo === 'inquilino' ? '#059669' : '#d1d5db' ?>;border-radius:6px;padding:10px 12px;display:flex;align-items:center;gap:8px;font-size:13px">
                        <input type="radio" name="residente_tipo_nuevo" value="inquilino"
                               <?= $residente_nuevo_tipo === 'inquilino' ? 'checked' : '' ?>
                               style="margin:0" onchange="_marcarTipoUsuario(this)">
                        <span>🏠 Inquilino</span>
                    </label>
                    <label style="flex:1;min-width:140px;cursor:pointer;background:#fff;border:2px solid <?= $residente_nuevo_tipo === 'propietario' ? '#059669' : '#d1d5db' ?>;border-radius:6px;padding:10px 12px;display:flex;align-items:center;gap:8px;font-size:13px">
                        <input type="radio" name="residente_tipo_nuevo" value="propietario"
                               <?= $residente_nuevo_tipo === 'propietario' ? 'checked' : '' ?>
                               style="margin:0" onchange="_marcarTipoUsuario(this)">
                        <span>🏘️ Propietario</span>
                    </label>
                    <label style="flex:1;min-width:140px;cursor:pointer;background:#fff;border:2px solid <?= $residente_nuevo_tipo === 'visitante' ? '#059669' : '#d1d5db' ?>;border-radius:6px;padding:10px 12px;display:flex;align-items:center;gap:8px;font-size:13px">
                        <input type="radio" name="residente_tipo_nuevo" value="visitante"
                               <?= $residente_nuevo_tipo === 'visitante' ? 'checked' : '' ?>
                               style="margin:0" onchange="_marcarTipoUsuario(this)">
                        <span>👥 Visitante</span>
                    </label>
                </div>
                <small class="field__hint" id="tipoUsuarioHint" style="color:#065f46;margin-top:8px;display:block">
                    <?php if ($residente_nuevo_tipo === 'visitante'): ?>
                        💡 Si no tienes el nombre, puedes dejarlo vacío. Se guardará automáticamente como <strong>"Visitante Apto <?= $apto_num ?: 'XXXX' ?>"</strong>.
                    <?php else: ?>
                        Escribe el nombre y celular del usuario abajo.
                    <?php endif; ?>
                </small>
            </div>

            <div class="grid-2" style="margin-top:10px">
                <label class="field">
                    <span>Nombre del nuevo usuario <span id="nombreOpc" style="color:#6b7280;font-weight:400">(opcional si es visitante)</span></span>
                    <input type="text" name="residente_nuevo_nombre" id="nombreNuevoInput" maxlength="150"
                           value="<?= $residente_nuevo ?>"
                           placeholder="Nombre completo">
                </label>
                <label class="field">
                    <span>Celular</span>
                    <input type="tel" name="residente_nuevo_celular" maxlength="30"
                           value="<?= $residente_nuevo_cel ?>"
                           inputmode="numeric"
                           placeholder="3001234567">
                </label>
            </div>
            <small class="field__hint">
                Se creará con el tipo que elijas arriba. Si llenas estos campos, ignora el select de residente existente.
            </small>
        </details>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Detalles del vehículo (opcional)</h3>
        <div class="grid-2">
            <label class="field"><span>Marca</span>
                <input type="text" name="marca" maxlength="60" value="<?= $marca ?>"></label>
            <label class="field"><span>Línea / Modelo</span>
                <input type="text" name="linea" maxlength="60" value="<?= $linea ?>"></label>
            <label class="field"><span>Color</span>
                <input type="text" name="color" maxlength="40" value="<?= $color ?>"></label>
            <label class="field"><span>Año</span>
                <input type="number" name="modelo_anio" min="1950" max="2099" value="<?= $anio ?>"></label>
        </div>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Foto del vehículo (opcional)</h3>
        <?php if ($foto): ?>
            <div class="current-photo">
                <img src="<?= e(url_foto($foto)) ?>" alt="Foto actual">
                <p class="t-muted">Foto actual. Subir una nueva la reemplaza.</p>
            </div>
        <?php endif; ?>
        <label class="field">
            <span>Tomar o subir foto</span>
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" capture="environment">
            <small class="field__hint">
                Se comprime a máx 1024×1024 y se estampa fecha/hora abajo-derecha.
            </small>
        </label>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Observaciones del vehículo</h3>
        <label class="field">
            <span>Notas sobre el vehículo</span>
            <textarea name="observaciones" maxlength="500" rows="3"
                      placeholder="Ej: vidrios polarizados, calcomanía azul, etc."><?= $obs ?></textarea>
            <small class="field__hint">
                Texto libre. Esto NO es el usuario del vehículo (eso se elige arriba).
            </small>
        </label>
    </div>

    <div class="form-actions">
        <a class="btn" href="<?= url('/vehiculos') ?>">Cancelar</a>
        <button type="submit" class="btn btn--primary"><?= e($submitLabel ?? 'Guardar') ?></button>
    </div>
</form>

<script>
(function () {
    var aptoInput = document.getElementById('aptoInput');
    var aptoHint  = document.getElementById('aptoHint');
    var residenteSelect = document.getElementById('residenteSelect');
    var residenteHint   = document.getElementById('residenteHint');
    if (!aptoInput) return;

    var timer = null;
    function actualizarApto() {
        var v = aptoInput.value.trim();
        aptoHint.textContent = '';
        aptoHint.className = 'field__hint';
        if (v.length < 2) return;

        fetch('<?= url('/api/search_apto') ?>?q=' + encodeURIComponent(v))
          .then(r => r.json())
          .then(function (data) {
              if (data.found) {
                  aptoHint.textContent = '✓ Apto ' + data.numero_visible
                      + ' (Torre ' + data.torre + ', Piso ' + data.piso + ')';
                  aptoHint.className = 'field__hint field__hint--ok';
                  cargarResidentes(v);
                  // v3AY: refrescar el hint del tipo si es visitante
                  _actualizarHintTipoVisitante(v);
              } else {
                  aptoHint.textContent = '✗ Ese apartamento no existe.';
                  aptoHint.className = 'field__hint field__hint--err';
                  residenteSelect.innerHTML = '<option value="">— Sin asignar —</option>';
                  residenteHint.textContent = 'Apto inválido.';
              }
          });
    }

    function cargarResidentes(apto) {
        residenteHint.textContent = 'Cargando residentes...';
        fetch('<?= url('/api/residentes_apto') ?>?apto=' + encodeURIComponent(apto))
          .then(r => r.json())
          .then(function (data) {
              var lista = data.residentes || [];
              var selectedId = '<?= (int)$residente_id ?>';
              residenteSelect.innerHTML = '';
              var opt = document.createElement('option');
              opt.value = ''; opt.textContent = '— Sin asignar / Elegir abajo —';
              residenteSelect.appendChild(opt);
              lista.forEach(function (r) {
                  var o = document.createElement('option');
                  o.value = r.id;
                  var extra = r.celular ? ' · ' + r.celular : '';
                  o.textContent = r.nombre + ' (' + r.tipo + extra + ')';
                  if (String(r.id) === selectedId) o.selected = true;
                  residenteSelect.appendChild(o);
              });
              residenteHint.textContent = lista.length === 0
                  ? 'Este apto no tiene residentes registrados. Crea uno abajo.'
                  : lista.length + ' residente' + (lista.length === 1 ? '' : 's') + ' disponible' + (lista.length === 1 ? '' : 's') + '.';
          });
    }

    aptoInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(actualizarApto, 300);
    });

    // v3AY: refrescar el hint del apto en el mensaje del tipo visitante
    function _actualizarHintTipoVisitante(apto) {
        var hint = document.getElementById('tipoUsuarioHint');
        var checked = document.querySelector('input[name="residente_tipo_nuevo"]:checked');
        if (!hint || !checked || checked.value !== 'visitante') return;
        hint.innerHTML = '💡 Si no tienes el nombre, puedes dejarlo vacío. Se guardará automáticamente como <strong>"Visitante Apto ' + (apto || 'XXXX') + '"</strong>.';
    }
})();

// v3AY: cambiar visual del radio seleccionado + actualizar hint
function _marcarTipoUsuario(input) {
    var todos = document.querySelectorAll('input[name="residente_tipo_nuevo"]');
    todos.forEach(function(r){
        var lbl = r.closest('label');
        if (lbl) lbl.style.borderColor = r.checked ? '#059669' : '#d1d5db';
    });
    var hint = document.getElementById('tipoUsuarioHint');
    var nombreOpc = document.getElementById('nombreOpc');
    var apto = (document.getElementById('aptoInput')||{}).value || 'XXXX';
    if (input.value === 'visitante') {
        hint.innerHTML = '💡 Si no tienes el nombre, puedes dejarlo vacío. Se guardará automáticamente como <strong>"Visitante Apto ' + apto + '"</strong>.';
        if (nombreOpc) nombreOpc.textContent = '(opcional si es visitante)';
    } else if (input.value === 'propietario') {
        hint.textContent = '🏘️ Escribe el nombre completo y celular del propietario.';
        if (nombreOpc) nombreOpc.textContent = '(obligatorio)';
    } else {
        hint.textContent = '🏠 Escribe el nombre completo y celular del inquilino.';
        if (nombreOpc) nombreOpc.textContent = '(obligatorio)';
    }
}
</script>
