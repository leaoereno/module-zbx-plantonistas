<?php
/**
 * cron_presence_tracker.php
 *
 * Rastreia a presença de analistas no Zabbix e grava os dados na tabela
 * module_plantonistas_user_sessions.
 *
 * Schema compatível com Zabbix 5.4, 6.x e 7.0+ (backend MySQL/MariaDB).
 * Runtime exige PHP 8.0+ (str_starts_with/str_ends_with).
 *
 * Cron sugerido (a cada 5 min):
 *   0/5 * * * * php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_presence_tracker.php
 *
 * Rodar em UM frontend só: o script escreve no banco compartilhado; em dois
 * nós a escrita duplica sem ganho nenhum.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 *
 * Changelog:
 *   v3.1.0 - Conexão via CliDb (PDO): roda em MySQL/MariaDB e PostgreSQL,
 *            escolhido pela env DB_TYPE (issue #1).
 *   v3.0.0 - API do Zabbix removida (issue #4). Tudo vem do banco em que o
 *            script já estava conectado. Some o TLS sem verificação, o
 *            ZABBIX_API_TOKEN, o ZABBIX_URL e a dependência de curl.
 *            O provisionamento das tabelas passou a viver só no Module.php.
 *   v2.3.0 - Autenticação migrada para token permanente (Bearer Auth)
 */

// ── Só por linha de comando ────────────────────────────────────────────────
//
// A pasta scripts/ mora DENTRO do módulo, e o módulo mora dentro da raiz web
// (o Zabbix serve `modules/` a partir do document root) — ou seja, este arquivo
// tem URL. Sem esta guarda, qualquer um que a acertasse fazia o servidor web
// executar um script que escreve no banco, sem autenticação nenhuma, e ainda
// via na tela o erro de conexão com as pistas de caminho e usuário.
//
// 404 e não 403: quem chuta URL não precisa saber que acertou o nome.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ── Configuração ──────────────────────────────────────────────────────────────

// Em CLI não existe $GLOBALS['DB'] (o frontend do Zabbix não é carregado),
// então as env DB_* do /etc/cron.d são obrigatórias em produção. Sem elas o
// script cai no default localhost e não acha o banco.
define('DB_TYPE',   strtolower((string)($GLOBALS['DB']['TYPE'] ?? getenv('DB_TYPE') ?: 'mysql')));
define('DB_HOST',   $GLOBALS['DB']['SERVER']   ?? getenv('DB_HOST')   ?: 'localhost');
// A porta default acompanha o dialeto: 3306 no MySQL, 5432 no PostgreSQL.
define('DB_PORT',   (int)($GLOBALS['DB']['PORT'] ?? getenv('DB_PORT')
    ?: (in_array(DB_TYPE, ['pgsql', 'postgresql'], true) ? 5432 : 3306)));
define('DB_NAME',   $GLOBALS['DB']['DATABASE'] ?? getenv('DB_NAME')   ?: 'zabbix');
define('DB_USER',   $GLOBALS['DB']['USER']     ?? getenv('DB_USER')   ?: 'zabbix');
define('DB_PASS',   $GLOBALS['DB']['PASSWORD'] ?? getenv('DB_PASS')   ?: '');

// Janela de inatividade: sessão encerrada se lastaccess > N segundos atrás
define('SESSION_TIMEOUT_SEC', 600); // 10 minutos

define('PRESENCE_TABLE', 'module_plantonistas_user_sessions');

// Grupos que nunca servem como contexto de NOC. Os dois primeiros são os
// nomes padrão do Zabbix em inglês; o descarte de verdade é feito por flag
// (users_status / gui_access) na consulta, que funciona em qualquer idioma.
// Esta lista existe só para preservar o comportamento anterior em instalações
// onde o grupo Guests não está marcado como sem acesso ao frontend.
define('SYSTEM_GROUPS', ['Disabled', 'No access to the frontend', 'Guests']);

