<?php
// POST /api/logout  ->  cierra la sesión del panel en este navegador
require_once __DIR__ . '/_auth.php';

soloMetodo('POST');
mismoOrigen();
cerrarSesion();
responder(200, ['ok' => true]);
