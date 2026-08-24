#!/bin/bash
# ============================================================
#  Instalador — Módulo Plantonistas v4.1
#  Suporta: Docker · All-in-One · Instalação Segmentada
#  Bancos:  MySQL/MariaDB · PostgreSQL (ver CLAUDE.md — suporte
#           a PostgreSQL está no código, mas ainda não foi
#           homologado em produção; use com cautela)
#  Cron:    /etc/cron.d · crontab de usuário · systemd timer
#           (Amazon Linux 2023 e afins não têm /etc/cron.d)
# ============================================================

set -euo pipefail

# ── Cores ──────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

MODULE_NAME="module-zbx-plantonistas"
MODULE_SRC="$(cd "$(dirname "$0")/.." && pwd)"
MIN_ZABBIX="6.4"

# ── Helpers ────────────────────────────────────────────────
info()    { echo -e "${BLUE}ℹ${NC}  $*"; }
ok()      { echo -e "${GREEN}✔${NC}  $*"; }
warn()    { echo -e "${YELLOW}⚠${NC}  $*"; }
err()     { echo -e "${RED}✖${NC}  $*"; }
step()    { echo -e "\n${BOLD}${CYAN}[$1]${NC} $2"; }
die()     { err "$*"; exit 1; }
ask()     { local __v; read -rp "$(echo -e "  ${YELLOW}?${NC} $1: ")" __v; echo "${__v:-${2:-}}"; }
confirm() { local r; read -rp "$(echo -e "  ${YELLOW}?${NC} $1 [s/N]: ")" r; [[ "${r,,}" == "s" ]]; }