// Tamanho de module_plantonistas_user_sessions.name no DDL do Module.php.
// users.name e users.surname são VARCHAR(100) cada: concatenados passam de
// 128 e, com sql_mode STRICT, o INSERT lançaria "Data too long" e a linha
// daquele analista seria perdida.
define('NAME_MAXLEN', 128);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/CliDb.php';

date_default_timezone_set('America/Sao_Paulo');

function logMsg(string $msg): void {
    $ts = date('[Y-m-d H:i:s]');
    echo "$ts $msg\n";
}

/**
 * Monta o nome de exibição a partir de users.name + users.surname.
 *
 * Concatenar às cegas duplica nome em cadastros do Zabbix onde o nome
 * completo foi digitado no campo errado. Casos reais em produção:
 *   name='Rafael', surname='Rafael Leao Ereno' → "Rafael Rafael Leao Ereno"
 *   name='Erica',  surname='Erica Felix de Oliveira'
 *
 * Regra: se um campo já contém o outro (nas bordas, comparação sem
 * distinguir maiúscula), usa só o mais completo. Contenção no meio da
 * string NÃO conta — "Ana" em "Mariana Costa" é coincidência de substring,
 * não repetição; por isso a comparação exige limite de palavra (o espaço).
 *
 * `$username` é a reserva quando name e surname estão os dois vazios — conta
 * de serviço ou integração, comum no Zabbix. Sem ela a coluna Nome da tela de
 * Presença sairia em branco, porque a view imprime o campo cru.
 *
 * Espelha TurnosReportBase::formatUserLabel(). A duplicação é proposital:
 * o cron roda em CLI, sem carregar o módulo.
 */
function buildFullName(?string $name, ?string $surname, string $username = ''): string {
    $norm = fn(?string $v) => trim((string)preg_replace('/\s+/', ' ', (string)$v));
    $name    = $norm($name);
    $surname = $norm($surname);

    if ($name === '' && $surname === '') return trim($username);
    if ($name === '')    return $surname;
    if ($surname === '') return $name;

    $ln = mb_strtolower($name);
    $ls = mb_strtolower($surname);

    // surname já traz o nome completo ('Rafael' + 'Rafael Leao Ereno')
    if ($ln === $ls || str_starts_with($ls, $ln . ' ')) {
        return $surname;
    }
    // name já traz o sobrenome ('Rafael Leao Ereno' + 'Ereno')
    if (str_ends_with($ln, ' ' . $ls)) {
        return $name;
    }

    return $name . ' ' . $surname;
}

// ── Conexão com o banco ───────────────────────────────────────────────────────

logMsg('=== Presence Tracker Start ===');

try {
    // CliDb: PDO por baixo, API do mysqli por cima — as consultas deste script
    // não mudaram. Ver scripts/CliDb.php.
    $db = new CliDb(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, DB_TYPE);
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao conectar no banco: ' . $e->getMessage());
    logMsg('Confira as env DB_TYPE/DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS no arquivo de cron.');
    exit(1);
}

// ── A tabela é provisionada pelo Module.php, não aqui ─────────────────────────
//
// O script já teve um CREATE TABLE próprio, que divergia do DDL oficial
// (id INT em vez de BIGINT UNSIGNED, sem a coluna ip, índices com outro nome).
// Num ambiente novo em que o cron rodasse antes do primeiro request com o
// módulo habilitado, Module::existingTables() via a tabela como existente e
// nunca a corrigia. Provisionamento tem que viver num lugar só — se a tabela
// não existe, o certo é falhar dizendo o que fazer.

try {
    $chk = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = " . $db->schemaFilter() . "
            AND TABLE_NAME   = '" . PRESENCE_TABLE . "'"
    );
    $table_exists = ($chk && $chk->num_rows > 0);
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao verificar a tabela de presença: ' . $e->getMessage());
    exit(1);
}

if (!$table_exists) {
    logMsg('CRITICAL: tabela ' . PRESENCE_TABLE . ' não existe.');
    logMsg('Habilite o módulo Plantonistas no frontend uma vez (Administração → Geral → Módulos).');
    logMsg('O Module::init() cria e migra o schema; este script não provisiona nada.');
    exit(1);
}

