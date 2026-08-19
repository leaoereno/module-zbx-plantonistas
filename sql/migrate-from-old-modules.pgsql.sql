-- ============================================================
-- Migração manual: módulos antigos → Plantonistas
--
-- NORMALMENTE VOCÊ NÃO PRECISA DESTE ARQUIVO.
-- O Module.php faz exatamente estes renames sozinho, de forma
-- idempotente, na primeira carga com o módulo habilitado.
--
-- Use apenas se preferir migrar manualmente (ex.: janela de
-- manutenção com o frontend parado). O rename é atômico e
-- instantâneo (só metadados — nenhuma linha é copiada).
--
-- ATENÇÃO: cada rename falha se a tabela destino já existir ou a
-- origem não existir — rode uma vez só, em banco ainda não migrado.
--
-- Dialeto: PostgreSQL
-- ============================================================

-- O PostgreSQL faz DDL dentro de transação: se qualquer rename falhar,
-- o BEGIN/COMMIT desfaz todos. É mais garantido que no MySQL, onde cada
-- RENAME TABLE é atômico em si, mas não há atomicidade entre eles.

BEGIN;

ALTER TABLE module_plantao_phones RENAME TO module_plantonistas_phones;
ALTER TABLE module_plantao_schedule RENAME TO module_plantonistas_schedule;
ALTER TABLE module_plantao_history RENAME TO module_plantonistas_history;
ALTER TABLE custom_shifts RENAME TO module_plantonistas_shifts;
ALTER TABLE custom_user_shift RENAME TO module_plantonistas_user_shift;
ALTER TABLE custom_shift_notes RENAME TO module_plantonistas_shift_notes;
ALTER TABLE custom_user_sessions RENAME TO module_plantonistas_user_sessions;
ALTER TABLE custom_shift_reports RENAME TO module_plantonistas_shift_reports;

COMMIT;
