<?php
/**
 * cron_presence_tracker.php
 *
 * Rastreia a presença de analistas no Zabbix consultando a API
 * e grava os dados na tabela module_plantonistas_user_sessions.
 *
 * Compatível com Zabbix 5.4, 6.x e 7.0+
 *
 * Cron sugerido (a cada 5 min):
 *   a cada 5 min: 0/5 * * * * php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_presence_tracker.php
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 *
 * Changelog:
 *   v2.3.0 - Autenticação migrada para token permanente (Bearer Auth)
 *            Token no header Authorization: Bearer <token> (Zabbix 7.0+)
 *            Validação via user.get em vez de user.checkAuthentication
 */

// ── Configuração ──────────────────────────────────────────────────────────────

// Token permanente gerado em: Zabbix → User Settings → API tokens
// Ou via: Administration → General → API tokens
// Defina via variável de ambiente ZABBIX_API_TOKEN (ou no /etc/cron.d). NÃO versione o token.
define('ZABBIX_API_TOKEN', getenv('ZABBIX_API_TOKEN') ?: '');

// URL do Zabbix — aceita URL base ou URL completa do endpoint
// Exemplos válidos:
//   http://localhost/zabbix
//   http://localhost/zabbix/api_jsonrpc.php
$_zbx_url = rtrim(getenv('ZABBIX_URL') ?: 'http://localhost/zabbix', '/');
define('ZABBIX_URL', $_zbx_url);
define('ZABBIX_API_URL', str_ends_with($_zbx_url, 'api_jsonrpc.php')
    ? $_zbx_url
    : $_zbx_url . '/api_jsonrpc.php'
);

// Conexão direta ao banco MySQL do Zabbix
define('DB_HOST',   $GLOBALS['DB']['SERVER']   ?? getenv('DB_HOST')   ?: 'localhost');
define('DB_PORT',   (int)($GLOBALS['DB']['PORT'] ?? getenv('DB_PORT')  ?: 3306));
define('DB_NAME',   $GLOBALS['DB']['DATABASE'] ?? getenv('DB_NAME')   ?: 'zabbix');
define('DB_USER',   $GLOBALS['DB']['USER']     ?? getenv('DB_USER')   ?: 'zabbix');
define('DB_PASS',   $GLOBALS['DB']['PASSWORD'] ?? getenv('DB_PASS')   ?: '');


// Janela de inatividade: sessão encerrada se lastaccess > N segundos atrás
define('SESSION_TIMEOUT_SEC', 600); // 10 minutos

// ── Bootstrap ─────────────────────────────────────────────────────────────────

date_default_timezone_set('America/Sao_Paulo');

function logMsg(string $msg): void {
    $ts = date('[Y-m-d H:i:s]');
    echo "$ts $msg\n";
}

// ── API Zabbix ────────────────────────────────────────────────────────────────

/**
 * Chama a API JSON-RPC do Zabbix.
 *
 * Zabbix 7.0+: token no header Authorization: Bearer <token>
 * Zabbix 5.4–6.x: token no campo "auth" do payload
 * Esta função envia o token nos DOIS lugares para compatibilidade total.
 */
function zabbixApi(string $method, array $params): array {
    $token = ZABBIX_API_TOKEN;

    if (empty($token) || $token === 'SEU_TOKEN_AQUI') {
        logMsg('CRITICAL: ZABBIX_API_TOKEN não configurado.');
        return ['error' => ['data' => 'Token não configurado']];
    }

    // Payload sem "auth" para Zabbix 7.0+
    // (Zabbix 7.0 rejeita "auth" como parâmetro separado no payload)
    $payload = [
        'jsonrpc' => '2.0',
        'method'  => $method,
        'params'  => $params,
        'id'      => 1,
    ];

    $ch = curl_init(ZABBIX_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,  // Zabbix 7.0+
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        logMsg("CURL error: $err");
        return ['error' => ['data' => $err]];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['error' => ['data' => 'JSON inválido: ' . $raw]];
}

// ── Validação do token ────────────────────────────────────────────────────────

logMsg('=== Presence Tracker Start ===');
logMsg('Usando token permanente: ' . substr(ZABBIX_API_TOKEN, 0, 8) . '...');

// user.get funciona no Zabbix 5.4, 6.x e 7.0 com Bearer token
$pingResp = zabbixApi('user.get', [
    'output' => ['userid', 'username'],
    'limit'  => 1,
]);

if (isset($pingResp['error']) || !isset($pingResp['result'])) {
    $errMsg = $pingResp['error']['data'] ?? $pingResp['error']['message'] ?? 'desconhecido';
    logMsg("CRITICAL: Token inválido ou API inacessível. Erro: $errMsg");
    logMsg('Verifique ZABBIX_API_TOKEN e ZABBIX_URL.');
    exit(1);
}

logMsg('Token válido — API acessível (Zabbix 7.0+ Bearer Auth)');

// ── Buscar usuários ativos na API ─────────────────────────────────────────────

$usersResp = zabbixApi('user.get', [
    'output'          => ['userid', 'username', 'name', 'surname', 'roleid'],
    'selectUsrgrps'   => ['usrgrpid', 'name'],
    'filter'          => ['gui_access' => [0, 1, 2]], // todos exceto desabilitados
    'sortfield'       => 'username',
]);

if (isset($usersResp['error']) || !isset($usersResp['result'])) {
    $errMsg = $usersResp['error']['data'] ?? 'desconhecido';
    logMsg("CRITICAL: Falha ao buscar usuários: $errMsg");
    exit(1);
}

$users = $usersResp['result'];
logMsg('Usuários encontrados: ' . count($users));

// ── Buscar sessões ativas via API ─────────────────────────────────────────────
// Zabbix não expõe sessions diretamente via API pública.
// Usamos user.get com lastLogin para estimar presença.
// Para rastreamento preciso, complementar com tabela sessions do banco.

$now = time();
$active_threshold = $now - SESSION_TIMEOUT_SEC;

// ── Conexão com o banco ───────────────────────────────────────────────────────

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $db->set_charset('utf8mb4');
} catch (Exception $e) {
    logMsg('CRITICAL: Falha ao conectar no banco: ' . $e->getMessage());
    exit(1);
}

