# CloudTUX

## Organización

- `Module_1/`: monitor del servidor (`estado_servidor.php`), configuración (`env.php`) y scripts SQL (`scripts/`).
- `Module_2/`: interfaz de respaldos (`respaldos.php`) y script de generación (`backup.sh`).
- La conexión a la base de datos, el inicio de sesión y el menú principal permanecen en la raíz del proyecto.

## Configuración

La aplicación y el script de respaldos leen la configuración de `~/proyecto/.env`, fuera del directorio público. También se puede indicar otra ruta con la variable de entorno `DBA_ENV_FILE`, siempre que el archivo sea legible por PHP y por el usuario que ejecute `backup.sh`.

El archivo debe usar asignaciones compatibles con Bash, por ejemplo:

```sh
DB_NAME=nombre_base
DB_HOST=localhost
DB_PORT=5432
DB_USER=usuario_base
DB_PASS='contraseña'
DBA_USER=usuario_panel
DBA_PASS_HASH='hash_de_contraseña'
```

`DB_NAME` y `DB_PASS` son obligatorios para el respaldo. `DB_HOST` y `DB_PORT` tienen valores predeterminados (`localhost` y `5432`); `DB_USER` usa por defecto el usuario del sistema. `DBA_USER` y `DBA_PASS_HASH` se usan para el inicio de sesión del panel. Proteja el archivo de configuración para que solo lo lean los usuarios necesarios.

## Respaldos de PostgreSQL

En el servidor, coloque el proyecto en `~/proyecto`. La interfaz ejecuta `Module_2/backup.sh` desde su ubicación dentro del proyecto; el script crea los respaldos en `~/proyecto/respaldos/` y registra el resultado en `respaldos.log`. Los archivos se nombran `respaldo_<base>_<fecha>.sql.gz`.

El servidor necesita Bash, `pg_dump`, `gzip` y la extensión PHP `pdo_pgsql`. El usuario con el que corre PHP debe poder ejecutar el script y acceder a `.env`; el script necesita permiso de ejecución:

```sh
chmod 700 ~/public_html/CloudPenguin/Module_2/backup.sh
```

Al crear un respaldo, el script comprueba tanto el resultado de `pg_dump` como la integridad gzip antes de conservar el archivo. La interfaz lista los archivos `respaldo_*.sql.gz` y muestra las últimas entradas de la bitácora.
