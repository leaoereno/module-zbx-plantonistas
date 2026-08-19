<?php

namespace Modules\Plantonistas\Actions;

/**
 * ZbxAlertSender — envia uma mensagem avulsa pelo media type do usuário.
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * A menção no Diário de Bordo só aparecia no banner da tela do Repasse: quem
 * fosse mencionado às 3h da manhã e estivesse em outra tela (ou nem logado)
 * não ficava sabendo (issue #6). O objetivo é usar o media type que a pessoa
 * já configurou — e-mail, Telegram, webhook — em vez de o módulo montar um
 * envio próprio.
 *
 * ── A rota, e por que é esta ─────────────────────────────────────────────
 *
 * Inserir em `alerts` NÃO serve: `actionid` e `eventid` são NOT NULL com FK
 * ON DELETE CASCADE (seria preciso "emprestar" uma Ação e um evento reais, que
 * o housekeeper apaga depois, levando a linha junto), e o `alertid` é alocado
 * por um contador em memória do server, não pela tabela `ids` que o frontend
 * usa — dois alocadores para a mesma coluna, a mesma classe de incidente do
 * `role_rule`/`fcorr` (ver CLAUDE.md). Não existe task de "enviar mensagem" e
 * a API JSON-RPC só expõe `alert.get`.
 *
 * O que existe é o request de socket **`alert.send`**, exposto no frontend por
 * `CZabbixServer::testMediaType()` — a mesma via do botão "Test" em Alertas →
 * Tipos de mídia. Vai direto ao alerter, é síncrono e não escreve em tabela
 * nenhuma do core.
 *
 * ── As duas contrapartidas ───────────────────────────────────────────────
 *
 * 1. **Exige Super Admin**, validado no server (`trapper_process_alert_send`),
 *    aceitando session id de 32 chars ou token de API de 64. Como quem escreve
 *    a nota normalmente é analista, a sessão dele não serve: é preciso um
 *    token de API de Super Admin, lido da env `PLANTONISTAS_ALERT_TOKEN`.
 *    **Sem o token o módulo simplesmente não notifica** — o banner na tela
 *    continua funcionando e nada quebra.
 * 2. **A janela de notificação não vem de graça.** Quem avalia `media.period`
 *    e `media.active` é o escalonador, ao criar a linha em `alerts`; o
 *    `alert.send` recebe `sendto`/`mediatypeid` prontos e manda. Por isso o
 *    filtro está aqui, em PHP.
 *
 * `media.severity` é bitmask de severidade de trigger e NÃO é aplicado: uma
 * menção não tem severidade, e descartar por esse campo silenciaria a
 * notificação por um critério que não diz respeito a ela.
 *
 * ── Estabilidade ─────────────────────────────────────────────────────────
 *
 * `alert.send` é uma rota cuja finalidade declarada no código do Zabbix é
 * *testar* media type. Funciona, mas não é API pública documentada e pode
 * mudar de assinatura entre versões maiores — por isso está isolada nesta
 * classe, do mesmo jeito que o ZbxDb isolou o mysqli.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class ZbxAlertSender {

    /** Nome da env (pool do PHP-FPM) com o token de API de Super Admin. */
    public const TOKEN_ENV = 'PLANTONISTAS_ALERT_TOKEN';

    /**
     * Timeouts CURTOS e próprios, em vez dos do Zabbix.
     *
     * O envio é síncrono e acontece durante o save da nota — o analista está
     * esperando. Os defaults do Zabbix para o teste de media type são 3s de
     * conexão e **65s** de leitura, pensados para um operador que clicou em
     * "Test" e sabe que vai esperar. Herdar esses 65s aqui significaria travar
     * o save por mais de um minuto POR MÍDIA quando o server não responde.
     */
    private const CONNECT_TIMEOUT = 2;
    private const READ_TIMEOUT    = 5;

    /**
     * Teto de envios por save.
     *
     * Uma nota pode mencionar várias pessoas, cada uma com várias mídias, e
     * cada envio é um socket novo (o CZabbixServer não reusa conexão). Sem
     * teto, uma nota que menciona 10 pessoas viraria 20 sockets em série. O
     * que passar do teto fica só no banner da tela, que continua funcionando.
     */
    private const MAX_ENVIOS = 5;

    /** Envios já feitos neste request — o teto é por save, não por usuário. */
    private static int $enviados = 0;

    /**
     * Notifica um usuário por todas as mídias dele que estejam elegíveis agora.
     *
     * Nunca lança: notificação é acessório e não pode derrubar quem chamou
     * (regra do módulo, mesma decisão da menção inválida no save da nota).
     *
     * @return array ['sent' => int, 'skipped' => int, 'reason' => ?string]
     */
    public static function notifyUser(ZbxDb $db, int $userid, string $subject,
                                      string $message): array {
        $out = ['sent' => 0, 'skipped' => 0, 'reason' => null];

        $token = (string) getenv(self::TOKEN_ENV);
        if ($token === '') {
            $out['reason'] = 'token-ausente';
            return $out;   // silencioso de propósito — ver o cabeçalho
        }

        try {
            $medias = self::userMedias($db, $userid);
        } catch (\Throwable $e) {
            error_log('[plantonistas] ZbxAlertSender: falha ao ler as mídias do usuário '
                . $userid . ': ' . $e->getMessage());
            $out['reason'] = 'erro-consulta';
            return $out;
        }

        if (!$medias) {
            $out['reason'] = 'sem-midia';
            return $out;
        }

        $now = time();
        $tz     = self::userTimezone($db, $userid);
        $tentou = false;

        foreach ($medias as $m) {
            if (!self::isEligible($m, $now, $tz)) {
                $out['skipped']++;
                continue;
            }

            if (self::$enviados >= self::MAX_ENVIOS) {
                $out['skipped']++;
                $out['reason'] = 'teto-de-envios';
                continue;
            }

            $tentou = true;
            self::$enviados++;

            if (self::sendOne($m, $subject, $message, $token)) {
                $out['sent']++;
            }
            else {
                $out['skipped']++;
            }
        }

        // Distinguir "não havia mídia elegível agora" de "tentou e falhou" —
        // logar a primeira quando foi a segunda manda quem investiga procurar
        // no lugar errado.
        if ($out['sent'] === 0 && $out['reason'] === null) {
            $out['reason'] = $tentou ? 'falha-envio' : 'fora-da-janela';
        }

        return $out;
    }

    /**
     * Mídias habilitadas do usuário, com o tipo de mídia também habilitado.
     *
     * `media.active` e `media_type.status` usam 0 = habilitado (invertido em
     * relação ao que o nome sugere).
     */
    private static function userMedias(ZbxDb $db, int $userid): array {
        // mt.type IN (0,2,4) = e-mail, SMS e webhook. O tipo 1 (Script) fica
        // de fora de propósito: o `alert.send` espera os parâmetros dele num
        // formato diferente (lista plana de valores, sem sendto/subject), e
        // mandar no formato errado falharia só no log.
        $stmt = $db->prepare(
            'SELECT m.mediatypeid, m.sendto, m.period, mt.type, mt.name
               FROM media m
               INNER JOIN media_type mt ON mt.mediatypeid = m.mediatypeid
              WHERE m.userid = ? AND m.active = 0 AND mt.status = 0
                AND mt.type IN (0, 2, 4)'
        );
        $stmt->bind_param('i', $userid);
        $stmt->execute();

        return $stmt->get_result()->fetch_all();
    }

    /** A mídia está dentro da janela configurada agora? */
    private static function isEligible(array $media, int $now, string $tz): bool {
        return self::matchesPeriod((string) ($media['period'] ?? ''), $now, $tz);
    }

    /**
     * Fuso do DESTINATÁRIO (users.timezone), não o de quem escreveu a nota.
     *
     * A janela de notificação é dele. No frontend o timezone do PHP é o do
     * usuário logado — o autor —, então avaliar "são 9h da manhã?" sem trocar
     * o fuso deslocaria a janela de um colega em outro fuso. É a mesma classe
     * de bug do heatmap e do cron de presença, num terceiro lugar.
     *
     * 'system'/'default'/vazio significam "usa o do sistema", que é o que o
     * PHP já está usando — devolver string vazia mantém o comportamento atual.
     */
    private static function userTimezone(ZbxDb $db, int $userid): string {
        try {
            $stmt = $db->prepare('SELECT timezone FROM users WHERE userid = ?');
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            $tz = trim((string) ($row['timezone'] ?? ''));

            if ($tz === '' || $tz === 'system' || $tz === 'default') {
                return '';
            }

            return in_array($tz, timezone_identifiers_list(), true) ? $tz : '';
        } catch (\Throwable $e) {
            error_log('[plantonistas] ZbxAlertSender: falha ao ler o fuso do usuário '
                . $userid . ': ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Replica o `zbx_check_time_period()` do server para o formato de período
     * do Zabbix: `d-d,hh:mm-hh:mm` separado por `;`.
     *
     * Ex.: `1-5,09:00-18:00;6-7,10:00-16:00` — segunda a sexta das 9h às 18h,
     * fim de semana das 10h às 16h. `1` é segunda e `7` é domingo (o `w` do
     * PHP devolve 0 para domingo, daí o ajuste).
     *
     * Período vazio conta como "sempre". Uma faixa fora do formato é ignorada
     * (as outras ainda valem); se NENHUMA faixa for legível, também vale
     * "sempre" — o lado seguro de errar aqui é notificar a mais, não silenciar
     * o aviso de alguém que foi mencionado.
     */
    public static function matchesPeriod(string $period, int $now, string $tz = ''): bool {
        $period = trim($period);
        if ($period === '') {
            return true;
        }

        try {
            $dt = new \DateTime('@' . $now);
            $dt->setTimezone(new \DateTimeZone($tz !== '' ? $tz : date_default_timezone_get()));
            $dow = (int) $dt->format('N');           // 1 = segunda ... 7 = domingo
            $min = (int) $dt->format('G') * 60 + (int) $dt->format('i');
        } catch (\Throwable $e) {
            $dow = (int) date('w', $now);
            $dow = ($dow === 0) ? 7 : $dow;          // domingo: 0 → 7
            $min = (int) date('G', $now) * 60 + (int) date('i', $now);
        }

        $lidas = 0;

        foreach (explode(';', $period) as $faixa) {
            $faixa = trim($faixa);
            if ($faixa === '') {
                continue;
            }

            if (!preg_match('/^(\d)-(\d),(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $faixa, $m)) {
                continue;   // faixa fora do formato: ignora esta, tenta as outras
            }
            $lidas++;

            [$_, $d1, $d2, $h1, $i1, $h2, $i2] = $m;

            if ($dow < (int) $d1 || $dow > (int) $d2) {
                continue;
            }

            $ini = (int) $h1 * 60 + (int) $i1;
            $fim = (int) $h2 * 60 + (int) $i2;       // 24:00 vira 1440, e funciona

            if ($min >= $ini && $min < $fim) {
                return true;
            }
        }

        if ($lidas === 0) {
            error_log('[plantonistas] ZbxAlertSender: período de notificação em formato'
                . ' desconhecido ("' . $period . '") — tratado como "sempre".');
            return true;
        }

        return false;
    }

    /**
     * Dispara uma mensagem por uma mídia, via request `alert.send`.
     *
     * @return bool true se o server aceitou
     */
    private static function sendOne(array $media, string $subject,
                                    string $message, string $token): bool {
        global $ZBX_SERVER, $ZBX_SERVER_PORT;

        if (empty($ZBX_SERVER)) {
            error_log('[plantonistas] ZbxAlertSender: $ZBX_SERVER não configurado no'
                . ' zabbix.conf.php — sem isso o frontend não fala com o server.');
            return false;
        }

        try {
            // Timeouts próprios (ver as constantes) e não os do Zabbix, que
            // são pensados para o botão "Test". O 5º argumento é o teto de
            // bytes da resposta: 0 desliga a checagem, então vai a constante
            // do próprio Zabbix.
            $server = new \CZabbixServer(
                $ZBX_SERVER,
                $ZBX_SERVER_PORT,
                self::CONNECT_TIMEOUT,
                self::READ_TIMEOUT,
                defined('ZBX_SOCKET_BYTES_LIMIT') ? ZBX_SOCKET_BYTES_LIMIT : 1048576
            );

            $params = [
                'mediatypeid' => (string) $media['mediatypeid'],
                'sendto'      => (string) $media['sendto'],
                'subject'     => $subject,
                'message'     => $message,
            ];

            // Webhook (type 4) é executado com os parâmetros do próprio media
            // type; o request precisa carregá-los, senão o script roda sem
            // nada e falha do lado do server.
            if ((int) $media['type'] === 4) {
                $params['parameters'] = self::webhookParams(
                    (int) $media['mediatypeid'], (string) $media['sendto'], $subject, $message
                );
            }

            $result = $server->testMediaType($params, $token);

            if ($result === false) {
                error_log('[plantonistas] ZbxAlertSender: o Zabbix server recusou o envio por "'
                    . ($media['name'] ?? '?') . '": ' . $server->getError());
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            error_log('[plantonistas] ZbxAlertSender: exceção ao enviar por "'
                . ($media['name'] ?? '?') . '": ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Parâmetros configurados no media type de webhook, no formato que o
     * `alert.send` espera.
     *
     * Duas armadilhas, as duas confirmadas no controller nativo
     * (CControllerMediatypeTestSend):
     *
     * 1. É um **mapa** `nome => valor`, não uma lista de `{name, value}`. No
     *    formato errado o server não acha nenhum parâmetro e o webhook roda
     *    vazio.
     * 2. As macros `{ALERT.SENDTO}` / `{ALERT.SUBJECT}` / `{ALERT.MESSAGE}`
     *    **não são expandidas** por esta rota — quem expande é o alert manager,
     *    ao montar o alerta a partir de uma Ação. Na tela de teste é o operador
     *    que digita o valor real no lugar da macro. Aqui a substituição é
     *    nossa; sem ela, o Telegram receberia literalmente "{ALERT.SENDTO}"
     *    como destinatário.
     *
     * @return array|\stdClass mapa de parâmetros; objeto vazio quando não há
     *         nenhum (array vazio viraria `[]` no JSON, e o server espera `{}`)
     */
    private static function webhookParams(int $mediatypeid, string $sendto,
                                          string $subject, string $message) {
        $params = [];
        $macros = [
            '{ALERT.SENDTO}'  => $sendto,
            '{ALERT.SUBJECT}' => $subject,
            '{ALERT.MESSAGE}' => $message,
        ];

        try {
            $res = \DBselect(
                'SELECT name, value FROM media_type_param WHERE mediatypeid='
                . \zbx_dbstr((string) $mediatypeid)
            );
            while ($row = \DBfetch($res, false)) {
                $params[$row['name']] = strtr((string) $row['value'], $macros);
            }
        } catch (\Throwable $e) {
            error_log('[plantonistas] ZbxAlertSender: falha ao ler parâmetros do webhook '
                . $mediatypeid . ': ' . $e->getMessage());
        }

        return $params === [] ? new \stdClass() : $params;
    }
}
