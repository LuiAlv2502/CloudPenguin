<?php
session_start();

require_once __DIR__ . '/Usuario_y_CLave.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario_ingresado = trim($_POST['usuario'] ?? '');
    $clave_ingresada = $_POST['clave'] ?? '';

    if (
        hash_equals($usuario, $usuario_ingresado) &&
        password_verify($clave_ingresada, $clave_hash)
    ) {
        session_regenerate_id(true);

        $_SESSION['autenticado'] = true;
        $_SESSION['usuario'] = $usuario;

        header('Location: index.php');
        exit;
    }

    $error = 'Usuario o contraseña incorrectos.';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Acceso al Sistema</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f5f7fb;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #24304f;
        }

        .logo {
            display: block;
            width: 90px;
            height: auto;
            margin: 0 auto 16px;
        }
        
        .login {
            width: 100%;
            max-width: 440px;
            padding: 42px 38px 36px;
            background: #fff;
            border: 1px solid #e8ebf2;
            border-top: 5px solid #30358b;
            border-radius: 14px;
            box-shadow: 0 12px 35px rgba(34, 45, 90, .08);
        }

        .encabezado {
            text-align: center;
            margin-bottom: 20px;
        }

        h1 {
            margin: 0 0 12px;
            color: #30358b;
            font-size: 25px;
            font-weight: 700;
            letter-spacing: -.5px;
        }

        .subtitulo {
            margin: 0;
            color: #75809a;
            font-size: 13px;
            line-height: 1.7;
        }

        .separador {
            height: 1px;
            margin: 18px 0;
            background: #e9edf4;
        }

        .campo {
            margin-bottom: 21px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            color: #263453;
            font-size: 13px;
            font-weight: 600;
        }

        input {
            width: 100%;
            height: 47px;
            padding: 0 14px;
            border: 1px solid #d8deea;
            border-radius: 8px;
            background: #fff;
            color: #263453;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        input::placeholder {
            color: #a3abc0;
        }

        input:focus {
            border-color: #30358b;
            box-shadow: 0 0 0 3px rgba(48, 53, 139, .09);
        }

        button {
            width: 100%;
            height: 47px;
            margin-top: 5px;
            border: none;
            border-radius: 8px;
            background: #30358b;
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, transform .2s, box-shadow .2s;
        }

        button:hover {
            background: #252a73;
            box-shadow: 0 5px 14px rgba(48, 53, 139, .20);
        }

        button:active {
            transform: scale(.99);
        }

        .error {
            margin: 0 0 20px;
            padding: 12px 14px;
            border: 1px solid #f3d0d0;
            border-radius: 8px;
            background: #fff1f1;
            color: #a32020;
            font-size: 13px;
            line-height: 1.5;
        }

       .pie {
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid #edf0f5;
            text-align: center;
            color: #929bb0;
            font-size: 11px;
            line-height: 1.6;
        }
        
        .pie strong {
            color: #30358b;
            font-size: 12px;
        }

        @media (max-width: 480px) {
            body {
                padding: 18px;
            }

            .login {
                padding: 34px 24px 28px;
            }

            h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

    <main class="login">

       <header class="encabezado">

    <img src="cloud_tux.png" alt="Cloud Tux Logo" class="logo">
           
        <h1>Acceso al Sistema</h1>
    
        <p class="subtitulo">
            <strong>CURSO:</strong>
            Administración de Bases de Datos
            <br>
            <strong>Prof. Manuel Espinoza G.</strong>
        </p>
    
    </header>

        <div class="separador"></div>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">

            <div class="campo">
                <label for="usuario">Usuario</label>

                <input
                    type="text"
                    id="usuario"
                    name="usuario"
                    placeholder="Ingresa tu usuario"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="campo">
                <label for="clave">Contraseña</label>

                <input
                    type="password"
                    id="clave"
                    name="clave"
                    placeholder="Ingresa tu contraseña"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit">
                Ingresar al Panel
            </button>

        </form>

        <footer class="pie">
            <strong>Grupo 6 · Cloud TUX</strong><br>
            Josué Peñaranda Alvarado · Ximena Ortiz Llanes<br>
            Diego Alvarado Salazar · Jesús Barboza Rios
        </footer>

    </main>

</body>
</html>
