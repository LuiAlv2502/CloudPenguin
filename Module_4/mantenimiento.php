<?php
session_start();
require_once __DIR__ . '/../conexion.php';
date_default_timezone_set('America/Costa_Rica');

// Control de acceso
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login.php');
    exit;
}

$usuario = $_SESSION['usuario'] ?? 'estudiante';

// Ruta FIJA a partir del HOME de la cuenta (nada viene de GET/POST)
if (!defined('LOG_FILE')) {
    define('LOG_FILE', env_home() . '/proyecto/mantenimiento.log');
}
const ESQUEMAS_PERMITIDOS = ['public'];     // esquema(s) propios

if (empty($_SESSION['token_mant'])) {
    $_SESSION['token_mant'] = bin2hex(random_bytes(16));
}

// ---------- Utilidades ----------
function token_ok(): bool
{
    return isset($_POST['token']) && hash_equals($_SESSION['token_mant'], (string)$_POST['token']);
}

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
    $lineas = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach (array_slice(array_reverse($lineas), 0, $n) as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return $out;
}

/** Catálogo autorizado: solo tablas de los esquemas propios. */
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

function tam_legible($b): string
{
    if ($b === null) return 'No disponible';
    $b = (int)$b;
    if ($b >= 1048576) return number_format($b / 1048576, 2, ',', '.') . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1, ',', '.') . ' KB';
    return $b . ' B';
}

function fmt_ts($t): string
{
    return $t ? (string)$t : 'Sin registro';
}

function fmt_filas($n): string
{
    return $n === null ? 'No disponible' : number_format((int)$n, 0, ',', '.');
}

// ---------- Contexto ----------
$pdo = conectar();
$ctx = $pdo->query('SELECT version() AS v, current_database() AS db, current_user AS u')->fetch();
$versionCorta = explode(' ', $ctx['v'])[1] ?? '';
$motor = MOTOR_NOMBRE . ' ' . $versionCorta;
$base  = $ctx['db'];
$usuarioConexion = $ctx['u'];

$confirmar = null;