// ── Sessões ativas ────────────────────────────────────────────────────────────

$now              = time();
$active_threshold = $now - SESSION_TIMEOUT_SEC;
$active_users     = [];

try {
    $stmt = $db->prepare("
        SELECT s.userid, MAX(s.lastaccess) AS lastaccess
        FROM sessions s
        WHERE s.status = 0
          AND s.lastaccess >= ?
        GROUP BY s.userid
    ");
    $stmt->bind_param('i', $active_threshold);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $active_users[(int)$row['userid']] = (int)$row['lastaccess'];
    }
    $stmt->close();
    logMsg('Sessões ativas no Zabbix: ' . count($active_users));
} catch (Throwable $e) {
    logMsg('CRITICAL: Não foi possível ler a tabela sessions: ' . $e->getMessage());
    exit(1);
}

// ── Dados dos usuários com sessão ativa ───────────────────────────────────────
//
// Antes isso vinha de user.get na API JSON-RPC, com o token viajando por um
// canal sem verificação de TLS. username/name/surname estão em `users`, no
// mesmo banco em que este script já está conectado — a API nunca foi
// necessária. A consulta agora é pequena: só os userids com sessão ativa,
// em vez da base inteira de usuários.
//
// Critério de habilitado: o mesmo do resto do módulo e do próprio Zabbix
// (CUser::getAccess() usa MAX(usrgrp.users_status)) — pertencer a QUALQUER
// grupo desabilitado desabilita a pessoa. Usuário sem role também conta como
// desabilitado. Ver TurnosReportBase::enabledUserClause() e o CLAUDE.md.

$users_by_id = [];

