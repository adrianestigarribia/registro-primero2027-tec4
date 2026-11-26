<?php
// Lógica de vacantes: asignar el estado de cada inscripción y promover la lista de espera.
//
// LA REGLA DE ORO: todo lo que cuenta o cambia vacantes pasa por tomarCandado() dentro de
// una transacción. El candado es la única fila de control_cupos, bloqueada con
// SELECT ... FOR UPDATE. Mientras un pedido lo tiene, los demás esperan su turno en esa
// línea; recién cuando el primero hace COMMIT, entra el siguiente y ya ve lo que guardó.
// Así, aunque 80 familias toquen "Enviar" en el mismo milisegundo, se atienden de a una:
// nunca dos pedidos ven "queda 1 vacante" a la vez.
//
// Siempre se bloquea en el mismo orden (primero control_cupos, después las inscripciones),
// así no hay bloqueos mutuos (deadlocks) entre inscripciones y anulaciones.
require_once __DIR__ . '/_db.php';

const ESTADO_TEXTO = [
    'ingresante' => 'Inscripción Recibida',
    'espera' => 'Lista de Espera',
    'fuera_zona' => 'Fuera de zona',
];

// Toma el candado y devuelve el último número de orden usado.
// SOLO se puede llamar dentro de transaccion().
function tomarCandado()
{
    $u = q('SELECT ultimo_orden FROM control_cupos WHERE id = 1 FOR UPDATE')->fetchColumn();
    if ($u === false) {
        // Sin la fila no hay nada que bloquear: es más seguro no aceptar inscripciones
        throw new RuntimeException('Falta la fila de control_cupos (ejecutá schema.sql).');
    }
    return (int) $u;
}

// Vacantes ocupadas ahora. Lectura con bloqueo compartido (LOCK IN SHARE MODE): lee siempre
// la última versión confirmada, sin depender del nivel de aislamiento. Con el candado tomado.
function contarIngresantes()
{
    return (int) q(
        "SELECT COUNT(*) FROM inscripciones WHERE anulada = 0 AND estado = 'ingresante' LOCK IN SHARE MODE"
    )->fetchColumn();
}

// Si hay vacantes libres, las ocupa con los primeros de la lista de espera (por orden de llegada).
// Pasa después de una anulación, o si se aumentó CUPO en la configuración.
// Devuelve las inscripciones promovidas. Con el candado tomado.
function completarVacantes()
{
    $faltan = CUPO - contarIngresantes();
    if ($faltan <= 0) return [];
    $ids = q(
        "SELECT id FROM inscripciones WHERE anulada = 0 AND estado = 'espera'
          ORDER BY orden LIMIT " . (int) $faltan . ' FOR UPDATE'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        q("UPDATE inscripciones SET estado = 'ingresante', promovida_at = NOW(3) WHERE id = ?", [$id]);
    }
    return array_map(function ($id) { return leerInscripcion((int) $id); }, $ids);
}

// Estado que le toca a una inscripción nueva. Con el candado tomado.
function estadoPara($cp)
{
    if (!cpEsDeBerazategui($cp)) return 'fuera_zona';
    // Primero se respeta a los que ya esperan: si quedó una vacante libre, es de ellos
    completarVacantes();
    return contarIngresantes() < CUPO ? 'ingresante' : 'espera';
}

