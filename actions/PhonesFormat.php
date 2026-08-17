<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

/**
 * PhonesFormat — normalização e máscara de telefone (padrão brasileiro).
 *
 * O banco (`module_plantonistas_phones.phone`) guarda SÓ DÍGITOS: quem salva
 * já aplica `preg_replace('/\D/', '', ...)`. Máscara é assunto de exibição —
 * este trait é o único lugar que sabe formatar, e é compartilhado pela tela,
 * pelo CSV de exportação e pela importação em massa.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
trait PhonesFormat {

    /** Remove máscara, espaços e qualquer caractere não numérico. */
    protected function phoneDigits(string $raw): string {
        return preg_replace('/\D/', '', $raw) ?? '';
    }

    /**
     * Aplica máscara brasileira sobre uma sequência de dígitos.
     *
     *   11 dígitos → (11) 98765-4321   celular com DDD
     *   10 dígitos → (11) 3456-7890    fixo com DDD
     *    9 dígitos → 98765-4321        celular sem DDD
     *    8 dígitos → 3456-7890         fixo sem DDD
     *
     * Qualquer outro tamanho volta como veio, sem máscara: ramal de 4 dígitos,
     * 0800, número com +55 colado ou cadastro digitado errado. Enfiar
     * parênteses num número que não tem DDD produziria um telefone com cara de
     * certo e conteúdo errado — pior que mostrar cru.
     */
    protected function formatPhoneBr(string $phone): string {
        $d = $this->phoneDigits($phone);
        $n = strlen($d);

        // Não geográfico (0800, 0300, 0500…): DDD brasileiro vai de 11 a 99,
        // nunca começa com 0, então o zero inicial identifica esse caso com
        // segurança. Sem isto, 08007771234 sairia como "(08) 00777-1234".
        if ($n === 11 && $d[0] === '0') {
            return substr($d, 0, 4) . ' ' . substr($d, 4, 3) . '-' . substr($d, 7);
        }
        if ($n === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if ($n === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }
        if ($n === 9) {
            return substr($d, 0, 5) . '-' . substr($d, 5);
        }
        if ($n === 8) {
            return substr($d, 0, 4) . '-' . substr($d, 4);
        }

        return $phone;
    }
}
