<?php
/**
 * cron_sync_oncall.php
 *
 * Sincroniza a escala do módulo com o escalonamento nativo do Zabbix.
 *
 * O módulo sabe quem está de plantão; as Ações do Zabbix não. Este script
 * fecha essa distância mantendo, para cada equipe, um grupo de usuários do
 * Zabbix com exatamente o plantonista do turno corrente. A partir daí a Ação
 * nativa escala para esse grupo, e o módulo não precisa reimplementar nada de
 * media type, mensagem ou escalonamento.
 *
 * ── Convenção de nome ────────────────────────────────────────────────────
 *
 * Para a equipe (grupo de usuários) "NOC", o script procura o grupo:
 *
 *     Plantonista de Hoje - NOC
 *
 * O prefixo é configurável pela env ONCALL_GROUP_PREFIX (o " - " é
 * acrescentado se você não puser um separador). O script **não cria grupo**:
 * se não existir, avisa no log e pula a equipe. Criar grupo de usuário exige
 * reservar usrgrpid na tabela `ids` do Zabbix, e o histórico deste ambiente
 * (ver CLAUDE.md, "Não é possível atualizar a função do usuário") mostra o
 * estrago de escrever ID na mão em tabela do core. Criar o grupo uma vez pela
 * UI é barato e seguro.
 *
 * O grupo de destino precisa estar **Habilitado**. Grupo com Status =
 * Desabilitado é recusado com aviso, porque o status de um usuário no Zabbix é
 * MAX(usrgrp.users_status) sobre TODOS os grupos dele: pôr o plantonista num
 * grupo desabilitado desabilitaria a conta dele no Zabbix inteiro — ele
 * pararia de logar, sumiria das telas do próprio módulo e deixaria de receber
 * a notificação, que é justamente o objetivo. E o sintoma apareceria longe da
 * causa.
 *
 * ── Sem cobertura ────────────────────────────────────────────────────────
 *
 * Dia/turno sem ninguém escalado NÃO esvazia o grupo: quem estava lá continua
 * até alguém ser escalado (decisão do Rafael). Esvaziar significaria alerta
 * sem destinatário justamente na madrugada. O log registra sempre que isso
 * acontece, porque a pessoa passa a receber alerta fora do turno dela.
 *
 * ── Escopo ───────────────────────────────────────────────────────────────
 *
 * Só o TITULAR entra no grupo. O reserva (userid_reserva, que só existe no
 * modo legado) fica de fora: reserva não está de plantão, está disponível.
 *
 * Cron sugerido (a cada 5 min — a virada de turno leva até um ciclo para
 * refletir; some ainda o config sync do Zabbix server, ~1 min):
 *   0/5 * * * * php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_sync_oncall.php
 *
 * Rodar em UM frontend só, como o rastreador de presença: escreve no banco
 * compartilhado, e em dois nós duas execuções concorrentes disputariam a
 * mesma reserva de ID.
 *
 * Sai com código 1 se alguma equipe falhou — dá para monitorar.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 *
 * Changelog:
 *   v1.0.0 - Primeira versão (issue #5).
 */

// ── Configuração ──────────────────────────────────────────────────────────────

// Em CLI não existe $GLOBALS['DB'], então as env DB_* do /etc/cron.d são
// obrigatórias em produção — sem elas o script cai no default localhost.
define('DB_HOST', $GLOBALS['DB']['SERVER']   ?? getenv('DB_HOST')   ?: 'localhost');
define('DB_PORT', (int)($GLOBALS['DB']['PORT'] ?? getenv('DB_PORT') ?: 3306));
define('DB_NAME', $GLOBALS['DB']['DATABASE'] ?? getenv('DB_NAME')   ?: 'zabbix');
define('DB_USER', $GLOBALS['DB']['USER']     ?? getenv('DB_USER')   ?: 'zabbix');
define('DB_PASS', $GLOBALS['DB']['PASSWORD'] ?? getenv('DB_PASS')   ?: '');

