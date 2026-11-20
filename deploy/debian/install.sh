#!/bin/bash
# Instala o CiclosERP num container LXC Debian 13 (Proxmox), numa VM Debian 13 ou em qualquer Debian/Ubuntu.
# Apache + PHP + MariaDB no mesmo servidor, atualização automática pelo GitHub e backup diário.
#
# Uso (como root, ou com sudo a partir de um usuário comum) — veja o README do repositório ciclos-erp:
#   sudo apt-get update && sudo apt-get install -y git
#   sudo git clone https://github.com/pepehend/ciclos-erp.git /opt/ciclos-erp
#   sudo bash /opt/ciclos-erp/deploy/debian/install.sh
#
# O repositório é público: não precisa de chave, token nem do link "raw" do GitHub.
# Ele usa o clone em /opt/ciclos-erp (scripts do servidor em deploy/debian/) e publica
# o sistema em /var/www/ciclos-erp. Pode rodar de novo sem problema: o que já existe é mantido.

set -euo pipefail

REPO_URL="https://github.com/pepehend/ciclos-erp.git"
BRANCH="main"
SRC="/opt/ciclos-erp"               # cópia deste repositório (atualizada pelo GitHub)
KIT="$SRC/deploy/debian"            # scripts do servidor
SITE="/var/www/ciclos-erp"          # o que o Apache publica
DB_NAME="ciclos_erp"
DB_USER="ciclos_erp"
LOGS="/var/log/ciclos-erp"
BACKUPS="/var/backups/ciclos-erp"

azul() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok() { printf '\033[1;32m%s\033[0m\n' "$*"; }
erro() { printf '\033[1;31mERRO: %s\033[0m\n' "$*" >&2; exit 1; }

# Precisa de root: se foi chamado por um usuário comum, roda de novo com sudo (pede a senha dele)
if [ "$(id -u)" != 0 ]; then
    command -v sudo >/dev/null || { echo "Este comando precisa de root: rode como root ou instale o sudo (apt-get install sudo)." >&2; exit 1; }
    exec sudo bash "$0" "$@"
