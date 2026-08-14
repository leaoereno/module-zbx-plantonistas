<?php

namespace Modules\Plantonistas\Actions;

/**
 * TurnosReportBase
 *
 * Trait compartilhado entre TurnosReportView e TurnosReportPdf.
 *
 * ── Segmentação automática ───────────────────────────────────
 * A visibilidade é derivada diretamente do Zabbix, sem configuração manual.
 *
 * Eventos / alertas:
 *   Filtrados pela tabela `rights` quando configurada.
 *   Se o usuário não tiver rights (ambiente sem permissões por host),
 *   mostra todos os eventos (sem filtro).
 *
 * Notas e Presença:
 *   Segmentados por grupo de usuário (`users_groups`).
 *   Um usuário vê apenas dados de outros usuários no mesmo grupo.
 *   Não depende de `rights` — funciona com qualquer configuração do Zabbix.
 *
 * Super Admin (role.type=3) ou Admin sem rights (role.type=2, rights vazias):
 *   Sem nenhum filtro — vê tudo.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 *
 * Fix v2.2.1: corrigidas queries incompatíveis com sql_mode=ONLY_FULL_GROUP_BY
 *   - queryInheritedAlerts: MIN() em colunas de JOIN fora do GROUP BY
 *   - queryUnackedAlerts:   MIN() em colunas de JOIN fora do GROUP BY
 */
trait TurnosReportBase {

    // ── Conexão ──────────────────────────────────────────────

    private function getDb(): ?\mysqli {
        try {
            $server = $GLOBALS['DB']['SERVER']   ?? 'localhost';
            $port   = (int)($GLOBALS['DB']['PORT'] ?? 3306);
            $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
            $user   = $GLOBALS['DB']['USER']     ?? 'zabbix';
            $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $mysqli = new \mysqli($server, $user, $pass, $dbname, $port);
            $mysqli->set_charset('utf8mb4');
            return $mysqli;
        } catch (\Throwable $e) {
            // Antes essa falha era só um retorno nulo silencioso — a tela de
            // Gerenciar Turnos aparecia normal, mas todo grupo vinha vazio,
            // sem nenhum rastro de que a conexão paralela (getDb(), fora do
            // DB::connect() nativo do Zabbix) nem chegou a abrir.
            error_log('[plantonistas] getDb() falhou: ' . $e->getMessage());
            return null;
        }
    }

    // ── Validação de data ────────────────────────────────────