// Guarda una inscripción (todo en una sola transacción con el candado).
// Devuelve:
//   ['resultado' => 'nueva', 'fila' => ...]       se guardó (nueva o reactivando una anulada)
//   ['resultado' => 'duplicado', 'fila' => ...]   ese DNI ya estaba, con la misma fecha de nacimiento
//   ['resultado' => 'dni_ocupado']                ese DNI ya estaba, con OTRA fecha de nacimiento
function inscribir($d, $alerta)
{
    return transaccion(function () use ($d, $alerta) {
        $ultimo = tomarCandado();

        $ex = q(
            "SELECT id, DATE_FORMAT(fecha_nacimiento, '%Y-%m-%d') AS fecha, anulada FROM inscripciones WHERE dni = ? FOR UPDATE",
            [$d['dni']]
        )->fetch();

        if ($ex && !(int) $ex['anulada']) {
            // Mismo DNI ya inscripto: no se toca nada ni se ocupa otra vacante.
            // La constancia original se muestra solo si también coincide la fecha de nacimiento,
            // así nadie puede ver los datos de un menor sabiendo solamente su DNI.
            if ($ex['fecha'] !== $d['fechaNacimiento']) return ['resultado' => 'dni_ocupado'];
            return ['resultado' => 'duplicado', 'fila' => leerInscripcion((int) $ex['id'])];
        }

        $estado = estadoPara($d['cp']);
        $orden = $ultimo + 1;
        $valores = [
            $orden, $d['alumnoApellido'], $d['alumnoNombre'], $d['fechaNacimiento'], $d['escuela'],
            $d['calle'], $d['numero'], $d['pisoDepto'], $d['cp'], $d['email'], $d['telefono'],
            $d['responsableApellido'], $d['responsableNombre'], $estado, $alerta,
        ];

        if ($ex) {
            // El DNI tenía una inscripción anulada: se reutiliza la fila (el DNI es único)
            // con los datos nuevos, como si llegara recién (orden nuevo, al final de la fila).
            q(
                'UPDATE inscripciones
                    SET orden = ?, alumno_apellido = ?, alumno_nombre = ?, fecha_nacimiento = ?, escuela_primaria = ?,
                        calle = ?, numero = ?, piso_depto = ?, cp = ?, email = ?, telefono = ?,
                        responsable_apellido = ?, responsable_nombre = ?, estado = ?, alerta = ?,
                        promovida_at = NULL, anulada = 0, anulada_at = NULL, created_at = NOW(3)
                  WHERE id = ?',
                array_merge($valores, [$ex['id']])
            );
            $id = (int) $ex['id'];
        } else {
            q(
                'INSERT INTO inscripciones
                   (orden, alumno_apellido, alumno_nombre, fecha_nacimiento, escuela_primaria,
                    calle, numero, piso_depto, cp, email, telefono,
                    responsable_apellido, responsable_nombre, estado, alerta, dni)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($valores, [$d['dni']])
            );
            $id = (int) db()->lastInsertId();
        }

        q('UPDATE control_cupos SET ultimo_orden = ? WHERE id = 1', [$orden]);
        return ['resultado' => 'nueva', 'fila' => leerInscripcion($id)];
    });
}

// Anula una inscripción (borrado lógico) y, si liberó una vacante, promueve al primero
// de la lista de espera. Todo en una transacción con el candado.
// Devuelve ['anulada' => fila, 'promovidas' => [filas]].
function anular($id)
{
    return transaccion(function () use ($id) {
        tomarCandado();
        $f = q('SELECT id, anulada FROM inscripciones WHERE id = ? FOR UPDATE', [$id])->fetch();
        if (!$f) throw new HttpError(404, 'No existe esa inscripción.');
        if ((int) $f['anulada']) throw new HttpError(409, 'Esa inscripción ya estaba anulada.');

        q('UPDATE inscripciones SET anulada = 1, anulada_at = NOW(3) WHERE id = ?', [$id]);
        $promovidas = completarVacantes();
        return ['anulada' => leerInscripcion($id), 'promovidas' => $promovidas];
    });
}

// Contadores para el panel (sin anuladas)
function contadores()
{
    $c = q(
        "SELECT COALESCE(SUM(anulada = 0 AND estado = 'ingresante'), 0) AS ocupadas,
                COALESCE(SUM(anulada = 0 AND estado = 'espera'), 0)     AS espera,
                COALESCE(SUM(anulada = 0 AND estado = 'fuera_zona'), 0) AS fuera_zona,
                COALESCE(SUM(anulada = 1), 0)                           AS anuladas
           FROM inscripciones"
    )->fetch();
    $ocupadas = (int) $c['ocupadas'];
    return [
        'cupo' => CUPO,
        'ocupadas' => $ocupadas,
        'libres' => max(0, CUPO - $ocupadas),
        'espera' => (int) $c['espera'],
        'fueraZona' => (int) $c['fuera_zona'],
        'anuladas' => (int) $c['anuladas'],
    ];
}

// Lo que ve el tutor en la pantalla de confirmación (nada de alertas ni datos internos)
function constancia($fila)
{
    return [
        'numero' => $fila['id'],
        'estado' => $fila['estado'],
        'estadoTexto' => ESTADO_TEXTO[$fila['estado']],
        'alumno' => $fila['alumnoApellido'] . ', ' . $fila['alumnoNombre'],
        'dni' => $fila['dni'],
        'fechaMs' => $fila['createdMs'],
    ];
}