banner() {
    echo -e "${CYAN}"
    echo "  ╔═══════════════════════════════════════════════════╗"
    echo "  ║        Módulo Plantonistas — Instalador           ║"
    echo "  ║              v4.1.0 · Zabbix 7.0 LTS              ║"
    echo "  ╚═══════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# ── Verifica pré-requisitos do host ────────────────────────
check_deps() {
    local missing=()
    for cmd in curl; do
        command -v "$cmd" &>/dev/null || missing+=("$cmd")
    done
    command -v mysql &>/dev/null || command -v psql &>/dev/null \
        || warn "Nem 'mysql' nem 'psql' encontrados no host — a instalação do schema via este script não vai funcionar (o módulo ainda cria as tabelas sozinho ao ser habilitado, se preferir pular esta etapa)."
    if [[ ${#missing[@]} -gt 0 ]]; then
        warn "Pacotes não encontrados no host: ${missing[*]}"
        warn "Alguns modos de instalação podem não funcionar."
    fi
}

# ══════════════════════════════════════════════════════════
#  Detecção do frontend (index.php) e do banco de dados
# ══════════════════════════════════════════════════════════

# Zabbix 7.0 empacotado oficialmente (RHEL/Debian) move o frontend para
# <raiz>/ui/ (index.php em /usr/share/zabbix/ui/index.php). Em boa parte das
# instalações reais (inclusive as que originaram este módulo) o vhost aponta
# direto para /usr/share/zabbix, sem o "ui" no meio — por isso ESSE caminho é
# testado primeiro. Onde quer que o index.php esteja, "modules/" é sempre
# IRMÃO dele (nunca fixo em /usr/share/zabbix/modules nem em .../ui/modules):
# é o Zabbix quem resolve a pasta de módulos relativa ao document root.
detect_frontend_dir() {
    local candidates=("/usr/share/zabbix" "/usr/share/zabbix/ui")
    local c
    for c in "${candidates[@]}"; do
        [[ -f "${c}/index.php" ]] && { echo "$c"; return 0; }
    done
    return 1
}

# Tenta ler $DB['TYPE'] do zabbix.conf.php (MYSQL ou POSTGRESQL) para sugerir
# o valor certo no prompt, sem obrigar o operador a saber de cor.
detect_db_type() {
    local frontend_dir="${1:-}"
    [[ -z "$frontend_dir" ]] && { echo ""; return; }
    local conf=""
    local candidate
    for candidate in "${frontend_dir}/conf/zabbix.conf.php" "$(dirname "$frontend_dir")/conf/zabbix.conf.php"; do
        [[ -f "$candidate" ]] && { conf="$candidate"; break; }
    done
    if [[ -z "$conf" ]]; then
        echo ""
        return
    fi
    if grep -qi "'TYPE'\s*=>\s*'POSTGRESQL'" "$conf" 2>/dev/null; then
        echo "pgsql"
    elif grep -qi "'TYPE'\s*=>\s*'MYSQL'" "$conf" 2>/dev/null; then
        echo "mysql"
    else
        echo ""
    fi
}

# Normaliza a resposta do operador (mysql/mariadb/pgsql/postgres/postgresql) → mysql|pgsql
ask_db_type() {
    local detected="$1"
    local raw
    raw=$(ask "Tipo de banco (mysql/pgsql)" "${detected:-mysql}")
    case "${raw,,}" in
        pg|pgsql|postgres|postgresql) echo "pgsql" ;;
        *)                            echo "mysql" ;;
    esac
}

db_default_port() { [[ "$1" == "pgsql" ]] && echo "5432" || echo "3306"; }

db_client_ok() {
    # Não usar "&&"/"||" encadeados aqui: com db_type=pgsql e psql ausente,
    # esse encadeamento cai pro teste do mysql e pode devolver "OK" errado
    # (mysql instalado, psql não — exatamente o cliente que falta).
    if [[ "$1" == "pgsql" ]]; then
        command -v psql &>/dev/null
    else
        command -v mysql &>/dev/null
    fi
}

# db_test_conn <dbtype> <host> <port> <user> <pass> <name>
db_test_conn() {
    local dbtype="$1" host="$2" port="$3" user="$4" pass="$5" name="$6"
    if [[ "$dbtype" == "pgsql" ]]; then
        PGPASSWORD="$pass" psql -h "$host" -p "$port" -U "$user" -d "$name" -c "SELECT 1;" &>/dev/null
    else
        mysql -h "$host" -P "$port" -u "$user" -p"$pass" "$name" -e "SELECT 1;" &>/dev/null
    fi
}

# db_run_file <dbtype> <host> <port> <user> <pass> <name> <arquivo.sql>
db_run_file() {
    local dbtype="$1" host="$2" port="$3" user="$4" pass="$5" name="$6" file="$7"
    if [[ "$dbtype" == "pgsql" ]]; then
        PGPASSWORD="$pass" psql -h "$host" -p "$port" -U "$user" -d "$name" -v ON_ERROR_STOP=1 -f "$file"
    else
        mysql -h "$host" -P "$port" -u "$user" -p"$pass" "$name" < "$file"
    fi
}

# Caminho do schema.<banco>.sql dentro do repo, e aviso de PG não homologado.
db_schema_file() {
    local dbtype="$1"
    echo "${MODULE_SRC}/sql/schema.${dbtype}.sql"
}

warn_pgsql_not_homologated() {
    warn "Suporte a PostgreSQL está implementado no código (ZbxDb/SqlFn/Schema.php)"
    warn "mas ainda NÃO foi validado contra um PostgreSQL real (ver CLAUDE.md/ROADMAP.md)."
    warn "Prossiga com cautela fora de ambiente de laboratório."
}

# ══════════════════════════════════════════════════════════
#  Agendamento de cron — com fallback pra hosts sem /etc/cron.d
#  (Amazon Linux 2023 e derivados removeram o pacote cronie por
#  padrão; a recomendação da AWS ali é systemd timer)
# ══════════════════════════════════════════════════════════

# build_env_block <dbtype> <host> <port> <name> <user> <pass> [extra KEY=VALUE por linha...]
build_env_block() {
    local dbtype="$1" host="$2" port="$3" name="$4" user="$5" pass="$6"
    shift 6
    echo "DB_TYPE=${dbtype}"
    echo "DB_HOST=${host}"
    echo "DB_PORT=${port}"
    echo "DB_NAME=${name}"
    echo "DB_USER=${user}"
    echo "DB_PASS=${pass}"
    local extra
    for extra in "$@"; do
        echo "$extra"
    done
}

# install_scheduled_job <job_name> <intervalo:1m|5m> <run_user> <php_bin> <script_path> <env_block(multilinha)> <log_file>
#
# Tenta, nesta ordem: /etc/cron.d (padrão em RHEL/CentOS/Ubuntu) → crontab do
# usuário do serviço (hosts com cronie mas sem cron.d) → unidade systemd timer
# (fallback para Amazon Linux 2023 e qualquer host sem cron instalado).
install_scheduled_job() {
    local job_name="$1" interval="$2" run_user="$3" php_bin="$4" script_path="$5" env_block="$6" log_file="$7"
    local cron_expr
    case "$interval" in
        1m) cron_expr="* * * * *" ;;
        *)  cron_expr="*/5 * * * *" ;;
    esac

    if [[ -d /etc/cron.d ]]; then
        local cron_file="/etc/cron.d/${job_name}"
        {
            echo "$env_block"
            echo "${cron_expr} ${run_user} ${php_bin} ${script_path} >> ${log_file} 2>&1"
        } > "$cron_file"
        chmod 600 "$cron_file"   # tem senha/token — nunca 644
        ok "Cron configurado em ${cron_file} (chmod 600)"
        return 0
    fi

    if command -v crontab &>/dev/null; then
        warn "/etc/cron.d não existe neste host — usando o crontab do usuário '${run_user}' como alternativa."
        local env_inline="" line
        while IFS= read -r line; do
            [[ -z "$line" ]] && continue
            env_inline+="${line} "
        done <<< "$env_block"
        local existing
        existing=$(crontab -u "$run_user" -l 2>/dev/null || true)
        {
            [[ -n "$existing" ]] && echo "$existing"
            echo "${cron_expr} ${env_inline}${php_bin} ${script_path} >> ${log_file} 2>&1"
        } | crontab -u "$run_user" -
        ok "Job adicionado ao crontab de '${run_user}' — conferir com: crontab -u ${run_user} -l"
        return 0
    fi

    if command -v systemctl &>/dev/null; then
        warn "Nem /etc/cron.d nem 'crontab' disponíveis — comum no Amazon Linux 2023, que"
        warn "substituiu o pacote cronie por systemd timers. Gerando unidade systemd..."
        local svc="/etc/systemd/system/${job_name}.service"
        local timer="/etc/systemd/system/${job_name}.timer"
        local oncalendar
        case "$interval" in
            1m) oncalendar="*:0/1" ;;
            *)  oncalendar="*:0/5" ;;
        esac

        {
            echo "[Unit]"
            echo "Description=Plantonistas - ${job_name}"
            echo ""
            echo "[Service]"
            echo "Type=oneshot"
            echo "User=${run_user}"
            local line
            while IFS= read -r line; do
                [[ -z "$line" ]] && continue
                echo "Environment=\"${line}\""
            done <<< "$env_block"
            echo "ExecStart=${php_bin} ${script_path}"
            echo "StandardOutput=append:${log_file}"
            echo "StandardError=append:${log_file}"
        } > "$svc"

        {
            echo "[Unit]"
            echo "Description=Timer - ${job_name}"
            echo ""
            echo "[Timer]"
            echo "OnCalendar=${oncalendar}"
            echo "Persistent=true"
            echo ""
            echo "[Install]"
            echo "WantedBy=timers.target"
        } > "$timer"

        chmod 600 "$svc"    # Environment= carrega senha/token, igual ao cron.d
        chmod 644 "$timer"
        systemctl daemon-reload
        systemctl enable --now "${job_name}.timer"
        ok "Timer systemd ativo: ${job_name}.timer — logs em: journalctl -u ${job_name}.service"
        return 0
    fi

    err "Não foi possível agendar '${job_name}': nem cron nem systemd disponíveis neste host."
    warn "Configure manualmente depois (ver README.md, seção de crons)."
    return 1
}

