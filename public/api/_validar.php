<?php
// Validación del formulario de inscripción (la misma regla que aplica form.js en el navegador,
// pero acá es la que vale: el navegador se puede saltear).
//
// Regla general: lo que no cumple el formato se RECHAZA (400), no se "arregla" en silencio.
// - Nombres y apellidos: letras (con tildes, ñ y ü), espacios simples, y apóstrofo o guion
//   entre letras (D'Angelo, Pérez-Gómez). Nada de números, <, >, ;, emojis ni otros alfabetos.
// - Escuela y calle: letras, números y signos comunes de una dirección (. , ° º ' " - / # ( )).
//   Siempre empiezan con letra o número, así nunca arrancan con = + - @ (fórmulas de Excel).
// - DNI: 7 u 8 números (se aceptan puntos o espacios: 50.123.456).
// - Fecha de nacimiento: fecha real, con edad para 1° año (ver _config.php).
// - CP: 4 cifras o formato CPA (B1884ABC). Se guarda solo el número (1884).
// Además, la base se consulta siempre con parámetros (?), nunca pegando texto al SQL,
// y el navegador muestra los datos con textContent, nunca como HTML.

// Largos máximos (iguales a las columnas de schema.sql y a los maxlength de index.html)
const MAX_NOMBRE = 80;
const MAX_ESCUELA = 120;
const MAX_CALLE = 100;
const MAX_NUMERO = 10;
const MAX_PISO = 20;
const MAX_EMAIL = 120;

// Letras latinas (incluye á é í ó ú ñ ü ç à ...)
const LETRAS = 'A-Za-zÀ-ÖØ-öø-ÿ';
const RE_NOMBRE = "/^[" . LETRAS . "]+(?:['’-][" . LETRAS . "]+)*(?: [" . LETRAS . "]+(?:['’-][" . LETRAS . "]+)*)*$/u";
const RE_TEXTO_DIRECCION = "/^[" . LETRAS . "0-9][" . LETRAS . "0-9 .,°º'’\"\\-\\/#()]*$/u";
const RE_NUMERO_CALLE = '/^(?:\d{1,6}(?: ?(?:bis|[A-Za-z]))?|S\/N)$/iu';
const RE_PISO = '/^[A-Za-z0-9][A-Za-z0-9 .°º\-\/]*$/u';

// DNI que tenga sentido hoy (mismo criterio que registro-muestra).
// - Menos de 2.000.000: documentos de hace más de 100 años.
// - Entre 65.000.000 y 89.999.999: números que todavía no se emitieron.
// - 90.000.000 en adelante: serie de DNI para extranjeros/residentes, se permite.
const DNI_MIN_VALIDO = 2000000;
const DNI_MAX_ARGENTINO = 64999999;
const DNI_EXTRANJERO_DESDE = 90000000;

function dniPlausible($n)
{
    if ($n < DNI_MIN_VALIDO) return false;
    if ($n > DNI_MAX_ARGENTINO && $n < DNI_EXTRANJERO_DESDE) return false;
    return true;
}

function texto($v)
{
    return is_scalar($v) && !is_bool($v) ? (string) $v : '';
}

// Junta espacios repetidos y recorta. Si no es texto válido UTF-8, devuelve ''.
// No corta al largo máximo: lo que se pasa se rechaza después con un mensaje claro.
function limpiar($v)
{
    if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) return '';
    if (class_exists('Normalizer')) $v = Normalizer::normalize($v, Normalizer::FORM_C) ?: $v;
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, 1000);
}

// Revisa un campo de texto obligatorio. Devuelve el mensaje de error o null.
function errorTexto($valor, $max, $regex, $faltaMsg, $formatoMsg, $min = 2)
{
    if (mb_strlen($valor) < $min) return $faltaMsg;
    if (mb_strlen($valor) > $max) return "Es demasiado largo (máximo $max caracteres).";
    if (!preg_match($regex, $valor)) return $formatoMsg;
    return null;
}

function errorNombre($valor, $faltaMsg)
{
    if (mb_strlen($valor) >= 2 && preg_match('/\d/', $valor)) return 'No puede tener números.';
    return errorTexto($valor, MAX_NOMBRE, RE_NOMBRE, $faltaMsg, 'Solo letras y espacios (se aceptan \' y - entre letras).');
}

// "B1884ABC", "b 1884", "1884" o 1884 -> "1884". Cualquier otra cosa -> ''.
function normalizarCp($v)
{
    $s = strtoupper(preg_replace('/\s+/', '', texto($v)));
    return preg_match('/^[A-Z]?(\d{4})(?:[A-Z]{3})?$/', $s, $m) ? $m[1] : '';
}

// Rango de nacimiento permitido: edad entre EDAD_MIN y EDAD_MAX al 30/06 del ciclo lectivo.
// Devuelve ['desde' => 'AAAA-MM-DD', 'hasta' => 'AAAA-MM-DD'].
function rangoNacimiento()
{
    return [
        'desde' => sprintf('%04d-07-01', CICLO_LECTIVO - EDAD_MAX - 1),
        'hasta' => sprintf('%04d-06-30', CICLO_LECTIVO - EDAD_MIN),
    ];
}

