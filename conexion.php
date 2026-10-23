<?php

/**
 * conexion.php - Conexión a PostgreSQL del Panel DBA
 * Los datos de conexión se leen de ~/proyecto/env (ver env.php).
 */

require_once __DIR__ . '/env.php';

const CONSOLA_VERSION = '1.0';
define('MOTOR_NOMBRE', env('DB_ENGINE', 'PostgreSQL'));

/** Usuario de PostgreSQL: el del env o, si falta, el usuario Linux de la cuenta */
function db_usuario(): string
{
    $u = env('DB_USER');
    if ($u !== null && $u !== '') {
        return $u;
    }
    $info = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : [];
    return $info['name'] ?? '';
}

function conectar(): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        env('DB_HOST', 'localhost'),
        env('DB_PORT', '5432'),
        env('DB_NAME')
    );

    return new PDO($dsn, db_usuario(), env('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
}

function h($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
