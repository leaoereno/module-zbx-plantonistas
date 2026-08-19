<?php

/**
 * ZbxServerClient — fala com o Zabbix server por socket, a partir do CLI.
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * A notificação de menção (issue #6) usa o request `alert.send`, que é a via
 * oficial frontend→server para mandar uma mensagem avulsa por um media type —
 * a mesma do botão "Test" em Alertas → Tipos de mídia. No frontend isso sai
 * pronto em `CZabbixServer::testMediaType()`, mas essa é **classe do
 * frontend**: não existe em CLI. Como a fila roda em cron (para o envio não
 * travar o save da nota), o protocolo precisou ser falado aqui.
 *
 * ── O protocolo ──────────────────────────────────────────────────────────
 *
 * É simples e estável desde o Zabbix 3.0:
 *
 *   ZBXD  (4 bytes)  — assinatura
 *   0x01  (1 byte)   — versão do protocolo
 *   len   (8 bytes)  — tamanho do payload, inteiro little-endian
 *   json  (len)      — o payload
 *
 * A resposta vem no mesmo formato. O `alert.send` responde
 * `{"response":"success"|"failed", "info":"..."}`.
 *
 * ── Permissão ────────────────────────────────────────────────────────────
 *
 * O server exige **Super Admin** neste request, validado por ele mesmo
 * (`trapper_process_alert_send`). O campo `sid` aceita session id de 32
 * caracteres ou token de API de 64 — daqui vai sempre um token de API, na env
 * `PLANTONISTAS_ALERT_TOKEN`.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class ZbxServerClient {

    private string $host;
    private int    $port;
    private int    $connectTimeout;
    private int    $readTimeout;

    /** Teto da resposta, para uma resposta absurda não estourar a memória. */
    private const MAX_RESPONSE = 8 * 1024 * 1024;

    public function __construct(string $host, int $port = 10051,
                                int $connectTimeout = 5, int $readTimeout = 30) {
        $this->host           = $host;
        $this->port           = $port;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout    = $readTimeout;
    }

    /**
     * Envia uma mensagem por um media type.
     *
     * @param array $params mediatypeid, sendto, subject, message e, para
     *                      webhook, `parameters` como MAPA nome => valor
     * @return array ['ok' => bool, 'info' => string]
     */
    public function alertSend(array $params, string $token): array {
        return $this->request([
            'request' => 'alert.send',
            'sid'     => $token,
            'data'    => $params,
        ]);
    }

    /** @return array ['ok' => bool, 'info' => string] */
    private function request(array $payload): array {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'info' => 'json_encode falhou: ' . json_last_error_msg()];
        }

        $erro = 0;
        $msg  = '';
        $sock = @stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $erro, $msg, $this->connectTimeout
        );

        if ($sock === false) {
            return ['ok' => false, 'info' => "não conectou em {$this->host}:{$this->port} — $msg"];
        }

        stream_set_timeout($sock, $this->readTimeout);

        try {
            // ZBXD + versão 1 + tamanho em 8 bytes little-endian.
            // 'P' (uint64 LE) exige PHP 64 bits, que é o caso de qualquer
            // frontend Zabbix moderno.
            $pacote = "ZBXD\x01" . pack('P', strlen($json)) . $json;

            if (@fwrite($sock, $pacote) === false) {
                return ['ok' => false, 'info' => 'falha ao escrever no socket'];
            }

            $cab = $this->readExactly($sock, 13);
            if ($cab === null || substr($cab, 0, 4) !== 'ZBXD') {
                return ['ok' => false, 'info' => 'resposta sem o cabeçalho ZBXD (timeout?)'];
            }

            $len = unpack('P', substr($cab, 5, 8))[1];
            if ($len <= 0 || $len > self::MAX_RESPONSE) {
                return ['ok' => false, 'info' => 'tamanho de resposta inválido: ' . $len];
            }

            $corpo = $this->readExactly($sock, $len);
            if ($corpo === null) {
                return ['ok' => false, 'info' => 'resposta truncada'];
            }

            $resp = json_decode($corpo, true);
            if (!is_array($resp)) {
                return ['ok' => false, 'info' => 'resposta não é JSON: ' . substr($corpo, 0, 200)];
            }

            return [
                'ok'   => (($resp['response'] ?? '') === 'success'),
                'info' => (string) ($resp['info'] ?? ''),
            ];
        } finally {
            @fclose($sock);
        }
    }

    /**
     * Lê exatamente N bytes.
     *
     * `fread()` pode devolver menos do que foi pedido mesmo sem erro — em
     * socket isso é o normal, não a exceção. Ler uma vez só e assumir que veio
     * tudo é o erro clássico deste tipo de código.
     */
    private function readExactly($sock, int $n): ?string {
        $buf = '';

        while (strlen($buf) < $n) {
            $pedaco = @fread($sock, $n - strlen($buf));

            if ($pedaco === false || $pedaco === '') {
                $meta = stream_get_meta_data($sock);
                if (!empty($meta['timed_out']) || feof($sock)) {
                    return null;
                }
                // Leitura vazia sem timeout e sem fim de arquivo é raríssima
                // num socket bloqueante, mas sem a pausa o `continue` viraria
                // um laço queimando CPU até o timeout do stream fechar a porta.
                usleep(1000);
                continue;
            }

            $buf .= $pedaco;
        }

        return $buf;
    }
}
