-- ============================================================
-- Relatório de Repasse de Plantão — Queries de Diagnóstico
-- Dialeto: MySQL / MariaDB.  (Versão PostgreSQL: queries.pgsql.sql)
--
-- Referência e troubleshooting. Não executar em produção
-- sem revisar os filtros de data/host.
--
-- Substitua @ts_start / @ts_end por timestamps Unix, ex:
--   SET @ts_start = UNIX_TIMESTAMP('2026-06-15 00:00:00');
--   SET @ts_end   = UNIX_TIMESTAMP('2026-06-15 23:59:59');
--
-- Os dois arquivos devem ser mantidos em par: ao mudar uma consulta
-- aqui, mudar a equivalente no arquivo do PostgreSQL.
-- ============================================================

-- ── Diagnóstico geral ───────────────────────────────────────

-- Últimas notas do Diário de Bordo
SELECT n.shift_date, n.shift_name, n.analyst_name,
       LEFT(n.notes, 80) AS preview, n.created_at
FROM module_plantonistas_shift_notes n
ORDER BY n.created_at DESC
LIMIT 20;

-- Analistas presentes hoje
SELECT cus.username, cus.name, cus.noc_context,
       cus.session_start, cus.lastaccess
FROM module_plantonistas_user_sessions cus
WHERE DATE(cus.lastaccess) = CURDATE()
ORDER BY cus.lastaccess DESC;

-- Grupos e role de cada usuário
SELECT u.userid, u.username,
       ug.name AS user_group,
       r.name  AS role_name,
       r.type  AS role_type
FROM users u
JOIN users_groups ugm ON ugm.userid    = u.userid
JOIN usrgrp ug        ON ug.usrgrpid   = ugm.usrgrpid
JOIN role r            ON r.roleid      = u.roleid
ORDER BY ug.name, u.username;

-- Hosts acessíveis por usuário (substitua userid = 5)
SELECT DISTINCT h.hostid, h.host, h.name AS host_name, r.permission
FROM hosts h
JOIN hosts_groups hg ON hg.hostid   = h.hostid
JOIN rights r        ON r.id        = hg.groupid
JOIN users_groups ug ON ug.usrgrpid = r.groupid
WHERE ug.userid = 5 AND r.permission >= 2
ORDER BY h.host;

-- ── MTTA por analista ───────────────────────────────────────

