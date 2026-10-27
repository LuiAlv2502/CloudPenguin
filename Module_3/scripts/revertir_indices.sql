-- =============================================================
-- revertir_indices.sql - Módulo 03 (Diagnóstico de consultas)
-- Elimina únicamente los índices que el Módulo 03 registró como propios.
-- No modifica ni borra datos.
-- Uso: psql -d su_bd -f revertir_indices.sql
-- =============================================================

DO $$
DECLARE
    nombre TEXT;
BEGIN
    IF to_regclass('dba_indices_prueba') IS NULL THEN
        RAISE EXCEPTION 'Falta dba_indices_prueba. Ejecute Module_3/scripts/planes_historicos.sql primero.';
    END IF;

    FOR nombre IN
        SELECT nombre_indice
        FROM dba_indices_prueba
        WHERE creado_por_modulo = TRUE
          AND nombre_indice IN ('idx_ventas_id_cliente', 'idx_detalle_id_venta')
    LOOP
        EXECUTE format('DROP INDEX IF EXISTS %I', nombre);
        DELETE FROM dba_indices_prueba WHERE nombre_indice = nombre;
    END LOOP;
END
$$;

ANALYZE ventas;
ANALYZE detalle_venta;
