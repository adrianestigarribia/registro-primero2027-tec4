<?php
// Prueba de concurrencia de la inscripción a 1° año.
//
// Manda muchas inscripciones AL MISMO TIEMPO (curl_multi: todas salen juntas, como si 80
// familias tocaran "Enviar" en el mismo instante) y verifica que el control de cupos no
// se rompa:
//   1. Exactamente CUPO (68) quedan "Inscripción Recibida" y el resto "Lista de Espera".
//   2. Ningún número de solicitud ni de orden se repite.
//   3. Los ingresantes son los primeros en el orden de llegada (orden 1..68) y la lista de
//      espera sigue después (69..80), sin huecos.
//   4. El mismo DNI enviado 10 veces a la vez se guarda UNA sola vez y ocupa un solo lugar.
//
// Uso (desde la carpeta del proyecto, con PHP de línea de comandos):
//   php pruebas/concurrencia.php URL [--cantidad=80] [--clave=CONTRASEÑA_DEL_PANEL]
// Ejemplos:
//   php pruebas/concurrencia.php http://localhost/inscripcion-tec4/public/
//   C:\xampp\php\php.exe pruebas\concurrencia.php http://localhost/inscripcion-tec4/public/ --clave=cambiar-esta-clave
//
// ANTES de correrla (ver README, sección "Prueba de concurrencia"):
//   - La base tiene que estar VACÍA. Nunca la corras contra la base real con inscripciones.
//   - En _config.local.php poné 'LIMITE_IP' => 'off' (si no, el antiabuso frena los envíos
//     porque todos salen de tu misma IP). Al terminar, sacalo.
// Con --clave, además de mirar las respuestas, entra al panel y revisa los contadores y el
// orden guardado en la base. Sin --clave, verifica solo con las respuestas.
//
// Sale con código 0 si todo está bien y 1 si algo falló (sirve para automatizarla).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // nunca desde el navegador
if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "Falta la extensión curl de PHP. En XAMPP: en php.ini, sacá el ; de la línea extension=curl\n");
    exit(1);
}

/* ---------- Argumentos ---------- */
$url = null; $cantidad = 80; $clave = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--cantidad=(\d+)$/', $a, $m)) $cantidad = (int) $m[1];
    elseif (preg_match('/^--clave=(.+)$/', $a, $m)) $clave = $m[1];
    elseif ($url === null) $url = $a;
}
if (!$url || $cantidad < 1 || $cantidad > 1000) {
    fwrite(STDERR, "Uso: php pruebas/concurrencia.php URL [--cantidad=80] [--clave=CONTRASEÑA]\n");
    exit(1);
}
$url = rtrim($url, '/') . '/';

$fallas = 0;
function ok($cond, $msg) { global $fallas; echo ($cond ? "  [OK]    " : "  [FALLA] ") . $msg . "\n"; if (!$cond) $fallas++; return $cond; }
function titulo($t) { echo "\n== $t ==\n"; }

/* ---------- Pedidos HTTP ---------- */
$cookies = tempnam(sys_get_temp_dir(), 'insc');   // sesión del panel (solo si hay --clave)

function pedido($metodo, $ruta, $cuerpo = null)
{
    global $url, $cookies;
    $c = curl_init($url . $ruta);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($cuerpo !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    $r = curl_exec($c);
    $codigo = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return [$codigo, json_decode((string) $r, true)];
}

// Inscripción de prueba. DNI desde 59.000.000 (rango que no se cruza con documentos reales de chicos).
function alumno($i, $dni = null)
{
    return [
        'alumnoApellido' => 'Prueba', 'alumnoNombre' => 'Concurrencia', 'dni' => (string) ($dni ?? 59000000 + $i),
        'fechaNacimiento' => '2015-06-15', 'escuela' => 'EP N° 1', 'calle' => 'Calle 14', 'numero' => (string) (1000 + $i),
        'pisoDepto' => '', 'cp' => '1884', 'email' => "prueba$i@ejemplo.com",
        'responsableApellido' => 'Prueba', 'responsableNombre' => 'Adulto', 'privacidad' => true,
        'web' => '', 't' => 30000,   // como si hubieran tardado 30 s en completar (no marca alerta)
    ];
}

// Manda todos los cuerpos a la vez. Devuelve [[código, json], ...] en el mismo orden.
function enParalelo(array $cuerpos)
{
    global $url;
    $mh = curl_multi_init();
    $hs = [];
    foreach ($cuerpos as $b) {
        $c = curl_init($url . 'api/registro');
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($b), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 120,
        ]);
        curl_multi_add_handle($mh, $c);
        $hs[] = $c;
    }
    // Sin límite de conexiones simultáneas: salen todas juntas
    if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 0);
    do {
        $st = curl_multi_exec($mh, $corriendo);
        if ($corriendo) curl_multi_select($mh, 1.0);
    } while ($corriendo && $st === CURLM_OK);
    $res = [];
    foreach ($hs as $c) {
        $res[] = [curl_getinfo($c, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($c), true)];
        curl_multi_remove_handle($mh, $c);
        curl_close($c);
    }
    curl_multi_close($mh);
    return $res;
}

/* ---------- 0. Preparación ---------- */
titulo('Preparación');
[$cod, $ping] = pedido('GET', 'api/ping');
if (!ok($cod === 200 && !empty($ping['base']), "El servidor responde y se conecta a la base ($url)")) {
    echo "  Revisá la URL (tiene que terminar en public/) y que Apache y MySQL estén prendidos.\n";
    exit(1);
}

