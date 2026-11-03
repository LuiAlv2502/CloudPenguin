-- =============================================================
-- auditoria.sql - Módulo 05 (Auditoría de cambios)
-- Se ejecuta una sola vez: crea la bitácora y la función que la llena.
-- Los triggers NO se crean aquí; los crea la página al pulsar «Activar».
-- Reproducible: se puede ejecutar varias veces sin borrar nada.
-- Uso: psql -d su_bd -f auditoria.sql
-- =============================================================

-- Bitácora: una fila por cada INSERT, UPDATE o DELETE auditado
CREATE TABLE IF NOT EXISTS dba_auditoria (
    id         SERIAL PRIMARY KEY,
    fecha      TIMESTAMPTZ NOT NULL DEFAULT now(),          -- cuándo
    usuario    TEXT        NOT NULL DEFAULT current_user,   -- quién
    tabla      TEXT        NOT NULL,                        -- en qué tabla
    operacion  TEXT        NOT NULL,                        -- INSERT / UPDATE / DELETE
    antes      JSONB,                                       -- fila anterior (OLD)
    despues    JSONB                                        -- fila nueva (NEW)
);

-- Función genérica: sirve para cualquier tabla porque guarda la fila completa como JSON
CREATE OR REPLACE FUNCTION dba_fn_auditar() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO dba_auditoria (tabla, operacion, antes, despues)
    VALUES (
        TG_TABLE_NAME,                                       -- tabla que disparó el trigger
        TG_OP,                                               -- operación realizada
        CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END,  -- en INSERT no hay "antes"
        CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END   -- en DELETE no hay "después"
    );
    RETURN COALESCE(NEW, OLD);  -- en un trigger AFTER el valor se ignora, pero es obligatorio
END $$;
