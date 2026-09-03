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
# Caminho do zabbix.conf.php. Nos pacotes RHEL/Amazon o arquivo REAL mora em
# /etc/zabbix/web/ e o frontend costuma chegar nele por symlink em conf/ — mas
# nem sempre: em host onde o symlink não existe, procurar só ao lado do
# index.php não acha nada, e o instalador passava a perguntar dados que já
# estavam no disco.
find_zbx_conf() {
    local frontend_dir="${1:-}"
    local candidate
    for candidate in "${ZBX_CONF:-}" \
                     "${frontend_dir}/conf/zabbix.conf.php" \
                     "$(dirname "${frontend_dir:-/}")/conf/zabbix.conf.php" \
                     "/etc/zabbix/web/zabbix.conf.php" \
                     "/usr/share/zabbix/conf/zabbix.conf.php" \
                     "/usr/share/zabbix/ui/conf/zabbix.conf.php"; do
        [[ -n "$candidate" && -f "$candidate" ]] && { echo "$candidate"; return 0; }
    done

    # Última tentativa: procurar de verdade, com profundidade curta. Instalação
    # fora do padrão existe, e é melhor achar do que mandar o operador digitar
    # host, base, usuário e senha de novo.
    local found
    found=$(find /etc/zabbix "${frontend_dir:-/usr/share/zabbix}" -maxdepth 3 -name zabbix.conf.php -type f 2>/dev/null | head -1)
    [[ -n "$found" ]] && { echo "$found"; return 0; }

    echo ""
    return 1
}

# Lê um $DB['CHAVE'] do zabbix.conf.php por TEXTO. É a reserva do
# read_zbx_conf_all() — aceita aspa simples e dupla, mas não entende constante,
# concatenação nem include.
read_zbx_conf_value() {
    local conf="$1" key="$2"
    [[ -f "$conf" ]] || { echo ""; return; }
    sed -nE "s/^[[:space:]]*\\\$DB\\['?\"?${key}'?\"?\\][[:space:]]*=[[:space:]]*['\"]([^'\"]*)['\"].*/\\1/p" "$conf" | head -1
}

# Lê as SEIS chaves do zabbix.conf.php de uma vez, uma por linha, na ordem
# TYPE SERVER PORT DATABASE USER PASSWORD.
#
# Quem lê é o PRÓPRIO PHP, e não um sed: o arquivo é código PHP, e o valor pode
# estar em aspas duplas, vir de constante, de concatenação ou de um include.
# Foi assim que a primeira versão falhou em produção enquanto funcionava em
# homologação — o parser de texto entendia um formato só. O sed continua como
# reserva, para host sem PHP na linha de comando (que existe: o frontend pode
# estar em outro nó).
read_zbx_conf_all() {
    local conf="$1"
    [[ -f "$conf" ]] || return 1

    if command -v php &>/dev/null; then
        php -r '
            $DB = [];
            include $argv[1];
            foreach (["TYPE","SERVER","PORT","DATABASE","USER","PASSWORD"] as $k) {
                echo str_replace(["\r","\n"], "", (string)($DB[$k] ?? "")), "\n";
            }
        ' "$conf" 2>/dev/null && return 0
    fi

    local k
    for k in TYPE SERVER PORT DATABASE USER PASSWORD; do
        read_zbx_conf_value "$conf" "$k"
    done
}

# Lê uma diretiva do zabbix_server.conf (DBHost=..., DBName=...). Segunda fonte:
# em host que roda o server, esses valores existem mesmo quando o frontend está
# noutro lugar. Ignora linha comentada.
read_server_conf_value() {
    local conf="$1" key="$2"
    [[ -f "$conf" && -r "$conf" ]] || { echo ""; return; }
    sed -nE "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*(.*)$/\\1/p" "$conf" | tail -1 | sed -E 's/[[:space:]]+$//'
}

