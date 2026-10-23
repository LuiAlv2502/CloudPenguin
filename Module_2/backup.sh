#!/bin/bash
# =============================================================
# backup.sh - Respaldo de PostgreSQL para la Consola DBA v1.0
# Ubicación: ~/proyecto/Module_2/backup.sh   (chmod 700)
# Lee la configuración desde ~/proyecto/.env (sin valores fijos)
# Salida: imprime el nombre del archivo si todo sale bien.
# Código de salida: 0 = OK, 1 = ERROR
# =============================================================

set -uo pipefail
umask 077   # archivos creados quedan 600, carpetas 700

# ---------- 1. Cargar configuración ----------
ENV_FILE="${DBA_ENV_FILE:-$HOME/proyecto/.env}"

if [ ! -r "$ENV_FILE" ]; then
    echo "ERROR: no se puede leer $ENV_FILE" >&2
    exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

# Valores por defecto (solo DB_NAME y DB_PASS son obligatorios en el env)
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-$(id -un)}"   # usuario Linux de la cuenta = usuario de PostgreSQL en Virtualmin

# Verificar variables obligatorias
for var in DB_NAME DB_PASS; do
    if [ -z "${!var:-}" ]; then
        echo "ERROR: falta la variable $var en $ENV_FILE" >&2
        exit 1
    fi
done

# Carpeta fija: los respaldos SOLO se guardan en $HOME/proyecto/respaldos
# (relativa a $HOME, así funciona en cualquier cuenta sin editar nada)
BACKUP_DIR="$HOME/proyecto/respaldos"
LOG_FILE="$BACKUP_DIR/respaldos.log"

# ---------- 2. Preparar carpeta ----------
mkdir -p "$BACKUP_DIR"   # no hace nada si la carpeta ya existe
chmod 700 "$BACKUP_DIR"  # solo esta carpeta; no se toca $HOME/proyecto

if [ ! -w "$BACKUP_DIR" ]; then
    echo "ERROR: no hay permiso de escritura en $BACKUP_DIR" >&2
    exit 1
fi

# ---------- 3. Nombre con fecha, hora, minutos y segundos ----------
FECHA=$(date +%Y-%m-%d_%H%M%S)
NOMBRE="respaldo_${DB_NAME}_${FECHA}.sql.gz"
DESTINO="$BACKUP_DIR/$NOMBRE"
TEMP="$DESTINO.tmp"

# ---------- 4. Función de bitácora ----------
# Formato: AAAA-MM-DD HH:MM:SS | archivo | OK/ERROR
registrar() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') | $1 | $2" >> "$LOG_FILE"
}

# ---------- 5. Ejecutar pg_dump y comprimir ----------
export PGPASSWORD="$DB_PASS"

pg_dump -w -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME" | gzip > "$TEMP"
ESTADOS=("${PIPESTATUS[@]}")   # [0]=pg_dump  [1]=gzip

unset PGPASSWORD

# ---------- 6. Validar resultado ----------
if [ "${ESTADOS[0]}" -eq 0 ] && [ "${ESTADOS[1]}" -eq 0 ] \
    && [ -s "$TEMP" ] && gzip -t "$TEMP" 2>/dev/null; then
    mv "$TEMP" "$DESTINO"
    registrar "$NOMBRE" "OK"
    echo "$NOMBRE"
    exit 0
else
    rm -f "$TEMP"
    registrar "$NOMBRE" "ERROR"
    echo "ERROR: falló el respaldo de $DB_NAME" >&2
    exit 1
fi