<?php declare(strict_types = 1);

namespace Modules\Plantonistas;

use APP;
use CMenu;
use CMenuItem;

/**
 * Aba SRE do menu lateral — ponto de encontro dos módulos de SRE.
 *
 * Os três módulos (Plantonistas, Recorrência de alertas, Custo de Monitoração) são
 * repositórios independentes: não dá para compartilhar código por include, então cada um
 * carrega a SUA cópia deste arquivo. É duplicação PROPOSITAL, no mesmo espírito do
 * UserLabel / formatUserLabel() / buildFullName() do Plantonistas — ao mexer em uma, mexer
 * nas três. O que precisa ser idêntico está isolado nas constantes abaixo: o rótulo da aba,
 * a classe do ícone e a ordem dos filhos.
 *
 * QUANDO PARAR DE DUPLICAR: com três módulos a cópia ainda paga — são ~120 linhas e o
 * alinhamento é verificável comparando o md5sum dos três arquivos (ATENÇÃO ao escrever
 * esse comando aqui dentro: um glob com barra fecha o comentário). No QUARTO módulo de SRE
 * isso deixa de valer: aí o caminho é um módulo "SRE core", habilitado como dependência,
 * expondo esta classe (e o isPgsql()/existingTables(), hoje duplicados entre Plantonistas
 * e Cloud Cost) por um namespace só.
 *
 * Por que não `findOrAdd('SRE')`: ele anexa a aba no FIM do menu principal, depois de
 * Administration. A aba tem de nascer logo após Reports, que é onde o pessoal de SRE olha.
 *
 * Por que ordem explícita em vez de deixar o `add()` decidir: o Zabbix carrega os módulos
 * ordenados por `relative_path` (ZBase::initModuleManager), então a ordem dos itens seria a
 * ordem alfabética dos DIRETÓRIOS — renomear uma pasta, ou instalar um módulo novo,
 * embaralharia o menu sem ninguém entender por quê. Com a lista abaixo cada módulo entra na
 * posição certa independentemente de quem carregou primeiro e de quais dos três estão
 * habilitados.
 */
final class SreMenu {

    /** Rótulo da aba de primeiro nível. Comparado por igualdade exata em find() — não traduzir. */
    public const SECTION = 'SRE';

    /**
     * Classe da arte própria do ícone (assets/css/sre-menu-icon.css). O arquivo é idêntico nos
     * três módulos de propósito: assim a aba mantém o ícone com qualquer um deles habilitado
     * sozinho — o CSS de módulo só é servido enquanto aquele módulo está ligado.
     */
    public const ICON_CLASS = 'sre-menu-icon';

    /**
     * Ordem canônica dos filhos diretos de SRE. Rótulo fora desta lista (módulo futuro que não
     * atualizou a cópia dele) entra no fim, sem quebrar nada.
     */
    private const ORDER = [
        'Plantão',
        'Recorrência de alertas',
        'Custo de Monitoração'
    ];

    /**
     * Devolve a aba SRE, criando-a se este for o primeiro módulo a chegar.
     *
     * @return CMenuItem|null  null em contexto sem menu (CLI, setup) — o chamador segue sem UI.
     */
    public static function section(): ?CMenuItem {
        // O try cobre o BLOCO INTEIRO, não só o get(): em contexto sem UI (CLI, setup, modo API)
        // falta o componente 'menu.main', mas também podem faltar a constante ZBX_ICON_SERVICES e
        // o _() do gettext. Menu é enfeite — nenhuma dessas faltas pode derrubar uma página que
        // nada tem a ver com SRE, e o init() de módulo roda em TODAS elas.
        try {
            $menu = APP::Component()->get('menu.main');

            $section = $menu->find(self::SECTION);

            if ($section === null) {
                // Duas classes de propósito. zi-services é a REDE DE PROTEÇÃO para quando o CSS da
                // arte própria não carregar (F5, dono errado depois de um git pull como root,
                // cache): sem ela a aba ficaria sem ícone nenhum, sem erro e sem log.
                $section = (new CMenuItem(self::SECTION))
                    ->setId('sre')
                    ->setIcon(ZBX_ICON_SERVICES.' '.self::ICON_CLASS)
                    ->setSubMenu(new CMenu());

                $menu->insertAfter(_('Reports'), $section);
            }

            return $section;
        }
        catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Insere um filho direto de SRE respeitando ORDER.
     *
     * A varredura olha os vizinhos JÁ PRESENTES em vez de apostar num único âncora, porque
     * insertAfter() cai no fim da lista e insertBefore() cai no começo quando o rótulo de
     * referência não existe — âncora desabilitada mandaria o item para a ponta errada.
     */
    public static function add(CMenuItem $item): void {
        $section = self::section();

        if ($section === null) {
            return;
        }

        $submenu = $section->getSubMenu();

        // Rótulo repetido viraria dois itens idênticos, e o find() das outras cópias passaria a
        // enxergar só o primeiro.
        if ($submenu->find($item->getLabel()) !== null) {
            return;
        }

        $pos = array_search($item->getLabel(), self::ORDER, true);

        if ($pos !== false) {
            for ($i = $pos - 1; $i >= 0; $i--) {
                if ($submenu->find(self::ORDER[$i]) !== null) {
                    $submenu->insertAfter(self::ORDER[$i], $item);

                    return;
                }
            }

            for ($i = $pos + 1, $n = count(self::ORDER); $i < $n; $i++) {
                if ($submenu->find(self::ORDER[$i]) !== null) {
                    $submenu->insertBefore(self::ORDER[$i], $item);

                    return;
                }
            }
        }

        $submenu->add($item);
    }
}