/**
 * Prefixo do grupo de destino: "NOC" → "Plantonista de Hoje - NOC".
 *
 * O separador é normalizado: quem definir ONCALL_GROUP_PREFIX='Plantonista de
 * Hoje' (o instinto natural, sem espaço no fim) não acaba com
 * "Plantonista de HojeNOC".
 */
$_prefix = trim(getenv('ONCALL_GROUP_PREFIX') ?: 'Plantonista de Hoje');
define('GROUP_PREFIX', preg_match('/[-–—:>|]\s*$/u', $_prefix) ? $_prefix . ' ' : $_prefix . ' - ');

/** DRY_RUN=1 mostra o que faria, sem escrever nada. */
define('DRY_RUN', (bool) getenv('DRY_RUN'));

/**
 * Janela de dias considerados ao listar equipes.
 *
 * Sem isso, uma equipe que teve escala uma vez em 2024 e nunca mais seria
 * processada para sempre, poluindo o log com "sem escalado" a cada ciclo.
 * São 2 dias, não 1, por causa do turno que vira a meia-noite: às 02h o
 * plantão corrente é o de ontem.
 */
define('LOOKBACK_DAYS', 2);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

date_default_timezone_set('America/Sao_Paulo');

function logMsg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
}

// ── Conexão ───────────────────────────────────────────────────────────────────

logMsg('=== Sync On-Call Start ===' . (DRY_RUN ? ' (DRY RUN — nada será gravado)' : ''));

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao conectar no banco: ' . $e->getMessage());
    logMsg('Confira as env DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS no arquivo de cron.');
    exit(1);
}

try {
    foreach (['module_plantonistas_schedule', 'module_plantonistas_shifts'] as $t) {
        $chk = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"
        );
        if (!$chk || $chk->num_rows === 0) {
            logMsg("CRITICAL: tabela $t não existe.");
            logMsg('Habilite o módulo Plantonistas no frontend uma vez — é o Module::init() que provisiona.');
            exit(1);
        }
    }
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao verificar as tabelas do módulo: ' . $e->getMessage());
    exit(1);
}

// ── Turno corrente ────────────────────────────────────────────────────────────

/**
 * Diz se "agora" está dentro da janela de um turno, e qual a data de plantão
 * correspondente.
 *
 * Turno que vira o dia (end_time <= start_time, ex.: 19:00–07:00) pertence à
 * data em que COMEÇOU: às 02h da manhã, quem está de plantão é o escalado de
 * ontem. Errar isso é escalar a pessoa errada exatamente na madrugada.
 *
 * Resolve a mesma regra de virada de meia-noite do computeCustomShiftBounds()
 * do trait TurnosReportBase, mas responde outra pergunta (lá: "quais são os
 * limites desta data"; aqui: "qual data está correndo agora") — não tente
 * unificar as duas. A duplicação da regra é proposital: o cron roda em CLI,
 * sem carregar o módulo.
 *
 * @return string|null data (Y-m-d) do plantão, ou null se o turno não corre agora
 */
function currentShiftDate(string $start, string $end, int $now): ?string {
    $today     = date('Y-m-d', $now);
    $yesterday = date('Y-m-d', strtotime('-1 day', $now));
    $tomorrow  = date('Y-m-d', strtotime('+1 day', $now));

    $crossesMidnight = strtotime($end) <= strtotime($start);

    if (!$crossesMidnight) {
        return ($now >= strtotime("$today $start") && $now < strtotime("$today $end"))
            ? $today
            : null;
    }

    // Cauda do turno que começou ontem (ex.: agora 02:00, turno 19:00–07:00)
    if ($now >= strtotime("$yesterday $start") && $now < strtotime("$today $end")) {
        return $yesterday;
    }

    // Começo do turno de hoje (ex.: agora 20:00)
    return ($now >= strtotime("$today $start") && $now < strtotime("$tomorrow $end"))
        ? $today
        : null;
}

