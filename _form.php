<?php
// /home/myzonaco/smartpark.myzona360.com/modules/visitantes/_form.php
if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }

$v = $visitante ?? [];
$placa     = e($_POST['placa']     ?? ($v['placa']     ?? ''));
$tipo      = $_POST['tipo']        ?? ($v['tipo']      ?? 'carro');
$apto_num  = e($_POST['apto_numero'] ?? ($v['apto_numero'] ?? ''));
$nombre    = e($_POST['nombre_visitante'] ?? ($v['nombre_visitante'] ?? ''));
$parent    = e($_POST['parentesco'] ?? ($v['parentesco'] ?? ''));
$cel       = e($_POST['celular']    ?? ($v['celular']    ?? ''));
$marca     = e($_POST['marca']      ?? ($v['marca']      ?? ''));
$color     = e($_POST['color']      ?? ($v['color']      ?? ''));
$recurr    = (int)($_POST['recurrente'] ?? ($v['recurrente'] ?? 0));
$obs       = e($_POST['observaciones'] ?? ($v['observaciones'] ?? ''));
$foto      = $v['foto_principal'] ?? null;
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <div class="form-section">
        <h3 class="form-section__title">Datos del visitante</h3>
        <div class="grid-2">
            <label class="field">
                <span>Placa *</span>
                <input type="text" name="placa" required maxlength="15"
                       value="<?= $placa ?>" style="text-transform:uppercase"
                       placeholder="Ej: XXX000">
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
            <span>Apartamento que visita *</span>
            <input type="text" name="apto_numero" required maxlength="10" value="<?= $apto_num ?>"
                   id="aptoInput" autocomplete="off" placeholder="Ej: 1016">
            <small class="field__hint" id="aptoHint"></small>
        </label>
        <div class="grid-2">
            <label class="field">
                <span>Nombre del visitante</span>
                <input type="text" name="nombre_visitante" maxlength="150" value="<?= $nombre ?>"
                       placeholder="Ej: Juan Pérez">
            </label>
            <label class="field">
                <span>Parentesco / relación</span>
                <input type="text" name="parentesco" maxlength="80" value="<?= $parent ?>"
                       placeholder="Ej: primo, amigo, hermana...">
            </label>
        </div>
        <label class="field">
            <span>Celular del visitante</span>
            <input type="tel" name="celular" maxlength="30" value="<?= $cel ?>" inputmode="numeric">
        </label>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Detalles del vehículo (opcional)</h3>
        <div class="grid-2">
            <label class="field"><span>Marca</span>
                <input type="text" name="marca" maxlength="60" value="<?= $marca ?>"></label>
            <label class="field"><span>Color</span>
                <input type="text" name="color" maxlength="40" value="<?= $color ?>"></label>
        </div>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Tipo de visita</h3>
        <label class="inline-radio">
            <input type="radio" name="recurrente" value="0" <?= $recurr === 0 ? 'checked' : '' ?>>
            <span>Visita ocasional / única</span>
        </label>
        <label class="inline-radio">
            <input type="radio" name="recurrente" value="1" <?= $recurr === 1 ? 'checked' : '' ?>>
            <span>⭐ Visitante recurrente (viene seguido)</span>
        </label>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Foto del vehículo (opcional)</h3>
        <?php if ($foto): ?>
            <div class="current-photo">
                <img src="<?= e(url_foto($foto)) ?>" alt="Foto"><p class="t-muted">Foto actual.</p>
            </div>
        <?php endif; ?>
        <label class="field">
            <span>Tomar foto del vehículo del visitante</span>
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" capture="environment">
        </label>
    </div>

    <div class="form-section">
        <h3 class="form-section__title">Observaciones</h3>
        <label class="field">
            <textarea name="observaciones" maxlength="500" rows="3"
                      placeholder="Ej: dejó mal parqueado, vehículo de servicio..."><?= $obs ?></textarea>
        </label>
    </div>

    <div class="form-actions">
        <a class="btn" href="<?= url('/visitantes') ?>">Cancelar</a>
        <button type="submit" class="btn btn--primary"><?= e($submitLabel ?? 'Registrar') ?></button>
    </div>
</form>

<script>
(function () {
    var input = document.getElementById('aptoInput');
    var hint  = document.getElementById('aptoHint');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var v = input.value.trim();
        hint.textContent = ''; hint.className = 'field__hint';
        if (v.length < 2) return;
        timer = setTimeout(function () {
            fetch('<?= url('/api/search_apto') ?>?q=' + encodeURIComponent(v))
              .then(r => r.json())
              .then(function (data) {
                  if (data.found) {
                      hint.textContent = '✓ Apto ' + data.numero_visible + ' (Torre ' + data.torre + ', Piso ' + data.piso + ')';
                      hint.className = 'field__hint field__hint--ok';
                  } else {
                      hint.textContent = '✗ Apto no existe.';
                      hint.className = 'field__hint field__hint--err';
                  }
              });
        }, 250);
    });
})();
</script>
