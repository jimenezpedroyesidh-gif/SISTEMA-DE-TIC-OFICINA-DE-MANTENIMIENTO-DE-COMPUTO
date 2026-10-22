-- Mantenimiento de «ciertos equipos»: el técnico elige qué equipos de la sala entran en la checklist.
ALTER TABLE `mantenimientos_programados`
  MODIFY `alcance_equipo` enum('TODOS','COMPUTO','VIDEOBEAM','SELECCION') NOT NULL DEFAULT 'TODOS';
