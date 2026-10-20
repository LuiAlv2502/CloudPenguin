CREATE TABLE ventas (
    id_venta     SERIAL PRIMARY KEY,
    id_cliente   INTEGER       NOT NULL REFERENCES clientes(id_cliente),
    fecha_venta  TIMESTAMP     NOT NULL,
    metodo_pago  VARCHAR(20)   NOT NULL,
    estado       VARCHAR(15)   NOT NULL,
    total        NUMERIC(12,2) NOT NULL DEFAULT 0,
    notas        TEXT
);

INSERT INTO ventas (id_cliente, fecha_venta, metodo_pago, estado, total, notas)
SELECT
    1 + (g * 7 % 250),
    TIMESTAMP '2026-01-01 08:00:00' + (g * INTERVAL '97 minutes'),
    (ARRAY['Efectivo','Tarjeta','SINPE','Transferencia'])[1 + g % 4],
    (ARRAY['Pagada','Pagada','Pagada','Pendiente'])[1 + g % 4],
    0,
    REPEAT('x', 125)
FROM generate_series(1, 1850) AS g;