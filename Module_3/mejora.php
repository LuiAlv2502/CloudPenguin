<?php
/**
 * mejora.php - Mejora ensayada del Módulo 03.
 * Los nombres y sentencias de índices son fijos en el servidor; el
 * formulario nunca puede enviar SQL ni nombres de objetos.
 */

return [
    'descripcion' => 'Índices sobre las llaves foráneas usadas en los filtros y JOIN de Q01 y Q02 '
                   . '(PostgreSQL indexa automáticamente las llaves primarias, pero no las foráneas).',
    'indices' => [
        'idx_ventas_id_cliente' => [
            'tabla' => 'ventas',
            'sql'   => 'CREATE INDEX idx_ventas_id_cliente ON ventas (id_cliente)',
        ],
        'idx_detalle_id_venta' => [
            'tabla' => 'detalle_venta',
            'sql'   => 'CREATE INDEX idx_detalle_id_venta ON detalle_venta (id_venta)',
        ],
    ],
    'tablas' => ['ventas', 'detalle_venta'],
];