# setup_crons_interactive <target_dir> <run_user> <dbtype> <host> <port> <name> <user> <pass>
# Oferece os três crons do módulo (presença, escalonamento, menções), cada um opcional.
setup_crons_interactive() {
    local target="$1" run_user="$2" dbtype="$3" host="$4" port="$5" name="$6" dbuser="$7" pass="$8"
    local php_bin
    php_bin=$(command -v php || echo "/usr/bin/php")
    local base_env
    base_env=$(build_env_block "$dbtype" "$host" "$port" "$name" "$dbuser" "$pass")

    if confirm "Configurar o cron de presença (module_plantonistas_user_sessions, a cada 5 min)?"; then
        install_scheduled_job "plantonistas-presence" "5m" "$run_user" "$php_bin" \
            "${target}/scripts/cron_presence_tracker.php" "$base_env" "/var/log/plantonistas-presence.log" \
            || warn "Rode em UM frontend só — o script grava no banco compartilhado."
    fi

    if confirm "Configurar o cron de escalonamento (sincroniza grupo 'Plantonista de Hoje - <equipe>', a cada 5 min)?"; then
        local prefix
        prefix=$(ask "Prefixo do grupo de escalonamento" "Plantonista de Hoje")
        local oncall_env
        oncall_env=$(build_env_block "$dbtype" "$host" "$port" "$name" "$dbuser" "$pass" "ONCALL_GROUP_PREFIX=${prefix}")
        install_scheduled_job "plantonistas-oncall" "5m" "$run_user" "$php_bin" \
            "${target}/scripts/cron_sync_oncall.php" "$oncall_env" "/var/log/plantonistas-oncall.log"
        info "Crie os grupos vazios (Status = Habilitado) em Usuários → Grupos de utilizadores antes de valer — o script nunca cria grupo."
        info "Teste sem gravar: DRY_RUN=1 ${php_bin} ${target}/scripts/cron_sync_oncall.php"
    fi

    if confirm "Configurar o cron de notificação de menções (Diário de Bordo, a cada 1 min — opcional)?"; then
        info "Exige um token de API de um usuário Super Admin (Usuários → Tokens de API)."
        local token zbx_server zbx_port frontend_url
        token=$(ask "PLANTONISTAS_ALERT_TOKEN (token de API, Super Admin)" "")
        if [[ -z "$token" ]]; then
            warn "Sem token, este cron não notifica nada (e não quebra) — pulando."
        else
            zbx_server=$(ask "IP/host do Zabbix server (ZBX_SERVER)" "127.0.0.1")
            zbx_port=$(ask "Porta do Zabbix server (ZBX_SERVER_PORT)" "10051")
            frontend_url=$(ask "URL pública do frontend (ZBX_FRONTEND_URL, opcional — sem ela o link do e-mail vai relativo)" "")
            local mentions_env
            mentions_env=$(build_env_block "$dbtype" "$host" "$port" "$name" "$dbuser" "$pass" \
                "PLANTONISTAS_ALERT_TOKEN=${token}" "ZBX_SERVER=${zbx_server}" "ZBX_SERVER_PORT=${zbx_port}" "ZBX_FRONTEND_URL=${frontend_url}")
            warn "Abra o módulo no navegador PELO MENOS UMA VEZ antes deste cron rodar (a coluna 'notified_at' só é criada no primeiro request com o módulo habilitado)."
            install_scheduled_job "plantonistas-mentions" "1m" "$run_user" "$php_bin" \
                "${target}/scripts/cron_notify_mentions.php" "$mentions_env" "/var/log/plantonistas-mentions.log"
        fi
    fi
}

