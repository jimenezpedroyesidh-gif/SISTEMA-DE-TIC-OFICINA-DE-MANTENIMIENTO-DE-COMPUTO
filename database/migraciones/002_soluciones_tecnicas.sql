-- Módulo de Soluciones Técnicas: scripts de mantenimiento, automatizaciones, agentes de inventario (GLPI Agent),
-- políticas de Windows, restricciones de aplicaciones y configuraciones especiales de los equipos.
-- Reutiliza salas, equipos, users y mantenimientos_programados por id: no duplica ninguna de esas tablas.
-- Nada se sobrescribe: las versiones, procedimientos, verificaciones y el historial (st_historial) solo se agregan.
-- Idempotente (CREATE TABLE IF NOT EXISTS / INSERT IGNORE). El mismo bloque está al final de database/tickets_db.sql.

CREATE TABLE IF NOT EXISTS `st_tipos` (
  `id_tipo` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_tipo`),
  UNIQUE KEY `uq_st_tipo_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `st_tipos` (`id_tipo`, `nombre`, `descripcion`) VALUES
(1, 'Script de mantenimiento', 'Limpieza, reparación o actualización que se ejecuta en el equipo'),
(2, 'Automatización', 'Tarea programada o proceso que se ejecuta solo'),
(3, 'Agente de inventario', 'GLPI Agent u otro agente que reporta el inventario del equipo'),
(4, 'Política de Windows', 'Directiva local o de grupo (GPO)'),
(5, 'Restricción de aplicaciones', 'Bloqueo o lista permitida de programas (AppLocker, SRP)'),
(6, 'Configuración especial', 'Ajuste particular de un equipo o de una sala');

CREATE TABLE IF NOT EXISTS `st_soluciones` (
  `id_solucion` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `id_tipo` int(11) NOT NULL,
  `descripcion` text NOT NULL,
  `objetivo` text NOT NULL,
  `id_responsable` int(11) DEFAULT NULL,
  `estado` enum('BORRADOR','ACTIVA','SUSPENDIDA','RETIRADA') NOT NULL DEFAULT 'BORRADOR' COMMENT 'BORRADOR hasta que el admin aprueba la primera versión',
  `id_creador` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_solucion`),
  UNIQUE KEY `uq_st_codigo` (`codigo`),
  KEY `idx_st_estado` (`estado`),
  KEY `fk_st_sol_tipo` (`id_tipo`),
  KEY `fk_st_sol_responsable` (`id_responsable`),
  KEY `fk_st_sol_creador` (`id_creador`),
  CONSTRAINT `fk_st_sol_tipo` FOREIGN KEY (`id_tipo`) REFERENCES `st_tipos` (`id_tipo`),
  CONSTRAINT `fk_st_sol_responsable` FOREIGN KEY (`id_responsable`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_st_sol_creador` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Versión vigente de una solución = su versión APROBADA más reciente (si se retira, vuelve a regir la anterior).
CREATE TABLE IF NOT EXISTS `st_versiones` (
  `id_version` int(11) NOT NULL AUTO_INCREMENT,
  `id_solucion` int(11) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `notas_cambios` text NOT NULL,
  `estado` enum('EN_REVISION','APROBADA','RECHAZADA','RETIRADA') NOT NULL DEFAULT 'EN_REVISION',
  `id_autor` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_revisor` int(11) DEFAULT NULL,
  `fecha_revision` datetime DEFAULT NULL,
  `observacion_revision` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id_version`),
  UNIQUE KEY `uq_st_version_numero` (`id_solucion`, `numero`),
  KEY `idx_st_version_estado` (`id_solucion`, `estado`),
  KEY `fk_st_ver_autor` (`id_autor`),
  KEY `fk_st_ver_revisor` (`id_revisor`),
  CONSTRAINT `fk_st_ver_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_ver_autor` FOREIGN KEY (`id_autor`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_st_ver_revisor` FOREIGN KEY (`id_revisor`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Archivos de una versión (privados en storage/soluciones/, se bajan solo por descargar_solucion.php).
-- Inmutables: un cambio es una versión nueva. El SHA-256 se revisa antes de cada descarga.
CREATE TABLE IF NOT EXISTS `st_archivos` (
  `id_archivo` int(11) NOT NULL AUTO_INCREMENT,
  `id_version` int(11) NOT NULL,
  `categoria` enum('SCRIPT','INSTALADOR','DOCUMENTACION','CONFIGURACION','OTRO') NOT NULL DEFAULT 'OTRO',
  `nombre_original` varchar(255) NOT NULL,
  `nombre_almacenado` varchar(100) NOT NULL COMMENT 'nombre aleatorio en disco, nunca el original',
  `tamano_bytes` int(10) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `id_usuario_subio` int(11) NOT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_archivo`),
  UNIQUE KEY `uq_st_archivo_almacenado` (`nombre_almacenado`),
  KEY `idx_st_archivo_version` (`id_version`),
  KEY `fk_st_arch_usuario` (`id_usuario_subio`),
  CONSTRAINT `fk_st_arch_version` FOREIGN KEY (`id_version`) REFERENCES `st_versiones` (`id_version`),
  CONSTRAINT `fk_st_arch_usuario` FOREIGN KEY (`id_usuario_subio`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Procedimientos por fase. Editar = fila nueva (id_reemplaza) y la anterior queda con vigente = 0.
CREATE TABLE IF NOT EXISTS `st_procedimientos` (
  `id_procedimiento` int(11) NOT NULL AUTO_INCREMENT,
  `id_solucion` int(11) NOT NULL,
  `fase` enum('INSTALACION','EJECUCION','VERIFICACION','REVERSION') NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `pasos` text NOT NULL,
  `enlace_video` varchar(500) DEFAULT NULL,
  `vigente` tinyint(1) NOT NULL DEFAULT 1,
  `id_reemplaza` int(11) DEFAULT NULL,
  `id_autor` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_procedimiento`),
  KEY `idx_st_proc_solucion` (`id_solucion`, `vigente`, `fase`),
  KEY `fk_st_proc_reemplaza` (`id_reemplaza`),
  KEY `fk_st_proc_autor` (`id_autor`),
  CONSTRAINT `fk_st_proc_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_proc_reemplaza` FOREIGN KEY (`id_reemplaza`) REFERENCES `st_procedimientos` (`id_procedimiento`),
  CONSTRAINT `fk_st_proc_autor` FOREIGN KEY (`id_autor`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dónde debe aplicarse: toda la sala (id_equipo NULL = sus equipos de cómputo operativos) o un equipo puntual.
CREATE TABLE IF NOT EXISTS `st_objetivos` (
  `id_objetivo` int(11) NOT NULL AUTO_INCREMENT,
  `id_solucion` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `id_creador` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_baja` datetime DEFAULT NULL,
  PRIMARY KEY (`id_objetivo`),
  KEY `idx_st_obj_solucion` (`id_solucion`, `activo`),
  KEY `fk_st_obj_sala` (`id_sala`),
  KEY `fk_st_obj_equipo` (`id_equipo`),
  KEY `fk_st_obj_creador` (`id_creador`),
  CONSTRAINT `fk_st_obj_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_obj_sala` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `fk_st_obj_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `fk_st_obj_creador` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Estado real de la solución en cada computadora (una fila por solución y equipo; los cambios quedan en st_historial).
CREATE TABLE IF NOT EXISTS `st_despliegues` (
  `id_despliegue` int(11) NOT NULL AUTO_INCREMENT,
  `id_solucion` int(11) NOT NULL,
  `id_equipo` int(11) NOT NULL,
  `id_version` int(11) DEFAULT NULL COMMENT 'Versión instalada',
  `estado` enum('PENDIENTE','PROGRAMADO','IMPLEMENTADO','VERIFICADO','CON_INCIDENCIA','REVERTIDO') NOT NULL DEFAULT 'PENDIENTE',
  `id_programado` int(11) DEFAULT NULL COMMENT 'Último mantenimiento que la programó o la aplicó',
  `fecha_implementacion` datetime DEFAULT NULL,
  `id_usuario_ultimo` int(11) DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_despliegue`),
  UNIQUE KEY `uq_st_despliegue_equipo` (`id_solucion`, `id_equipo`),
  KEY `idx_st_desp_equipo` (`id_equipo`),
  KEY `idx_st_desp_programado` (`id_programado`),
  KEY `fk_st_desp_version` (`id_version`),
  KEY `fk_st_desp_usuario` (`id_usuario_ultimo`),
  CONSTRAINT `fk_st_desp_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_desp_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `fk_st_desp_version` FOREIGN KEY (`id_version`) REFERENCES `st_versiones` (`id_version`),
  CONSTRAINT `fk_st_desp_programado` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`),
  CONSTRAINT `fk_st_desp_usuario` FOREIGN KEY (`id_usuario_ultimo`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Verificación manual del funcionamiento en un equipo (solo se agregan filas).
CREATE TABLE IF NOT EXISTS `st_verificaciones` (
  `id_verificacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_despliegue` int(11) NOT NULL,
  `id_version` int(11) DEFAULT NULL,
  `resultado` enum('CORRECTO','CON_OBSERVACIONES','FALLIDO') NOT NULL,
  `observaciones` text NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_verificacion`),
  KEY `idx_st_verif_despliegue` (`id_despliegue`, `fecha`),
  KEY `fk_st_verif_version` (`id_version`),
  KEY `fk_st_verif_usuario` (`id_usuario`),
  CONSTRAINT `fk_st_verif_despliegue` FOREIGN KEY (`id_despliegue`) REFERENCES `st_despliegues` (`id_despliegue`),
  CONSTRAINT `fk_st_verif_version` FOREIGN KEY (`id_version`) REFERENCES `st_versiones` (`id_version`),
  CONSTRAINT `fk_st_verif_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Capturas y fotos: de un procedimiento, de una verificación o de la ejecución de un equipo (beca).
-- Una misma foto puede respaldar varias verificaciones hechas a la vez (por eso el nombre en disco no es único).
CREATE TABLE IF NOT EXISTS `st_evidencias` (
  `id_evidencia` int(11) NOT NULL AUTO_INCREMENT,
  `id_solucion` int(11) NOT NULL,
  `id_procedimiento` int(11) DEFAULT NULL,
  `id_verificacion` int(11) DEFAULT NULL,
  `id_despliegue` int(11) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `nombre_almacenado` varchar(100) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `tamano_bytes` int(10) unsigned NOT NULL,
  `id_usuario_subio` int(11) NOT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_evidencia`),
  KEY `idx_st_evid_solucion` (`id_solucion`),
  KEY `idx_st_evid_procedimiento` (`id_procedimiento`),
  KEY `idx_st_evid_verificacion` (`id_verificacion`),
  KEY `idx_st_evid_despliegue` (`id_despliegue`),
  KEY `fk_st_evid_usuario` (`id_usuario_subio`),
  CONSTRAINT `fk_st_evid_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_evid_procedimiento` FOREIGN KEY (`id_procedimiento`) REFERENCES `st_procedimientos` (`id_procedimiento`),
  CONSTRAINT `fk_st_evid_verificacion` FOREIGN KEY (`id_verificacion`) REFERENCES `st_verificaciones` (`id_verificacion`),
  CONSTRAINT `fk_st_evid_despliegue` FOREIGN KEY (`id_despliegue`) REFERENCES `st_despliegues` (`id_despliegue`),
  CONSTRAINT `fk_st_evid_usuario` FOREIGN KEY (`id_usuario_subio`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Historial de la solución: cada acción queda como una fila nueva (la aplicación nunca lo edita ni lo borra).
CREATE TABLE IF NOT EXISTS `st_historial` (
  `id_historial` int(11) NOT NULL AUTO_INCREMENT,
  `id_solucion` int(11) NOT NULL,
  `entidad` enum('SOLUCION','VERSION','ARCHIVO','PROCEDIMIENTO','OBJETIVO','DESPLIEGUE','VERIFICACION','EVIDENCIA','MANTENIMIENTO') NOT NULL,
  `id_entidad` int(11) DEFAULT NULL,
  `accion` varchar(40) NOT NULL,
  `detalle` text DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_historial`),
  KEY `idx_st_hist_solucion` (`id_solucion`, `fecha`),
  KEY `fk_st_hist_usuario` (`id_usuario`),
  CONSTRAINT `fk_st_hist_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_hist_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Vínculo con el módulo de mantenimiento: qué solución (y versión) se aplica en cada mantenimiento programado.
-- Al cerrarse el mantenimiento, su checklist actualiza los despliegues (sincronizado_en marca que ya se hizo).
CREATE TABLE IF NOT EXISTS `st_mantenimiento_detalle` (
  `id_detalle` int(11) NOT NULL AUTO_INCREMENT,
  `id_programado` int(11) NOT NULL,
  `id_solucion` int(11) NOT NULL,
  `id_version` int(11) NOT NULL,
  `id_creador` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `sincronizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id_detalle`),
  UNIQUE KEY `uq_st_mant_solucion` (`id_programado`, `id_solucion`),
  KEY `idx_st_mant_sincronizar` (`sincronizado_en`),
  KEY `fk_st_mant_solucion` (`id_solucion`),
  KEY `fk_st_mant_version` (`id_version`),
  KEY `fk_st_mant_creador` (`id_creador`),
  CONSTRAINT `fk_st_mant_programado` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`),
  CONSTRAINT `fk_st_mant_solucion` FOREIGN KEY (`id_solucion`) REFERENCES `st_soluciones` (`id_solucion`),
  CONSTRAINT `fk_st_mant_version` FOREIGN KEY (`id_version`) REFERENCES `st_versiones` (`id_version`),
  CONSTRAINT `fk_st_mant_creador` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
