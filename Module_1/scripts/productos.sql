CREATE TABLE productos (
    id_producto  SERIAL PRIMARY KEY,
    nombre       VARCHAR(60)    NOT NULL,
    categoria    VARCHAR(30)    NOT NULL,
    precio       NUMERIC(10,2)  NOT NULL,
    stock        INTEGER        NOT NULL,
    descripcion  TEXT
);

INSERT INTO productos (nombre, categoria, precio, stock, descripcion)
SELECT
    'Producto ' || LPAD(g::text, 3, '0'),
    (ARRAY['Abarrotes','Bebidas','Limpieza','Higiene','Snacks'])[1 + g % 5],
    ROUND((500 + (g * 37 % 9500))::numeric, 2),
    10 + (g * 13 % 490),
    REPEAT('x', 185)
FROM generate_series(1, 400) AS g;