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
