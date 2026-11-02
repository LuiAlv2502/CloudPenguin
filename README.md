# CloudTUX

## Organización

- `Module_1/`: monitor del servidor (`estado_servidor.php`), configuración (`env.php`) y scripts SQL (`scripts/`).
- `Module_2/`: interfaz de respaldos (`respaldos.php`) y script de generación (`backup.sh`).
- `Module_6/`: explorador de metadata de tablas propias en PostgreSQL (`diccionario.php`).
- La conexión a la base de datos, el inicio de sesión y el menú principal permanecen en la raíz del proyecto.

## Módulo 06: Explorador del diccionario de datos

Desde el menú principal, abra **Módulo 06 · Explorador del diccionario de datos**. Seleccione una tabla del esquema `public` para consultar sus columnas, tipos, valores nulos, valores predeterminados, llaves y restricciones, e índices disponibles. El módulo usa la conexión configurada y solo realiza consultas de lectura sobre metadata; no necesita instalaciones, permisos administrativos ni cambios a los datos.

Para probarlo, seleccione `productos` y compruebe que aparecen sus columnas y la llave primaria `id_producto`. También puede seleccionar `ventas` o `detalle_venta` para revisar sus restricciones e índices. Si una tabla no aparece, el usuario de conexión no la ve en el esquema permitido o no existe en esa base.

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
