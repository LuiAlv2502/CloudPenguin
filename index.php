<?php
session_start();

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel DBA - Gestor de Prácticas</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background-color: #f3f4f6;
            color: #1e293b;
        }

        /* Barra Superior Principal */
        .header-main {
            background-color: #0f172a;
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 24px;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 700;
        }

        .header-brand img {
            height: 38px;
            width: auto;
            object-fit: contain;
        }

        .header-user {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
        }

        .btn-logout {
            background-color: #dc2626;
            color: #ffffff;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            transition: background-color 0.2s;
        }

        .btn-logout:hover {
            background-color: #b91c1c;
        }

        /* Barra Secundaria Informativa */
        .header-sub {
            background-color: #e0f2fe;
            color: #0284c7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 24px;
            font-size: 13px;
            border-bottom: 1px solid #bae6fd;
        }

        .header-sub strong {
            color: #0369a1;
        }

        /* Contenido Principal */
        .container {
            max-width: 1200px;
            margin: 36px auto;
            padding: 0 24px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .page-description {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 28px;
        }

        /* Rejilla de Opciones */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

        .card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .card-title {
            color: #2563eb;
            font-size: 16px;
            font-weight: 700;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-text {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
            flex-grow: 1;
        }

        .btn-action {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            width: fit-content;
            transition: background-color 0.2s;
        }

        .btn-action:hover {
            background-color: #1d4ed8;
        }

        @media (max-width: 768px) {
            .header-main, .header-sub {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
        }
    </style>
</head>
<body>

    <!-- Encabezado Superior -->
    <header class="header-main">
        <div class="header-brand">
            <!-- Logo Cloud Tux (asegúrate de que el archivo esté en la misma carpeta o ajusta la ruta/extensión) -->
            <img src="cloud_tux.png" alt="Cloud Tux Logo">
            <span>Panel DBA - Gestor de Prácticas</span>
        </div>
        <div class="header-user">
            <span>Usuario: <strong><?= htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') ?></strong></span>
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
            Seleccione una opción de administración para ejecutar las prácticas de PostgreSQL / MariaDB:
        </p>

        <div class="cards-grid">
            <!-- Tarjeta 1 -->
            <div class="card">
                <div>
                    <h2 class="card-title">1. Conexión y Estado del Servidor</h2>
                    <p class="card-text">
                        Verificar el estado del motor de base de datos, versión activa y parámetros de conexión (PDO / MySQLi).
                    </p>
                </div>
                <a href="#" class="btn-action">Gestionar Conexión</a>
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

</body>
</html>