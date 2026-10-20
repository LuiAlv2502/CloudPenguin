<?php
/**
 * conexion.php - Conexión a PostgreSQL del Panel DBA
 * AJUSTE estos valores a su servidor.
 */
const DB_HOST = 'localhost';
const DB_PORT = '5432';
const DB_NAME = 'pg_penaranda';
const DB_USER = 'u119750839';
const DB_PASS = 'RzJtHCqE4YWBsM19wYsFlkj0H';

const CONSOLA_VERSION = '1.0';
const MOTOR_NOMBRE    = 'PostgreSQL';

function conectar(): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
}

function h($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
