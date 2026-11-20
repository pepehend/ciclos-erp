#!/bin/bash
# Instala o sistema numa VM Debian 13 (trixie) limpa: Apache + PHP + MariaDB + Git + Cron.
#
# Uso (como root):
#   bash install.sh --domain sistema.exemplo.com.br [--repo URL] [--branch main] [--site /var/www/ciclos-erp]
#                   [--repo-dir /opt/ciclos-erp] [--db mysql|sqlite] [--ssl email@exemplo.com]
#
# Depois de rodar, abra http(s)://<domínio>/install.php no navegador e use os dados do banco que
# o script mostra no fim (ficam também em /root/ciclos-erp-banco.txt).
#
# Atualizações: o cron do root chama deploy/auto-deploy.sh a cada 5 minutos, igual ao cPanel
# (publica a branch main quando o Pedro aprova o PR) e roda as tarefas do sistema (backup no Google Drive).
set -euo pipefail

DOMAIN=""
REPO_URL="https://github.com/pepehend/ciclos-erp.git"
BRANCH="main"
SITE="/var/www/ciclos-erp"
REPO_DIR="/opt/ciclos-erp"
DB="mysql"
SSL_EMAIL=""

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --repo) REPO_URL="$2"; shift 2 ;;
        --branch) BRANCH="$2"; shift 2 ;;
        --site) SITE="${2%/}"; shift 2 ;;
        --repo-dir) REPO_DIR="${2%/}"; shift 2 ;;
        --db) DB="$2"; shift 2 ;;
        --ssl) SSL_EMAIL="$2"; shift 2 ;;
        -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
        *) echo "Opção desconhecida: $1" >&2; exit 1 ;;
    esac
done

[ "$(id -u)" = "0" ] || { echo "Rode como root (sudo bash install.sh ...)" >&2; exit 1; }
[ -n "$DOMAIN" ] || { echo "Informe o domínio: --domain sistema.exemplo.com.br (ou o IP da VM)" >&2; exit 1; }
case "$DB" in mysql|sqlite) ;; *) echo "--db precisa ser mysql ou sqlite" >&2; exit 1 ;; esac
if [ -r /etc/os-release ]; then
    . /etc/os-release
    [ "${ID:-}" = "debian" ] || echo "Aviso: feito para Debian 13; este sistema é ${PRETTY_NAME:-desconhecido}."
fi

passo() { echo; echo "==> $*"; }

passo "Instalando pacotes (Apache, PHP, MariaDB, Git, Cron)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
PKGS="apache2 libapache2-mod-php php-cli php-mysql php-sqlite3 php-mbstring php-xml php-curl php-gd php-zip git cron ca-certificates curl"
[ "$DB" = "mysql" ] && PKGS="$PKGS mariadb-server"
[ -n "$SSL_EMAIL" ] && PKGS="$PKGS certbot python3-certbot-apache"
# shellcheck disable=SC2086
apt-get install -y -q $PKGS

passo "Baixando o sistema do GitHub em $REPO_DIR"
if [ -d "$REPO_DIR/.git" ]; then
    git -C "$REPO_DIR" fetch -q origin "$BRANCH"
    git -C "$REPO_DIR" checkout -q "$BRANCH"
    git -C "$REPO_DIR" reset -q --hard "origin/$BRANCH"
else
    git clone -q --branch "$BRANCH" "$REPO_URL" "$REPO_DIR" || {
        echo "Não consegui clonar $REPO_URL. Se o repositório for privado, use uma chave de implantação (deploy key)" >&2
        echo "ou um token: --repo https://<token>@github.com/pepehend/ciclos-erp.git" >&2
        exit 1
    }
fi

passo "Configurando a publicação (deploy/servidor.conf)"
cat > "$REPO_DIR/deploy/servidor.conf" <<CONF
# Criado por deploy/debian/install.sh. Lido por deploy/auto-deploy.sh.
MODE=vm
DEPLOYPATH=$SITE/
OWNER=www-data
CONF

passo "Publicando os arquivos em $SITE"
mkdir -p "$SITE"
# A 1ª publicação copia direto, com as tarefas do .cpanel.yml (depois o auto-deploy cuida das novas versões)
(
    cd "$REPO_DIR"
    TASKS=$(sed -n 's/^[[:space:]]*- \(.*\)$/\1/p' .cpanel.yml | sed "s#^export DEPLOYPATH=.*#export DEPLOYPATH=$SITE/#")
    set -e; eval "$TASKS"
)
# O instalador só vai na primeira vez (ele se bloqueia depois de instalar; apague-o depois)
[ -f "$SITE/app/config.php" ] || cp "$REPO_DIR/install.php" "$SITE/"
chown -R www-data:www-data "$SITE"
find "$SITE" -type d -exec chmod 755 {} +
find "$SITE" -type f -exec chmod 644 {} +

