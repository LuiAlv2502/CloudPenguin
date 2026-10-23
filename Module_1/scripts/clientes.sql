DROP TABLE IF EXISTS detalle_venta, ventas, productos, clientes CASCADE;

CREATE TABLE clientes (
    id_cliente      SERIAL PRIMARY KEY,
    nombre          VARCHAR(60)  NOT NULL,
    email           VARCHAR(80)  NOT NULL,
    telefono        VARCHAR(20),
    fecha_registro  DATE         NOT NULL,
    notas           TEXT
);

INSERT INTO clientes (nombre, email, telefono, fecha_registro, notas)
SELECT
    'Cliente ' || LPAD(g::text, 3, '0'),
    'cliente' || g || '@correo.com',
    '8' || LPAD((1000000 + g)::text, 7, '0'),
    DATE '2025-01-01' + (g % 365),
    REPEAT('x', 100)
FROM generate_series(1, 250) AS g;
