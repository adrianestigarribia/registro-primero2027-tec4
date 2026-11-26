<?php
// Conexión a MariaDB, respuestas JSON y utilidades comunes de la API.
require_once __DIR__ . '/_config.php';

// Hora de Argentina para lo que arma PHP (por ejemplo, el nombre del archivo CSV),
// sin depender de cómo esté configurado el php.ini del servidor.
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Error con código HTTP: se responde tal cual al navegador.
class HttpError extends Exception
{
    public $http;
    public function __construct($http, $mensaje)
    {
        parent::__construct($mensaje);
        $this->http = $http;
    }
}

function db()
{
    static $pdo = null;
    // Si ya no se pudo conectar en este pedido, se avisa enseguida en vez de volver a esperar
    static $fallo = null;
    if ($fallo) throw $fallo;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASSWORD,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,     // consultas preparadas de verdad
                    PDO::MYSQL_ATTR_FOUND_ROWS => true,      // filas encontradas, no solo las que cambiaron
                    PDO::ATTR_TIMEOUT => 10,
                ]
            );
            // READ COMMITTED: cada consulta ve lo último que se confirmó (COMMIT).
            // Junto con el candado de control_cupos, garantiza que el conteo de vacantes
            // incluya todo lo que guardaron los pedidos que esperaron antes que este.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            // Horas de Argentina en las fechas que arma la base (exportación CSV)
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (PDOException $e) {
            $fallo = $e;
            throw $e;
        }
    }
    return $pdo;
}

// Ejecuta una consulta con parámetros (?) y devuelve el statement. Nunca se pega texto al SQL.
function q($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// true si el error es un bloqueo mutuo (1213) o una espera de bloqueo vencida (1205):
// se puede reintentar la transacción entera sin riesgo, porque se deshizo completa.
function esReintentable($e)
{
    return $e instanceof PDOException && isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1205, 1213], true);
}

// Ejecuta $fn dentro de una transacción: si algo falla, no se guarda nada.
// Si choca con otra transacción (1213/1205), la reintenta hasta $intentos veces.
function transaccion($fn, $intentos = 3)
{
    $pdo = db();
    for ($i = 1; ; $i++) {
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($i < $intentos && esReintentable($e)) {
                usleep(random_int(20000, 80000)); // espera corta al azar para no volver a chocar
                continue;
            }
            throw $e;
        }
    }
}

// true si el error es una clave única repetida (por ejemplo, el DNI)
function esDuplicado($e)
{
    return $e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
}

function responder($status, $data)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Cuerpo JSON del pedido como array. Si no es un objeto JSON válido ({...}), responde 400.
// Exige Content-Type: application/json (si no, 415). Así una página ajena no puede mandar
// pedidos desde el navegador de otra persona con un <form> común (text/plain o form-data).
function cuerpo()
{
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        responder(415, ['error' => 'El pedido tiene que ser JSON (Content-Type: application/json).']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $obj = json_decode($raw === false ? '' : $raw);
    if (!($obj instanceof stdClass)) responder(400, ['error' => 'El pedido tiene que ser un objeto JSON válido.']);
    return json_decode($raw, true) ?: [];
}

// Corta el pedido si el método HTTP no es el esperado.
function soloMetodo($metodo)
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === $metodo) return;
    header('Allow: ' . $metodo);
    responder(405, ['error' => 'Método no permitido.']);
}

// REMOTE_ADDR es la IP que ve el servidor. No se usa X-Forwarded-For porque el cliente la puede inventar.
function ipCliente()
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'), 0, 45);
}

// ---------------------------------------------------------------------------
// Lectura de inscripciones
// ---------------------------------------------------------------------------
const SELECT_INSCRIPCION = "
  SELECT id, orden, alumno_apellido, alumno_nombre, dni,
         DATE_FORMAT(fecha_nacimiento, '%Y-%m-%d') AS fecha_nacimiento,
         escuela_primaria, calle, numero, piso_depto, cp, email, telefono,
         responsable_apellido, responsable_nombre, estado, alerta, anulada,
         FLOOR(UNIX_TIMESTAMP(created_at) * 1000)   AS created_ms,
         FLOOR(UNIX_TIMESTAMP(promovida_at) * 1000) AS promovida_ms,
         FLOOR(UNIX_TIMESTAMP(anulada_at) * 1000)   AS anulada_ms
  FROM inscripciones";

// Fila de la base -> objeto para el panel (nombres en camelCase, tipos correctos)
function aInscripcion($r)
{
    $ms = function ($v) { return $v === null ? null : (int) $v; };
    return [
        'id' => (int) $r['id'],
        'orden' => (int) $r['orden'],
        'alumnoApellido' => $r['alumno_apellido'],
        'alumnoNombre' => $r['alumno_nombre'],
        'dni' => $r['dni'],
        'fechaNacimiento' => $r['fecha_nacimiento'],
        'escuela' => $r['escuela_primaria'],
        'calle' => $r['calle'],
        'numero' => $r['numero'],
        'pisoDepto' => $r['piso_depto'],
        'cp' => $r['cp'],
        'email' => $r['email'],
        'telefono' => $r['telefono'],      // null en inscripciones anteriores al campo
        'responsableApellido' => $r['responsable_apellido'],
        'responsableNombre' => $r['responsable_nombre'],
        'estado' => $r['estado'],
        'alerta' => $r['alerta'] ?: null,
        'anulada' => (bool) (int) $r['anulada'],
        'createdMs' => $ms($r['created_ms']),
        'promovidaMs' => $ms($r['promovida_ms']),
        'anuladaMs' => $ms($r['anulada_ms']),
    ];
}

function leerInscripcion($id)
{
    $r = q(SELECT_INSCRIPCION . ' WHERE id = ?', [$id])->fetch();
    return $r ? aInscripcion($r) : null;
}
