<?php
// POST /api/registro  ->  inscripción desde el formulario público
//
// Respuestas:
//   201 { ok, duplicado: false, constancia }   inscripción nueva
//   200 { ok, duplicado: true,  constancia }   ese DNI ya estaba (misma fecha de nacimiento):
//                                              se muestra la constancia original, sin ocupar otra vacante
//   400 { error, errores: { campo: mensaje } } datos inválidos
//   409 { error, errores }                     ese DNI ya está con otra fecha de nacimiento
//   429 / 503 / 500                            límite por IP, mucha gente a la vez, error del servidor
require_once __DIR__ . '/_validar.php';
require_once __DIR__ . '/_antiabuso.php';
require_once __DIR__ . '/_cupos.php';

soloMetodo('POST');

$b = cuerpo();
controlarLimite();

// Bot que completó el campo trampa: se le contesta como si se hubiera inscripto, sin guardar nada
if (esBot($b)) responder(201, ['ok' => true, 'duplicado' => false, 'constancia' => null]);

list($d, $errores) = validarInscripcion($b);
if ($errores) responder(400, ['error' => 'Revisá los datos marcados.', 'errores' => $errores]);

try {
    $r = inscribir($d, motivoTiempo($b));

    if ($r['resultado'] === 'dni_ocupado') {
        responder(409, [
            'error' => 'Ya hay una solicitud con ese DNI, pero con otra fecha de nacimiento. Revisá la fecha; si está bien, comunicate con la escuela.',
            'errores' => ['dni' => 'Ya hay una solicitud con este DNI.', 'fechaNacimiento' => 'No coincide con la de la solicitud ya enviada.'],
        ]);
    }
    $dup = $r['resultado'] === 'duplicado';
    responder($dup ? 200 : 201, ['ok' => true, 'duplicado' => $dup, 'constancia' => constancia($r['fila'])]);
} catch (Throwable $e) {
    if ($e instanceof HttpError) responder($e->http, ['error' => $e->getMessage()]);
    // No debería pasar (el candado ya ordena los pedidos), pero si la clave única del DNI frena
    // un segundo envío simultáneo, se le pide que toque Enviar otra vez y verá su constancia.
    if (esDuplicado($e)) responder(409, ['error' => 'Esta solicitud se acaba de enviar. Tocá Enviar otra vez para ver la constancia.']);
    if (esReintentable($e)) {
        header('Retry-After: 5');
        responder(503, ['error' => 'Hay muchas familias enviando al mismo tiempo. Esperá unos segundos y tocá Enviar otra vez: tus datos siguen cargados.']);
    }
    error_log('registro: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo guardar la inscripción. Probá de nuevo en unos segundos.']);
}
