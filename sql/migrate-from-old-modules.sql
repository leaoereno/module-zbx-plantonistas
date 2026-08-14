-- ============================================================
-- Migração manual: módulos antigos → Plantonistas v4.0.0
--
-- NORMALMENTE VOCÊ NÃO PRECISA DESTE ARQUIVO.
-- O Module.php faz exatamente estes RENAMEs sozinho, de forma
-- idempotente, na primeira carga com o módulo habilitado.
--
-- Use apenas se preferir migrar manualmente (ex.: janela de
-- manutenção com o frontend parado). RENAME TABLE é atômico e
-- instantâneo (só metadados — nenhuma linha é copiada).
--
-- ATENÇÃO: cada RENAME falha se a tabela destino já existir ou a
-- origem não existir — rode uma vez só, em banco ainda não migrado.
-- ============================================================

RENAME TABLE module_plantao_phones   TO module_plantonistas_phones;
RENAME TABLE module_plantao_schedule TO module_plantonistas_schedule;
RENAME TABLE module_plantao_history  TO module_plantonistas_history;

RENAME TABLE custom_shifts        TO module_plantonistas_shifts;
RENAME TABLE custom_user_shift    TO module_plantonistas_user_shift;
RENAME TABLE custom_shift_notes   TO module_plantonistas_shift_notes;
RENAME TABLE custom_user_sessions TO module_plantonistas_user_sessions;
RENAME TABLE custom_shift_reports TO module_plantonistas_shift_reports;
