<?php

namespace Modules\Plantonistas\Actions;

/**
 * SpreadsheetReader — leitura de CSV e XLSX para as telas de importação.
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * O `readCsv()` estava duplicado, linha por linha, entre `PlantaoImport` e
 * `PhonesImport`, e o leitor de XLSX vivia privado dentro do `PlantaoImport` —
 * o que deixava a importação de telefones aceitando só CSV, sem motivo técnico
 * nenhum: o leitor já existia a dois arquivos de distância.
 *
 * ── Sem dependência externa ──────────────────────────────────────────────
 *
 * O XLSX é lido com `ZipArchive` + `simplexml` (um .xlsx é um zip de XML).
 * Nada de biblioteca vendorizada: o F5 bloqueia arquivo estático e o módulo
 * evita dependência de terceiros por princípio.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
trait SpreadsheetReader {

    /**
     * Lê CSV a partir do CONTEÚDO (não do caminho).
     *
     * Detecta o separador entre `;`, TAB e `,` pela primeira linha — planilha
     * salva no Excel pt-BR sai com `;`, exportada de sistema em inglês com `,`,
     * e colada do Sheets com TAB. Também remove o BOM que o Excel escreve.
     *
     * @return array[]|null linhas já divididas em colunas, ou null se vazio
     */
    private function readCsvContent(string $content): ?array {
        $content = ltrim($content, "\xEF\xBB\xBF");   // BOM do Excel

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if (!$lines) {
            return null;
        }

        $first = $lines[0];
        $sep   = ';';

        if (substr_count($first, "\t") > substr_count($first, ';')
                && substr_count($first, "\t") > substr_count($first, ',')) {
            $sep = "\t";
        }
        elseif (substr_count($first, ',') > substr_count($first, ';')) {
            $sep = ',';
        }

        return array_map(fn($l) => str_getcsv($l, $sep, '"', ''), $lines);
    }

    /** Mesmo que readCsvContent(), lendo de um arquivo. */
    private function readCsvFile(string $caminho): ?array {
        $content = file_get_contents($caminho);

        return $content === false ? null : $this->readCsvContent($content);
    }

    /**
     * Lê a primeira planilha de um .xlsx.
     *
     * Trata três coisas que quebram leitor ingênuo de XLSX:
     *
     * - **sharedStrings**: o Excel guarda o texto num dicionário à parte e a
     *   célula referencia o índice (`t="s"`). Sem resolver isso, sai número.
     * - **A planilha nem sempre é `sheet1.xml`**: quando não é, o nome real
     *   vem do relacionamento declarado no `workbook.xml`.
     * - **Células vazias não existem no XML.** A referência (`r="C5"`) é que
     *   diz a coluna; sem preencher os buracos, uma linha com a coluna do meio
     *   vazia sai com as colunas deslocadas para a esquerda — e o import lê o
     *   telefone na coluna do nome, sem erro nenhum.
     *
     * @return array[]|null linhas, ou null se não deu para ler
     */
    private function readXlsxFile(string $caminho): ?array {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($caminho) !== true) {
            return null;
        }

        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = @simplexml_load_string($ssXml);
            if ($ss) {
                foreach ($ss->si as $si) {
                    $val = '';
                    foreach ($si->xpath('.//t') as $t) {
                        $val .= (string) $t;
                    }
                    $sharedStrings[] = $val;
                }
            }
        }

        $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet1 === false) {
            $wbXml = $zip->getFromName('xl/workbook.xml');
            if ($wbXml !== false) {
                $wb = @simplexml_load_string($wbXml);
                if ($wb) {
                    $wb->registerXPathNamespace(
                        'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
                    );
                    $rid = (string) ($wb->sheets->sheet[0]->attributes('r', true)['id'] ?? '');
                    if ($rid) {
                        $sheet1 = $zip->getFromName("xl/worksheets/$rid.xml");
                    }
                }
            }
            if ($sheet1 === false) {
                $zip->close();
                return null;
            }
        }
        $zip->close();

        $xml = @simplexml_load_string($sheet1);
        if (!$xml) {
            return null;
        }
        $xml->registerXPathNamespace(
            'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );

        $rows = [];
        foreach ($xml->xpath('//x:row') as $row) {
            $rowArr  = [];
            $prevCol = -1;

            foreach ($row->xpath('x:c') as $cell) {
                $ref    = (string) ($cell->attributes()['r'] ?? '');
                $colIdx = $this->colRefToIndex(preg_replace('/[0-9]/', '', $ref));

                // Preenche as células que o XML omitiu (ver o docblock).
                for ($g = $prevCol + 1; $g < $colIdx; $g++) {
                    $rowArr[] = '';
                }
                $prevCol = $colIdx;

                $t = (string) ($cell->attributes()['t'] ?? '');
                $v = (string) ($cell->v ?? '');

                if ($t === 's') {
                    $rowArr[] = $sharedStrings[(int) $v] ?? '';
                }
                elseif ($t === 'inlineStr') {
                    $rowArr[] = (string) ($cell->is->t ?? '');
                }
                else {
                    $rowArr[] = $v;
                }
            }

            $rows[] = $rowArr;
        }

        return $rows ?: null;
    }

    /** "AB" → 27. Referência de coluna do Excel é base-26 sem zero. */
    private function colRefToIndex(string $ref): int {
        $idx = 0;

        foreach (str_split(strtoupper($ref)) as $c) {
            $idx = $idx * 26 + (ord($c) - ord('A') + 1);
        }

        return $idx - 1;
    }
}
