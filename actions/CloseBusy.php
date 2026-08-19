<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

/**
 * CloseBusy — recusa esperada do "Fechar turno", com mensagem já escrita
 * para o usuário.
 *
 * Existe para separar dois tipos de falha que o `catch` do TurnosReportClose
 * precisa tratar de forma diferente:
 *
 *  - **Recusa esperada** (turno sendo fechado por outra pessoa neste
 *    instante, turno já fechado e o usuário não é Admin, checagem de
 *    fechamentos anteriores falhou): a mensagem foi escrita para ser lida,
 *    e vai para a tela.
 *  - **Exceção de banco**: a `RuntimeException` do `ZbxDb` carrega o SQL
 *    inteiro, com o JSON do snapshot dentro. Essa nunca vai para a UI.
 *
 * Sem a distinção, ou o SQL vazaria na tela, ou toda recusa viraria o texto
 * genérico "confira o log do PHP-FPM", que não diz nada a quem clicou.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class CloseBusy extends \RuntimeException {
}