# ══════════════════════════════════════════════════════════
#  MODO 1 — DOCKER
# ══════════════════════════════════════════════════════════
install_docker() {
    step "Docker" "Instalação em ambiente Docker / Docker Compose"

    local web_container db_container
    web_container=$(ask "Nome do container Zabbix Web" "zabbix-web")
    docker inspect "$web_container" &>/dev/null || die "Container '$web_container' não encontrado. Verifique com: docker ps"

    db_container=$(ask "Nome do container do banco (MariaDB/MySQL ou PostgreSQL)" "zabbix-mariadb")
    docker inspect "$db_container" &>/dev/null || die "Container '$db_container' não encontrado."

    local db_type db_host db_port db_user db_pass db_name
    db_type=$(ask_db_type "")
    [[ "$db_type" == "pgsql" ]] && warn_pgsql_not_homologated
    db_host=$(ask "Host do banco (dentro da rede docker)" "$db_container")
    db_port=$(ask "Porta do banco" "$(db_default_port "$db_type")")
    db_user=$(ask "Usuário do banco" "zabbix")
    read -rsp "  ${YELLOW}?${NC} Senha do banco: " db_pass; echo
    db_name=$(ask "Nome do banco" "zabbix")

    step "1/3" "Copiando módulo para o container..."
    local target="/usr/share/zabbix/modules/${MODULE_NAME}"
    docker exec "$web_container" bash -c "rm -rf ${target} && mkdir -p /usr/share/zabbix/modules"
    docker cp "${MODULE_SRC}/." "${web_container}:${target}"
    docker exec --user root "$web_container" bash -c "chown -R www-data:www-data ${target} && chmod -R 755 ${target}" 2>/dev/null || true
    ok "Módulo copiado para ${target}"

    step "2/3" "Criando tabelas no banco de dados..."
    if confirm "Banco NOVO, sem os módulos antigos (plantao/turnos)? Rodar sql/schema.${db_type}.sql"; then
        local schema_file
        schema_file=$(db_schema_file "$db_type")
        if [[ "$db_type" == "pgsql" ]]; then
            docker exec -e PGPASSWORD="$db_pass" -i "$db_container" psql -U "$db_user" -d "$db_name" -v ON_ERROR_STOP=1 < "$schema_file" \
                || die "Falha ao executar schema.pgsql.sql. Verifique as credenciais."
        else
            docker exec -i "$db_container" mysql -u"$db_user" -p"$db_pass" "$db_name" < "$schema_file" \
                || die "Falha ao executar schema.mysql.sql. Verifique as credenciais."
        fi
        ok "Tabelas criadas com sucesso"
    else
        info "Pulado — migração de módulos antigos: o módulo renomeia as tabelas sozinho ao ser habilitado."
    fi

    step "3/3" "Configurando cron de presença dentro do container (opcional)..."
    if confirm "Deseja configurar o cron de presença dentro do container?"; then
        local base_env
        base_env=$(build_env_block "$db_type" "$db_host" "$db_port" "$db_name" "$db_user" "$db_pass")
        local cron_line="${base_env//$'\n'/ }"
        cron_line="*/5 * * * * ${cron_line} php ${target}/scripts/cron_presence_tracker.php >> /var/log/zabbix_presence.log 2>&1"
        docker exec "$web_container" bash -c "echo '${cron_line}' | crontab -" 2>/dev/null \
            && ok "Cron configurado no container" \
            || warn "Container sem cron (comum em imagens Alpine mínimas). Configure manualmente ou rode o script via um sidecar/systemd timer no host."
    fi

    finish_message
}

