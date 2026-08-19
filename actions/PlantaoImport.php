<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseRedirect, CUrl, CWebUser;

class PlantaoImport extends CController {

    use UserLabel;

    // readCsv/readXlsx/colRefToIndex saíram daqui para o trait: o readCsv
    // estava duplicado linha a linha no PhonesImport, e o leitor de XLSX
    // ficava preso nesta classe — era por isso que a importação de telefones
    // aceitava só CSV.
    use SpreadsheetReader;

    protected function checkInput(): bool { return true; }

    public function checkPermissions(): bool {
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        $usrgrpid = (int)($_GET['usrgrpid'] ?? $_POST['usrgrpid'] ?? 0);
        $month    = (int)($_GET['month']    ?? $_POST['month']    ?? date('n'));
        $year     = (int)($_GET['year']     ?? $_POST['year']     ?? date('Y'));
        if ($month < 1 || $month > 12) $month = (int)date('n');
        if ($year  < 2000)             $year  = (int)date('Y');

        $redirect = (new CUrl('zabbix.php'))
            ->setArgument('action',   'plantonistas.list')
            ->setArgument('month',    $month)
            ->setArgument('year',     $year)
            ->setArgument('usrgrpid', $usrgrpid);

        if ($usrgrpid <= 0) {
            $this->err($redirect, 'Parâmetro usrgrpid ausente.'); return;
        }

        if (!$is_super && !DBfetch(DBselect(
            'SELECT usrgrpid FROM users_groups WHERE userid=' . $current_userid . ' AND usrgrpid=' . $usrgrpid
        ))) {
            $this->err($redirect, 'Permissão negada: você não pertence a este grupo.'); return;
        }

        // Ler arquivo — enviado como base64 via campo hidden (evita multipart)
        $b64   = $_POST['import_file_b64']  ?? '';
        $fname = strtolower(trim($_POST['import_file_name'] ?? ''));

        if ($b64 === '' || $fname === '') {
            $this->err($redirect, 'Nenhum arquivo recebido. Tente novamente.'); return;
        }

        // O FileReader.readAsDataURL gera "data:<mime>;base64,<dados>"
        // Extrair só a parte após a vírgula
        if (strpos($b64, ',') !== false) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        $b64 = str_replace(["\n", "\r", ' '], '', $b64); // limpar espaços/quebras

        $content = base64_decode($b64, true);
        if ($content === false || $content === '') {
            $this->err($redirect, 'Falha ao decodificar o arquivo. Tente novamente.'); return;
        }

        $ext = pathinfo($fname, PATHINFO_EXTENSION);

        $tmp = tempnam(sys_get_temp_dir(), 'plt_');
        if ($tmp === false) {
            $this->err($redirect, 'Não foi possível criar arquivo temporário.'); return;
        }
        file_put_contents($tmp, $content);

        if ($ext === 'xlsx') {
            $rows = $this->readXlsxFile($tmp);
        } else {
            $rows = $this->readCsvFile($tmp);
        }
        @unlink($tmp);

        if ($rows === null || count($rows) < 2) {
            $this->err($redirect, 'Arquivo vazio ou formato não reconhecido. Use CSV (;) ou XLSX.'); return;
        }

        $header = array_map(fn($c) => mb_strtolower(trim((string)$c)), $rows[0]);
        $col    = $this->detectColumns($header);

        if ($col['data'] === -1) {
            $this->err($redirect, 'Coluna "data" não encontrada. Cabeçalho: ' . implode(' | ', $rows[0])); return;
        }
        if ($col['plantonista'] === -1) {
            $this->err($redirect, 'Coluna "plantonista" não encontrada. Cabeçalho: ' . implode(' | ', $rows[0])); return;
        }

        $usermap  = $this->buildUserMap($usrgrpid, $is_super);
        $accepted = array_unique(array_keys($usermap));

        $saved   = 0;
        $skipped = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            $raw_date = trim((string)($row[$col['data']]        ?? ''));
            $raw_tech = trim((string)($row[$col['plantonista']] ?? ''));
            $raw_res  = $col['reserva'] !== -1 ? trim((string)($row[$col['reserva']] ?? '')) : '';

            if ($raw_date === '' && $raw_tech === '') continue;

            $date = $this->parseDate($raw_date);
            if (!$date) {
                $skipped[] = "Linha " . ($i + 1) . ": data inválida \"$raw_date\"";
                continue;
            }

            $userid = $this->resolveUser($raw_tech, $usermap);
            if (!$userid) {
                $sample = implode(', ', array_slice($accepted, 0, 8));
                $skipped[] = "Linha " . ($i + 1) . " ($date): técnico \"$raw_tech\" não encontrado. Exemplos aceitos: $sample";
                continue;
            }

            $userid_reserva = 0;
            if ($raw_res !== '') {
                $userid_reserva = $this->resolveUser($raw_res, $usermap) ?? 0;
                if (!$userid_reserva) {
                    $skipped[] = "Linha " . ($i + 1) . " ($date): reserva \"$raw_res\" não encontrada (ignorada).";
                }
            }

            if ($userid_reserva > 0 && $userid === $userid_reserva) {
                $userid_reserva = 0;
            }

            $r_sql = $userid_reserva > 0 ? $userid_reserva : 'NULL';
            $r_set = $userid_reserva > 0
                ? ', userid_reserva=' . $userid_reserva
                : ', userid_reserva=NULL';

            try {
                $existing = DBfetch(DBselect(
                    'SELECT scheduleid, userid, userid_reserva FROM module_plantonistas_schedule' .
                    ' WHERE usrgrpid=' . $usrgrpid . ' AND schedule_date=' . zbx_dbstr($date)
                ));

                if ($existing) {
                    DBexecute(
                        'UPDATE module_plantonistas_schedule SET userid=' . $userid . $r_set .
                        ', created_by=' . $current_userid . ', created_at=' . time() .
                        ' WHERE scheduleid=' . (int)$existing['scheduleid']
                    );
                    $this->logHistory(
                        (int)$existing['scheduleid'], $usrgrpid, $date, 'update',
                        (int)$existing['userid'], $userid,
                        $existing['userid_reserva'] ? (int)$existing['userid_reserva'] : null,
                        $userid_reserva > 0 ? $userid_reserva : null,
                        $current_userid
                    );
                } else {
                    DBexecute(
                        'INSERT INTO module_plantonistas_schedule' .
                        ' (usrgrpid,userid,userid_reserva,schedule_date,created_by,created_at)' .
                        ' VALUES (' . $usrgrpid . ',' . $userid . ',' . $r_sql . ',' .
                        zbx_dbstr($date) . ',' . $current_userid . ',' . time() . ')'
                    );
                    $new_id = DBfetch(DBselect(
                        'SELECT scheduleid FROM module_plantonistas_schedule' .
                        ' WHERE usrgrpid=' . $usrgrpid . ' AND schedule_date=' . zbx_dbstr($date)
                    ));
                    $this->logHistory(
                        $new_id ? (int)$new_id['scheduleid'] : 0, $usrgrpid, $date, 'create',
                        null, $userid, null,
                        $userid_reserva > 0 ? $userid_reserva : null,
                        $current_userid
                    );
                }
                $saved++;
            } catch (\Exception $e) {
                $skipped[] = "Linha " . ($i + 1) . " ($date): " . $e->getMessage();
            }
        }

