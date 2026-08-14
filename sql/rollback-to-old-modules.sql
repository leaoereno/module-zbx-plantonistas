-- ============================================================
-- ROLLBACK: Plantonistas v4.0.0 → módulos antigos
--
-- Devolve as tabelas aos nomes que os módulos antigos
-- (module-zbx-escala-plantao e module-zbx-repasse-plantao) usam.
-- RENAME TABLE é atômico e instantâneo — nenhuma linha é copiada,
-- dados gravados enquanto o Plantonistas esteve ativo são mantidos.
--
-- Ordem do rollback completo:
--   1. Zabbix UI: desabilitar o módulo "Plantonistas"
--      (senão o init() dele renomeia tudo de volta na próxima carga)
--   2. Rodar este SQL
--   3. Zabbix UI: reabilitar os dois módulos antigos
--   4. Restaurar o crontab antigo do presence tracker
-- ============================================================

RENAME TABLE module_plantonistas_phones   TO module_plantao_phones;
RENAME TABLE module_plantonistas_schedule TO module_plantao_schedule;
RENAME TABLE module_plantonistas_history  TO module_plantao_history;

RENAME TABLE module_plantonistas_shifts        TO custom_shifts;
RENAME TABLE module_plantonistas_user_shift    TO custom_user_shift;
RENAME TABLE module_plantonistas_shift_notes   TO custom_shift_notes;
RENAME TABLE module_plantonistas_user_sessions TO custom_user_sessions;
RENAME TABLE module_plantonistas_shift_reports TO custom_shift_reports;
