#!/bin/bash
# Publicação automática: confere o GitHub e, se houver versão nova aprovada
# na branch main, atualiza o repositório do cPanel e publica no site.
#
# Uso no cPanel → Cron Jobs (a cada 15 minutos; a hospedagem não aceita menos):
#   /bin/bash $HOME/repositories/<pasta do repositório>/deploy/auto-deploy.sh >/dev/null 2>&1
# (na instalação atual a pasta é sistema-gestao)
#
# Na VM Debian (deploy/debian/install.sh), o cron do root chama este mesmo script; o arquivo
# deploy/servidor.conf (criado pelo instalador, fora do Git) diz a pasta do site e o dono dos arquivos.
#
# O registro fica em ~/logs/ciclos-erp-deploy.log (só quando algo é publicado ou dá erro).

# Lê deploy/servidor.conf (VM): MODE=vm, DEPLOYPATH=/var/www/..., OWNER=www-data
servidor_conf() {
    local REPO="$1"
    MODE="cpanel"; OWNER=""; CONF_DEPLOYPATH=""
    if [ -f "$REPO/deploy/servidor.conf" ]; then
        # shellcheck disable=SC1091
        . "$REPO/deploy/servidor.conf"
        CONF_DEPLOYPATH="${DEPLOYPATH:-}"
    fi
}

# Copia os arquivos para o site, com as mesmas tarefas do .cpanel.yml.
# Na VM troca a pasta do site pela do servidor.conf e acerta o dono dos arquivos.
copiar() {
    local TASKS
    TASKS=$(sed -n 's/^[[:space:]]*- \(.*\)$/\1/p' .cpanel.yml)
    if [ -n "$CONF_DEPLOYPATH" ]; then
        TASKS=$(printf '%s\n' "$TASKS" | sed "s#^export DEPLOYPATH=.*#export DEPLOYPATH=${CONF_DEPLOYPATH%/}/#")
    fi
    (set -e; eval "$TASKS") || return 1
    if [ -n "$OWNER" ] && [ -n "$CONF_DEPLOYPATH" ]; then chown -R "$OWNER:$OWNER" "$CONF_DEPLOYPATH"; fi
}

# Tudo dentro de uma função: o bash lê o script inteiro antes de rodar,
# então ele pode ser atualizado pelo próprio git sem quebrar a execução.
main() {
    set -u
    # Pasta do repositório no cPanel = a pasta acima deste script (vale para qualquer nome de pasta)
    local REPO; REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
    local BRANCH="main"
    local LOG_DIR="$HOME/logs"
    local LOG="$LOG_DIR/ciclos-erp-deploy.log"
    local LOCK="$HOME/.ciclos-erp-deploy.lock"

    mkdir -p "$LOG_DIR"
    log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

    # Evita duas publicações ao mesmo tempo
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$LOCK"
        flock -n 9 || return 0
    fi

    cd "$REPO" || { log "ERRO: pasta $REPO não encontrada"; return 1; }
    servidor_conf "$REPO"

    if ! git fetch -q origin "$BRANCH" 2>>"$LOG"; then
        log "ERRO: não consegui consultar o GitHub (veja a mensagem acima)"
        return 1
    fi

    local LOCAL REMOTE
    LOCAL=$(git rev-parse HEAD)
    REMOTE=$(git rev-parse "origin/$BRANCH")
    [ "$LOCAL" = "$REMOTE" ] && return 0   # nada novo

    log "Nova versão encontrada: ${LOCAL:0:7} -> ${REMOTE:0:7} ($(git log -1 --format=%s "origin/$BRANCH"))"
    git checkout -q "$BRANCH" 2>>"$LOG"
    if ! git reset -q --hard "origin/$BRANCH" 2>>"$LOG"; then
        log "ERRO: não consegui atualizar o repositório"
        return 1
    fi

    # 1ª opção (só no cPanel): a própria implantação do cPanel (aparece no histórico do Git Version Control)
    local UAPI
    UAPI=$(command -v uapi || echo /usr/local/cpanel/bin/uapi)
    if [ "$MODE" != "vm" ] && [ -x "$UAPI" ] && "$UAPI" VersionControlDeployment create repository_root="$REPO" 2>>"$LOG" | grep -q "status: 1"; then
        log "Publicado pelo cPanel (Deploy HEAD Commit)."
        return 0
    fi

    # 2ª opção (e a única na VM): executa as mesmas tarefas do .cpanel.yml diretamente
    [ "$MODE" = "vm" ] || log "Implantação do cPanel indisponível; executando as tarefas do .cpanel.yml."
    if copiar >>"$LOG" 2>&1; then
        log "Publicado com sucesso."
    else
        log "ERRO: falha ao copiar os arquivos para o site"
        return 1
    fi
}

# Tarefas agendadas do sistema (backup automático no Google Drive): roda a cada chamada,
# mesmo sem versão nova. O próprio sistema decide se chegou a hora.
tarefas() {
    local REPO LOG="$HOME/logs/ciclos-erp-deploy.log" SITE PHP
    REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
    servidor_conf "$REPO"
    if [ -n "$CONF_DEPLOYPATH" ]; then
        SITE="$CONF_DEPLOYPATH"
    else
        SITE=$(sed -n 's/^[[:space:]]*- export DEPLOYPATH=//p' "$REPO/.cpanel.yml" 2>/dev/null | head -1)
        SITE=$(eval echo "$SITE")
    fi
    [ -n "$SITE" ] && [ -f "${SITE%/}/app/cron.php" ] && [ -f "${SITE%/}/app/config.php" ] || return 0
    PHP=$(command -v php || echo /usr/local/bin/php)
    [ -x "$PHP" ] || return 0
    # Na VM o cron roda como root: as tarefas do sistema rodam como o dono do site (www-data)
    if [ -n "$OWNER" ] && [ "$(id -u)" = "0" ] && command -v runuser >/dev/null 2>&1; then
        runuser -u "$OWNER" -- "$PHP" "${SITE%/}/app/cron.php" >> "$LOG" 2>&1
    else
        "$PHP" "${SITE%/}/app/cron.php" >> "$LOG" 2>&1
    fi
}

main "$@"
rc=$?
tarefas
exit $rc
