-- Migración v2: agrega el teléfono de contacto a las inscripciones.
--
-- Para una base que ya existe (creada con el schema.sql anterior). Una base nueva creada
-- con el schema.sql actual ya lo trae: NO hace falta correr esto.
--
-- Ejecutar como root ANTES de publicar el código nuevo (el usuario de la app no tiene
-- permiso ALTER). Si el código nuevo llega primero, el formulario falla al guardar.
--
-- Las inscripciones anteriores quedan con telefono = NULL. Se puede correr dos veces sin
-- problema (IF NOT EXISTS).
--
-- Cambiá inscripcion_tec4 por el nombre de tu base si es otro (en Dokploy, DB_NAME).

USE inscripcion_tec4;

ALTER TABLE inscripciones
  ADD COLUMN IF NOT EXISTS telefono VARCHAR(13) NULL AFTER email;

ALTER TABLE inscripciones
  ADD CONSTRAINT IF NOT EXISTS chk_telefono CHECK (telefono IS NULL OR telefono REGEXP '^[0-9]{8,13}$');
