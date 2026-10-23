<?php
/**
 * env.php - Lee el archivo de configuración ~/proyecto/env
 * (fuera de public_html). No contiene ningún dato fijo.
 */

/** Carpeta HOME real del usuario que ejecuta PHP */
function env_home(): string
{
    if (function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (!empty($info['dir'])) {
            return $info['dir'];
        }
    }
    return dirname(__DIR__, 2); // respaldo: /home/usuario
}

/** Ruta del archivo de configuración: ~/proyecto/env */
function env_ruta(): string
{
    $explicita = getenv('DBA_ENV_FILE');
    if ($explicita && is_readable($explicita)) {
        return $explicita;
    }
    return env_home() . '/proyecto/env';
}

function env_cargar(): array
{
    static $datos = null;
    if ($datos !== null) {
        return $datos;
    }
    $ruta = env_ruta();
    if (!is_readable($ruta)) {
        http_response_code(500);
        exit('Error de configuración: no se puede leer el archivo env.');
    }
    $datos = [];
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $linea, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === "'" || $v[0] === '"') && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $datos[trim($k)] = $v;
    }
    return $datos;
}

function env(string $clave, ?string $defecto = null): ?string
{
    return env_cargar()[$clave] ?? $defecto;
}
