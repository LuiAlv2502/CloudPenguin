<?php
// Usuario y hash de la contraseña de la Consola DBA.
// Los valores se leen de ~/proyecto/.env (no se guardan en el repositorio).
require_once __DIR__ . '/Module_1/env.php';

$usuario    = env('DBA_USER', 'TUX');
$clave_hash = env('DBA_PASS_HASH');
