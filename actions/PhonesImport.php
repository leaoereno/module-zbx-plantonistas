<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseRedirect, CUrl, CWebUser;

/**
 * PhonesImport — importação em massa de telefones via CSV.
 *
 * Layout esperado: o MESMO arquivo que "Exportar Usuários" gera
 * (`Usuario;Nome;Telefone`), para o fluxo natural ser exportar → editar no
 * Excel → reimportar. A coluna Nome é ignorada na importação (existe só para
 * quem preenche a planilha saber de quem é a linha); o casamento é por
 * `users.username`, que é único e não muda com correção de cadastro.
 *
 * Permissão: a MESMA da edição individual (PhonesSave) — Super Admin altera
 * qualquer um; os demais só quem compartilha pelo menos um grupo. Linha
 * de usuário fora do alcance é recusada e reportada, não aplicada em silêncio.
 *
 * Telefone vazio na planilha NÃO apaga o cadastro: o CSV exportado traz todos
 * os usuários, a maioria sem telefone, e um round-trip parcial (exportar,
 * editar 3 linhas, reimportar) limparia a base inteira. Para remover, usa-se
 * o campo da tela deixando-o vazio.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class PhonesImport extends CController {

    use SpreadsheetReader;

    use PhonesFormat;

    /** Máximo de dígitos aceitos — acima disso é erro de planilha, não telefone. */
    private const MAX_DIGITS = 15;

    /** Mínimo de dígitos — cobre ramal curto (4) sem aceitar lixo de 1-2 dígitos. */
    private const MIN_DIGITS = 4;

    protected function checkInput(): bool { return true; }

    public function checkPermissions(): bool {
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        $redirect = (new CUrl('zabbix.php'))->setArgument('action', 'plantonistas.phones.list');

        // Upload chega em base64 num campo hidden, mesmo padrão do import da
        // Escala (o form nativo do Zabbix não trata multipart nesta rota).
        $b64   = $_POST['import_file_b64']  ?? '';
        $fname = trim($_POST['import_file_name'] ?? '');

        if ($b64 === '' || $fname === '') {
            $this->err($redirect, 'Nenhum arquivo recebido. Tente novamente.');
            return;
        }

        if (strpos($b64, ',') !== false) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        $content = base64_decode(str_replace(["\n", "\r", ' '], '', $b64), true);

        if ($content === false || $content === '') {
            $this->err($redirect, 'Falha ao decodificar o arquivo. Tente novamente.');
            return;
        }

        // XLSX passou a ser aceito junto com CSV: o leitor já existia no
        // módulo (era privado no PlantaoImport) e agora vive no trait. Como o
        // conteúdo chega em base64, o XLSX precisa ir para um arquivo
        // temporário — o ZipArchive só abre caminho, não string.
        //
        // `.xls` (o formato binário antigo do Excel) fica de fora de
        // propósito: o ZipArchive não o abre, e sem esta checagem o operador
        // receberia a mensagem genérica de arquivo ilegível sem entender que
        // o problema é o formato.
        if (preg_match('/\.xls$/i', $fname)) {
            $this->err($redirect, 'Formato .xls (Excel antigo) não é lido. '
                . 'Salve como .xlsx ou CSV e importe de novo.');
            return;
        }

        if (preg_match('/\.xlsx$/i', $fname)) {
            $tmp = tempnam(sys_get_temp_dir(), 'plt_phones_');
            if ($tmp === false) {
                $this->err($redirect, 'Não foi possível criar arquivo temporário.');
                return;
            }
            file_put_contents($tmp, $content);
            $rows = $this->readXlsxFile($tmp);
            @unlink($tmp);
        }
        else {
            $rows = $this->readCsvContent($content);
        }

        if ($rows === null || count($rows) < 2) {
            $this->err($redirect, 'Arquivo vazio ou ilegível. Use o CSV/XLSX gerado por "Exportar Usuários".');
            return;
        }

        // ── Cabeçalho ────────────────────────────────────────────────────
        $header = array_map(fn($c) => mb_strtolower(trim((string)$c)), $rows[0]);
        [$col_user, $col_phone] = $this->detectColumns($header);

        if ($col_user === -1 || $col_phone === -1) {
            $this->err($redirect,
                'Cabeçalho não reconhecido. São necessárias uma coluna de usuário '
                . '(Usuario/Username/Login) e uma de telefone (Telefone/Celular/Fone). '
                . 'Cabeçalho lido: ' . implode(' | ', array_slice($rows[0], 0, 6)));
            return;
        }

        // ── Alvos permitidos: username → userid ──────────────────────────
        $allowed = $this->buildAllowedMap($current_userid, $is_super);
        if (!$allowed) {
            $this->err($redirect, 'Nenhum usuário na sua estrutura (grupo) para atualizar.');
            return;
        }

        $saved = 0;
        $blank = 0;
        $warn  = [];

        for ($i = 1; $i < count($rows); $i++) {
            $line     = $i + 1;
            $username = trim((string)($rows[$i][$col_user]  ?? ''));
            $raw      = trim((string)($rows[$i][$col_phone] ?? ''));

            if ($username === '' && $raw === '') {
                continue; // linha em branco no fim do arquivo
            }
            if ($username === '') {
                $warn[] = "Linha $line: sem usuário";
                continue;
            }
            if ($raw === '' || $raw === '—') {
                $blank++; // telefone vazio não apaga — ver docblock
                continue;
            }

            // Excel converte telefone só-dígitos em número e devolve notação
            // científica ("9,87654E+10"). Limpar os não-dígitos disso produz um
            // número plausível e ERRADO, que ninguém notaria: recusar é melhor.
            if (preg_match('/\d[eE]\+?\d/', $raw)) {
                $warn[] = "Linha $line ($username): \"$raw\" veio em notação científica do Excel — "
                        . 'formate a coluna como Texto e exporte de novo';
                continue;
            }

            $ukey = mb_strtolower($username);
            if (!isset($allowed[$ukey])) {
                $warn[] = "Linha $line: usuário \"$username\" não existe ou está fora da sua estrutura";
                continue;
            }

            $digits = $this->phoneDigits($raw);
            $len    = strlen($digits);

            if ($len < self::MIN_DIGITS || $len > self::MAX_DIGITS) {
                $warn[] = "Linha $line ($username): telefone \"$raw\" inválido "
                        . "($len dígito(s); esperado entre " . self::MIN_DIGITS . ' e ' . self::MAX_DIGITS . ')';
                continue;
            }

            // Upsert (userid é PK): uma ida ao banco, sem a janela de corrida
            // do SELECT-then-INSERT. O PhonesSave usa a mesma forma.
            $ok = DBexecute(
                'INSERT INTO module_plantonistas_phones (userid, phone)' .
                ' VALUES (' . (int)$allowed[$ukey] . ', ' . zbx_dbstr($digits) . ')' .
                SqlFn::upsert('userid', ['phone'])
            );

            if ($ok) {
                $saved++;
            } else {
                $warn[] = "Linha $line ($username): falha ao gravar no banco";
            }
        }

        $msg = $saved . ' telefone(s) atualizado(s).';
        if ($blank > 0) {
            $msg .= " $blank linha(s) sem telefone ignorada(s).";
        }

        if ($warn) {
            $detail = implode(' | ', array_slice($warn, 0, 5));
            if (count($warn) > 5) {
                $detail .= ' … e mais ' . (count($warn) - 5) . ' aviso(s).';
            }
            $this->err($redirect, $msg . ' Avisos (' . count($warn) . '): ' . $detail);
            return;
        }

        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('success', $msg)
        ));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} índices das colunas de usuário e telefone */
    private function detectColumns(array $header): array {
        $col_user  = -1;
        $col_phone = -1;

        foreach ($header as $i => $h) {
            $h = $this->stripAccents($h);
            if ($col_user === -1 && in_array($h, ['usuario', 'username', 'login', 'user'], true)) {
                $col_user = $i;
            }
            if ($col_phone === -1 && in_array($h, ['telefone', 'celular', 'fone', 'phone', 'contato', 'ramal'], true)) {
                $col_phone = $i;
            }
        }

        return [$col_user, $col_phone];
    }

    /** "Usuário" → "usuario": o cabeçalho pode vir acentuado ou não. */
    private function stripAccents(string $s): string {
        return strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e',
            'í'=>'i','ì'=>'i',
            'ó'=>'o','õ'=>'o','ô'=>'o','ò'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u',
            'ç'=>'c',
        ]);
    }

    /**
     * username (minúsculo) → userid dos usuários que este operador pode
     * alterar. Repete a regra do PhonesSave: Super Admin altera todos; os
     * demais, só quem compartilha grupo E não tem papel mais alto que o seu.
     *
     * A guarda de papel vale só para a ESCRITA. Enxergar o telefone de todo
     * mundo do grupo é o objetivo da tela; alterar o cadastro de um Super
     * Admin não é.
     */
    private function buildAllowedMap(int $current_userid, bool $is_super): array {
        if ($is_super) {
            $sql = "SELECT userid, username FROM users WHERE LOWER(username) <> 'guest'";
        } else {
            $meu_tipo = (int) CWebUser::$data['type'];

            $sql =
                'SELECT DISTINCT u.userid, u.username' .
                ' FROM users u' .
                ' JOIN users_groups ug1 ON ug1.userid = u.userid' .
                ' JOIN users_groups ug2 ON ug2.usrgrpid = ug1.usrgrpid' .
                ' LEFT JOIN role r ON r.roleid = u.roleid' .
                ' WHERE ug2.userid = ' . $current_userid .
                '   AND COALESCE(r.type, 1) <= ' . $meu_tipo;
        }

        $map = [];
        $res = DBselect($sql);
        while ($row = DBfetch($res)) {
            $map[mb_strtolower($row['username'])] = (int)$row['userid'];
        }

        return $map;
    }

    private function err(CUrl $redirect, string $msg): void {
        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('error', $msg)
        ));
    }
}
