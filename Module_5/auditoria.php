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

/** Metadatos de una tabla del catálogo: columnas, llave primaria simple y llaves foráneas */
function metadatos(PDO $pdo, string $tabla): array
{
    $reg = "('public.' || quote_ident(?))::regclass";

    $st = $pdo->prepare("
        SELECT a.attname AS col, format_type(a.atttypid, a.atttypmod) AS tipo,
               a.attnotnull::int AS obligatorio,
               COALESCE(pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval(%' OR a.attidentity <> '', false)::int AS auto
        FROM pg_attribute a
        LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
        WHERE a.attrelid = $reg AND a.attnum > 0 AND NOT a.attisdropped
        ORDER BY a.attnum
    ");
    $st->execute([$tabla]);
    $cols = $st->fetchAll();
    foreach ($cols as &$c) {
        $c['obligatorio'] = (bool)$c['obligatorio'];
        $c['auto']        = (bool)$c['auto'];
    }
    unset($c);

    $st = $pdo->prepare("
        SELECT a.attname
        FROM pg_index i
        JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
        WHERE i.indrelid = $reg AND i.indisprimary
    ");
    $st->execute([$tabla]);
    $pks = $st->fetchAll(PDO::FETCH_COLUMN);

    $st = $pdo->prepare("
        SELECT a.attname AS col, cf.relname AS ref_tabla, af.attname AS ref_col
        FROM pg_constraint c
        JOIN pg_attribute a  ON a.attrelid  = c.conrelid  AND a.attnum  = c.conkey[1]
        JOIN pg_class cf     ON cf.oid = c.confrelid
        JOIN pg_attribute af ON af.attrelid = c.confrelid AND af.attnum = c.confkey[1]
        WHERE c.contype = 'f' AND c.conrelid = $reg
          AND array_length(c.conkey, 1) = 1
          AND cf.relnamespace = 'public'::regnamespace
    ");
    $st->execute([$tabla]);
    $fks = [];
    foreach ($st->fetchAll() as $f) {
        // Valor de ejemplo: un id que sí exista en la tabla referenciada
        $f['ejemplo'] = $pdo->query(
            'SELECT min(' . q_ident($f['ref_col']) . ') FROM ' . q_ident($f['ref_tabla'])
        )->fetchColumn();
        $fks[$f['col']] = $f;
    }

    return ['cols' => $cols, 'pk' => count($pks) === 1 ? $pks[0] : null, 'fk' => $fks];
}

/** Valor sugerido para rellenar el formulario según el tipo de la columna */
function valor_ejemplo(array $c, ?array $fk, bool $editar): string
{
    if ($fk !== null) {
        return (string)($fk['ejemplo'] ?? '');
    }
    $tipo  = $c['tipo'];
    $largo = preg_match('/\((\d+)(?:,\d+)?\)/', $tipo, $m) ? (int)$m[1] : null;

    if (preg_match('/^(integer|bigint|smallint)/', $tipo)) {
        return $editar ? (string)mt_rand(10, 99) : '1';
    }
    if (strpos($tipo, 'numeric') === 0) {
        return $editar ? mt_rand(10, 99) . '.50' : '1';
    }
    if ($tipo === 'date') {
        return date('Y-m-d', $editar ? strtotime('+' . mt_rand(1, 30) . ' days') : time());
    }
    if (strpos($tipo, 'timestamp') === 0) {
        return date('Y-m-d H:i:s', $editar ? strtotime('+' . mt_rand(1, 30) . ' days') : time());
    }
    if ($tipo === 'boolean') {
        return $editar ? 'false' : 'true';
    }
    if (preg_match('/^(character|text)/', $tipo)) {
        $v = $editar ? 'Editado ' . date('His') : 'Prueba auditoria';
        if (!$editar && stripos($c['col'], 'email') !== false) {
            $v = 'prueba@correo.com';
        }
        return $largo !== null ? substr($v, 0, $largo) : $v;
    }
    return '';
}

/** Tipo de <input> HTML según el tipo de la columna */
function tipo_input(string $tipo): string
{
    if (preg_match('/^(integer|bigint|smallint|numeric)/', $tipo)) {
        return 'number';
    }
    return $tipo === 'date' ? 'date' : 'text';
}

/** Últimas filas de la tabla (llave + 4 columnas, texto recortado) para elegir cuál modificar */
function filas_recientes(PDO $pdo, string $tabla, array $meta, int $n = 6): array
{
    if ($meta['pk'] === null) {
        return [];
    }
    $nombres = [$meta['pk']];
    foreach ($meta['cols'] as $c) {
        if ($c['col'] !== $meta['pk'] && count($nombres) < 5) {
            $nombres[] = $c['col'];
        }
    }
    $sel = array_map(fn($c) => 'left(' . q_ident($c) . '::text, 25) AS ' . q_ident($c), $nombres);
    $sql = 'SELECT ' . implode(', ', $sel) . ' FROM ' . q_ident($tabla)
         . ' ORDER BY ' . q_ident($meta['pk']) . ' DESC LIMIT ' . (int)$n;
    return $pdo->query($sql)->fetchAll();
}

/** Traduce el código SQLSTATE a un mensaje comprensible (sin exponer el detalle del motor) */
function mensaje_error_pg(PDOException $e): string
{
    $mensajes = [
        '23502' => 'Falta un valor obligatorio (columna NOT NULL).',
        '23503' => 'Viola una llave foránea: el valor referenciado no existe, o la fila está '
                 . 'referenciada por otras tablas (por ejemplo, un cliente con ventas no se puede borrar). '
                 . 'Pruebe con una fila recién insertada.',
        '23505' => 'Viola una restricción de unicidad (valor duplicado).',
        '22P02' => 'Valor con formato no válido para el tipo de la columna.',
        '22001' => 'El valor es demasiado largo para la columna.',
        '22003' => 'Valor numérico fuera de rango.',
        '22007' => 'Fecha u hora no válida.',
        '22008' => 'Fecha u hora fuera de rango.',
    ];
    $codigo = (string)$e->getCode();
    return $mensajes[$codigo] ?? 'Error del motor (código ' . $codigo . ').';
}

/**
 * Ejecuta un INSERT, UPDATE o DELETE de prueba sobre una tabla del catálogo.
 * Devuelve [tipo, mensaje] para el aviso de la página.
 * Solo se usan identificadores del catálogo (entre comillas) y parámetros enlazados.
 */
function probar_cambio(PDO $pdo, string $tabla, string $op, array $in, bool $activa): array
{
    $meta    = metadatos($pdo, $tabla);
    $tablaId = q_ident($tabla);
    $pk      = $meta['pk'];
    $nombres = array_column($meta['cols'], 'col');

    if ($op !== 'insert' && $pk === null) {
        return ['error', "La tabla $tabla no tiene llave primaria simple: no se puede ubicar una fila para $op."];
    }

    try {
        $pdo->beginTransaction();

        if ($op === 'insert') {
            $datos  = is_array($in['c'] ?? null) ? $in['c'] : [];
            $campos = [];
            $vals   = [];
            foreach ($meta['cols'] as $c) {
                if ($c['auto']) {
                    continue;                       // SERIAL: lo genera el motor
                }
                $v = trim((string)($datos[$c['col']] ?? ''));
                if ($v === '') {
                    continue;                       // vacío → valor predeterminado / NULL
                }
                $campos[] = q_ident($c['col']);
                $vals[]   = $v;
            }
            $sql = $campos
                ? "INSERT INTO $tablaId (" . implode(', ', $campos) . ') VALUES ('
                  . implode(', ', array_fill(0, count($vals), '?')) . ')'
                : "INSERT INTO $tablaId DEFAULT VALUES";
            if ($pk !== null) {
                $sql .= ' RETURNING ' . q_ident($pk);
            }
            $st = $pdo->prepare($sql);
            $st->execute($vals);
            $id  = $pk !== null ? $st->fetchColumn() : null;
            $msg = "INSERT ejecutado en $tabla" . ($id !== null ? " ($pk = $id)" : '') . '.';

        } elseif ($op === 'update') {
            $col = (string)($in['columna'] ?? '');
            $id  = trim((string)($in['id'] ?? ''));
            if (!in_array($col, $nombres, true) || $col === $pk) {
                $pdo->rollBack();
                return ['error', 'Columna no permitida. No se ejecutó ninguna operación.'];
            }
            if ($id === '') {
                $pdo->rollBack();
                return ['error', "Indique el valor de $pk de la fila a modificar."];
            }
            $v  = trim((string)($in['valor'] ?? ''));
            $st = $pdo->prepare("UPDATE $tablaId SET " . q_ident($col) . ' = ? WHERE ' . q_ident($pk) . ' = ?');
            $st->execute([$v === '' ? null : $v, $id]);
            if ($st->rowCount() === 0) {
                $pdo->rollBack();
                return ['error', "No existe ninguna fila con $pk = $id en $tabla."];
            }
            $msg = "UPDATE ejecutado en $tabla: se modificó $col de la fila $pk = $id.";

        } else { // delete
            $id = trim((string)($in['id'] ?? ''));
            if ($id === '') {
                $pdo->rollBack();
                return ['error', "Indique el valor de $pk de la fila a eliminar."];
            }
            $st = $pdo->prepare("DELETE FROM $tablaId WHERE " . q_ident($pk) . ' = ?');
            $st->execute([$id]);
            if ($st->rowCount() === 0) {
                $pdo->rollBack();
                return ['error', "No existe ninguna fila con $pk = $id en $tabla."];
            }
            $msg = "DELETE ejecutado en $tabla: se eliminó la fila $pk = $id.";
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['error', 'No se pudo ejecutar ' . strtoupper($op) . ' en ' . $tabla . '. ' . mensaje_error_pg($e)];
    }

    return $activa
        ? ['ok', $msg . ' Revise el historial: el cambio debe aparecer registrado.']
        : ['aviso', $msg . " La auditoría no está activa en $tabla, por eso este cambio NO quedó registrado."];
}

$error = null;
$ctx = [];
$tablas = [];
$conAuditoria = [];
$historial = [];
$instalado = false;
$filtro = (string)($_GET['tabla'] ?? '');
$ptabla = (string)($_GET['ptabla'] ?? '');   // tabla elegida en «Probar la auditoría»
$pop    = (string)($_GET['pop'] ?? '');       // insert / update / delete
$meta   = null;
$recientes = [];

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

        $destino = 'auditoria.php';
        $esPrueba = in_array($accion, ['insert', 'update', 'delete'], true);

        if (!isset($_POST['token']) || !hash_equals($_SESSION['token_aud'], (string)$_POST['token'])) {
            $_SESSION['flash_aud'] = ['error', 'Solicitud no válida. Recargue la página e intente de nuevo.'];
        } elseif (!$instalado) {
            $_SESSION['flash_aud'] = ['error', 'El módulo no está instalado: ejecute Module_5/scripts/auditoria.sql.'];
        } elseif (!in_array($tabla, $tablas, true)
                  || !in_array($accion, ['activar', 'desactivar', 'insert', 'update', 'delete'], true)) {
            $_SESSION['flash_aud'] = ['error', 'Tabla no permitida. No se ejecutó ninguna operación.'];
        } elseif ($esPrueba) {
            // Cambio de prueba (INSERT / UPDATE / DELETE) para comprobar que la auditoría registra
            $_SESSION['flash_aud'] = probar_cambio($pdo, $tabla, $accion, $_POST, in_array($tabla, activas($pdo), true));
            $destino = 'auditoria.php?tabla=' . rawurlencode($tabla) . '#historial';
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
        header('Location: ' . $destino);
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

    if ($instalado && in_array($ptabla, $tablas, true) && in_array($pop, ['insert', 'update', 'delete'], true)) {
        $meta = metadatos($pdo, $ptabla);
        if ($pop !== 'insert') {
            $recientes = filas_recientes($pdo, $ptabla, $meta);
        }
    } else {
        $ptabla = '';
        $pop    = '';
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
        input[type=text], input[type=number], input[type=date] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--border);
            border-radius: 6px; background: #fff; color: var(--text); font-family: inherit;
        }
        input:disabled { background: var(--bg); color: var(--muted); }
        .fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .tipo { font-weight: 400; color: var(--muted); font-size: 12px; }
        .hint { color: var(--muted); font-size: 13px; margin: 10px 0 14px; }
        .test-form { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border); }
        .test-form .row { display: flex; gap: 14px; flex-wrap: wrap; align-items: end; margin-bottom: 14px; }
        .test-form .row > div { min-width: 200px; flex: 1; }
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
        (quién, cuándo, valor anterior y nuevo). La auditoría en sí no modifica los datos; el panel
        «Probar la auditoría» sí ejecuta cambios reales para comprobar que quedan registrados.
    </p>
    <div class="actions">
        <a href="../index.php" class="btn-action btn-secondary">← Volver al menú</a>
        <a href="auditoria.php" class="btn-action btn-secondary">Actualizar</a>
    </div>

    <?php if ($flash): ?>
        <div class="<?= ['ok' => 'alert-ok', 'aviso' => 'alert-aviso'][$flash[0]] ?? 'alert-error' ?>"><?= h($flash[1]) ?></div>
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

        <!-- ===== Probar la auditoría: INSERT / UPDATE / DELETE de prueba ===== -->
        <h2 class="section-title" id="probar">Probar la auditoría</h2>
        <div class="panel">
            <p class="context">
                Ejecute un <strong>INSERT</strong>, <strong>UPDATE</strong> o <strong>DELETE</strong> real sobre una tabla
                y compruebe en el historial que quedó registrado. Estos cambios <strong>sí modifican los datos</strong>.
            </p>
            <form method="GET" action="auditoria.php" class="selector">
                <?php if ($filtro !== ''): ?><input type="hidden" name="tabla" value="<?= h($filtro) ?>"><?php endif; ?>
                <div>
                    <label for="ptabla">Tabla</label>
                    <select id="ptabla" name="ptabla" required>
                        <option value="">Seleccione…</option>
                        <?php foreach ($tablas as $t): ?>
                            <option value="<?= h($t) ?>" <?= $t === $ptabla ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="pop">Operación</label>
                    <select id="pop" name="pop" required>
                        <?php foreach (['insert' => 'INSERT (agregar fila)', 'update' => 'UPDATE (modificar fila)', 'delete' => 'DELETE (eliminar fila)'] as $k => $etq): ?>
                            <option value="<?= h($k) ?>" <?= $k === $pop ? 'selected' : '' ?>><?= h($etq) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-action">Continuar</button>
            </form>

            <?php if ($meta !== null): $activaP = in_array($ptabla, $conAuditoria, true); ?>
                <div class="test-form">
                    <?php if ($activaP): ?>
                        <div class="alert-ok">La auditoría está <strong>activa</strong> en <?= h($ptabla) ?>: el cambio quedará registrado.</div>
                    <?php else: ?>
                        <div class="alert-aviso">
                            La auditoría está <strong>inactiva</strong> en <?= h($ptabla) ?>: el cambio se aplicará,
                            pero no quedará en el historial. Actívela en la tabla de arriba para probarla.
                        </div>
                    <?php endif; ?>

                    <?php if ($pop !== 'insert' && $meta['pk'] === null): ?>
                        <p class="empty">La tabla <?= h($ptabla) ?> no tiene una llave primaria simple; solo se puede probar INSERT.</p>

                    <?php else: ?>
                    <form method="POST" action="auditoria.php"
                          <?php if ($pop !== 'insert'): ?>onsubmit="return confirm(<?= h(json_encode('¿Ejecutar ' . strtoupper($pop) . ' sobre ' . $ptabla . '? El cambio se aplicará en la base de datos.')) ?>)"<?php endif; ?>>
                        <input type="hidden" name="token" value="<?= h($_SESSION['token_aud']) ?>">
                        <input type="hidden" name="tabla" value="<?= h($ptabla) ?>">
                        <input type="hidden" name="accion" value="<?= h($pop) ?>">

                        <?php if ($pop === 'insert'): ?>
                            <p class="hint">Los campos vienen con valores de ejemplo (las llaves foráneas usan un id existente). Los vacíos usan el valor predeterminado del motor.</p>
                            <div class="fields">
                                <?php foreach ($meta['cols'] as $c): $fk = $meta['fk'][$c['col']] ?? null; ?>
                                    <div>
                                        <label for="c_<?= h($c['col']) ?>">
                                            <?= h($c['col']) ?>
                                            <span class="tipo">
                                                <?= h($c['tipo']) ?><?= $c['obligatorio'] && !$c['auto'] ? ' · obligatorio' : '' ?><?= $fk ? ' · → ' . h($fk['ref_tabla'] . '.' . $fk['ref_col']) : '' ?>
                                            </span>
                                        </label>
                                        <?php if ($c['auto']): ?>
                                            <input type="text" id="c_<?= h($c['col']) ?>" value="(automático)" disabled>
                                        <?php else: ?>
                                            <input type="<?= h(tipo_input($c['tipo'])) ?>" step="any"
                                                   id="c_<?= h($c['col']) ?>" name="c[<?= h($c['col']) ?>]"
                                                   value="<?= h(valor_ejemplo($c, $fk, false)) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn-action">Ejecutar INSERT</button>

                        <?php else: ?>
                            <p class="hint">Últimas filas de <?= h($ptabla) ?> (ordenadas por <?= h($meta['pk']) ?>), para elegir cuál usar:</p>
                            <?php if ($recientes): ?>
                                <div class="table-wrap" style="margin-bottom:16px">
                                    <table>
                                        <tr><?php foreach (array_keys($recientes[0]) as $k): ?><th><?= h($k) ?></th><?php endforeach; ?></tr>
                                        <?php foreach ($recientes as $f): ?>
                                            <tr><?php foreach ($f as $v): ?><td><?= h($v ?? 'NULL') ?></td><?php endforeach; ?></tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="empty">La tabla está vacía.</p>
                            <?php endif; ?>

                            <div class="row">
                                <div>
                                    <label for="id_fila"><?= h($meta['pk']) ?> de la fila</label>
                                    <input type="text" id="id_fila" name="id" required
                                           value="<?= h($recientes[0][$meta['pk']] ?? '') ?>">
                                </div>
                                <?php if ($pop === 'update'): ?>
                                    <div>
                                        <label for="columna">Columna a modificar</label>
                                        <select id="columna" name="columna" style="width:100%">
                                            <?php foreach ($meta['cols'] as $c): if ($c['col'] === $meta['pk'] || $c['auto']) continue; ?>
                                                <option value="<?= h($c['col']) ?>"
                                                        data-ejemplo="<?= h(valor_ejemplo($c, $meta['fk'][$c['col']] ?? null, true)) ?>">
                                                    <?= h($c['col']) ?> (<?= h($c['tipo']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="valor">Nuevo valor</label>
                                        <input type="text" id="valor" name="valor">
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($pop === 'delete'): ?>
                                <p class="hint">
                                    Si la fila está referenciada por otra tabla (p. ej. un cliente con ventas) el motor rechazará el DELETE.
                                    Para una prueba limpia, inserte una fila nueva y elimine esa.
                                </p>
                            <?php endif; ?>
                            <button type="submit" class="btn-action <?= $pop === 'delete' ? 'btn-off' : '' ?>">
                                Ejecutar <?= h(strtoupper($pop)) ?>
                            </button>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== Historial con filtro por tabla ===== -->
        <h2 class="section-title" id="historial">Historial de cambios (últimos 50)</h2>
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
<script>
// Al elegir la columna del UPDATE, sugiere un valor nuevo acorde a su tipo
(function () {
    var sel = document.getElementById('columna'), val = document.getElementById('valor');
    if (!sel || !val) return;
    function rellenar() {
        var o = sel.options[sel.selectedIndex];
        val.value = o ? (o.getAttribute('data-ejemplo') || '') : '';
    }
    sel.addEventListener('change', rellenar);
    rellenar();
})();
</script>
</body>
</html>
