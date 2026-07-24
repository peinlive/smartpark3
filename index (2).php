<?php
// /home/myzonaco/smartpark.myzona360.com/modules/anonimizar/index.php
// v1.0 — MÓDULO INDEPENDIENTE: Anonimizar residentes (habeas data).
//
// QUÉ HACE (solo cuando se confirma explícitamente):
//   1. Borra TODOS los residentes del conjunto.
//   2. Crea, por cada apartamento, exactamente 2 residentes:
//        • "Propietario"  (tipo propietario, vive_en_apto = 1)
//        • "Residente"    (tipo inquilino,   vive_en_apto = 1)
//      ambos con celular/documento/email en blanco.
//   3. Reata los vehículos: los que estaban a un residente tipo
//      propietario quedan al nuevo "Propietario"; el resto al nuevo
//      "Residente" del MISMO apartamento.
//   4. Limpia propietario_nombre / propietario_celular de apartamentos.
//
// SEGURIDAD:
//   • Solo super_admin.
//   • Exige escribir una frase de confirmación.
//   • Todo dentro de una transacción: si algo falla, no se aplica nada.
//   • NO toca celdas, cuartos, revistas, observaciones ni novedades.
//
// Este módulo es independiente: no modifica ningún archivo existente.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

auth_require_role('super_admin');

$pdo = db();
$u   = auth_user();
$conjuntoId = (int)($u['conjunto_id'] ?? 1);

$FRASE = 'ANONIMIZAR RESIDENTES';

$hecho   = false;
$resumen = [];
$error   = '';

