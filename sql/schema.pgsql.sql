-- ARQUIVO GERADO — não edite à mão.
--
-- Fonte: Schema.php (descrição única das tabelas do módulo).
-- Regenerar: php scripts/gen_schema.php
--
-- Dialeto: PostgreSQL
-- Módulo:  Plantonistas (Zabbix 7.0 LTS)
--
-- Este arquivo serve para instalação manual e consulta. Em uso normal
-- quem cria e migra as tabelas é o Module::init(), a cada request, de
-- forma idempotente — não é preciso rodar nada disto à mão.

-- ── module_plantonistas_phones ──────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_phones (
  userid BIGINT NOT NULL,
  phone VARCHAR(100) NOT NULL DEFAULT '',
  PRIMARY KEY (userid)
);

-- ── module_plantonistas_schedule ────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_schedule (
  scheduleid BIGSERIAL,
  usrgrpid BIGINT NOT NULL DEFAULT 0,
  shift_id BIGINT NOT NULL DEFAULT 0,
  userid BIGINT NOT NULL,
  userid_reserva BIGINT,
  schedule_date DATE NOT NULL,
  created_by BIGINT NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (scheduleid),
  CONSTRAINT uniq_group_day_shift UNIQUE (usrgrpid, schedule_date, shift_id)
);
CREATE INDEX IF NOT EXISTS idx_sched_shift ON module_plantonistas_schedule (shift_id);
COMMENT ON TABLE module_plantonistas_schedule IS 'Escala de plantão: 1 titular por grupo/dia, ou por grupo/dia/turno quando há turnos cadastrados';
COMMENT ON COLUMN module_plantonistas_schedule.usrgrpid IS 'FK -> usrgrp.usrgrpid';
COMMENT ON COLUMN module_plantonistas_schedule.shift_id IS 'FK -> module_plantonistas_shifts.id; 0 = grupo sem turnos (legado)';

-- ── module_plantonistas_history ─────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_history (
  historyid BIGSERIAL,
  scheduleid BIGINT NOT NULL DEFAULT 0,
  usrgrpid BIGINT NOT NULL DEFAULT 0,
  shift_id BIGINT NOT NULL DEFAULT 0,
  shift_name VARCHAR(50) NOT NULL DEFAULT '',
  schedule_date DATE NOT NULL,
  action VARCHAR(16) NOT NULL DEFAULT 'save',
  userid_old BIGINT,
  userid_new BIGINT,
  reserva_old BIGINT,
  reserva_new BIGINT,
  changed_by BIGINT NOT NULL DEFAULT 0,
  changed_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (historyid)
);
CREATE INDEX IF NOT EXISTS idx_schedule_date ON module_plantonistas_history (schedule_date);
CREATE INDEX IF NOT EXISTS idx_usrgrpid ON module_plantonistas_history (usrgrpid);
CREATE INDEX IF NOT EXISTS idx_hist_shift ON module_plantonistas_history (shift_id);
COMMENT ON TABLE module_plantonistas_history IS 'Histórico de alterações da escala';
COMMENT ON COLUMN module_plantonistas_history.shift_name IS 'Snapshot do nome do turno — sobrevive a rename/remoção';