SET @ts_start = UNIX_TIMESTAMP(DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00'));
SET @ts_end   = UNIX_TIMESTAMP(DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59'));

SELECT
    u.username,
    CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
    COUNT(DISTINCT a.eventid)               AS total_acks,
    ROUND(AVG(a.clock - e.clock) / 60, 1)  AS avg_mtta_min,
    ROUND(MIN(a.clock - e.clock) / 60, 1)  AS min_mtta_min,
    ROUND(MAX(a.clock - e.clock) / 60, 1)  AS max_mtta_min
FROM acknowledges a
JOIN events e   ON e.eventid   = a.eventid
JOIN users u    ON u.userid    = a.userid
JOIN triggers t ON t.triggerid = e.objectid
JOIN functions f ON f.triggerid = t.triggerid
JOIN items i    ON i.itemid    = f.itemid
JOIN hosts h    ON h.hostid    = i.hostid
WHERE e.source = 0 AND e.object = 0
  AND e.clock  BETWEEN @ts_start AND @ts_end
  AND a.clock  BETWEEN @ts_start AND @ts_end
  AND a.acknowledgeid = (
      SELECT MIN(a2.acknowledgeid)
      FROM acknowledges a2
      WHERE a2.eventid = a.eventid
  )
GROUP BY u.userid, u.username, u.name, u.surname
ORDER BY avg_mtta_min ASC;

-- ── Alertas herdados (abertos antes do turno, sem recovery) ─

SELECT
    FROM_UNIXTIME(e.clock) AS event_datetime,
    e.severity,
    t.description AS trigger_desc,
    h.host
FROM events e
LEFT JOIN event_recovery er ON er.eventid = e.eventid
JOIN triggers t  ON t.triggerid = e.objectid
JOIN functions f ON f.triggerid = t.triggerid
JOIN items i     ON i.itemid    = f.itemid
JOIN hosts h     ON h.hostid    = i.hostid
WHERE e.source = 0 AND e.object = 0 AND e.value = 1
  AND e.clock < @ts_start
  AND er.r_eventid IS NULL
ORDER BY e.severity DESC, e.clock ASC;

-- ── Alertas sem ACK no turno ────────────────────────────────

SELECT
    FROM_UNIXTIME(e.clock) AS event_datetime,
    e.severity,
    t.description AS trigger_desc,
    h.host
FROM events e
JOIN triggers t  ON t.triggerid = e.objectid
JOIN functions f ON f.triggerid = t.triggerid
JOIN items i     ON i.itemid    = f.itemid
JOIN hosts h     ON h.hostid    = i.hostid
WHERE e.source = 0 AND e.object = 0 AND e.value = 1
  AND e.clock BETWEEN @ts_start AND @ts_end
  AND NOT EXISTS (
      SELECT 1 FROM acknowledges ak WHERE ak.eventid = e.eventid
  )
ORDER BY e.severity DESC, e.clock DESC;

-- ── Top hosts por volume de alertas ─────────────────────────

SELECT
    h.host, h.name AS host_name,
    COUNT(e.eventid) AS event_count,
    MAX(e.severity)  AS max_severity
FROM events e
JOIN triggers t  ON t.triggerid = e.objectid
JOIN functions f ON f.triggerid = t.triggerid
JOIN items i     ON i.itemid    = f.itemid
JOIN hosts h     ON h.hostid    = i.hostid
WHERE e.source = 0 AND e.object = 0 AND e.value = 1
  AND e.clock BETWEEN @ts_start AND @ts_end
GROUP BY h.hostid, h.host, h.name
ORDER BY event_count DESC
LIMIT 10;

-- ── Presença de analistas no turno ──────────────────────────

SELECT
    cus.username, cus.name AS fullname, cus.noc_context,
    MIN(cus.session_start) AS first_seen,
    MAX(cus.lastaccess)    AS last_seen,
    TIMESTAMPDIFF(MINUTE, MIN(cus.session_start), MAX(cus.lastaccess)) AS online_minutes
FROM module_plantonistas_user_sessions cus
WHERE cus.lastaccess BETWEEN FROM_UNIXTIME(@ts_start) AND FROM_UNIXTIME(@ts_end)
GROUP BY cus.userid, cus.username, cus.name, cus.noc_context
ORDER BY first_seen ASC;

-- ── Crescimento do Diário de Bordo ──────────────────────────
--
-- O módulo NÃO expira notas: é histórico permanente, por decisão. Esta
-- consulta existe para a tabela não crescer sem ninguém olhar. Só vira
-- assunto quando o tamanho incomodar de verdade — arquivar é decisão de
-- quem opera.

SELECT
    COUNT(*)                                   AS notas,
    MIN(n.created_at)                          AS mais_antiga,
    MAX(n.created_at)                          AS mais_recente,
    -- LENGTH (bytes), não CHAR_LENGTH (caracteres): o número tem de ser
    -- comparável com o `mb` em disco da consulta abaixo, e texto PT-BR com
    -- acento ocupa mais bytes que caracteres em UTF-8.
    ROUND(SUM(LENGTH(n.notes)) / 1048576, 2) AS mb_de_texto
FROM module_plantonistas_shift_notes n;

-- Tamanho real em disco (dados + índices), para as 9 tabelas do módulo
SELECT table_name,
       table_rows,
       ROUND((data_length + index_length) / 1048576, 2) AS mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
  -- BASE TABLE espelha o `relkind = 'r'` do arquivo do PostgreSQL: uma view
  -- com este prefixo apareceria com data_length nulo.
  AND table_type = 'BASE TABLE'
  AND table_name LIKE 'module\\_plantonistas\\_%'
ORDER BY (data_length + index_length) DESC;

-- ============================================================