fi
export HOME=/root   # configurações ficam no root, mesmo vindo do sudo
# Rodando a cópia de dentro de $SRC: o git vai reescrever este arquivo, então roda uma cópia temporária
case "$(readlink -f "$0")" in
    "$SRC"/*) TMP_SELF="$(mktemp /tmp/ciclos-erp-install.XXXXXX)"; cp "$0" "$TMP_SELF"; exec bash "$TMP_SELF" "$@" ;;
esac
command -v apt-get >/dev/null || erro "este instalador é para Debian/Ubuntu."

# ---------------------------------------------------------------- pacotes
echo "Instalador do CiclosERP (ciclos-erp)"

azul "Instalando Apache, PHP, MariaDB e utilitários"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 libapache2-mod-php php-cli php-mysql php-mbstring php-xml php-curl php-gd \
    mariadb-server git cron curl ca-certificates locales sudo >/dev/null
systemctl enable --now apache2 mariadb cron >/dev/null 2>&1 || true
ok "Pacotes instalados ($(php -r 'echo PHP_VERSION;'))"

# ---------------------------------------------------------------- código
azul "Baixando o sistema"
git config --global --add safe.directory "$SRC" 2>/dev/null || true
if [ -d "$SRC/.git" ]; then
    git -C "$SRC" remote set-url origin "$REPO_URL"
    git -C "$SRC" fetch -q origin "$BRANCH" && git -C "$SRC" checkout -q "$BRANCH" && git -C "$SRC" reset -q --hard "origin/$BRANCH"
else
    if [ -e "$SRC" ]; then mv "$SRC" "$SRC.antigo.$(date +%s)"; fi
    git clone -q -b "$BRANCH" "$REPO_URL" "$SRC" || erro "não consegui baixar $REPO_URL (confira a internet do servidor)."
fi
VERSION="$(grep -oP "APP_VERSION = '\K[^']+" "$SRC/app/bootstrap.php" || echo '?')"
chmod 755 "$KIT"/*.sh "$SRC/deploy/auto-deploy.sh"
ok "Código em $SRC: versão $VERSION ($(git -C "$SRC" log -1 --format='%h %s'))"

# Publicação deste servidor: deploy/auto-deploy.sh lê este arquivo (fora do Git) para saber onde publicar
cat > "$SRC/deploy/servidor.conf" <<CONF
# Criado por deploy/debian/install.sh. Lido por deploy/auto-deploy.sh.
MODE=vm
DEPLOYPATH=$SITE/
OWNER=www-data
LOG_DIR=$LOGS
CONF

# ---------------------------------------------------------------- banco de dados
azul "Banco de dados"
CFG="$SITE/app/config.php"
if [ -f "$CFG" ]; then
    ok "Configuração existente mantida ($CFG)"
else
    DB_PASS="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 28)"
    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    mkdir -p "$SITE/app"
    cat > "$CFG" <<PHP
<?php
// Gerado pelo install.sh (ciclos-erp) em $(date '+%d/%m/%Y %H:%M')
return [
    'db' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => '$DB_NAME', 'user' => '$DB_USER', 'pass' => '$DB_PASS'],
    'debug' => false,
];
PHP
    ok "Banco \"$DB_NAME\" criado (a senha fica só em $CFG)"
fi

# ---------------------------------------------------------------- publicar
azul "Publicando o sistema em $SITE"
mkdir -p "$LOGS"
bash "$SRC/deploy/auto-deploy.sh" --forcar || erro "falha ao publicar (veja $LOGS/update.log)"
rm -f "$SITE/install.php"   # aqui o banco e o administrador são preparados pelo console, sem instalador web
php "$KIT/setup.php" "$SITE"

# ---------------------------------------------------------------- PHP
azul "Ajustando o PHP"
for dir in /etc/php/*/apache2/conf.d /etc/php/*/cli/conf.d; do
    [ -d "$dir" ] || continue
    cat > "$dir/99-ciclos-erp.ini" <<'INI'
; CiclosERP
upload_max_filesize = 16M
post_max_size = 18M
memory_limit = 256M
max_execution_time = 120
date.timezone = America/Sao_Paulo
expose_php = Off
; O Debian apaga sessões paradas pelo valor daqui (padrão 24 min); o sistema usa 12 h
session.gc_maxlifetime = 43200
INI
done

# ---------------------------------------------------------------- Apache
azul "Configurando o Apache"
cat > /etc/apache2/sites-available/ciclos-erp.conf <<APACHE
<VirtualHost *:80>
    ServerName ciclos-erp
    DocumentRoot $SITE

    <Directory $SITE>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Quando vier pelo Cloudflare Tunnel, usa o IP real do visitante (o login bloqueia por IP)
    <IfModule mod_remoteip.c>
        RemoteIPHeader CF-Connecting-IP
        RemoteIPInternalProxy 127.0.0.1 ::1
    </IfModule>

    ErrorLog \${APACHE_LOG_DIR}/ciclos-erp-error.log
    CustomLog \${APACHE_LOG_DIR}/ciclos-erp-access.log combined
</VirtualHost>
APACHE
a2enmod -q headers remoteip >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q ciclos-erp >/dev/null
apache2ctl configtest >/dev/null 2>&1 || erro "configuração do Apache inválida (rode: apache2ctl configtest)"
systemctl reload apache2 || systemctl restart apache2
ok "Apache servindo $SITE na porta 80"

# ---------------------------------------------------------------- administrador
# Sistema novo, banco vazio: cria o primeiro usuário (se já existir algum, não pergunta nada)
azul "Primeiro usuário"
until php "$KIT/setup.php" "$SITE" --admin-se-vazio; do
    echo "Vamos tentar de novo."
done

# ---------------------------------------------------------------- tarefas automáticas
azul "Atualização automática e backup diário"
mkdir -p "$LOGS" "$BACKUPS"
chmod 700 "$BACKUPS"
cat > /etc/cron.d/ciclos-erp <<CRON
# CiclosERP
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Confere o GitHub a cada 5 minutos e publica a versão aprovada (branch main); depois roda o backup no Google Drive
*/5 * * * * root bash $SRC/deploy/auto-deploy.sh >/dev/null 2>&1
# Backup do banco todo dia às 3h (guarda 30 dias em $BACKUPS)
0 3 * * * root bash $KIT/backup.sh >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/ciclos-erp
ok "Agendado: atualização a cada 5 min e backup diário às 3h"

IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
azul "Pronto!"
cat <<FIM
Acesse na sua rede:  http://${IP:-IP-DO-SERVIDOR}/

Entre com o e-mail e a senha do administrador e preencha Configurações → Dados da empresa.

Próximos passos (detalhes no README do repositório ciclos-erp):
  Acesso pela internet (links do orçamento, da OS e portal do cliente): sudo bash $KIT/tunnel.sh
  Usuários pelo console:  sudo bash $KIT/users.sh listar
  Restaurar um backup:    sudo bash $KIT/restore.sh $BACKUPS/<arquivo>.sql.gz
  E-mails (OS digital, pesquisa, convite do portal) saem pela função mail() do PHP:
  instale e configure um envio de e-mail no servidor (ex.: apt install msmtp-mta).

Registros: $LOGS/  ·  Backups: $BACKUPS/
FIM
