<?php
// /home/myzonaco/smartpark.myzona360.com/modules/importaciones/contactos.php
// v7.0 — Importar residentes desde los contactos de Google (VCF o CSV).
//
// QUE HACE:
//   1. Subes el export de Google Contacts (.vcf o .csv)
//   2. Parsea "1020 Inqu Juan Jose Soto" -> apto=1020, tipo=inquilino, nombre=...
//   3. Compara contra la BD y muestra un PREVIEW con checkboxes:
//        ➕ NUEVOS      -> no existen, se van a crear
//        🔄 CAMBIOS     -> existen pero cambio el nombre/celular/tipo
//        ✓ IGUALES      -> sin cambios (no se tocan)
//        ⚠️ SIN APTO    -> el apto no existe en la BD -> se ignoran
//   4. Marcas cuales aplicar y confirmas
//
// NADA se escribe hasta que confirmas. El preview NO toca la BD.
//
// Medido con el VCF real (2077 contactos):
//   1577 residentes unicos · 161 duplicados eliminados · 575 aptos

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin', 'admin');

$pdo = db();
$u   = auth_user();
$cj  = (int)($u['conjunto_id'] ?? 1);

// ═══════════════════════════════════════════════════════════════
//  PARSER
// ═══════════════════════════════════════════════════════════════

/** Quita tildes y pasa a mayusculas (para comparar) */
function cnt_norm(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $s));
}

/** Variantes REALES encontradas en la agenda: Inqu, Inq, Prop, Pro, ... */
function cnt_tipos(): array {
    return [
        'INQU'=>'inquilino','INQ'=>'inquilino','INQUI'=>'inquilino','INQUILINO'=>'inquilino',
        'ARR'=>'inquilino','ARREND'=>'inquilino','ARRENDATARIO'=>'inquilino',
        'PROP'=>'propietario','PRO'=>'propietario','PROPIETARIO'=>'propietario','DUENO'=>'propietario',
        'FAM'=>'familiar','FAMILIAR'=>'familiar',
        'OTRO'=>'otro','ENCARGADA'=>'otro','ENCARGADO'=>'otro',
        'AUTORIZADA'=>'otro','AUTORIZADO'=>'otro',
    ];
}

/**
 * "1020 Inqu Juan Jose Soto"  ->  ['apto','tipo','nombre','conf']
 * conf = 'alta'  (trae tipo explicito)
 *      | 'media' (solo apto + nombre -> se asume inquilino, hay que revisarlo)
 */
function cnt_parsear(?string $fn): ?array {
    if (!$fn) return null;
    $t = preg_replace('/\s+/', ' ', trim($fn));
    // "1002 - FABIANA"  ->  "1002 FABIANA"
    $t = preg_replace('/^(\S+)\s*[-–—]\s*/u', '$1 ', $t);
    $p = explode(' ', $t);
    if (count($p) < 2) return null;

    $apto = trim($p[0], '.,');
    if (!preg_match('/\d/', $apto)) return null;
    // un "apto" de 6+ digitos es un telefono, no un apartamento
    if (strlen(preg_replace('/\D/', '', $apto)) > 5) return null;

    $resto = array_slice($p, 1);
    $tr    = rtrim(cnt_norm($resto[0]), '.');
    $tipos = cnt_tipos();

    if (isset($tipos[$tr])) {
        $tipo = $tipos[$tr];
        $nom  = trim(implode(' ', array_slice($resto, 1)));
        $conf = 'alta';
    } else {
        // v7.1: sin tipo -> 'otro', NO 'inquilino'.
        // Asumir inquilino seria inventar un dato que el contacto no tiene.
        // Con 'otro' no se pierde el residente y queda marcado para revisar.
        $tipo = 'otro';
        $nom  = trim(implode(' ', $resto));
        $conf = 'media';
    }
    if (mb_strlen($nom) < 3) return null;

    // ── v7.1: descartar los que traen NOTAS mezcladas con el nombre ──
    // Ej: "2012 Llamar A Elibaet Prop Gabriel Jaime Silva"
    //     "2120 Prop NO LLAMAR - Jhoana Alejandra"
    //     "222 Y 217 Prop Richard Ospina"   <- dos aptos
    // Importarlos generaria un nombre corrupto en la BD. Se dejan afuera
    // para que los arregles en Google Contacts.
    $chk = cnt_norm($fn);
    if (preg_match('/\b(LLAMAR|AVISAR|NO\s+CONTESTA|TIMBRAR|MENSAJE)\b/', $chk)) return null;
    if (preg_match('/^\S+\s+Y\s+\d/', $chk)) return null;   // "222 Y 217 ..."

    // descartar comercios
    if (preg_match('/\b(TIENDA|AUTOSERVICIO|FERRETERIA|RESTAURANTE|SALON|PANADERIA)\b/', cnt_norm($nom))) {
        return null;
    }
    $nom = mb_convert_case(mb_strtolower($nom), MB_CASE_TITLE, 'UTF-8');
    return ['apto'=>$apto, 'tipo'=>$tipo, 'nombre'=>$nom, 'conf'=>$conf];
}

