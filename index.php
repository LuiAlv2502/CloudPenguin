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

        .login {
            width: 100%;
            max-width: 440px;
            padding: 42px 38px 36px;
            background: #ffffff;
            border: 1px solid #e8ebf2;
            border-top: 5px solid #30358b;
            border-radius: 14px;
            box-shadow: 0 12px 35px rgba(34, 45, 90, 0.08);
        }

        .encabezado {
            text-align: center;
            margin-bottom: 30px;
        }

        .icono {
            width: 54px;
            height: 54px;
            margin: 0 auto 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: #eef0ff;
            color: #30358b;
        }

        .icono svg {
            width: 27px;
            height: 27px;
        }

        h1 {
            margin: 0 0 12px;
            color: #30358b;
            font-size: 25px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .subtitulo {
            margin: 0;
            color: #75809a;
            font-size: 13px;
            line-height: 1.7;
        }

        .separador {
            height: 1px;
            margin: 25px 0;
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
            background: #ffffff;
            color: #263453;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input::placeholder {
            color: #a3abc0;
        }

        input:focus {
            border-color: #30358b;
            box-shadow: 0 0 0 3px rgba(48, 53, 139, 0.09);
        }

        button {
            width: 100%;
            height: 47px;
            margin-top: 5px;
            border: none;
            border-radius: 8px;
            background: #30358b;
            color: #ffffff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s,
                        box-shadow 0.2s;
        }

        button:hover {
            background: #252a73;
            box-shadow: 0 5px 14px rgba(48, 53, 139, 0.20);
        }

        button:active {
            transform: scale(0.99);
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

            <div class="icono">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <rect x="4" y="10" width="16" height="11" rx="2"/>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    <path d="M12 14v3"/>
                </svg>
            </div>

            <h1>Acceso al Sistema</h1>

            <p class="subtitulo">
                Administración de Bases de Datos
                <br>
                <strong>Prof. Manuel Espinoza G.</strong>
            </p>

        </header>

        <div class="separador"></div>

        <form action="#" method="post">

            <div class="campo">
                <label for="usuario">Usuario</label>

                <input
                    type="text"
                    id="usuario"
                    name="usuario"
                    placeholder="Ingresa tu usuario"
                    autocomplete="username"
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
                >
            </div>

            <button type="button">
                Ingresar al Panel
            </button>

        </form>

        <footer class="pie">
            Sistema de Administración
        </footer>

    </main>

</body>
</html>