<?php declare(strict_types = 1);
/**
 * _theme.php — paleta compartilhada das telas da família escala.
 *
 * ── O problema que isto resolve ──────────────────────────────────────────
 *
 * O módulo nasceu da união de dois módulos com visual próprio: a família
 * escala (Escala, Visão Geral, Histórico, Telefones) era ESCURA fixa, com
 * `#2b2b2b` e `#f2f2f2` escritos à mão em ~40 lugares; a família repasse era
 * CLARA, com detecção de tema escuro por JS. Resultado: quem usa o Zabbix no
 * tema claro via metade do menu Plantão escura, e quem usa no escuro via a
 * outra metade clara.
 *
 * Aqui as duas passam a seguir o **tema ativo do Zabbix**. As telas continuam
 * com prefixo CSS próprio (`plt-`, `ov-`, `phn-`, `rp-`) — o que unifica é a
 * paleta, não a estrutura. Trocar a cor de fundo de todas elas é mexer numa
 * linha só, aqui.
 *
 * ── Como o tema é detectado ──────────────────────────────────────────────
 *
 * Lendo a **luminância do fundo já renderizado** pelo Zabbix, não o nome do
 * arquivo de tema. É a mesma técnica que a família repasse já usava, e existe
 * porque o Zabbix referencia arquivos de tema alternativos no HTML mesmo com
 * outro tema ativo — procurar "dark-theme" no href dava falso positivo.
 * Funciona com Blue, Dark, High-contrast e tema customizado da empresa.
 *
 * A detecção roda ANTES do CSS ser aplicado visualmente porque acrescenta a
 * classe no `<html>`, e o seletor `.plt-dark` cai em cascata sobre tudo que
 * vem depois. Sem `document.body` pronto o `getComputedStyle` devolveria
 * vazio, então o bloco fica no corpo da página, não no `<head>`.
 *
 * ── Uso ──────────────────────────────────────────────────────────────────
 *
 *     <?php include __DIR__ . '/_theme.php'; ?>
 *
 * uma vez por view, ANTES do `<style>` da tela.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
?>
<script>
// Detecção de tema. Roda uma vez por página; o `if` evita reprocessar quando
// duas telas do módulo forem incluídas na mesma requisição.
(function () {
    if (document.documentElement.hasAttribute('data-plt-theme')) return;

    var escuro = false;
    try {
        var bg  = getComputedStyle(document.body).backgroundColor;
        var rgb = bg.match(/\d+/g);
        if (rgb && rgb.length >= 3) {
            var r = +rgb[0], g = +rgb[1], b = +rgb[2];
            // Luminância percebida (coeficientes ITU-R BT.601). Abaixo de 0.5
            // o fundo é escuro o bastante para o texto precisar ser claro.
            escuro = (0.299 * r + 0.587 * g + 0.114 * b) / 255 < 0.5;
        }
    } catch (e) {
        // Sem acesso ao body (ordem de carregamento inesperada): fica no tema
        // claro, que é o padrão do Zabbix.
    }

    document.documentElement.setAttribute('data-plt-theme', escuro ? 'dark' : 'light');
    if (escuro) {
        document.documentElement.classList.add('plt-dark');
        // A família repasse já usava estes dois marcadores; mantidos para o
        // CSS dela continuar valendo sem ser reescrito.
        document.documentElement.setAttribute('data-theme', 'dark-theme');
        document.body.classList.add('theme-dark-blue');
    }
})();
</script>
<style>
/* ── Paleta — tema claro (padrão do Zabbix) ─────────────────────────────
   Nomes por FUNÇÃO, não por cor: `--plt-surface`, não `--plt-cinza-escuro`.
   Um nome de cor vira mentira assim que o tema inverte. */
:root {
    --plt-bg:            #ffffff;   /* fundo de célula / linha            */
    --plt-bg-alt:        #f7f9fa;   /* faixa alternada, célula vazia      */
    --plt-bg-hover:      #eef3f7;
    --plt-panel:         #f2f5f7;   /* painel de formulário, cabeçalho    */
    --plt-panel-alt:     #e7edf1;

    --plt-border:        #d6dee4;
    --plt-border-strong: #b9c6cf;

    --plt-text:          #1f2d38;   /* texto principal                    */
    --plt-text-soft:     #46606f;   /* rótulo, cabeçalho de tabela        */
    --plt-text-muted:    #7b8d99;   /* auxiliar, "sem telefone"           */
    /* Contraste 4.6:1 sobre o branco. O tom mais claro que parecia natural
       (#9fb0ba) dava 2.2:1, e esta variável não é só enfeite — carrega
       "sem telefone", "Nenhum histórico registrado" e o "—" das células. */
    --plt-text-faint:    #6f8391;   /* auxiliar de baixa ênfase            */

    --plt-accent:        #0275b8;   /* seleção, link                      */
    --plt-accent-bg:     #e3f0fa;
    --plt-ok:            #1f7a3f;   /* plantonista escalado               */
    --plt-ok-bg:         #e6f4ea;
    --plt-ok-border:     #b7dfc4;
    --plt-warn:          #a35c00;   /* hoje, sem cobertura                */
    --plt-warn-bg:       #fff4e0;
    --plt-warn-border:   #f0d3a0;
    --plt-danger:        #c62828;   /* remover, erro                      */
    --plt-danger-bg:     #fdecec;
    --plt-danger-border: #f2bcbc;
    --plt-tag-bg:        #e8f1f8;   /* chip de grupo                      */
    --plt-tag-text:      #2b5c7d;
    --plt-tag-border:    #c3d9e8;
}

/* ── Paleta — tema escuro ───────────────────────────────────────────────
   Valores herdados do visual original da família escala, que era o padrão
   antes desta unificação: quem já usa o Zabbix no escuro não vê diferença. */
.plt-dark {
    --plt-bg:            #2b2b2b;
    --plt-bg-alt:        #222222;
    --plt-bg-hover:      #383838;
    --plt-panel:         #303030;
    --plt-panel-alt:     #262626;

    --plt-border:        #4f4f4f;
    --plt-border-strong: #5a5a5a;

    --plt-text:          #f2f2f2;
    --plt-text-soft:     #c8d8e4;
    --plt-text-muted:    #8faabc;
    --plt-text-faint:    #666666;

    --plt-accent:        #4a82c4;
    --plt-accent-bg:     #1a2d4a;
    --plt-ok:            #59db8f;
    --plt-ok-bg:         #162616;
    --plt-ok-border:     #2a5a2a;
    --plt-warn:          #e99003;
    --plt-warn-bg:       #2e2200;
    --plt-warn-border:   #7a4400;
    --plt-danger:        #e45959;
    --plt-danger-bg:     #2a1010;
    --plt-danger-border: #5a2a2a;
    --plt-tag-bg:        #1a2a3a;
    --plt-tag-text:      #8faabc;
    --plt-tag-border:    #2a4a6a;
}
</style>
