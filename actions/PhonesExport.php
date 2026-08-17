<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CWebUser;

/**
 * Exporta CSV com Usuário | Nome | Telefone dos membros visíveis ao usuário logado.
 * Respeita as mesmas regras de grupo e role do PhonesList.
 */
class PhonesExport extends CController {

    use PhonesFormat;

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool { return true; }

    public function checkPermissions(): bool {
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        $select =
            'SELECT DISTINCT' .
            '  u.userid, u.username, u.name, u.surname,' .
            '  COALESCE(p.phone, \'\') AS phone' .
            ' FROM users u' .
            ' JOIN users_groups ug ON ug.userid  = u.userid' .
            ' LEFT JOIN module_plantonistas_phones p ON p.userid = u.userid';

        // Mesmo critério de "usuário habilitado" das telas Telefones/Escala/
        // Gerenciar Turnos, igual ao do Zabbix: QUALQUER grupo desabilitado
        // desabilita a pessoa (era o inverso antes — ver PhonesList).
        $active_filter =
            'NOT EXISTS (' .
            '   SELECT 1 FROM users_groups ugx' .
            '   JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid' .
            '   WHERE ugx.userid = u.userid AND gx.users_status = 1' .
            ' )' .
            ' AND u.roleid IS NOT NULL AND u.roleid <> 0';

        if ($is_super) {
            $sql = $select . " WHERE u.username != 'guest' AND " . $active_filter . " ORDER BY u.name, u.surname";
        } else {
            $group_ids = [];
            $res = DBselect('SELECT usrgrpid FROM users_groups WHERE userid=' . $current_userid);
            while ($r = DBfetch($res)) $group_ids[] = (int)$r['usrgrpid'];

            if (empty($group_ids)) {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="usuarios_plantao.csv"');
                echo "\xEF\xBB\xBF";
                echo "Usuario;Nome;Telefone\n";
                exit;
            }

            $roleid_row = DBfetch(DBselect('SELECT roleid FROM users WHERE userid=' . $current_userid));
            $roleid     = $roleid_row ? (int)$roleid_row['roleid'] : 0;

            $sql = $select .
                ' WHERE ug.usrgrpid IN (' . implode(',', $group_ids) . ')' .
                '   AND u.roleid = ' . $roleid .
                '   AND ' . $active_filter .
                ' ORDER BY u.name, u.surname';
        }

        // Deduplicar por userid
        $users = [];
        $res = DBselect($sql);
        while ($r = DBfetch($res)) {
            $users[$r['userid']] = $r;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="usuarios_plantao.csv"');
        header('Cache-Control: no-cache');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Usuario', 'Nome', 'Telefone'], ';');

        foreach ($users as $u) {
            $nome = trim($u['name'] . ' ' . $u['surname']);
            fputcsv($out, [
                $u['username'],
                $nome !== '' ? $nome : '—',
                // Com máscara: este CSV é o modelo da importação em massa, e
                // telefone só com dígitos o Excel trata como número (come zero
                // à esquerda e joga pra notação científica em campo longo).
                // A importação remove a máscara de volta.
                $this->formatPhoneBr($u['phone']),
            ], ';');
        }
        fclose($out);
        exit;
    }
}
