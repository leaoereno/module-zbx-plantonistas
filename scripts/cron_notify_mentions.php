<?php
/**
 * cron_notify_mentions.php
 *
 * Avisa, pelo media type que a pessoa já configurou no Zabbix, quem foi
 * mencionado com @ no Diário de Bordo (issue #6).
 *
 * ── Por que é cron, e não no save da nota ────────────────────────────────
 *
 * A primeira versão enviava direto no `recordMentions()`, durante o save. Cada
 * envio abre um socket para o Zabbix server, e uma nota que menciona várias
 * pessoas com várias mídias vira uma fila de sockets em série — com o analista
 * esperando a tela responder. Havia teto de 5 envios e timeout curto
 * justamente para limitar o estrago, o que significa que parte das
 * notificações simplesmente não saía.
 *
 * Aqui não há ninguém esperando: a nota é gravada na hora, o banner da tela
 * aparece na hora, e o aviso sai no próximo ciclo. Sem teto e sem pressa.
 *
 * ── Dedupe ───────────────────────────────────────────────────────────────
 *
 * Duas regras, as duas pensadas para não transformar menção em spam:
 *
 * 1. **Uma notificação por pessoa por ciclo.** Dez menções à mesma pessoa
 *    viram um aviso que diz "10 menções", não dez e-mails.
 * 2. **Quem está online não é notificado.** A pessoa está com o Zabbix aberto
 *    e vai ver o banner do Repasse; mandar e-mail é redundante. Usa a tabela
 *    de presença, que já existe e é populada pelo outro cron. A menção
 *    continua marcada como notificada — se ela não olhar, o banner ainda está
 *    lá na próxima vez que abrir a tela.
 *
 * ── Configuração ─────────────────────────────────────────────────────────
 *
 * Além das env DB_* de sempre:
 *
 *   PLANTONISTAS_ALERT_TOKEN  token de API de um Super Admin (o `alert.send`
 *                             exige esse papel, validado pelo próprio server)
 *   ZBX_SERVER                host do Zabbix server (default 127.0.0.1)
 *   ZBX_SERVER_PORT           porta (default 10051)
 *   ZBX_FRONTEND_URL          URL do frontend, para o link no corpo da mensagem
 *
 * **Sem o token o script não faz nada e sai com 0** — é o estado normal de
 * quem não ligou o recurso, e o banner da tela continua funcionando.
 *
 * Cron sugerido (a cada 1 min — é aviso, quanto antes melhor):
 *   * * * * * php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_notify_mentions.php
 *
 * Rodar em UM frontend só: marca as menções como notificadas no banco
 * compartilhado, e em dois nós a mesma menção sairia duas vezes.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 *
 * Changelog:
 *   v1.0.0 - Primeira versão: fila do que era envio síncrono (issues #6 e #1).
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

define('DB_TYPE', strtolower((string)($GLOBALS['DB']['TYPE'] ?? getenv('DB_TYPE') ?: 'mysql')));
define('DB_HOST', $GLOBALS['DB']['SERVER']   ?? getenv('DB_HOST')   ?: 'localhost');
define('DB_PORT', (int)($GLOBALS['DB']['PORT'] ?? getenv('DB_PORT')
    ?: (in_array(DB_TYPE, ['pgsql', 'postgresql'], true) ? 5432 : 3306)));
define('DB_NAME', $GLOBALS['DB']['DATABASE'] ?? getenv('DB_NAME')   ?: 'zabbix');
define('DB_USER', $GLOBALS['DB']['USER']     ?? getenv('DB_USER')   ?: 'zabbix');
define('DB_PASS', $GLOBALS['DB']['PASSWORD'] ?? getenv('DB_PASS')   ?: '');

define('ALERT_TOKEN', (string) getenv('PLANTONISTAS_ALERT_TOKEN'));
define('ZBX_SERVER',      getenv('ZBX_SERVER')      ?: '127.0.0.1');
define('ZBX_SERVER_PORT', (int)(getenv('ZBX_SERVER_PORT') ?: 10051));
define('FRONTEND_URL',    rtrim((string) getenv('ZBX_FRONTEND_URL'), '/'));

/**
 * Idade máxima de uma menção para ainda valer aviso.
 *
 * Se o cron ficou parado uma semana, ninguém quer receber o acúmulo — e um
 * aviso de menção de terça-feira passada não ajuda em nada. O que passar disso
 * é marcado como notificado sem enviar.
 */
