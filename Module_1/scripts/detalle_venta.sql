CREATE TABLE detalle_venta (
    id_detalle       SERIAL PRIMARY KEY,
    id_venta         INTEGER       NOT NULL REFERENCES ventas(id_venta),
    id_producto      INTEGER       NOT NULL REFERENCES productos(id_producto),
    cantidad         INTEGER       NOT NULL,
    precio_unitario  NUMERIC(10,2) NOT NULL,
    subtotal         NUMERIC(12,2) NOT NULL,
    observaciones    TEXT
);

INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario, subtotal, observaciones)
SELECT
    1 + ((g - 1) % 1850),          -- toda venta tiene al menos un detalle
    p.id_producto,
    1 + (g % 5),
    p.precio,
    (1 + (g % 5)) * p.precio,
    REPEAT('x', 170)
FROM generate_series(1, 4700) AS g
JOIN productos p ON p.id_producto = 1 + (g * 13 % 400);

-- Total de cada venta = suma de sus detalles (no cambia el número de filas)
UPDATE ventas v
SET total = d.suma
FROM (SELECT id_venta, SUM(subtotal) AS suma FROM detalle_venta GROUP BY id_venta) d
WHERE d.id_venta = v.id_venta;

-- Recuperar el espacio de las versiones antiguas de filas y actualizar estadísticas
VACUUM FULL ventas;
ANALYZE;