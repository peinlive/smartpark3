<?php
// /home/myzonaco/smartpark.myzona360.com/modules/exportar.php
// v7.5 — Exportar a CSV (se abre en Excel).
//
// USO:  /exportar?t=residentes
//       /exportar?t=vehiculos
//       /exportar?t=parqueadero
//       /exportar?t=apartamentos
//       /exportar?t=observaciones
//
// POR QUE CSV Y NO XLSX:
//   Generar un .xlsx de verdad necesita PhpSpreadsheet (una librería pesada
//   que no está instalada). El CSV se abre en Excel con doble click y sirve
//   igual para revisar y para archivar.
//
//   Se le pone el BOM de UTF-8 para que Excel muestre bien las tildes y las Ñ
//   (sin eso, "Muñoz" sale como "MuÃ±oz").
//
//   El separador es ';' porque Excel en español lo espera así.
//
// SOLO LEE. No modifica nada.

if (!defined('SMARTPARK_BOOT')) { http_response_code(403); exit('Forbidden'); }
auth_require_role('super_admin', 'admin', 'supervisor');

$pdo = db();
$u   = auth_user();
$cj  = (int)($u['conjunto_id'] ?? 1);
$t   = $_GET['t'] ?? '';

/** Manda el CSV al navegador */
function exp_csv(string $nombre, array $cabecera, array $filas): void
{
    $archivo = $nombre . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // BOM UTF-8: sin esto Excel rompe las tildes
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, $cabecera, ';');
    foreach ($filas as $f) {
        fputcsv($out, $f, ';');
    }
    fclose($out);
    exit;
}

