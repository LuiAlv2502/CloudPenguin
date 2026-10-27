<?php
/**
 * mantenimiento.php - Módulo 04 Mantenimiento básico (PostgreSQL 16)
 * Usa conectar(), h(), env_home() y las constantes de conexion.php.
 * La bitácora se guarda en ~/proyecto/ (fuera de public_html).
 */
require_once __DIR__ . '/../conexion.php';
date_default_timezone_set('America/Costa_Rica');
session_start();

// --- 1. Sesión (adaptar a lo que use login.php) ---------------------------
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login.php');
    exit;
}

// --- 2. Configuración ------------------------------------------------------
// Ruta fija a partir del HOME de la cuenta: ~/proyecto/mantenimiento.log
if (!defined('LOG_FILE')) {
    define('LOG_FILE', env_home() . '/proyecto/mantenimiento.log');
}
const ESQUEMAS_PERMITIDOS = ['public'];     // esquema(s) propios

// --- 3. CSRF ---------------------------------------------------------------
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_ok(): bool
{
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// --- 4. Utilidades ---------------------------------------------------------
function q_ident(string $id): string        // escapa identificador PostgreSQL
{
    return '"' . str_replace('"', '""', $id) . '"';
}

function bitacora(string $motor, string $base, string $tabla, string $accion,
                  string $resultado, string $detalle, float $segundos): void
{
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $nuevo = !file_exists(LOG_FILE);
    $linea = json_encode([
        'fecha'     => date('Y-m-d H:i:s T (P)'),
        'motor'     => $motor,
        'base'      => $base,
        'tabla'     => $tabla,
        'accion'    => $accion,
        'resultado' => $resultado,
        'detalle'   => mb_substr($detalle, 0, 200),
        'duracion'  => round($segundos, 3) . ' s',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents(LOG_FILE, $linea, FILE_APPEND | LOCK_EX);
    if ($nuevo) {
        chmod(LOG_FILE, 0600);
    }
}

function ultimas_bitacoras(int $n = 10): array
{
    if (!is_readable(LOG_FILE)) {
        return [];
    }
    $lineas = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];
    foreach (array_slice(array_reverse($lineas), 0, $n) as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return $out;
}

/** Catálogo autorizado: solo tablas ordinarias de los esquemas propios. */
function catalogo(PDO $pdo): array
{
    $in = implode(',', array_fill(0, count(ESQUEMAS_PERMITIDOS), '?'));
    $sql = "SELECT s.schemaname, s.relname,
                   s.n_live_tup,
                   pg_total_relation_size(s.relid) AS total_bytes,
                   pg_indexes_size(s.relid)        AS index_bytes,
                   (SELECT count(*) FROM pg_indexes i
                     WHERE i.schemaname = s.schemaname
                       AND i.tablename  = s.relname) AS n_indices,
                   s.last_analyze, s.last_autoanalyze
              FROM pg_stat_user_tables s
             WHERE s.schemaname IN ($in)
          ORDER BY s.schemaname, s.relname";
    $st = $pdo->prepare($sql);
    $st->execute(ESQUEMAS_PERMITIDOS);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $res[$r['schemaname'] . '.' . $r['relname']] = $r;
    }
    return $res;
}

function fmt_bytes($b): string
{
    if ($b === null) return 'No disponible';
    return number_format($b / 1024, 1) . ' KB';
}
function fmt_ts($t): string
{
    return $t ? h($t) : 'Sin registro';
}

// --- 5. Contexto -----------------------------------------------------------
$pdo    = conectar();
$ctx    = $pdo->query('SELECT version() AS v, current_database() AS db, current_user AS u')->fetch();
$motor  = MOTOR_NOMBRE . ' ' . (explode(' ', $ctx['v'])[1] ?? '');
$base   = $ctx['db'];
$usuario = $ctx['u'];

$mensaje = null; $clase = '';
$antes = $despues = null; $conteo_antes = $conteo_despues = null;
$confirmar = null;

// --- 6. Procesar POST ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paso  = $_POST['paso'] ?? '';
    $clave = (string)($_POST['tabla'] ?? '');       // formato esquema.tabla
    $t0    = microtime(true);

    if (!csrf_ok()) {
        $mensaje = 'ERROR: token CSRF inválido. Operación rechazada.';
        $clase = 'err';
        bitacora($motor, $base, mb_substr($clave, 0, 80), 'Operación rechazada', 'ERROR',
                 'Token CSRF inválido', microtime(true) - $t0);
    } else {
        $cat = catalogo($pdo);
        if (!isset($cat[$clave])) {
            // Identificador inexistente o no permitido: NO se ejecuta SQL
            $mensaje = 'ERROR: la tabla solicitada no existe o no está autorizada.';
            $clase = 'err';
            bitacora($motor, $base, mb_substr($clave, 0, 80), 'Operación rechazada', 'ERROR',
                     'Tabla no existe o no autorizada; no se ejecutó SQL', microtime(true) - $t0);
        } elseif ($paso === 'confirmar') {
            $confirmar = $cat[$clave];
        } elseif ($paso === 'ejecutar') {
            $esq = $cat[$clave]['schemaname'];
            $tab = $cat[$clave]['relname'];
            $ident = q_ident($esq) . '.' . q_ident($tab);   // viene del catálogo, no del POST
            $antes = $cat[$clave];
            try {
                $conteo_antes = (int)$pdo->query("SELECT COUNT(*) FROM $ident")->fetchColumn();
                $pdo->exec("ANALYZE $ident");
                $conteo_despues = (int)$pdo->query("SELECT COUNT(*) FROM $ident")->fetchColumn();
                $cat2 = catalogo($pdo);
                $despues = $cat2[$clave] ?? null;
                // ANALYZE omite tablas sin permiso con un WARNING (no excepción):
                // se verifica que last_analyze realmente avanzó.
                $avanzo = $despues && $despues['last_analyze'] !== null
                          && $despues['last_analyze'] !== $antes['last_analyze'];
                if ($avanzo) {
                    $mensaje = "OK: estadísticas actualizadas en $clave.";
                    $clase = 'ok';
                    $res = 'OK'; $det = 'ANALYZE completado; last_analyze actualizado';
                } else {
                    $mensaje = "ERROR: ANALYZE no registró actualización en $clave "
                             . '(posible omisión o falta de permisos).';
                    $clase = 'err';
                    $res = 'ERROR'; $det = 'ANALYZE sin cambio en last_analyze (omitida/sin permiso)';
                }
            } catch (PDOException $e) {
                $mensaje = 'ERROR: el motor rechazó la operación (código ' . h($e->getCode()) . ').';
                $clase = 'err';
                $res = 'ERROR'; $det = 'PDOException SQLSTATE ' . $e->getCode();
            }
            bitacora($motor, $base, $clave, 'Actualización de estadísticas', $res, $det,
                     microtime(true) - $t0);
        }
    }
}

$tablas = catalogo($pdo);
$logs   = ultimas_bitacoras(10);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Módulo 04 Mantenimiento</title>
<style>
 body{font-family:sans-serif;margin:20px} table{border-collapse:collapse;margin:10px 0}
 td,th{border:1px solid #999;padding:4px 8px} .ok{color:#060} .err{color:#a00;font-weight:bold}
</style>
</head>
<body>
<h1>Módulo 04 Mantenimiento básico</h1>
<p><a href="../index.php">&larr; Volver al menú principal</a></p>
<p>Consola v<?= h(CONSOLA_VERSION) ?> | Motor: <?= h($ctx['v']) ?> | Base: <?= h($base) ?>
 | Usuario: <?= h($usuario) ?> | <?= h(date('Y-m-d H:i:s T')) ?></p>

<?php if ($mensaje): ?><p class="<?= $clase ?>"><?= h($mensaje) ?></p><?php endif; ?>

<?php if ($confirmar): ?>
<h2>Confirmar actualización de estadísticas</h2>
<p>Base: <b><?= h($base) ?></b> — Tabla: <b><?= h($confirmar['schemaname'] . '.' . $confirmar['relname']) ?></b></p>
<p>Se ejecutará <code>ANALYZE esquema.tabla</code>: recalcula las estadísticas que usa el
 planificador. No borra filas ni modifica datos.</p>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
  <input type="hidden" name="paso" value="ejecutar">
  <input type="hidden" name="tabla" value="<?= h($confirmar['schemaname'] . '.' . $confirmar['relname']) ?>">
  <button type="submit">Confirmar</button> <a href="mantenimiento.php">Cancelar</a>
</form>
<?php endif; ?>

<?php if ($antes && $despues): ?>
<h2>Antes / después (<?= h($antes['schemaname'] . '.' . $antes['relname']) ?>)</h2>
<table>
<tr><th></th><th>Filas estimadas</th><th>COUNT(*)</th><th>Tamaño total</th><th>Tamaño índices</th><th>last_analyze</th></tr>
<tr><td>Antes</td><td><?= h($antes['n_live_tup']) ?></td><td><?= h($conteo_antes) ?></td>
 <td><?= fmt_bytes($antes['total_bytes']) ?></td><td><?= fmt_bytes($antes['index_bytes']) ?></td>
 <td><?= fmt_ts($antes['last_analyze']) ?></td></tr>
<tr><td>Después</td><td><?= h($despues['n_live_tup']) ?></td><td><?= h($conteo_despues) ?></td>
 <td><?= fmt_bytes($despues['total_bytes']) ?></td><td><?= fmt_bytes($despues['index_bytes']) ?></td>
 <td><?= fmt_ts($despues['last_analyze']) ?></td></tr>
</table>
<p>Filas conservadas: <b><?= $conteo_antes === $conteo_despues ? 'Sí' : 'NO' ?></b></p>
<?php endif; ?>

<h2>Tablas autorizadas</h2>
<table>
<tr><th>Esquema.tabla</th><th>Filas (estimadas)</th><th>Tamaño total</th><th>Índices (cant.)</th>
<th>Tamaño índices</th><th>Último análisis</th><th>Acción</th></tr>
<?php foreach ($tablas as $clave => $t): ?>
<tr>
 <td><?= h($clave) ?></td>
 <td><?= $t['n_live_tup'] === null ? 'No disponible' : h($t['n_live_tup']) ?></td>
 <td><?= fmt_bytes($t['total_bytes']) ?></td>
 <td><?= h($t['n_indices']) ?></td>
 <td><?= fmt_bytes($t['index_bytes']) ?></td>
 <td><?= fmt_ts($t['last_analyze'] ?? $t['last_autoanalyze']) ?></td>
 <td><form method="post">
   <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
   <input type="hidden" name="paso" value="confirmar">
   <input type="hidden" name="tabla" value="<?= h($clave) ?>">
   <button type="submit">Actualizar estadísticas</button></form></td>
</tr>
<?php endforeach; ?>
</table>
<p><small>Filas = n_live_tup, valor estimado por el motor.</small></p>

<h2>Últimas 10 ejecuciones</h2>
<table>
<tr><th>Fecha</th><th>Motor</th><th>Base</th><th>Tabla</th><th>Acción</th><th>Resultado</th><th>Detalle</th><th>Duración</th></tr>
<?php foreach ($logs as $l): ?>
<tr><td><?= h($l['fecha']) ?></td><td><?= h($l['motor']) ?></td><td><?= h($l['base']) ?></td>
 <td><?= h($l['tabla']) ?></td><td><?= h($l['accion']) ?></td>
 <td class="<?= $l['resultado'] === 'OK' ? 'ok' : 'err' ?>"><?= h($l['resultado']) ?></td>
 <td><?= h($l['detalle']) ?></td><td><?= h($l['duracion']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="8">Sin registro</td></tr><?php endif; ?>
</table>
</body>
</html>