# Lê uma env de um agendamento do módulo JÁ instalado (cron.d ou unidade
# systemd). Terceira fonte, e a mais específica que existe: são exatamente os
# valores com que o coletor está rodando hoje.
read_job_env_value() {
    local key="$1" f
    for f in /etc/cron.d/plantonistas-* /etc/systemd/system/plantonistas-*.service; do
        [[ -f "$f" ]] || continue
        local v
        v=$(sed -nE "s/^(Environment=\")?${key}=([^\"]*)\"?.*/\\2/p" "$f" | head -1)
        [[ -n "$v" ]] && { echo "$v"; return; }
    done
    echo ""
}

# Primeiro valor não vazio da lista.
pick_first() {
    local v
    for v in "$@"; do
        [[ -n "$v" ]] && { echo "$v"; return; }
    done
    echo ""
}

detect_db_type() {
    local frontend_dir="${1:-}"
    [[ -z "$frontend_dir" ]] && { echo ""; return; }
    local conf
    conf=$(find_zbx_conf "$frontend_dir")
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
#  MODO 4 — SOMENTE OS SERVIÇOS, SEM PERGUNTAS
# ══════════════════════════════════════════════════════════
#
# Por que este modo existe: habilitar o módulo pela tela do Zabbix cria as
# TABELAS (o init() do módulo faz a migração), mas não cria — e não pode criar —
# os serviços que rodam fora do frontend. O módulo roda dentro do PHP-FPM, como
# o usuário do servidor web: não tem root para escrever em /etc/systemd/system,
# nem para instalar pacote, nem para dar systemctl daemon-reload. E não é só
# limitação: um módulo de frontend capaz de escrever unidade systemd daria, a
# quem consegue habilitar módulo na UI, execução de código como root no host.
#
# Então a parte de root continua sendo um comando de root — só que agora um só,
# sem menu e sem redigitar senha de banco:
#
#     sudo ./scripts/install.sh --services
#
# Idempotente: rodar de novo reescreve as unidades/cron com os mesmos valores.
# Descobre a configuração do banco SEM perguntar nada, olhando, em ordem, os
# lugares onde ela já existe no host. Preenche CAMPO A CAMPO: se o frontend tem
# tudo menos a senha e o zabbix_server.conf tem a senha, sai completo.
#
# Ordem (o primeiro não vazio vence):
#
#   1. variáveis DB_* já exportadas       — o operador mandou, o operador manda
#   2. zabbix.conf.php do frontend        — a fonte natural
#   3. /etc/zabbix/zabbix_server.conf     — existe em host que roda o server
#   4. agendamento do módulo já instalado — os valores com que ele roda HOJE
#   5. variáveis ZBX_DB_*                 — convenção das imagens de container
#
# Deixa em CFG_TYPE/HOST/PORT/NAME/USER/PASS.
resolve_db_config() {
    local frontend conf srvconf
    frontend="$(detect_frontend_dir || echo '')"
    conf="$(find_zbx_conf "$frontend" || true)"
    srvconf="${ZBX_SERVER_CONF:-/etc/zabbix/zabbix_server.conf}"

    local c_type="" c_host="" c_port="" c_name="" c_user="" c_pass=""
    if [[ -n "$conf" ]]; then
        { read -r c_type; read -r c_host; read -r c_port; read -r c_name; read -r c_user; read -r c_pass; } \
            < <(read_zbx_conf_all "$conf") || true
        ok "Frontend:   ${conf}"
    else
        warn "zabbix.conf.php não encontrado — seguindo com as outras fontes."
    fi
    if [[ -r "$srvconf" ]]; then ok "Server:     ${srvconf}"; fi

    local job_type job_host job_port job_name job_user job_pass
    job_type=$(read_job_env_value DB_TYPE); job_host=$(read_job_env_value DB_HOST)
    job_port=$(read_job_env_value DB_PORT); job_name=$(read_job_env_value DB_NAME)
    job_user=$(read_job_env_value DB_USER); job_pass=$(read_job_env_value DB_PASS)
    if [[ -n "$job_name" ]]; then ok "Agendamento anterior do módulo: reaproveitando o que faltar"; fi

    local raw_type
    raw_type=$(pick_first "${DB_TYPE:-}" "$c_type" "$job_type" "${ZBX_DB_TYPE:-}")
    CFG_HOST=$(pick_first "${DB_HOST:-}" "$c_host" "$(read_server_conf_value "$srvconf" DBHost)" "$job_host" "${ZBX_DB_HOST:-}" "127.0.0.1")
    CFG_PORT=$(pick_first "${DB_PORT:-}" "$c_port" "$(read_server_conf_value "$srvconf" DBPort)" "$job_port" "${ZBX_DB_PORT:-}")
    CFG_NAME=$(pick_first "${DB_NAME:-}" "$c_name" "$(read_server_conf_value "$srvconf" DBName)" "$job_name" "${ZBX_DB_NAME:-}")
    CFG_USER=$(pick_first "${DB_USER:-}" "$c_user" "$(read_server_conf_value "$srvconf" DBUser)" "$job_user" "${ZBX_DB_USER:-}")
    CFG_PASS=$(pick_first "${DB_PASS:-}" "$c_pass" "$(read_server_conf_value "$srvconf" DBPassword)" "$job_pass" "${ZBX_DB_PASS:-}")

    case "${raw_type^^}" in
        POSTGRESQL|PGSQL|POSTGRES) CFG_TYPE="pgsql" ;;
        MYSQL|MARIADB)             CFG_TYPE="mysql" ;;
        "")                        CFG_TYPE="" ;;
        *)                         CFG_TYPE="${raw_type,,}" ;;
    esac

    # Último recurso para o TIPO: o zabbix_server.conf não diz qual é (quem diz
    # é o binário instalado), então, se só um cliente existe no host, esse é o
    # palpite — anunciado, porque palpite errado só aparece depois, no log do
    # coletor.
    if [[ -z "$CFG_TYPE" ]]; then
        if command -v psql &>/dev/null && ! command -v mysql &>/dev/null; then
            CFG_TYPE="pgsql"; warn "DB_TYPE não estava em lugar nenhum; supondo pgsql (só o cliente psql existe aqui)."
        elif command -v mysql &>/dev/null && ! command -v psql &>/dev/null; then
            CFG_TYPE="mysql"; warn "DB_TYPE não estava em lugar nenhum; supondo mysql (só o cliente mysql existe aqui)."
        fi
    fi

    # '0' é como o Zabbix grava "porta padrão" no zabbix.conf.php; passar isso
    # adiante faz o cliente tentar conectar na porta zero.
    if [[ -z "$CFG_PORT" || "$CFG_PORT" == "0" ]]; then CFG_PORT="$(db_default_port "$CFG_TYPE")"; fi

    if [[ -z "$CFG_TYPE" || -z "$CFG_NAME" || -z "$CFG_USER" ]]; then
        err "Não consegui montar a configuração do banco. Faltou:"
        [[ -z "$CFG_TYPE" ]] && err "  DB_TYPE  (mysql ou pgsql)"
        [[ -z "$CFG_NAME" ]] && err "  DB_NAME"
        [[ -z "$CFG_USER" ]] && err "  DB_USER"
        echo ""
        info "Procurei em:"
        info "  variáveis DB_* / ZBX_DB_* do ambiente"
        info "  ${conf:-<zabbix.conf.php não encontrado>}"
        info "  ${srvconf}$([[ -r "$srvconf" ]] || echo ' (ilegível ou inexistente)')"
        info "  /etc/cron.d/plantonistas-* e /etc/systemd/system/plantonistas-*.service"
        echo ""
        info "Se o frontend está noutro caminho, aponte com: ZBX_CONF=/caminho/zabbix.conf.php $0 --services"
        die "Ou preencha na mão: DB_TYPE=… DB_NAME=… DB_USER=… DB_PASS=… $0 --services"
    fi

    ok "Banco:      ${CFG_TYPE} ${CFG_HOST}:${CFG_PORT}/${CFG_NAME} (usuário ${CFG_USER})"

    # `if`, e não `[[ … ]] && warn`: como ESTA é a última linha da função, o
    # status dela vira o status do `resolve_db_config` — e um `&&` cujo teste dá
    # falso devolve 1, o que sob `set -e` mata o script inteiro logo depois de
    # descobrir a configuração, sem imprimir nada. Aconteceu na primeira versão.
    if [[ -z "$CFG_PASS" ]]; then
        warn "Senha vazia — ok se o banco autentica por peer/trust ou .pgpass; senão passe DB_PASS."
    fi

    return 0
}

