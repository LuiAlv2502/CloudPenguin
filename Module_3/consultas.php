<?php
/**
 * consultas.php - Catálogo controlado del Módulo 03.
 * El SQL se define aquí, del lado del servidor. La web solo permite
 * escoger Q01 o Q02 y un valor numérico validado que se enlaza
 * como parámetro preparado (:valor). Nunca se ejecuta SQL escrito
 * por el usuario.
 */

return [
    'Q01' => [
        'id'        => 'Q01',
        'titulo'    => 'Ventas de un cliente',
        'proposito' => 'Filtrar la tabla ventas por un dato significativo: el cliente que realizó la compra.',
        'tipo'      => 'Filtro sobre una tabla',
        'tablas'    => ['ventas'],
        'sql'       => "SELECT id_venta, fecha_venta, metodo_pago, total\n"
                     . "FROM ventas\n"
                     . "WHERE id_cliente = :valor",
        'param'     => [
            'etiqueta' => 'ID del cliente',
            'defecto'  => 42,
            'min'      => 1,
            'max'      => 2147483647,
        ],
        'mejora'    => 'Índice idx_ventas_id_cliente sobre ventas(id_cliente). Sin índice, el motor debe '
                     . 'leer las 1850 ventas y descartar las que no son del cliente (Seq Scan con Filter). '
                     . 'Con el índice, ubica directamente las pocas filas del cliente.',
    ],
    'Q02' => [
        'id'        => 'Q02',
        'titulo'    => 'Detalle de compras de un cliente',
        'proposito' => 'Relacionar ventas, detalle_venta y productos con JOIN, filtrar por cliente y ordenar por fecha.',
        'tipo'      => 'JOIN de tres tablas + filtro + ORDER BY',
        'tablas'    => ['ventas', 'detalle_venta', 'productos'],
        'sql'       => "SELECT v.id_venta, v.fecha_venta, p.nombre AS producto,\n"
                     . "       d.cantidad, d.subtotal\n"
                     . "FROM ventas v\n"
                     . "JOIN detalle_venta d ON d.id_venta = v.id_venta\n"
                     . "JOIN productos p     ON p.id_producto = d.id_producto\n"
                     . "WHERE v.id_cliente = :valor\n"
                     . "ORDER BY v.fecha_venta DESC",
        'param'     => [
            'etiqueta' => 'ID del cliente',
            'defecto'  => 42,
            'min'      => 1,
            'max'      => 2147483647,
        ],
        'mejora'    => 'Índices idx_ventas_id_cliente sobre ventas(id_cliente) e idx_detalle_id_venta sobre '
                     . 'detalle_venta(id_venta). Sin ellos, el motor recorre completas ventas y detalle_venta '
                     . '(4700 filas) para armar un Hash Join. Con ellos, filtra las ventas del cliente por índice '
                     . 'y busca solo sus detalles (Nested Loop con búsqueda por índice).',
    ],
];
