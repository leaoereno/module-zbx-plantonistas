# Changelog — module-zbx-plantonistas

Histórico de versões. O **porquê** de cada decisão (e os modos de falha que
motivaram cada correção) fica no `CLAUDE.md`; aqui está o que mudou, do ponto
de vista de quem opera o módulo.

Formato: uma seção por versão, da mais recente para a mais antiga. A coluna
"Precisa de configuração?" avisa quando a atualização exige alguma ação além
de `git pull` + restart do php-fpm.

---

## 5.4.1

Correções de exibição e de escopo, achadas numa auditoria dos números da tela.

| O quê | Precisa de configuração? |
|---|---|
| **Gráfico de severidade em modo barra mostrava `[object Object]`** no tooltip em vez do valor. No Chart.js v4 o `parsed` é número em rosca e objeto `{x,y}` em barra, e o mesmo gráfico troca de tipo no botão "mudar formato" — o defeito só aparecia no modo menos usado | Não |
| **"MTTA por Hora" não respeitava a restrição por papel.** Um usuário User via o KPI dizer "Seu MTTA", a tabela dizer "você não registrou nenhum ACK" e o gráfico ao lado desenhar as barras de todos — além de expor o tempo de resposta dos colegas, que os outros três pontos já protegiam | Não |
| **XSS armazenado no PDF**: o rótulo do turno (nome do turno + nome do grupo, digitados por um Admin) era impresso sem escape. A tela ao vivo escapava nos três pontos equivalentes; só o documento não | Não |
| **Rodapé do PDF dizia "Módulo Repasse v2.5.0"** desde o fork — número fixo justamente no artefato que fica arquivado. Agora lê o `manifest.json`, como a tela | Não |
| **Coluna "Notas" da lista** repetia a mesma contagem em cada refechamento, o que fazia a coluna somar 15 para 5 notas. Passa a aparecer só na linha do fechamento vigente, e o cabeçalho diz que é o estado de hoje, não o congelado no documento | Não |

## 5.4.0

| O quê | Precisa de configuração? |
|---|---|
| **Tela nova "Repasses (abertos/fechados)"** (`plantonistas.report.list`), no menu Plantão e num botão do cabeçalho do Repasse. Lista o período inteiro: uma linha por **documento fechado** — inclusive os refechamentos, que o banner do Repasse não alcançava porque só mostra o último — e uma linha por **turno aberto**, que é o turno com movimento no Diário de Bordo e sem fechamento. Filtro de datas, atalhos de 7/30/90 dias e filtro por situação | Não |
| **Botão "Baixar PDF" dentro do documento**, ao lado de "Lista de repasses". A página do documento já era a versão imprimível, mas nada nela dizia como baixar; a barra some na impressão | Não |
| Visibilidade **sem regra nova**: fechado passa pelo mesmo `canReadSnapshot()` do PDF (os grupos do autor têm que caber nos do leitor) e aberto vem da contagem de notas, já segmentada por grupo compartilhado | Não |

Teto de 500 fechamentos por consulta: acima disso a tela avisa e pede um
intervalo menor, em vez de varrer a tabela inteira.

---

## 5.3.0

Auditoria de queries, escrita e segurança. Nada de configuração nova; a maior
parte é número errado na tela que passou a ser número certo.

