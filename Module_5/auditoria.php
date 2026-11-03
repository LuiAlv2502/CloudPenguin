<?php

session_start();
require_once __DIR__ . '/../conexion.php';
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login.php');
    exit;
}
$usuario = $_SESSION['usuario'] ?? 'estudiante';

if (empty($_SESSION['token_aud'])) {
    $_SESSION['token_aud'] = bin2hex(random_bytes(16));
}

function q_ident(string $id): string
{
    return '"' . str_replace('"', '""', $id) . '"';
}

function catalogo(PDO $pdo): array
{
    return $pdo->query("
        SELECT relname FROM pg_stat_user_tables
        WHERE schemaname = 'public' AND relname NOT LIKE 'dba\_%'
        ORDER BY relname
    ")->fetchAll(PDO::FETCH_COLUMN);
}


function activas(PDO $pdo): array
{
    return $pdo->query(
	"SELECT c.relname FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    WHERE t.tgname = 'trg_aud_' || c.relname"
	)->fetchAll(PDO::FETCH_COLUMN);
}

$error = null;
$ctx = [];
$tablas = [];
$conAuditoria = [];
$historial = [];
$instalado = false;
$filtro = (string)($_GET['tabla'] ?? '');

try {
    $pdo = conectar();
    $instalado = (bool)$pdo->query("
        SELECT to_regclass('dba_auditoria') IS NOT NULL
           AND EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'dba_fn_auditar')
    ")->fetchColumn();

    $tablas = catalogo($pdo);

// auditoria
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tabla  = (string)($_POST['tabla'] ?? '');
        $accion = (string)($_POST['accion'] ?? '');

        if (!isset($_POST['token']) || !hash_equals($_SESSION['token_aud'], (string)$_POST['token'])) {
            $_SESSION['flash_aud'] = ['error', 'Solicitud no válida. Recargue la página e intente de nuevo.'];
        } elseif (!$instalado) {
            $_SESSION['flash_aud'] = ['error', 'El módulo no está instalado: ejecute Module_5/scripts/auditoria.sql.'];
        } elseif (!in_array($tabla, $tablas, true) || !in_array($accion, ['activar', 'desactivar'], true)) {
            $_SESSION['flash_aud'] = ['error', 'Tabla no permitida. No se ejecutó ninguna operación.'];
        } else {
            $activa  = in_array($tabla, activas($pdo), true);
            $trigger = q_ident('trg_aud_' . $tabla);   // nombre fijo a partir del catálogo
            $tablaId = q_ident($tabla);

            if ($accion === 'activar' && $activa) {
                $_SESSION['flash_aud'] = ['error', "La tabla $tabla ya tiene auditoría activa."];
            } elseif ($accion === 'desactivar' && !$activa) {
                $_SESSION['flash_aud'] = ['error', "La tabla $tabla no posee auditoría activa."];
            } elseif ($accion === 'activar') {
                $pdo->exec("CREATE TRIGGER $trigger AFTER INSERT OR UPDATE OR DELETE ON $tablaId
                            FOR EACH ROW EXECUTE FUNCTION dba_fn_auditar()");
                $_SESSION['flash_aud'] = ['ok', "Auditoría activada en $tabla."];
            } else {
                $pdo->exec("DROP TRIGGER IF EXISTS $trigger ON $tablaId");
                $_SESSION['flash_aud'] = ['ok', "Auditoría desactivada en $tabla. El historial se conserva."];
            }
        }
        header('Location: auditoria.php');
        exit;
    }

    $ctx = $pdo->query("
        SELECT current_database() AS base, current_user AS usuario,
               to_char(now(), 'DD/MM/YYYY HH24:MI:SS') AS fecha
    ")->fetch();

    $conAuditoria = activas($pdo);

    if ($filtro !== '' && !in_array($filtro, $tablas, true)) {
        $error  = 'Tabla no permitida en el filtro. Se muestra el historial completo.';
        $filtro = '';
    }

    if ($instalado) {
        $sql = 
				"SELECT id, to_char(fecha, 'DD/MM/YYYY HH24:MI:SS') AS fecha, usuario, tabla, operacion,
                   CASE operacion
                     WHEN 'UPDATE' THEN (
                         SELECT string_agg(n.key || ': ' || COALESCE(o.value #>> '{}', 'NULL')
                                           || ' → ' || COALESCE(n.value #>> '{}', 'NULL'), ', ' ORDER BY n.key)
                         FROM jsonb_each(despues) n
                         LEFT JOIN jsonb_each(antes) o ON o.key = n.key
                         WHERE o.value IS DISTINCT FROM n.value)
                     WHEN 'INSERT' THEN despues::text
                     ELSE antes::text
                   END AS cambio
            FROM dba_auditoria" . ($filtro !== '' ? ' WHERE tabla = :tabla' : '') . "
            ORDER BY id DESC
            LIMIT 50";
        $st = $pdo->prepare($sql);
        $st->execute($filtro !== '' ? [':tabla' => $filtro] : []);
        $historial = $st->fetchAll();
    }
} catch (PDOException $e) {
    $error = 'Error del motor (código ' . h($e->getCode()) . ').';
}

$flash = $_SESSION['flash_aud'] ?? null;
unset($_SESSION['flash_aud']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulo 05 · Auditoría de cambios - Panel DBA</title>
    <style>
        :root {
            --bg: #f4f6f5; --text: #1f2d2b; --muted: #61716e; --dark: #1f3a38;
            --accent: #3f7d78; --accent-hover: #316662; --soft: #e4eeec;
            --soft-border: #c9dbd8; --soft-text: #2f5d59; --border: #dde5e3;
            --danger: #a8514c; --danger-hover: #8d413d;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background: var(--bg); color: var(--text); min-height: 100vh;
            display: flex; flex-direction: column;
        }
        .header-main {
            background: var(--dark); color: #fff; display: flex; justify-content: space-between;
            align-items: center; padding: 10px 24px;
        }
        .header-brand, .header-user { display: flex; align-items: center; gap: 12px; }
        .header-brand { font-size: 18px; font-weight: 700; }
        .header-brand img { height: 38px; width: auto; }
        .header-user { font-size: 14px; }
        .btn-logout {
            background: var(--danger); color: #fff; text-decoration: none; padding: 6px 14px;
            border-radius: 6px; font-weight: 600; font-size: 13px;
        }
        .header-sub {
            background: var(--soft); color: var(--soft-text); display: flex;
            justify-content: space-between; padding: 8px 24px; font-size: 13px;
            border-bottom: 1px solid var(--soft-border);
        }
        .container { flex: 1; width: 100%; max-width: 1200px; margin: 36px auto; padding: 0 24px; }
        .page-title { font-size: 24px; margin-bottom: 8px; }
        .page-description { color: var(--muted); font-size: 14px; margin-bottom: 22px; }
        .actions { display: flex; gap: 10px; margin-bottom: 22px; }
        .btn-action {
            display: inline-block; background: var(--accent); color: #fff; border: 0;
            text-decoration: none; padding: 7px 14px; border-radius: 6px;
            font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit;
        }
        .btn-action:hover { background: var(--accent-hover); }
        .btn-secondary { background: #fff; color: var(--dark); border: 1px solid var(--border); }
        .btn-off { background: var(--danger); }
        .btn-off:hover { background: var(--danger-hover); }
        .panel {
            background: #fff; border: 1px solid var(--border); border-radius: 10px;
            padding: 20px; margin-bottom: 20px;
        }
        .section-title { color: var(--dark); font-size: 17px; margin: 26px 0 12px; }
        .context { color: var(--muted); font-size: 13px; margin-bottom: 16px; }
        .selector { display: flex; align-items: end; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        select {
            min-width: 240px; padding: 8px 10px; border: 1px solid var(--border);
            border-radius: 6px; background: #fff; color: var(--text);
        }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th {
            background: var(--soft); color: var(--soft-text); text-align: left;
            padding: 10px 12px; border-bottom: 2px solid var(--soft-border); white-space: nowrap;
        }
        td { padding: 9px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
        code { overflow-wrap: anywhere; font-size: 12px; }
        .on  { color: #2d6a3e; font-weight: 700; }
        .off { color: var(--muted); }
        .alert-ok, .alert-error, .alert-aviso {
            padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px;
        }
        .alert-ok    { background: #e4f2e8; color: #2d6a3e; border: 1px solid #bfdcc7; }
        .alert-error { background: #f6e3e1; color: var(--danger-hover); border: 1px solid #e6c3bf; }
        .alert-aviso { background: #fbf0e4; color: #7a4c17; border: 1px solid #ecd3b4; }
        .empty { color: var(--muted); padding: 14px 0; font-size: 14px; }
        .footer-main {
            background: var(--dark); color: #cfdcda; text-align: center;
            padding: 18px 24px; font-size: 12px; line-height: 1.7; margin-top: 24px;
        }
        @media (max-width: 700px) {
            .header-main, .header-sub { flex-direction: column; gap: 8px; text-align: center; }
            .container { margin: 24px auto; padding: 0 14px; }
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
    <h1 class="page-title">Módulo 05 · Auditoría de cambios</h1>
    <p class="page-description">
        Registra automáticamente cada INSERT, UPDATE y DELETE de las tablas auditadas
        (quién, cuándo, valor anterior y nuevo). No modifica los datos de las tablas.
    </p>
    <div class="actions">
        <a href="../index.php" class="btn-action btn-secondary">← Volver al menú</a>
        <a href="auditoria.php" class="btn-action btn-secondary">Actualizar</a>
    </div>

    <?php if ($flash): ?>
        <div class="<?= $flash[0] === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($ctx): ?>
        <p class="context">
            Motor: <strong><?= h(MOTOR_NOMBRE) ?></strong> · Base: <strong><?= h($ctx['base']) ?></strong> ·
            Usuario: <strong><?= h($ctx['usuario']) ?></strong> · Fecha y hora: <?= h($ctx['fecha']) ?>
        </p>
    <?php endif; ?>

    <?php if (!$instalado && !$error): ?>
        <div class="alert-aviso">
            El módulo no está instalado en esta base. Ejecute una vez:
            <code>psql -d <?= h($ctx['base'] ?? 'su_base') ?> -f Module_5/scripts/auditoria.sql</code>
        </div>
    <?php endif; ?>

    <?php if ($instalado): ?>
        <!-- ===== Tablas: activar / desactivar ===== -->
        <h2 class="section-title">Tablas autorizadas</h2>
        <div class="panel table-wrap">
            <table>
                <tr><th>Tabla</th><th>Auditoría</th><th>Acción</th></tr>
                <?php foreach ($tablas as $t): $on = in_array($t, $conAuditoria, true); ?>
                    <tr>
                        <td>public.<?= h($t) ?></td>
                        <td class="<?= $on ? 'on' : 'off' ?>"><?= $on ? 'Activa' : 'Inactiva' ?></td>
                        <td>
                            <form method="POST" action="auditoria.php">
                                <input type="hidden" name="token" value="<?= h($_SESSION['token_aud']) ?>">
                                <input type="hidden" name="tabla" value="<?= h($t) ?>">
                                <input type="hidden" name="accion" value="<?= $on ? 'desactivar' : 'activar' ?>">
                                <button type="submit" class="btn-action <?= $on ? 'btn-off' : '' ?>">
                                    <?= $on ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- ===== Historial con filtro por tabla ===== -->
        <h2 class="section-title">Historial de cambios (últimos 50)</h2>
        <div class="panel">
            <form method="GET" action="auditoria.php" class="selector">
                <div>
                    <label for="tabla">Filtrar por tabla</label>
                    <select id="tabla" name="tabla">
                        <option value="">Todas</option>
                        <?php foreach ($tablas as $t): ?>
                            <option value="<?= h($t) ?>" <?= $t === $filtro ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-action">Filtrar</button>
            </form>

            <?php if (!$historial): ?>
                <p class="empty">Todavía no hay cambios registrados<?= $filtro !== '' ? ' para ' . h($filtro) : '' ?>.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <tr><th>#</th><th>Fecha</th><th>Usuario</th><th>Tabla</th><th>Operación</th><th>Cambio</th></tr>
                        <?php foreach ($historial as $r): ?>
                            <tr>
                                <td><?= (int)$r['id'] ?></td>
                                <td><?= h($r['fecha']) ?></td>
                                <td><?= h($r['usuario']) ?></td>
                                <td><?= h($r['tabla']) ?></td>
                                <td><strong><?= h($r['operacion']) ?></strong></td>
                                <td><code><?= h($r['cambio'] ?? 'Sin cambios en los valores') ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<footer class="footer-main">
    <strong>Grupo 6 · Cloud TUX</strong><br>
    Motor: <?= h(MOTOR_NOMBRE) ?> · Base: <?= h($ctx['base'] ?? env('DB_NAME')) ?> ·
    Usuario de conexión: <?= h($ctx['usuario'] ?? db_usuario()) ?> · Consola DBA v<?= h(CONSOLA_VERSION) ?>
</footer>
</body>
</html>
