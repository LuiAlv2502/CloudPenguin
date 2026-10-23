<?php
session_start();
require_once 'conexion.php';

// Control de acceso
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'] ?? 'estudiante';

// Rutas FIJAS, calculadas a partir del HOME de la cuenta (nada viene de GET/POST)
$home     = env_home();
$dirResp  = $home . '/proyecto/respaldos';
$script   = $home . '/dba_scripts/backup.sh';
$bitacora = $dirResp . '/respaldos.log';

if (empty($_SESSION['token_resp'])) {
    $_SESSION['token_resp'] = bin2hex(random_bytes(16));
}

/** ¿Está permitido usar exec() en este servidor? */
function exec_disponible(): bool
{
    $off = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('exec') && !in_array('exec', $off, true);
}

// ---------- Acción permitida: Generar respaldo ----------
// Única acción ejecutable: PHP decide qué script autorizado correr.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar') {
    $tokenOk = isset($_POST['token']) && hash_equals($_SESSION['token_resp'], (string)$_POST['token']);

    if (!$tokenOk) {
        $_SESSION['flash'] = ['error', 'Solicitud no válida. Recargue la página e intente de nuevo.'];
    } elseif (!is_file($script) || !is_executable($script)) {
        $_SESSION['flash'] = ['error', 'No se encontró el script de respaldo o no tiene permiso de ejecución.'];
    } elseif (!exec_disponible()) {
        $_SESSION['flash'] = ['error', 'La función exec() está deshabilitada en este servidor.'];
    } else {
        $salida = [];
        $codigo = 1;
        exec(escapeshellarg($script) . ' 2>&1', $salida, $codigo);
        $_SESSION['flash'] = ($codigo === 0)
            ? ['ok', 'Respaldo generado correctamente: ' . trim(implode(' ', $salida))]
            : ['error', 'ERROR al generar el respaldo. Revise la bitácora.'];
    }
    header('Location: respaldos.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Utilidades ----------
function tam_legible(int $b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 2, ',', '.') . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1, ',', '.') . ' KB';
    return $b . ' B';
}

/** Estado del respaldo: OK si tiene contenido y firma gzip válida */
function estado_respaldo(string $ruta): string
{
    if (!is_file($ruta) || filesize($ruta) === 0) {
        return 'ERROR';
    }
    $f = fopen($ruta, 'rb');
    $firma = fread($f, 2);
    fclose($f);
    return $firma === "\x1f\x8b" ? 'OK' : 'ERROR';
}

