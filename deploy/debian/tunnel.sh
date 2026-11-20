#!/bin/bash
# Acesso pela internet com Cloudflare Tunnel (HTTPS, sem abrir portas no roteador e sem IP fixo).
# Antes, crie o túnel no painel da Cloudflare (passo a passo no README do repositório ciclos-erp)
# e tenha em mãos o token que ele mostra.
#
#   bash /opt/ciclos-erp/deploy/debian/tunnel.sh
#
# O token é pedido aqui no terminal (não aparece na tela) e fica só neste servidor.
set -euo pipefail
# Precisa de root: se foi chamado por um usuário comum, roda de novo com sudo (pede a senha dele)
if [ "$(id -u)" != 0 ]; then
    command -v sudo >/dev/null || { echo "Este comando precisa de root: rode como root ou instale o sudo (apt-get install sudo)." >&2; exit 1; }
    exec sudo bash "$0" "$@"
fi
export HOME=/root   # configurações ficam no root, mesmo vindo do sudo

if ! command -v cloudflared >/dev/null; then
    echo "Instalando o cloudflared (repositório oficial da Cloudflare)..."
    install -m 0755 -d /usr/share/keyrings
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg
    echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' \
        > /etc/apt/sources.list.d/cloudflared.list
    apt-get update -qq
    apt-get install -y -qq cloudflared >/dev/null
fi
echo "cloudflared $(cloudflared --version 2>/dev/null | awk '{print $3}') instalado."

if systemctl is-active --quiet cloudflared 2>/dev/null; then
    echo "O túnel já está ativo neste servidor. Para trocar o token: cloudflared service uninstall e rode de novo."
    exit 0
fi

echo
echo "Cole o token do túnel (começa com \"eyJ\") e aperte Enter. Ele não aparece enquanto você cola:"
read -r -s TOKEN
echo
TOKEN="${TOKEN#*--token }"; TOKEN="${TOKEN##* }"   # aceita também o comando inteiro copiado do painel
[ ${#TOKEN} -gt 50 ] || { echo "Token muito curto; confira no painel da Cloudflare."; exit 1; }
cloudflared service install "$TOKEN" >/dev/null 2>&1
unset TOKEN
sleep 3
if systemctl is-active --quiet cloudflared; then
    echo "Túnel ativo. No painel da Cloudflare, o conector deve aparecer como HEALTHY."
    echo "Confira se o \"Public hostname\" aponta para  http://localhost:80"
else
    echo "O serviço não subiu. Veja: journalctl -u cloudflared -n 50"
    exit 1
fi
