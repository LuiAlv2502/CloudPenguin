-- =============================================================
-- planes_historicos.sql - Módulo 03 (Diagnóstico de consultas)
-- Tabla donde se guarda el plan de ejecución ANTES de la mejora,
-- identificado con caso, fecha y cuenta.
-- Es reproducible: se puede ejecutar varias veces sin borrar nada.
-- Uso: psql -d su_bd -f planes_historicos.sql
-- =============================================================

CREATE TABLE IF NOT EXISTS dba_planes (
    id           SERIAL PRIMARY KEY,
    caso         VARCHAR(3)    NOT NULL,          -- Q01 o Q02
    fecha        TIMESTAMP     NOT NULL DEFAULT now(),
    cuenta       VARCHAR(63)   NOT NULL DEFAULT current_user,
    base_datos   VARCHAR(63)   NOT NULL DEFAULT current_database(),
    parametro    VARCHAR(50),                     -- valor no sensible usado en el filtro
    etapa        VARCHAR(10)   NOT NULL DEFAULT 'ANTES',
    costo_total  NUMERIC(14,2),
    filas_est    BIGINT,
    plan_texto   TEXT          NOT NULL,          -- salida de EXPLAIN en texto
    plan_json    JSONB         NOT NULL           -- salida de EXPLAIN (FORMAT JSON)
);

CREATE TABLE IF NOT EXISTS dba_indices_prueba (
    nombre_indice     VARCHAR(63) PRIMARY KEY,
    creado_por_modulo BOOLEAN     NOT NULL,
    actualizado       TIMESTAMP   NOT NULL DEFAULT now()
);

-- Los nombres son exclusivos de la mejora de este módulo. Registrar los
-- existentes permite que el botón de reversión también gestione ensayos previos.
INSERT INTO dba_indices_prueba (nombre_indice, creado_por_modulo)
SELECT 'idx_ventas_id_cliente', TRUE
WHERE to_regclass('idx_ventas_id_cliente') IS NOT NULL
ON CONFLICT (nombre_indice) DO NOTHING;

INSERT INTO dba_indices_prueba (nombre_indice, creado_por_modulo)
SELECT 'idx_detalle_id_venta', TRUE
WHERE to_regclass('idx_detalle_id_venta') IS NOT NULL
ON CONFLICT (nombre_indice) DO NOTHING;
