<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CControllerResponseRedirect, CUrl;

/**
 * AjaxRedirect — uma action de escrita que responde redirect no navegador e
 * JSON quando chamada por fetch.
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * As telas de Escala e Telefones passaram a enviar por `fetch` para não perder
 * o que foi digitado quando o token CSRF expira. Só que as actions continuavam
 * respondendo **redirect**, e a única forma de o JS saber se deu certo era
 * seguir esse redirect — o que faz o navegador BAIXAR a página de destino
 * inteira e jogar fora, para em seguida baixá-la de novo no `location.href`.
 * Dois renders e duas rodadas de consulta por salvamento.
 *
 * Com JSON o caminho fica: uma resposta curta, e o `location.href` só quando
 * realmente há algo novo para ver. Erro de validação nem recarrega — a
 * mensagem aparece ao lado do formulário, com tudo ainda preenchido.
 *
 * ── Como o modo é escolhido ──────────────────────────────────────────────
 *
 * Por um **campo do POST** (`plt_ajax=1`), não pelo cabeçalho
 * `X-Requested-With`. A diferença importa neste ambiente: o frontend fica
 * atrás de um F5 BIG-IP, e proxy/WAF removem ou reescrevem cabeçalho com
 * facilidade — corpo de POST, não.
 *
 * E o modo de falha do cabeçalho seria péssimo: sem ele a action responderia
 * 302, o `fetch` seguiria o redirect, viria HTML, e o JS diria "sessão
 * expirada, nada foi salvo" para uma operação que **deu certo**. Em
 * `plantonistas.delete` isso vira "Entrada não encontrada" logo depois de uma
 * remoção bem-sucedida, e no import vira histórico duplicado por reenvio.
 *
 * O cabeçalho continua sendo aceito como segunda via. Sem nenhum dos dois —
 * link colado no navegador, formulário sem JS, `curl` — a action responde
 * redirect como sempre respondeu.
 *
 * ── O que este trait NÃO cobre ───────────────────────────────────────────
 *
 * Falha de CSRF acontece **antes** do `doAction()`, no roteador do Zabbix, que
 * responde a página HTML "Acesso negado" — inclusive para request AJAX. Por
 * isso o JS continua obrigado a conferir o `content-type` antes de chamar
 * `r.json()`; sem isso o usuário veria "erro de conexão" no lugar de "recarregue
 * a página".
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
trait AjaxRedirect {

    /** A requisição veio do `fetch` das telas do módulo? */
    private function isAjaxRequest(): bool {
        if (($_POST['plt_ajax'] ?? '') === '1') {
            return true;
        }

        // Segunda via, para o caso de o campo se perder num refactor do JS.
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))
            === 'xmlhttprequest';
    }

    /**
     * Sucesso: JSON com a URL de destino, ou o redirect de sempre.
     *
     * O JS decide se navega. Salvar uma escala muda o calendário, então ele
     * navega; se um dia houver uma escrita que não muda a tela, dá para só
     * exibir a mensagem sem recarregar nada.
     */
    private function respondOk(CUrl $redirect, string $msg): void {
        $destino = (clone $redirect)->setArgument('success', $msg);

        if ($this->isAjaxRequest()) {
            $this->respondJson([
                'success'  => true,
                'message'  => $msg,
                'redirect' => $destino->getUrl(),
            ]);
            return;
        }

        $this->setResponse(new CControllerResponseRedirect($destino));
    }

    /**
     * Deu certo em parte: gravou alguma coisa E tem aviso a mostrar.
     *
     * É o desfecho MAIS COMUM de uma importação real ("30 linhas importadas.
     * Avisos (3): ..."). Tratá-lo como erro puro seria regressão: a tela não
     * recarregaria, as linhas gravadas ficariam invisíveis até um F5 manual, e
     * a importação teria cara de ter falhado inteira. Por isso vai `redirect`
     * junto — a mensagem entra como `error` na URL, exatamente como antes, e o
     * banner aparece vermelho sobre a tela já atualizada.
     */
    private function respondPartial(CUrl $redirect, string $msg): void {
        $destino = (clone $redirect)->setArgument('error', $msg);

        if ($this->isAjaxRequest()) {
            $this->respondJson([
                'success'  => false,
                'warning'  => true,
                'message'  => $msg,
                'redirect' => $destino->getUrl(),
            ]);
            return;
        }

        $this->setResponse(new CControllerResponseRedirect($destino));
    }

    /**
     * Erro de validação ou permissão.
     *
     * Em AJAX **não** vai URL de destino: recarregar a tela para mostrar um
     * erro é o que fazia o operador perder o que tinha preenchido. A mensagem
     * aparece ao lado do formulário e ele corrige.
     */
    private function respondErr(CUrl $redirect, string $msg): void {
        if ($this->isAjaxRequest()) {
            $this->respondJson(['success' => false, 'message' => $msg]);
            return;
        }

        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('error', $msg)
        ));
    }

    /**
     * Emite o JSON e encerra.
     *
     * `die()` porque estas actions não têm `"view"` no manifest (respondem
     * redirect) — deixar o fluxo seguir faria o Zabbix procurar uma view que
     * não existe. É o mesmo padrão das actions AJAX do Repasse.
     */
    private function respondJson(array $payload): void {
        // JSON_INVALID_UTF8_SUBSTITUTE não é zelo excessivo: as mensagens de
        // erro do import ecoam bytes CRUS do arquivo enviado (cabeçalho lido,
        // nome do técnico não encontrado), e o leitor de CSV não converte
        // encoding. Planilha salva como ANSI no Excel pt-BR — o público exato
        // desta tela — é Latin-1 puro. Sem a flag, json_encode devolveria
        // `false`, o `echo false` imprimiria string VAZIA com content-type de
        // JSON, e o usuário veria "erro de conexão" para algo que gravou.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            // A flag cobre encoding, não todo erro possível (recursão,
            // profundidade). Payload de reserva, sem o texto original.
            $json = json_encode([
                'success' => false,
                'message' => 'A operação respondeu, mas a mensagem não pôde ser '
                           . 'codificada. Recarregue a página para ver o resultado.',
            ]);
        }

        // Um warning do PHP escapando antes daqui deixaria o content-type em
        // text/html, e o JS interpretaria como "sessão expirada".
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        echo $json;
        die();
    }
}