try {
    switch ($t) {

        // ══════════════ RESIDENTES ══════════════
        case 'residentes':
            $st = $pdo->prepare("
                SELECT r.id AS residente_id, a.id AS apartamento_id,
                       a.numero_visible AS apto, tr.numero AS torre, a.piso,
                       r.nombre, r.tipo, r.celular, r.documento, r.email,
                       r.vive_en_apto, r.activo,
                       r.archivado_en, r.archivado_motivo, r.creado_en,
                       (SELECT GROUP_CONCAT(v.placa SEPARATOR ' / ')
                          FROM vehiculos v
                         WHERE v.residente_id = r.id AND v.archivado_en IS NULL) AS placas
                  FROM residentes r
                  JOIN apartamentos a ON a.id = r.apartamento_id
             LEFT JOIN torres tr      ON tr.id = a.torre_id
                 WHERE a.conjunto_id = :c
              ORDER BY tr.numero, a.numero_visible, r.tipo, r.nombre");
            $st->execute([':c' => $cj]);

            $filas = [];
            while ($r = $st->fetch()) {
                $filas[] = [
                    $r['residente_id'], $r['apartamento_id'],
                    $r['apto'], $r['torre'], $r['piso'],
                    $r['nombre'], $r['tipo'], $r['celular'],
                    $r['documento'] ?: '', $r['email'] ?: '',
                    (int)$r['vive_en_apto'] === 1 ? 'SI' : 'NO',
                    $r['activo'] ? 'activo' : 'ARCHIVADO',
                    $r['archivado_en'] ?: '',
                    $r['archivado_motivo'] ?: '',
                    $r['creado_en'] ?: '',
                    $r['placas'] ?: '',
                ];
            }
            exp_csv('residentes',
                ['ID Residente','ID Apartamento','Apto','Torre','Piso',
                 'Nombre','Tipo','Celular','Documento','Email','Vive en apto',
                 'Estado','Archivado el','Motivo','Creado el','Vehiculos'],
                $filas);
            break;

        // ══════════════ VEHÍCULOS ══════════════
        case 'vehiculos':
            $st = $pdo->prepare("
                SELECT v.placa, v.tipo, v.marca, v.color,
                       a.numero_visible AS apto, tr.numero AS torre,
                       r.nombre AS residente, r.tipo AS res_tipo, r.celular,
                       v.observaciones, v.archivado_en,
                       (SELECT GROUP_CONCAT(c.nombre_visible SEPARATOR ' / ')
                          FROM celdas c
                         WHERE c.apto_dueno_id = a.id) AS celdas
                  FROM vehiculos v
                  JOIN apartamentos a ON a.id = v.apartamento_id
             LEFT JOIN torres tr      ON tr.id = a.torre_id
             LEFT JOIN residentes r   ON r.id = v.residente_id
                 WHERE v.conjunto_id = :c
              ORDER BY v.placa");
            $st->execute([':c' => $cj]);

            $filas = [];
            while ($r = $st->fetch()) {
                $filas[] = [
                    $r['placa'], $r['tipo'], $r['marca'], $r['color'],
                    $r['apto'], $r['torre'],
                    // ¡Este es el dato clave tras la importación de contactos!
                    $r['residente'] ?: '*** SIN DUEÑO ASIGNADO ***',
                    $r['res_tipo'] ?: '',
                    $r['celular'] ?: '',
                    $r['celdas'] ?: '',
                    $r['observaciones'] ?: '',
                    $r['archivado_en'] ? 'ARCHIVADO' : 'activo',
                ];
            }
            exp_csv('vehiculos',
                ['Placa','Tipo','Marca','Color','Apto','Torre','Residente',
                 'Tipo residente','Celular','Celdas del apto','Observaciones','Estado'],
                $filas);
            break;

        // ══════════════ PARQUEADERO ══════════════
        case 'parqueadero':
            $st = $pdo->prepare("
                SELECT c.id AS celda_id, c.nombre_visible AS celda, c.tipo, c.activa,
                       n.codigo AS nivel, n.nombre AS nivel_nombre,
                       ad.numero_visible AS apto_dueno,
                       au.numero_visible AS apto_usuario,
                       ac.tipo AS asignacion, ac.valor_mensual,
                       ac.fecha_inicio, ac.observacion,
                       (SELECT GROUP_CONCAT(v.placa SEPARATOR ' / ')
                          FROM vehiculos v
                         WHERE v.apartamento_id = COALESCE(ac.apto_usuario_id, c.apto_dueno_id)
                           AND v.archivado_en IS NULL) AS placas
                  FROM celdas c
             LEFT JOIN niveles_parqueadero n ON n.id = c.nivel_id
             LEFT JOIN apartamentos ad       ON ad.id = c.apto_dueno_id
             LEFT JOIN asignaciones_celdas ac
                    ON ac.celda_id = c.id AND ac.activa = 1 AND ac.archivado_en IS NULL
             LEFT JOIN apartamentos au       ON au.id = ac.apto_usuario_id
                 WHERE c.conjunto_id = :c
              ORDER BY n.codigo, c.numero_orden, c.nombre_visible");
            $st->execute([':c' => $cj]);

            // 'prestamo_gratis' se muestra como "Autorizado" (el enum en BD no cambia)
            $etiq = [
                'uso_propio'      => 'Uso propio',
                'prestamo_gratis' => 'Autorizado',
                'alquiler'        => 'Alquiler',
            ];
            $tipoCelda = [
                'comun'              => 'Común',
                'privada'            => 'Privada',
                'moto_comun'         => 'Moto',
                'libre'              => 'Libre',
                'movilidad_reducida' => 'Movilidad reducida',
            ];

            $filas = [];
            while ($r = $st->fetch()) {
                $filas[] = [
                    $r['celda_id'],
                    $r['celda'],
                    $r['nivel'], $r['nivel_nombre'],
                    $tipoCelda[$r['tipo']] ?? $r['tipo'],
                    $r['apto_dueno'] ?: '',
                    $r['apto_usuario'] ?: '',
                    $etiq[$r['asignacion']] ?? ($r['asignacion'] ?: ''),
                    $r['valor_mensual'] ? number_format((float)$r['valor_mensual'], 0, ',', '.') : '',
                    $r['fecha_inicio'] ?: '',
                    $r['placas'] ?: '',
                    $r['observacion'] ?: '',
                    $r['activa'] ? 'activa' : 'INACTIVA',
                ];
            }
            exp_csv('parqueadero',
                ['ID BD','Celda','Nivel','Nombre nivel','Tipo celda','Apto dueño','Apto usuario',
                 'Asignación','Valor mensual','Desde','Placas','Observación','Estado'],
                $filas);
            break;

        // ══════════════ APARTAMENTOS ══════════════
        case 'apartamentos':
            $st = $pdo->prepare("
                SELECT a.numero_visible AS apto, t.numero AS torre, t.nombre AS torre_nombre,
                       a.piso, a.estado_morosidad, a.meses_mora, a.bloqueo_comunes,
                       a.propietario_nombre, a.propietario_celular,
                       (SELECT COUNT(*) FROM residentes r
                         WHERE r.apartamento_id = a.id AND r.activo = 1) AS n_res,
                       (SELECT COUNT(*) FROM vehiculos v
                         WHERE v.apartamento_id = a.id AND v.tipo = 'carro'
                           AND v.archivado_en IS NULL) AS n_carros,
                       (SELECT COUNT(*) FROM vehiculos v
                         WHERE v.apartamento_id = a.id AND v.tipo = 'moto'
                           AND v.archivado_en IS NULL) AS n_motos,
                       (SELECT GROUP_CONCAT(c.nombre_visible SEPARATOR ' / ')
                          FROM celdas c WHERE c.apto_dueno_id = a.id) AS celdas
                  FROM apartamentos a
             LEFT JOIN torres t ON t.id = a.torre_id
                 WHERE a.conjunto_id = :c
              ORDER BY t.numero, a.numero_visible");
            $st->execute([':c' => $cj]);

            $filas = [];
            while ($r = $st->fetch()) {
                $filas[] = [
                    $r['apto'], $r['torre'], $r['torre_nombre'], $r['piso'],
                    $r['estado_morosidad'] ?: 'al_dia',
                    (int)$r['meses_mora'],
                    $r['bloqueo_comunes'] ? 'SI' : 'no',
                    $r['propietario_nombre'] ?: '',
                    $r['propietario_celular'] ?: '',
                    (int)$r['n_res'], (int)$r['n_carros'], (int)$r['n_motos'],
                    $r['celdas'] ?: '',
                ];
            }
            exp_csv('apartamentos',
                ['Apto','Torre','Nombre torre','Piso','Morosidad','Meses mora',
                 'Bloqueo comunes','Propietario','Cel. propietario',
                 'Residentes','Carros','Motos','Celdas'],
                $filas);
            break;

        // ══════════════ OBSERVACIONES ══════════════
        case 'observaciones':
            $st = $pdo->prepare("
                SELECT o.creado_en, v.placa, v.tipo AS veh_tipo,
                       a.numero_visible AS apto, t.numero AS torre,
                       o.tipo, o.gravedad, o.descripcion, o.estado,
                       us.nombre_completo AS usuario
                  FROM observaciones_vehiculo o
                  JOIN vehiculos v      ON v.id = o.vehiculo_id
             LEFT JOIN apartamentos a   ON a.id = v.apartamento_id
             LEFT JOIN torres t         ON t.id = a.torre_id
             LEFT JOIN usuarios us      ON us.id = o.usuario_registra
                 WHERE v.conjunto_id = :c
              ORDER BY o.creado_en DESC");
            $st->execute([':c' => $cj]);

            $filas = [];
            while ($r = $st->fetch()) {
                $filas[] = [
                    $r['creado_en'], $r['placa'], $r['veh_tipo'],
                    $r['apto'] ?: '', $r['torre'] ?: '',
                    $r['tipo'], $r['gravedad'],
                    $r['descripcion'], $r['estado'] ?: '',
                    $r['usuario'] ?: '',
                ];
            }
            exp_csv('observaciones',
                ['Fecha','Placa','Tipo vehículo','Apto','Torre',
                 'Tipo novedad','Gravedad','Descripción','Estado','Registró'],
                $filas);
            break;

        default:
            http_response_code(400);
            echo 'Tipo de exportación no válido. Usá: residentes, vehiculos, '
               . 'parqueadero, apartamentos u observaciones.';
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error al exportar: ' . htmlspecialchars($e->getMessage());
}