$cupo = 68;
$panel = false;
if ($clave !== null) {
    [$cod, $j] = pedido('POST', 'api/login', ['clave' => $clave]);
    if (!ok($cod === 200, 'Entró al panel con la contraseña')) exit(1);
    [$cod, $c] = pedido('GET', 'api/cupos');
    $cupo = (int) $c['cupo'];
    $total = $c['ocupadas'] + $c['espera'] + $c['fueraZona'] + $c['anuladas'];
    if (!ok($total === 0, "La base está vacía (tiene $total inscripciones)")) {
        echo "  La prueba necesita la base vacía. Ver README: \"Vaciar la base de prueba\".\n";
        exit(1);
    }
    $panel = true;
} else {
    echo "  (Sin --clave: se asume la base vacía y CUPO = 68. Con --clave se verifica también en el panel.)\n";
}
$esperados = min($cupo, $cantidad);
echo "  Cupo: $cupo · Inscripciones a la vez: $cantidad · Esperado: $esperados ingresantes y " . ($cantidad - $esperados) . " en lista de espera\n";

/* ---------- 1. Todos a la vez ---------- */
titulo("1. $cantidad inscripciones en paralelo");
$t0 = microtime(true);
$res = enParalelo(array_map('alumno', range(0, $cantidad - 1)));
$seg = round(microtime(true) - $t0, 2);
echo "  Tardaron $seg s en total.\n";

$codigos = array_count_values(array_map(function ($r) { return $r[0]; }, $res));
ksort($codigos);
$resumen = implode(', ', array_map(function ($k, $v) { return "$v × HTTP $k"; }, array_keys($codigos), $codigos));
if (!ok(($codigos[201] ?? 0) === $cantidad, "Todas respondieron 201 ($resumen)")) {
    if (isset($codigos[429])) echo "  Hubo 429: el límite por IP sigue activo. Poné 'LIMITE_IP' => 'off' en _config.local.php.\n";
    if (isset($codigos[503])) echo "  Hubo 503: la base tardó demasiado en dar el turno. Ver README, \"Problemas comunes\".\n";
}

$estados = ['ingresante' => 0, 'espera' => 0, 'fuera_zona' => 0];
$numeros = [];
foreach ($res as [$cod, $j]) {
    if (isset($j['constancia']['estado'])) {
        $estados[$j['constancia']['estado']]++;
        $numeros[] = $j['constancia']['numero'];
    }
}
ok($estados['ingresante'] === $esperados, "Inscripción Recibida: {$estados['ingresante']} (esperado $esperados)");
ok($estados['espera'] === $cantidad - $esperados, "Lista de Espera: {$estados['espera']} (esperado " . ($cantidad - $esperados) . ')');
ok(count($numeros) === count(array_unique($numeros)), 'Ningún número de solicitud se repite');

/* ---------- 2. Lo que quedó guardado (solo con --clave) ---------- */
if ($panel) {
    titulo('2. Lo que quedó guardado en la base (vía panel)');
    [$cod, $c] = pedido('GET', 'api/cupos');
    ok($c['ocupadas'] === $esperados && $c['espera'] === $cantidad - $esperados,
        "Contadores del panel: {$c['ocupadas']} ocupadas de {$c['cupo']}, {$c['espera']} en espera");

    [$cod, $l] = pedido('GET', 'api/inscripciones');
    $filas = $l['inscripciones'];
    usort($filas, function ($a, $b) { return $a['orden'] - $b['orden']; });
    $ordenes = array_column($filas, 'orden');
    ok($ordenes === range(1, $cantidad), "Orden de llegada 1..$cantidad, sin repetidos ni huecos");
    $bien = true;
    foreach ($filas as $f) {
        $debe = $f['orden'] <= $cupo ? 'ingresante' : 'espera';
        if ($f['estado'] !== $debe) { $bien = false; echo "    orden {$f['orden']} quedó {$f['estado']} (debía ser $debe)\n"; }
    }
    ok($bien, "Los ingresantes son exactamente los primeros $esperados en llegar; la espera sigue después");
}

/* ---------- 3. Mismo DNI 10 veces a la vez ---------- */
titulo('3. El mismo DNI enviado 10 veces a la vez');
$dni = 59999999;
$res = enParalelo(array_fill(0, 10, alumno(999, $dni)));
$nuevos = 0; $dups = 0; $nums = [];
foreach ($res as [$cod, $j]) {
    if ($cod === 201) $nuevos++;
    if ($cod === 200 && !empty($j['duplicado'])) $dups++;
    if (isset($j['constancia']['numero'])) $nums[] = $j['constancia']['numero'];
}
ok($nuevos === 1, "Se guardó una sola vez ($nuevos respuesta 201)");
ok($dups === 9, "Los otros 9 recibieron la constancia original ($dups respuestas 200 duplicado)");
ok(count(array_unique($nums)) === 1, 'Los 10 ven el mismo número de solicitud');
if ($panel) {
    [$cod, $c] = pedido('GET', 'api/cupos');
    $totalAhora = $c['ocupadas'] + $c['espera'];
    ok($totalAhora === $cantidad + 1, "Ocupó un solo lugar (total en base: $totalAhora, esperado " . ($cantidad + 1) . ')');
    pedido('POST', 'api/logout');
}

@unlink($cookies);
echo "\n" . ($fallas === 0
    ? "RESULTADO: TODO OK. El control de cupos resiste pedidos simultáneos.\n"
    : "RESULTADO: $fallas verificación(es) fallaron. Revisá los mensajes de arriba.\n");
echo "Acordate: sacá 'LIMITE_IP' => 'off' de _config.local.php y vaciá la base antes de usarla de verdad.\n";
exit($fallas === 0 ? 0 : 1);