# ══════════════════════════════════════════════════════════
#  MODO 2 — ALL-IN-ONE (tudo na mesma VM)
# ══════════════════════════════════════════════════════════
install_allinone() {
    step "All-in-One" "Instalação em servidor único (Zabbix + DB + Web na mesma VM)"
    [[ $EUID -ne 0 ]] && die "Este modo requer execução como root (sudo ./install.sh)"

    # Localizar frontend — checa /usr/share/zabbix e /usr/share/zabbix/ui
    # (Zabbix 7.0 empacotado oficialmente usa o segundo; boa parte das
    # instalações reais usa o primeiro). "modules/" é sempre irmão do index.php.
    local zabbix_dir
    zabbix_dir=$(detect_frontend_dir) || zabbix_dir=$(ask "Caminho do Zabbix frontend (pasta com o index.php)" "/usr/share/zabbix")
    [[ ! -f "${zabbix_dir}/index.php" ]] && die "'${zabbix_dir}' não é um frontend Zabbix válido (index.php não encontrado)."
    ok "Frontend detectado em ${zabbix_dir}"

    local db_type_detected
    db_type_detected=$(detect_db_type "$zabbix_dir")
    [[ -n "$db_type_detected" ]] && info "Tipo de banco detectado em zabbix.conf.php: ${db_type_detected}"

    local db_type db_host db_port db_user db_pass db_name
    db_type=$(ask_db_type "$db_type_detected")
    [[ "$db_type" == "pgsql" ]] && warn_pgsql_not_homologated
    db_client_ok "$db_type" || die "Cliente de banco necessário não encontrado no host ($( [[ "$db_type" == "pgsql" ]] && echo psql || echo mysql ))."
    db_host=$(ask "Host do banco" "localhost")
    db_port=$(ask "Porta do banco" "$(db_default_port "$db_type")")
    db_user=$(ask "Usuário do banco" "zabbix")
    read -rsp "  ${YELLOW}?${NC} Senha do banco: " db_pass; echo
    db_name=$(ask "Nome do banco" "zabbix")

    step "1/4" "Copiando módulo..."
    local target="${zabbix_dir}/modules/${MODULE_NAME}"
    [[ -d "$target" ]] && { warn "Módulo já existe. Atualizando..."; rm -rf "$target"; }
    cp -r "${MODULE_SRC}" "$target"

    local web_user="www-data"
    id apache &>/dev/null && web_user="apache"
    id nginx  &>/dev/null && web_user="nginx"
    chown -R "${web_user}:${web_user}" "$target"
    chmod -R 755 "$target"
    ok "Módulo copiado para ${target} (owner: ${web_user})"

    step "2/4" "Criando tabelas no banco de dados..."
    if confirm "Banco NOVO, sem os módulos antigos (plantao/turnos)? Rodar sql/schema.${db_type}.sql"; then
        db_test_conn "$db_type" "$db_host" "$db_port" "$db_user" "$db_pass" "$db_name" \
            || die "Não foi possível conectar ao banco ${db_host}:${db_port}. Verifique credenciais."
        db_run_file "$db_type" "$db_host" "$db_port" "$db_user" "$db_pass" "$db_name" "$(db_schema_file "$db_type")" \
            || die "Falha ao executar sql/schema.${db_type}.sql."
        ok "Tabelas criadas"
    else
        info "Pulado — migração de módulos antigos: o módulo renomeia as tabelas sozinho ao ser habilitado."
    fi

    step "3/4" "Configurando crons (opcional)..."
    setup_crons_interactive "$target" "$web_user" "$db_type" "$db_host" "$db_port" "$db_name" "$db_user" "$db_pass"

    step "4/4" "Verificando permissões finais..."
    [[ -r "${target}/manifest.json" ]] && ok "manifest.json acessível" || warn "Verifique permissões em ${target}"

    finish_message
}

