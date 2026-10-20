SELECT
    current_database()                                  AS base_de_datos,
    current_user                                        AS usuario,
    now()                                               AS fecha_consulta,
    relname                                             AS tabla,
    (xpath('/row/c/text()',
        query_to_xml(format('SELECT count(*) AS c FROM %I', relname), false, true, ''))
    )[1]::text::int                                     AS registros,
    pg_size_pretty(pg_total_relation_size(oid))         AS tamano
FROM pg_class
WHERE relkind = 'r'
  AND relnamespace = 'public'::regnamespace
ORDER BY pg_total_relation_size(oid) DESC;

SELECT 'Tamaño total aproximado de la BD: ' ||
       pg_size_pretty(pg_database_size(current_database())) AS resumen;