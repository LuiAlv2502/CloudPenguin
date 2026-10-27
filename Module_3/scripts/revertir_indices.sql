-- =============================================================
-- revertir_indices.sql - Módulo 03 (Diagnóstico de consultas)
-- Elimina los índices de la mejora para volver al plan "antes"
-- y poder repetir el ensayo. No modifica ni borra datos.
-- Uso: psql -d su_bd -f revertir_indices.sql
-- =============================================================

DROP INDEX IF EXISTS idx_ventas_id_cliente;
DROP INDEX IF EXISTS idx_detalle_id_venta;

ANALYZE ventas;
ANALYZE detalle_venta;
