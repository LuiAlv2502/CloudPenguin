<?php
session_start();
require_once 'conexion.php';

// Control de acceso
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: login.php');
    exit;
}

$usuario     = $_SESSION['usuario'] ?? 'estudiante';
$error       = null;
$info        = [];
$tablas      = [];
$tamanoBytes = 0;

try {
    $pdo = conectar();

    // --- Información general ---
    // version(), current_database(), current_user y now() son funciones del motor.
    // pg_database_size() devuelve el tamaño total de la base en bytes.
    $info = $pdo->query("
        SELECT
            version()                                            AS version_motor,
            current_database()                                   AS base_datos,
            current_user                                         AS usuario_conectado,
            to_char(now(), 'DD/MM/YYYY HH24:MI:SS')              AS fecha_hora,
            pg_database_size(current_database())                 AS tamano_bytes,
            pg_size_pretty(pg_database_size(current_database())) AS tamano_legible
    ")->fetch();

    $tamanoBytes = (int)$info['tamano_bytes'];

    // --- Tablas de usuario con tamaños y estadísticas ---
    // pg_stat_user_tables: actividad y filas estimadas por tabla de usuario.
    // pg_relation_size():       solo datos de la tabla (main fork).
    // pg_indexes_size():        solo índices de la tabla.
    // pg_total_relation_size(): datos + índices + TOAST.
    // n_live_tup es una estimación; si aún es 0 (sin ANALYZE) se usa pg_class.reltuples.
    $tablas = $pdo->query("
        SELECT
            s.schemaname                                    AS esquema,
            s.relname                                       AS tabla,
            pg_size_pretty(pg_relation_size(s.relid))       AS datos,
            pg_size_pretty(pg_indexes_size(s.relid))        AS indices,
            pg_total_relation_size(s.relid)                 AS total_bytes,
            pg_size_pretty(pg_total_relation_size(s.relid)) AS total,
            GREATEST(
                CASE WHEN s.n_live_tup > 0 THEN s.n_live_tup ELSE c.reltuples::bigint END,
                0
            )                                               AS filas_estimadas,
            s.n_dead_tup                                    AS filas_muertas,
            s.seq_scan,
            s.idx_scan
        FROM pg_stat_user_tables s
        JOIN pg_class c ON c.oid = s.relid
        ORDER BY pg_total_relation_size(s.relid) DESC, s.relname ASC
    ")->fetchAll();

} catch (PDOException $e) {
    $error = $e->getMessage();
}

$cantidadTablas = count($tablas);
$topN           = array_slice($tablas, 0, 5);
$maxTotal       = $topN ? max(1, (int)$topN[0]['total_bytes']) : 1;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conexión y Estado del Servidor - Panel DBA</title>
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
        <h1 class="page-title">Conexión y Estado del Servidor</h1>
        <p class="page-description">
            Información del motor, tamaño de la base de datos y consumo de espacio por tabla.
        </p>

        <?php if ($error): ?>
            <div class="alert-error">
                <strong>No fue posible conectar con la base de datos.</strong><br>
                <?= h($error) ?><br>
                Revise los datos de <code>conexion.php</code> y que la extensión <code>pdo_pgsql</code> esté habilitada.
            </div>
            <a href="index.php" class="btn-action btn-secondary">← Volver al menú</a>
        <?php else: ?>

        <h2 class="section-title" style="margin-top:0">Información general</h2>
        <div class="info-panel">
            <div class="info-item">
                <div class="label">Motor utilizado</div>
                <div class="value"><?= h(MOTOR_NOMBRE) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Nombre de la base de datos</div>
                <div class="value"><?= h($info['base_datos']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Usuario conectado</div>
                <div class="value"><?= h($info['usuario_conectado']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Fecha y hora de la consulta</div>
                <div class="value"><?= h($info['fecha_hora']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Tamaño aproximado de la base</div>
                <div class="value"><?= h($info['tamano_legible']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Cantidad de tablas</div>
                <div class="value"><?= (int)$cantidadTablas ?></div>
            </div>
        </div>
        <p class="nota">Versión del motor: <?= h($info['version_motor']) ?></p>

        <h2 class="section-title">Tablas que consumen mayor espacio (Top 5)</h2>
        <div class="panel">
            <?php if (!$topN): ?>
                <p class="nota">No hay tablas de usuario en esta base de datos.</p>
            <?php else: ?>
                <?php foreach ($topN as $t):
                    $pct     = round(((int)$t['total_bytes'] / $maxTotal) * 100);
                    $pctBase = $tamanoBytes > 0 ? round(((int)$t['total_bytes'] / $tamanoBytes) * 100, 1) : 0;
                ?>
                <div class="bar-row">
                    <div><strong><?= h($t['esquema']) ?>.<?= h($t['tabla']) ?></strong></div>
                    <div class="bar-track"><div class="bar-fill" style="width: <?= $pct ?>%"></div></div>
                    <div class="bar-size"><?= h($t['total']) ?> (<?= $pctBase ?>% de la base)</div>
                </div>
                <?php endforeach; ?>
                <p class="nota">El porcentaje se calcula con <code>pg_total_relation_size()</code> de la tabla sobre <code>pg_database_size()</code> de la base.</p>
            <?php endif; ?>
        </div>

        <h2 class="section-title">Listado de tablas</h2>
        <div class="panel">
            <div class="table-wrap">
                <table class="datos">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Esquema</th>
                            <th>Tabla</th>
                            <th class="num">Datos</th>
                            <th class="num">Índices</th>
                            <th class="num">Tamaño total</th>
                            <th class="num">Registros (est.)</th>
                            <th class="num">Filas muertas</th>
                            <th class="num">Seq scan</th>
                            <th class="num">Idx scan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$tablas): ?>
                        <tr><td colspan="10">No hay tablas de usuario.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tablas as $i => $t): ?>
                        <tr class="<?= $i < 3 ? 'top' : '' ?>">
                            <td><?= $i + 1 ?></td>
                            <td><?= h($t['esquema']) ?></td>
                            <td>
                                <?= h($t['tabla']) ?>
                                <?php if ($i < 3): ?><span class="badge">Mayor consumo</span><?php endif; ?>
                            </td>
                            <td class="num"><?= h($t['datos']) ?></td>
                            <td class="num"><?= h($t['indices']) ?></td>
                            <td class="num"><strong><?= h($t['total']) ?></strong></td>
                            <td class="num"><?= number_format((int)$t['filas_estimadas'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format((int)$t['filas_muertas'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format((int)$t['seq_scan'], 0, ',', '.') ?></td>
                            <td class="num"><?= number_format((int)$t['idx_scan'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="nota">
                Ordenadas de mayor a menor tamaño total; las 3 primeras se resaltan como las de mayor consumo.
                El número de registros es una <strong>estimación</strong> (<code>n_live_tup</code> de <code>pg_stat_user_tables</code>,
                o <code>reltuples</code> si aún no se ha ejecutado ANALYZE); para un conteo exacto se usaría <code>SELECT COUNT(*)</code>.
            </p>
        </div>

        <h2 class="section-title">Funciones y catálogos utilizados</h2>
        <div class="panel">
            <div class="table-wrap">
                <table class="datos">
                    <thead><tr><th>Función / Catálogo</th><th>Qué devuelve</th><th>Dónde se usa</th></tr></thead>
                    <tbody>
                        <tr><td><code>pg_database_size()</code></td><td>Tamaño total de la base de datos en bytes</td><td>Tamaño aproximado de la base</td></tr>
                        <tr><td><code>pg_relation_size()</code></td><td>Tamaño solo de los datos de la tabla (sin índices ni TOAST)</td><td>Columna «Datos»</td></tr>
                        <tr><td><code>pg_indexes_size()</code></td><td>Tamaño de todos los índices de la tabla</td><td>Columna «Índices»</td></tr>
                        <tr><td><code>pg_total_relation_size()</code></td><td>Datos + índices + TOAST</td><td>Tamaño total y ranking de consumo</td></tr>
                        <tr><td><code>pg_size_pretty()</code></td><td>Convierte bytes a KB, MB, GB</td><td>Todas las columnas de tamaño</td></tr>
                        <tr><td><code>pg_stat_user_tables</code></td><td>Estadísticas de actividad por tabla de usuario</td><td>Listado de tablas, filas estimadas, scans y filas muertas</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top:28px">
            <a href="index.php" class="btn-action btn-secondary">← Volver al menú</a>
            <a href="estado_servidor.php" class="btn-action">Actualizar consulta</a>
        </div>

        <?php endif; ?>
    </main>

    <footer class="footer-main">
        <strong>Grupo 6 · Cloud TUX</strong><br>
        <span class="integrantes">
            Josué Peñaranda Alvarado · Ximena Ortiz Llanes ·
            Diego Alvarado Salazar · Jesús Barboza Rios
        </span>
        <div class="sistema">
            Motor: <?= h(MOTOR_NOMBRE) ?> · Base: <?= h($info['base_datos'] ?? DB_NAME) ?> ·
            Usuario de conexión: <?= h($info['usuario_conectado'] ?? DB_USER) ?> ·
            Consola DBA v<?= h(CONSOLA_VERSION) ?>
        </div>
    </footer>
</body>
</html>
