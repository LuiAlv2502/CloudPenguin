<?php
session_start();
require_once __DIR__ . '/../conexion.php';

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login.php');
    exit;
}

$usuario = $_SESSION['usuario'] ?? 'estudiante';
$tablas = [];
$columnas = [];
$restricciones = [];
$indices = [];
$error = null;
$tablaSeleccionada = isset($_GET['tabla']) ? (string)$_GET['tabla'] : '';
$contexto = [];

try {
    $pdo = conectar();
    $contexto = $pdo->query(
        'SELECT current_database() AS base, current_user AS usuario'
    )->fetch();

    $tablas = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    if ($tablaSeleccionada !== '') {
        if (!in_array($tablaSeleccionada, $tablas, true)) {
            $error = 'La tabla seleccionada no existe o no está disponible en el esquema public.';
            $tablaSeleccionada = '';
        } else {
            $stmt = $pdo->prepare("
                SELECT column_name, data_type, is_nullable, column_default,
                       character_maximum_length, numeric_precision, numeric_scale
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = :tabla
                ORDER BY ordinal_position
            ");
            $stmt->execute([':tabla' => $tablaSeleccionada]);
            $columnas = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT con.conname AS constraint_name,
                       CASE con.contype
                           WHEN 'p' THEN 'PRIMARY KEY'
                           WHEN 'f' THEN 'FOREIGN KEY'
                           WHEN 'u' THEN 'UNIQUE'
                           WHEN 'c' THEN 'CHECK'
                           WHEN 'x' THEN 'EXCLUDE'
                           ELSE con.contype::text
                       END AS constraint_type,
                       pg_get_constraintdef(con.oid) AS definition
                FROM pg_catalog.pg_constraint con
                JOIN pg_catalog.pg_class tbl ON tbl.oid = con.conrelid
                JOIN pg_catalog.pg_namespace ns ON ns.oid = tbl.relnamespace
                WHERE ns.nspname = 'public' AND tbl.relname = :tabla
                ORDER BY con.conname
            ");
            $stmt->execute([':tabla' => $tablaSeleccionada]);
            $restricciones = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT indexname, indexdef
                FROM pg_indexes
                WHERE schemaname = 'public' AND tablename = :tabla
                ORDER BY indexname
            ");
            $stmt->execute([':tabla' => $tablaSeleccionada]);
            $indices = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $error = 'No fue posible consultar el diccionario de datos. Código: ' . h($e->getCode());
}

function tipo_columna(array $columna): string
{
    $tipo = $columna['data_type'];
    if ($columna['character_maximum_length'] !== null) {
        $tipo .= '(' . $columna['character_maximum_length'] . ')';
    } elseif (
        in_array($columna['data_type'], ['numeric', 'decimal'], true)
        && $columna['numeric_precision'] !== null
        && $columna['numeric_scale'] !== null
    ) {
        $tipo .= '(' . $columna['numeric_precision'] . ', ' . $columna['numeric_scale'] . ')';
    }
    return $tipo;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulo 06 · Diccionario de datos - Panel DBA</title>
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
            text-decoration: none; padding: 8px 16px; border-radius: 6px;
            font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-action:hover { background: var(--accent-hover); }
        .btn-secondary { background: #fff; color: var(--dark); border: 1px solid var(--border); }
        .panel {
            background: #fff; border: 1px solid var(--border); border-radius: 10px;
            padding: 20px; margin-bottom: 20px;
        }
        .section-title { color: var(--dark); font-size: 17px; margin: 26px 0 12px; }
        .context { color: var(--muted); font-size: 13px; margin-bottom: 16px; }
        .selector { display: flex; align-items: end; gap: 10px; flex-wrap: wrap; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        select {
            min-width: 240px; padding: 9px 10px; border: 1px solid var(--border);
            border-radius: 6px; background: #fff; color: var(--text);
        }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th {
            background: var(--soft); color: var(--soft-text); text-align: left;
            padding: 10px 12px; border-bottom: 2px solid var(--soft-border); white-space: nowrap;
        }
        td { padding: 9px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
        code { overflow-wrap: anywhere; }
        .alert-error {
            background: #f6e3e1; color: var(--danger-hover); border: 1px solid #e6c3bf;
            padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px;
        }
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
    <h1 class="page-title">Módulo 06 · Explorador del diccionario de datos</h1>
    <p class="page-description">
        Consulte columnas, restricciones e índices de las tablas propias del esquema public.
        Esta pantalla solo consulta metadatos; no modifica tablas ni registros.
    </p>
    <div class="actions">
        <a href="../index.php" class="btn-action btn-secondary">← Volver al menú</a>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($contexto): ?>
        <p class="context">
            Base de datos: <strong><?= h($contexto['base']) ?></strong>
            · Usuario de conexión: <strong><?= h($contexto['usuario']) ?></strong>
            · Esquema: <strong>public</strong>
        </p>
    <?php endif; ?>

    <section class="panel">
        <form method="get" action="diccionario.php" class="selector">
            <div>
                <label for="tabla">Tabla propia</label>
                <select id="tabla" name="tabla" required>
                    <option value="">Seleccione una tabla</option>
                    <?php foreach ($tablas as $tabla): ?>
                        <option value="<?= h($tabla) ?>" <?= $tabla === $tablaSeleccionada ? 'selected' : '' ?>>
                            <?= h($tabla) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-action">Explorar estructura</button>
        </form>
        <?php if (!$tablas): ?>
            <p class="empty">No se encontraron tablas propias visibles en el esquema public.</p>
        <?php endif; ?>
    </section>

    <?php if ($tablaSeleccionada !== ''): ?>
        <h2 class="section-title">Columnas · <?= h($tablaSeleccionada) ?></h2>
        <section class="panel table-wrap">
            <?php if ($columnas): ?>
                <table>
                    <thead>
                    <tr><th>Posición</th><th>Columna</th><th>Tipo de dato</th><th>Acepta NULL</th><th>Valor predeterminado</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($columnas as $posicion => $columna): ?>
                        <tr>
                            <td><?= h($posicion + 1) ?></td>
                            <td><strong><?= h($columna['column_name']) ?></strong></td>
                            <td><?= h(tipo_columna($columna)) ?></td>
                            <td><?= $columna['is_nullable'] === 'YES' ? 'Sí' : 'No' ?></td>
                            <td><?= $columna['column_default'] !== null ? h($columna['column_default']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No hay columnas visibles para esta tabla.</p>
            <?php endif; ?>
        </section>

        <h2 class="section-title">Llaves y restricciones</h2>
        <section class="panel table-wrap">
            <?php if ($restricciones): ?>
                <table>
                    <thead><tr><th>Nombre</th><th>Tipo</th><th>Definición</th></tr></thead>
                    <tbody>
                    <?php foreach ($restricciones as $restriccion): ?>
                        <tr>
                            <td><?= h($restriccion['constraint_name']) ?></td>
                            <td><?= h($restriccion['constraint_type']) ?></td>
                            <td><code><?= h($restriccion['definition']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">La tabla no tiene restricciones visibles.</p>
            <?php endif; ?>
        </section>

        <h2 class="section-title">Índices</h2>
        <section class="panel table-wrap">
            <?php if ($indices): ?>
                <table>
                    <thead><tr><th>Nombre</th><th>Definición</th></tr></thead>
                    <tbody>
                    <?php foreach ($indices as $indice): ?>
                        <tr><td><?= h($indice['indexname']) ?></td><td><code><?= h($indice['indexdef']) ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No hay índices visibles para esta tabla.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<footer class="footer-main">
    <strong>Grupo 6 · Cloud TUX</strong><br>
    Explorador de metadata PostgreSQL · Solo lectura
</footer>
</body>
</html>