if ($active_users) {
    $ids = implode(',', array_map('intval', array_keys($active_users)));

    try {
        $res = $db->query("
            SELECT u.userid, u.username, u.name, u.surname
            FROM users u
            WHERE u.userid IN ($ids)
              AND NOT EXISTS (
                    SELECT 1 FROM users_groups ugx
                    INNER JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid
                    WHERE ugx.userid = u.userid AND gx.users_status = 1
                  )
              AND u.roleid IS NOT NULL AND u.roleid <> 0
        ");
        while ($row = $res->fetch_assoc()) {
            $users_by_id[(int)$row['userid']] = $row;
        }
        logMsg('Usuários habilitados entre as sessões ativas: ' . count($users_by_id));
    } catch (Throwable $e) {
        logMsg('CRITICAL: Falha ao buscar usuários: ' . $e->getMessage());
        exit(1);
    }
}

// ── Contexto de NOC (grupo do usuário) ────────────────────────────────────────
//
// Query própria, não JOIN na consulta acima: contexto é informação acessória
// e, se ela falhar, a presença tem que continuar sendo gravada (mesma regra
// que já vale para a coluna Turno da tela de Presença — ver CLAUDE.md).

$groups_by_user = [];

if ($users_by_id) {
    $ids = implode(',', array_map('intval', array_keys($users_by_id)));

    try {
        $res = $db->query("
            SELECT ug.userid, g.name
            FROM users_groups ug
            INNER JOIN usrgrp g ON g.usrgrpid = ug.usrgrpid
            WHERE ug.userid IN ($ids)
              AND g.users_status = 0
              AND g.gui_access <> 3
            ORDER BY ug.userid, g.usrgrpid
        ");
        while ($row = $res->fetch_assoc()) {
            $groups_by_user[(int)$row['userid']][] = $row['name'];
        }
    } catch (Throwable $e) {
        // Não é fatal: sem contexto a presença continua sendo registrada.
        logMsg('WARN: Falha ao buscar grupos dos usuários: ' . $e->getMessage());
        logMsg('Continuando sem noc_context nesta execução.');
    }
}

// ── Gravar presenças ──────────────────────────────────────────────────────────

$inserted = 0;
$updated  = 0;

// "Hoje" e o corte da limpeza são calculados em PHP (America/Sao_Paulo), não
// com CURDATE()/NOW() do servidor. O banco roda em outro host e pode estar em
// UTC: das 21h em diante o CURDATE() do MariaDB já seria o dia seguinte, a
// linha gravada às 21h05 nunca casaria, e o script criaria uma sessão nova a
// cada ciclo — duplicando a presença justamente no turno da noite. Mesmo tipo
// de bug do heatmap do Repasse (ver CLAUDE.md).
// Limites do dia como intervalo, para a busca abaixo poder usar o índice
// idx_cus_session_start: com CAST(session_start AS DATE) = ... o banco tem de
// calcular a função em cada linha e o índice fica de fora.
$dia_ini = date('Y-m-d 00:00:00');
$dia_fim = date('Y-m-d 00:00:00', strtotime('+1 day'));
$cutoff = date('Y-m-d H:i:s', $now - 7 * 86400);

foreach ($active_users as $userid => $lastaccess_ts) {
    $u = $users_by_id[$userid] ?? null;
    if (!$u) continue;  // sessão de usuário desabilitado ou removido

    $username   = $u['username'];
    $fullname   = mb_substr(buildFullName($u['name'] ?? '', $u['surname'] ?? '', $username), 0, NAME_MAXLEN);
    $lastaccess = date('Y-m-d H:i:s', $lastaccess_ts);

    // Primeiro grupo do usuário que não seja de sistema
    $noc_context = null;
    foreach ($groups_by_user[$userid] ?? [] as $gname) {
        if (!in_array($gname, SYSTEM_GROUPS, true)) {
            $noc_context = mb_substr($gname, 0, 50);
            break;
        }
    }

    try {
        // Verifica se já existe sessão ativa para esse usuário hoje
        $stmt = $db->prepare("
            SELECT id
            FROM " . PRESENCE_TABLE . "
            WHERE userid = ?
              AND session_start >= ?
              AND session_start <  ?
            ORDER BY session_start DESC
            LIMIT 1
        ");
        $stmt->bind_param('iss', $userid, $dia_ini, $dia_fim);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            // `name` também entra no SET (antes só era gravado no INSERT): sem
            // isso, a sessão aberta hoje ficaria com o nome antigo até a
            // limpeza de 7 dias — inclusive o nome duplicado que
            // buildFullName() corrige, e qualquer renomeação feita no cadastro
            // do Zabbix depois de a sessão abrir.
            $stmt = $db->prepare("
                UPDATE " . PRESENCE_TABLE . "
                SET lastaccess = ?, noc_context = ?, name = ?
                WHERE id = ?
            ");
            $stmt->bind_param('sssi', $lastaccess, $noc_context, $fullname, $existing['id']);
            $stmt->execute();
            $stmt->close();
            $updated++;
        } else {
            $session_start = date('Y-m-d H:i:s', $lastaccess_ts - 60); // estimativa
            $stmt = $db->prepare("
                INSERT INTO " . PRESENCE_TABLE . "
                    (userid, username, name, session_start, lastaccess, noc_context)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('isssss',
                $userid, $username, $fullname,
                $session_start, $lastaccess, $noc_context
            );
            $stmt->execute();
            $stmt->close();
            $inserted++;
        }
    } catch (Throwable $e) {
        // Uma linha problemática não pode derrubar a execução inteira.
        logMsg("WARN: Falha ao gravar presença de {$username}: " . $e->getMessage());
        continue;
    }

    logMsg("  [{$username}] lastaccess={$lastaccess}" . ($noc_context ? " ctx={$noc_context}" : ''));
}

logMsg("Gravados: {$inserted} novos, {$updated} atualizados.");

// ── Limpeza de sessões antigas (> 7 dias) ─────────────────────────────────────

try {
    $stmt = $db->prepare(
        'DELETE FROM ' . PRESENCE_TABLE . ' WHERE session_start < ?'
    );
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    if ($deleted > 0) {
        logMsg("Limpeza: {$deleted} sessões antigas removidas (> 7 dias).");
    }
} catch (Throwable $e) {
    logMsg('WARN: Falha na limpeza de sessões antigas: ' . $e->getMessage());
}

$db->close();
logMsg('=== Presence Tracker End ===');
exit(0);