define('MAX_IDADE_HORAS', 24);

/** Minutos de presença que contam como "está online agora". */
define('ONLINE_MINUTOS', 15);

define('DRY_RUN', (bool) getenv('DRY_RUN'));

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/CliDb.php';
require_once __DIR__ . '/ZbxServerClient.php';

date_default_timezone_set('America/Sao_Paulo');

function logMsg(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
}

/**
 * A mídia está dentro da janela configurada pelo usuário?
 *
 * Formato do Zabbix: `d-d,hh:mm-hh:mm` separado por `;`. Quem avalia isso
 * normalmente é o escalonador, ao criar a linha em `alerts`; o `alert.send`
 * recebe destinatário e mídia prontos e manda. Então a regra é replicada aqui.
 *
 * Avaliada no fuso do DESTINATÁRIO (`users.timezone`) — a janela é dele.
 * Período vazio, ou ilegível por inteiro, conta como "sempre": o lado seguro
 * de errar é avisar a mais, não silenciar quem foi mencionado.
 */
function dentroDaJanela(string $period, int $now, string $tz): bool {
    $period = trim($period);
    if ($period === '') {
        return true;
    }

    try {
        $dt = new DateTime('@' . $now);
        $dt->setTimezone(new DateTimeZone($tz !== '' ? $tz : date_default_timezone_get()));
        $dow = (int) $dt->format('N');          // 1 = segunda ... 7 = domingo
        $min = (int) $dt->format('G') * 60 + (int) $dt->format('i');
    } catch (Throwable $e) {
        $dow = (int) date('N', $now);
        $min = (int) date('G', $now) * 60 + (int) date('i', $now);
    }

    $lidas = 0;

    foreach (explode(';', $period) as $faixa) {
        $faixa = trim($faixa);
        if ($faixa === '' || !preg_match('/^(\d)-(\d),(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $faixa, $m)) {
            continue;
        }
        $lidas++;

        [, $d1, $d2, $h1, $i1, $h2, $i2] = $m;
        if ($dow < (int) $d1 || $dow > (int) $d2) {
            continue;
        }
        if ($min >= (int) $h1 * 60 + (int) $i1 && $min < (int) $h2 * 60 + (int) $i2) {
            return true;
        }
    }

    return $lidas === 0;
}

// ── Início ────────────────────────────────────────────────────────────────────

logMsg('=== Notify Mentions Start ===' . (DRY_RUN ? ' (DRY RUN — nada será enviado nem marcado)' : ''));

if (ALERT_TOKEN === '') {
    // Silencioso de propósito: é o estado de quem não configurou o recurso,
    // e encher o log a cada minuto não ajudaria ninguém.
    logMsg('PLANTONISTAS_ALERT_TOKEN não configurado — notificação por media type desligada.');
    exit(0);
}

try {
    $db = new CliDb(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, DB_TYPE);
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao conectar no banco: ' . $e->getMessage());
    exit(1);
}

$now      = time();
$corte    = date('Y-m-d H:i:s', $now - MAX_IDADE_HORAS * 3600);
$online   = date('Y-m-d H:i:s', $now - ONLINE_MINUTOS * 60);
$agora    = date('Y-m-d H:i:s', $now);
$servidor = new ZbxServerClient(ZBX_SERVER, ZBX_SERVER_PORT);

// ── Fila: uma linha por PESSOA, não por menção ────────────────────────────────
//
// O GROUP BY é o dedupe da regra 1: dez menções à mesma pessoa viram um aviso.

try {
    $stmt = $db->prepare(
        "SELECT m.mentioned_userid,
                COUNT(*)  AS quantas,
                MAX(m.id) AS ultima_id
           FROM module_plantonistas_mentions m
          WHERE m.notified_at IS NULL
            AND m.created_at >= ?
          GROUP BY m.mentioned_userid"
    );
    $stmt->bind_param('s', $corte);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_all();
    $stmt->close();
} catch (Throwable $e) {
    logMsg('CRITICAL: Falha ao ler a fila de menções: ' . $e->getMessage());
    exit(1);
}

logMsg('Pessoas com menção pendente: ' . count($fila));

// Menções velhas demais saem da fila sem aviso — ver MAX_IDADE_HORAS.
if (!DRY_RUN) {
    try {
        $stmt = $db->prepare(
            'UPDATE module_plantonistas_mentions SET notified_at = ?
              WHERE notified_at IS NULL AND created_at < ?'
        );
        $stmt->bind_param('ss', $agora, $corte);
        $stmt->execute();
        $velhas = $stmt->affected_rows;
        $stmt->close();

        if ($velhas > 0) {
            logMsg("Descartadas $velhas menção(ões) com mais de " . MAX_IDADE_HORAS . 'h sem aviso.');
        }
    } catch (Throwable $e) {
        logMsg('WARN: Falha ao descartar menções antigas: ' . $e->getMessage());
    }
}

$enviados = 0;
$pulados  = 0;
$erros    = 0;

foreach ($fila as $item) {
    $uid     = (int) $item['mentioned_userid'];
    $quantas = (int) $item['quantas'];
    // Teto do que será marcado como notificado. Uma menção gravada DEPOIS
    // desta leitura tem id maior e fica para o próximo ciclo — sem o teto,
    // ela seria marcada sem nunca ter sido enviada e sumiria do e-mail.
    // Usa id, e não data, porque `created_at` vem do relógio do banco,
    // que pode estar em outro fuso (ver recordMentions no trait).
    $ate_id  = (int) $item['ultima_id'];

    try {
        // ── Dedupe 2: quem está online vê o banner, não precisa de e-mail ──
        $stmt = $db->prepare(
            'SELECT 1 FROM module_plantonistas_user_sessions
              WHERE userid = ? AND lastaccess >= ? LIMIT 1'
        );
        $stmt->bind_param('is', $uid, $online);
        $stmt->execute();
        $estaOnline = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        if ($estaOnline) {
            logMsg("  userid $uid está online — banner na tela dá conta, sem e-mail.");
            marcarNotificadas($db, $uid, $ate_id, $agora);
            $pulados++;
            continue;
        }

        // ── Dados do destinatário e mídias elegíveis ──────────────────────
        $stmt = $db->prepare(
            'SELECT username, name, surname, timezone FROM users WHERE userid = ?'
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($u === null) {
            logMsg("  WARN: userid $uid não existe mais — menções descartadas.");
            marcarNotificadas($db, $uid, $ate_id, $agora);
            $pulados++;
            continue;
        }

        $tz = trim((string) ($u['timezone'] ?? ''));
        if ($tz === 'system' || $tz === 'default') {
            $tz = '';
        }

        // mt.type IN (0,2,4) = e-mail, SMS e webhook. Script (1) fica de fora:
        // o alert.send espera os parâmetros dele em outro formato.
        $stmt = $db->prepare(
            'SELECT m.mediatypeid, m.sendto, m.period, mt.type, mt.name
               FROM media m
               INNER JOIN media_type mt ON mt.mediatypeid = m.mediatypeid
              WHERE m.userid = ? AND m.active = 0 AND mt.status = 0
                AND mt.type IN (0, 2, 4)'
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $midias = $stmt->get_result()->fetch_all();
        $stmt->close();

        if (!$midias) {
            logMsg("  userid $uid não tem mídia configurada — sem como avisar.");
            marcarNotificadas($db, $uid, $ate_id, $agora);
            $pulados++;
            continue;
        }

        // ── Mensagem ──────────────────────────────────────────────────────
        $assunto = $quantas === 1
            ? 'Você foi mencionado no Diário de Bordo'
            : "Você foi mencionado $quantas vezes no Diário de Bordo";

        $link = FRONTEND_URL !== ''
            ? FRONTEND_URL . '/zabbix.php?action=plantonistas.report.view'
            : '';

        $corpo = ($quantas === 1
                    ? "Você foi mencionado numa nota do Diário de Bordo do Repasse de Plantão.\n\n"
                    : "Você foi mencionado em $quantas notas do Diário de Bordo do Repasse de Plantão.\n\n")
               . ($link !== ''
                    ? "Abra o Repasse para ler: $link\n\n"
                    : "Abra o Repasse de Plantão no Zabbix para ler.\n\n")
               . '(Mensagem automática do módulo Plantonistas.)';

        $enviouAlguma = false;

        foreach ($midias as $md) {
            if (!dentroDaJanela((string) ($md['period'] ?? ''), $now, $tz)) {
                continue;
            }

            $params = [
                'mediatypeid' => (string) $md['mediatypeid'],
                'sendto'      => (string) $md['sendto'],
                'subject'     => $assunto,
                'message'     => $corpo,
            ];

            if ((int) $md['type'] === 4) {
                $params['parameters'] = webhookParams(
                    $db, (int) $md['mediatypeid'], (string) $md['sendto'], $assunto, $corpo
                );
            }

            if (DRY_RUN) {
                logMsg("  DRY: userid $uid via \"{$md['name']}\" → {$md['sendto']} ($quantas menção(ões))");
                $enviouAlguma = true;
                continue;
            }

            $r = $servidor->alertSend($params, ALERT_TOKEN);

            if ($r['ok']) {
                logMsg("  OK: userid $uid via \"{$md['name']}\" ($quantas menção(ões))");
                $enviouAlguma = true;
            }
            else {
                logMsg("  WARN: falha ao enviar para userid $uid via \"{$md['name']}\": " . $r['info']);
            }
        }

        if ($enviouAlguma) {
            $enviados++;
        }
        else {
            $pulados++;
        }

        // Marca em qualquer caso: fora da janela ou falha de envio, insistir a
        // cada minuto só geraria repetição. O banner da tela continua lá.
        marcarNotificadas($db, $uid, $ate_id, $agora);
    } catch (Throwable $e) {
        logMsg("  WARN: erro ao processar userid $uid: " . $e->getMessage());
        $erros++;
    }
}

logMsg("Resumo: {$enviados} avisado(s), {$pulados} pulado(s), {$erros} com erro.");

$db->close();
logMsg('=== Notify Mentions End ===');

exit($erros > 0 ? 1 : 0);

// ── Auxiliares ────────────────────────────────────────────────────────────────

/**
 * Tira da fila as menções pendentes de um usuário até `$ate_id`.
 *
 * O teto existe por causa da janela entre o SELECT da fila e este UPDATE:
 * sem ele, uma menção gravada nesse intervalo seria marcada como notificada
 * sem ter sido enviada, e a pessoa só teria o banner da tela.
 */
function marcarNotificadas(CliDb $db, int $uid, int $ate_id, string $agora): void {
    if (DRY_RUN) {
        return;
    }

    try {
        $stmt = $db->prepare(
            'UPDATE module_plantonistas_mentions SET notified_at = ?
              WHERE mentioned_userid = ? AND notified_at IS NULL AND id <= ?'
        );
        $stmt->bind_param('sii', $agora, $uid, $ate_id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Não marcar significa reenviar no próximo ciclo — ruim, mas melhor
        // que derrubar a execução das outras pessoas da fila.
        logMsg("  WARN: falha ao marcar menções do userid $uid como notificadas: " . $e->getMessage());
    }
}

/**
 * Parâmetros do webhook, no formato que o `alert.send` espera.
 *
 * Duas armadilhas: é um MAPA nome => valor (não lista de {name, value}), e as
 * macros {ALERT.*} NÃO são expandidas por esta rota — quem expande é o alert
 * manager, ao montar um alerta a partir de uma Ação. Sem substituir aqui, o
 * Telegram receberia literalmente "{ALERT.SENDTO}" como destinatário.
 */
function webhookParams(CliDb $db, int $mediatypeid, string $sendto,
                       string $assunto, string $corpo) {
    $params = [];
    $macros = [
        '{ALERT.SENDTO}'  => $sendto,
        '{ALERT.SUBJECT}' => $assunto,
        '{ALERT.MESSAGE}' => $corpo,
    ];

    try {
        $stmt = $db->prepare('SELECT name, value FROM media_type_param WHERE mediatypeid = ?');
        $stmt->bind_param('i', $mediatypeid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all() as $p) {
            $params[$p['name']] = strtr((string) $p['value'], $macros);
        }
        $stmt->close();
    } catch (Throwable $e) {
        logMsg("  WARN: falha ao ler parâmetros do webhook $mediatypeid: " . $e->getMessage());
    }

    // Array vazio viraria [] no JSON, e o server espera um objeto.
    return $params === [] ? new stdClass() : $params;
}
