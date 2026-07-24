<?php
// /home/myzonaco/smartpark.myzona360.com/modules/residentes/_form.php
// Partial: formulario crear/editar residente.
// Variables esperadas: $residente (array|null), $action (string), $submitLabel (string)

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

$r = $residente ?? [];
$nombre      = e($_POST['nombre']         ?? ($r['nombre']         ?? ''));
$apto_num    = e($_POST['apto_numero']    ?? ($r['apto_numero']    ?? ''));
$tipo        =   $_POST['tipo']           ?? ($r['tipo']           ?? 'inquilino');
$celular     = e($_POST['celular']        ?? ($r['celular']        ?? ''));
$documento   = e($_POST['documento']      ?? ($r['documento']      ?? ''));
$email       = e($_POST['email']          ?? ($r['email']          ?? ''));
$vive_apto   = (string)($_POST['vive_en_apto'] ?? ($r['vive_en_apto'] ?? '1'));
?>

<form method="post" action="<?= e($action) ?>" class="form-grid">
    <?= csrf_field() ?>

    <div class="form-section">
        <h3 class="form-section__title">Datos del residente</h3>

        <div class="grid-2">
            <label class="field">
                <span>Apartamento *</span>
                <input type="text" name="apto_numero" required maxlength="10"
                       value="<?= $apto_num ?>"
                       id="aptoInput" autocomplete="off"
                       placeholder="Ej: 1024">
                <small class="field__hint" id="aptoHint"></small>
            </label>

            <label class="field">
                <span>Tipo *</span>
                <select name="tipo" required>
                    <option value="inquilino"   <?= $tipo === 'inquilino'   ? 'selected' : '' ?>>Inquilino</option>
                    <option value="propietario" <?= $tipo === 'propietario' ? 'selected' : '' ?>>Propietario</option>
                    <option value="familiar"    <?= $tipo === 'familiar'    ? 'selected' : '' ?>>Familiar</option>
                    <option value="otro"        <?= $tipo === 'otro'        ? 'selected' : '' ?>>Otro</option>
                </select>
            </label>
        </div>

        <label class="field">
            <span>Nombre completo *</span>
            <input type="text" name="nombre" required maxlength="150" value="<?= $nombre ?>">
        </label>

        <div class="grid-2">
            <label class="field">
                <span>Celular</span>
                <input type="tel" name="celular" maxlength="30" value="<?= $celular ?>"
                       inputmode="numeric" placeholder="3001234567">
            </label>
            <label class="field">
                <span>Documento (opcional)</span>
                <input type="text" name="documento" maxlength="40" value="<?= $documento ?>">
            </label>
        </div>

        <label class="field">
            <span>Email (opcional)</span>
            <input type="email" name="email" maxlength="150" value="<?= $email ?>">
        </label>

        <label class="field" style="margin-top:10px">
            <span>¿Vive en el apartamento?</span>
            <select name="vive_en_apto">
                <option value="1" <?= $vive_apto === '1' ? 'selected' : '' ?>>Sí, vive aquí</option>
                <option value="0" <?= $vive_apto === '0' ? 'selected' : '' ?>>No (propietario que arrendó)</option>
            </select>
            <small class="field__hint">
                Marca "No" si es propietario que ya no vive en el apto pero sigue siendo dueño.
                Para mudanzas completas usa el botón "Registrar mudanza" en la vista del residente.
            </small>
        </label>
    </div>

    <div class="form-actions">
        <a class="btn" href="<?= url('/residentes') ?>">Cancelar</a>
        <button type="submit" class="btn btn--primary"><?= e($submitLabel ?? 'Guardar') ?></button>
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
        hint.textContent = '';
        hint.className = 'field__hint';
        if (v.length < 3) return;
        timer = setTimeout(function () {
            fetch('<?= url('/api/search_apto') ?>?q=' + encodeURIComponent(v))
              .then(r => r.json())
              .then(function (data) {
                  if (data.found) {
                      hint.textContent = '✓ Apto ' + data.numero_visible
                          + ' (Torre ' + data.torre + ', Piso ' + data.piso + ')';
                      hint.className = 'field__hint field__hint--ok';
                  } else {
                      hint.textContent = '✗ Ese apartamento no existe en este conjunto.';
                      hint.className = 'field__hint field__hint--err';
                  }
              })
              .catch(function () {});
        }, 250);
    });
})();
</script>