/**
 * Reserva N ids para uma tabela do core do Zabbix, do jeito que o próprio
 * Zabbix reserva (DB::reserveIds / DB::refreshIds).
 *
 * `users_groups.id` NÃO é auto-increment: o Zabbix guarda o **último id
 * usado** em `ids.nextid` e distribui a partir dele. Inserir com MAX(id)+1 sem
 * mexer em `ids` deixa o contador atrasado e faz TODO insert seguinte do
 * frontend colidir — foi exatamente isso que o módulo `fcorr` causou em
 * `role_rule` neste ambiente, e que impediu de salvar papel de usuário (ver
 * CLAUDE.md).
 *
 * `$table` e `$field` são interpolados no SQL (identificador não aceita
 * placeholder): só passe literais do código, nunca dado externo.
 *
 * Abre transação própria. Em MySQL/MariaDB `START TRANSACTION` faz **commit
 * implícito** da transação em andamento, então NÃO chame esta função de dentro
 * de outra transação — reserve o id antes de abri-la.
 *
 * (O teto ZBX_DB_MAX_ID que o Zabbix confere antes do UPDATE foi omitido de
 * propósito: irrelevante para users_groups.id na escala deste ambiente.)
 *
 * @return int primeiro id livre; os N ids são [retorno, retorno + N - 1]
 */
function reserveIds(mysqli $db, string $table, string $field, int $count): int {
    $db->begin_transaction();

    try {
        // FOR UPDATE trava a linha do contador: duas execuções simultâneas do
        // cron (ou o frontend salvando um usuário ao mesmo tempo) esperam a vez
        // em vez de reservarem a mesma faixa.
        $stmt = $db->prepare(
            'SELECT nextid FROM ids WHERE table_name = ? AND field_name = ? FOR UPDATE'
        );
        $stmt->bind_param('ss', $table, $field);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            // Sem linha em `ids`, o Zabbix a cria a partir do MAX() da tabela.
            $max = (int) ($db->query("SELECT COALESCE(MAX($field), 0) AS m FROM $table")
                ->fetch_assoc()['m'] ?? 0);
            $stmt = $db->prepare(
                'INSERT INTO ids (table_name, field_name, nextid) VALUES (?, ?, ?)'
            );
            $stmt->bind_param('ssi', $table, $field, $max);
            $stmt->execute();
            $stmt->close();
            $nextid = $max;
        }
        else {
            $nextid = (int) $row['nextid'];
        }

        $newid = $nextid + $count;
        $stmt  = $db->prepare(
            'UPDATE ids SET nextid = ? WHERE table_name = ? AND field_name = ?'
        );
        $stmt->bind_param('iss', $newid, $table, $field);
        $stmt->execute();
        $stmt->close();

        $db->commit();

        return $nextid + 1;
    } catch (Throwable $e) {
        // rollback() dentro de try próprio: com MYSQLI_REPORT_STRICT, se a
        // causa foi perda de conexão o próprio rollback lança e engoliria a
        // exceção original, que é a que diz o que aconteceu de verdade.
        try {
            $db->rollback();
        } catch (Throwable $ignored) {
        }
        throw $e;
    }
}

// ── Equipes com escala recente ────────────────────────────────────────────────

$now      = time();
$since    = date('Y-m-d', strtotime('-' . LOOKBACK_DAYS . ' days', $now));

try {
    $stmt = $db->prepare(
        "SELECT DISTINCT s.usrgrpid, g.name
           FROM module_plantonistas_schedule s
           INNER JOIN usrgrp g ON g.usrgrpid = s.usrgrpid
          WHERE s.schedule_date >= ?
          ORDER BY g.name"
    );
    $stmt->bind_param('s', $since);
    $stmt->execute();
    $teams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao listar equipes com escala: ' . $e->getMessage());
    exit(1);
}

