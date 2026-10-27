<?php
/**
 * diagnostico.php - Módulo 03: Diagnóstico de consultas
 * Muestra el plan de ejecución (EXPLAIN) de dos consultas de lectura
 * definidas en el servidor (Q01 y Q02), lo interpreta y lo compara
 * con un plan histórico guardado antes de la mejora.
 */
session_start();
require_once __DIR__ . '/../conexion.php';

// Control de acceso
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login.php');
    exit;
}

$usuario  = $_SESSION['usuario'] ?? 'estudiante';
$catalogo = require __DIR__ . '/consultas.php';
$mejora   = require __DIR__ . '/mejora.php';

if (empty($_SESSION['token_diag'])) {
    $_SESSION['token_diag'] = bin2hex(random_bytes(16));
}

// ---------- Validación de entrada (solo valores del catálogo) ----------
function caso_valido(?string $id, array $catalogo): string
{
    return ($id !== null && isset($catalogo[$id])) ? $id : 'Q01';
}

function valor_valido($valor, array $param): ?int
{
    $v = filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $param['min'], 'max_range' => $param['max']],
    ]);
    return $v === false ? null : $v;
}

// ---------- EXPLAIN con el valor enlazado como parámetro ----------
// EXPLAIN no ejecuta la consulta: solo pide al motor su plan.
// Se usa preparación emulada porque PostgreSQL no acepta parámetros
// del lado servidor dentro de EXPLAIN; PDO enlaza el valor como entero.
function obtener_plan(PDO $pdo, string $sql, int $valor): array
{
    $opc = [PDO::ATTR_EMULATE_PREPARES => true];

    $st = $pdo->prepare('EXPLAIN ' . $sql, $opc);
    $st->bindValue(':valor', $valor, PDO::PARAM_INT);
    $st->execute();
    $texto = implode("\n", $st->fetchAll(PDO::FETCH_COLUMN));

    $st = $pdo->prepare('EXPLAIN (FORMAT JSON) ' . $sql, $opc);
    $st->bindValue(':valor', $valor, PDO::PARAM_INT);
    $st->execute();
    $json = (string)$st->fetchColumn();

    $datos = json_decode($json, true);
    return [
        'texto' => $texto,
        'json'  => $json,
        'raiz'  => $datos[0]['Plan'] ?? [],
    ];
}

function indice_existe(PDO $pdo, string $nombre): bool
{
    $st = $pdo->prepare('SELECT (to_regclass(:nombre) IS NOT NULL)::text');
    $st->execute([':nombre' => $nombre]);
    return $st->fetchColumn() === 'true';
}

/** Recorre el árbol del plan y devuelve una fila por nodo */
function nodos_plan(array $nodo, int $nivel = 0, array &$filas = []): array
{
    if (!$nodo) {
        return $filas;
    }
    $cond = [];
    foreach (['Index Cond', 'Recheck Cond', 'Hash Cond', 'Join Filter', 'Filter'] as $k) {
        if (!empty($nodo[$k])) {
            $cond[] = $k . ': ' . $nodo[$k];
        }
    }
    if (!empty($nodo['Sort Key'])) {
        $cond[] = 'Sort Key: ' . implode(', ', (array)$nodo['Sort Key']);
    }
    $filas[] = [
        'nivel'  => $nivel,
        'tipo'   => $nodo['Node Type'] ?? '',
        'join'   => $nodo['Join Type'] ?? '',
        'tabla'  => $nodo['Relation Name'] ?? '',
        'alias'  => $nodo['Alias'] ?? '',
        'indice' => $nodo['Index Name'] ?? '',
        'cond'   => implode(' | ', $cond),
        'inicio' => $nodo['Startup Cost'] ?? 0,
        'costo'  => $nodo['Total Cost'] ?? 0,
        'filas'  => $nodo['Plan Rows'] ?? 0,
    ];
    foreach ($nodo['Plans'] ?? [] as $hijo) {
        nodos_plan($hijo, $nivel + 1, $filas);
    }
    return $filas;
}

