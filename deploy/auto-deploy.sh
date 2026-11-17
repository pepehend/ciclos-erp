#!/bin/bash
# Publicação automática: confere o GitHub e, se houver versão nova aprovada
# na branch main, atualiza o repositório do cPanel e publica no site.
#
# Uso no cPanel → Cron Jobs (a cada 15 minutos; a hospedagem não aceita menos):
#   /bin/bash $HOME/repositories/<pasta do repositório>/deploy/auto-deploy.sh >/dev/null 2>&1
# (na instalação atual a pasta é sistema-estoque)
#
# O registro fica em ~/logs/estoque-deploy.log (só quando algo é publicado ou dá erro).

# Tudo dentro de uma função: o bash lê o script inteiro antes de rodar,
# então ele pode ser atualizado pelo próprio git sem quebrar a execução.
main() {
    set -u
    # Pasta do repositório no cPanel = a pasta acima deste script (vale para qualquer nome de pasta)
    local REPO; REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
    local BRANCH="main"
    local LOG_DIR="$HOME/logs"
    local LOG="$LOG_DIR/estoque-deploy.log"
    local LOCK="$HOME/.estoque-deploy.lock"

    mkdir -p "$LOG_DIR"
    log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

    # Evita duas publicações ao mesmo tempo
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$LOCK"
        flock -n 9 || return 0
    fi

    cd "$REPO" || { log "ERRO: pasta $REPO não encontrada"; return 1; }

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

    # 1ª opção: a própria implantação do cPanel (aparece no histórico do Git Version Control)
    local UAPI
    UAPI=$(command -v uapi || echo /usr/local/cpanel/bin/uapi)
    if [ -x "$UAPI" ] && "$UAPI" VersionControlDeployment create repository_root="$REPO" 2>>"$LOG" | grep -q "status: 1"; then
        log "Publicado pelo cPanel (Deploy HEAD Commit)."
        return 0
    fi

    # 2ª opção: executa as mesmas tarefas do .cpanel.yml diretamente
    log "Implantação do cPanel indisponível; executando as tarefas do .cpanel.yml."
    local TASKS
    TASKS=$(sed -n 's/^[[:space:]]*- \(.*\)$/\1/p' .cpanel.yml)
    if (set -e; eval "$TASKS") >>"$LOG" 2>&1; then
        log "Publicado com sucesso."
    else
        log "ERRO: falha ao copiar os arquivos para o site"
        return 1
    fi
}

# Tarefas agendadas do sistema (backup automático no Google Drive): roda a cada chamada,
# mesmo sem versão nova. O próprio sistema decide se chegou a hora.
tarefas() {
    local REPO LOG="$HOME/logs/estoque-deploy.log" SITE PHP
    REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
    SITE=$(sed -n 's/^[[:space:]]*- export DEPLOYPATH=//p' "$REPO/.cpanel.yml" 2>/dev/null | head -1)
    SITE=$(eval echo "$SITE")
    [ -n "$SITE" ] && [ -f "${SITE%/}/app/cron.php" ] && [ -f "${SITE%/}/app/config.php" ] || return 0
    PHP=$(command -v php || echo /usr/local/bin/php)
    [ -x "$PHP" ] || return 0
    "$PHP" "${SITE%/}/app/cron.php" >> "$LOG" 2>&1
}

main "$@"
rc=$?
tarefas
exit $rc
