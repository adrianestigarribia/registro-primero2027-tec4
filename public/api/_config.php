<?php
// Configuración de la app. ES EL ÚNICO LUGAR donde se cambian el cupo, los códigos
// postales de Berazategui y el rango de edad.
//
// Este archivo trae los valores para probar en la compu con XAMPP y NO tiene
// contraseñas reales. Los datos del servidor de la escuela van en _config.local.php
// (copiá _config.local.example.php con ese nombre y completalo). Ese archivo no se
// sube a GitHub (.gitignore) ni se puede abrir desde el navegador (.htaccess).
//
// También se pueden pasar como variables de entorno del servidor (DB_HOST, DB_PASSWORD,
// CUPO, etc.), que tienen prioridad sobre los dos archivos.

$cfg = [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => 3306,
    'DB_USER' => 'inscripcion_user',
    'DB_PASSWORD' => 'test1234',          // la de XAMPP del README; en el servidor va otra
    'DB_NAME' => 'inscripcion_tec4',

    // Contraseña inicial del panel (6 a 72 caracteres). Después se cambia desde el panel,
    // y desde ese momento esta ya no sirve (vale la guardada cifrada en la base).
    'ADMIN_CLAVE' => 'cambiar-esta-clave',

    // Vacantes de 1° año. Las primeras CUPO inscripciones de Berazategui son "Inscripción Recibida";
    // de ahí en adelante, "Lista de Espera".
    'CUPO' => 68,

    // Códigos postales del partido de Berazategui (4 cifras, separados por coma).
    // 1884 Berazategui · 1885 Guillermo E. Hudson · 1886 Ranelagh
    // 1890 Juan María Gutiérrez · 1893 Centro Agrícola El Pato · 1894 Pereyra
    // CONFIRMAR CON CORREO ARGENTINO antes de abrir la inscripción (en especial los CP de
    // Plátanos y Sourigues, que las fuentes consultadas no dan igual).
    // Un CP que no está en la lista queda "Fuera de zona" (se guarda, pero no ocupa vacante).
    'CP_BERAZATEGUI' => '1884,1885,1886,1890,1893,1894',

    // Año en que el alumno empieza 1° año, y edad que puede tener al 30 de junio de ese año.
    // Con 2027, 11 y 15: nacidos entre el 01/07/2011 y el 30/06/2016.
    'CICLO_LECTIVO' => 2027,
    'EDAD_MIN' => 11,
    'EDAD_MAX' => 15,

    // Límite de envíos por IP del formulario público: 'on' u 'off'.
    // 'off' SOLO para la prueba de concurrencia en tu compu (pruebas/concurrencia.php).
    'LIMITE_IP' => 'on',
];

// Valores que son números enteros (el resto se define como texto)
const CFG_ENTEROS = ['DB_PORT', 'CUPO', 'CICLO_LECTIVO', 'EDAD_MIN', 'EDAD_MAX'];

$local = __DIR__ . '/_config.local.php';
if (is_file($local)) {
    $extra = require $local;
    if (is_array($extra)) $cfg = array_merge($cfg, array_intersect_key($extra, $cfg));
}

foreach ($cfg as $clave => $valor) {
    $env = getenv($clave);
    $valor = $env !== false && $env !== '' ? $env : $valor;
    define($clave, in_array($clave, CFG_ENTEROS, true) ? (int) $valor : (string) $valor);
}

// Lista de CP de Berazategui como array (solo entradas de 4 cifras).
function cpsBerazategui()
{
    static $lista = null;
    if ($lista === null) {
        $lista = array_values(array_filter(
            array_map('trim', explode(',', CP_BERAZATEGUI)),
            function ($cp) { return preg_match('/^\d{4}$/', $cp) === 1; }
        ));
    }
    return $lista;
}

// true si el CP (ya normalizado a 4 cifras) es del partido de Berazategui
function cpEsDeBerazategui($cp)
{
    return in_array($cp, cpsBerazategui(), true);
}