/** Forma de acceso a cada tabla: secuencial o por índice (y cuál) */
function acceso_tablas(array $raiz): array
{
    $tablas = [];
    $recorrer = function (array $n) use (&$recorrer, &$tablas) {
        $tipo = $n['Node Type'] ?? '';
        if (!empty($n['Relation Name'])) {
            $indice = $n['Index Name'] ?? '';
            if ($tipo === 'Bitmap Heap Scan') {
                // El índice lo usa el nodo hijo "Bitmap Index Scan"
                foreach ($n['Plans'] ?? [] as $h) {
                    if (!empty($h['Index Name'])) {
                        $indice = $h['Index Name'];
                    }
                }
            }
            $tablas[] = [
                'tabla'  => $n['Relation Name'],
                'alias'  => $n['Alias'] ?? '',
                'tipo'   => $tipo,
                'acceso' => $tipo === 'Seq Scan' ? 'Secuencial (recorre toda la tabla)' : 'Por índice',
                'indice' => $indice !== '' ? $indice : 'Ninguno',
                'filtro' => $n['Filter'] ?? ($n['Index Cond'] ?? ($n['Recheck Cond'] ?? '')),
                'filas'  => $n['Plan Rows'] ?? 0,
                'costo'  => $n['Total Cost'] ?? 0,
            ];
        }
        foreach ($n['Plans'] ?? [] as $h) {
            $recorrer($h);
        }
    };
    if ($raiz) {
        $recorrer($raiz);
    }
    return $tablas;
}

function num($v, int $dec = 2): string
{
    return number_format((float)$v, $dec, ',', '.');
}

// ---------- Entrada actual ----------
$casoId = caso_valido($_REQUEST['caso'] ?? null, $catalogo);
$caso   = $catalogo[$casoId];
$avisoValor = null;

$valorEntrada = $_REQUEST['valor'] ?? $caso['param']['defecto'];
$valor = valor_valido($valorEntrada, $caso['param']);
if ($valor === null) {
    $avisoValor = 'El valor indicado no es válido; se usó el valor por defecto (' . $caso['param']['defecto'] . ').';
    $valor = $caso['param']['defecto'];
}

$error = null;
$ctx = [];
$plan = ['texto' => '', 'json' => '', 'raiz' => []];
$conteos = [];
$indices = [];
$tablaHist = false;
$referencia = null;
$historial = [];
$tablaEstadoIndices = false;