# ══════════════════════════════════════════════════════════
#  MODO 3 — SEGMENTADO (web e DB em servidores distintos)
# ══════════════════════════════════════════════════════════
install_segmented() {
    step "Segmentado" "Web e banco em servidores separados"
    [[ $EUID -ne 0 ]] && die "Este modo requer execução como root (sudo ./install.sh)"

    info "Execute este script no servidor do Zabbix Web."
    info "Você precisará acessar o banco de dados remotamente."

    local zabbix_dir
    zabbix_dir=$(detect_frontend_dir) || zabbix_dir=$(ask "Caminho do Zabbix frontend (pasta com o index.php)" "/usr/share/zabbix")
    [[ ! -f "${zabbix_dir}/index.php" ]] && die "'${zabbix_dir}' não é um frontend Zabbix válido (index.php não encontrado)."
    ok "Frontend detectado em ${zabbix_dir}"

    local db_type_detected
    db_type_detected=$(detect_db_type "$zabbix_dir")
    [[ -n "$db_type_detected" ]] && info "Tipo de banco detectado em zabbix.conf.php: ${db_type_detected}"

    local db_type db_host db_port db_user db_pass db_name
    db_type=$(ask_db_type "$db_type_detected")
    [[ "$db_type" == "pgsql" ]] && warn_pgsql_not_homologated
    db_client_ok "$db_type" || die "Cliente de banco necessário não encontrado no host ($( [[ "$db_type" == "pgsql" ]] && echo psql || echo mysql ))."
    db_host=$(ask "Host do banco de dados (IP ou hostname)" "192.168.1.100")
    db_port=$(ask "Porta do banco" "$(db_default_port "$db_type")")
    db_user=$(ask "Usuário do banco" "zabbix")
    read -rsp "  ${YELLOW}?${NC} Senha do banco: " db_pass; echo
    db_name=$(ask "Nome do banco" "zabbix")

    step "1/4" "Testando conectividade com o banco remoto..."
    db_test_conn "$db_type" "$db_host" "$db_port" "$db_user" "$db_pass" "$db_name" \
        || die "Não foi possível conectar ao banco ${db_host}:${db_port}. Verifique credenciais e firewall."
    ok "Conexão com banco remoto OK"

    step "2/4" "Copiando módulo para o servidor web..."
    local target="${zabbix_dir}/modules/${MODULE_NAME}"
    [[ -d "$target" ]] && { warn "Atualizando instalação existente..."; rm -rf "$target"; }
    cp -r "${MODULE_SRC}" "$target"

    local web_user="www-data"
    id apache &>/dev/null && web_user="apache"
    id nginx  &>/dev/null && web_user="nginx"
    chown -R "${web_user}:${web_user}" "$target"
    chmod -R 755 "$target"
    ok "Módulo copiado (owner: ${web_user})"

    step "3/4" "Criando tabelas no banco remoto..."
    if confirm "Banco NOVO, sem os módulos antigos (plantao/turnos)? Rodar sql/schema.${db_type}.sql"; then
        db_run_file "$db_type" "$db_host" "$db_port" "$db_user" "$db_pass" "$db_name" "$(db_schema_file "$db_type")" \
            || die "Falha ao executar sql/schema.${db_type}.sql."
        ok "Tabelas criadas no banco remoto"
    else
        info "Pulado — migração de módulos antigos: o módulo renomeia as tabelas sozinho ao ser habilitado."
    fi

    step "4/4" "Crons (opcional)..."
    setup_crons_interactive "$target" "$web_user" "$db_type" "$db_host" "$db_port" "$db_name" "$db_user" "$db_pass"

    finish_message
}

