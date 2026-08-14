<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CWebUser;

/**
 * Exporta CSV com Usuário | Nome | Telefone dos membros visíveis ao usuário logado.
 * Respeita as mesmas regras de grupo e role do PhonesList.
 */
class PhonesExport extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool { return true; }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
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

        // Mesmo critério de "usuário ativo" das telas Telefones/Escala/Gerenciar
        // Turnos — pelo menos 1 grupo com usrgrp.users_status=0.
        $active_filter =
            'EXISTS (' .
            '   SELECT 1 FROM users_groups ugx' .
            '   JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid' .
            '   WHERE ugx.userid = u.userid AND gx.users_status = 0' .
            ' )';

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
                $u['phone'],
            ], ';');
        }
        fclose($out);
        exit;
    }
}
