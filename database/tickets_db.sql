-- =====================================================================
-- Base de datos del Sistema de Tickets (tickets_db) — esquema completo (68 tablas) y catálogos base.
-- No destructivo: todo es CREATE ... IF NOT EXISTS / INSERT IGNORE y las columnas nuevas se agregan solo
-- si faltan, así que se puede volver a importar sobre una base con datos sin perder nada.
--
-- Instalación:  mysql -u USUARIO -p < database/tickets_db.sql   (o phpMyAdmin → Importar)
-- Usuarios y datos de prueba: database/seeds/.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE DATABASE IF NOT EXISTS `tickets_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `tickets_db`;

-- ===================== ESQUEMA =====================

CREATE TABLE IF NOT EXISTS `activo_software` (
  `id_activo_software` int(11) NOT NULL AUTO_INCREMENT,
  `id_componente` int(11) NOT NULL,
  `id_licencia` int(11) NOT NULL,
  `fecha_instalacion` date NOT NULL,
  `fecha_desinstalacion` date DEFAULT NULL,
  `instalado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_activo_software`),
  KEY `idx_licencia_activa` (`id_licencia`,`fecha_desinstalacion`),
  KEY `id_componente` (`id_componente`),
  KEY `instalado_por` (`instalado_por`),
  CONSTRAINT `activo_software_ibfk_1` FOREIGN KEY (`id_componente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `activo_software_ibfk_2` FOREIGN KEY (`id_licencia`) REFERENCES `licencias_software` (`id_licencia`),
  CONSTRAINT `activo_software_ibfk_3` FOREIGN KEY (`instalado_por`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `auditoria` (
  `id_auditoria` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) DEFAULT NULL,
  `tabla_afectada` varchar(50) NOT NULL,
  `id_registro` int(11) DEFAULT NULL,
  `accion` enum('CREAR','EDITAR','BORRAR') NOT NULL,
  `detalle` text DEFAULT NULL,
  `fecha_hora` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_auditoria`),
  KEY `id_usuario` (`id_usuario`),
  KEY `idx_auditoria_tabla_registro` (`tabla_afectada`,`id_registro`,`fecha_hora`),
  CONSTRAINT `auditoria_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `auditoria_detalle_campos` (
  `id_detalle` int(11) NOT NULL AUTO_INCREMENT,
  `id_auditoria` int(11) NOT NULL,
  `campo` varchar(100) NOT NULL,
  `valor_anterior` text DEFAULT NULL,
  `valor_nuevo` text DEFAULT NULL,
  PRIMARY KEY (`id_detalle`),
  KEY `id_auditoria` (`id_auditoria`),
  CONSTRAINT `auditoria_detalle_campos_ibfk_1` FOREIGN KEY (`id_auditoria`) REFERENCES `auditoria` (`id_auditoria`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `categorias_activo` (
  `id_categoria` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `id_categoria_padre` int(11) DEFAULT NULL,
  `tipo_categoria` enum('HARDWARE','SOFTWARE','LICENCIA') NOT NULL DEFAULT 'HARDWARE',
  PRIMARY KEY (`id_categoria`),
  UNIQUE KEY `uq_categoria_padre_nombre` (`id_categoria_padre`,`nombre`),
  CONSTRAINT `categorias_activo_ibfk_1` FOREIGN KEY (`id_categoria_padre`) REFERENCES `categorias_activo` (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `componentes` (
  `id_componente` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('torre','monitor','teclado','mouse','parlante','videobeam','portatil','todo_en_uno','otro','aire_acondicionado','iluminacion','toma_electrica','silla','otro_infraestructura') NOT NULL,
  `id_categoria` int(11) DEFAULT NULL,
  `serial` varchar(50) NOT NULL,
  `codigo_inventario` varchar(30) NOT NULL,
  `marca` varchar(50) DEFAULT NULL,
  `id_fabricante` int(11) DEFAULT NULL,
  `modelo` varchar(50) DEFAULT NULL,
  `id_modelo` int(11) DEFAULT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  `estado` enum('ACTIVO','EN_REVISION','DEFECTUOSO','DE_BAJA') NOT NULL DEFAULT 'ACTIVO',
  `id_equipo_actual` int(11) DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp(),
  `fecha_compra` date DEFAULT NULL,
  `valor_compra` decimal(12,2) DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `fecha_fin_garantia` date DEFAULT NULL,
  `vida_util_meses` int(11) DEFAULT NULL,
  `ubicacion_fisica_detalle` varchar(150) DEFAULT NULL,
  `id_sala_directa` int(11) DEFAULT NULL,
  `glpi_itemtype` varchar(40) DEFAULT NULL COMMENT 'Tipo del activo en GLPI (Computer, Monitor, Peripheral…) cuando está vinculado',
  `glpi_id` int(11) DEFAULT NULL COMMENT 'id del activo en GLPI; NULL = aún no existe/no está vinculado',
  PRIMARY KEY (`id_componente`),
  UNIQUE KEY `uq_serial` (`serial`),
  UNIQUE KEY `uq_codigo_inventario` (`codigo_inventario`),
  KEY `idx_estado` (`estado`),
  KEY `id_equipo_actual` (`id_equipo_actual`),
  KEY `fk_componentes_fabricante` (`id_fabricante`),
  KEY `fk_componentes_modelo` (`id_modelo`),
  KEY `fk_componentes_categoria` (`id_categoria`),
  KEY `fk_componentes_sala_directa` (`id_sala_directa`),
  CONSTRAINT `componentes_ibfk_1` FOREIGN KEY (`id_equipo_actual`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `fk_componentes_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias_activo` (`id_categoria`),
  CONSTRAINT `fk_componentes_fabricante` FOREIGN KEY (`id_fabricante`) REFERENCES `fabricantes` (`id_fabricante`),
  CONSTRAINT `fk_componentes_modelo` FOREIGN KEY (`id_modelo`) REFERENCES `modelos_activo` (`id_modelo`),
  CONSTRAINT `fk_componentes_sala_directa` FOREIGN KEY (`id_sala_directa`) REFERENCES `salas` (`id_sala`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `equipos` (
  `id_equipo` int(11) NOT NULL AUTO_INCREMENT,
  `id_sala` int(11) NOT NULL,
  `codigo` varchar(30) NOT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  `id_sala_original` int(11) DEFAULT NULL,
  `operativo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_equipo`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `id_sala` (`id_sala`),
  KEY `fk_equipos_sala_original` (`id_sala_original`),
  CONSTRAINT `equipos_ibfk_1` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `fk_equipos_sala_original` FOREIGN KEY (`id_sala_original`) REFERENCES `salas` (`id_sala`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `excepciones_horario` (
  `id_excepcion` int(11) NOT NULL AUTO_INCREMENT,
  `id_horario` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `tipo_excepcion` enum('CANCELADO','CAMBIO_SALA','CAMBIO_HORA') NOT NULL,
  `nueva_id_sala` int(11) DEFAULT NULL,
  `nueva_hora_inicio` time DEFAULT NULL,
  `nueva_hora_fin` time DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `id_creador` int(11) NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_excepcion`),
  UNIQUE KEY `unica_excepcion_por_fecha` (`id_horario`,`fecha`),
  KEY `nueva_id_sala` (`nueva_id_sala`),
  KEY `id_creador` (`id_creador`),
  CONSTRAINT `excepciones_horario_ibfk_1` FOREIGN KEY (`id_horario`) REFERENCES `horarios` (`id_horario`),
  CONSTRAINT `excepciones_horario_ibfk_2` FOREIGN KEY (`nueva_id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `excepciones_horario_ibfk_3` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `fabricantes` (
  `id_fabricante` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_fabricante`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `historial_componente` (
  `id_historial` int(11) NOT NULL AUTO_INCREMENT,
  `id_componente` int(11) NOT NULL,
  `tipo_evento` enum('REGISTRO','ASIGNACION','DESASIGNACION','CAMBIO_ESTADO','REVISION','BAJA') NOT NULL,
  `id_equipo_anterior` int(11) DEFAULT NULL,
  `id_equipo_nuevo` int(11) DEFAULT NULL,
  `estado_anterior` varchar(20) DEFAULT NULL,
  `estado_nuevo` varchar(20) DEFAULT NULL,
  `id_ticket` int(11) DEFAULT NULL,
  `id_ticket_archivado` int(11) DEFAULT NULL COMMENT 'tickets_archivo.id_archivo cuando el ticket se archivó en el cierre de semestre',
  `motivo` varchar(255) DEFAULT NULL,
  `id_usuario_responsable` int(11) NOT NULL,
  `observacion` text DEFAULT NULL,
  `fecha_evento` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_historial`),
  KEY `idx_componente` (`id_componente`),
  KEY `id_ticket` (`id_ticket`),
  KEY `id_usuario_responsable` (`id_usuario_responsable`),
  CONSTRAINT `historial_componente_ibfk_1` FOREIGN KEY (`id_componente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `historial_componente_ibfk_2` FOREIGN KEY (`id_ticket`) REFERENCES `tickets` (`id`),
  CONSTRAINT `historial_componente_ibfk_3` FOREIGN KEY (`id_usuario_responsable`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `horarios` (
  `id_horario` int(11) NOT NULL AUTO_INCREMENT,
  `id_sala` int(11) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `tipo` enum('CLASE','TURNO_BECA','TURNO_TECNICO') NOT NULL,
  `dia_semana` tinyint(4) NOT NULL,
  `periodo` varchar(9) NOT NULL DEFAULT '',
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `detalle` varchar(150) DEFAULT NULL,
  `id_creador` int(11) NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_horario`),
  KEY `id_creador` (`id_creador`),
  KEY `idx_horarios_sala_dia` (`id_sala`,`dia_semana`),
  KEY `idx_horarios_usuario` (`id_usuario`),
  KEY `idx_horarios_periodo` (`periodo`),
  CONSTRAINT `horarios_ibfk_1` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `horarios_ibfk_2` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`),
  CONSTRAINT `horarios_ibfk_3` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`dia_semana` between 1 and 7),
  CONSTRAINT `CONSTRAINT_2` CHECK (`hora_fin` > `hora_inicio`),
  CONSTRAINT `CONSTRAINT_3` CHECK (`tipo` <> 'CLASE' or `id_sala` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `licencias_software` (
  `id_licencia` int(11) NOT NULL AUTO_INCREMENT,
  `id_software` int(11) NOT NULL,
  `id_version` int(11) DEFAULT NULL,
  `tipo_licencia` enum('PERPETUA','SUSCRIPCION','OEM','VOLUMEN','FREEWARE') NOT NULL DEFAULT 'PERPETUA',
  `clave_producto` varchar(255) DEFAULT NULL,
  `asientos_totales` int(11) NOT NULL DEFAULT 1,
  `fecha_compra` date DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `costo` decimal(12,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_licencia`),
  KEY `idx_vencimiento` (`fecha_vencimiento`),
  KEY `id_software` (`id_software`),
  KEY `id_version` (`id_version`),
  CONSTRAINT `licencias_software_ibfk_1` FOREIGN KEY (`id_software`) REFERENCES `software` (`id_software`),
  CONSTRAINT `licencias_software_ibfk_2` FOREIGN KEY (`id_version`) REFERENCES `versiones_software` (`id_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `mantenimiento_equipos` (
  `id_mant_equipo` int(11) NOT NULL AUTO_INCREMENT,
  `id_programado` int(11) NOT NULL,
  `id_equipo` int(11) NOT NULL,
  `estado` enum('PENDIENTE','TOMADO','REALIZADO','INCOMPLETO','EXCLUIDO_TRASLADO','TRASLADO_PENDIENTE') NOT NULL DEFAULT 'PENDIENTE' COMMENT 'TRASLADO_PENDIENTE = se propuso llevar una pieza a la oficina de mantenimiento (bodega); falta que el técnico lo apruebe',
  `id_usuario_ultimo` int(11) DEFAULT NULL,
  `id_usuario_asignado` int(11) DEFAULT NULL,
  `fecha_actualizacion` datetime DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `id_componente_saliente` int(11) DEFAULT NULL,
  `id_componente_entrante` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_mant_equipo`),
  UNIQUE KEY `uq_equipo_por_mantenimiento` (`id_programado`,`id_equipo`),
  KEY `id_equipo` (`id_equipo`),
  KEY `id_usuario_ultimo` (`id_usuario_ultimo`),
  KEY `id_componente_saliente` (`id_componente_saliente`),
  KEY `id_componente_entrante` (`id_componente_entrante`),
  KEY `fk_mantequipo_asignado` (`id_usuario_asignado`),
  CONSTRAINT `fk_mantequipo_asignado` FOREIGN KEY (`id_usuario_asignado`) REFERENCES `users` (`id`),
  CONSTRAINT `mantenimiento_equipos_ibfk_1` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`),
  CONSTRAINT `mantenimiento_equipos_ibfk_2` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `mantenimiento_equipos_ibfk_3` FOREIGN KEY (`id_usuario_ultimo`) REFERENCES `users` (`id`),
  CONSTRAINT `mantenimiento_equipos_ibfk_4` FOREIGN KEY (`id_componente_saliente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `mantenimiento_equipos_ibfk_5` FOREIGN KEY (`id_componente_entrante`) REFERENCES `componentes` (`id_componente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `mantenimientos_programados` (
  `id_programado` int(11) NOT NULL AUTO_INCREMENT,
  `id_sala` int(11) NOT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `tipo` enum('MANTENIMIENTO_PREVENTIVO','INSTALACION_SOFTWARE','OTRO') NOT NULL,
  `alcance_equipo` enum('TODOS','COMPUTO','VIDEOBEAM','SELECCION') NOT NULL DEFAULT 'TODOS',
  `descripcion` text NOT NULL,
  `enlace_referencia` varchar(500) DEFAULT NULL,
  `fecha_programada` datetime NOT NULL,
  `id_responsable` int(11) DEFAULT NULL,
  `id_creador` int(11) NOT NULL,
  `id_ticket_origen` int(11) DEFAULT NULL,
  `id_ticket_profesor_origen` int(11) DEFAULT NULL,
  `estado` enum('PROGRAMADO','EN_PROCESO','REALIZADO','CANCELADO') NOT NULL DEFAULT 'PROGRAMADO',
  `observaciones_cierre` text DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT current_timestamp(),
  `fecha_realizado` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id_programado`),
  KEY `idx_estado_fecha` (`estado`,`fecha_programada`),
  KEY `id_sala` (`id_sala`),
  KEY `id_equipo` (`id_equipo`),
  KEY `id_responsable` (`id_responsable`),
  KEY `id_creador` (`id_creador`),
  KEY `fk_mant_ticket_origen` (`id_ticket_origen`),
  KEY `fk_mantprog_ticketprofesor` (`id_ticket_profesor_origen`),
  CONSTRAINT `fk_mant_ticket_origen` FOREIGN KEY (`id_ticket_origen`) REFERENCES `tickets` (`id`),
  CONSTRAINT `fk_mantprog_ticketprofesor` FOREIGN KEY (`id_ticket_profesor_origen`) REFERENCES `tickets_profesor` (`id`),
  CONSTRAINT `mantenimientos_programados_ibfk_1` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `mantenimientos_programados_ibfk_2` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `mantenimientos_programados_ibfk_3` FOREIGN KEY (`id_responsable`) REFERENCES `users` (`id`),
  CONSTRAINT `mantenimientos_programados_ibfk_4` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `modelos_activo` (
  `id_modelo` int(11) NOT NULL AUTO_INCREMENT,
  `id_fabricante` int(11) DEFAULT NULL,
  `id_categoria` int(11) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `ficha_tecnica` text DEFAULT NULL,
  PRIMARY KEY (`id_modelo`),
  UNIQUE KEY `uq_fabricante_modelo` (`id_fabricante`,`nombre`),
  KEY `id_categoria` (`id_categoria`),
  CONSTRAINT `modelos_activo_ibfk_1` FOREIGN KEY (`id_fabricante`) REFERENCES `fabricantes` (`id_fabricante`),
  CONSTRAINT `modelos_activo_ibfk_2` FOREIGN KEY (`id_categoria`) REFERENCES `categorias_activo` (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `movimientos_activos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_equipo` int(11) DEFAULT NULL,
  `id_componente` int(11) DEFAULT NULL,
  `id_sala_origen` int(11) DEFAULT NULL,
  `id_sala_destino` int(11) DEFAULT NULL,
  `motivo` varchar(150) NOT NULL,
  `tabla_ticket_relacionado` enum('tickets','tickets_profesor') DEFAULT NULL,
  `id_ticket_relacionado` int(11) DEFAULT NULL,
  `id_solicito` int(11) DEFAULT NULL,
  `id_aprobo` int(11) DEFAULT NULL,
  `id_ejecuto` int(11) DEFAULT NULL,
  `fecha_solicitado` timestamp NULL DEFAULT current_timestamp(),
  `fecha_aprobado` datetime DEFAULT NULL,
  `fecha_ejecutado` datetime DEFAULT NULL,
  `evidencia` varchar(255) DEFAULT NULL,
  `id_adjunto` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_equipo` (`id_equipo`),
  KEY `idx_componente` (`id_componente`),
  KEY `idx_ticket_relacionado` (`tabla_ticket_relacionado`,`id_ticket_relacionado`),
  KEY `id_sala_origen` (`id_sala_origen`),
  KEY `id_sala_destino` (`id_sala_destino`),
  KEY `id_solicito` (`id_solicito`),
  KEY `id_aprobo` (`id_aprobo`),
  KEY `id_ejecuto` (`id_ejecuto`),
  KEY `fk_movimiento_adjunto` (`id_adjunto`),
  CONSTRAINT `fk_movimiento_adjunto` FOREIGN KEY (`id_adjunto`) REFERENCES `ticket_adjuntos` (`id`),
  CONSTRAINT `movimientos_activos_ibfk_1` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `movimientos_activos_ibfk_2` FOREIGN KEY (`id_componente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `movimientos_activos_ibfk_3` FOREIGN KEY (`id_sala_origen`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `movimientos_activos_ibfk_4` FOREIGN KEY (`id_sala_destino`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `movimientos_activos_ibfk_5` FOREIGN KEY (`id_solicito`) REFERENCES `users` (`id`),
  CONSTRAINT `movimientos_activos_ibfk_6` FOREIGN KEY (`id_aprobo`) REFERENCES `users` (`id`),
  CONSTRAINT `movimientos_activos_ibfk_7` FOREIGN KEY (`id_ejecuto`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_movimiento_referencia` CHECK (`id_equipo` is not null or `id_componente` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `notificaciones` (
  `id_notificacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `tipo` enum('TICKET_ESTADO','MANTENIMIENTO','REQUERIMIENTO','SISTEMA') NOT NULL DEFAULT 'TICKET_ESTADO',
  `mensaje` varchar(255) NOT NULL,
  `url_destino` varchar(255) DEFAULT NULL,
  `leido` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_leido` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id_notificacion`),
  KEY `idx_usuario_leido` (`id_usuario`,`leido`),
  CONSTRAINT `notificaciones_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `relaciones_activos` (
  `id_relacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_componente_origen` int(11) NOT NULL,
  `id_componente_destino` int(11) NOT NULL,
  `id_tipo_relacion` int(11) NOT NULL,
  `fecha_inicio` date NOT NULL DEFAULT curdate(),
  `fecha_fin` date DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `id_usuario_registro` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_relacion`),
  UNIQUE KEY `uq_relacion_vigente` (`id_componente_origen`,`id_componente_destino`,`id_tipo_relacion`,`fecha_fin`),
  KEY `id_componente_destino` (`id_componente_destino`),
  KEY `id_tipo_relacion` (`id_tipo_relacion`),
  KEY `id_usuario_registro` (`id_usuario_registro`),
  CONSTRAINT `relaciones_activos_ibfk_1` FOREIGN KEY (`id_componente_origen`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `relaciones_activos_ibfk_2` FOREIGN KEY (`id_componente_destino`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `relaciones_activos_ibfk_3` FOREIGN KEY (`id_tipo_relacion`) REFERENCES `tipos_relacion_activo` (`id_tipo_relacion`),
  CONSTRAINT `relaciones_activos_ibfk_4` FOREIGN KEY (`id_usuario_registro`) REFERENCES `users` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`id_componente_origen` <> `id_componente_destino`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `salas` (
  `id_sala` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_sala` varchar(50) NOT NULL,
  `abreviatura` varchar(10) NOT NULL DEFAULT '',
  `dependencia` varchar(100) DEFAULT NULL,
  `codigo_qr_token` varchar(100) NOT NULL,
  `es_oficina_mantenimiento` tinyint(1) NOT NULL DEFAULT 0,
  `glpi_location_id` int(11) DEFAULT NULL COMMENT 'Ubicación (glpi_locations.id) equivalente en GLPI',
  PRIMARY KEY (`id_sala`),
  UNIQUE KEY `nombre_sala` (`nombre_sala`),
  UNIQUE KEY `codigo_qr_token` (`codigo_qr_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Distribución física del salón (maquetación del plano): filas → mesas → puestos, con el equipo de cada
-- puesto, su número visible y el punto de red; además, dónde está el puesto docente (adelante/atrás).
-- La edita técnico/admin en distribucion_sala.php. JSON validado por DistribucionSala::normalizar().
CREATE TABLE IF NOT EXISTS `sala_distribucion` (
  `id_sala` int(11) NOT NULL,
  `estructura` longtext NOT NULL COMMENT 'JSON: docente{ubicacion,lado,id_equipo,numero,red} + filas[{mesas[{pasillo,puestos[{id_equipo,numero,red}]}]}]',
  `id_usuario` int(11) DEFAULT NULL COMMENT 'Último que la modificó',
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_sala`),
  CONSTRAINT `fk_sala_distribucion_sala` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- La secretaria crea/edita/borra; la beca mueve las suyas entre columnas.
CREATE TABLE IF NOT EXISTS `actividades_tablero` (
  `id_actividad` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(120) NOT NULL,
  `detalle` text DEFAULT NULL,
  `color` enum('amarillo','azul','verde','rosa','lila') NOT NULL DEFAULT 'amarillo',
  `estado` enum('POR_HACER','EN_CURSO','HECHO') NOT NULL DEFAULT 'POR_HACER',
  `id_asignado` int(11) DEFAULT NULL COMMENT 'Beca a cargo (NULL = cualquiera del área la puede tomar)',
  `id_creador` int(11) NOT NULL,
  `fecha_limite` date DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_actividad`),
  KEY `idx_actividad_estado` (`estado`),
  KEY `idx_actividad_asignado` (`id_asignado`),
  CONSTRAINT `fk_actividad_asignado` FOREIGN KEY (`id_asignado`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_actividad_creador` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `salas_unidades` (
  `id_sala` int(11) NOT NULL,
  `id_unidad` int(11) NOT NULL,
  PRIMARY KEY (`id_sala`,`id_unidad`),
  KEY `id_unidad` (`id_unidad`),
  CONSTRAINT `salas_unidades_ibfk_1` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `salas_unidades_ibfk_2` FOREIGN KEY (`id_unidad`) REFERENCES `unidades_organizacionales` (`id_unidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Personal (técnico/beca) asignado a una oficina de mantenimiento. La usa gestionar_salas.php. No
-- destructivo: IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS `oficina_staff` (
  `id_sala` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rol` enum('tecnico','beca') NOT NULL,
  `fecha_asignacion` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_sala`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `oficina_staff_ibfk_1` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `oficina_staff_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `software` (
  `id_software` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `id_fabricante` int(11) DEFAULT NULL,
  `categoria` enum('SISTEMA_OPERATIVO','OFIMATICA','ANTIVIRUS','EDUCATIVO','DESARROLLO','OTRO') NOT NULL DEFAULT 'OTRO',
  PRIMARY KEY (`id_software`),
  UNIQUE KEY `uq_software_fabricante` (`nombre`,`id_fabricante`),
  KEY `id_fabricante` (`id_fabricante`),
  CONSTRAINT `software_ibfk_1` FOREIGN KEY (`id_fabricante`) REFERENCES `fabricantes` (`id_fabricante`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `solicitudes_baja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_componente` int(11) NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `id_solicito` int(11) NOT NULL,
  `fecha_solicitado` timestamp NULL DEFAULT current_timestamp(),
  `estado` enum('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  `id_aprobo` int(11) DEFAULT NULL,
  `fecha_aprobado` datetime DEFAULT NULL,
  `observacion_aprobacion` varchar(255) DEFAULT NULL,
  `tabla_ticket_relacionado` enum('tickets','tickets_profesor') DEFAULT NULL,
  `id_ticket_relacionado` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_componente` (`id_componente`),
  KEY `idx_estado` (`estado`),
  KEY `id_solicito` (`id_solicito`),
  KEY `id_aprobo` (`id_aprobo`),
  CONSTRAINT `solicitudes_baja_ibfk_1` FOREIGN KEY (`id_componente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `solicitudes_baja_ibfk_2` FOREIGN KEY (`id_solicito`) REFERENCES `users` (`id`),
  CONSTRAINT `solicitudes_baja_ibfk_3` FOREIGN KEY (`id_aprobo`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `ticket_activos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `id_componente` int(11) DEFAULT NULL,
  `tipo_afectacion` enum('DAÑADO','REVISION','CAMBIO_PIEZA','TRASLADADO') NOT NULL DEFAULT 'DAÑADO',
  `observacion` varchar(255) DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_activo` (`tabla_ticket`,`id_ticket`,`id_equipo`,`id_componente`),
  KEY `idx_ticket` (`tabla_ticket`,`id_ticket`),
  KEY `idx_equipo` (`id_equipo`),
  KEY `idx_componente` (`id_componente`),
  CONSTRAINT `ticket_activos_ibfk_1` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `ticket_activos_ibfk_2` FOREIGN KEY (`id_componente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `chk_ticket_activo_referencia` CHECK (`id_equipo` is not null or `id_componente` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `ticket_adjuntos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') DEFAULT NULL,
  `id_ticket` int(11) DEFAULT NULL,
  `id_movimiento` int(11) DEFAULT NULL,
  `id_programado` int(11) DEFAULT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `nombre_almacenado` varchar(100) NOT NULL COMMENT 'nombre aleatorio real en disco, nunca el original',
  `categoria` enum('ADJUNTO','EVIDENCIA') NOT NULL DEFAULT 'ADJUNTO' COMMENT 'EVIDENCIA = foto del trabajo realizado (va al PDF)',
  `mime_type` varchar(100) NOT NULL,
  `tamano_bytes` int(10) unsigned NOT NULL,
  `id_usuario_subio` int(11) NOT NULL,
  `fecha_subida` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nombre_almacenado` (`nombre_almacenado`),
  KEY `idx_ticket` (`tabla_ticket`,`id_ticket`),
  KEY `idx_movimiento` (`id_movimiento`),
  KEY `idx_programado` (`id_programado`),
  KEY `id_usuario_subio` (`id_usuario_subio`),
  CONSTRAINT `fk_adjunto_programado` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`),
  CONSTRAINT `ticket_adjuntos_ibfk_1` FOREIGN KEY (`id_movimiento`) REFERENCES `movimientos_activos` (`id`),
  CONSTRAINT `ticket_adjuntos_ibfk_2` FOREIGN KEY (`id_usuario_subio`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_adjunto_un_solo_destino` CHECK (`id_ticket` is not null and `id_movimiento` is null and `id_programado` is null or `id_ticket` is null and `id_movimiento` is not null and `id_programado` is null or `id_ticket` is null and `id_movimiento` is null and `id_programado` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `ticket_asignaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `id_tecnico` int(11) NOT NULL,
  `fecha_asignado` timestamp NULL DEFAULT current_timestamp(),
  `fecha_liberado` datetime DEFAULT NULL,
  `motivo_liberacion` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`tabla_ticket`,`id_ticket`),
  KEY `idx_tecnico` (`id_tecnico`),
  CONSTRAINT `ticket_asignaciones_ibfk_1` FOREIGN KEY (`id_tecnico`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `ticket_comentarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL COMMENT 'NULL = quien reportó por QR sin sesión (ver autor_externo)',
  `autor_externo` varchar(150) DEFAULT NULL COMMENT 'nombre de quien escribe sin sesión (QR)',
  `visibilidad` enum('INTERNO','CONVERSACION') NOT NULL DEFAULT 'INTERNO' COMMENT 'INTERNO = nota del equipo; CONVERSACION = chat con quien reportó (va al PDF)',
  `es_reiteracion` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = el solicitante insistió tras vencer el tiempo de respuesta',
  `comentario` text NOT NULL,
  `fecha_creacion` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`tabla_ticket`,`id_ticket`),
  KEY `id_usuario` (`id_usuario`),
  CONSTRAINT `ticket_comentarios_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `tecnico_id` int(11) DEFAULT NULL,
  `beca_id` int(11) DEFAULT NULL,
  `id_sala` int(11) DEFAULT NULL,
  `id_sala_destino` int(11) DEFAULT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `id_componente_saliente` int(11) DEFAULT NULL,
  `id_componente_entrante` int(11) DEFAULT NULL,
  `origen` enum('QR_ANONIMO','PANEL_INTERNO','BECA_DIRECTO','TECNICO_DIRECTO') NOT NULL DEFAULT 'PANEL_INTERNO',
  `tipo_solicitud` enum('INCIDENTE','REQUERIMIENTO') NOT NULL DEFAULT 'INCIDENTE',
  `categoria` enum('HARDWARE','RED','SOFTWARE','IMPRESORA','PROYECTOR','INFRAESTRUCTURA','OTRO','SEGURIDAD') DEFAULT NULL,
  `ip_origen` varchar(45) DEFAULT NULL,
  `codigo_seguimiento` varchar(12) DEFAULT NULL,
  `radicado_datarsoft` varchar(40) DEFAULT NULL COMMENT 'Secretaría: número de radicado en DatArSoft (servicios que llegan de otras dependencias)',
  `nombre_contacto` varchar(100) DEFAULT NULL COMMENT 'QR sin sesión: nombre de quien reporta',
  `correo_contacto` varchar(150) DEFAULT NULL COMMENT 'QR sin sesión: correo institucional @ufps.edu.co para contactarlo',
  `nombre_dependencia` varchar(100) DEFAULT NULL,
  `ubicacion` varchar(100) DEFAULT NULL,
  `equipo` varchar(100) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `marca_modelo` varchar(100) DEFAULT NULL,
  `numero_inventario` varchar(50) DEFAULT NULL,
  `solucion` text DEFAULT NULL,
  `estado` enum('En espera','En proceso','Solucionado','Listo pero sin validar','Aprobado','Rechazado','Cerrado','Descartado') DEFAULT 'En espera',
  `firmado` tinyint(1) DEFAULT 0,
  `traslado_aprobado` tinyint(1) NOT NULL DEFAULT 0,
  `reubicado` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_terminacion` datetime DEFAULT NULL,
  `fecha_firmado` datetime DEFAULT NULL,
  `sugerido_mantenimiento_general` tinyint(1) NOT NULL DEFAULT 0,
  `tabla_ticket_relacionado` enum('tickets','tickets_profesor') DEFAULT NULL,
  `id_ticket_relacionado` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_seguimiento` (`codigo_seguimiento`),
  KEY `user_id` (`user_id`),
  KEY `tecnico_id` (`tecnico_id`),
  KEY `beca_id` (`beca_id`),
  KEY `fk_tickets_sala` (`id_sala`),
  KEY `fk_tickets_equipo` (`id_equipo`),
  KEY `fk_ticket_comp_saliente` (`id_componente_saliente`),
  KEY `fk_ticket_comp_entrante` (`id_componente_entrante`),
  KEY `fk_tickets_sala_destino` (`id_sala_destino`),
  KEY `idx_tickets_ticket_relacionado` (`tabla_ticket_relacionado`,`id_ticket_relacionado`),
  KEY `idx_tickets_firmado_fecha` (`firmado`,`fecha_creacion`),
  CONSTRAINT `fk_ticket_comp_entrante` FOREIGN KEY (`id_componente_entrante`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `fk_ticket_comp_saliente` FOREIGN KEY (`id_componente_saliente`) REFERENCES `componentes` (`id_componente`),
  CONSTRAINT `fk_tickets_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `fk_tickets_sala` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `fk_tickets_sala_destino` FOREIGN KEY (`id_sala_destino`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `tickets_fk_beca` FOREIGN KEY (`beca_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`tecnico_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `tickets_profesor` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profesor_id` int(11) NOT NULL,
  `tipo_ticket` enum('requerimiento','danio') NOT NULL,
  `origen` enum('PANEL_PROFESOR','QR_PROFESOR') NOT NULL DEFAULT 'PANEL_PROFESOR',
  `categoria` enum('HARDWARE','RED','SOFTWARE','IMPRESORA','PROYECTOR','INFRAESTRUCTURA','OTRO','SEGURIDAD') DEFAULT NULL,
  `subtipo_requerimiento` enum('CAMBIO_SALA','INSTALACION_SOFTWARE','PRESTAMO_SALA','PRESTAMO_EQUIPO') DEFAULT NULL,
  `id_sala` int(11) DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` enum('En espera','En proceso','Solucionado','Listo pero sin validar','Aprobado','Rechazado','Cerrado','Descartado') DEFAULT 'En espera',
  `programa` varchar(150) DEFAULT NULL,
  `sala_requerimiento` varchar(100) DEFAULT NULL,
  `id_horario_referencia` int(11) DEFAULT NULL,
  `id_sala_actual` int(11) DEFAULT NULL,
  `sala_actual_nombre` varchar(150) DEFAULT NULL,
  `fecha_requerida` date DEFAULT NULL,
  `fecha_devolucion_prevista` date DEFAULT NULL,
  `devuelto` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_devuelto` datetime DEFAULT NULL,
  `descripcion_actividad` text DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `software` varchar(150) DEFAULT NULL,
  `descripcion_software` text DEFAULT NULL,
  `cantidad_alumnos` smallint(5) unsigned DEFAULT NULL,
  `para_que` text DEFAULT NULL,
  `duracion_prestamo` enum('1_HORA','2_HORAS','TODO_DIA') DEFAULT NULL,
  `sala_danio` varchar(100) DEFAULT NULL,
  `salon` varchar(100) DEFAULT NULL,
  `descripcion_problema` text DEFAULT NULL,
  `solucion` text DEFAULT NULL COMMENT 'explicación del trabajo realizado (obligatoria al marcar Solucionado)',
  `fecha_solucion` datetime DEFAULT NULL,
  `tecnico_id` int(11) DEFAULT NULL,
  `beca_id` int(11) DEFAULT NULL,
  `firmado` tinyint(1) DEFAULT 0,
  `fecha_firmado` datetime DEFAULT NULL,
  `traslado_aprobado` tinyint(1) NOT NULL DEFAULT 0,
  `reubicado` tinyint(1) NOT NULL DEFAULT 0,
  `sugerido_mantenimiento_general` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `profesor_id` (`profesor_id`),
  KEY `tecnico_id` (`tecnico_id`),
  KEY `beca_id` (`beca_id`),
  KEY `fk_ticketsprof_horario` (`id_horario_referencia`),
  KEY `fk_ticketsprofesor_sala` (`id_sala`),
  KEY `fk_ticketsprof_sala_actual` (`id_sala_actual`),
  KEY `idx_ticketsprof_firmado_fecha` (`firmado`,`fecha_creacion`),
  CONSTRAINT `fk_ticketsprof_horario` FOREIGN KEY (`id_horario_referencia`) REFERENCES `horarios` (`id_horario`),
  CONSTRAINT `fk_ticketsprof_sala_actual` FOREIGN KEY (`id_sala_actual`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `fk_ticketsprofesor_sala` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `tickets_profesor_fk_beca` FOREIGN KEY (`beca_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_profesor_ibfk_1` FOREIGN KEY (`profesor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_profesor_ibfk_2` FOREIGN KEY (`tecnico_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `tipos_relacion_activo` (
  `id_tipo_relacion` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id_tipo_relacion`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `unidades_organizacionales` (
  `id_unidad` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `tipo` enum('DEPARTAMENTO','DEPENDENCIA','OFICINA','PLAN_ESTUDIOS') NOT NULL,
  `id_unidad_padre` int(11) DEFAULT NULL,
  `es_administradora_sistema` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_unidad`),
  UNIQUE KEY `uq_unidad_padre_nombre` (`id_unidad_padre`,`nombre`),
  CONSTRAINT `unidades_organizacionales_ibfk_1` FOREIGN KEY (`id_unidad_padre`) REFERENCES `unidades_organizacionales` (`id_unidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) DEFAULT NULL,
  `correo` varchar(100) NOT NULL,
  `notificar_por_correo` tinyint(1) NOT NULL DEFAULT 0,
  `password` varchar(255) NOT NULL,
  `password_temporal` tinyint(1) NOT NULL DEFAULT 0,
  `role` enum('solicitante','tecnico','admin','profesor','beca_trabajo','secretaria','vigilante','visualizacion') NOT NULL,
  `permiso_ver_tickets_sistemas` tinyint(1) NOT NULL DEFAULT 0,
  `permiso_horarios_extra` tinyint(1) NOT NULL DEFAULT 0,
  `permiso_ver_horas_becas` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'El admin lo activa: puede ver las horas de TODAS las becas trabajo (cualquier rol menos admin, que ya las ve)',
  `permiso_ver_tickets_externos` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'El admin lo activa: el técnico ve y atiende tickets de salas/dependencias fuera del Departamento de Sistemas',
  `estado_activo` tinyint(1) NOT NULL DEFAULT 1,
  `nombre_completo` varchar(100) NOT NULL,
  `cargo` varchar(50) NOT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL COMMENT 'Nombre del archivo de foto de perfil en assets/uploads/perfiles/, NULL = sin foto (avatar con iniciales)',
  `dependencia` varchar(100) DEFAULT NULL,
  `id_unidad` int(11) DEFAULT NULL,
  `area_beca` enum('mantenimiento','secretaria','redes') DEFAULT NULL COMMENT 'Solo beca_trabajo: área asignada por el admin (NULL = mantenimiento)',
  `mfa_activo` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Verificación en dos pasos (TOTP) activa',
  `mfa_secreto` varchar(255) DEFAULT NULL COMMENT 'Secreto TOTP cifrado (AES-256-GCM)',
  `mfa_recuperacion` text DEFAULT NULL COMMENT 'Hashes de los códigos de recuperación (JSON)',
  `mfa_activado_en` datetime DEFAULT NULL,
  `online` tinyint(1) DEFAULT 0,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `correo` (`correo`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `fk_users_unidad` (`id_unidad`),
  CONSTRAINT `fk_users_unidad` FOREIGN KEY (`id_unidad`) REFERENCES `unidades_organizacionales` (`id_unidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Conexiones externas que configura el admin en «Conexiones» (asistencia facial en PostgreSQL, GLPI por BD y por API).
-- `datos` es JSON; las contraseñas/tokens van cifrados (AES-256-GCM, clave en storage/clave_conexiones.key).
CREATE TABLE IF NOT EXISTS `conexiones_externas` (
  `clave` varchar(30) NOT NULL,
  `datos` text NOT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  `actualizado_por` int(11) DEFAULT NULL,
  `prueba_ok` tinyint(1) DEFAULT NULL,
  `prueba_en` datetime DEFAULT NULL,
  `prueba_mensaje` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Cola de envíos a GLPI (por API): altas de componentes y cambios de ubicación ya aprobados por el técnico.
CREATE TABLE IF NOT EXISTS `glpi_cola` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('CREAR_COMPONENTE','CAMBIO_UBICACION') NOT NULL,
  `id_componente` int(11) NOT NULL,
  `id_sala_destino` int(11) DEFAULT NULL COMMENT 'CAMBIO_UBICACION: sala destino (NULL = bodega/oficina de mantenimiento)',
  `id_movimiento` int(11) DEFAULT NULL,
  `estado` enum('PENDIENTE','ENVIADO','ERROR') NOT NULL DEFAULT 'PENDIENTE',
  `intentos` int(11) NOT NULL DEFAULT 0,
  `ultimo_error` varchar(255) DEFAULT NULL,
  `glpi_itemtype` varchar(40) DEFAULT NULL,
  `glpi_id` int(11) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `creado_en` timestamp NULL DEFAULT current_timestamp(),
  `enviado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_componente` (`id_componente`),
  CONSTRAINT `fk_glpi_cola_componente` FOREIGN KEY (`id_componente`) REFERENCES `componentes` (`id_componente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `versiones_software` (
  `id_version` int(11) NOT NULL AUTO_INCREMENT,
  `id_software` int(11) NOT NULL,
  `version` varchar(50) NOT NULL,
  PRIMARY KEY (`id_version`),
  UNIQUE KEY `uq_software_version` (`id_software`,`version`),
  CONSTRAINT `versiones_software_ibfk_1` FOREIGN KEY (`id_software`) REFERENCES `software` (`id_software`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================== SEGURIDAD DE ACCESO =====================
-- Bloqueo por intentos fallidos de login (3 intentos). Una fila por usuario.
CREATE TABLE IF NOT EXISTS `usuario_bloqueo` (
  `id_usuario` int(11) NOT NULL,
  `intentos_fallidos` int(11) NOT NULL DEFAULT 0,
  `bloqueado` tinyint(1) NOT NULL DEFAULT 0,
  `bloqueado_en` datetime DEFAULT NULL,
  `ultimo_intento` datetime DEFAULT NULL,
  `ultima_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id_usuario`),
  CONSTRAINT `fk_bloqueo_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Enlaces de recuperación de clave por correo (de un solo uso; se guarda el hash, nunca el token).
CREATE TABLE IF NOT EXISTS `recuperacion_clave` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expira_en` datetime NOT NULL,
  `usado_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recuperacion_token` (`token_hash`),
  KEY `idx_recuperacion_usuario` (`id_usuario`,`creado_en`),
  CONSTRAINT `fk_recuperacion_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Vínculo cuenta del sistema <-> cuenta de Google (inicio de sesión con Google, futuro).
CREATE TABLE IF NOT EXISTS `usuario_google` (
  `id_usuario` int(11) NOT NULL,
  `google_sub` varchar(64) NOT NULL,
  `correo_google` varchar(150) DEFAULT NULL,
  `vinculado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `uq_google_sub` (`google_sub`),
  CONSTRAINT `fk_google_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================== CALIDAD DEL SERVICIO (SLA) Y SEGURIDAD =====================

-- Parámetros del sistema que cambia el admin (horario hábil, umbrales, seguridad). Clave → valor.
CREATE TABLE IF NOT EXISTS `config_sistema` (
  `clave` varchar(60) NOT NULL,
  `valor` text DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  `actualizado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Acuerdos de nivel de servicio: horas HÁBILES de primera respuesta y de solución por tipo y prioridad.
CREATE TABLE IF NOT EXISTS `sla_politicas` (
  `id_politica` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('INCIDENTE','REQUERIMIENTO') NOT NULL,
  `prioridad` enum('CRITICA','ALTA','MEDIA','BAJA') NOT NULL,
  `horas_respuesta` decimal(6,2) NOT NULL,
  `horas_solucion` decimal(6,2) NOT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id_politica`),
  UNIQUE KEY `uq_sla_tipo_prioridad` (`tipo`,`prioridad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Días no hábiles (festivos de Colombia y cierres de la universidad): no cuentan para el SLA.
CREATE TABLE IF NOT EXISTS `dias_festivos` (
  `fecha` date NOT NULL,
  `descripcion` varchar(120) NOT NULL,
  PRIMARY KEY (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de servicio de cada ticket (de ambas tablas): prioridad (impacto × urgencia) y marcas de tiempo.
CREATE TABLE IF NOT EXISTS `ticket_sla` (
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `tipo` enum('INCIDENTE','REQUERIMIENTO') NOT NULL DEFAULT 'INCIDENTE',
  `impacto` tinyint(1) NOT NULL DEFAULT 2 COMMENT '1 alto · 2 medio · 3 bajo',
  `urgencia` tinyint(1) NOT NULL DEFAULT 2 COMMENT '1 alta · 2 media · 3 baja',
  `prioridad` enum('CRITICA','ALTA','MEDIA','BAJA') NOT NULL DEFAULT 'MEDIA',
  `prioridad_manual` tinyint(1) NOT NULL DEFAULT 0,
  `id_ajusto` int(11) DEFAULT NULL,
  `fecha_primera_respuesta` datetime DEFAULT NULL,
  `fecha_primera_solucion` datetime DEFAULT NULL,
  `fecha_solucion` datetime DEFAULT NULL,
  `reaperturas` smallint(5) unsigned NOT NULL DEFAULT 0,
  `aviso_por_vencer` tinyint(1) NOT NULL DEFAULT 0,
  `aviso_vencido` tinyint(1) NOT NULL DEFAULT 0,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`tabla_ticket`,`id_ticket`),
  KEY `idx_ticket_sla_prioridad` (`prioridad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cada cambio de estado de un ticket con su hora exacta (lo llenan los disparadores del final del archivo).
CREATE TABLE IF NOT EXISTS `ticket_eventos` (
  `id_evento` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `estado_anterior` varchar(40) DEFAULT NULL,
  `estado_nuevo` varchar(40) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_evento`),
  KEY `idx_ticket_eventos_ticket` (`tabla_ticket`,`id_ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pausas del reloj del SLA («en espera del usuario»): el tiempo pausado no cuenta.
CREATE TABLE IF NOT EXISTS `ticket_pausas` (
  `id_pausa` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `inicio` datetime NOT NULL,
  `fin` datetime DEFAULT NULL,
  `motivo` varchar(255) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_pausa`),
  KEY `idx_ticket_pausas_ticket` (`tabla_ticket`,`id_ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Encuesta de satisfacción (1 a 5) al cerrar: desde el panel o con el código de seguimiento del QR.
CREATE TABLE IF NOT EXISTS `encuestas_satisfaccion` (
  `id_encuesta` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `calificacion` tinyint(1) NOT NULL,
  `comentario` varchar(500) DEFAULT NULL,
  `canal` enum('PANEL','CODIGO') NOT NULL DEFAULT 'PANEL',
  `id_usuario` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_encuesta`),
  UNIQUE KEY `uq_encuesta_ticket` (`tabla_ticket`,`id_ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bitácora de accesos (ISO 27001 A.8.15): solo se agrega; cada fila lleva el hash de la anterior (cadena).
CREATE TABLE IF NOT EXISTS `bitacora_accesos` (
  `id_bitacora` bigint(20) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL,
  `evento` varchar(40) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `agente` varchar(255) DEFAULT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `hash_anterior` char(64) NOT NULL,
  `hash` char(64) NOT NULL,
  PRIMARY KEY (`id_bitacora`),
  KEY `idx_bitacora_fecha` (`fecha`),
  KEY `idx_bitacora_evento` (`evento`),
  KEY `idx_bitacora_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Respaldos cifrados generados desde «Seguridad → Respaldos» (el archivo vive en storage/respaldos/).
CREATE TABLE IF NOT EXISTS `respaldos` (
  `id_respaldo` int(11) NOT NULL AUTO_INCREMENT,
  `archivo` varchar(200) NOT NULL,
  `tamano` bigint(20) NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL,
  `tablas` int(11) NOT NULL DEFAULT 0,
  `filas` int(11) NOT NULL DEFAULT 0,
  `adjuntos` int(11) NOT NULL DEFAULT 0,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `verificado_en` datetime DEFAULT NULL,
  `verificacion_ok` tinyint(1) DEFAULT NULL,
  `verificacion_detalle` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_respaldo`),
  UNIQUE KEY `uq_respaldo_archivo` (`archivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Registro de incidentes de seguridad de la información (ISO 27001 A.5.24–A.5.28).
CREATE TABLE IF NOT EXISTS `incidentes_seguridad` (
  `id_incidente` int(11) NOT NULL AUTO_INCREMENT,
  `tabla_ticket` enum('tickets','tickets_profesor') DEFAULT NULL,
  `id_ticket` int(11) DEFAULT NULL,
  `titulo` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `tipo` enum('ACCESO_NO_AUTORIZADO','MALWARE','PHISHING','FUGA_INFORMACION','PERDIDA_EQUIPO','DENEGACION_SERVICIO','OTRO') NOT NULL DEFAULT 'OTRO',
  `severidad` enum('CRITICA','ALTA','MEDIA','BAJA') NOT NULL DEFAULT 'ALTA',
  `afecta_confidencialidad` tinyint(1) NOT NULL DEFAULT 0,
  `afecta_integridad` tinyint(1) NOT NULL DEFAULT 0,
  `afecta_disponibilidad` tinyint(1) NOT NULL DEFAULT 0,
  `estado` enum('REPORTADO','EN_ANALISIS','CONTENIDO','CERRADO') NOT NULL DEFAULT 'REPORTADO',
  `contencion` text DEFAULT NULL,
  `causa` text DEFAULT NULL,
  `leccion` text DEFAULT NULL,
  `id_reporto` int(11) DEFAULT NULL,
  `id_responsable` int(11) DEFAULT NULL,
  `fecha_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_contencion` datetime DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  PRIMARY KEY (`id_incidente`),
  UNIQUE KEY `uq_incidente_ticket` (`tabla_ticket`,`id_ticket`),
  KEY `idx_incidente_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================== CATALOGOS BASE =====================

INSERT IGNORE INTO `categorias_activo` VALUES
(1,'Torre',NULL,'HARDWARE'),
(2,'Monitor',NULL,'HARDWARE'),
(3,'Teclado',NULL,'HARDWARE'),
(4,'Mouse',NULL,'HARDWARE'),
(5,'Parlante',NULL,'HARDWARE'),
(6,'Otro',NULL,'HARDWARE'),
(7,'Videobeam',NULL,'HARDWARE'),
(8,'Portátil',NULL,'HARDWARE');

INSERT IGNORE INTO `tipos_relacion_activo` VALUES
(1,'CONTIENE','El activo origen contiene físicamente al destino (ej. torre contiene RAM)'),
(2,'CONECTADO_A','El activo origen está conectado/cableado al destino (ej. monitor a torre)'),
(3,'REPUESTO_DE','El activo destino es repuesto/backup del origen'),
(4,'PAR_DE','Ambos activos forman un kit/combo (ej. teclado y mouse comprados juntos)');

INSERT IGNORE INTO `unidades_organizacionales` VALUES
(1,'Departamento de Sistemas e Informatica','DEPARTAMENTO',NULL,1,'2026-09-05 23:23:31');

-- Los tipos de infraestructura (aire, iluminación, tomas, sillas) que usa ficha_equipo/ficha_componente no
-- existían en el enum y «agregar a la sala» siempre fallaba. Idempotente: ampliar el enum es seguro en BD
-- existentes.
ALTER TABLE `componentes` MODIFY COLUMN `tipo` enum('torre','monitor','teclado','mouse','parlante','videobeam','portatil','todo_en_uno','otro','aire_acondicionado','iluminacion','toma_electrica','silla','otro_infraestructura') NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ===================== ACTUALIZACION DE BASES EXISTENTES =====================
-- Seguimiento de horas: permiso que el admin habilita a cualquier rol para ver las horas de todas las becas.
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'permiso_ver_horas_becas');
SET @sql_col := IF(@existe_col = 0,
    'ALTER TABLE `users` ADD COLUMN `permiso_ver_horas_becas` tinyint(1) NOT NULL DEFAULT 0 AFTER `permiso_horarios_extra`',
    'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- Conexiones / GLPI: vínculo de componentes y salas con GLPI.
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes' AND COLUMN_NAME = 'glpi_itemtype');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `componentes` ADD COLUMN `glpi_itemtype` varchar(40) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes' AND COLUMN_NAME = 'glpi_id');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `componentes` ADD COLUMN `glpi_id` int(11) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salas' AND COLUMN_NAME = 'glpi_location_id');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `salas` ADD COLUMN `glpi_location_id` int(11) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- Área de la beca trabajo (mantenimiento / secretaría / redes), la asigna el admin en Usuarios.
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'area_beca');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `users` ADD COLUMN `area_beca` enum(''mantenimiento'',''secretaria'',''redes'') DEFAULT NULL AFTER `id_unidad`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- ===================== CALIDAD DEL SERVICIO Y SEGURIDAD =====================
-- Acuerdos de servicio iniciales (horas hábiles). El admin los cambia en «Calidad del servicio → Acuerdos (SLA)».
INSERT IGNORE INTO `sla_politicas` (`tipo`, `prioridad`, `horas_respuesta`, `horas_solucion`) VALUES
('INCIDENTE','CRITICA',0.50,4.00),
('INCIDENTE','ALTA',1.00,8.00),
('INCIDENTE','MEDIA',4.00,24.00),
('INCIDENTE','BAJA',8.00,40.00),
('REQUERIMIENTO','CRITICA',1.00,8.00),
('REQUERIMIENTO','ALTA',4.00,16.00),
('REQUERIMIENTO','MEDIA',8.00,40.00),
('REQUERIMIENTO','BAJA',16.00,80.00);

-- Categoría «Incidente de seguridad» (ampliar el enum es seguro en bases existentes).
ALTER TABLE `tickets` MODIFY COLUMN `categoria` enum('HARDWARE','RED','SOFTWARE','IMPRESORA','PROYECTOR','INFRAESTRUCTURA','OTRO','SEGURIDAD') DEFAULT NULL;
ALTER TABLE `tickets_profesor` MODIFY COLUMN `categoria` enum('HARDWARE','RED','SOFTWARE','IMPRESORA','PROYECTOR','INFRAESTRUCTURA','OTRO','SEGURIDAD') DEFAULT NULL;

-- Verificación en dos pasos (admin y técnico): columnas nuevas en users, solo si faltan.
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_activo');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_activo` tinyint(1) NOT NULL DEFAULT 0 AFTER `area_beca`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_secreto');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_secreto` varchar(255) DEFAULT NULL AFTER `mfa_activo`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_recuperacion');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_recuperacion` text DEFAULT NULL AFTER `mfa_secreto`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_activado_en');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_activado_en` datetime DEFAULT NULL AFTER `mfa_recuperacion`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- Disparadores: guardan la hora exacta de cada cambio de estado de un ticket (base de los indicadores
-- de primera respuesta, solución y reapertura). Una sola instrucción cada uno: no necesitan DELIMITER.
CREATE TRIGGER IF NOT EXISTS `trg_tickets_evento_alta` AFTER INSERT ON `tickets` FOR EACH ROW
  INSERT INTO `ticket_eventos` (`tabla_ticket`, `id_ticket`, `estado_anterior`, `estado_nuevo`, `fecha`) VALUES ('tickets', NEW.`id`, NULL, NEW.`estado`, NOW());
CREATE TRIGGER IF NOT EXISTS `trg_tickets_evento_estado` AFTER UPDATE ON `tickets` FOR EACH ROW
  INSERT INTO `ticket_eventos` (`tabla_ticket`, `id_ticket`, `estado_anterior`, `estado_nuevo`, `fecha`)
  SELECT 'tickets', NEW.`id`, OLD.`estado`, NEW.`estado`, NOW() FROM DUAL WHERE NOT (OLD.`estado` <=> NEW.`estado`);
CREATE TRIGGER IF NOT EXISTS `trg_tickets_profesor_evento_alta` AFTER INSERT ON `tickets_profesor` FOR EACH ROW
  INSERT INTO `ticket_eventos` (`tabla_ticket`, `id_ticket`, `estado_anterior`, `estado_nuevo`, `fecha`) VALUES ('tickets_profesor', NEW.`id`, NULL, NEW.`estado`, NOW());
CREATE TRIGGER IF NOT EXISTS `trg_tickets_profesor_evento_estado` AFTER UPDATE ON `tickets_profesor` FOR EACH ROW
  INSERT INTO `ticket_eventos` (`tabla_ticket`, `id_ticket`, `estado_anterior`, `estado_nuevo`, `fecha`)
  SELECT 'tickets_profesor', NEW.`id`, OLD.`estado`, NEW.`estado`, NOW() FROM DUAL WHERE NOT (OLD.`estado` <=> NEW.`estado`);

-- ===================== ROLES VIGILANTE Y VISUALIZACIÓN · CONTACTO QR · ARCHIVO SEMESTRAL =====================
-- vigilante: guardia de seguridad, solo ve los horarios de las salas y los mantenimientos (para no prestar la sala).
-- visualizacion: cuenta para el televisor de la oficina de mantenimiento (pantalla.php, se actualiza sola).
-- Ampliar el enum es seguro en bases existentes.
ALTER TABLE `users` MODIFY COLUMN `role` enum('solicitante','tecnico','admin','profesor','beca_trabajo','secretaria','vigilante','visualizacion') NOT NULL;

-- Reporte por QR sin sesión: nombre y correo institucional de quien reporta (para poder contactarlo).
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'nombre_contacto');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `tickets` ADD COLUMN `nombre_contacto` varchar(100) DEFAULT NULL AFTER `codigo_seguimiento`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'correo_contacto');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `tickets` ADD COLUMN `correo_contacto` varchar(150) DEFAULT NULL AFTER `nombre_contacto`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- Historial de componentes: conserva el vínculo con el ticket aunque el ticket se archive.
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historial_componente' AND COLUMN_NAME = 'id_ticket_archivado');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `historial_componente` ADD COLUMN `id_ticket_archivado` int(11) DEFAULT NULL AFTER `id_ticket`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- Cierre de semestre: cada vez que el admin decide archivar queda un lote.
CREATE TABLE IF NOT EXISTS `archivo_lotes` (
  `id_lote` int(11) NOT NULL AUTO_INCREMENT,
  `periodo` varchar(6) NOT NULL COMMENT 'AAAA-N (1 = ene-jun, 2 = jul-dic)',
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `total_tickets` int(11) NOT NULL DEFAULT 0,
  `total_profesor` int(11) NOT NULL DEFAULT 0,
  `adjuntos_borrados` int(11) NOT NULL DEFAULT 0,
  `bytes_liberados` bigint(20) NOT NULL DEFAULT 0,
  `id_admin` int(11) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_lote`),
  KEY `idx_lote_periodo` (`periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Resumen compacto de cada ticket archivado (solo lo relevante). Los tickets SIN contestar o SIN solucionar nunca se archivan.
CREATE TABLE IF NOT EXISTS `tickets_archivo` (
  `id_archivo` int(11) NOT NULL AUTO_INCREMENT,
  `id_lote` int(11) NOT NULL,
  `periodo` varchar(6) NOT NULL,
  `tabla_origen` enum('tickets','tickets_profesor') NOT NULL,
  `id_original` int(11) NOT NULL,
  `tipo` varchar(40) DEFAULT NULL,
  `origen` varchar(30) DEFAULT NULL,
  `categoria` varchar(20) DEFAULT NULL,
  `estado_final` varchar(30) NOT NULL,
  `id_sala` int(11) DEFAULT NULL,
  `sala_nombre` varchar(150) DEFAULT NULL,
  `solicitante` varchar(150) DEFAULT NULL,
  `correo_contacto` varchar(150) DEFAULT NULL,
  `tecnico` varchar(100) DEFAULT NULL,
  `beca` varchar(100) DEFAULT NULL,
  `descripcion` varchar(1000) DEFAULT NULL,
  `solucion` varchar(1000) DEFAULT NULL,
  `equipos_afectados` varchar(500) DEFAULT NULL,
  `prioridad` varchar(10) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL,
  `fecha_primera_respuesta` datetime DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  `minutos_resolucion` int(11) DEFAULT NULL,
  `reaperturas` smallint(5) unsigned NOT NULL DEFAULT 0,
  `calificacion` tinyint(1) DEFAULT NULL,
  `total_comentarios` smallint(5) unsigned NOT NULL DEFAULT 0,
  `total_adjuntos` smallint(5) unsigned NOT NULL DEFAULT 0,
  `id_programado_relacionado` int(11) DEFAULT NULL,
  `codigo_seguimiento` varchar(12) DEFAULT NULL,
  `archivado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_archivo`),
  UNIQUE KEY `uq_archivo_original` (`tabla_origen`,`id_original`),
  KEY `idx_archivo_periodo` (`periodo`),
  KEY `idx_archivo_sala` (`id_sala`),
  KEY `idx_archivo_codigo` (`codigo_seguimiento`),
  KEY `idx_archivo_lote` (`id_lote`),
  CONSTRAINT `fk_archivo_lote` FOREIGN KEY (`id_lote`) REFERENCES `archivo_lotes` (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Equipos/componentes de cada ticket archivado: la ficha del equipo sigue mostrando su historial.
CREATE TABLE IF NOT EXISTS `tickets_archivo_activos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_archivo` int(11) NOT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `id_componente` int(11) DEFAULT NULL,
  `tipo_afectacion` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_archivo_activo_archivo` (`id_archivo`),
  KEY `idx_archivo_activo_equipo` (`id_equipo`),
  KEY `idx_archivo_activo_componente` (`id_componente`),
  CONSTRAINT `fk_archivo_activo_archivo` FOREIGN KEY (`id_archivo`) REFERENCES `tickets_archivo` (`id_archivo`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================== SECRETARÍA: SALAS DE HORARIOS Y RADICADO DATARSOFT =====================
-- Salas cuyos horarios puede ver y editar cada secretaria con el permiso de horarios (las elige el admin).
-- Además siempre ve y edita los turnos de las becas asignadas a la secretaría (users.area_beca = 'secretaria').
CREATE TABLE IF NOT EXISTS `secretaria_salas` (
  `id_usuario` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `asignado_por` int(11) DEFAULT NULL,
  `asignado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_usuario`, `id_sala`),
  KEY `idx_secretaria_salas_sala` (`id_sala`),
  CONSTRAINT `fk_secretaria_salas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_secretaria_salas_sala` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tickets de la secretaría: número de radicado de DatArSoft (opcional).
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'radicado_datarsoft');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `tickets` ADD COLUMN `radicado_datarsoft` varchar(40) DEFAULT NULL AFTER `codigo_seguimiento`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- ===================== SIN CÉDULA =====================
-- La cédula ya no se pide ni se guarda (el usuario se identifica con su código institucional y su correo).
-- En bases existentes se elimina la columna (y con ella su índice único) solo si todavía existe.
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'cedula');
SET @sql_col := IF(@existe_col = 1, 'ALTER TABLE `users` DROP COLUMN `cedula`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- ===================== SOLUCIÓN, CONVERSACIÓN Y EVIDENCIA FOTOGRÁFICA =====================
-- tickets_profesor guarda la explicación de la solución. ticket_comentarios separa las notas internas del
-- chat con quien reportó (profesor / QR) y marca las reiteraciones; id_usuario queda opcional para quien
-- escribe sin sesión desde «Consultar estado». ticket_adjuntos marca qué archivos son fotos de evidencia del
-- trabajo (se imprimen en el PDF).
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets_profesor' AND COLUMN_NAME = 'solucion');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `tickets_profesor` ADD COLUMN `solucion` text DEFAULT NULL AFTER `descripcion_problema`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets_profesor' AND COLUMN_NAME = 'fecha_solucion');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `tickets_profesor` ADD COLUMN `fecha_solucion` datetime DEFAULT NULL AFTER `solucion`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_comentarios' AND COLUMN_NAME = 'autor_externo');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `ticket_comentarios` ADD COLUMN `autor_externo` varchar(150) DEFAULT NULL AFTER `id_usuario`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_comentarios' AND COLUMN_NAME = 'visibilidad');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `ticket_comentarios` ADD COLUMN `visibilidad` enum(''INTERNO'',''CONVERSACION'') NOT NULL DEFAULT ''INTERNO'' AFTER `autor_externo`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_comentarios' AND COLUMN_NAME = 'es_reiteracion');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `ticket_comentarios` ADD COLUMN `es_reiteracion` tinyint(1) NOT NULL DEFAULT 0 AFTER `visibilidad`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_adjuntos' AND COLUMN_NAME = 'categoria');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `ticket_adjuntos` ADD COLUMN `categoria` enum(''ADJUNTO'',''EVIDENCIA'') NOT NULL DEFAULT ''ADJUNTO'' AFTER `nombre_almacenado`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
ALTER TABLE `ticket_comentarios` MODIFY COLUMN `id_usuario` int(11) DEFAULT NULL;

-- ===================== SOLO DEPARTAMENTO DE SISTEMAS + TRASLADO A BODEGA DESDE LA CHECKLIST =====================
-- Los técnicos trabajan solo con los tickets del Departamento de Sistemas e Informática. Ver los de otras
-- dependencias es un permiso que el admin activa por usuario en «Usuarios» (por defecto apagado).
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'permiso_ver_tickets_externos');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `users` ADD COLUMN `permiso_ver_tickets_externos` tinyint(1) NOT NULL DEFAULT 0 AFTER `permiso_ver_horas_becas`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;
-- Checklist de mantenimiento: «Trasladar a la oficina de mantenimiento (bodega)» queda pendiente hasta que el técnico lo aprueba.
ALTER TABLE `mantenimiento_equipos` MODIFY COLUMN `estado` enum('PENDIENTE','TOMADO','REALIZADO','INCOMPLETO','EXCLUIDO_TRASLADO','TRASLADO_PENDIENTE') NOT NULL DEFAULT 'PENDIENTE';

-- Índices para que las consultas de inventario, plano de sala, bodega y buscadores NO recorran la tabla
-- completa cuando el inventario crece. No borra ni cambia datos: solo agrega índices y una columna VIRTUAL
-- (no ocupa espacio en disco). Idempotente: si el índice o la columna ya existen, no hace nada.

-- historial_componente: «última desasignación de cada pieza», historial por equipo y ranking de modelos.
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historial_componente' AND INDEX_NAME = 'idx_hist_tipo_comp');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `historial_componente` ADD INDEX `idx_hist_tipo_comp` (`tipo_evento`, `id_componente`, `id_historial`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historial_componente' AND INDEX_NAME = 'idx_hist_comp_tipo');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `historial_componente` ADD INDEX `idx_hist_comp_tipo` (`id_componente`, `tipo_evento`, `id_historial`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historial_componente' AND INDEX_NAME = 'idx_hist_equipo_anterior');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `historial_componente` ADD INDEX `idx_hist_equipo_anterior` (`id_equipo_anterior`, `tipo_evento`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'historial_componente' AND INDEX_NAME = 'idx_hist_equipo_nuevo');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `historial_componente` ADD INDEX `idx_hist_equipo_nuevo` (`id_equipo_nuevo`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;

-- componentes: listas de bodega por estado (más recientes primero) y piezas sueltas ordenadas por tipo/código.
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes' AND INDEX_NAME = 'idx_comp_estado_fecha');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `componentes` ADD INDEX `idx_comp_estado_fecha` (`estado`, `fecha_registro`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes' AND INDEX_NAME = 'idx_comp_bodega');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `componentes` ADD INDEX `idx_comp_bodega` (`id_equipo_actual`, `tipo`, `codigo_inventario`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;

-- componentes: búsqueda por el FINAL del código de inventario (ej. «0231» encuentra «UFPS-00000231») usando
-- índice: se indexa el código al revés en una columna VIRTUAL (se calcula sola, no hay que mantenerla).
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes' AND COLUMN_NAME = 'codigo_inventario_rev');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `componentes` ADD COLUMN `codigo_inventario_rev` varchar(30) GENERATED ALWAYS AS (REVERSE(`codigo_inventario`)) VIRTUAL', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;
SET @existe_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes' AND INDEX_NAME = 'idx_comp_codigo_rev');
SET @sql_idx := IF(@existe_idx = 0, 'ALTER TABLE `componentes` ADD INDEX `idx_comp_codigo_rev` (`codigo_inventario_rev`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;

ANALYZE TABLE `componentes`, `historial_componente`;

-- ===================== MÓDULO DE MANTENIMIENTO (programación por sala, campañas, historial y análisis) =====================
-- Reutiliza salas, equipos, tickets, horarios y préstamos: no duplica nada, solo relaciona por id.
-- Idempotente como el resto del archivo (CREATE IF NOT EXISTS / columnas solo si faltan / ampliar enum).

-- Estados del ciclo: PENDIENTE = espera a la sala anterior de una campaña secuencial (bloqueada);
-- REPROGRAMADO = se movió de fecha (el historial del cambio queda en mantenimiento_reprogramaciones).
ALTER TABLE `mantenimientos_programados` MODIFY COLUMN `estado` enum('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO','REALIZADO','CANCELADO') NOT NULL DEFAULT 'PROGRAMADO';

SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mantenimientos_programados' AND COLUMN_NAME = 'duracion_minutos');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `mantenimientos_programados` ADD COLUMN `duracion_minutos` smallint(5) unsigned NOT NULL DEFAULT 60 COMMENT ''Duración estimada; ocupa la sala en el calendario'' AFTER `fecha_programada`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mantenimientos_programados' AND COLUMN_NAME = 'actividades');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `mantenimientos_programados` ADD COLUMN `actividades` text DEFAULT NULL COMMENT ''Actividades planeadas, una por línea'' AFTER `descripcion`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mantenimientos_programados' AND COLUMN_NAME = 'resultado');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `mantenimientos_programados` ADD COLUMN `resultado` enum(''RESUELTO'',''PARCIAL'',''NO_RESUELTO'') DEFAULT NULL COMMENT ''Resultado obtenido al cerrar'' AFTER `observaciones_cierre`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mantenimientos_programados' AND COLUMN_NAME = 'id_campana');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `mantenimientos_programados` ADD COLUMN `id_campana` int(11) DEFAULT NULL, ADD COLUMN `orden_campana` smallint(5) unsigned DEFAULT NULL, ADD KEY `idx_mant_campana` (`id_campana`, `orden_campana`)', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;

-- Bloque/edificio de la sala (filtro del historial). Opcional: se llena en «Salas y QR».
SET @existe_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salas' AND COLUMN_NAME = 'bloque');
SET @sql_col := IF(@existe_col = 0, 'ALTER TABLE `salas` ADD COLUMN `bloque` varchar(40) DEFAULT NULL AFTER `dependencia`', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;

-- Campaña: una actividad general (actualizar un programa, revisión preventiva…) en varias salas.
-- Genera una intervención (mantenimientos_programados) por sala, con orden_campana para la secuencia.
CREATE TABLE IF NOT EXISTS `mantenimiento_campanas` (
  `id_campana` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `tipo` enum('MANTENIMIENTO_PREVENTIVO','INSTALACION_SOFTWARE','OTRO') NOT NULL,
  `alcance_equipo` enum('TODOS','COMPUTO','VIDEOBEAM') NOT NULL DEFAULT 'TODOS',
  `descripcion` text NOT NULL,
  `actividades` text DEFAULT NULL,
  `enlace_referencia` varchar(500) DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL,
  `duracion_minutos` smallint(5) unsigned NOT NULL DEFAULT 60,
  `secuencial` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = finalizar una sala antes de continuar con la siguiente',
  `id_responsable` int(11) DEFAULT NULL,
  `id_creador` int(11) NOT NULL,
  `estado` enum('ACTIVA','FINALIZADA','CANCELADA') NOT NULL DEFAULT 'ACTIVA',
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_cierre` datetime DEFAULT NULL,
  PRIMARY KEY (`id_campana`),
  KEY `idx_campana_estado` (`estado`),
  CONSTRAINT `fk_campana_responsable` FOREIGN KEY (`id_responsable`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_campana_creador` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reportes (daño, QR, solicitudes de profesor) que se atienden en un mantenimiento además del ticket de origen.
-- Polimórfica como ticket_activos (tabla_ticket + id_ticket); un ticket solo se atiende en un mantenimiento.
CREATE TABLE IF NOT EXISTS `mantenimiento_vinculos` (
  `id_programado` int(11) NOT NULL,
  `tabla_ticket` enum('tickets','tickets_profesor') NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_programado`, `tabla_ticket`, `id_ticket`),
  UNIQUE KEY `uq_vinculo_ticket` (`tabla_ticket`, `id_ticket`),
  CONSTRAINT `fk_vinculo_programado` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`) ON DELETE CASCADE,
  CONSTRAINT `fk_vinculo_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Historial de cada cambio de fecha (manual, por conflicto con un préstamo/clase, o por la secuencia de una campaña).
CREATE TABLE IF NOT EXISTS `mantenimiento_reprogramaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_programado` int(11) NOT NULL,
  `fecha_anterior` datetime NOT NULL,
  `fecha_nueva` datetime NOT NULL,
  `tipo` enum('MANUAL','CONFLICTO','SECUENCIA') NOT NULL DEFAULT 'MANUAL',
  `motivo` varchar(255) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reprog_programado` (`id_programado`),
  CONSTRAINT `fk_reprog_programado` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`) ON DELETE CASCADE,
  CONSTRAINT `fk_reprog_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Acciones correctivas que salen del análisis de fin de semestre; se convierten en mantenimientos del siguiente.
CREATE TABLE IF NOT EXISTS `mantenimiento_acciones` (
  `id_accion` int(11) NOT NULL AUTO_INCREMENT,
  `periodo_origen` varchar(6) NOT NULL COMMENT 'Semestre analizado (AAAA-N)',
  `periodo_destino` varchar(6) NOT NULL COMMENT 'Semestre en que se ejecuta',
  `id_sala` int(11) DEFAULT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `tipo_accion` enum('REEMPLAZAR_EQUIPO','ESTANDARIZAR_SOFTWARE','REVISAR_CONFIGURACION','PREVENTIVO','OTRA') NOT NULL,
  `causa` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `prioridad` enum('ALTA','MEDIA','BAJA') NOT NULL DEFAULT 'MEDIA',
  `id_responsable` int(11) DEFAULT NULL,
  `fecha_objetivo` date NOT NULL,
  `estado` enum('PENDIENTE','PROGRAMADA','HECHA','DESCARTADA') NOT NULL DEFAULT 'PENDIENTE',
  `id_programado` int(11) DEFAULT NULL COMMENT 'Mantenimiento creado a partir de la acción',
  `id_creador` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_accion`),
  KEY `idx_accion_periodo` (`periodo_destino`, `estado`),
  CONSTRAINT `fk_accion_sala` FOREIGN KEY (`id_sala`) REFERENCES `salas` (`id_sala`),
  CONSTRAINT `fk_accion_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id_equipo`),
  CONSTRAINT `fk_accion_responsable` FOREIGN KEY (`id_responsable`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_accion_programado` FOREIGN KEY (`id_programado`) REFERENCES `mantenimientos_programados` (`id_programado`) ON DELETE SET NULL,
  CONSTRAINT `fk_accion_creador` FOREIGN KEY (`id_creador`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- FK de la campaña (después de crear la tabla). Solo si falta.
SET @existe_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mantenimientos_programados' AND CONSTRAINT_NAME = 'fk_mant_campana');
SET @sql_fk := IF(@existe_fk = 0, 'ALTER TABLE `mantenimientos_programados` ADD CONSTRAINT `fk_mant_campana` FOREIGN KEY (`id_campana`) REFERENCES `mantenimiento_campanas` (`id_campana`)', 'SELECT 1');
PREPARE stmt_fk FROM @sql_fk; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;


-- ===================== SOLUCIONES TÉCNICAS (scripts, agentes, políticas y configuraciones de los equipos) =====================
-- Mismo contenido que database/migraciones/002_soluciones_tecnicas.sql. Reutiliza salas, equipos, users y
-- mantenimientos_programados por id; las versiones, procedimientos, verificaciones y el historial solo se agregan.

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
