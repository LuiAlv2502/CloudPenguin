<?php
session_start();
require_once 'conexion.php';

// Cierre de sesión si se solicita por la URL (?action=logout)
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Control de acceso: redirige al login si no está autenticado
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: login.php');
    exit;
}

$usuario = $_SESSION['usuario'] ?? 'estudiante';

// Datos del sistema: se leen del servidor; si falla, se usan los configurados
$baseDatos       = DB_NAME;
$usuarioConexion = DB_USER;
$conectado       = false;

try {
    $pdo  = conectar();
    $fila = $pdo->query("SELECT current_database() AS bd, current_user AS usr")->fetch();
    $baseDatos       = $fila['bd'];
    $usuarioConexion = $fila['usr'];
    $conectado       = true;
} catch (PDOException $e) {
    $conectado = false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel DBA - Gestor de Prácticas</title>
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
            background-color: var(--dark);
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        .page-title { font-size: 24px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
        .page-description { font-size: 14px; color: var(--muted); margin-bottom: 28px; }

        /* Panel de información del sistema */
        .info-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
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

        .alert-info {
            background: var(--soft); color: var(--soft-text); border: 1px solid var(--soft-border);
            padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px;
        }

        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .card {
            background-color: #ffffff; border: 1px solid var(--border); border-left: 4px solid var(--accent);
            border-radius: 10px; padding: 22px; display: flex; flex-direction: column;
            justify-content: space-between; box-shadow: 0 1px 3px rgba(31, 58, 56, 0.06);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .card:hover { box-shadow: 0 6px 16px rgba(31, 58, 56, 0.10); transform: translateY(-2px); }
        .card-title {
            color: var(--dark); font-size: 16px; font-weight: 700;
            padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--border);
        }
        .card-text { color: var(--muted); font-size: 13px; line-height: 1.5; margin-bottom: 20px; flex-grow: 1; }
        .btn-action {
            display: inline-block; background-color: var(--accent); color: #ffffff; text-decoration: none;
            padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
            width: fit-content; transition: background-color 0.2s;
        }
        .btn-action:hover { background-color: var(--accent-hover); }

        code { background: rgba(0,0,0,.06); padding: 1px 6px; border-radius: 4px; font-size: 12px; }

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

    <!-- Encabezado Superior -->
    <header class="header-main">
        <div class="header-brand">
            <img src="cloud_tux.png" alt="Cloud Tux Logo">
            <span>Panel DBA - Gestor de Prácticas</span>
        </div>
        <div class="header-user">
            <span>Usuario: <strong><?= h($usuario) ?></strong></span>
            <a href="?action=logout" class="btn-logout">Cerrar Sesión</a>
        </div>
    </header>

    <!-- Sub-encabezado -->
    <div class="header-sub">
        <div><strong>CURSO:</strong> Administración de Bases de Datos</div>
        <div><strong>Profesor:</strong> Manuel Espinoza G.</div>
    </div>

    <!-- Menú Principal -->
    <main class="container">
        <h1 class="page-title">Menú Principal de Tareas de Administrador</h1>
        <p class="page-description">
            Seleccione una opción de administración para ejecutar las prácticas de PostgreSQL:
        </p>

        <!-- Información del sistema -->
        <div class="info-panel">
            <div class="info-item">
                <div class="label">Motor utilizado</div>
                <div class="value"><?= h(MOTOR_NOMBRE) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Nombre de la base</div>
                <div class="value"><?= h($baseDatos) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Usuario de conexión</div>
                <div class="value"><?= h($usuarioConexion) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Versión de la Consola DBA</div>
                <div class="value">v<?= h(CONSOLA_VERSION) ?></div>
            </div>
        </div>

        <?php if (!$conectado): ?>
            <div class="alert-info">
                No se pudo conectar al servidor en este momento; se muestran los valores configurados en <code>conexion.php</code>.
            </div>
        <?php endif; ?>

        <div class="cards-grid">
            <!-- Tarjeta 1 -->
            <div class="card">
                <div>
                    <h2 class="card-title">1. Conexión y Estado del Servidor</h2>
                    <p class="card-text">
                        Verificar el estado del motor de base de datos, tamaño de la base, listado de tablas y las que consumen mayor espacio.
                    </p>
                </div>
                <a href="estado_servidor.php" class="btn-action">Gestionar Conexión</a>
            </div>

            <!-- Tarjeta 2 -->
            <div class="card">
                <div>
                    <h2 class="card-title">2. Gestión de Esquemas y Tablas</h2>
                    <p class="card-text">
                        Crear, modificar o listar tablas, definir tipos de datos y restricciones de integridad referencial.
                    </p>
                </div>
                <a href="#" class="btn-action">Ver Esquemas</a>
            </div>

            <!-- Tarjeta 3 -->
            <div class="card">
                <div>
                    <h2 class="card-title">3. Usuarios y Privilegios (GRANT/REVOKE)</h2>
                    <p class="card-text">
                        Administrar roles, control de accesos de usuarios y asignación de permisos sobre las bases de datos.
                    </p>
                </div>
                <a href="#" class="btn-action">Control de Accesos</a>
            </div>

            <!-- Tarjeta 4 -->
            <div class="card">
                <div>
                    <h2 class="card-title">4. Respaldos y Restauración (Dump)</h2>
                    <p class="card-text">
                        Generar respaldos lógicos de bases de datos mediante comandos del sistema y exportación de estructuras.
                    </p>
                </div>
                <a href="#" class="btn-action">Respaldos</a>
            </div>

            <!-- Tarjeta 5 -->
            <div class="card">
                <div>
                    <h2 class="card-title">5. Monitoreo y Consultas (SQL)</h2>
                    <p class="card-text">
                        Ejecutar consultas de diagnóstico, revisar procesos activos y analizar planes de ejecución (EXPLAIN).
                    </p>
                </div>
                <a href="#" class="btn-action">Consola SQL</a>
            </div>

            <!-- Tarjeta 6 -->
            <div class="card">
                <div>
                    <h2 class="card-title">6. Bitácoras y Logs del Sistema</h2>
                    <p class="card-text">
                        Revisar los registros de errores y auditoría del motor de base de datos desde el entorno web.
                    </p>
                </div>
                <a href="#" class="btn-action">Ver Logs</a>
            </div>
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