install_services_only() {
    [[ $EUID -ne 0 ]] && die "Este modo escreve em /etc/systemd/system (ou /etc/cron.d) e exige root: sudo $0 --services"

    step "Serviços" "Agendando os coletores do módulo (sem perguntas)"

    # Raiz do módulo = pasta acima desta. É o caminho REAL de onde o script foi
    # chamado, e não um /usr/share/... fixo: quem serve o módulo por symlink
    # (o caso desta suíte) precisa que o agendamento aponte para o arquivo que
    # existe no disco, não para o link.
    local target
    target="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
    [[ -n "${SERVICES_TARGET:-}" ]] && target="$SERVICES_TARGET"
    [[ -f "${target}/scripts/cron_presence_tracker.php" ]] \
        || die "Não encontrei ${target}/scripts/cron_presence_tracker.php — use SERVICES_TARGET=<dir do módulo> $0 --services"
    ok "Módulo: ${target}"

    resolve_db_config

    # Usuário de execução: mesma ordem que os modos interativos já usam. Só
    # importa para permissão de arquivo — a conexão com o banco vai por
    # DB_USER/DB_PASS, não pelo usuário do sistema. Em host com apache E nginx
    # instalados (é o caso quando um deles ficou de instalação anterior), vale
    # apontar na mão o dono do pool do PHP-FPM.
    local web_user="${SERVICES_USER:-}"
    if [[ -z "$web_user" ]]; then
        web_user="www-data"
        id apache &>/dev/null && web_user="apache"
        id nginx  &>/dev/null && web_user="nginx"
    fi
    id "$web_user" &>/dev/null || die "Usuário '${web_user}' não existe neste host — passe SERVICES_USER=<usuário>."
    ok "Usuário de execução: ${web_user}"

    local php_bin
    php_bin=$(command -v php || echo "/usr/bin/php")

    local base_env
    base_env=$(build_env_block "$CFG_TYPE" "$CFG_HOST" "$CFG_PORT" "$CFG_NAME" "$CFG_USER" "$CFG_PASS")

    # ── Presença: o coletor que alimenta a tabela de presença do Repasse ───
    install_scheduled_job "plantonistas-presence" "5m" "$web_user" "$php_bin" \
        "${target}/scripts/cron_presence_tracker.php" "$base_env" "/var/log/plantonistas-presence.log" \
        || warn "Presença não agendada — veja a mensagem acima."

    # ── Escalonamento: sincroniza o grupo do plantonista do dia ────────────
    local prefix oncall_env
    prefix="${ONCALL_GROUP_PREFIX:-Plantonista de Hoje}"
    oncall_env=$(build_env_block "$CFG_TYPE" "$CFG_HOST" "$CFG_PORT" "$CFG_NAME" "$CFG_USER" "$CFG_PASS" "ONCALL_GROUP_PREFIX=${prefix}")
    install_scheduled_job "plantonistas-oncall" "5m" "$web_user" "$php_bin" \
        "${target}/scripts/cron_sync_oncall.php" "$oncall_env" "/var/log/plantonistas-oncall.log" \
        || warn "Escalonamento não agendado — veja a mensagem acima."

    # ── Menções: só faz sentido com token de API, então é opt-in ───────────
    if [[ -n "${PLANTONISTAS_ALERT_TOKEN:-}" ]]; then
        local mentions_env
        mentions_env=$(build_env_block "$CFG_TYPE" "$CFG_HOST" "$CFG_PORT" "$CFG_NAME" "$CFG_USER" "$CFG_PASS" \
            "PLANTONISTAS_ALERT_TOKEN=${PLANTONISTAS_ALERT_TOKEN}" \
            "ZBX_SERVER=${ZBX_SERVER:-127.0.0.1}" \
            "ZBX_SERVER_PORT=${ZBX_SERVER_PORT:-10051}" \
            "ZBX_FRONTEND_URL=${ZBX_FRONTEND_URL:-}")
        install_scheduled_job "plantonistas-mentions" "1m" "$web_user" "$php_bin" \
            "${target}/scripts/cron_notify_mentions.php" "$mentions_env" "/var/log/plantonistas-mentions.log" \
            || warn "Notificação de menções não agendada — veja a mensagem acima."
    else
        info "Notificação de menções não agendada: exige token de API de Super Admin."
        info "Para incluir: PLANTONISTAS_ALERT_TOKEN=<token> $0 --services"
    fi

    echo ""
    info "RODE EM UM FRONTEND SÓ: os três escrevem no banco compartilhado."
    info "Conferir:  systemctl list-timers | grep plantonistas   (ou: ls -l /etc/cron.d/plantonistas-*)"
    info "Logs:      journalctl -u plantonistas-presence.service -f   ou   /var/log/plantonistas-presence.log"
    echo ""
    ok "Serviços configurados."
}