# ── Mensagem final ─────────────────────────────────────────
finish_message() {
    echo -e "\n${GREEN}${BOLD}══════════════════════════════════════════════${NC}"
    echo -e "${GREEN}${BOLD}  ✔  Instalação concluída com sucesso!         ${NC}"
    echo -e "${GREEN}${BOLD}══════════════════════════════════════════════${NC}"
    echo ""
    echo -e "${BOLD}Próximos passos no Zabbix:${NC}"
    echo "  1. Acesse: Administration → General → Modules"
    echo "  2. Clique em  \"Scan directory\""
    echo "  3. DESABILITE os módulos antigos (Escala de Plantão / Relatório Repasse de Plantão), se existirem"
    echo "  4. Habilite   \"Plantonistas\" — na primeira carga ele renomeia as tabelas antigas automaticamente"
    echo "  5. Acesse:    Plantão → Visão Geral / Escala / Repasse Plantão"
    echo ""
}

# ══════════════════════════════════════════════════════════
#  MENU PRINCIPAL
# ══════════════════════════════════════════════════════════
banner
check_deps

echo -e "${BOLD}Selecione o modo de instalação:${NC}\n"
echo "  1)  Docker / Docker Compose"
echo "  2)  All-in-One  (Zabbix + DB + Web na mesma VM)"
echo "  3)  Segmentado  (Web e banco em servidores distintos)"
echo ""

MODO=$(ask "Opção" "1")

case "$MODO" in
    1) install_docker      ;;
    2) install_allinone    ;;
    3) install_segmented   ;;
    *) die "Opção inválida: $MODO" ;;
esac
