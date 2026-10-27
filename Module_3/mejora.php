<?php
/**
 * mejora.php - Mejora ensayada del Módulo 03.
 * Sentencias FIJAS definidas en el servidor. La web solo puede pedir
 * "ensayar la mejora"; nunca envía SQL ni nombres de objetos.
 * Son las mismas sentencias de scripts/revertir_indices.sql y
 * scripts/mejora_indices.sql. Ninguna modifica ni borra datos.
 */

return [
    'descripcion' => 'Índices sobre las llaves foráneas usadas en los filtros y JOIN de Q01 y Q02 '
                   . '(PostgreSQL indexa automáticamente las llaves primarias, pero no las foráneas).',

    // 1. Volver al estado sin mejora para medir el plan "ANTES"
    'revertir' => [
        'DROP INDEX IF EXISTS idx_ventas_id_cliente',
        'DROP INDEX IF EXISTS idx_detalle_id_venta',
        'ANALYZE ventas',
        'ANALYZE detalle_venta',
    ],

    // 2. Aplicar la mejora para medir el plan "DESPUÉS" (el actual, en vivo)
    'aplicar' => [
        'CREATE INDEX IF NOT EXISTS idx_ventas_id_cliente ON ventas (id_cliente)',
        'CREATE INDEX IF NOT EXISTS idx_detalle_id_venta ON detalle_venta (id_venta)',
        'ANALYZE ventas',
        'ANALYZE detalle_venta',
    ],
];
