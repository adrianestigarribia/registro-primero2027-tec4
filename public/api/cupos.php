<?php
// GET /api/cupos  ->  contadores de vacantes (solo con sesión de admin)
// { cupo, ocupadas, libres, espera, fueraZona, anuladas }
// No hay ninguna versión pública de esto: el formulario no muestra cuántas vacantes quedan.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_cupos.php';

soloMetodo('GET');
requireAdmin();

try {
    responder(200, contadores());
} catch (Throwable $e) {
    error_log('cupos: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo leer la base de datos.']);
}