/** Prefiere CELL/MOBILE. Limpia el +57. */
function cnt_tel(array $tels): ?string {
    $limp = function ($n) {
        $d = preg_replace('/\D/', '', $n ?? '');
        if (strpos($d, '57') === 0 && strlen($d) === 12) $d = substr($d, 2);
        return strlen($d) >= 7 ? $d : '';
    };
    foreach ($tels as [$tipo, $num]) {
        if (in_array($tipo, ['CELL','MOBILE'], true)) {
            $c = $limp($num);
            if ($c !== '') return $c;
        }
    }
    foreach ($tels as [$tipo, $num]) {
        $c = $limp($num);
        if ($c !== '') return $c;
    }
    return null;
}

/** VCF -> [ ['fn'=>..., 'tels'=>[[tipo,num]] ] ] */
function cnt_parse_vcf(string $txt): array {
    $txt = str_replace("\r\n", "\n", $txt);
    $txt = preg_replace('/=\n/', '', $txt);      // unir lineas quoted-printable
    $out = [];
    if (!preg_match_all('/BEGIN:VCARD(.*?)END:VCARD/s', $txt, $m)) return $out;

    foreach ($m[1] as $bloque) {
        $fn = null; $tels = [];
        foreach (explode("\n", $bloque) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || strpos($linea, ':') === false) continue;
            [$head, $val] = explode(':', $linea, 2);
            $H = strtoupper($head);

            if (strpos($H, 'FN') === 0) {
                if (strpos($H, 'QUOTED-PRINTABLE') !== false) {
                    $cs = 'UTF-8';
                    if (preg_match('/CHARSET=([\w-]+)/', $H, $mc)) $cs = $mc[1];
                    $dec = quoted_printable_decode($val);
                    $fn  = ($cs !== 'UTF-8') ? (@iconv($cs, 'UTF-8//IGNORE', $dec) ?: $dec) : $dec;
                } else {
                    $fn = $val;
                }
            } elseif (strpos($H, 'TEL') === 0) {
                $tipo = 'OTHER';
                foreach (['CELL','MOBILE','HOME','WORK','MAIN','VOICE'] as $t) {
                    if (strpos($H, $t) !== false) { $tipo = $t; break; }
                }
                $tels[] = [$tipo, $val];
            }
        }
        if ($fn) $out[] = ['fn' => trim($fn), 'tels' => $tels];
    }
    return $out;
}

