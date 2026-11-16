-- Base de datos de la Inscripción a 1° año - E.E.S.T. N°4 Berazategui
-- Instalación desde cero (versión 1). Ejecutar como root:
--   source C:/xampp/htdocs/inscripcion-tec4/schema.sql
-- Si en el futuro cambia la estructura, NO se vuelve a ejecutar este archivo sobre una
-- base con datos: se agrega un migracion-v2.sql, v3, etc. (como en registro-muestra).
--
-- Reglas de la base:
-- - Nunca se borran filas (DELETE): una inscripción anulada queda marcada (anulada = 1).
--   Por eso el usuario de la app no tiene permiso DELETE.
-- - Todo en utf8mb4_unicode_ci: acepta tildes y ñ, y compara sin distinguir mayúsculas.
-- - Las fechas son TIMESTAMP (se guardan en UTC y se muestran en la zona horaria de cada
--   conexión). Así, si alguien corrige un dato a mano desde la consola con otra zona horaria,
--   la hora de "última modificación" no queda corrida y el panel no se confunde.

CREATE DATABASE IF NOT EXISTS inscripcion_tec4
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE inscripcion_tec4;

-- ---------------------------------------------------------------------------
-- Inscripciones (una fila por alumno; el DNI no se puede repetir)
-- ---------------------------------------------------------------------------
-- id     = número de solicitud que ve el tutor en la constancia.
-- orden  = orden de llegada. Lo asigna la API DENTRO del bloqueo de control_cupos
--          (ultimo_orden + 1), así nunca hay dos con el mismo lugar en la fila, aunque
--          lleguen en el mismo milisegundo. La lista de espera se promueve por este orden.
-- estado:
--   'ingresante' -> una de las vacantes (el tutor ve "Inscripción Recibida")
--   'espera'     -> Lista de Espera (llegó cuando ya estaban las vacantes ocupadas)
--   'fuera_zona' -> el CP no es de Berazategui: se guarda, pero no ocupa vacante
--                   ni entra en la lista de espera
-- anulada: borrado lógico. Una anulada no cuenta para nada. Si ese mismo DNI se vuelve
--   a inscribir, se reutiliza la fila con los datos nuevos y un orden nuevo (al final).
-- alerta: marca del antiabuso para el panel ("Formulario completado en menos de 3 s", etc.).
--   El tutor nunca la ve.
CREATE TABLE IF NOT EXISTS inscripciones (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden                INT UNSIGNED NOT NULL,
  -- Alumno
  alumno_apellido      VARCHAR(80)  NOT NULL,
  alumno_nombre        VARCHAR(80)  NOT NULL,
  dni                  VARCHAR(8)   NOT NULL,          -- solo números, 7 u 8 cifras
  fecha_nacimiento     DATE         NOT NULL,
  escuela_primaria     VARCHAR(120) NOT NULL,
  -- Domicilio y contacto
  calle                VARCHAR(100) NOT NULL,
  numero               VARCHAR(10)  NOT NULL,          -- texto: admite "1234" o "1234 bis"
  piso_depto           VARCHAR(20)  NULL,              -- opcional
  cp                   CHAR(4)      NOT NULL,          -- 4 cifras (de "B1884ABC" se guarda 1884)
  email                VARCHAR(120) NOT NULL,
  -- Adulto responsable
  responsable_apellido VARCHAR(80)  NOT NULL,
  responsable_nombre   VARCHAR(80)  NOT NULL,
  -- Estado de la vacante
  estado               ENUM('ingresante','espera','fuera_zona') NOT NULL,
  promovida_at         TIMESTAMP(3) NULL,              -- cuándo pasó de espera a ingresante
  alerta               VARCHAR(160) NULL,
  anulada              TINYINT(1)   NOT NULL DEFAULT 0,
  anulada_at           TIMESTAMP(3) NULL,
  created_at           TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at           TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  -- Un alumno = un DNI. Si dos pedidos con el mismo DNI llegan juntos, el segundo choca acá.
  UNIQUE KEY uq_dni (dni),
  UNIQUE KEY uq_orden (orden),
  -- Para contar vacantes y encontrar rápido al primero de la lista de espera
  KEY idx_cola (anulada, estado, orden),
  KEY idx_apellido (alumno_apellido),
  KEY idx_updated (updated_at),          -- el panel pide "lo que cambió desde..." (polling)
  -- Última defensa: aunque la API ya valida, la base no acepta formatos imposibles
  CONSTRAINT chk_dni CHECK (dni REGEXP '^[0-9]{7,8}$'),
  CONSTRAINT chk_cp  CHECK (cp REGEXP '^[0-9]{4}$'),
  CONSTRAINT chk_anulada CHECK (anulada IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fila de control de cupos (el "candado")
-- ---------------------------------------------------------------------------
-- Tiene UNA sola fila (id = 1). Cada inscripción y cada anulación hace, dentro de una
-- transacción:
--   SELECT ultimo_orden FROM control_cupos WHERE id = 1 FOR UPDATE;   -- toma el candado
--   SELECT COUNT(*) ... estado = 'ingresante' AND anulada = 0;        -- cuenta vacantes
--   INSERT/UPDATE con el estado que corresponde y orden = ultimo_orden + 1
--   UPDATE control_cupos SET ultimo_orden = ...;
--   COMMIT;                                                           -- suelta el candado
-- Mientras una transacción tiene el candado, las demás esperan en el FOR UPDATE.
-- Así es imposible que dos pedidos simultáneos vean "quedan 1" y entren los dos.
CREATE TABLE IF NOT EXISTS control_cupos (
  id            TINYINT UNSIGNED NOT NULL,
  ultimo_orden  INT UNSIGNED     NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  CONSTRAINT chk_una_fila CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La fila tiene que existir antes del primer pedido (si no, no hay nada que bloquear)
INSERT IGNORE INTO control_cupos (id, ultimo_orden) VALUES (1, 0);

-- ---------------------------------------------------------------------------
-- Ajustes de la app
-- ---------------------------------------------------------------------------
-- Guarda la contraseña del panel cifrada con bcrypt (clave 'admin_clave').
-- Nunca en texto. Si está vacía, vale la de ADMIN_CLAVE de la configuración
-- hasta que el admin la cambie desde el panel.
CREATE TABLE IF NOT EXISTS ajustes (
  clave       VARCHAR(40)  NOT NULL,
  valor       VARCHAR(255) NOT NULL,
  updated_at  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Intentos fallidos de login del panel por IP (bloqueo temporal)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS intentos_login (
  ip              VARCHAR(45)  NOT NULL,
  fallos          INT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta TIMESTAMP(3) NULL,
  updated_at      TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Límite de envíos del formulario público por IP (antiabuso)
-- ---------------------------------------------------------------------------
-- Cuántos envíos hizo cada IP en el minuto y en la hora actuales.
CREATE TABLE IF NOT EXISTS limite_registro (
  ip          VARCHAR(45)  NOT NULL,
  min_inicio  TIMESTAMP    NOT NULL,
  min_n       INT UNSIGNED NOT NULL DEFAULT 0,
  hora_inicio TIMESTAMP    NOT NULL,
  hora_n      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Usuario de la app con permisos mínimos
-- ---------------------------------------------------------------------------
-- SELECT + INSERT + UPDATE: alcanza para todo, incluido el SELECT ... FOR UPDATE
-- (que pide SELECT y UPDATE). Sin DELETE, DROP, ALTER ni CREATE.
-- En tu PC (XAMPP) usá 'localhost'. En el servidor, si PHP y MariaDB están en la misma
-- máquina también es 'localhost'; si no, poné la IP del servidor web (nunca '%').
-- Cambiá la contraseña por una larga en el servidor.
-- CREATE USER 'inscripcion_user'@'localhost' IDENTIFIED BY 'test1234';
-- GRANT SELECT, INSERT, UPDATE ON inscripcion_tec4.* TO 'inscripcion_user'@'localhost';
-- FLUSH PRIVILEGES;