try {
    $pdo = conectar();

    // ---------- Acciones de la mejora ----------
    $accion = $_POST['accion'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['ensayar', 'revertir'], true)) {
        $tokenOk = isset($_POST['token']) && hash_equals($_SESSION['token_diag'], (string)$_POST['token']);
        $existePlanes = $pdo->query("SELECT (to_regclass('dba_planes') IS NOT NULL)::text")->fetchColumn() === 'true';
        $existeEstado = $pdo->query("SELECT (to_regclass('dba_indices_prueba') IS NOT NULL)::text")->fetchColumn() === 'true';

        if (!$tokenOk) {
            $_SESSION['flash_diag'] = ['error', 'Solicitud no válida. Recargue la página e intente de nuevo.'];
        } elseif (!$existeEstado) {
            $_SESSION['flash_diag'] = ['error', 'Falta la tabla de control de índices. Ejecute o vuelva a ejecutar Module_3/scripts/planes_historicos.sql.'];
        } elseif ($accion === 'ensayar' && !$existePlanes) {
            $_SESSION['flash_diag'] = ['error', 'No existe la tabla dba_planes. Ejecute primero Module_3/scripts/planes_historicos.sql.'];
        } else {
            try {
                $pdo->beginTransaction();

                if ($accion === 'ensayar') {
                    $st = $pdo->prepare("
                        SELECT creado_por_modulo::text
                        FROM dba_indices_prueba
                        WHERE nombre_indice = :nombre
                    ");
                    $indicesPrevios = [];
                    foreach ($mejora['indices'] as $nombre => $indice) {
                        $st->execute([':nombre' => $nombre]);
                        $gestionado = $st->fetchColumn() === 'true';
                        if ($gestionado) {
                            $pdo->exec('DROP INDEX IF EXISTS "' . $nombre . '"');
                        } elseif (indice_existe($pdo, $nombre)) {
                            $indicesPrevios[] = $nombre;
                        }
                    }

                    foreach ($mejora['tablas'] as $tabla) {
                        $pdo->exec('ANALYZE ' . $tabla);
                    }

                    $planAntes = obtener_plan($pdo, $caso['sql'], $valor);
                    $ins = $pdo->prepare("
                        INSERT INTO dba_planes (caso, parametro, etapa, costo_total, filas_est, plan_texto, plan_json)
                        VALUES (:caso, :parametro, 'ANTES', :costo, :filas, :texto, CAST(:json AS jsonb))
                    ");
                    $ins->execute([
                        ':caso'      => $casoId,
                        ':parametro' => $caso['param']['etiqueta'] . ' = ' . $valor,
                        ':costo'     => $planAntes['raiz']['Total Cost'] ?? null,
                        ':filas'     => $planAntes['raiz']['Plan Rows'] ?? null,
                        ':texto'     => $planAntes['texto'],
                        ':json'      => $planAntes['json'],
                    ]);

                    $registrarIndice = $pdo->prepare("
                        INSERT INTO dba_indices_prueba (nombre_indice, creado_por_modulo)
                        VALUES (:nombre, :creado)
                        ON CONFLICT (nombre_indice) DO UPDATE
                        SET creado_por_modulo = EXCLUDED.creado_por_modulo,
                            actualizado = now()
                    ");
                    foreach ($mejora['indices'] as $nombre => $indice) {
                        $existe = indice_existe($pdo, $nombre);
                        if (!$existe) {
                            $pdo->exec($indice['sql']);
                            $registrarIndice->execute([':nombre' => $nombre, ':creado' => true]);
                        } else {
                            $registrarIndice->execute([':nombre' => $nombre, ':creado' => false]);
                        }
                    }

                    foreach ($mejora['tablas'] as $tabla) {
                        $pdo->exec('ANALYZE ' . $tabla);
                    }
                    $mensaje = 'Ensayo listo para ' . $casoId . ' con ID de cliente ' . $valor
                             . ': se guardó el plan ANTES y se aplicaron los índices de la mejora.';
                    if ($indicesPrevios) {
                        $mensaje .= ' El plan ANTES ya incluía índices preexistentes/no gestionados: '
                                  . implode(', ', $indicesPrevios) . '.';
                    }
                } else {
                    $eliminados = [];
                    $conservados = [];
                    $st = $pdo->prepare("
                        SELECT creado_por_modulo::text
                        FROM dba_indices_prueba
                        WHERE nombre_indice = :nombre
                    ");
                    foreach ($mejora['indices'] as $nombre => $indice) {
                        $st->execute([':nombre' => $nombre]);
                        $gestionado = $st->fetchColumn() === 'true';

                        if ($gestionado) {
                            if (indice_existe($pdo, $nombre)) {
                                $pdo->exec('DROP INDEX "' . $nombre . '"');
                                $eliminados[] = $nombre;
                            }
                            $del = $pdo->prepare("
                                DELETE FROM dba_indices_prueba
                                WHERE nombre_indice = :nombre
                            ");
                            $del->execute([':nombre' => $nombre]);
                        } elseif (indice_existe($pdo, $nombre)) {
                            $conservados[] = $nombre;
                        }
                    }
                    foreach ($mejora['tablas'] as $tabla) {
                        $pdo->exec('ANALYZE ' . $tabla);
                    }

                    $mensaje = $eliminados
                        ? 'Se revirtió la mejora. Índices quitados: ' . implode(', ', $eliminados) . '.'
                        : 'No había índices de la mejora gestionados por el módulo para quitar.';
                    if ($conservados) {
                        $mensaje .= ' Se conservaron índices preexistentes/no gestionados: '
                                  . implode(', ', $conservados) . '.';
                    }
                }

                $pdo->commit();
                $_SESSION['flash_diag'] = ['ok', $mensaje];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_diag'] = ['error', 'ERROR al procesar la acción; no se aplicó ningún cambio. ' . $e->getMessage()];
            }
        }
        header('Location: diagnostico.php?caso=' . urlencode($casoId) . '&valor=' . $valor);
        exit;
    }

    // ---------- Contexto ----------
    $ctx = $pdo->query("
        SELECT current_setting('server_version')        AS version_servidor,
               current_database()                       AS base_datos,
               current_user                             AS usuario_conectado,
               to_char(now(), 'DD/MM/YYYY HH24:MI:SS')  AS fecha_hora
    ")->fetch();

    // ---------- Plan actual (siempre en vivo) ----------
    $plan = obtener_plan($pdo, $caso['sql'], $valor);

    // ---------- Datos de prueba: filas reales de las tablas del caso ----------
    // Los nombres vienen del catálogo del servidor, no del usuario.
    foreach ($caso['tablas'] as $t) {
        $conteos[$t] = (int)$pdo->query('SELECT count(*) FROM ' . $t)->fetchColumn();
    }

    // ---------- Índices existentes en esas tablas ----------
    $marcas = implode(',', array_fill(0, count($caso['tablas']), '?'));
    $st = $pdo->prepare("
        SELECT tablename AS tabla, indexname AS indice, indexdef AS definicion
        FROM pg_indexes
        WHERE schemaname = current_schema() AND tablename IN ($marcas)
        ORDER BY tablename, indexname
    ");
    $st->execute($caso['tablas']);
    $indices = $st->fetchAll();

    // ---------- Plan histórico (referencia ANTES) ----------
    $tablaHist = $pdo->query("SELECT (to_regclass('dba_planes') IS NOT NULL)::text")->fetchColumn() === 'true';
    $tablaEstadoIndices = $pdo->query("SELECT (to_regclass('dba_indices_prueba') IS NOT NULL)::text")->fetchColumn() === 'true';
    if ($tablaHist) {
        $parametroActual = $caso['param']['etiqueta'] . ' = ' . $valor;
        $st = $pdo->prepare("
            SELECT id, caso, to_char(fecha, 'DD/MM/YYYY HH24:MI:SS') AS fecha, cuenta, base_datos,
                   parametro, etapa, costo_total, filas_est, plan_texto, plan_json::text AS plan_json
            FROM dba_planes
            WHERE caso = :caso AND etapa = 'ANTES' AND parametro = :parametro
            ORDER BY fecha DESC, id DESC
            LIMIT 1
        ");
        $st->execute([':caso' => $casoId, ':parametro' => $parametroActual]);
        $referencia = $st->fetch() ?: null;

        $st = $pdo->prepare("
            SELECT id, caso, to_char(fecha, 'DD/MM/YYYY HH24:MI:SS') AS fecha, cuenta, base_datos,
                   parametro, etapa, costo_total, filas_est, plan_texto, plan_json::text AS plan_json
            FROM dba_planes
            WHERE caso = :caso AND etapa = 'ANTES'
            ORDER BY fecha DESC, id DESC
            LIMIT 5
        ");
        $st->execute([':caso' => $casoId]);
        $historial  = $st->fetchAll();
    }
} catch (PDOException $e) {
    $error = $e->getMessage();
}

$flash = $_SESSION['flash_diag'] ?? null;
unset($_SESSION['flash_diag']);

// ---------- Interpretación ----------
$nodos   = nodos_plan($plan['raiz']);
$accesos = acceso_tablas($plan['raiz']);
$costoActual = (float)($plan['raiz']['Total Cost'] ?? 0);
$filasActual = (int)($plan['raiz']['Plan Rows'] ?? 0);

$accesosAntes = [];
$costoAntes = null;
if ($referencia) {
    $raizAntes = json_decode($referencia['plan_json'], true)[0]['Plan'] ?? [];
    $accesosAntes = acceso_tablas($raizAntes);
    $costoAntes = (float)$referencia['costo_total'];
}

// Comparación automática: qué cambió en cada tabla
$cambios = [];
foreach ($accesos as $a) {
    foreach ($accesosAntes as $b) {
        if ($a['tabla'] === $b['tabla'] && ($a['tipo'] !== $b['tipo'] || $a['indice'] !== $b['indice'])) {
            $cambios[] = $a['tabla'] . ': antes ' . $b['tipo'] . ($b['indice'] !== 'Ninguno' ? ' (' . $b['indice'] . ')' : ' sin índice')
                       . ', ahora ' . $a['tipo'] . ($a['indice'] !== 'Ninguno' ? ' usando ' . $a['indice'] : ' sin índice') . '.';
        }
    }
}
$variacion = ($costoAntes !== null && $costoAntes > 0)
    ? round((($costoActual - $costoAntes) / $costoAntes) * 100, 1)
    : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proyecto 03 · Diagnóstico de consultas - Panel DBA</title>
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
    <style>
        .alert-ok {
            background: #e4f2e8; color: #2d6a3e; border: 1px solid #bfdcc7;
            padding: 14px 18px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; line-height: 1.6;
        }
        .alert-aviso {
            background: var(--warn-bg); color: #7a4c17; border: 1px solid #ecd3b4;
            padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; line-height: 1.6;
        }
        .selector { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .selector .tabs { display: flex; gap: 8px; }
        .tab {
            display: inline-block; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
            text-decoration: none; border: 1px solid var(--soft-border); color: var(--soft-text); background: #fff;
        }
        .tab.activo { background: var(--accent); color: #fff; border-color: var(--accent); }
        .campo label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 4px; }
        .campo input {
            height: 36px; width: 140px; padding: 0 10px; border: 1px solid var(--soft-border);
            border-radius: 6px; font-family: inherit; font-size: 14px;
        }
        button.btn-action { border: none; cursor: pointer; font-family: inherit; }
        pre.sql, pre.plan {
            background: #1f2d2b; color: #d7e6e3; padding: 14px 16px; border-radius: 8px;
            font-size: 12px; line-height: 1.6; overflow-x: auto; white-space: pre;
        }
        .kv { display: grid; grid-template-columns: 170px 1fr; gap: 8px 14px; font-size: 13px; margin-bottom: 14px; }
        .kv .k { color: var(--muted); }
        .nodo { white-space: nowrap; }
        .seq { color: var(--danger); font-weight: 700; }
        .idx { color: #2d6a3e; font-weight: 700; }
        .comparar { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .comparar > div { min-width: 0; }
        .comparar h3 { font-size: 13px; color: var(--dark); margin-bottom: 8px; }
        .resumen-costo { display: flex; gap: 14px; flex-wrap: wrap; margin: 14px 0; }
        .resumen-costo .info-item { flex: 1; min-width: 180px; }
        ul.lista { margin: 8px 0 0 20px; font-size: 13px; line-height: 1.7; }
        @media (max-width: 900px) { .comparar { grid-template-columns: 1fr; } .kv { grid-template-columns: 1fr; } }
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
        <h1 class="page-title">Proyecto 03 · Diagnóstico de consultas</h1>
        <p class="page-description">
            Plan de ejecución (<code>EXPLAIN</code>) de dos consultas de lectura definidas en el servidor,
            su interpretación y la comparación con el plan anterior a la mejora.
        </p>

        <?php if ($flash): ?>
            <div class="<?= $flash[0] === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($flash[1]) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error">
                <strong>No fue posible obtener el diagnóstico.</strong><br>
                <?= h($error) ?>
            </div>
            <a href="../index.php" class="btn-action btn-secondary">← Volver al menú</a>
        <?php else: ?>

        <!-- ================= CONTEXTO ================= -->
        <h2 class="section-title" style="margin-top:0">Contexto</h2>
        <div class="info-panel">
            <div class="info-item"><div class="label">Motor y versión del servidor</div><div class="value"><?= h(MOTOR_NOMBRE) ?> <?= h($ctx['version_servidor']) ?></div></div>
            <div class="info-item"><div class="label">Base de datos</div><div class="value"><?= h($ctx['base_datos']) ?></div></div>
            <div class="info-item"><div class="label">Usuario conectado</div><div class="value"><?= h($ctx['usuario_conectado']) ?></div></div>
            <div class="info-item"><div class="label">Fecha y hora</div><div class="value"><?= h($ctx['fecha_hora']) ?></div></div>
            <div class="info-item"><div class="label">Versión de la Consola DBA</div><div class="value">v<?= h(CONSOLA_VERSION) ?></div></div>
        </div>

        <!-- ================= SELECCIÓN DEL CASO ================= -->
        <h2 class="section-title">Caso a diagnosticar</h2>
        <div class="panel">
            <form method="GET" action="diagnostico.php" class="selector">
                <div class="tabs">
                    <?php foreach ($catalogo as $id => $c): ?>
                        <a class="tab <?= $id === $casoId ? 'activo' : '' ?>" href="diagnostico.php?caso=<?= h($id) ?>"><?= h($id) ?> · <?= h($c['titulo']) ?></a>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="caso" value="<?= h($casoId) ?>">
                <div class="campo">
                    <label for="valor"><?= h($caso['param']['etiqueta']) ?></label>
                    <input type="number" id="valor" name="valor" min="<?= (int)$caso['param']['min'] ?>" value="<?= (int)$valor ?>" required>
                </div>
                <button type="submit" class="btn-action">Obtener plan</button>
            </form>
            <p class="nota">Solo se puede escoger Q01 o Q02. El SQL está definido en el servidor y el valor se valida como entero y se enlaza como parámetro preparado.</p>
        </div>

        <?php if ($avisoValor): ?>
            <div class="alert-aviso" style="margin-top:16px"><?= h($avisoValor) ?></div>
        <?php endif; ?>

        <!-- ================= CASO ================= -->
        <h2 class="section-title">Caso <?= h($casoId) ?> · <?= h($caso['titulo']) ?></h2>
        <div class="panel">
            <div class="kv">
                <div class="k">Identificador</div><div><strong><?= h($casoId) ?></strong></div>
                <div class="k">Propósito</div><div><?= h($caso['proposito']) ?></div>
                <div class="k">Tipo de consulta</div><div><?= h($caso['tipo']) ?></div>
                <div class="k">Parámetro</div><div><code>:valor</code> = <?= (int)$valor ?> (<?= h($caso['param']['etiqueta']) ?>)</div>
                <div class="k">Datos de prueba</div>
                <div>
                    <?php foreach ($conteos as $t => $n): ?>
                        <code><?= h($t) ?></code>: <?= number_format($n, 0, ',', '.') ?> filas<?= $t !== array_key_last($conteos) ? ' · ' : '' ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <pre class="sql"><?= h($caso['sql']) ?></pre>
        </div>

        <!-- ================= PLAN ================= -->
        <h2 class="section-title">Plan de ejecución actual (EXPLAIN)</h2>
        <div class="panel">
            <pre class="plan"><?= h($plan['texto']) ?></pre>
            <p class="nota">Obtenido en vivo desde la aplicación con <code>EXPLAIN</code> en el momento de la consulta. EXPLAIN no ejecuta la consulta, solo muestra el plan que eligió el planificador.</p>
        </div>

        <!-- ================= INTERPRETACIÓN ================= -->
        <h2 class="section-title">Interpretación</h2>
        <div class="resumen-costo">
            <div class="info-item"><div class="label">Costo total estimado</div><div class="value"><?= num($costoActual) ?></div></div>
            <div class="info-item"><div class="label">Filas estimadas del resultado</div><div class="value"><?= number_format($filasActual, 0, ',', '.') ?></div></div>
            <div class="info-item"><div class="label">Tablas que participan</div><div class="value"><?= count($accesos) ?></div></div>
        </div>

        <div class="panel">
            <h3 style="font-size:14px;color:var(--dark);margin-bottom:10px">Forma de acceso por tabla</h3>
            <div class="table-wrap">
                <table class="datos">
                    <thead><tr><th>Tabla</th><th>Nodo</th><th>Forma de acceso</th><th>Índice utilizado</th><th>Condición</th><th class="num">Filas est.</th><th class="num">Costo</th></tr></thead>
                    <tbody>
                    <?php foreach ($accesos as $a): ?>
                        <tr>
                            <td><code><?= h($a['tabla']) ?></code><?= $a['alias'] !== $a['tabla'] ? ' (' . h($a['alias']) . ')' : '' ?></td>
                            <td><?= h($a['tipo']) ?></td>
                            <td class="<?= $a['tipo'] === 'Seq Scan' ? 'seq' : 'idx' ?>"><?= h($a['acceso']) ?></td>
                            <td><?= $a['indice'] === 'Ninguno' ? '<span class="seq">Ninguno (ausente)</span>' : '<code>' . h($a['indice']) . '</code>' ?></td>
                            <td><?= $a['filtro'] !== '' ? '<code>' . h($a['filtro']) . '</code>' : '' ?></td>
                            <td class="num"><?= number_format((int)$a['filas'], 0, ',', '.') ?></td>
                            <td class="num"><?= num($a['costo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="font-size:14px;color:var(--dark);margin:22px 0 10px">Nodos del plan</h3>
            <div class="table-wrap">
                <table class="datos">
                    <thead><tr><th>Operación</th><th>Tabla / índice</th><th>Condición</th><th class="num">Costo inicio</th><th class="num">Costo total</th><th class="num">Filas est.</th></tr></thead>
                    <tbody>
                    <?php foreach ($nodos as $n): ?>
                        <tr>
                            <td class="nodo" style="padding-left:<?= 12 + $n['nivel'] * 18 ?>px"><?= $n['nivel'] > 0 ? '↳ ' : '' ?><?= h($n['tipo']) ?><?= $n['join'] ? ' (' . h($n['join']) . ')' : '' ?></td>
                            <td><?= h(trim($n['tabla'] . ($n['indice'] ? ' · ' . $n['indice'] : ''), ' ·')) ?></td>
                            <td><?= $n['cond'] !== '' ? '<code>' . h($n['cond']) . '</code>' : '' ?></td>
                            <td class="num"><?= num($n['inicio']) ?></td>
                            <td class="num"><?= num($n['costo']) ?></td>
                            <td class="num"><?= number_format((int)$n['filas'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="font-size:14px;color:var(--dark);margin:22px 0 10px">Índices existentes en las tablas del caso</h3>
            <div class="table-wrap">
                <table class="datos">
                    <thead><tr><th>Tabla</th><th>Índice</th><th>Definición</th></tr></thead>
                    <tbody>
                    <?php foreach ($indices as $i): ?>
                        <tr><td><code><?= h($i['tabla']) ?></code></td><td><code><?= h($i['indice']) ?></code></td><td><code><?= h($i['definicion']) ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="nota">
                <strong>Seq Scan</strong>: lee la tabla completa y descarta filas con <em>Filter</em>.
                <strong>Index Scan / Bitmap Index Scan</strong>: usa un índice para ubicar solo las filas que cumplen la condición.
                El <strong>costo</strong> es una estimación del planificador de PostgreSQL en unidades internas (no son milisegundos),
                y las <strong>filas</strong> son las que el motor espera obtener según sus estadísticas.
            </p>
        </div>

        <!-- ================= COMPARACIÓN ================= -->
        <h2 class="section-title">Comparación: plan anterior vs. plan actual</h2>
        <div class="panel">
            <p style="font-size:13px;margin-bottom:12px"><strong>Mejora ensayada:</strong> <?= h($caso['mejora']) ?></p>

            <?php if (!$tablaHist): ?>
                <div class="alert-aviso">No existe la tabla <code>dba_planes</code>. Ejecute <code>Module_3/scripts/planes_historicos.sql</code> para poder guardar el plan anterior.</div>
            <?php elseif (!$referencia): ?>
                <div class="alert-aviso">No hay una comparación guardada para <?= h($casoId) ?> con el ID <?= (int)$valor ?>. Presione «Probar mejora con este ID» para guardar el plan anterior y comparar exactamente el mismo valor.</div>
            <?php else: ?>
                <div class="resumen-costo">
                    <div class="info-item"><div class="label">Costo antes</div><div class="value"><?= num($costoAntes) ?></div></div>
                    <div class="info-item"><div class="label">Costo actual</div><div class="value"><?= num($costoActual) ?></div></div>
                    <div class="info-item"><div class="label">Variación del costo estimado</div><div class="value"><?= $variacion === null ? '—' : ($variacion > 0 ? '+' : '') . num($variacion, 1) . ' %' ?></div></div>
                </div>

                <?php if ($cambios): ?>
                    <p style="font-size:13px"><strong>Cambios en la forma de acceso:</strong></p>
                    <ul class="lista"><?php foreach ($cambios as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p class="nota">La forma de acceso a las tablas no cambió respecto al plan anterior guardado.</p>
                <?php endif; ?>

                <div class="comparar" style="margin-top:16px">
                    <div>
                        <h3>Antes · guardado el <?= h($referencia['fecha']) ?> · cuenta <?= h($referencia['cuenta']) ?> · <?= h($referencia['parametro']) ?></h3>
                        <pre class="plan"><?= h($referencia['plan_texto']) ?></pre>
                    </div>
                    <div>
                        <h3>Actual · <?= h($ctx['fecha_hora']) ?> · cuenta <?= h($ctx['usuario_conectado']) ?> · <?= h($caso['param']['etiqueta']) ?> = <?= (int)$valor ?></h3>
                        <pre class="plan"><?= h($plan['texto']) ?></pre>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$tablaEstadoIndices): ?>
                <div class="alert-aviso" style="margin-top:16px">Ejecute o vuelva a ejecutar <code>Module_3/scripts/planes_historicos.sql</code> para habilitar los controles de probar y revertir.</div>
            <?php elseif ($tablaHist): ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                    <form method="POST" action="diagnostico.php">
                        <input type="hidden" name="token" value="<?= h($_SESSION['token_diag']) ?>">
                        <input type="hidden" name="accion" value="ensayar">
                        <input type="hidden" name="caso" value="<?= h($casoId) ?>">
                        <input type="hidden" name="valor" value="<?= (int)$valor ?>">
                        <button type="submit" class="btn-action">Probar mejora con este ID</button>
                    </form>
                    <form method="POST" action="diagnostico.php" onsubmit="return confirm('Se quitarán solo los índices registrados como creados por el Módulo 03. No se modificarán los datos. ¿Continuar?');">
                        <input type="hidden" name="token" value="<?= h($_SESSION['token_diag']) ?>">
                        <input type="hidden" name="accion" value="revertir">
                        <input type="hidden" name="caso" value="<?= h($casoId) ?>">
                        <input type="hidden" name="valor" value="<?= (int)$valor ?>">
                        <button type="submit" class="btn-action btn-secondary">Revertir mejora</button>
                    </form>
                </div>
                <p class="nota">
                    «Probar mejora» guarda el plan ANTES de <code><?= h($casoId) ?></code> con el ID <?= (int)$valor ?> y luego aplica los índices. «Revertir mejora» quita únicamente los índices registrados como gestionados por este módulo. Los índices preexistentes/no gestionados se conservan; los datos nunca se borran ni se cambian.
                </p>
            <?php else: ?>
                <div class="alert-aviso" style="margin-top:16px">Primero cree la tabla <code>dba_planes</code> ejecutando <code>Module_3/scripts/planes_historicos.sql</code>.</div>
            <?php endif; ?>

            <?php if (count($historial) > 1): ?>
                <h3 style="font-size:14px;color:var(--dark);margin:22px 0 10px">Referencias guardadas de <?= h($casoId) ?></h3>
                <div class="table-wrap">
                    <table class="datos">
                        <thead><tr><th>#</th><th>Fecha</th><th>Cuenta</th><th>Base</th><th>Parámetro</th><th class="num">Costo</th></tr></thead>
                        <tbody>
                        <?php foreach ($historial as $r): ?>
                            <tr><td><?= (int)$r['id'] ?></td><td><?= h($r['fecha']) ?></td><td><?= h($r['cuenta']) ?></td><td><?= h($r['base_datos']) ?></td><td><?= h($r['parametro']) ?></td><td class="num"><?= num($r['costo_total']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="nota">La tabla muestra los últimos ensayos de <?= h($casoId) ?>. La comparación superior usa solo uno hecho con el mismo ID seleccionado.</p>
            <?php endif; ?>
        </div>

        <div style="margin-top:28px">
            <a href="../index.php" class="btn-action btn-secondary">← Volver al menú</a>
            <a href="diagnostico.php?caso=<?= h($casoId) ?>&amp;valor=<?= (int)$valor ?>" class="btn-action">Actualizar plan</a>
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
            Motor: <?= h(MOTOR_NOMBRE) ?> · Base: <?= h($ctx['base_datos'] ?? env('DB_NAME')) ?> ·
            Usuario de conexión: <?= h($ctx['usuario_conectado'] ?? db_usuario()) ?> ·
            Consola DBA v<?= h(CONSOLA_VERSION) ?>
        </div>
    </footer>
</body>
</html>
