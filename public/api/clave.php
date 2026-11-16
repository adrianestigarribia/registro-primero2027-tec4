<?php
// POST /api/clave  { actual, nueva }
// Cambia la contraseña del panel. Hace falta estar logueado Y escribir la actual.
// Después del cambio, las sesiones abiertas en otros dispositivos se cierran solas;
// la de este navegador sigue abierta.
require_once __DIR__ . '/_auth.php';

soloMetodo('POST');
mismoOrigen();
requireAdmin(true);

$b = cuerpo();
$actual = $b['actual'] ?? null;
$nueva = $b['nueva'] ?? null;

if (!esClaveValida($nueva)) {
    responder(400, ['error' => 'La contraseña nueva tiene que tener entre ' . CLAVE_MIN . ' y ' . CLAVE_MAX . ' caracteres.',
        'errores' => ['nueva' => 'Entre ' . CLAVE_MIN . ' y ' . CLAVE_MAX . ' caracteres.']]);
}
if ($nueva === $actual) {
    responder(400, ['error' => 'La contraseña nueva tiene que ser distinta de la actual.', 'errores' => ['nueva' => 'Tiene que ser distinta de la actual.']]);
}
if (hash_equals(ADMIN_CLAVE, $nueva)) {
    responder(400, ['error' => 'No uses la contraseña inicial de la configuración.', 'errores' => ['nueva' => 'Elegí otra.']]);
}

try {
    if (!esClaveValida($actual) || !password_verify($actual, claveGuardada())) {
        registrarFallo(ipCliente());   // cuenta para el bloqueo, igual que el login
        responder(400, ['error' => 'La contraseña actual no es correcta.', 'errores' => ['actual' => 'No es la contraseña actual.']]);
    }
    $hash = cifrarClave($nueva);
    q('UPDATE ajustes SET valor = ? WHERE clave = ?', [$hash, CLAVE_ADMIN]);
    $_SESSION['huella'] = huellaClave($hash);   // esta sesión sigue valiendo
    session_write_close();
    responder(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('clave: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo cambiar la contraseña.']);
}