/** CSV de Google Contacts */
function cnt_parse_csv(string $txt): array {
    $lineas = preg_split('/\r\n|\n|\r/', $txt);
    if (!$lineas) return [];
    $head = str_getcsv(array_shift($lineas));
    $idx  = [];
    foreach ($head as $i => $h) $idx[cnt_norm($h)] = $i;

    $cName = $idx['NAME'] ?? $idx['DISPLAY NAME'] ?? $idx['FIRST NAME'] ?? 0;
    $cols  = [];
    foreach ($idx as $k => $i) {
        if (strpos($k, 'PHONE') !== false && strpos($k, 'VALUE') !== false) $cols[] = $i;
    }
    if (!$cols) {
        foreach ($idx as $k => $i) if (strpos($k, 'PHONE') !== false) $cols[] = $i;
    }

    $out = [];
    foreach ($lineas as $l) {
        if (trim($l) === '') continue;
        $r  = str_getcsv($l);
        $fn = $r[$cName] ?? '';
        if (trim($fn) === '') continue;
        $tels = [];
        foreach ($cols as $c) {
            if (!empty($r[$c])) $tels[] = ['CELL', $r[$c]];
        }
        $out[] = ['fn' => trim($fn), 'tels' => $tels];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════
//  POST: procesar archivo o aplicar cambios
// ═══════════════════════════════════════════════════════════════
$preview = null;
$msg     = null;
$err     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {

    // ── APLICAR los seleccionados ─────────────────────────────
    if (($_POST['accion'] ?? '') === 'aplicar') {
        $items = json_decode($_POST['items'] ?? '[]', true);
        $sel   = $_POST['sel'] ?? [];
        $nuevos = $upd = $bajas = $vehLibres = 0;

        $pdo->beginTransaction();
        try {
            foreach ($sel as $i) {
                $i = (int)$i;
                if (!isset($items[$i])) continue;
                $r = $items[$i];
                if (empty($r['apto_id'])) continue;

                // ── v7.2: BAJA de un residente que ya no está en los contactos ──
                if (($r['estado'] ?? '') === 'baja') {
                    $rid = (int)$r['res_id'];

                    // 1) LIBERAR sus vehículos: residente_id = NULL.
                    //    El vehículo NO se borra ni se archiva: sigue en el
                    //    apartamento, solo queda SIN DUEÑO asignado.
                    //    Ejemplo real: se va un hijo pero el carro se queda en
                    //    la familia -> hay que reasignarlo a mano en /vehiculos.
                    $pdo->prepare("UPDATE vehiculos
                                      SET residente_id = NULL
                                    WHERE residente_id = :r")
                        ->execute([':r' => $rid]);

                    // 2) archivar el residente (NO se borra: queda el historial)
                    $pdo->prepare("UPDATE residentes
                            SET activo = 0,
                                archivado_en = NOW(),
                                archivado_motivo = 'No aparece en la importación de contactos'
                          WHERE id = :id")
                        ->execute([':id' => $rid]);
                    $bajas++;
                    continue;
                }

                if (!empty($r['res_id'])) {
                    // UPDATE
                    $pdo->prepare("UPDATE residentes
                            SET nombre = :n, celular = :c, tipo = :t
                          WHERE id = :id")
                        ->execute([
                            ':n'  => $r['nombre'],
                            ':c'  => $r['tel'] ?: null,
                            ':t'  => $r['tipo'],
                            ':id' => (int)$r['res_id'],
                        ]);
                    $upd++;
                } else {
                    // INSERT
                    $pdo->prepare("INSERT INTO residentes
                            (apartamento_id, nombre, celular, tipo, activo, creado_en)
                         VALUES (:a, :n, :c, :t, 1, NOW())")
                        ->execute([
                            ':a' => (int)$r['apto_id'],
                            ':n' => $r['nombre'],
                            ':c' => $r['tel'] ?: null,
                            ':t' => $r['tipo'],
                        ]);
                    $nuevos++;
                }
            }
            $pdo->commit();
            $msg = "✅ Listo: {$nuevos} creado(s) · {$upd} actualizado(s)"
                 . ($bajas ? " · {$bajas} dado(s) de baja" : "");
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Error al guardar: ' . $e->getMessage();
        }
    }

    // ── SUBIR y previsualizar ─────────────────────────────────
    elseif (!empty($_FILES['archivo']['tmp_name'])) {
        $tmp = $_FILES['archivo']['tmp_name'];
        $nom = strtolower($_FILES['archivo']['name'] ?? '');
        $txt = file_get_contents($tmp);

        if ($txt === false || $txt === '') {
            $err = 'No se pudo leer el archivo.';
        } else {
            if (!mb_check_encoding($txt, 'UTF-8')) {
                $txt = mb_convert_encoding($txt, 'UTF-8', 'ISO-8859-1');
            }
            $cards = (substr($nom, -4) === '.csv') ? cnt_parse_csv($txt) : cnt_parse_vcf($txt);

            if (!$cards) {
                $err = 'No se encontraron contactos. ¿Es un export de Google Contacts (.vcf o .csv)?';
            } else {
                // 1) parsear + DEDUPLICAR (la agenda trae repetidos)
                $vistos = [];
                $dups   = 0;
                foreach ($cards as $c) {
                    $p = cnt_parsear($c['fn']);
                    if (!$p) continue;
                    $tel = cnt_tel($c['tels']);
                    $k   = $p['apto'] . '|' . ($tel ?: substr(cnt_norm($p['nombre']), 0, 20));
                    $reg = $p + ['tel' => $tel, 'orig' => $c['fn']];

                    if (isset($vistos[$k])) {
                        $dups++;
                        $ant = $vistos[$k];
                        // quedarse con el que trae tipo explicito / nombre mas completo
                        if ($reg['conf'] === 'alta' && $ant['conf'] !== 'alta') $vistos[$k] = $reg;
                        elseif ($reg['conf'] === $ant['conf']
                                && mb_strlen($reg['nombre']) > mb_strlen($ant['nombre'])) $vistos[$k] = $reg;
                    } else {
                        $vistos[$k] = $reg;
                    }
                }
                $regs = array_values($vistos);

                // 2) mapa de apartamentos del conjunto
                $aptos = [];
                $sa = $pdo->prepare("SELECT id, numero_visible FROM apartamentos WHERE conjunto_id = :c");
                $sa->execute([':c' => $cj]);
                while ($a = $sa->fetch()) {
                    $aptos[cnt_norm((string)$a['numero_visible'])] = (int)$a['id'];
                }

                // 3) residentes actuales
                $act = [];
                $sr = $pdo->prepare("
                    SELECT r.id, r.apartamento_id, r.nombre, r.celular, r.tipo
                      FROM residentes r
                      JOIN apartamentos a ON a.id = r.apartamento_id
                     WHERE a.conjunto_id = :c AND r.archivado_en IS NULL");
                $sr->execute([':c' => $cj]);
                while ($r = $sr->fetch()) {
                    $act[] = $r;
                }

                // 4) comparar
                $items = [];
                $cnt = ['nuevo'=>0, 'cambio'=>0, 'igual'=>0, 'sin_apto'=>0, 'baja'=>0];

                foreach ($regs as $r) {
                    $aid = $aptos[cnt_norm($r['apto'])] ?? 0;
                    if (!$aid) {
                        $items[] = $r + ['estado'=>'sin_apto', 'apto_id'=>0, 'res_id'=>0, 'dif'=>[]];
                        $cnt['sin_apto']++;
                        continue;
                    }

                    // buscar el residente: mismo apto + (mismo celular O mismo nombre)
                    $match = null;
                    foreach ($act as $a) {
                        if ((int)$a['apartamento_id'] !== $aid) continue;
                        $mismoTel = $r['tel'] && $a['celular']
                                  && preg_replace('/\D/','',$a['celular']) === $r['tel'];
                        $mismoNom = cnt_norm($a['nombre']) === cnt_norm($r['nombre']);
                        if ($mismoTel || $mismoNom) { $match = $a; break; }
                    }

                    if (!$match) {
                        $items[] = $r + ['estado'=>'nuevo', 'apto_id'=>$aid, 'res_id'=>0, 'dif'=>[]];
                        $cnt['nuevo']++;
                        continue;
                    }

                    $dif = [];
                    if (cnt_norm($match['nombre']) !== cnt_norm($r['nombre'])) {
                        $dif['nombre'] = [$match['nombre'], $r['nombre']];
                    }
                    $telAct = preg_replace('/\D/', '', $match['celular'] ?? '');
                    if ($r['tel'] && $telAct !== $r['tel']) {
                        $dif['celular'] = [$match['celular'] ?: '—', $r['tel']];
                    }
                    if ($match['tipo'] !== $r['tipo']) {
                        $dif['tipo'] = [$match['tipo'], $r['tipo']];
                    }

                    $items[] = $r + [
                        'estado'  => $dif ? 'cambio' : 'igual',
                        'apto_id' => $aid,
                        'res_id'  => (int)$match['id'],
                        'dif'     => $dif,
                    ];
                    $cnt[$dif ? 'cambio' : 'igual']++;
                }

                // ── v7.2: RESIDENTES QUE YA NO ESTÁN EN LOS CONTACTOS ──
                // Si un inquilino se mudó, desaparece de la agenda. Sin esto,
                // quedaría ACTIVO en la BD para siempre y sus vehículos seguirían
                // asignados a alguien que ya no vive ahí.
                //
                // OJO: solo se marcan los de aptos QUE SÍ APARECEN en el archivo.
                // Si un apto entero falta del VCF (te olvidaste de exportarlo,
                // o no tiene contactos cargados), NO tocamos a nadie de ese apto.
                // Eso evita dar de baja a medio conjunto por un export incompleto.
                $aptosEnArchivo = [];
                foreach ($items as $it) {
                    if ($it['apto_id']) $aptosEnArchivo[$it['apto_id']] = true;
                }
                // ids de residentes que SÍ vinieron en el archivo
                $vivos = [];
                foreach ($items as $it) {
                    if (!empty($it['res_id'])) $vivos[(int)$it['res_id']] = true;
                }

                $bajas = [];
                foreach ($act as $a) {
                    $aid = (int)$a['apartamento_id'];
                    if (!isset($aptosEnArchivo[$aid])) continue;   // apto no vino: no tocar
                    if (isset($vivos[(int)$a['id']]))   continue;  // sigue en la agenda
                    $bajas[] = $a;
                }

                // ¿tienen vehículos asignados? -> hay que avisarlo
                $vehDe = [];
                if ($bajas) {
                    $ids = array_map(fn($b) => (int)$b['id'], $bajas);
                    $in  = implode(',', array_fill(0, count($ids), '?'));
                    $sv  = $pdo->prepare("SELECT residente_id, placa, tipo
                                            FROM vehiculos
                                           WHERE residente_id IN ($in)
                                             AND archivado_en IS NULL");
                    $sv->execute($ids);
                    while ($v = $sv->fetch()) {
                        $vehDe[(int)$v['residente_id']][] = $v['placa'];
                    }
                }

                // buscar el numero de apto para mostrarlo
                $numDeApto = array_flip($aptos);   // id -> NUMERO_NORMALIZADO
                foreach ($bajas as $b) {
                    $items[] = [
                        'apto'    => $numDeApto[(int)$b['apartamento_id']] ?? '?',
                        'tipo'    => $b['tipo'],
                        'nombre'  => $b['nombre'],
                        'tel'     => $b['celular'],
                        'conf'    => 'alta',
                        'orig'    => '',
                        'estado'  => 'baja',
                        'apto_id' => (int)$b['apartamento_id'],
                        'res_id'  => (int)$b['id'],
                        'dif'     => [],
                        'veh'     => $vehDe[(int)$b['id']] ?? [],
                    ];
                    $cnt['baja'] = ($cnt['baja'] ?? 0) + 1;
                }

                // ordenar: cambios primero, después nuevos, bajas, iguales al final
                $ord = ['cambio'=>0, 'nuevo'=>1, 'baja'=>2, 'sin_apto'=>3, 'igual'=>4];
                usort($items, function ($a, $b) use ($ord) {
                    $c = $ord[$a['estado']] <=> $ord[$b['estado']];
                    return $c !== 0 ? $c : strnatcmp($a['apto'], $b['apto']);
                });

                $preview = [
                    'items'    => $items,
                    'cnt'      => $cnt,
                    'total'    => count($cards),
                    'dups'     => $dups,
                    'archivo'  => $_FILES['archivo']['name'],
                ];
            }
        }
    }
}

$_pageTitle = 'Importar contactos';
include INCLUDES_PATH . '/header.php';
?>

<div class="page-head">
  <h1>📇 Importar residentes desde Contactos</h1>
  <p class="page-head__sub">
    Sube el export de Google Contacts. Nada se guarda hasta que revises y confirmes.
  </p>
</div>

<?php if ($msg): ?>
  <div class="card" style="background:#dcfce7;border-color:#86efac;color:#166534">
    <b><?= e($msg) ?></b>
  </div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="card" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b">
    <b><?= e($err) ?></b>
  </div>
<?php endif; ?>

<?php if (!$preview): ?>
  <div class="card">
    <h3 style="margin-top:0">1 · Exportá tus contactos</h3>
    <ol style="line-height:1.9;color:#374151;font-size:14px">
      <li>Entrá a <b>contacts.google.com</b></li>
      <li>Menú <b>Exportar</b> → elegí <b>vCard (.vcf)</b> o <b>Google CSV</b></li>
      <li>Subí el archivo acá abajo</li>
    </ol>

    <h3>2 · Formato esperado del nombre</h3>
    <p style="font-size:14px;color:#374151">
      El contacto debe llamarse: <code>apto · tipo · nombre</code>
    </p>
    <pre style="background:#f9fafb;padding:11px;border-radius:8px;font-size:13px;line-height:1.7">1020 Inqu Juan Jose Soto González   <span style="color:#166534">→ inquilino</span>
419  Prop Medardo Restrepo          <span style="color:#166534">→ propietario</span>
2015 Inq  Paulina Henao             <span style="color:#166534">→ inquilino</span>
616  Pro  Jhon Wilmar               <span style="color:#166534">→ propietario</span>
1002 - Fabiana Andrea Diaz          <span style="color:#92400e">→ otro (el contacto no dice el tipo)</span></pre>
    <p class="t-muted" style="font-size:13px">
      Reconoce <b>Inqu · Inq · Prop · Pro · Fam</b> y variantes.
      Si el contacto <b>no trae tipo</b>, se guarda como <b>otro</b> (no se pierde) y queda marcado.
    </p>
    <p class="t-muted" style="font-size:13px;background:#fef3c7;padding:9px 11px;border-radius:7px">
      <b>⚠️ Se dejan afuera</b> los contactos con notas mezcladas en el nombre, porque
      importarlos guardaría un nombre corrupto:<br>
      <code>2012 Llamar A Elibaet Prop Gabriel</code> · <code>222 Y 217 Prop Richard</code><br>
      Arreglalos en Google Contacts y volvé a exportar.
    </p>

    <form method="post" enctype="multipart/form-data" style="margin-top:16px">
      <?= csrf_field() ?>
      <input type="file" name="archivo" accept=".vcf,.csv" required
             style="padding:11px;border:2px dashed #d1d5db;border-radius:9px;width:100%">
      <button type="submit" class="btn btn--primary" style="margin-top:11px">
        📤 Analizar archivo
      </button>
    </form>
  </div>

<?php else:
  $c = $preview['cnt'];
  $aplicables = $c['nuevo'] + $c['cambio'];
?>
  <div class="card">
    <h3 style="margin-top:0">Resultado del análisis · <?= e($preview['archivo']) ?></h3>
    <p class="t-muted" style="font-size:12.5px;margin:4px 0 8px">
      👆 Tocá una tarjeta para ver solo esos.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin:6px 0 12px">
      <div class="filtro" data-f="todos"
           style="background:#f3f4f6;padding:11px;border-radius:9px;text-align:center;cursor:pointer;
                  border:2px solid #1e6cff">
        <div style="font-size:22px;font-weight:700;color:#374151"><?= count($preview['items']) ?></div>
        <small style="color:#374151">📋 Todos</small>
      </div>
      <div class="filtro" data-f="nuevo"
           style="background:#dbeafe;padding:11px;border-radius:9px;text-align:center;cursor:pointer;
                  border:2px solid transparent">
        <div style="font-size:22px;font-weight:700;color:#1e40af"><?= $c['nuevo'] ?></div>
        <small style="color:#1e40af">➕ Nuevos</small>
      </div>
      <div class="filtro" data-f="cambio"
           style="background:#fef3c7;padding:11px;border-radius:9px;text-align:center;cursor:pointer;
                  border:2px solid transparent">
        <div style="font-size:22px;font-weight:700;color:#92400e"><?= $c['cambio'] ?></div>
        <small style="color:#92400e">🔄 Con cambios</small>
      </div>
      <div class="filtro" data-f="igual"
           style="background:#dcfce7;padding:11px;border-radius:9px;text-align:center;cursor:pointer;
                  border:2px solid transparent">
        <div style="font-size:22px;font-weight:700;color:#166534"><?= $c['igual'] ?></div>
        <small style="color:#166534">✓ Sin cambios</small>
      </div>
      <div class="filtro" data-f="baja"
           style="background:#fce7f3;padding:11px;border-radius:9px;text-align:center;cursor:pointer;
                  border:2px solid transparent">
        <div style="font-size:22px;font-weight:700;color:#9d174d"><?= $c['baja'] ?? 0 ?></div>
        <small style="color:#9d174d">👋 Ya no están</small>
      </div>
      <div class="filtro" data-f="sin_apto"
           style="background:#fee2e2;padding:11px;border-radius:9px;text-align:center;cursor:pointer;
                  border:2px solid transparent">
        <div style="font-size:22px;font-weight:700;color:#991b1b"><?= $c['sin_apto'] ?></div>
        <small style="color:#991b1b">⚠️ Apto no existe</small>
      </div>
    </div>

    <?php if (!empty($c['baja'])): ?>
      <div style="background:#fdf2f8;border:1px solid #f9a8d4;border-radius:9px;padding:12px;margin-top:6px">
        <b style="color:#9d174d">👋 <?= $c['baja'] ?> residente(s) ya no están en tus contactos</b>
        <p style="font-size:13px;color:#374151;margin:7px 0 0;line-height:1.7">
          Si los marcás, se van a <b>archivar</b> (no se borran: queda el historial)
          y <b>sus vehículos quedarán SIN DUEÑO asignado</b>.<br>
          El vehículo <b>NO se borra</b> — sigue en el apartamento. Solo hay que
          reasignarlo a mano en <a href="<?= url('/vehiculos') ?>">Vehículos</a>
          (típico: se va un hijo pero el carro queda en la familia).
        </p>
        <p style="font-size:12.5px;color:#92400e;margin:8px 0 0;background:#fef3c7;padding:7px 9px;border-radius:6px">
          <b>⚠️ Revisá uno por uno antes de marcar.</b> Si a alguien le faltó el
          contacto en la agenda, lo estarías dando de baja por error.
          Por eso vienen <b>DESMARCADOS</b> por defecto.
        </p>

        <?php
          $totalRes = $c['cambio'] + $c['igual'] + ($c['baja'] ?? 0);
          $pct = $totalRes ? round(100 * ($c['baja'] ?? 0) / $totalRes) : 0;
        ?>
        <?php if ($pct >= 8): ?>
          <p style="font-size:13px;color:#991b1b;margin:8px 0 0;background:#fee2e2;
                    padding:9px 11px;border-radius:7px;border:1px solid #fca5a5">
            <b>🚨 Son <?= $pct ?>% de tus residentes.</b> Eso es mucho.<br>
            Antes de marcarlos, preguntate: ¿de verdad se fueron <?= $c['baja'] ?> personas,
            o hay contactos que <b>no exportaste</b> / <b>están mal escritos</b> en la agenda?<br><br>
            Un contacto se pierde si le falta el apto, el tipo, o tiene notas mezcladas
            (<code>2012 Llamar A Elibaet Prop Gabriel</code>).<br>
            <b>Si dudás, no los marqués.</b> Se pueden dar de baja después, uno por uno,
            desde <a href="<?= url('/residentes') ?>">Residentes</a>.
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <p class="t-muted" style="font-size:13px">
      <?= $preview['total'] ?> contactos leídos ·
      <?= $preview['dups'] ?> duplicados en la agenda descartados ·
      <b><?= $aplicables ?></b> se pueden aplicar
    </p>
  </div>

  <form method="post" id="frm-aplicar">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="aplicar">
    <input type="hidden" name="items"
           value='<?= e(json_encode($preview['items'], JSON_UNESCAPED_UNICODE)) ?>'>

    <div class="card">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="text" id="buscar" placeholder="🔍 Buscar apto o nombre…"
               style="flex:1;min-width:190px;padding:9px 12px;border:1px solid #d1d5db;
                      border-radius:8px;font-size:14px">
        <span id="nvisible" class="t-muted" style="font-size:12.5px"></span>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <button type="button" class="btn btn--sm" onclick="marcar('cambio',true)">Marcar cambios</button>
        <button type="button" class="btn btn--sm" onclick="marcar('nuevo',true)">Marcar nuevos</button>
        <?php if (!empty($c['baja'])): ?>
          <button type="button" class="btn btn--sm" style="background:#fce7f3;color:#9d174d"
                  onclick="marcar('baja',true)">Marcar bajas</button>
        <?php endif; ?>
        <button type="button" class="btn btn--sm" onclick="marcar('all',false)">Desmarcar todo</button>
        <span style="flex:1"></span>
        <b id="ncheck" style="color:#1e6cff">0 seleccionados</b>
      </div>

      <div id="tabla-wrap" style="max-height:560px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:9px">
      <table style="width:100%;border-collapse:collapse;font-size:13.5px">
        <thead style="position:sticky;top:0;background:#f9fafb;z-index:1">
          <tr>
            <th style="padding:9px;width:36px"></th>
            <th style="padding:9px;text-align:left">Apto</th>
            <th style="padding:9px;text-align:left">Nombre</th>
            <th style="padding:9px;text-align:left">Tipo</th>
            <th style="padding:9px;text-align:left">Celular</th>
            <th style="padding:9px;text-align:left">Estado</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($preview['items'] as $i => $r):
          $e = $r['estado'];
          $bg = ['nuevo'=>'#eff6ff','cambio'=>'#fffbeb','igual'=>'#fff',
                 'sin_apto'=>'#fef2f2','baja'=>'#fdf2f8'][$e] ?? '#fff';
          $puede = in_array($e, ['nuevo','cambio','baja'], true);
          // las BAJAS vienen DESMARCADAS: hay que decidirlas una por una
          $marcado = in_array($e, ['nuevo','cambio'], true);
        ?>
          <tr data-e="<?= $e ?>"
              data-buscar="<?= e(strtoupper($r['apto'] . ' ' . $r['nombre'] . ' ' . ($r['tel'] ?? ''))) ?>"
              style="background:<?= $bg ?>;border-bottom:1px solid #f3f4f6">
            <td style="padding:8px;text-align:center">
              <?php if ($puede): ?>
                <input type="checkbox" name="sel[]" value="<?= $i ?>" class="chk"
                       <?= $marcado ? 'checked' : '' ?>
                       style="width:17px;height:17px;accent-color:<?= $e==='baja' ? '#9d174d' : '#1e6cff' ?>">
              <?php endif; ?>
            </td>
            <td style="padding:8px"><b><?= e($r['apto']) ?></b></td>
            <td style="padding:8px">
              <?= e($r['nombre']) ?>
              <?php if (!empty($r['dif']['nombre'])): ?>
                <br><small style="color:#92400e">
                  <s style="color:#9ca3af"><?= e($r['dif']['nombre'][0]) ?></s> →
                  <b><?= e($r['dif']['nombre'][1]) ?></b>
                </small>
              <?php endif; ?>
              <?php if ($r['conf'] === 'media'): ?>
                <br><small style="color:#92400e">⚠️ el contacto no dice el tipo → se guarda como <b>otro</b></small>
              <?php endif; ?>
            </td>
            <td style="padding:8px">
              <?= e($r['tipo']) ?>
              <?php if (!empty($r['dif']['tipo'])): ?>
                <br><small style="color:#92400e">
                  <s style="color:#9ca3af"><?= e($r['dif']['tipo'][0]) ?></s> →
                  <b><?= e($r['dif']['tipo'][1]) ?></b>
                </small>
              <?php endif; ?>
            </td>
            <td style="padding:8px;font-family:ui-monospace,monospace">
              <?= e($r['tel'] ?: '—') ?>
              <?php if (!empty($r['dif']['celular'])): ?>
                <br><small style="color:#92400e">
                  <s style="color:#9ca3af"><?= e($r['dif']['celular'][0]) ?></s> →
                  <b><?= e($r['dif']['celular'][1]) ?></b>
                </small>
              <?php endif; ?>
            </td>
            <td style="padding:8px;white-space:nowrap">
              <?php if ($e === 'nuevo'): ?>
                <span style="background:#dbeafe;color:#1e40af;padding:3px 8px;border-radius:9px;font-size:11px;font-weight:600">➕ NUEVO</span>
              <?php elseif ($e === 'cambio'): ?>
                <span style="background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:9px;font-size:11px;font-weight:600">🔄 CAMBIO</span>
              <?php elseif ($e === 'igual'): ?>
                <span style="background:#f3f4f6;color:#6b7280;padding:3px 8px;border-radius:9px;font-size:11px">✓ igual</span>
              <?php elseif ($e === 'baja'): ?>
                <span style="background:#fce7f3;color:#9d174d;padding:3px 8px;border-radius:9px;font-size:11px;font-weight:600">👋 YA NO ESTÁ</span>
                <?php if (!empty($r['veh'])): ?>
                  <br><small style="color:#92400e;font-weight:600">
                    🚗 <?= count($r['veh']) ?> vehículo(s) quedarán sin dueño:<br>
                    <?= e(implode(', ', $r['veh'])) ?>
                  </small>
                <?php endif; ?>
              <?php else: ?>
                <span style="background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:9px;font-size:11px;font-weight:600">⚠️ apto no existe</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div style="display:flex;gap:9px;margin-top:14px;flex-wrap:wrap">
        <button type="submit" class="btn btn--primary" id="btn-aplicar">
          💾 Aplicar seleccionados
        </button>
        <a href="<?= url('/importaciones/contactos') ?>" class="btn">Cancelar</a>
      </div>
    </div>
  </form>

  <script>
  var FILTRO = 'todos';

  /* Filtrar por tipo (al tocar una tarjeta) + por texto (buscador).
     Las filas ocultas NO se desmarcan: si marcaste 20 bajas y después
     filtrás por "nuevos", esas 20 siguen marcadas y se van a aplicar. */
  function aplicarFiltros() {
    var q = (document.getElementById('buscar').value || '').toUpperCase().trim();
    var n = 0;
    document.querySelectorAll('tr[data-e]').forEach(function (tr) {
      var okTipo = (FILTRO === 'todos' || tr.getAttribute('data-e') === FILTRO);
      var okTxt  = (!q || (tr.getAttribute('data-buscar') || '').indexOf(q) >= 0);
      var ver = okTipo && okTxt;
      tr.style.display = ver ? '' : 'none';
      if (ver) n++;
    });
    document.getElementById('nvisible').textContent = n + ' visibles';
  }

  document.querySelectorAll('.filtro').forEach(function (d) {
    d.onclick = function () {
      FILTRO = this.getAttribute('data-f');
      document.querySelectorAll('.filtro').forEach(function (x) {
        x.style.borderColor = (x === d) ? '#1e6cff' : 'transparent';
      });
      aplicarFiltros();
      document.querySelector('#tabla-wrap').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
  });
  document.getElementById('buscar').oninput = aplicarFiltros;

  function marcar(tipo, on) {
    // solo marca/desmarca lo VISIBLE (respeta el filtro activo)
    document.querySelectorAll('tr[data-e]').forEach(function (tr) {
      if (tr.style.display === 'none') return;
      var c = tr.querySelector('.chk');
      if (!c) return;
      if (tipo === 'all' || tr.getAttribute('data-e') === tipo) c.checked = on;
    });
    contar();
  }
  function contar() {
    var n = document.querySelectorAll('.chk:checked').length;
    document.getElementById('ncheck').textContent = n + ' seleccionados';
    document.getElementById('btn-aplicar').disabled = (n === 0);
  }
  document.querySelectorAll('.chk').forEach(function (c) { c.onchange = contar; });
  document.getElementById('frm-aplicar').onsubmit = function (e) {
    var n = document.querySelectorAll('.chk:checked').length;
    if (!n) { e.preventDefault(); return; }
    // contar bajas marcadas: eso archiva gente y libera vehículos
    var nb = 0;
    document.querySelectorAll('tr[data-e="baja"] .chk:checked').forEach(function(){ nb++; });
    var msg = 'Se van a aplicar ' + n + ' cambio(s) a la base de datos.';
    if (nb) {
      msg += '\n\n⚠️ ' + nb + ' residente(s) se van a ARCHIVAR.\n' +
             'Sus vehículos quedarán SIN DUEÑO asignado\n' +
             '(no se borran: hay que reasignarlos en Vehículos).';
    }
    if (!confirm(msg + '\n\n¿Continuar?')) e.preventDefault();
  };
  aplicarFiltros();
  contar();
  </script>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