| O quê | Precisa de configuração? |
|---|---|
| **Contagens do Repasse deixaram de ser infladas.** O JOIN em `functions`/`items` devolve uma linha por item da trigger, então uma trigger com 2 itens contava cada evento duas vezes: "críticos + médios + baixos" somava mais que o total, a rosca de severidade não batia com o KPI, o heatmap mostrava mais críticos que eventos no dia, e o MTTA por analista era ponderado pelo tamanho da expressão da trigger | Não |
| **KPI de alarme não mente mais.** As 4 tabelas mostram no máximo 50 linhas e o card exibia esse 50 como se fosse o total — um turno com 213 alertas sem ACK aparecia como "50". Agora sai **50+**, com aviso no rodapé da tabela | Não |
| **Gravação que falha deixou de ser reportada como sucesso.** `\DBexecute()` devolve `false` em vez de lançar, e o retorno era ignorado em Escala, Telefones e nos dois imports: "30 linhas importadas" sem nada gravado, "Plantão removido" com a linha ainda no calendário | Não |
| **Menos consultas por página.** A migração de schema saiu do caminho de toda requisição do frontend (3 consultas ao `INFORMATION_SCHEMA` por página, para todo usuário), Gerenciar Turnos passou de 2 consultas por equipe para 2 no total, e o filtro de hosts deixou de repetir milhares de ids em cada consulta | Não |
| **Mensagem de erro parou de expor SQL** no Diário de Bordo, e a nota HTML passa pela sanitização também na exibição | Não |
| **Importação de XLSX** aceita planilha cuja primeira aba não se chama `sheet1.xml` (export do LibreOffice/Sheets) — o caminho alternativo existia mas nunca funcionou | Não |
| Cron de sincronismo de escalonamento não depende mais da extensão `mysqli` estar instalada (só afetava frontend com PostgreSQL) | Não |

Detalhe de operação: ao adicionar migração de schema nova, **suba
`Module::SCHEMA_VERSION`** — é ele que invalida o marcador que evita a
revalidação a cada requisição (a revalidação de segurança roda a cada 24 h de
qualquer forma). Ver `CLAUDE.md`.

---

## 5.2.1

Correção de dois bugs que a 5.2.0 introduziu no Repasse de Plantão:

| O quê | Precisa de configuração? |
|---|---|
| **MTTA por Hora, Distribuição por Severidade e Volume de Alertas (30 dias) pararam de renderizar**, com um bloco de texto cru no fim da página. Um comentário do JavaScript continha, por extenso, a própria sequência de fechamento do `<script>` que ele explicava ser perigosa — o navegador fechou a tag ali e jogou o resto do script como texto | Não |
| **Lupa "Ver no Zabbix"** nas 4 tabelas de alarme (Herdados, Sem ACK, Em Tratativas, Resolvidos) abria a lista geral de Alarmes filtrada por nome, não o alarme clicado — agora abre a página nativa "Detalhes do evento" (`tr_events.php`) direto no evento | Não |

---

## 5.2.0

Repasse de Plantão ganhou histórico de ações e mais duas tabelas de alarme:

| O quê | Precisa de configuração? |
|---|---|
| **Coluna Ações** em Alertas Herdados e Alertas Sem ACK: o que o analista fez no alarme (ACK, mensagem, mudança de severidade, fechar, suprimir) e o que o Zabbix notificou sozinho (e-mail/SMS/webhook das Ações configuradas), num chip por evento | Não |
| **Alarmes em Tratativas** (nova tabela): alarmes abertos durante o turno que já têm alguma ação — complemento direto de Alertas Sem ACK | Não |
| **Alarmes Resolvidos** (nova tabela, histórico do turno): alarmes cuja resolução caiu dentro da janela do turno, com MTTR e se o fechamento foi manual (e por quem) ou automático | Não |
| As duas tabelas novas e a coluna Ações entram também no PDF e no documento de "Fechar Turno" | Não |

Alarmes Resolvidos depende da tabela `problem` do Zabbix, que o housekeeper
limpa depois de um tempo configurável — turnos antigos podem aparecer vazios
ali mesmo tendo tido resolução (mesma limitação que Alertas Herdados já tinha,
ver `CLAUDE.md`).

---

## 5.1.0

Rodada de correções e revisão pré-produção, em cima da 5.0.0:

| O quê | Precisa de configuração? |
|---|---|
| **Repasse usa as cores e nomes REAIS de severidade** de Administração > Geral > Opções de exibição de acionadores, em vez de rótulos/cores fixos no PHP e no CSS (que nem batiam com o padrão de fábrica do Zabbix) | Não |
| **Escala: reserva sem nome no hover/card** quando o cadastro não tinha `name`/`surname` preenchido — faltava o `username` como reserva (a Visão Geral já fazia certo) | Não |
| **`<select>` desalinhado** no cabeçalho do Repasse e em Gerenciar Turnos — Zabbix vencia a disputa de altura/padding contra a classe do módulo | Não |
| **`install.sh` detecta o caminho certo do módulo e o dialeto MySQL/PostgreSQL sozinho**, e funciona em host sem `/etc/cron.d` (Amazon Linux 2023 e afins) — cai para `crontab` do usuário e, na ausência dele, gera timer systemd. Passa a oferecer os 3 crons (presença, escalonamento, menções) na instalação, não só o de presença | Não |
| **Suíte de testes automatizados** (`tests/`, ver seção própria abaixo) | Não |

---

## 5.0.0

Fecha as 6 issues abertas. As decisões e os modos de falha de cada uma estão
no `CLAUDE.md`; o estado por issue, no `ROADMAP.md`.

| O quê | Issue | Precisa de configuração? |
|---|---|---|
| **PostgreSQL** — DDL, consultas, migrações e crons por dialeto; o módulo inteiro roda nos dois bancos | [#1](https://github.com/leaoereno/module-zbx-plantonistas/issues/1) | Só `DB_TYPE=pgsql` nos crons |
| **Fechar turno** — o repasse vira documento imutável, com trava contra corrida | [#2](https://github.com/leaoereno/module-zbx-plantonistas/issues/2) | Não |
| **CSRF ligado** nas 10 actions que escrevem no banco | [#3](https://github.com/leaoereno/module-zbx-plantonistas/issues/3) | Não |
| **Cron de presença sem a API do Zabbix** — some o TLS sem verificação e o token de API | [#4](https://github.com/leaoereno/module-zbx-plantonistas/issues/4) | Limpeza: tirar `ZABBIX_*` do cron e revogar o token |
| **Escala alimenta o escalonamento do Zabbix** — grupo por equipe com o plantonista do turno | [#5](https://github.com/leaoereno/module-zbx-plantonistas/issues/5) | **Sim** — criar os grupos e agendar o cron |
| **Menção notifica pelo media type** do usuário, com fila e dedupe | [#6](https://github.com/leaoereno/module-zbx-plantonistas/issues/6) | **Sim** — token de API; opcional |

### Segurança

- **CSRF exigido** nas actions de escrita (antes: `disableCsrfValidation()` em
  todas). Um `GET` numa aba qualquer não escala mais ninguém nem apaga turno.
- **Fim do TLS sem verificação** no cron de presença — ele não chama mais a
  API do Zabbix, lê tudo do banco. O `ZABBIX_API_TOKEN` sai do arquivo de cron
  e pode ser revogado.
- **Fim da conexão `mysqli` própria** — tudo passa pela camada de banco do
  Zabbix (`ZbxDb`), com bind de parâmetro no lugar de concatenação.
- **Credencial só em arquivo `chmod 600`**, nunca no repositório.

### Corretude e concorrência

- **Fechar turno com trava consultiva** — dois analistas fechando o mesmo
  turno não geram dois snapshots.
- **`PhonesSave` por upsert** — fecha a janela de corrida entre ler e gravar.
- **Duplo clique não gera dois POSTs.**
- **Fuso do PHP × fuso do banco** duplicando presença no turno da noite.
- **Nome em branco** de conta de serviço, **nome longo** descartando a linha de
  presença e **nome com apóstrofo** quebrando o botão "remover" da Escala.
- **Telefones filtra por grupo, não por papel.**
- **SQL de "marcar menção como lida"** que nunca marcava.

### Interface e usabilidade

- **Visual unificado** seguindo o tema do Zabbix.
- **Histórico de menções** no Repasse.
- **Vínculo analista→turno em massa** em Gerenciar Turnos.
- **CSV/XLSX da Escala** passa a conhecer turnos; import de telefones aceita
  XLSX.
- **Actions de escrita respondem JSON** em request AJAX — erro aparece na tela
  em vez de virar página em branco.
- **Aviso quando o gráfico não carrega**, em vez de área vazia sem explicação.