// ───────── Conteos actuales (para mostrar antes de ejecutar) ─────────
$stats = ['aptos' => 0, 'residentes' => 0, 'archivados' => 0, 'vehiculos' => 0];
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM apartamentos WHERE conjunto_id = :c");
    $q->execute([':c' => $conjuntoId]);
    $stats['aptos'] = (int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM residentes r
                          JOIN apartamentos a ON a.id = r.apartamento_id
                         WHERE a.conjunto_id = :c AND r.archivado_en IS NULL");
    $q->execute([':c' => $conjuntoId]);
    $stats['residentes'] = (int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM residentes r
                          JOIN apartamentos a ON a.id = r.apartamento_id
                         WHERE a.conjunto_id = :c AND r.archivado_en IS NOT NULL");
    $q->execute([':c' => $conjuntoId]);
    $stats['archivados'] = (int)$q->fetchColumn();

    $q = $pdo->prepare("SELECT COUNT(*) FROM vehiculos v
                          JOIN apartamentos a ON a.id = v.apartamento_id
                         WHERE a.conjunto_id = :c AND v.residente_id IS NOT NULL");
    $q->execute([':c' => $conjuntoId]);
    $stats['vehiculos'] = (int)$q->fetchColumn();
} catch (Throwable $e) {
    $error = 'No se pudieron leer los conteos: ' . $e->getMessage();
}

// ───────── Ejecución ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $frase = trim((string)($_POST['frase'] ?? ''));

    if ($frase !== $FRASE) {
        $error = 'La frase de confirmación no coincide. No se hizo ningún cambio.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1) Mapa: apartamento -> vehículos y si su residente era propietario
            //    Se guarda ANTES de borrar, para poder reatar después.
            $mapaVeh = [];   // apartamento_id => [ ['id'=>vehId,'era_prop'=>0|1], ... ]
            $qv = $pdo->prepare("
                SELECT v.id AS veh_id, v.apartamento_id,
                       CASE WHEN r.tipo = 'propietario' THEN 1 ELSE 0 END AS era_prop
                  FROM vehiculos v
                  JOIN apartamentos a ON a.id = v.apartamento_id
             LEFT JOIN residentes r   ON r.id = v.residente_id
                 WHERE a.conjunto_id = :c");
            $qv->execute([':c' => $conjuntoId]);
            while ($row = $qv->fetch()) {
                $ap = (int)$row['apartamento_id'];
                if (!isset($mapaVeh[$ap])) $mapaVeh[$ap] = [];
                $mapaVeh[$ap][] = [
                    'id'       => (int)$row['veh_id'],
                    'era_prop' => (int)$row['era_prop'],
                ];
            }

            // 2) Borrar TODOS los residentes del conjunto (activos y archivados).
            //    La FK de vehiculos es ON DELETE SET NULL: los vehículos quedan
            //    temporalmente sin residente y se reatan en el paso 4.
            $del = $pdo->prepare("
                DELETE r FROM residentes r
                  JOIN apartamentos a ON a.id = r.apartamento_id
                 WHERE a.conjunto_id = :c");
            $del->execute([':c' => $conjuntoId]);
            $resumen['residentes_borrados'] = $del->rowCount();

            // 3) Crear 2 residentes por apartamento
            $qa = $pdo->prepare("SELECT id FROM apartamentos WHERE conjunto_id = :c ORDER BY id");
            $qa->execute([':c' => $conjuntoId]);
            $aptos = $qa->fetchAll(PDO::FETCH_COLUMN);

            $insRes = $pdo->prepare("
                INSERT INTO residentes
                    (apartamento_id, nombre, celular, tipo, vive_en_apto, activo, creado_en)
                VALUES (:ap, :nom, '', :tipo, 1, 1, NOW())");

            $nuevoProp = [];   // apartamento_id => id del nuevo Propietario
            $nuevoResi = [];   // apartamento_id => id del nuevo Residente
            $creados = 0;

            foreach ($aptos as $apId) {
                $apId = (int)$apId;

                $insRes->execute([':ap' => $apId, ':nom' => 'Propietario', ':tipo' => 'propietario']);
                $nuevoProp[$apId] = (int)$pdo->lastInsertId();
                $creados++;

                $insRes->execute([':ap' => $apId, ':nom' => 'Residente', ':tipo' => 'inquilino']);
                $nuevoResi[$apId] = (int)$pdo->lastInsertId();
                $creados++;
            }
            $resumen['residentes_creados'] = $creados;
            $resumen['apartamentos']       = count($aptos);

            // 4) Reatar vehículos según a quién estaban antes
            $upVeh = $pdo->prepare("UPDATE vehiculos SET residente_id = :r WHERE id = :v");
            $reatados = 0;
            foreach ($mapaVeh as $apId => $vehs) {
                foreach ($vehs as $v) {
                    $destino = $v['era_prop'] === 1
                        ? ($nuevoProp[$apId] ?? null)
                        : ($nuevoResi[$apId] ?? null);
                    if ($destino) {
                        $upVeh->execute([':r' => $destino, ':v' => $v['id']]);
                        $reatados++;
                    }
                }
            }
            $resumen['vehiculos_reatados'] = $reatados;

            // 5) Limpiar datos personales del propietario en apartamentos
            $upAp = $pdo->prepare("
                UPDATE apartamentos
                   SET propietario_nombre = 'Propietario', propietario_celular = ''
                 WHERE conjunto_id = :c");
            $upAp->execute([':c' => $conjuntoId]);
            $resumen['apartamentos_limpiados'] = $upAp->rowCount();

            $pdo->commit();
            $hecho = true;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error al anonimizar (no se aplicó ningún cambio): ' . $e->getMessage();
        }
    }
}

$_pageTitle = 'Anonimizar residentes';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
    <h1 class="page-head__title">🔒 Anonimizar residentes</h1>
    <p class="page-head__sub">Herramienta de uso especial (habeas data).</p>
</div>

<?php if ($error): ?>
    <div style="background:#fee2e2;border:2px solid #dc2626;border-radius:10px;padding:14px;margin:14px 0;color:#991b1b">
        <b>⚠️ <?= e($error) ?></b>
    </div>
<?php endif; ?>

<?php if ($hecho): ?>
    <div style="background:#dcfce7;border:2px solid #22c55e;border-radius:10px;padding:16px;margin:14px 0">
        <h2 style="color:#166534;margin-bottom:8px">✅ Listo. Datos anonimizados.</h2>
        <ul style="margin-left:18px;line-height:1.8;color:#166534">
            <li>Residentes borrados: <b><?= (int)($resumen['residentes_borrados'] ?? 0) ?></b></li>
            <li>Apartamentos procesados: <b><?= (int)($resumen['apartamentos'] ?? 0) ?></b></li>
            <li>Residentes creados: <b><?= (int)($resumen['residentes_creados'] ?? 0) ?></b>
                (1 "Propietario" + 1 "Residente" por apto)</li>
            <li>Vehículos reatados: <b><?= (int)($resumen['vehiculos_reatados'] ?? 0) ?></b></li>
            <li>Apartamentos limpiados: <b><?= (int)($resumen['apartamentos_limpiados'] ?? 0) ?></b></li>
        </ul>
        <p style="margin-top:10px">
            <a class="btn btn--primary" href="<?= url('/residentes') ?>">Ver residentes</a>
        </p>
    </div>
<?php else: ?>

    <div style="background:#fff7ed;border:2px solid #f59e0b;border-radius:10px;padding:16px;margin:14px 0">
        <h3 style="color:#b45309;margin-bottom:8px">⚠️ Esto NO se puede deshacer</h3>
        <p style="color:#92400e;line-height:1.7">
            Se van a <b>borrar todos los residentes</b> y en su lugar se creará, por
            cada apartamento, un <b>"Propietario"</b> y un <b>"Residente"</b> con los
            celulares en blanco.
        </p>
    </div>

    <div class="card" style="margin:14px 0">
        <h3 style="margin-bottom:10px">1️⃣ Primero: descargá el respaldo</h3>
        <p class="muted" style="margin-bottom:10px">
            Guardá el archivo antes de continuar. Incluye los ID, nombres, celulares
            y vehículos actuales.
        </p>
        <a class="btn btn--primary" href="<?= url('/exportar?t=residentes') ?>">
            📥 Descargar respaldo de residentes (CSV)
        </a>
    </div>

    <div class="card" style="margin:14px 0">
        <h3 style="margin-bottom:10px">2️⃣ Qué hay ahora en la base</h3>
        <table style="width:100%;font-size:14px">
            <tr><td>Apartamentos</td><td style="text-align:right"><b><?= (int)$stats['aptos'] ?></b></td></tr>
            <tr><td>Residentes activos</td><td style="text-align:right"><b><?= (int)$stats['residentes'] ?></b></td></tr>
            <tr><td>Residentes archivados</td><td style="text-align:right"><b><?= (int)$stats['archivados'] ?></b></td></tr>
            <tr><td>Vehículos ligados a un residente</td><td style="text-align:right"><b><?= (int)$stats['vehiculos'] ?></b></td></tr>
        </table>
        <p class="muted" style="margin-top:10px">
            Después quedarán <b><?= (int)$stats['aptos'] * 2 ?></b> residentes
            (2 por apartamento) y los vehículos se reatarán a los nuevos.
        </p>
    </div>

    <div class="card" style="margin:14px 0;border:2px solid #dc2626">
        <h3 style="margin-bottom:10px;color:#991b1b">3️⃣ Confirmar</h3>
        <p style="margin-bottom:10px">
            Para ejecutar, escribí exactamente:
            <b style="font-family:monospace;background:#fee2e2;padding:2px 8px;border-radius:5px"><?= e($FRASE) ?></b>
        </p>
        <form method="post" onsubmit="return confirm('ÚLTIMA CONFIRMACIÓN.\n\nSe borrarán todos los residentes. ¿Continuar?');">
            <?= csrf_field() ?>
            <input type="text" name="frase" autocomplete="off" required
                   placeholder="Escribí la frase exacta"
                   style="width:100%;max-width:420px;padding:11px;border:2px solid #d1d5db;border-radius:8px;font-size:15px;margin-bottom:10px">
            <br>
            <button type="submit" class="btn"
                    style="background:#dc2626;color:#fff;padding:12px 22px;font-size:15px;font-weight:700">
                🔒 Anonimizar ahora
            </button>
            <a class="btn" href="<?= url('/dashboard') ?>" style="margin-left:8px">Cancelar</a>
        </form>
    </div>

<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