usage() {
    cat <<'TXT'
Uso:
  sudo ./install.sh                 instalação completa, com menu (Docker / All-in-One / Segmentado)
  sudo ./install.sh --show-config   mostra a configuração de banco que ele descobriu, sem escrever nada
  sudo ./install.sh --services      SÓ os coletores (presença, escalonamento e, com token, menções),
                                    sem perguntas — é o passo que a tela de módulos do Zabbix não faz

Variáveis aceitas pelo --services (todas opcionais; o padrão sai do zabbix.conf.php):
  DB_TYPE DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
  ZBX_CONF                 caminho do zabbix.conf.php, se estiver fora dos lugares conhecidos
  ZBX_SERVER_CONF          caminho do zabbix_server.conf (padrão: /etc/zabbix/zabbix_server.conf)
  SERVICES_TARGET          diretório do módulo, se não for o pai deste script
  SERVICES_USER            usuário que roda os jobs (padrão: usuário do servidor web)
  ONCALL_GROUP_PREFIX      prefixo do grupo de escalonamento (padrão: "Plantonista de Hoje")
  PLANTONISTAS_ALERT_TOKEN token de API; sem ele o cron de menções não é agendado
  ZBX_SERVER ZBX_SERVER_PORT ZBX_FRONTEND_URL
TXT
}