passo "Configurando o PHP"
for d in /etc/php/*/apache2/conf.d /etc/php/*/cli/conf.d; do
    [ -d "$d" ] || continue
    cat > "$d/99-ciclos-erp.ini" <<'INI'
; CiclosERP (mesmos limites do .user.ini do cPanel)
upload_max_filesize = 16M
post_max_size = 18M
memory_limit = 256M
max_execution_time = 120
expose_php = Off
INI
done
TZ_NAME=$(timedatectl show -p Timezone --value 2>/dev/null || echo "America/Sao_Paulo")
[ -n "$TZ_NAME" ] && [ "$TZ_NAME" != "UTC" ] || TZ_NAME="America/Sao_Paulo"
for d in /etc/php/*/apache2/conf.d /etc/php/*/cli/conf.d; do
    [ -d "$d" ] && echo "date.timezone = $TZ_NAME" >> "$d/99-ciclos-erp.ini"
done

passo "Configurando o Apache ($DOMAIN)"
cat > /etc/apache2/sites-available/ciclos-erp.conf <<APACHE
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $SITE
    <Directory $SITE>
        # O sistema usa .htaccess (bloqueio de arquivos e da pasta data/), como no cPanel
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/ciclos-erp-error.log
    CustomLog \${APACHE_LOG_DIR}/ciclos-erp-access.log combined
</VirtualHost>
APACHE
a2enmod -q rewrite headers >/dev/null
a2ensite -q ciclos-erp >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true
apache2ctl configtest
systemctl enable --now apache2 cron >/dev/null
systemctl reload apache2

DBINFO=""
if [ "$DB" = "mysql" ]; then
    passo "Criando o banco de dados MariaDB"
    systemctl enable --now mariadb >/dev/null
    DBNAME="ciclos_erp"; DBUSER="ciclos_erp"
    if [ -f /root/ciclos-erp-banco.txt ]; then
        DBPASS=$(sed -n 's/^Senha: //p' /root/ciclos-erp-banco.txt)
    else
        DBPASS=$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
    fi
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DBNAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';
ALTER USER '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON \`$DBNAME\`.* TO '$DBUSER'@'localhost';
FLUSH PRIVILEGES;
SQL
    DBINFO="Servidor: localhost
Banco: $DBNAME
Usuário: $DBUSER
Senha: $DBPASS"
    umask 077
    printf '%s\n' "$DBINFO" > /root/ciclos-erp-banco.txt
fi

passo "Agendando a publicação automática e as tarefas do sistema (cron a cada 5 minutos)"
cat > /etc/cron.d/ciclos-erp <<CRON
# CiclosERP: publica a branch $BRANCH aprovada e roda o backup automático
*/5 * * * * root /bin/bash $REPO_DIR/deploy/auto-deploy.sh >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/ciclos-erp

if [ -n "$SSL_EMAIL" ]; then
    passo "Ativando HTTPS (Let's Encrypt)"
    certbot --apache -n --agree-tos -m "$SSL_EMAIL" -d "$DOMAIN" --redirect || echo "Aviso: não consegui ativar o HTTPS agora. Confira o DNS do domínio e rode: certbot --apache -d $DOMAIN"
fi

echo
echo "============================================================"
echo " Pronto! Abra no navegador: http${SSL_EMAIL:+s}://$DOMAIN/install.php"
if [ "$DB" = "mysql" ]; then
    echo " Escolha MySQL e use:"
    echo "$DBINFO" | sed 's/^/   /'
    echo " (guardado em /root/ciclos-erp-banco.txt)"
else
    echo " Escolha SQLite (o banco fica em $SITE/data, protegido pelo .htaccess)."
fi
echo " Depois de instalar, apague o instalador: rm $SITE/install.php"
echo " E-mails (OS digital, pesquisa, convites do portal) usam a função mail() do PHP:"
echo " instale e configure um envio de e-mail (ex.: apt install msmtp-mta) para eles saírem."
echo " Registro das publicações: /root/logs/ciclos-erp-deploy.log"
echo "============================================================"