logMsg('Equipes com escala desde ' . $since . ': ' . count($teams));

$sincronizados  = 0;
$sem_mudanca    = 0;
$sem_cobertura  = 0;
$erros          = 0;

foreach ($teams as $team) {
    $usrgrpid  = (int) $team['usrgrpid'];
    $teamName  = $team['name'];
    $groupName = GROUP_PREFIX . $teamName;

    // ── 1. Qual turno está correndo agora nesta equipe ────────────────────
    //
    // Grupo sem turno cadastrado continua no modo legado: shift_id = 0 e o
    // plantão vale o dia inteiro, como sempre foi.
    $shiftId    = 0;
    $shiftLabel = 'dia inteiro';
    $planDate   = date('Y-m-d', $now);

    try {
        $stmt = $db->prepare(
            "SELECT id, name, start_time, end_time
               FROM module_plantonistas_shifts
              WHERE usrgrpid = ? AND active = 1
              ORDER BY sort_order, start_time"
        );
        $stmt->bind_param('i', $usrgrpid);
        $stmt->execute();
        $shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        // NÃO cai para o modo legado aqui. Turno não é dado acessório: é o que
        // decide quem está de plantão. Com $shifts = [] o script sincronizaria
        // a entrada shift_id = 0 — que existe mesmo em grupo com turnos,
        // porque o import CSV grava sempre em modo legado — e escalaria a
        // pessoa errada, com um "OK:" no log dizendo que deu certo.
        logMsg("WARN: [$teamName] falha ao ler turnos — equipe pulada: " . $e->getMessage());
        $erros++;
        continue;
    }

    if ($shifts) {
        $correntes = [];
        foreach ($shifts as $s) {
            $d = currentShiftDate($s['start_time'], $s['end_time'], $now);
            if ($d !== null) {
                $correntes[] = [$s, $d];
            }
        }

        if (!$correntes) {
            // Turnos cadastrados que não cobrem as 24h deixam buracos —
            // madrugada sem turno, por exemplo. Nesse intervalo não há turno
            // corrente, e mexer no grupo seria chute.
            logMsg("INFO: [$teamName] nenhum turno cobre o horário atual — grupo mantido.");
            $sem_cobertura++;
            continue;
        }

        if (count($correntes) > 1) {
            // Sobreposição é erro de cadastro em "Gerenciar Turnos" (nada
            // impede 07:00–19:00 e 12:00–20:00 na mesma equipe). Sem este
            // aviso, um plantonista seria ignorado todo dia sem deixar rastro.
            $nomes = implode(', ', array_map(fn($c) => $c[0]['name'], $correntes));
            logMsg("WARN: [$teamName] turnos sobrepostos correndo agora ($nomes)"
                 . ' — usando o primeiro por sort_order. Revise o cadastro.');
        }

        [$s, $planDate] = $correntes[0];
        $shiftId    = (int) $s['id'];
        $shiftLabel = $s['name'] . ' (' . substr($s['start_time'], 0, 5)
                    . '–' . substr($s['end_time'], 0, 5) . ')';
    }

    // ── 2. Quem é o titular ───────────────────────────────────────────────
    try {
        $stmt = $db->prepare(
            "SELECT userid FROM module_plantonistas_schedule
              WHERE usrgrpid = ? AND schedule_date = ? AND shift_id = ?"
        );
        $stmt->bind_param('isi', $usrgrpid, $planDate, $shiftId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        logMsg("WARN: [$teamName] falha ao ler a escala: " . $e->getMessage());
        $erros++;
        continue;
    }

    if ($row === null) {
        // Sem cobertura: o grupo fica como está (decisão registrada no topo).
        logMsg("INFO: [$teamName] sem escalado para $planDate / $shiftLabel"
             . ' — mantido quem já estava no grupo.');
        $sem_cobertura++;
        continue;
    }

    $userid = (int) $row['userid'];

    // ── 3. O usuário ainda existe e está habilitado? ──────────────────────
    //
    // module_plantonistas_schedule.userid não tem FK para users: usuário
    // removido no Zabbix deixa a linha da escala órfã, e users_groups.userid
    // TEM FK — o INSERT falharia. E escalar quem está desabilitado é escalar
    // ninguém. Mesmo critério de "habilitado" do resto do módulo.
    try {
        // CASE ... THEN 1 ELSE 0 END em vez de devolver a expressão booleana
        // crua: o MySQL entrega 1/0, mas o PostgreSQL entrega 't'/'f', e
        // (int)'t' é 0 — TODO plantonista seria considerado desabilitado,
        // toda equipe seria pulada, e o log diria "está desabilitado no
        // Zabbix", mandando investigar um cadastro que está correto.
        $stmt = $db->prepare(
            "SELECT u.userid,
                    CASE WHEN NOT EXISTS (
                            SELECT 1 FROM users_groups ugx
                            INNER JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid
                            WHERE ugx.userid = u.userid AND gx.users_status = 1
                         ) AND u.roleid IS NOT NULL AND u.roleid <> 0
                         THEN 1 ELSE 0 END AS habilitado
               FROM users u WHERE u.userid = ?"
        );
        $stmt->bind_param('i', $userid);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        logMsg("WARN: [$teamName] falha ao validar o plantonista: " . $e->getMessage());
        $erros++;
        continue;
    }

    if ($u === null) {
        logMsg("WARN: [$teamName] userid $userid está na escala mas não existe mais"
             . ' no Zabbix — equipe pulada. Reescale o dia.');
        $erros++;
        continue;
    }

    if (!(int) $u['habilitado']) {
        logMsg("WARN: [$teamName] userid $userid está desabilitado no Zabbix"
             . ' (algum grupo com Status = Desabilitado, ou sem papel) — equipe pulada.');
        $erros++;
        continue;
    }

    // ── 4. O grupo de destino existe e serve? ─────────────────────────────
    try {
        // Comparação por LOWER(TRIM(...)) nos dois lados: o nome do grupo é
        // digitado por uma pessoa na UI do Zabbix, e um espaço no fim ou uma
        // letra em caixa diferente faria a equipe ser pulada todo ciclo, com
        // o log dizendo que o grupo "não existe" enquanto ele está lá na tela.
        // Vale independente de banco, mas é obrigatório no PostgreSQL, que —
        // ao contrário do MySQL com collation _ci — compara texto com caixa.
        $stmt = $db->prepare(
            'SELECT usrgrpid, users_status FROM usrgrp WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))'
        );
        $stmt->bind_param('s', $groupName);
        $stmt->execute();
        $g = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        logMsg("WARN: [$teamName] falha ao procurar o grupo de destino: " . $e->getMessage());
        $erros++;
        continue;
    }

    if ($g === null) {
        logMsg("WARN: [$teamName] grupo \"$groupName\" não existe — crie em"
             . ' Usuários → Grupos de utilizadores (vazio, Habilitado). Equipe pulada.');
        $erros++;
        continue;
    }

    $targetGrpId = (int) $g['usrgrpid'];

    if ($targetGrpId === $usrgrpid) {
        // Prefixo mal configurado apontando para o próprio grupo da equipe:
        // sincronizar aqui esvaziaria a equipe inteira, deixando só o
        // plantonista. Remoção em massa num grupo do core, sem volta.
        logMsg("WARN: [$teamName] o grupo de destino é o PRÓPRIO grupo da equipe"
             . ' — equipe pulada. Confira ONCALL_GROUP_PREFIX.');
        $erros++;
        continue;
    }

    if ((int) $g['users_status'] === 1) {
        // O status de um usuário no Zabbix é MAX(users_status) sobre todos os
        // grupos dele: pôr o plantonista aqui desabilitaria a conta.
        logMsg("WARN: [$teamName] grupo \"$groupName\" está com Status = Desabilitado."
             . ' Incluir o plantonista ali DESABILITARIA a conta dele no Zabbix'
             . ' (status = MAX(users_status) de todos os grupos): ele pararia de'
             . ' logar, sumiria das telas do módulo e deixaria de ser notificado.'
             . ' Marque o grupo como Habilitado. Equipe pulada.');
        $erros++;
        continue;
    }

    // ── 5. Sincronizar os membros ─────────────────────────────────────────
    try {
        $stmt = $db->prepare('SELECT userid FROM users_groups WHERE usrgrpid = ?');
        $stmt->bind_param('i', $targetGrpId);
        $stmt->execute();
        $atuais = array_map(
            fn($r) => (int) $r['userid'],
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
        );
        $stmt->close();
    } catch (Throwable $e) {
        logMsg("WARN: [$teamName] falha ao ler membros de \"$groupName\": " . $e->getMessage());
        $erros++;
        continue;
    }

    if ($atuais === [$userid]) {
        $sem_mudanca++;
        continue;   // já está certo; não escreve à toa
    }

    $remover = array_values(array_diff($atuais, [$userid]));
    $incluir = in_array($userid, $atuais, true) ? [] : [$userid];

    if (DRY_RUN) {
        logMsg("DRY: [$teamName] $shiftLabel ($planDate) → userid $userid"
             . ($remover ? ' | sairiam: ' . implode(',', $remover) : '')
             . ($incluir ? ' | entraria: ' . implode(',', $incluir) : ''));
        continue;
    }

    try {
        // INSERT ANTES do DELETE, de propósito. Na virada de turno o caminho é
        // sempre "sai o antigo, entra o novo"; fazendo o DELETE primeiro, o
        // grupo fica sem ninguém entre as duas escritas — e se o INSERT
        // falhasse, ficaria vazio até o próximo ciclo, 5 minutos de Ação sem
        // destinatário. Invertido, o pior caso é o grupo ficar com duas
        // pessoas por um ciclo: alerta a mais, nunca alerta a menos.
        //
        // Não há transação em volta porque reserveIds() abre a sua própria, e
        // em MySQL START TRANSACTION comita implicitamente a que estiver aberta.
        if ($incluir) {
            // id vem da tabela `ids` do Zabbix, nunca de MAX(id)+1 — ver
            // reserveIds() para o porquê.
            $firstId = reserveIds($db, 'users_groups', 'id', count($incluir));
            $stmt = $db->prepare('INSERT INTO users_groups (id, usrgrpid, userid) VALUES (?, ?, ?)');
            foreach ($incluir as $i => $uid) {
                $newId = $firstId + $i;
                $stmt->bind_param('iii', $newId, $targetGrpId, $uid);
                $stmt->execute();
            }
            $stmt->close();
        }

        if ($remover) {
            $stmt = $db->prepare('DELETE FROM users_groups WHERE usrgrpid = ? AND userid = ?');
            foreach ($remover as $uid) {
                $stmt->bind_param('ii', $targetGrpId, $uid);
                $stmt->execute();
            }
            $stmt->close();
        }

        logMsg("OK: [$teamName] $shiftLabel ($planDate) → userid $userid"
             . ($remover ? ' | removidos: ' . implode(',', $remover) : ''));
        $sincronizados++;
    } catch (Throwable $e) {
        // Uma equipe com problema não pode impedir a sincronização das outras.
        logMsg("WARN: [$teamName] falha ao sincronizar \"$groupName\": " . $e->getMessage());
        $erros++;
    }
}

logMsg("Resumo: {$sincronizados} sincronizada(s), {$sem_mudanca} já correta(s), "
     . "{$sem_cobertura} sem cobertura, {$erros} com erro.");

$db->close();
logMsg('=== Sync On-Call End ===');

// Código de saída != 0 quando houve erro, para dar o que monitorar.
exit($erros > 0 ? 1 : 0);
