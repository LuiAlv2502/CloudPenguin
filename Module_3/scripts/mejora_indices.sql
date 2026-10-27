-- =============================================================
-- mejora_indices.sql - Módulo 03 (Diagnóstico de consultas)
-- Mejora ensayada: índices sobre las llaves foráneas usadas en
-- los filtros y JOIN de Q01 y Q02.
-- PostgreSQL crea índices automáticamente para las llaves
-- primarias, pero NO para las llaves foráneas.
-- No modifica ni borra datos. Se puede ejecutar varias veces.
-- Uso: psql -d su_bd -f mejora_indices.sql
-- =============================================================

CREATE TABLE IF NOT EXISTS dba_indices_prueba (
    nombre_indice     VARCHAR(63) PRIMARY KEY,
    creado_por_modulo BOOLEAN     NOT NULL,
    actualizado       TIMESTAMP   NOT NULL DEFAULT now()
);

-- Q01 y Q02 filtran ventas por id_cliente
CREATE INDEX IF NOT EXISTS idx_ventas_id_cliente ON ventas (id_cliente);

-- Q02 une detalle_venta con ventas por id_venta
CREATE INDEX IF NOT EXISTS idx_detalle_id_venta ON detalle_venta (id_venta);

INSERT INTO dba_indices_prueba (nombre_indice, creado_por_modulo)
VALUES
    ('idx_ventas_id_cliente', TRUE),
    ('idx_detalle_id_venta', TRUE)
ON CONFLICT (nombre_indice) DO UPDATE
SET creado_por_modulo = TRUE,
    actualizado = now();

-- Actualizar estadísticas solo de las tablas propias
ANALYZE ventas;
ANALYZE detalle_venta;
