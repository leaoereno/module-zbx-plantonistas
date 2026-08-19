#!/bin/bash
# ============================================================
#  Instalador — Módulo Plantonistas v4.0
#  Suporta: Docker · All-in-One · Instalação Segmentada
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
    echo "  ║              v4.0.0 · Zabbix 7.0 LTS              ║"
    echo "  ╚═══════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# ── Verifica pré-requisitos do host ────────────────────────
check_deps() {
    local missing=()
    for cmd in mysql curl; do
        command -v "$cmd" &>/dev/null || missing+=("$cmd")
    done
    if [[ ${#missing[@]} -gt 0 ]]; then
        warn "Pacotes não encontrados no host: ${missing[*]}"
        warn "Alguns modos de instalação podem não funcionar."
    fi
}

# ══════════════════════════════════════════════════════════
#  MODO 1 — DOCKER
# ══════════════════════════════════════════════════════════
install_docker() {
    step "Docker" "Instalação em ambiente Docker / Docker Compose"

    # Detectar container web
    local web_container
    web_container=$(ask "Nome do container Zabbix Web" "zabbix-web")
    docker inspect "$web_container" &>/dev/null || die "Container '$web_container' não encontrado. Verifique com: docker ps"

    # Detectar container DB
    local db_container
    db_container=$(ask "Nome do container MariaDB/MySQL" "zabbix-mariadb")
    docker inspect "$db_container" &>/dev/null || die "Container '$db_container' não encontrado."

    local db_user db_pass db_name
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
    if confirm "Banco NOVO, sem os módulos antigos (plantao/turnos)? Rodar schema.mysql.sql"; then
        docker exec -i "$db_container" mysql -u"$db_user" -p"$db_pass" "$db_name" < "${MODULE_SRC}/sql/schema.mysql.sql" \
            || die "Falha ao executar schema.mysql.sql. Verifique as credenciais."
        ok "Tabelas criadas com sucesso"
    else
        info "Pulado — migração de módulos antigos: o módulo renomeia as tabelas sozinho ao ser habilitado."
    fi

    step "3/3" "Configurando cron de presença (opcional)..."
    if confirm "Deseja configurar o cron de presença dentro do container?"; then
        # env inline: crontab de usuário não tem arquivo de ambiente próprio,
        # e sem as DB_* o script cai no default localhost.
        local cron_line="*/5 * * * * DB_HOST=${db_host} DB_NAME=${db_name} DB_USER=${db_user} DB_PASS='${db_pass}' php ${target}/scripts/cron_presence_tracker.php >> /var/log/zabbix_presence.log 2>&1"
        docker exec "$web_container" bash -c "echo '${cron_line}' | crontab -" 2>/dev/null \
            && ok "Cron configurado no container" \
            || warn "Não foi possível configurar o cron automaticamente. Configure manualmente."
    fi

    finish_message
}

# ══════════════════════════════════════════════════════════
#  MODO 2 — ALL-IN-ONE (tudo na mesma VM)
# ══════════════════════════════════════════════════════════
install_allinone() {
    step "All-in-One" "Instalação em servidor único (Zabbix + DB + Web na mesma VM)"
    [[ $EUID -ne 0 ]] && die "Este modo requer execução como root (sudo ./install.sh)"

    # Localizar frontend
    local zabbix_dir="/usr/share/zabbix"
    [[ ! -f "${zabbix_dir}/index.php" ]] && zabbix_dir=$(ask "Caminho do Zabbix frontend" "/usr/share/zabbix")
    [[ ! -f "${zabbix_dir}/index.php" ]] && die "'${zabbix_dir}' não é um frontend Zabbix válido."

    local db_host db_user db_pass db_name
    db_host=$(ask "Host do banco" "localhost")
    db_user=$(ask "Usuário do banco" "zabbix")
    read -rsp "  ${YELLOW}?${NC} Senha do banco: " db_pass; echo
    db_name=$(ask "Nome do banco" "zabbix")

    step "1/4" "Copiando módulo..."
    local target="${zabbix_dir}/modules/${MODULE_NAME}"
    [[ -d "$target" ]] && { warn "Módulo já existe. Atualizando..."; rm -rf "$target"; }
    cp -r "${MODULE_SRC}" "$target"

    # Detectar usuário do web server
    local web_user="www-data"
    id apache &>/dev/null && web_user="apache"
    id nginx  &>/dev/null && web_user="nginx"
    chown -R "${web_user}:${web_user}" "$target"
    chmod -R 755 "$target"
    ok "Módulo copiado para ${target} (owner: ${web_user})"

    step "2/4" "Criando tabelas no banco de dados..."
    if confirm "Banco NOVO, sem os módulos antigos (plantao/turnos)? Rodar schema.mysql.sql"; then
        mysql -h "$db_host" -u "$db_user" -p"$db_pass" "$db_name" < "${MODULE_SRC}/sql/schema.mysql.sql" \
            || die "Falha ao executar schema.mysql.sql."
        ok "Tabelas criadas"
    else
        info "Pulado — migração de módulos antigos: o módulo renomeia as tabelas sozinho ao ser habilitado."
    fi

    step "3/4" "Configurando cron de presença (opcional)..."
    if confirm "Deseja configurar o cron de presença?"; then
        local cron_file="/etc/cron.d/plantonistas-presence"
        # As env DB_* são OBRIGATÓRIAS: o cron roda em CLI, onde não existe
        # $GLOBALS['DB'], e sem elas o script tenta localhost e não acha o
        # banco. Antes este arquivo saía sem env nenhuma e o cron gerado
        # simplesmente não funcionava.
        {
            echo "DB_HOST=${db_host}"
            echo "DB_NAME=${db_name}"
            echo "DB_USER=${db_user}"
            echo "DB_PASS=${db_pass}"
            echo "*/5 * * * * ${web_user} /usr/bin/php ${target}/scripts/cron_presence_tracker.php >> /var/log/zabbix_presence.log 2>&1"
        } > "$cron_file"
        # 600, não 644: o arquivo tem a senha do banco.
        chmod 600 "$cron_file"
        ok "Cron configurado em ${cron_file} (com as credenciais do banco, chmod 600)"
        warn "Rode em UM frontend só — o script grava no banco compartilhado."
    fi

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

    # Frontend
    local zabbix_dir="/usr/share/zabbix"
    [[ ! -f "${zabbix_dir}/index.php" ]] && zabbix_dir=$(ask "Caminho do Zabbix frontend" "/usr/share/zabbix")
    [[ ! -f "${zabbix_dir}/index.php" ]] && die "'${zabbix_dir}' não é um frontend Zabbix válido."

    local db_host db_user db_pass db_name db_port
    db_host=$(ask "Host do banco de dados (IP ou hostname)" "192.168.1.100")
    db_port=$(ask "Porta do banco" "3306")
    db_user=$(ask "Usuário do banco" "zabbix")
    read -rsp "  ${YELLOW}?${NC} Senha do banco: " db_pass; echo
    db_name=$(ask "Nome do banco" "zabbix")

    step "1/4" "Testando conectividade com o banco remoto..."
    mysql -h "$db_host" -P "$db_port" -u "$db_user" -p"$db_pass" "$db_name" -e "SELECT 1;" &>/dev/null \
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
    if confirm "Banco NOVO, sem os módulos antigos (plantao/turnos)? Rodar schema.mysql.sql"; then
        mysql -h "$db_host" -P "$db_port" -u "$db_user" -p"$db_pass" "$db_name" < "${MODULE_SRC}/sql/schema.mysql.sql" \
            || die "Falha ao executar schema.mysql.sql."
        ok "Tabelas criadas no banco remoto"
    else
        info "Pulado — migração de módulos antigos: o módulo renomeia as tabelas sozinho ao ser habilitado."
    fi

    step "4/4" "Cron de presença (opcional)..."
    if confirm "Deseja configurar o cron de presença neste servidor web?"; then
        local cron_file="/etc/cron.d/plantonistas-presence"
        # As env DB_* são OBRIGATÓRIAS: o cron roda em CLI, onde não existe
        # $GLOBALS['DB'], e sem elas o script tenta localhost e não acha o
        # banco. Antes este arquivo saía sem env nenhuma e o cron gerado
        # simplesmente não funcionava.
        {
            echo "DB_HOST=${db_host}"
            echo "DB_NAME=${db_name}"
            echo "DB_USER=${db_user}"
            echo "DB_PASS=${db_pass}"
            echo "*/5 * * * * ${web_user} /usr/bin/php ${target}/scripts/cron_presence_tracker.php >> /var/log/zabbix_presence.log 2>&1"
        } > "$cron_file"
        # 600, não 644: o arquivo tem a senha do banco.
        chmod 600 "$cron_file"
        ok "Cron configurado em ${cron_file} (com as credenciais do banco, chmod 600)"
        warn "Rode em UM frontend só — o script grava no banco compartilhado."
    fi

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

