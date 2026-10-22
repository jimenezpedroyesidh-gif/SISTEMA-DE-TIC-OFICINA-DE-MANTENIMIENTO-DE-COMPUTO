-- Esquema MÍNIMO de GLPI (solo las columnas que lee GlpiDb) para las pruebas. No es GLPI real.
CREATE TABLE glpi_locations (id INT PRIMARY KEY, completename VARCHAR(255), ancestors_cache TEXT);
CREATE TABLE glpi_manufacturers (id INT PRIMARY KEY, name VARCHAR(255));
CREATE TABLE glpi_states (id INT PRIMARY KEY, completename VARCHAR(255));
CREATE TABLE glpi_computermodels (id INT PRIMARY KEY, name VARCHAR(255));
CREATE TABLE glpi_monitormodels (id INT PRIMARY KEY, name VARCHAR(255));
CREATE TABLE glpi_peripheralmodels (id INT PRIMARY KEY, name VARCHAR(255));
CREATE TABLE glpi_computers (id INT PRIMARY KEY, name VARCHAR(255), serial VARCHAR(255), otherserial VARCHAR(255), locations_id INT DEFAULT 0,
  manufacturers_id INT DEFAULT 0, computermodels_id INT DEFAULT 0, states_id INT DEFAULT 0, is_deleted TINYINT DEFAULT 0, is_template TINYINT DEFAULT 0);
CREATE TABLE glpi_monitors (id INT PRIMARY KEY, name VARCHAR(255), serial VARCHAR(255), otherserial VARCHAR(255), locations_id INT DEFAULT 0,
  manufacturers_id INT DEFAULT 0, monitormodels_id INT DEFAULT 0, states_id INT DEFAULT 0, is_deleted TINYINT DEFAULT 0, is_template TINYINT DEFAULT 0);
CREATE TABLE glpi_peripherals (id INT PRIMARY KEY, name VARCHAR(255), serial VARCHAR(255), otherserial VARCHAR(255), locations_id INT DEFAULT 0,
  manufacturers_id INT DEFAULT 0, peripheralmodels_id INT DEFAULT 0, states_id INT DEFAULT 0, is_deleted TINYINT DEFAULT 0, is_template TINYINT DEFAULT 0);
-- (sin glpi_printers ni glpi_networkequipments a propósito: esos tipos deben omitirse sin error)

INSERT INTO glpi_locations VALUES (1,'Aula Sur','[]'),(2,'Aula Sur > SA401','{"1":1}'),(3,'Aula Sur > SA402','{"1":1}'),(9,'Oficina de mantenimiento','[]');
INSERT INTO glpi_manufacturers VALUES (1,'Dell'),(2,'Genius');
INSERT INTO glpi_states VALUES (1,'En uso'),(2,'Dañado');
INSERT INTO glpi_computermodels VALUES (1,'OptiPlex 7010');
INSERT INTO glpi_monitormodels VALUES (1,'P2219H');
INSERT INTO glpi_computers VALUES (10,'SA401-PC01','ZTG-SER-PC01','INV-PC01',2,1,1,1,0,0),(11,'SA401-PC02','ZTG-SER-PC02','INV-PC02',2,1,1,2,0,0),
  (12,'Borrado','ZTG-SER-DEL','',2,1,1,1,1,0),(13,'Plantilla','','',0,0,0,0,0,1),(14,'SA402-PC01','ZTG-SER-PC03','INV-PC03',3,1,1,1,0,0);
INSERT INTO glpi_monitors VALUES (20,'Monitor SA401-01','ZTG-SER-MON1','INV-M1',2,1,1,1,0,0);
INSERT INTO glpi_peripherals VALUES (30,'Mouse SA402','ZTG-SER-MOU1','',3,2,0,1,0,0);
