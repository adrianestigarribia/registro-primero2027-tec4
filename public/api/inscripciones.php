<?php
// GET /api/inscripciones            -> lista completa (sin anuladas), por orden de llegada
// GET /api/inscripciones?since=...  -> solo lo que cambió desde ese momento
//                                      (incluye anuladas, así el panel las saca de la tabla)
// Siempre trae también los contadores, así el panel hace un solo pedido cada 3 segundos.
// Solo con sesión de admin. La búsqueda por apellido o DNI la hace el panel sobre esta lista.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_cupos.php';

soloMetodo('GET');
requireAdmin();

$since = isset($_GET['since']) && is_string($_GET['since']) ? $_GET['since'] : '';
if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $since)) {
    responder(400, ['error' => 'Parámetro since inválido.']);
}

try {
    // Cursor con 2 s de margen para no perder escrituras que se confirmaron justo ahora;
    // el panel descarta duplicados por id. ("cursor" es palabra reservada en MariaDB)
    $cur = db()->query("SELECT DATE_FORMAT(NOW(3) - INTERVAL 2 SECOND, '%Y-%m-%d %H:%i:%s.%f') AS cur")->fetchColumn();
    $rows = $since !== ''
        ? q(SELECT_INSCRIPCION . ' WHERE updated_at >= ? ORDER BY orden', [$since])->fetchAll()
        : db()->query(SELECT_INSCRIPCION . ' WHERE anulada = 0 ORDER BY orden')->fetchAll();

    responder(200, [
        'cursor' => $cur,
        'contadores' => contadores(),
        'inscripciones' => array_map('aInscripcion', $rows),
    ]);
} catch (Throwable $e) {
    error_log('inscripciones: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo leer la base de datos.']);
}