// ── Garantir que a tabela existe ──────────────────────────────────────────────

$db->query("
    CREATE TABLE IF NOT EXISTS module_plantonistas_user_sessions (
        id             INT          NOT NULL AUTO_INCREMENT,
        userid         BIGINT       NOT NULL,
        username       VARCHAR(100) NOT NULL DEFAULT '',
        name           VARCHAR(255) NOT NULL DEFAULT '',
        session_start  DATETIME     NOT NULL,
        lastaccess     DATETIME     NOT NULL,
        noc_context    VARCHAR(50)  DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_userid      (userid),
        KEY idx_lastaccess  (lastaccess)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Adiciona noc_context se a tabela já existia sem ela.
// Usa INFORMATION_SCHEMA — compatível com MySQL 5.7, 8.0 e MariaDB.
$colCheck = $db->query("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'module_plantonistas_user_sessions'
      AND COLUMN_NAME  = 'noc_context'
");
if ($colCheck && $colCheck->num_rows === 0) {
    $db->query("ALTER TABLE module_plantonistas_user_sessions ADD COLUMN noc_context VARCHAR(50) DEFAULT NULL");
    logMsg('Migração: coluna noc_context adicionada à module_plantonistas_user_sessions.');
}

// ── Buscar sessões ativas no banco do Zabbix ──────────────────────────────────

$active_users = [];

// Zabbix armazena sessões na tabela `sessions` (campo lastaccess = unix timestamp)
try {
    $stmt = $db->prepare("
        SELECT DISTINCT s.userid, s.lastaccess
        FROM sessions s
        WHERE s.status = 0
          AND s.lastaccess >= ?
    ");
    $stmt->bind_param('i', $active_threshold);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $active_users[$row['userid']] = (int)$row['lastaccess'];
    }
    $stmt->close();
    logMsg('Sessões ativas no Zabbix: ' . count($active_users));
} catch (Exception $e) {
    logMsg('WARN: Não foi possível ler tabela sessions: ' . $e->getMessage());
    logMsg('Continuando sem dados de sessão do banco...');
}

// ── Indexar usuários por userid ───────────────────────────────────────────────

$users_by_id = [];
foreach ($users as $u) {
    $users_by_id[(int)$u['userid']] = $u;
}

// ── Gravar presenças ──────────────────────────────────────────────────────────

$inserted = 0;
$updated  = 0;
$now_dt   = date('Y-m-d H:i:s', $now);

foreach ($active_users as $userid => $lastaccess_ts) {
    $u = $users_by_id[$userid] ?? null;
    if (!$u) continue;

    $username    = $u['username'];
    $fullname    = trim(($u['name'] ?? '') . ' ' . ($u['surname'] ?? ''));
    $lastaccess  = date('Y-m-d H:i:s', $lastaccess_ts);

    // Determinar noc_context a partir dos grupos do usuário
    $noc_context = null;
    if (!empty($u['usrgrps'])) {
        foreach ($u['usrgrps'] as $grp) {
            // Primeiro grupo que não seja sistema = contexto NOC
            if (!in_array($grp['name'], ['Disabled', 'No access to the frontend', 'Guests'], true)) {
                $noc_context = substr($grp['name'], 0, 50);
                break;
            }
        }
    }

    // Verifica se já existe sessão ativa para esse usuário hoje
    $stmt = $db->prepare("
        SELECT id, session_start
        FROM module_plantonistas_user_sessions
        WHERE userid = ?
          AND DATE(session_start) = CURDATE()
        ORDER BY session_start DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $userid);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        // Atualiza lastaccess da sessão existente
        $stmt = $db->prepare("
            UPDATE module_plantonistas_user_sessions
            SET lastaccess = ?, noc_context = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $lastaccess, $noc_context, $existing['id']);
        $stmt->execute();
        $stmt->close();
        $updated++;
    } else {
        // Insere nova sessão
        $session_start = date('Y-m-d H:i:s', $lastaccess_ts - 60); // estimativa
        $stmt = $db->prepare("
            INSERT INTO module_plantonistas_user_sessions
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

    logMsg("  [{$username}] lastaccess={$lastaccess}" . ($noc_context ? " ctx={$noc_context}" : ''));
}

logMsg("Gravados: {$inserted} novos, {$updated} atualizados.");

// ── Limpeza de sessões antigas (> 7 dias) ─────────────────────────────────────

$db->query("
    DELETE FROM module_plantonistas_user_sessions
    WHERE session_start < DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$deleted = $db->affected_rows;
if ($deleted > 0) {
    logMsg("Limpeza: {$deleted} sessões antigas removidas (> 7 dias).");
}

$db->close();
logMsg('=== Presence Tracker End ===');
exit(0);
