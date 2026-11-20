#!/bin/bash
# Administração de usuários pelo console do servidor (o único jeito de rebaixar, desativar ou
# excluir um administrador). Exemplos:
#   sudo bash /opt/ciclos-erp/deploy/debian/users.sh listar
#   sudo bash /opt/ciclos-erp/deploy/debian/users.sh desativar fulano@empresa.com
# Comandos: listar, desativar, ativar, rebaixar, promover, excluir (veja app/users-cli.php).
set -euo pipefail
# Precisa de root: se foi chamado por um usuário comum, roda de novo com sudo (pede a senha dele)
if [ "$(id -u)" != 0 ]; then
    command -v sudo >/dev/null || { echo "Este comando precisa de root: rode como root ou instale o sudo (apt-get install sudo)." >&2; exit 1; }
    exec sudo bash "$0" "$@"
fi
export HOME=/root

SITE="/var/www/ciclos-erp"
[ -f "$SITE/app/users-cli.php" ] || { echo "Sistema não encontrado em $SITE (rode o install.sh)." >&2; exit 1; }
# Roda como o usuário do Apache, que já lê o app/config.php
exec runuser -u www-data -- php "$SITE/app/users-cli.php" "$@"