# ══════════════════════════════════════════════════════════
#  MENU PRINCIPAL
# ══════════════════════════════════════════════════════════
#
# `--services` sai ANTES do menu e de check_deps: ele não instala schema nem
# copia arquivo, então não tem por que exigir cliente de banco no host nem
# perguntar modo de instalação.
case "${1:-}" in
    --services|--services-only|--crons)
        banner
        install_services_only
        exit 0
        ;;
    --show-config)
        # Diagnóstico: mostra o que o --services descobriria, sem escrever nada.
        # Existe porque a primeira falha em produção ("faltam dados do banco")
        # não dizia onde ele tinha procurado.
        banner
        step "Config" "Descobrindo a configuração do banco (sem escrever nada)"
        resolve_db_config
        echo ""
        info "DB_TYPE=${CFG_TYPE}  DB_HOST=${CFG_HOST}  DB_PORT=${CFG_PORT}"
        info "DB_NAME=${CFG_NAME}  DB_USER=${CFG_USER}  DB_PASS=$([[ -n "$CFG_PASS" ]] && echo '<definida>' || echo '<vazia>')"
        exit 0
        ;;
    -h|--help)
        usage
        exit 0
        ;;
    "")
        ;;
    *)
        err "Argumento desconhecido: $1"
        echo ""
        usage
        exit 1
        ;;
esac

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
