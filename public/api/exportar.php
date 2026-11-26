<?php
// GET /api/exportar  ->  descarga el listado completo en CSV (solo con sesión de admin)
//
// - UTF-8 con BOM: Excel lo abre con las tildes y la ñ bien.
// - Separador punto y coma (;): es el que espera Excel configurado en español (Argentina).
// - Protección contra inyección de fórmulas: si una celda empieza con = + - @ (o tab / retorno),
//   se le antepone un apóstrofo, así Excel la muestra como texto y nunca la ejecuta.
// - Orden: Ingresantes, Lista de Espera, Fuera de zona (cada grupo por orden de llegada)
//   y al final las anuladas, marcadas como tales.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_cupos.php';

soloMetodo('GET');
requireAdmin();

function celdaSegura($v)
{
    $v = (string) $v;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) return "'" . $v;
    return $v;
}

// "1123456789" -> "112345-6789". Con el guion, Excel lo toma como texto: no le saca un 0
// inicial ni lo muestra como 1,12E+09.
function telefonoLegible($t)
{
    if ($t === null || $t === '') return '';
    return substr($t, 0, -4) . '-' . substr($t, -4);
}

try {
    $rows = db()->query(
        "SELECT id, orden, estado, anulada, alumno_apellido, alumno_nombre, dni,
                DATE_FORMAT(fecha_nacimiento, '%d/%m/%Y') AS nacimiento,
                escuela_primaria, calle, numero, piso_depto, cp, email, telefono,
                responsable_apellido, responsable_nombre,
                DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS fecha_insc,
                DATE_FORMAT(promovida_at, '%d/%m/%Y %H:%i') AS fecha_prom,
                DATE_FORMAT(anulada_at, '%d/%m/%Y %H:%i') AS fecha_anul,
                alerta
           FROM inscripciones
          ORDER BY anulada, FIELD(estado, 'ingresante', 'espera', 'fuera_zona'), orden"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('exportar: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo leer la base de datos.']);
}

$nombre = 'inscripciones-1er-anio-' . date('Y-m-d-Hi') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM
$columnas = [
    'N° solicitud', 'Orden de llegada', 'Estado', 'Apellido/s alumno', 'Nombre/s alumno', 'DNI',
    'Fecha de nacimiento', 'Escuela primaria', 'Calle', 'Número', 'Piso/Depto', 'CP', 'Email', 'Teléfono',
    'Apellido responsable', 'Nombre responsable', 'Fecha de inscripción', 'Promovida desde espera',
    'Anulada', 'Alerta',
];
fputcsv($out, $columnas, ';', '"', '', "\r\n");

$etiqueta = ['ingresante' => 'Ingresante', 'espera' => 'Lista de Espera', 'fuera_zona' => 'Fuera de zona'];
foreach ($rows as $r) {
    $anulada = (int) $r['anulada'] === 1;
    $fila = [
        $r['id'], $r['orden'],
        $anulada ? 'Anulada (era ' . $etiqueta[$r['estado']] . ')' : $etiqueta[$r['estado']],
        $r['alumno_apellido'], $r['alumno_nombre'], $r['dni'], $r['nacimiento'],
        $r['escuela_primaria'], $r['calle'], $r['numero'], $r['piso_depto'] ?? '', $r['cp'], $r['email'], telefonoLegible($r['telefono']),
        $r['responsable_apellido'], $r['responsable_nombre'], $r['fecha_insc'], $r['fecha_prom'] ?? '',
        $anulada ? $r['fecha_anul'] : '', $r['alerta'] ?? '',
    ];
    fputcsv($out, array_map('celdaSegura', $fila), ';', '"', '', "\r\n");
}
fclose($out);