// ---------- Listado de respaldos ----------
$respaldos = [];
$totalBytes = 0;
foreach (glob($dirResp . '/respaldo_*.sql.gz') ?: [] as $ruta) {
    $bytes = (int)filesize($ruta);
    $totalBytes += $bytes;
    $respaldos[] = [
        'archivo' => basename($ruta),
        'mtime'   => filemtime($ruta),
        'bytes'   => $bytes,
        'estado'  => estado_respaldo($ruta),
    ];
}
usort($respaldos, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$cantidad = count($respaldos);
$masNuevo = $respaldos[0] ?? null;
$masViejo = $respaldos[$cantidad - 1] ?? null;

// ---------- Bitácora (últimas 10 líneas) ----------
$lineasLog = [];
if (is_readable($bitacora)) {
    $todas = file($bitacora, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lineasLog = array_reverse(array_slice($todas, -10));
}

$baseDatos = env('DB_NAME');
$usuarioConexion = db_usuario();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Respaldos - Panel DBA</title>
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
        table.datos tr.top td { background: var(--warn-bg); }
        .badge {
            display: inline-block; background: var(--warn); color: #fff; font-size: 10px;
            font-weight: 700; padding: 2px 7px; border-radius: 10px; margin-left: 6px; vertical-align: middle;
        }

        .bar-row { display: grid; grid-template-columns: 180px 1fr 190px; gap: 12px; align-items: center; margin-bottom: 10px; font-size: 13px; }
        .bar-track { background: var(--soft); border-radius: 6px; height: 14px; overflow: hidden; }
        .bar-fill { background: var(--accent); height: 100%; border-radius: 6px; }
        .bar-row:first-child .bar-fill { background: var(--warn); }
        .bar-size { text-align: right; color: var(--muted); }

        .alert-error {
            background: #f6e3e1; color: var(--danger-hover); border: 1px solid #e6c3bf;
            padding: 14px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; line-height: 1.6;
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

        .footer-main {
            background-color: var(--dark); color: #cfdcda; text-align: center;
            padding: 18px 24px; font-size: 12px; line-height: 1.7; margin-top: 36px;
        }
        .footer-main strong { color: #ffffff; font-size: 13px; }
        .footer-main .integrantes { color: #9fb4b1; }
        .footer-main .sistema { color: #9fb4b1; margin-top: 6px; }

        @media (max-width: 768px) {
            .header-main, .header-sub { flex-direction: column; gap: 8px; text-align: center; }
            .bar-row { grid-template-columns: 1fr; gap: 4px; }
            .bar-size { text-align: left; }
        }
    .alert-ok {
      background: #e4f2e8; color: #2d6a3e; border: 1px solid #bfdcc7;
      padding: 14px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; line-height: 1.6;
    }
    button.btn-action { border: none; cursor: pointer; font-family: inherit; }
    .est-ok  { color: #2d6a3e; font-weight: 700; }
    .est-err { color: var(--danger); font-weight: 700; }
    .acciones { margin-bottom: 8px; }
    pre.log {
      background: #1f2d2b; color: #d7e6e3; padding: 14px 16px; border-radius: 8px;
      font-size: 12px; line-height: 1.7; overflow-x: auto;
    }
</style>
</head>
<body>

<header class="header-main">
    <div class="header-brand">
        <img src="cloud_tux.png" alt="Cloud Tux Logo">
        <span>Panel DBA - Gestor de Prácticas</span>
    </div>
    <div class="header-user">
        <span>Usuario: <strong><?= h($usuario) ?></strong></span>
        <a href="index.php?action=logout" class="btn-logout">Cerrar Sesión</a>
    </div>
</header>

<div class="header-sub">
    <div><strong>CURSO:</strong> Administración de Bases de Datos</div>
    <div><strong>Profesor:</strong> Manuel Espinoza G.</div>
</div>

<main class="container">
    <h1 class="page-title">Proyecto 02 · Respaldos</h1>
    <p class="page-description">
        Generación y consulta de respaldos comprimidos de la base de datos con <code>pg_dump</code>.
    </p>

    <?php if ($flash): ?>
        <div class="<?= $flash[0] === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <div class="acciones">
        <form method="POST" action="respaldos.php" style="display:inline">
            <input type="hidden" name="token" value="<?= h($_SESSION['token_resp']) ?>">
            <input type="hidden" name="accion" value="generar">
            <button type="submit" class="btn-action">Generar nuevo respaldo</button>
        </form>
        <a href="respaldos.php" class="btn-action btn-secondary">Actualizar listado</a>
        <a href="index.php" class="btn-action btn-secondary">← Volver al menú</a>
    </div>

    <h2 class="section-title">Resumen</h2>
    <div class="info-panel">
        <div class="info-item">
            <div class="label">Cantidad de respaldos</div>
            <div class="value"><?= (int)$cantidad ?></div>
        </div>
        <div class="info-item">
            <div class="label">Espacio total utilizado</div>
            <div class="value"><?= h(tam_legible($totalBytes)) ?></div>
        </div>
        <div class="info-item">
            <div class="label">Respaldo más reciente</div>
            <div class="value"><?= $masNuevo ? h(date('d/m/Y H:i:s', $masNuevo['mtime'])) : '—' ?></div>
        </div>
        <div class="info-item">
            <div class="label">Respaldo más antiguo</div>
            <div class="value"><?= $masViejo ? h(date('d/m/Y H:i:s', $masViejo['mtime'])) : '—' ?></div>
        </div>
    </div>

    <h2 class="section-title">Listado de respaldos</h2>
    <div class="panel">
        <div class="table-wrap">
            <table class="datos">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Fecha</th>
                        <th class="num">Tamaño</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$respaldos): ?>
                    <tr><td colspan="4">Todavía no hay respaldos. Use «Generar nuevo respaldo».</td></tr>
                <?php endif; ?>
                <?php foreach ($respaldos as $r): ?>
                    <tr>
                        <td><?= h($r['archivo']) ?></td>
                        <td><?= h(date('d/m/Y H:i:s', $r['mtime'])) ?></td>
                        <td class="num"><?= h(tam_legible($r['bytes'])) ?></td>
                        <td class="<?= $r['estado'] === 'OK' ? 'est-ok' : 'est-err' ?>"><?= h($r['estado']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="nota">
            Carpeta de respaldos: <code>~/proyecto/respaldos/</code> (fuera de <code>public_html</code>).
            El estado se valida comprobando que el archivo tenga contenido y firma gzip válida.
        </p>
    </div>

    <h2 class="section-title">Bitácora de ejecución (últimas 10)</h2>
    <div class="panel">
        <?php if ($lineasLog): ?>
            <pre class="log"><?= h(implode("\n", $lineasLog)) ?></pre>
        <?php else: ?>
            <p class="nota">La bitácora todavía no tiene registros.</p>
        <?php endif; ?>
        <p class="nota">Formato: <code>fecha y hora | archivo generado | OK/ERROR</code></p>
    </div>
</main>

<footer class="footer-main">
    <strong>Grupo 6 · Cloud TUX</strong><br>
    <span class="integrantes">
        Josué Peñaranda Alvarado · Ximena Ortiz Llanes ·
        Diego Alvarado Salazar · Jesús Barboza Rios
    </span>
    <div class="sistema">
        Motor: <?= h(MOTOR_NOMBRE) ?> · Base: <?= h($baseDatos) ?> ·
        Usuario de conexión: <?= h($usuarioConexion) ?> ·
        Consola DBA v<?= h(CONSOLA_VERSION) ?>
    </div>
</footer>

</body>
</html>
