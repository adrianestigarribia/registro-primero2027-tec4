<?php
// POST /api/anular  { id }
// Anula una inscripción (borrado lógico: queda en la base con anulada = 1, nunca DELETE).
// Si era Ingresante, la vacante que libera pasa al primero de la Lista de Espera
// (por orden de llegada), en la misma transacción.
// 200 { ok, anulada, promovidas: [ ... ] } · 404 no existe · 409 ya estaba anulada
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_validar.php';
require_once __DIR__ . '/_cupos.php';

soloMetodo('POST');
mismoOrigen();
requireAdmin();

$id = idDe(cuerpo()['id'] ?? null);
if (!$id) responder(400, ['error' => 'Falta el número de solicitud.']);

try {
    $r = anular($id);
    responder(200, ['ok' => true, 'anulada' => $r['anulada'], 'promovidas' => $r['promovidas'], 'contadores' => contadores()]);
} catch (Throwable $e) {
    if ($e instanceof HttpError) responder($e->http, ['error' => $e->getMessage()]);
    if (esReintentable($e)) responder(503, ['error' => 'La base está ocupada. Probá de nuevo en unos segundos.']);
    error_log('anular: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo anular la inscripción.']);
}