-- ── module_plantonistas_user_sessions ───────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_user_sessions (
  id BIGSERIAL,
  userid BIGINT NOT NULL,
  username VARCHAR(100) NOT NULL,
  name VARCHAR(128),
  session_start TIMESTAMP(0) NOT NULL,
  lastaccess TIMESTAMP(0) NOT NULL,
  ip VARCHAR(39),
  noc_context VARCHAR(50),
  PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_cus_userid ON module_plantonistas_user_sessions (userid);
CREATE INDEX IF NOT EXISTS idx_cus_lastaccess ON module_plantonistas_user_sessions (lastaccess);
CREATE INDEX IF NOT EXISTS idx_cus_session_start ON module_plantonistas_user_sessions (session_start);
CREATE INDEX IF NOT EXISTS idx_cus_noc_context ON module_plantonistas_user_sessions (noc_context);
COMMENT ON TABLE module_plantonistas_user_sessions IS 'Presença de analistas — populada por scripts/cron_presence_tracker.php';

-- ── module_plantonistas_shift_notes ─────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_shift_notes (
  id BIGSERIAL,
  shift_date DATE NOT NULL,
  shift_name VARCHAR(50) NOT NULL,
  shift_id BIGINT,
  analyst_userid BIGINT NOT NULL,
  analyst_name VARCHAR(128) NOT NULL,
  notes TEXT NOT NULL,
  notes_format VARCHAR(10) NOT NULL DEFAULT 'text',
  noc_context VARCHAR(50),
  created_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_csn_shift ON module_plantonistas_shift_notes (shift_date, shift_name);
CREATE INDEX IF NOT EXISTS idx_csn_shift_id ON module_plantonistas_shift_notes (shift_id);
CREATE INDEX IF NOT EXISTS idx_csn_analyst ON module_plantonistas_shift_notes (analyst_userid);
CREATE INDEX IF NOT EXISTS idx_csn_noc ON module_plantonistas_shift_notes (noc_context);
COMMENT ON TABLE module_plantonistas_shift_notes IS 'Diário de Bordo — histórico permanente, sem expiração';
COMMENT ON COLUMN module_plantonistas_shift_notes.notes_format IS 'text = legado (escapado na exibição) | html = editor rico (já sanitizado)';

-- ── module_plantonistas_mentions ────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_mentions (
  id BIGSERIAL,
  note_id BIGINT NOT NULL,
  mentioned_userid BIGINT NOT NULL,
  created_by BIGINT NOT NULL,
  is_read SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP(0),
  notified_at TIMESTAMP(0),
  PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_mention_user_unread ON module_plantonistas_mentions (mentioned_userid, is_read);
CREATE INDEX IF NOT EXISTS idx_mention_note ON module_plantonistas_mentions (note_id);
CREATE INDEX IF NOT EXISTS idx_mention_fila ON module_plantonistas_mentions (notified_at);
COMMENT ON TABLE module_plantonistas_mentions IS 'Menções @ do Diário de Bordo';
COMMENT ON COLUMN module_plantonistas_mentions.notified_at IS 'Quando o cron avisou pelo media type; NULL = ainda na fila';

-- ── module_plantonistas_shift_reports ───────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_shift_reports (
  id BIGSERIAL,
  shift_date DATE NOT NULL,
  shift_name VARCHAR(20) NOT NULL,
  generated_by BIGINT NOT NULL,
  noc_context VARCHAR(50),
  generated_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  report_json TEXT NOT NULL,
  PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_csr_shift ON module_plantonistas_shift_reports (shift_date, shift_name);
CREATE INDEX IF NOT EXISTS idx_csr_noc ON module_plantonistas_shift_reports (noc_context);
COMMENT ON TABLE module_plantonistas_shift_reports IS 'Turnos fechados — repasse congelado (issue #2)';
COMMENT ON COLUMN module_plantonistas_shift_reports.generated_by IS 'FK -> users.userid';
COMMENT ON COLUMN module_plantonistas_shift_reports.report_json IS 'Snapshot JSON do relatório no fechamento do turno';

-- ── module_plantonistas_shifts ──────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_shifts (
  id BIGSERIAL,
  usrgrpid BIGINT NOT NULL,
  name VARCHAR(50) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uq_cs_group_name UNIQUE (usrgrpid, name)
);
CREATE INDEX IF NOT EXISTS idx_cs_usrgrp ON module_plantonistas_shifts (usrgrpid);
CREATE INDEX IF NOT EXISTS idx_cs_active ON module_plantonistas_shifts (active);
COMMENT ON TABLE module_plantonistas_shifts IS 'Turnos configuráveis por equipe';
COMMENT ON COLUMN module_plantonistas_shifts.usrgrpid IS 'FK -> usrgrp.usrgrpid (equipe dona do turno)';
COMMENT ON COLUMN module_plantonistas_shifts.name IS 'Ex: Diurno, Turno 1';
COMMENT ON COLUMN module_plantonistas_shifts.end_time IS 'Se <= start_time, o turno vira o dia (madrugada)';

-- ── module_plantonistas_user_shift ──────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_user_shift (
  userid BIGINT NOT NULL,
  shift_id BIGINT NOT NULL,
  updated_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (userid)
);
CREATE INDEX IF NOT EXISTS idx_cush_shift ON module_plantonistas_user_shift (shift_id);
COMMENT ON TABLE module_plantonistas_user_shift IS 'Vínculo analista -> turno (1 turno por analista)';