function errorFecha($f)
{
    if ($f === '') return 'Elegí la fecha de nacimiento.';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return 'La fecha no es válida.';
    }
    $r = rangoNacimiento();
    // Las fechas AAAA-MM-DD se pueden comparar como texto
    if ($f < $r['desde'] || $f > $r['hasta']) {
        return 'Para 1° año ' . CICLO_LECTIVO . ' el alumno tiene que tener entre ' . EDAD_MIN . ' y ' . EDAD_MAX
            . ' años al 30/06/' . CICLO_LECTIVO . '. Revisá el año de nacimiento.';
    }
    return null;
}

// Valida todo el formulario. Devuelve [datos limpios, errores por campo].
// Las claves de $errores son los name de los campos de index.html.
function validarInscripcion($b)
{
    $dniTexto = mb_substr(trim(texto($b['dni'] ?? null)), 0, 20);
    $piso = limpiar($b['pisoDepto'] ?? null);
    $numero = limpiar($b['numero'] ?? null);
    $d = [
        'alumnoApellido' => limpiar($b['alumnoApellido'] ?? null),
        'alumnoNombre' => limpiar($b['alumnoNombre'] ?? null),
        'dni' => preg_match('/^[\d. ]+$/', $dniTexto) ? preg_replace('/[. ]/', '', $dniTexto) : '',
        'fechaNacimiento' => is_string($b['fechaNacimiento'] ?? null) ? trim($b['fechaNacimiento']) : '',
        'escuela' => limpiar($b['escuela'] ?? null),
        'calle' => limpiar($b['calle'] ?? null),
        'numero' => strcasecmp($numero, 's/n') === 0 ? 'S/N' : $numero,
        'pisoDepto' => $piso === '' ? null : $piso,
        'cpTexto' => limpiar(texto($b['cp'] ?? null)),
        'cp' => normalizarCp($b['cp'] ?? null),
        'email' => mb_strtolower(limpiar($b['email'] ?? null)),
        'responsableApellido' => limpiar($b['responsableApellido'] ?? null),
        'responsableNombre' => limpiar($b['responsableNombre'] ?? null),
    ];
    $e = [];

    if ($x = errorNombre($d['alumnoApellido'], 'Escribí el apellido del alumno.')) $e['alumnoApellido'] = $x;
    if ($x = errorNombre($d['alumnoNombre'], 'Escribí el nombre del alumno.')) $e['alumnoNombre'] = $x;

    if (!preg_match('/^\d{7,8}$/', $d['dni'])) {
        $e['dni'] = 'El DNI tiene que tener 7 u 8 números, sin letras ni símbolos.';
    } elseif (!dniPlausible((int) $d['dni'])) {
        $e['dni'] = 'Revisá el DNI: ese número no corresponde a un documento válido.';
    }

    if ($x = errorFecha($d['fechaNacimiento'])) $e['fechaNacimiento'] = $x;

    if ($x = errorTexto($d['escuela'], MAX_ESCUELA, RE_TEXTO_DIRECCION,
        'Escribí la escuela primaria.', 'Usá letras, números y signos comunes (. , ° - /).')) $e['escuela'] = $x;
    if ($x = errorTexto($d['calle'], MAX_CALLE, RE_TEXTO_DIRECCION,
        'Escribí la calle.', 'Usá letras, números y signos comunes (. , ° - /).')) $e['calle'] = $x;
    if ($x = errorTexto($d['numero'], MAX_NUMERO, RE_NUMERO_CALLE,
        'Escribí el número (o S/N si no tiene).', 'Solo el número, por ejemplo 1234, 1234 bis o S/N.', 1)) $e['numero'] = $x;
    if ($d['pisoDepto'] !== null && ($x = errorTexto($d['pisoDepto'], MAX_PISO, RE_PISO,
        '', 'Solo letras, números, . ° - y /. Por ejemplo: 2° B.', 1))) $e['pisoDepto'] = $x;

    if ($d['cpTexto'] === '') $e['cp'] = 'Escribí el código postal.';
    elseif ($d['cp'] === '') $e['cp'] = 'El código postal son 4 números (por ejemplo 1884) o el formato B1884ABC.';

    if ($d['email'] === '') $e['email'] = 'Escribí un email de contacto.';
    elseif (strlen($d['email']) > MAX_EMAIL) $e['email'] = 'El email es demasiado largo (máximo ' . MAX_EMAIL . ' caracteres).';
    elseif (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || !preg_match('/\.[a-z]{2,}$/', $d['email'])) {
        $e['email'] = 'Revisá el email: tiene que ser como nombre@ejemplo.com.';
    }

    if ($x = errorNombre($d['responsableApellido'], 'Escribí el apellido del adulto responsable.')) $e['responsableApellido'] = $x;
    if ($x = errorNombre($d['responsableNombre'], 'Escribí el nombre del adulto responsable.')) $e['responsableNombre'] = $x;

    // Ley 25.326: el aviso de privacidad se lee y se acepta antes de enviar
    if (($b['privacidad'] ?? null) !== true) $e['privacidad'] = 'Para enviar, leé y aceptá el aviso de privacidad.';

    unset($d['cpTexto']);
    return [$d, $e];
}

// Id de un registro: entero positivo (número o texto de solo dígitos). Si no, null.
function idDe($v)
{
    if (is_int($v)) $n = $v;
    elseif (is_string($v) && preg_match('/^\d{1,10}$/', $v)) $n = (int) $v;
    else return null;
    return $n > 0 && $n <= 4294967295 ? $n : null;
}
