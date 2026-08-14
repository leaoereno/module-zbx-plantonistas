<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosMentionsSearch — AJAX: busca hostgroups/hosts/usuários para o
 * autocomplete de menções ([hostgroup]/[host]/[user]) do Diário de Bordo.
 *
 * Permissão de visualização segue as mesmas regras já usadas no resto do
 * módulo: hostgroup/host respeitam `rights` do Zabbix (mesma lógica de
 * host_filter em resolveUserContext()); user respeita grupo compartilhado
 * (mesma regra de Notas/Presença) e exclui usuário com todos os grupos
 * desabilitados (mesmo critério de Telefones/Escala/Gerenciar Turnos).
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosMentionsSearch extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'type' => 'required|string',
            'q'    => 'string',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $type = $this->getInput('type', '');
        $q    = trim($this->getInput('q', ''));

        if (!in_array($type, ['hostgroup', 'host', 'user'], true)) {
            echo json_encode(['success' => false, 'message' => 'Tipo inválido.', 'results' => []]);
            die();
        }

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.', 'results' => []]);
            die();
        }

        $userid = (int)(CWebUser::$data['userid'] ?? 0);
        $ctx    = $this->resolveUserContext($db, $userid);

        switch ($type) {
            case 'hostgroup':
                $results = $this->searchHostgroups($db, $ctx, $q);
                break;
            case 'host':
                $results = $this->searchHosts($db, $ctx, $q);
                break;
            case 'user':
                $results = $this->searchMentionableUsers($db, $ctx, $q);
                break;
            default:
                $results = [];
        }

        $db->close();
        echo json_encode(['success' => true, 'results' => $results]);
        die();
    }
}
