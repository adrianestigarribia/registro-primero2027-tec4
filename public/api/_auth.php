<?php
// Login del panel de administración.
//
// - La contraseña se guarda en la tabla `ajustes` cifrada con password_hash (bcrypt, con sal):
//   en la base nunca queda en texto. Si todavía no hay ninguna, vale ADMIN_CLAVE de la
//   configuración. El admin la cambia desde el panel (POST /api/clave).
// - Al entrar bien se abre una sesión PHP: cookie HttpOnly (JavaScript no la puede leer),
//   SameSite=Strict (otra página no la puede usar) y Secure si el sitio está en HTTPS.
//   Así bcrypt se calcula solo al entrar, no en cada consulta del panel.
// - Después de MAX_FALLOS contraseñas incorrectas desde la misma IP, esa IP queda
//   bloqueada BLOQUEO_SEG segundos, así no se puede adivinar probando.
// - Si se cambia la contraseña, las sesiones abiertas en otros dispositivos se cierran solas.
require_once __DIR__ . '/_db.php';

const CLAVE_ADMIN = 'admin_clave';
const MAX_FALLOS = 5;
const BLOQUEO_SEG = 300;          // 5 minutos
const VENTANA_MIN = 15;           // los fallos se cuentan dentro de esta ventana
const SESION_MAX_SEG = 12 * 3600; // una sesión dura como mucho 12 horas
const CLAVE_MIN = 6;
const CLAVE_MAX = 72;             // bcrypt solo usa los primeros 72 bytes

function esClaveValida($c)
{
    return is_string($c) && strlen($c) >= CLAVE_MIN && strlen($c) <= CLAVE_MAX && mb_check_encoding($c, 'UTF-8');
}

function cifrarClave($c)
{
    return password_hash($c, PASSWORD_BCRYPT, ['cost' => 11]);
}

// Lee la contraseña cifrada; la primera vez guarda la inicial de la configuración.
function claveGuardada()
{
    $v = q('SELECT valor FROM ajustes WHERE clave = ?', [CLAVE_ADMIN])->fetchColumn();
    if ($v !== false) return $v;
    if (!esClaveValida(ADMIN_CLAVE)) throw new RuntimeException('ADMIN_CLAVE tiene que tener entre 6 y 72 caracteres.');
    q('INSERT IGNORE INTO ajustes (clave, valor) VALUES (?, ?)', [CLAVE_ADMIN, cifrarClave(ADMIN_CLAVE)]);
    return q('SELECT valor FROM ajustes WHERE clave = ?', [CLAVE_ADMIN])->fetchColumn();
}

// "Huella" de la contraseña actual: si cambia, las sesiones viejas dejan de valer
function huellaClave($hash)
{
    return hash('sha256', $hash);
}

function esHttps()
{
    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function iniciarSesion()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');   // no acepta ids de sesión inventados
    ini_set('session.use_only_cookies', '1');
    session_name('insc_sid');
    session_set_cookie_params([
        'lifetime' => 0,           // se borra al cerrar el navegador
        'path' => '/',
        'secure' => esHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Rechaza pedidos de escritura del panel que vengan de otra página (defensa extra además de
// SameSite y del Content-Type JSON). Los navegadores mandan Origin y Sec-Fetch-Site.
function mismoOrigen()
{
    $sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sitio !== '' && $sitio !== 'same-origin' && $sitio !== 'none') {
        responder(403, ['error' => 'Pedido rechazado: no viene del panel.']);
    }
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origen !== '' && parse_url($origen, PHP_URL_HOST) . (parse_url($origen, PHP_URL_PORT) ? ':' . parse_url($origen, PHP_URL_PORT) : '')
        !== ($_SERVER['HTTP_HOST'] ?? '')) {
        responder(403, ['error' => 'Pedido rechazado: no viene del panel.']);
    }
}

function registrarFallo($ip)
{
    // MariaDB/MySQL aplican las asignaciones en orden: bloqueado_hasta ya ve el nuevo valor de fallos
    q(
        'INSERT INTO intentos_login (ip, fallos, bloqueado_hasta) VALUES (?, 1, NULL)
         ON DUPLICATE KEY UPDATE
           fallos = IF(updated_at < NOW(3) - INTERVAL ' . VENTANA_MIN . ' MINUTE, 1, fallos + 1),
           bloqueado_hasta = IF(fallos >= ' . MAX_FALLOS . ', NOW(3) + INTERVAL ' . BLOQUEO_SEG . ' SECOND, NULL)',
        [$ip]
    );
}

// Si la IP está bloqueada, responde 429 y corta. Devuelve la fila de intentos (o false).
function controlarBloqueo($ip)
{
    $bloq = q(
        'SELECT fallos, CEIL(TIMESTAMPDIFF(MICROSECOND, NOW(3), bloqueado_hasta) / 1000000) AS seg FROM intentos_login WHERE ip = ?',
        [$ip]
    )->fetch();
    if ($bloq && (int) $bloq['seg'] > 0) {
        header('Retry-After: ' . (int) $bloq['seg']);
        responder(429, ['error' => 'Demasiados intentos con contraseña incorrecta. Esperá ' . (int) ceil($bloq['seg'] / 60) . ' minuto(s) y probá de nuevo.']);
    }
    return $bloq;
}

// POST /api/login: verifica la contraseña y abre la sesión. Siempre responde y corta.
function login($clave)
{
    $ip = ipCliente();
    try {
        $bloq = controlarBloqueo($ip);
        $hash = claveGuardada();
        if (!esClaveValida($clave) || !password_verify($clave, $hash)) {
            registrarFallo($ip);
            responder(401, ['error' => 'Contraseña incorrecta.']);
        }
        if ($bloq && (int) $bloq['fallos'] > 0) {
            q('UPDATE intentos_login SET fallos = 0, bloqueado_hasta = NULL WHERE ip = ?', [$ip]);
        }
        iniciarSesion();
        session_regenerate_id(true);   // id nuevo al entrar: evita robo de sesión por fijación
        $_SESSION = ['admin' => true, 'inicio' => time(), 'huella' => huellaClave($hash)];
        session_write_close();
        // Aviso para el panel: sigue con la contraseña inicial de la configuración
        responder(200, ['ok' => true, 'debeCambiar' => hash_equals(ADMIN_CLAVE, $clave)]);
    } catch (Throwable $e) {
        error_log('login: ' . $e->getMessage());
        responder(500, ['error' => 'No se pudo verificar la contraseña.']);
    }
}

// Corta el pedido con 401 si no hay una sesión de admin válida.
// Con $escribir = true deja la sesión abierta para modificarla (cambio de contraseña);
// si no, la cierra enseguida para no frenar los otros pedidos del mismo navegador.
function requireAdmin($escribir = false)
{
    iniciarSesion();
    $ok = !empty($_SESSION['admin']) && time() - (int) ($_SESSION['inicio'] ?? 0) < SESION_MAX_SEG;
    if ($ok) {
        try {
            $ok = hash_equals((string) ($_SESSION['huella'] ?? ''), huellaClave(claveGuardada()));
        } catch (Throwable $e) {
            error_log('auth: ' . $e->getMessage());
            responder(500, ['error' => 'No se pudo verificar la sesión.']);
        }
    }
    if (!$ok) {
        $_SESSION = [];
        session_write_close();
        responder(401, ['error' => 'Tu sesión terminó. Volvé a entrar con la contraseña.']);
    }
    if (!$escribir) session_write_close();
}

// Cierra la sesión actual (POST /api/logout)
function cerrarSesion()
{
    iniciarSesion();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_destroy();
}
