#!/bin/bash
# Backup diário do banco (agendado pelo install.sh às 3h). Guarda 30 dias em /var/backups/ciclos-erp.
#   bash /opt/ciclos-erp/deploy/debian/backup.sh
# Para restaurar: bash /opt/ciclos-erp/deploy/debian/restore.sh /var/backups/ciclos-erp/<arquivo>.sql.gz
set -euo pipefail
# Precisa de root: se foi chamado por um usuário comum, roda de novo com sudo (pede a senha dele)
if [ "$(id -u)" != 0 ]; then
    command -v sudo >/dev/null || { echo "Este comando precisa de root: rode como root ou instale o sudo (apt-get install sudo)." >&2; exit 1; }
    exec sudo bash "$0" "$@"
fi
export HOME=/root   # configurações ficam no root, mesmo vindo do sudo

DIR="/var/backups/ciclos-erp"; DIAS=30
LOG="/var/log/ciclos-erp/backup.log"
mkdir -p "$DIR" /var/log/ciclos-erp; chmod 700 "$DIR"
ARQ="$DIR/bkp-db-$(date +%Y-%m-%d_%H%M).sql.gz"
DB=$(php -r '$c = require "/var/www/ciclos-erp/app/config.php"; echo $c["db"]["name"];')
if mysqldump --single-transaction --quick --default-character-set=utf8mb4 "$DB" | gzip -9 > "$ARQ.tmp"; then
    mv "$ARQ.tmp" "$ARQ"; chmod 600 "$ARQ"
    find "$DIR" -name 'bkp-db-*.sql.gz' -mtime +"$DIAS" -delete
    echo "$(date '+%Y-%m-%d %H:%M:%S') Backup ok: $ARQ ($(du -h "$ARQ" | cut -f1))" >> "$LOG"
else
    rm -f "$ARQ.tmp"
    echo "$(date '+%Y-%m-%d %H:%M:%S') ERRO no backup" >> "$LOG"
    exit 1
fi
