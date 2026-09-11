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
   Alinhada ao tema escuro do PRÓPRIO Zabbix (assets/styles/dark-theme.css):
   superfície de tabela/painel #2b2b2b, hover de linha #383838, texto #f2f2f2,
   verde #59db8f, vermelho #e45959. Os cinzas já vinham do visual original da
   família escala e batiam; o que destoava era o resto — texto auxiliar
   azulado (#c8d8e4/#8faabc), bordas claras demais (#4f4f4f) e fundos de
   estado em hex chapado. Trocados por cinza neutro e por lavado em alfa, que
   é o que o CrowdStrike (tokens --cs-*) já faz na suíte. */
.plt-dark {
    --plt-bg:            #2b2b2b;   /* = .list-table do dark-theme.css    */
    --plt-bg-alt:        #242424;
    --plt-bg-hover:      #383838;   /* = hover de linha do dark-theme.css */
    --plt-panel:         #333638;
    --plt-panel-alt:     #2f3234;

    /* Borda em alfa: a mesma linha serve sobre #2b2b2b e sobre #333638 sem
       virar duas variáveis. Equivale ao #383838 entre linhas de tabela. */
    --plt-border:        rgba(255, 255, 255, 0.14);
    --plt-border-strong: rgba(255, 255, 255, 0.30);

    --plt-text:          #f2f2f2;
    --plt-text-soft:     #b9bcbf;
    --plt-text-muted:    #9ca1a4;
    /* 4,3:1 sobre o fundo do card. O #666666 anterior dava 2,5:1 — e esta
       variável carrega "sem telefone", "Nenhum histórico registrado" e o "—"
       das células, que é justamente o que precisa ser lido. */
    --plt-text-faint:    #8a8f92;

    --plt-accent:        #4796c4;   /* cor de link do dark-theme.css      */
    --plt-accent-bg:     rgba(71, 150, 196, 0.16);
    --plt-ok:            #59db8f;   /* = .green do dark-theme.css         */
    --plt-ok-bg:         rgba(89, 219, 143, 0.14);
    --plt-ok-border:     rgba(89, 219, 143, 0.36);
    --plt-warn:          #e99003;
    --plt-warn-bg:       rgba(233, 144, 3, 0.14);
    --plt-warn-border:   rgba(233, 144, 3, 0.36);
    --plt-danger:        #e45959;   /* = .red do dark-theme.css           */
    --plt-danger-bg:     rgba(228, 89, 89, 0.14);
    --plt-danger-border: rgba(228, 89, 89, 0.36);
    --plt-tag-bg:        rgba(255, 255, 255, 0.08);
    --plt-tag-text:      #b9bcbf;
    --plt-tag-border:    rgba(255, 255, 255, 0.16);
}
</style>
