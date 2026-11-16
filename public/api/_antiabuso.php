<?php
// Defensas del formulario público contra envíos masivos (scripts, bots).
//
// Están pensadas para no frenar a los padres reales:
// - Campo trampa: un campo oculto ("web") que una persona nunca ve ni completa. Si viene con
//   algo, es un bot: se le contesta como si se hubiera inscripto, pero no se guarda nada.
// - Tiempo de llenado: el formulario manda cuántos ms pasaron desde que se abrió ("t"). Si es
//   menos de TIEMPO_MIN_MS, o no viene, se guarda igual pero queda con una alerta para que la
//   escuela lo revise en el panel (el autocompletar del celular puede llenar muy rápido, por eso
//   no se bloquea: bloquear le quitaría la vacante a una familia real).
// - Límite por IP: LIMITE_MINUTO envíos por minuto y LIMITE_HORA por hora. Es generoso porque
//   varias familias pueden compartir conexión (por ejemplo, el Wi-Fi de la escuela).
// Si el control falla (por ejemplo, falta la tabla), la inscripción pasa igual. El error queda en el log.
require_once __DIR__ . '/_db.php';

const LIMITE_MINUTO = 20;
const LIMITE_HORA = 200;
const TIEMPO_MIN_MS = 5000;

// true si el campo trampa vino completado
function esBot($b)
{
    $web = $b['web'] ?? '';
    return !is_string($web) || $web !== '';
}

// Motivo de la alerta si el envío fue demasiado rápido o no vino del formulario, o null
function motivoTiempo($b)
{
    $t = $b['t'] ?? null;
    if (!is_int($t)) return 'Enviado sin usar el formulario';
    if ($t < TIEMPO_MIN_MS) return 'Formulario completado en menos de ' . (TIEMPO_MIN_MS / 1000) . ' segundos';
    return null;
}

// Cuenta este pedido para la IP y responde 429 si pasó el límite.
function controlarLimite()
{
    if (strtolower(LIMITE_IP) === 'off') return;
    $ip = ipCliente();
    try {
        // Las asignaciones se aplican en orden: cada contador se reinicia mirando el inicio
        // de su ventana ANTES de que se actualice en la línea siguiente.
        q(
            'INSERT INTO limite_registro (ip, min_inicio, min_n, hora_inicio, hora_n) VALUES (?, NOW(), 1, NOW(), 1)
             ON DUPLICATE KEY UPDATE
               min_n = IF(min_inicio <= NOW() - INTERVAL 1 MINUTE, 1, min_n + 1),
               min_inicio = IF(min_inicio <= NOW() - INTERVAL 1 MINUTE, NOW(), min_inicio),
               hora_n = IF(hora_inicio <= NOW() - INTERVAL 1 HOUR, 1, hora_n + 1),
               hora_inicio = IF(hora_inicio <= NOW() - INTERVAL 1 HOUR, NOW(), hora_inicio)',
            [$ip]
        );
        $c = q(
            'SELECT min_n, hora_n,
                    TIMESTAMPDIFF(SECOND, NOW(), min_inicio + INTERVAL 1 MINUTE) AS seg_min,
                    TIMESTAMPDIFF(SECOND, NOW(), hora_inicio + INTERVAL 1 HOUR) AS seg_hora
               FROM limite_registro WHERE ip = ?',
            [$ip]
        )->fetch();
    } catch (PDOException $e) {
        error_log('antiabuso: ' . $e->getMessage());
        return;
    }
    if (!$c) return;
    $seg = null;
    if ((int) $c['hora_n'] > LIMITE_HORA) $seg = max(1, (int) $c['seg_hora']);
    elseif ((int) $c['min_n'] > LIMITE_MINUTO) $seg = max(1, (int) $c['seg_min']);
    if ($seg === null) return;
    header('Retry-After: ' . $seg);
    responder(429, ['error' => 'Hay demasiados envíos seguidos desde esta conexión. Esperá un minuto y tocá Enviar otra vez.']);
}