// ---------- Acciones permitidas: confirmar / ejecutar ANALYZE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paso  = (string)($_POST['paso'] ?? '');
    $clave = (string)($_POST['tabla'] ?? '');       // formato esquema.tabla
    $t0    = microtime(true);

    if (!token_ok()) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida (token CSRF). Recargue la página e intente de nuevo.'];
        bitacora($motor, $base, mb_substr($clave, 0, 80), 'Operación rechazada', 'ERROR',
                 'Token CSRF inválido', microtime(true) - $t0);
        header('Location: mantenimiento.php');
        exit;
    }

    $cat = catalogo($pdo);

    if (!in_array($paso, ['confirmar', 'ejecutar'], true) || !isset($cat[$clave])) {
        // Identificador inexistente o no permitido: NO se ejecuta SQL
        $_SESSION['flash'] = ['error', 'ERROR: la tabla solicitada no existe o no está autorizada. No se ejecutó ninguna operación.'];
        bitacora($motor, $base, mb_substr($clave, 0, 80), 'Operación rechazada', 'ERROR',
                 'Tabla no existe o no autorizada; no se ejecutó SQL', microtime(true) - $t0);
        header('Location: mantenimiento.php');
        exit;
    }

    if ($paso === 'confirmar') {
        $confirmar = $cat[$clave];
    } else {
        $ident  = q_ident($cat[$clave]['schemaname']) . '.' . q_ident($cat[$clave]['relname']); // del catálogo
        $antes  = $cat[$clave];
        $despues = null; $cAntes = $cDespues = null;
        try {
            $cAntes = (int)$pdo->query("SELECT COUNT(*) FROM $ident")->fetchColumn();
            $pdo->exec("ANALYZE $ident");
            $cDespues = (int)$pdo->query("SELECT COUNT(*) FROM $ident")->fetchColumn();
            $despues = (catalogo($pdo))[$clave] ?? null;

            // ANALYZE omite tablas sin permiso con un WARNING (no excepción):
            // se verifica que last_analyze realmente avanzó.
            $avanzo = $despues && $despues['last_analyze'] !== null
                      && $despues['last_analyze'] !== $antes['last_analyze'];
            if ($avanzo) {
                $res = 'OK';
                $det = 'ANALYZE completado; last_analyze actualizado';
                $_SESSION['flash'] = ['ok', "Estadísticas actualizadas correctamente en $clave."];
            } else {
                $res = 'ERROR';
                $det = 'ANALYZE sin cambio en last_analyze (omitida o sin permiso)';
                $_SESSION['flash'] = ['error', "ERROR: ANALYZE no registró actualización en $clave (posible omisión o falta de permisos)."];
            }
        } catch (PDOException $e) {
            $res = 'ERROR';
            $det = 'PDOException SQLSTATE ' . $e->getCode();
            $_SESSION['flash'] = ['error', 'ERROR: el motor rechazó la operación (código ' . $e->getCode() . ').'];
        }
        $_SESSION['mant_resultado'] = [
            'tabla' => $clave, 'antes' => $antes, 'despues' => $despues,
            'c_antes' => $cAntes, 'c_despues' => $cDespues,
        ];
        bitacora($motor, $base, $clave, 'Actualización de estadísticas', $res, $det,
                 microtime(true) - $t0);
        header('Location: mantenimiento.php');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$resultado = $_SESSION['mant_resultado'] ?? null;
unset($_SESSION['mant_resultado']);

$tablas   = catalogo($pdo);
$logs     = ultimas_bitacoras(10);
$ahora    = date('d/m/Y H:i:s T');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mantenimiento - Panel DBA</title>
<style>
        :root {
            --bg: #f4f6f5;
            --text: #1f2d2b;
            --muted: #61716e;
            --dark: #1f3a38;
            --accent: #3f7d78;
            --accent-hover: #316662;
            --soft: #e4eeec;
            --soft-border: #c9dbd8;
            --soft-text: #2f5d59;
            --border: #dde5e3;
            --danger: #a8514c;
            --danger-hover: #8d413d;
            --warn-bg: #fbf0e4;
            --warn: #b9772f;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header-main {
            background-color: var(--dark); color: #ffffff;
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 24px;
        }
        .header-brand { display: flex; align-items: center; gap: 12px; font-size: 18px; font-weight: 700; }
        .header-brand img { height: 38px; width: auto; object-fit: contain; }
        .header-user { display: flex; align-items: center; gap: 16px; font-size: 14px; }
        .btn-logout {
            background-color: var(--danger); color: #ffffff; text-decoration: none;
            padding: 6px 14px; border-radius: 6px; font-weight: 600; font-size: 13px;
            transition: background-color 0.2s;
        }
        .btn-logout:hover { background-color: var(--danger-hover); }

        .header-sub {
            background-color: var(--soft); color: var(--soft-text);
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 24px; font-size: 13px; border-bottom: 1px solid var(--soft-border);
        }
        .header-sub strong { color: var(--dark); }

        .container { flex: 1; width: 100%; max-width: 1200px; margin: 36px auto; padding: 0 24px; }
        .page-title { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .page-description { font-size: 14px; color: var(--muted); margin-bottom: 28px; }
        .section-title { font-size: 16px; font-weight: 700; color: var(--dark); margin: 32px 0 14px; }

        .info-panel {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px; margin-bottom: 12px;
        }
        .info-item {
            background: #fff; border: 1px solid var(--border); border-top: 3px solid var(--accent);
            border-radius: 8px; padding: 14px 16px;
        }
        .info-item .label {
            font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
            color: var(--muted); margin-bottom: 4px;
        }
        .info-item .value { font-size: 16px; font-weight: 700; color: var(--dark); word-break: break-word; }

        .panel {
            background: #fff; border: 1px solid var(--border); border-radius: 10px;
            padding: 20px; box-shadow: 0 1px 3px rgba(31, 58, 56, 0.06);
        }
        .table-wrap { overflow-x: auto; }
        table.datos { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.datos th {
            background: var(--soft); color: var(--soft-text); text-align: left;
            padding: 10px 12px; border-bottom: 2px solid var(--soft-border); white-space: nowrap;
        }
        table.datos td { padding: 9px 12px; border-bottom: 1px solid var(--border); }
        table.datos tr:hover td { background: #f8faf9; }
        table.datos .num { text-align: right; font-variant-numeric: tabular-nums; }

        .alert-error {
            background: #f6e3e1; color: var(--danger-hover); border: 1px solid #e6c3bf;
            padding: 14px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; line-height: 1.6;
        }
        .alert-ok {
            background: #e4f2e8; color: #2d6a3e; border: 1px solid #bfdcc7;
            padding: 14px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; line-height: 1.6;
        }
        .alert-warn {
            background: var(--warn-bg); color: #7a4d14; border: 1px solid #ecd3b0;
            padding: 16px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; line-height: 1.6;
        }
        .nota { font-size: 12px; color: var(--muted); margin-top: 12px; line-height: 1.5; }
        code { background: var(--soft); padding: 1px 6px; border-radius: 4px; font-size: 12px; }

        .btn-action {
            display: inline-block; background-color: var(--accent); color: #ffffff; text-decoration: none;
            padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
            transition: background-color 0.2s; margin-right: 8px;
        }
        .btn-action:hover { background-color: var(--accent-hover); }
        .btn-secondary { background-color: var(--soft); color: var(--soft-text); border: 1px solid var(--soft-border); }
        .btn-secondary:hover { background-color: var(--soft-border); }
        button.btn-action { border: none; cursor: pointer; font-family: inherit; }
        .acciones { margin-bottom: 8px; }
        .est-ok  { color: #2d6a3e; font-weight: 700; }
        .est-err { color: var(--danger); font-weight: 700; }

        .footer-main {
            background-color: var(--dark); color: #cfdcda; text-align: center;
            padding: 18px 24px; font-size: 12px; line-height: 1.7; margin-top: 36px;
        }
        .footer-main strong { color: #ffffff; font-size: 13px; }
        .footer-main .integrantes { color: #9fb4b1; }
        .footer-main .sistema { color: #9fb4b1; margin-top: 6px; }

        @media (max-width: 768px) {
            .header-main, .header-sub { flex-direction: column; gap: 8px; text-align: center; }
        }
</style>
</head>
<body>

<header class="header-main">
    <div class="header-brand">
        <img src="../cloud_tux.png" alt="Cloud Tux Logo">
        <span>Panel DBA - Gestor de Prácticas</span>
    </div>
    <div class="header-user">
        <span>Usuario: <strong><?= h($usuario) ?></strong></span>
        <a href="../index.php?action=logout" class="btn-logout">Cerrar Sesión</a>
    </div>
</header>

<div class="header-sub">
    <div><strong>CURSO:</strong> Administración de Bases de Datos</div>
    <div><strong>Profesor:</strong> Manuel Espinoza G.</div>
</div>

<main class="container">
    <h1 class="page-title">Módulo 04 · Mantenimiento básico</h1>
    <p class="page-description">
        Revisión de estadísticas de tablas propias y actualización con <code>ANALYZE</code> de una tabla seleccionada.
    </p>

    <?php if ($flash): ?>
        <div class="<?= $flash[0] === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <div class="acciones">
        <a href="mantenimiento.php" class="btn-action btn-secondary">Actualizar panel</a>
        <a href="../index.php" class="btn-action btn-secondary">← Volver al menú</a>
    </div>

    <h2 class="section-title">Contexto</h2>
    <div class="info-panel">
        <div class="info-item">
            <div class="label">Motor y versión del servidor</div>
            <div class="value"><?= h($ctx['v']) ?></div>
        </div>
        <div class="info-item">
            <div class="label">Base de datos</div>
            <div class="value"><?= h($base) ?></div>
        </div>
        <div class="info-item">
            <div class="label">Usuario de conexión</div>
            <div class="value"><?= h($usuarioConexion) ?></div>
        </div>
        <div class="info-item">
            <div class="label">Fecha y hora</div>
            <div class="value"><?= h($ahora) ?></div>
        </div>
    </div>

    <?php if ($confirmar): ?>
        <h2 class="section-title">Confirmar actualización de estadísticas</h2>
        <div class="alert-warn">
            Base: <strong><?= h($base) ?></strong> · Tabla:
            <strong><?= h($confirmar['schemaname'] . '.' . $confirmar['relname']) ?></strong><br>
            Se ejecutará <code>ANALYZE esquema.tabla</code>, que recalcula las estadísticas que usa el
            planificador de consultas. No borra filas ni modifica datos.
            <div style="margin-top:12px">
                <form method="POST" action="mantenimiento.php" style="display:inline">
                    <input type="hidden" name="token" value="<?= h($_SESSION['token_mant']) ?>">
                    <input type="hidden" name="paso" value="ejecutar">
                    <input type="hidden" name="tabla" value="<?= h($confirmar['schemaname'] . '.' . $confirmar['relname']) ?>">
                    <button type="submit" class="btn-action">Confirmar</button>
                </form>
                <a href="mantenimiento.php" class="btn-action btn-secondary">Cancelar</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($resultado && $resultado['antes'] && $resultado['despues']): ?>
        <h2 class="section-title">Antes y después · <?= h($resultado['tabla']) ?></h2>
        <div class="panel">
            <div class="table-wrap">
                <table class="datos">
                    <thead>
                        <tr>
                            <th></th><th class="num">Filas estimadas</th><th class="num">COUNT(*)</th>
                            <th class="num">Tamaño total</th><th class="num">Tamaño índices</th><th>last_analyze</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (['Antes' => ['antes', 'c_antes'], 'Después' => ['despues', 'c_despues']] as $rot => [$k, $c]): ?>
                        <tr>
                            <td><strong><?= $rot ?></strong></td>
                            <td class="num"><?= h(fmt_filas($resultado[$k]['n_live_tup'])) ?></td>
                            <td class="num"><?= h(fmt_filas($resultado[$c])) ?></td>
                            <td class="num"><?= h(tam_legible($resultado[$k]['total_bytes'])) ?></td>
                            <td class="num"><?= h(tam_legible($resultado[$k]['index_bytes'])) ?></td>
                            <td><?= h(fmt_ts($resultado[$k]['last_analyze'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="nota">
                Filas conservadas:
                <strong class="<?= $resultado['c_antes'] === $resultado['c_despues'] ? 'est-ok' : 'est-err' ?>">
                    <?= $resultado['c_antes'] === $resultado['c_despues'] ? 'Sí' : 'NO' ?></strong>
                (COUNT(*) antes y después). Valores iguales antes y después no significan que la operación falló.
            </p>
        </div>
    <?php endif; ?>

    <h2 class="section-title">Tablas autorizadas</h2>
    <div class="panel">
        <div class="table-wrap">
            <table class="datos">
                <thead>
                    <tr>
                        <th>Esquema.tabla</th>
                        <th class="num">Filas (estimadas)</th>
                        <th class="num">Tamaño total</th>
                        <th class="num">Índices</th>
                        <th class="num">Tamaño índices</th>
                        <th>Último análisis</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$tablas): ?>
                    <tr><td colspan="7">No hay tablas autorizadas en la base.</td></tr>
                <?php endif; ?>
                <?php foreach ($tablas as $clave => $t): ?>
                    <tr>
                        <td><?= h($clave) ?></td>
                        <td class="num"><?= h(fmt_filas($t['n_live_tup'])) ?></td>
                        <td class="num"><?= h(tam_legible($t['total_bytes'])) ?></td>
                        <td class="num"><?= h($t['n_indices']) ?></td>
                        <td class="num"><?= h(tam_legible($t['index_bytes'])) ?></td>
                        <td><?= h(fmt_ts($t['last_analyze'] ?? $t['last_autoanalyze'])) ?></td>
                        <td>
                            <form method="POST" action="mantenimiento.php" style="display:inline">
                                <input type="hidden" name="token" value="<?= h($_SESSION['token_mant']) ?>">
                                <input type="hidden" name="paso" value="confirmar">
                                <input type="hidden" name="tabla" value="<?= h($clave) ?>">
                                <button type="submit" class="btn-action">Actualizar estadísticas</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="nota">
            Las filas son un <strong>valor estimado</strong> por el motor (<code>n_live_tup</code>).
            Solo se listan tablas del esquema propio de la base.
        </p>
    </div>

    <h2 class="section-title">Bitácora de mantenimiento (últimas 10)</h2>
    <div class="panel">
        <div class="table-wrap">
            <table class="datos">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Motor</th><th>Base</th><th>Tabla</th>
                        <th>Acción</th><th>Resultado</th><th>Detalle</th><th>Duración</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="8">La bitácora todavía no tiene registros.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= h($l['fecha'] ?? '') ?></td>
                        <td><?= h($l['motor'] ?? '') ?></td>
                        <td><?= h($l['base'] ?? '') ?></td>
                        <td><?= h($l['tabla'] ?? '') ?></td>
                        <td><?= h($l['accion'] ?? '') ?></td>
                        <td class="<?= ($l['resultado'] ?? '') === 'OK' ? 'est-ok' : 'est-err' ?>"><?= h($l['resultado'] ?? '') ?></td>
                        <td><?= h($l['detalle'] ?? '') ?></td>
                        <td><?= h($l['duracion'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="nota">
            Archivo privado: <code>~/proyecto/mantenimiento.log</code> (fuera de <code>public_html</code>).
            Registra la última operación realizada por esta consola, no el mantenimiento automático del servidor.
        </p>
    </div>
</main>

<footer class="footer-main">
    <strong>Grupo 6 · Cloud TUX</strong><br>
    <span class="integrantes">
        Josué Peñaranda Alvarado · Ximena Ortiz Llanes ·
        Diego Alvarado Salazar · Jesús Barboza Rios
    </span>
    <div class="sistema">
        Motor: <?= h(MOTOR_NOMBRE) ?> · Base: <?= h($base) ?> ·
        Usuario de conexión: <?= h($usuarioConexion) ?> ·
        Consola DBA v<?= h(CONSOLA_VERSION) ?>
    </div>
</footer>

</body>
</html>
