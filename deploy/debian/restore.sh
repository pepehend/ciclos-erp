#!/bin/bash
# Restaura um backup DESTE servidor (os diários em /var/backups/ciclos-erp) ou o arquivo baixado
# em Configurações → Sistema → Backup do sistema. Use se precisar voltar os dados a um dia anterior.
#
#   bash /opt/ciclos-erp/deploy/debian/restore.sh /root/bkp-db-2026-11-11-030000.sql
#
# ATENÇÃO: substitui os dados atuais do servidor pelos do arquivo. Antes, faz um backup de segurança.
set -euo pipefail
# Precisa de root: se foi chamado por um usuário comum, roda de novo com sudo (pede a senha dele)
if [ "$(id -u)" != 0 ]; then
    command -v sudo >/dev/null || { echo "Este comando precisa de root: rode como root ou instale o sudo (apt-get install sudo)." >&2; exit 1; }
    exec sudo bash "$0" "$@"
fi
export HOME=/root   # configurações ficam no root, mesmo vindo do sudo

KIT="/opt/ciclos-erp/deploy/debian"; SITE="/var/www/ciclos-erp"
ARQ="${1:-}"
[ -n "$ARQ" ] && [ -f "$ARQ" ] || { echo "Uso: bash restore.sh /caminho/do/backup.sql[.gz]"; exit 1; }
DB=$(php -r '$c = require "/var/www/ciclos-erp/app/config.php"; echo $c["db"]["name"];')

echo "Isto vai SUBSTITUIR os dados do banco \"$DB\" pelos do arquivo:"
echo "  $ARQ"
read -r -p "Digite restaurar para continuar: " CONF
[ "$CONF" = "restaurar" ] || { echo "Cancelado."; exit 1; }

echo "Backup de segurança do estado atual..."
bash "$KIT/backup.sh" && ls -t /var/backups/ciclos-erp/*.sql.gz | head -1

ler() { if [[ "$ARQ" == *.gz ]]; then gunzip -c "$ARQ"; else cat "$ARQ"; fi; }
if ler | grep 'CREATE TABLE' >/dev/null; then  # sem -q: lê o arquivo todo (evita erro de pipe)
    # Exportação completa (phpMyAdmin/mysqldump): traz as próprias tabelas, então começa de um banco vazio
    echo "Arquivo com estrutura completa: recriando o banco vazio..."
    mariadb -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
else
    # Backup do próprio sistema (só os dados): cria as tabelas antes
    echo "Preparando as tabelas..."
    php "$KIT/setup.php" "$SITE" --quieto
fi

echo "Importando..."
# MySQL 8 (hospedagem) usa um agrupamento que o MariaDB não conhece: troca pelo equivalente
ler | sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' -e 's/utf8mb4_0900_as_cs/utf8mb4_bin/g' \
    | mariadb --default-character-set=utf8mb4 "$DB"

echo "Atualizando o banco para a versão instalada..."
php "$KIT/setup.php" "$SITE"
echo "Pronto. Os usuários e senhas voltam a ser os do backup."