        $msg = $saved . ' ' . ($saved === 1 ? 'dia importado' : 'dias importados') . '.';
        if ($skipped) {
            $detail = implode(' | ', array_slice($skipped, 0, 5));
            if (count($skipped) > 5) $detail .= ' … e mais ' . (count($skipped) - 5) . ' avisos.';
            $this->err($redirect, $msg . ' Avisos (' . count($skipped) . '): ' . $detail);
            return;
        }

        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('success', $msg)
        ));
    }




    // ── Helpers ───────────────────────────────────────────────────────────

    private function detectColumns(array $header): array {
        $col = ['data' => -1, 'plantonista' => -1, 'reserva' => -1];
        $map = [
            'data'        => ['data', 'date', 'dia', 'day'],
            'plantonista' => ['plantonista', 'técnico', 'tecnico', 'tech', 'nome', 'name', 'usuario', 'usuário', 'username'],
            'reserva'     => ['reserva', 'backup', 'técnico reserva', 'tecnico reserva'],
        ];
        foreach ($header as $i => $h) {
            foreach ($map as $key => $candidates) {
                if ($col[$key] === -1 && in_array($h, $candidates, true)) {
                    $col[$key] = $i;
                }
            }
        }
        if ($col['data'] === -1 && $col['plantonista'] === -1) {
            $col['data'] = 0; $col['plantonista'] = 1; $col['reserva'] = 2;
        }
        return $col;
    }

    private function parseDate(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            $dt = \DateTime::createFromFormat('d/m/Y', sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3]));
            if ($dt) return $dt->format('Y-m-d');
        }
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $raw)) {
            $dt = \DateTime::createFromFormat('Y-m-d', $raw);
            if ($dt && $dt->format('Y-m-d') === $raw) return $raw;
        }
        if (preg_match('#^(\d{1,2})-(\d{1,2})-(\d{4})$#', $raw, $m)) {
            $dt = \DateTime::createFromFormat('d-m-Y', sprintf('%02d-%02d-%04d', $m[1], $m[2], $m[3]));
            if ($dt) return $dt->format('Y-m-d');
        }
        if (ctype_digit($raw) && (int)$raw > 1000) {
            return date('Y-m-d', (((int)$raw) - 25569) * 86400);
        }
        return null;
    }

    private function buildUserMap(int $usrgrpid, bool $is_super): array {
        $map = [];
        if ($is_super) {
            $res = DBselect("SELECT userid, username, name, surname FROM users WHERE LOWER(username) <> 'guest'");
        } else {
            $res = DBselect(
                'SELECT DISTINCT u.userid, u.username, u.name, u.surname' .
                ' FROM users u JOIN users_groups ug ON ug.userid = u.userid' .
                ' WHERE ug.usrgrpid = ' . $usrgrpid
            );
        }
        while ($r = DBfetch($res)) {
            $uid   = (int)$r['userid'];
            $uname = mb_strtolower(trim($r['username']));
            $full  = mb_strtolower(trim($r['name'] . ' ' . $r['surname']));
            // O CSV exportado traz o rótulo do UserLabel (sem o nome
            // duplicado). Sem esta chave, exportar e reimportar deixaria de
            // casar justamente com quem tem o nome completo no surname.
            $lbl   = mb_strtolower($this->userLabel($r['name'], $r['surname'], ''));
            $nome  = mb_strtolower(trim($r['name']));
            foreach ([$uname, $this->removeAccents($uname)] as $k) {
                if ($k !== '') $map[$k] = $uid;
            }
            foreach ([$full, $this->removeAccents($full), $lbl, $this->removeAccents($lbl)] as $k) {
                if ($k !== '') $map[$k] = $uid;
            }
            foreach ([$nome, $this->removeAccents($nome)] as $k) {
                if ($k !== '' && !isset($map[$k])) $map[$k] = $uid;
            }
        }
        return $map;
    }

    private function resolveUser(string $raw, array $usermap): ?int {
        if ($raw === '') return null;
        $key = mb_strtolower(trim($raw));
        return $usermap[$key] ?? $usermap[$this->removeAccents($key)] ?? null;
    }

    private function removeAccents(string $str): string {
        $from = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï',
                 'ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ','ý','ÿ',
                 'Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï',
                 'Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ','Ý'];
        $to   = ['a','a','a','a','a','e','e','e','e','i','i','i','i',
                 'o','o','o','o','o','u','u','u','u','c','n','y','y',
                 'a','a','a','a','a','e','e','e','e','i','i','i','i',
                 'o','o','o','o','o','u','u','u','u','c','n','y'];
        return mb_strtolower(str_replace($from, $to, $str));
    }

    private function logHistory(
        int $scheduleid, int $usrgrpid, string $date, string $action,
        ?int $userid_old, ?int $userid_new,
        ?int $reserva_old, ?int $reserva_new,
        int $changed_by
    ): void {
        DBexecute(
            'INSERT INTO module_plantonistas_history' .
            ' (scheduleid,usrgrpid,schedule_date,action,userid_old,userid_new,' .
            '  reserva_old,reserva_new,changed_by,changed_at)' .
            ' VALUES (' .
                $scheduleid . ',' . $usrgrpid . ',' . zbx_dbstr($date) . ',' .
                zbx_dbstr($action) . ',' .
                ($userid_old  !== null ? $userid_old  : 'NULL') . ',' .
                ($userid_new  !== null ? $userid_new  : 'NULL') . ',' .
                ($reserva_old !== null ? $reserva_old : 'NULL') . ',' .
                ($reserva_new !== null ? $reserva_new : 'NULL') . ',' .
                $changed_by . ',' . time() .
            ')'
        );
    }

    private function err(CUrl $redirect, string $msg): void {
        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('error', $msg)
        ));
    }
}