    private function validateDate(string $date): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$y, $m, $d] = explode('-', $date);
            if (checkdate((int)$m, (int)$d, (int)$y)) {
                return $date;
            }
        }
        return date('Y-m-d');
    }

    // ── Janelas de turno ─────────────────────────────────────
    //
    // Turnos legados (24h/manha/tarde/noite) continuam com janelas fixas,
    // por compatibilidade. Turnos cadastrados em module_plantonistas_shifts (v2.5+) são
    // identificados por um shift_id numérico e têm a janela calculada a
    // partir de start_time/end_time — com detecção automática de virada de
    // meia-noite (quando end_time <= start_time).

    private function getShiftBounds(\mysqli $db, string $date, string $shift): array {
        $date = $this->validateDate($date);
        switch ($shift) {
            case 'manha':
                return [strtotime("$date 07:00:00"), strtotime("$date 12:59:59")];
            case 'tarde':
                return [strtotime("$date 13:00:00"), strtotime("$date 18:59:59")];
            case 'noite':
                if ($date === date('Y-m-d') && (int)date('H') < 7) {
                    $date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
                }
                $next = date('Y-m-d', strtotime("$date +1 day"));
                return [strtotime("$date 19:00:00"), strtotime("$next 06:59:59")];
            case '24h':
                return [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
            default:
                if (ctype_digit($shift)) {
                    $row = $this->getShiftById($db, (int)$shift);
                    if ($row !== null) {
                        return $this->computeCustomShiftBounds(
                            $date, $row['start_time'], $row['end_time']
                        );
                    }
                }
                return [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
        }
    }

    /**
     * Calcula início/fim (timestamps) de um turno cadastrado, tratando a
     * virada de meia-noite igual ao turno "noite" legado: se end_time <=
     * start_time, o turno cruza para o dia seguinte.
     */
    private function computeCustomShiftBounds(string $date, string $start, string $end): array {
        $crossesMidnight = strtotime($end) <= strtotime($start);

        if (!$crossesMidnight) {
            return [strtotime("$date $start"), strtotime("$date $end") - 1];
        }

        // Se estamos vendo "hoje" e o horário atual ainda está dentro da
        // cauda do turno (antes de end_time), o turno começou ontem.
        if ($date === date('Y-m-d') && strtotime(date('H:i:s')) < strtotime($end)) {
            $date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
        }
        $next = date('Y-m-d', strtotime("$date +1 day"));
        return [strtotime("$date $start"), strtotime("$next $end") - 1];
    }

    private function getShiftById(\mysqli $db, int $shiftId): ?array {
        try {
            $stmt = $db->prepare(
                "SELECT id, usrgrpid, name, start_time, end_time, sort_order, active
                 FROM module_plantonistas_shifts WHERE id = ?"
            );
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Monta as opções do seletor de turno exibidas ao usuário: os 4 turnos
     * legados (sempre presentes, por compatibilidade) + os turnos
     * cadastrados em module_plantonistas_shifts para o(s) grupo(s) do usuário.
     *
     * Super Admin vê os turnos cadastrados de TODAS as equipes (rotulados
     * com o nome do grupo, já que pode navegar entre equipes diferentes).
     * Demais usuários veem apenas os turnos do(s) próprio(s) grupo(s).
     *
     * Retorna ['options' => [codigo => rotulo, ...], 'rows' => [id => linha]]
     */
    /**
     * Rótulos dos 4 turnos legados (24h/manhã/tarde/noite), sempre disponíveis
     * por compatibilidade. Fonte única — reutilizado por queryShiftOptions()
     * e exposto à view de Gerenciar Turnos, que antes citava esses turnos no
     * texto de apoio sem mostrar em lugar nenhum quais são ou seus horários.
     */
    private function legacyShiftLabels(): array {
        return [
            '24h'   => '24 Horas (Dia Inteiro)',
            'manha' => 'Manhã (07h–13h)',
            'tarde' => 'Tarde (13h–19h)',
            'noite' => 'Noite (19h–07h)',
        ];
    }

    private function queryShiftOptions(\mysqli $db, array $ctx): array {
        $options = $this->legacyShiftLabels();
        $rows = [];

        try {
            if (!empty($ctx['is_superadmin'])) {
                $sql = "SELECT cs.id, cs.usrgrpid, cs.name, cs.start_time, cs.end_time,
                            ug.name AS group_name
                        FROM module_plantonistas_shifts cs
                        INNER JOIN usrgrp ug ON ug.usrgrpid = cs.usrgrpid
                        WHERE cs.active = 1
                        ORDER BY ug.name ASC, cs.sort_order ASC, cs.start_time ASC";
                $res = $db->query($sql);
                $shifts = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                $groupIds = $ctx['group_ids'] ?? [];
                if (empty($groupIds)) {
                    return ['options' => $options, 'rows' => $rows];
                }
                $ids = implode(',', array_map('intval', $groupIds));
                $sql = "SELECT cs.id, cs.usrgrpid, cs.name, cs.start_time, cs.end_time,
                            ug.name AS group_name
                        FROM module_plantonistas_shifts cs
                        INNER JOIN usrgrp ug ON ug.usrgrpid = cs.usrgrpid
                        WHERE cs.active = 1 AND cs.usrgrpid IN ($ids)
                        ORDER BY cs.sort_order ASC, cs.start_time ASC";
                $res = $db->query($sql);
                $shifts = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            }

            foreach ($shifts as $s) {
                $label = substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5);
                $prefix = !empty($ctx['is_superadmin']) ? '[' . $s['group_name'] . '] ' : '';
                $options[$s['id']] = $prefix . $s['name'] . ' (' . $label . ')';
                $rows[(int)$s['id']] = $s;
            }
        } catch (\Exception $e) {}

        return ['options' => $options, 'rows' => $rows];
    }

    /**
     * Garante que o parâmetro shift recebido é válido: um código legado
     * conhecido ou um ID numérico presente nas opções do usuário. Caso
     * contrário, cai para '24h' (comportamento seguro).
     */
    private function normalizeShift(string $shiftRaw, array $shiftOptions): string {
        return array_key_exists($shiftRaw, $shiftOptions) ? $shiftRaw : '24h';
    }

    // ── Analistas escalados no turno selecionado ─────────────

    private function queryShiftAnalysts(\mysqli $db, int $shiftId): array {
        $sql = "SELECT u.userid, u.username,
                    CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
                    online.last_seen,
                    CASE WHEN online.last_seen IS NOT NULL
                              AND online.last_seen >= (NOW() - INTERVAL 15 MINUTE)
                         THEN 1 ELSE 0 END AS is_online
                FROM module_plantonistas_user_shift cush
                INNER JOIN users u ON u.userid = cush.userid
                LEFT JOIN (
                    SELECT userid, MAX(lastaccess) AS last_seen
                    FROM module_plantonistas_user_sessions
                    GROUP BY userid
                ) online ON online.userid = u.userid
                WHERE cush.shift_id = ?
                ORDER BY fullname ASC";

        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    // ── Resolução de contexto ─────────────────────────────────

    private function getUserRoleType(\mysqli $db, int $userid): int {
        try {
            $stmt = $db->prepare(
                "SELECT r.type AS user_type
                 FROM users u
                 INNER JOIN role r ON r.roleid = u.roleid
                 WHERE u.userid = ?"
            );
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row !== false && $row !== null) {
                return (int)$row['user_type'];
            }
        } catch (\Exception $e) {}

        try {
            $stmt = $db->prepare("SELECT type AS user_type FROM users WHERE userid = ?");
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row ? (int)$row['user_type'] : 1;
        } catch (\Exception $e) {
            return 1;
        }
    }

    private function resolveUserContext(\mysqli $db, int $userid): array {
        $result = [
            'is_superadmin'  => false,
            'host_filter'    => '',
            'display_groups' => [],
            'group_ids'      => [],
            'role_type'      => 1,
            'userid'         => $userid,
        ];

        $roleType = $this->getUserRoleType($db, $userid);
        $result['role_type'] = $roleType;

        // Grupos do usuário (necessário mesmo para Super Admin — usado para
        // escopar o seletor de turnos e a tela de gerenciamento).
        try {
            $stmt = $db->prepare(
                "SELECT ug.usrgrpid
                 FROM usrgrp ug
                 INNER JOIN users_groups ugm ON ugm.usrgrpid = ug.usrgrpid
                 WHERE ugm.userid = ?"
            );
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $result['group_ids'] = array_map(
                fn($r) => (int)$r['usrgrpid'],
                $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
            );
        } catch (\Throwable $e) {
            error_log('[plantonistas] resolveUserContext() group_ids falhou (userid=' . $userid . '): ' . $e->getMessage());
        }

        if ($roleType === 3) {
            $result['is_superadmin'] = true;
        }

        // Super Admin não tem filtro de host — mantém o mesmo comportamento
        // de antes (sem consultar `rights`).
        if (!$result['is_superadmin']) {
            try {
                $stmt = $db->prepare(
                    "SELECT DISTINCT hg.hostid
                     FROM hosts_groups hg
                     INNER JOIN rights r        ON r.id        = hg.groupid
                     INNER JOIN users_groups ug ON ug.usrgrpid = r.groupid
                     WHERE ug.userid = ? AND r.permission >= 2"
                );
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                $hostIds = array_map(
                    fn($r) => (int)$r['hostid'],
                    $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
                );
                $result['host_filter'] = empty($hostIds)
                    ? ''
                    : 'AND h.hostid IN (' . implode(',', $hostIds) . ')';
            } catch (\Exception $e) {}
        }

        // Label de exibição (badge no header): mantém o comportamento original
        // — Super Admin não exibe badge de equipe (vê tudo, sem contexto único).
        if (!$result['is_superadmin']) {
            try {
                $stmt = $db->prepare(
                    "SELECT ug.name
                     FROM usrgrp ug
                     INNER JOIN users_groups ugm ON ugm.usrgrpid = ug.usrgrpid
                     WHERE ugm.userid = ?
                     ORDER BY ug.usrgrpid ASC"
                );
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                $result['display_groups'] = array_column(
                    $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
                    'name'
                );
            } catch (\Exception $e) {}
        }

        return $result;
    }

    // ── Segmentação por grupo de usuário ─────────────────────

    private function sameGroupExists(int $userid, string $authorIdColumn): string {
        return "EXISTS (
            SELECT 1
            FROM users_groups ug_reader
            JOIN users_groups ug_author
                ON ug_author.usrgrpid = ug_reader.usrgrpid
            WHERE ug_reader.userid = $userid
              AND ug_author.userid  = $authorIdColumn
        )";
    }

    // ── Queries ──────────────────────────────────────────────

    private function queryMTTA(\mysqli $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT sub.userid, sub.username, sub.fullname,
                    COUNT(*) AS total_acks,
                    ROUND(AVG(sub.mtta), 0) AS avg_mtta,
                    MIN(sub.mtta) AS min_mtta,
                    MAX(sub.mtta) AS max_mtta
                FROM (
                    SELECT a.userid, u.username,
                        CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
                        a.eventid,
                        (a.clock - ev.clock) AS mtta
                    FROM acknowledges a
                    INNER JOIN events ev   ON ev.eventid  = a.eventid
                    INNER JOIN users u     ON u.userid    = a.userid
                    INNER JOIN triggers t  ON t.triggerid = ev.objectid
                    INNER JOIN functions f ON f.triggerid = t.triggerid
                    INNER JOIN items i     ON i.itemid    = f.itemid
                    INNER JOIN hosts h     ON h.hostid    = i.hostid
                    WHERE ev.source = 0 AND ev.object = 0
                      AND ev.clock BETWEEN ? AND ?
                      AND LEFT(u.username, 4) <> 'api_'
                      AND a.acknowledgeid = (
                          SELECT MIN(a2.acknowledgeid)
                          FROM acknowledges a2
                          WHERE a2.eventid = a.eventid
                      )
                      $hostFilter
                ) sub
                GROUP BY sub.userid, sub.username, sub.fullname
                ORDER BY avg_mtta ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * FIX ONLY_FULL_GROUP_BY:
     * Colunas de tabelas joined (h.name, h.host, t.description) não são
     * funcionalmente dependentes de e.eventid na visão do otimizador MySQL.
     * Solução: MIN() em vez de ANY_VALUE() — compatível com MariaDB < 10.2 (determinístico neste
     * contexto pois eventid→host é 1-para-1 via trigger/function/item).
     */
    private function queryInheritedAlerts(\mysqli $db, int $ts_start, string $hostFilter = ''): array {
        // PERF FIX v2.4.4 (substitui a tentativa v2.4.2): a v2.4.2 limitou a
        // busca em `events` a 180 dias, mas mesmo assim a query levava 200+
        // segundos e nunca terminava nesse ambiente (confirmado via SHOW FULL
        // PROCESSLIST em produção — milhões de linhas mesmo dentro da janela).
        //
        // Fix real: usar a tabela `problem`, nativa do Zabbix — é a mesma
        // fonte que a tela Monitoring → Problemas usa, contém só os problemas
        // correntes/relevantes (não o histórico completo de `events`), e já
        // tem r_eventid/r_clock prontos pra checar "ainda aberto ou resolvido
        // depois do início do turno" sem precisar de LEFT JOIN em
        // event_recovery. Validado em produção: 0,26s (era 200s+ e nunca
        // completava). Sem necessidade de corte de data — `problem` já é
        // enxuta por natureza, e um alerta esquecido há meses é exatamente o
        // tipo de coisa que este relatório deve continuar mostrando.
        $sql = "SELECT p.eventid, p.clock, p.severity,
                    REPLACE(MIN(t.description), '{HOST.NAME}', MIN(h.name)) AS trigger_desc,
                    MIN(h.host)  AS host,
                    MIN(h.name)  AS host_name,
                    (? - p.clock)      AS age_seconds,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM acknowledges ak WHERE ak.eventid = p.eventid
                    ) THEN 1 ELSE 0 END AS has_ack
                FROM problem p
                INNER JOIN triggers t       ON t.triggerid = p.objectid
                INNER JOIN functions f      ON f.triggerid = t.triggerid
                INNER JOIN items i          ON i.itemid    = f.itemid
                INNER JOIN hosts h          ON h.hostid    = i.hostid
                WHERE p.source = 0 AND p.object = 0
                  AND p.clock < ?
                  AND (p.r_eventid IS NULL OR p.r_clock > ?)
                  $hostFilter
                GROUP BY p.eventid, p.clock, p.severity
                ORDER BY p.severity DESC, p.clock ASC
                LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $ts_start, $ts_start, $ts_start);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * FIX ONLY_FULL_GROUP_BY:
     * Mesma correção — MIN() para colunas de JOIN não agrupadas.
     */
    private function queryUnackedAlerts(\mysqli $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT ev.eventid, ev.clock, ev.severity,
                    REPLACE(MIN(t.description), '{HOST.NAME}', MIN(h.name)) AS trigger_desc,
                    MIN(h.host) AS host,
                    MIN(h.name) AS host_name
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  AND NOT EXISTS (
                      SELECT 1 FROM acknowledges ak WHERE ak.eventid = ev.eventid
                  )
                  $hostFilter
                GROUP BY ev.eventid, ev.clock, ev.severity
                ORDER BY ev.severity DESC, ev.clock DESC
                LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function queryTopHosts(\mysqli $db, int $s, int $e, int $limit, string $hostFilter = ''): array {
        $limitClause = $limit > 0 ? 'LIMIT ' . (int)$limit : '';
        $sql = "SELECT h.hostid, h.host, h.name AS host_name,
                    COUNT(DISTINCT ev.eventid) AS event_count,
                    MAX(ev.severity) AS max_severity
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY h.hostid, h.host, h.name
                ORDER BY event_count DESC $limitClause";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function queryTopTriggers(\mysqli $db, int $s, int $e, int $limit, string $hostFilter = ''): array {
        $limitClause = $limit > 0 ? 'LIMIT ' . (int)$limit : '';
        $sql = "SELECT t.triggerid,
                    REPLACE(t.description, '{HOST.NAME}', MIN(h.name)) AS description,
                    t.priority AS severity,
                    COUNT(DISTINCT ev.eventid) AS event_count
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY t.triggerid, t.description, t.priority
                ORDER BY t.priority DESC, event_count DESC $limitClause";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function queryEventTotals(\mysqli $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT COUNT(DISTINCT ev.eventid) AS total,
                    SUM(CASE WHEN ev.severity >= 4 THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN ev.severity = 3  THEN 1 ELSE 0 END) AS average,
                    SUM(CASE WHEN ev.severity <= 2 THEN 1 ELSE 0 END) AS low
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()
            ?: ['total' => 0, 'critical' => 0, 'average' => 0, 'low' => 0];
    }

    private function queryMttaTimeline(\mysqli $db, int $s, int $e, string $hostFilter = ''): array {
        $tzOffset = (int)date('Z');
        $sql = "SELECT FROM_UNIXTIME(ev.clock + ?, '%H') AS hora,
                    ROUND(AVG(a.clock - ev.clock), 0) AS avg_mtta
                FROM acknowledges a
                INNER JOIN events ev   ON ev.eventid  = a.eventid
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0
                  AND ev.clock BETWEEN ? AND ?
                  AND a.acknowledgeid = (
                      SELECT MIN(a2.acknowledgeid)
                      FROM acknowledges a2
                      WHERE a2.eventid = a.eventid
                  )
                  $hostFilter
                GROUP BY hora
                ORDER BY hora ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $tzOffset, $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function querySeverityDistribution(\mysqli $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT ev.severity, COUNT(*) AS cnt
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY ev.severity
                ORDER BY ev.severity ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $rows[(int)$r['severity']] = (int)$r['cnt'];
        }
        return $rows;
    }

    private function queryCalendarHeatmap(\mysqli $db, string $hostFilter = ''): array {
        $ts30     = (int)strtotime('-30 days 00:00:00');
        $tsNow    = (int)time();
        $tzOffset = (int)date('Z');

        $sql = "SELECT DATE(FROM_UNIXTIME(ev.clock + ?)) AS dia,
                    COUNT(DISTINCT ev.eventid) AS cnt,
                    SUM(CASE WHEN ev.severity >= 4 THEN 1 ELSE 0 END) AS critical
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY dia
                ORDER BY dia ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $tzOffset, $ts30, $tsNow);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $rows[$r['dia']] = $r;
        }
        return $rows;
    }

    private function queryNotes(\mysqli $db, string $date, string $shift,
                                int $userid, bool $isSuperadmin = false): array {
        $date = $this->validateDate($date);

        // Turnos cadastrados (v2.5+) gravam shift_id numérico; turnos
        // legados (24h/manha/tarde/noite) continuam filtrando por shift_name.
        $filterByShiftId = ctype_digit($shift);
        $shiftIdInt      = $filterByShiftId ? (int)$shift : 0;
        $shiftCol        = $filterByShiftId ? 'shift_id' : 'shift_name';

        if ($isSuperadmin) {
            $sql  = "SELECT id, analyst_userid, analyst_name, notes, created_at
                     FROM module_plantonistas_shift_notes
                     WHERE shift_date = ? AND $shiftCol = ?
                     ORDER BY created_at DESC";
            $stmt = $db->prepare($sql);
            $filterByShiftId
                ? $stmt->bind_param('si', $date, $shiftIdInt)
                : $stmt->bind_param('ss', $date, $shift);
        } else {
            $sameGroup = $this->sameGroupExists($userid, 'csn.analyst_userid');
            $sql = "SELECT DISTINCT csn.id, csn.analyst_userid, csn.analyst_name,
                        csn.notes, csn.created_at
                    FROM module_plantonistas_shift_notes csn
                    WHERE csn.shift_date = ? AND csn.$shiftCol = ?
                      AND $sameGroup
                    ORDER BY csn.created_at DESC";
            $stmt = $db->prepare($sql);
            $filterByShiftId
                ? $stmt->bind_param('si', $date, $shiftIdInt)
                : $stmt->bind_param('ss', $date, $shift);
        }

        try {
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function queryPresence(\mysqli $db, int $s, int $e,
                                   int $userid, bool $isSuperadmin = false): array {
        $ds = date('Y-m-d H:i:s', $s);
        $de = date('Y-m-d H:i:s', $e);

        if ($isSuperadmin) {
            $sql = "SELECT cus.userid, cus.username, cus.name AS fullname,
                        MIN(cus.session_start) AS first_seen,
                        MAX(cus.lastaccess)    AS last_seen,
                        TIMESTAMPDIFF(MINUTE, MIN(cus.session_start), MAX(cus.lastaccess)) AS online_minutes
                    FROM module_plantonistas_user_sessions cus
                    WHERE cus.lastaccess BETWEEN ? AND ?
                    GROUP BY cus.userid, cus.username, cus.name
                    ORDER BY first_seen ASC";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $ds, $de);
        } else {
            $sameGroup = $this->sameGroupExists($userid, 'cus.userid');
            $sql = "SELECT cus.userid, cus.username, cus.name AS fullname,
                        MIN(cus.session_start) AS first_seen,
                        MAX(cus.lastaccess)    AS last_seen,
                        TIMESTAMPDIFF(MINUTE, MIN(cus.session_start), MAX(cus.lastaccess)) AS online_minutes
                    FROM module_plantonistas_user_sessions cus
                    WHERE cus.lastaccess BETWEEN ? AND ?
                      AND $sameGroup
                    GROUP BY cus.userid, cus.username, cus.name
                    ORDER BY first_seen ASC";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $ds, $de);
        }

        try {
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    // ── Helper: MTTA global ponderado ────────────────────────

    private function calcGlobalMTTA(array $mttaRows): int {
        if (empty($mttaRows)) return 0;
        $sum = $cnt = 0;
        foreach ($mttaRows as $m) {
            $sum += $m['avg_mtta'] * $m['total_acks'];
            $cnt += $m['total_acks'];
        }
        return $cnt > 0 ? (int)round($sum / $cnt) : 0;
    }

    /**
     * Visibilidade de MTTA por role_type:
     *   3 (Super Admin) / 2 (Admin) → vê o MTTA de todos os analistas.
     *   1 (User)                    → vê apenas o próprio MTTA.
     */
    private function restrictMttaByRole(array $mttaRows, int $roleType, int $userid): array {
        if ($roleType >= 2) {
            return $mttaRows;
        }
        return array_values(array_filter(
            $mttaRows,
            fn($m) => (int)$m['userid'] === $userid
        ));
    }

    // ── Administração de turnos (CRUD) — Admin/Super Admin ───

    /**
     * Grupos que o usuário pode administrar: Super Admin → todos os grupos
     * com pelo menos 1 membro; Admin → apenas os grupos aos quais pertence.
     */
    private function listManageableGroups(\mysqli $db, array $ctx): array {
        try {
            if (!empty($ctx['is_superadmin'])) {
                // Nota: exige >=1 membro em users_groups pra o grupo aparecer.
                // Um usrgrp de permissão sem nenhum usuário atribuído nunca
                // vira um card aqui — não é bug, é o INNER JOIN abaixo.
                $sql = "SELECT DISTINCT ug.usrgrpid, ug.name
                        FROM usrgrp ug
                        INNER JOIN users_groups ugm ON ugm.usrgrpid = ug.usrgrpid
                        ORDER BY ug.name ASC";
                $res = $db->query($sql);
                return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            }

            $groupIds = $ctx['group_ids'] ?? [];
            if (empty($groupIds)) return [];
            $ids = implode(',', array_map('intval', $groupIds));
            $sql = "SELECT usrgrpid, name FROM usrgrp WHERE usrgrpid IN ($ids) ORDER BY name ASC";
            $res = $db->query($sql);
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            error_log('[plantonistas] listManageableGroups() falhou: ' . $e->getMessage());
            return [];
        }
    }

    private function listShiftsByGroup(\mysqli $db, int $usrgrpid): array {
        try {
            $stmt = $db->prepare(
                "SELECT id, usrgrpid, name, start_time, end_time, sort_order, active
                 FROM module_plantonistas_shifts
                 WHERE usrgrpid = ?
                 ORDER BY sort_order ASC, start_time ASC"
            );
            $stmt->bind_param('i', $usrgrpid);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $e) {
            error_log('[plantonistas] listShiftsByGroup() falhou (usrgrpid=' . $usrgrpid . '): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retorna os analistas (usuários Zabbix) de uma equipe.
     *
     * Se a consulta falhar (conexão, permissão do usuário de banco, schema),
     * a chave 'error' vem preenchida — a view distingue isso de "equipe sem
     * analistas" de verdade, que é 'error' => null com 'rows' => [].
     * Antes esse retorno era só um array vazio nos dois casos, e a tela
     * mostrava sempre "Nenhum usuário Zabbix neste grupo", sem dar pra saber
     * se o grupo estava mesmo sem gente ou se a consulta tinha explodido.
     */
    private function listUsersByGroup(\mysqli $db, int $usrgrpid): array {
        try {
            $stmt = $db->prepare(
                "SELECT u.userid, u.username,
                        CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
                        cush.shift_id
                 FROM users u
                 INNER JOIN users_groups ugm ON ugm.userid = u.userid
                 LEFT JOIN module_plantonistas_user_shift cush ON cush.userid = u.userid
                 WHERE ugm.usrgrpid = ?
                 ORDER BY fullname ASC"
            );
            if ($stmt === false) {
                throw new \RuntimeException($db->error ?: 'prepare() retornou false');
            }
            $stmt->bind_param('i', $usrgrpid);
            $stmt->execute();
            return ['error' => null, 'rows' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
        } catch (\Throwable $e) {
            error_log('[plantonistas] listUsersByGroup() falhou (usrgrpid=' . $usrgrpid . '): ' . $e->getMessage());
            return ['error' => $e->getMessage(), 'rows' => []];
        }
    }

    /**
     * Confirma que o usuário logado pode administrar o grupo informado
     * (Super Admin: qualquer grupo; Admin: apenas os próprios grupos).
     */
    private function canManageGroup(array $ctx, int $usrgrpid): bool {
        if (!empty($ctx['is_superadmin'])) return true;
        return in_array($usrgrpid, $ctx['group_ids'] ?? [], true);
    }

    /**
     * Cria ou atualiza um turno. Retorna ['success'=>bool,'message'=>string,'id'=>?int].
     */
    private function saveShift(\mysqli $db, array $ctx, ?int $id, int $usrgrpid,
                                string $name, string $start, string $end, int $sortOrder): array {
        if (!$this->canManageGroup($ctx, $usrgrpid)) {
            return ['success' => false, 'message' => 'Sem permissão para esta equipe.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Informe um nome para o turno.'];
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            return ['success' => false, 'message' => 'Horário inválido (use HH:MM).'];
        }

        try {
            if ($id) {
                $stmt = $db->prepare(
                    "UPDATE module_plantonistas_shifts
                     SET name = ?, start_time = ?, end_time = ?, sort_order = ?
                     WHERE id = ? AND usrgrpid = ?"
                );
                $stmt->bind_param('sssiii', $name, $start, $end, $sortOrder, $id, $usrgrpid);
                $stmt->execute();
                return ['success' => true, 'message' => 'Turno atualizado.', 'id' => $id];
            }

            $stmt = $db->prepare(
                "INSERT INTO module_plantonistas_shifts (usrgrpid, name, start_time, end_time, sort_order, active)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );
            $stmt->bind_param('isssi', $usrgrpid, $name, $start, $end, $sortOrder);
            $stmt->execute();
            return ['success' => true, 'message' => 'Turno criado.', 'id' => $db->insert_id];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Já existe um turno com esse nome nessa equipe, ou erro ao salvar.'];
        }
    }

    private function deleteShift(\mysqli $db, array $ctx, int $id): array {
        $shift = $this->getShiftById($db, $id);
        if ($shift === null) {
            return ['success' => false, 'message' => 'Turno não encontrado.'];
        }
        if (!$this->canManageGroup($ctx, (int)$shift['usrgrpid'])) {
            return ['success' => false, 'message' => 'Sem permissão para esta equipe.'];
        }

        try {
            $stmt = $db->prepare("DELETE FROM module_plantonistas_user_shift WHERE shift_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();

            $stmt = $db->prepare("DELETE FROM module_plantonistas_shifts WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();

            return ['success' => true, 'message' => 'Turno removido.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Erro ao remover turno.'];
        }
    }

    /**
     * Vincula (ou desvincula, se $shiftId for null) um usuário a um turno.
     */
    private function saveUserShift(\mysqli $db, array $ctx, int $userid, ?int $shiftId): array {
        if ($shiftId !== null) {
            $shift = $this->getShiftById($db, $shiftId);
            if ($shift === null || !$this->canManageGroup($ctx, (int)$shift['usrgrpid'])) {
                return ['success' => false, 'message' => 'Turno inválido ou sem permissão.'];
            }
        } else {
            // Para desvincular, confirma que o usuário pertence a um grupo administrável.
            try {
                $stmt = $db->prepare(
                    "SELECT usrgrpid FROM users_groups WHERE userid = ?"
                );
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                $groups = array_map(fn($r) => (int)$r['usrgrpid'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
                $allowed = !empty($ctx['is_superadmin']);
                foreach ($groups as $g) {
                    if ($this->canManageGroup($ctx, $g)) { $allowed = true; break; }
                }
                if (!$allowed) {
                    return ['success' => false, 'message' => 'Sem permissão para este usuário.'];
                }
            } catch (\Exception $e) {
                return ['success' => false, 'message' => 'Erro ao validar usuário.'];
            }
        }

        try {
            if ($shiftId === null) {
                $stmt = $db->prepare("DELETE FROM module_plantonistas_user_shift WHERE userid = ?");
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                return ['success' => true, 'message' => 'Usuário desvinculado do turno.'];
            }

            $stmt = $db->prepare(
                "INSERT INTO module_plantonistas_user_shift (userid, shift_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)"
            );
            $stmt->bind_param('ii', $userid, $shiftId);
            $stmt->execute();
            return ['success' => true, 'message' => 'Turno atribuído.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Erro ao salvar vínculo.'];
        }
    }
}
