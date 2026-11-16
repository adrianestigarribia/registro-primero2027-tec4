<?php
// GET /api/ping  ->  pedido liviano que hace el formulario al abrirse.
// - "Despierta" la conexión con la base mientras la familia completa los datos.
// - Le pasa al formulario el ciclo lectivo y el rango de fechas de nacimiento de _config.php,
//   así esos valores se cambian en un solo lugar (el formulario arma la lista de años con esto).
// - "base" dice si se pudo conectar: sirve para ver desde el navegador si la configuración está bien.
// No devuelve datos de nadie ni cuántas vacantes quedan. El detalle de un error queda solo en el log.
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_validar.php';

$base = true;
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $base = false;
    error_log('ping: ' . $e->getMessage());
}
responder(200, [
    'ok' => true,
    'base' => $base,
    'ciclo' => CICLO_LECTIVO,
    'nacimiento' => rangoNacimiento() + ['edadMin' => EDAD_MIN, 'edadMax' => EDAD_MAX],
]);
