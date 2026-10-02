<?php
/**
 * zte_onu :: validacao de entrada.
 *
 * Tudo o que vem da interface passa por aqui antes de chegar ao banco, ao FTP ou a CLI da
 * OLT. Cada metodo devolve o valor NORMALIZADO ou lanca ZteErro com o codigo do problema —
 * nunca "corrige" em silencio um valor perigoso.
 */
require_once __DIR__ . '/ZteErro.php';

final class Validar
{
    /** Segmento de nome de arquivo ou pasta: comeca por letra/numero, sem ".." nem espaco. */
    private const SEGMENTO = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/';

    /** IPv4, IPv6 ou hostname RFC 1123. */
    public static function host($v): string
    {
        $v = strtolower(trim((string) $v));
        if ($v === '' || strlen($v) > 253) {
            throw new ZteErro('ZTE-VAL-001', ['valor' => self::amostra($v)]);
        }
        if (filter_var($v, FILTER_VALIDATE_IP) !== false) {
            return $v;
        }
        $rotulo = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match('/^' . $rotulo . '(?:\.' . $rotulo . ')*$/', $v)) {
            throw new ZteErro('ZTE-VAL-001', ['valor' => self::amostra($v)]);
        }
        // So digitos e pontos que nao formam um IPv4 valido (octeto acima de 255) nao e hostname.
        if (preg_match('/^[0-9.]+$/', $v)) {
            throw new ZteErro('ZTE-VAL-001', ['valor' => self::amostra($v)]);
        }
        return $v;
    }

    public static function porta($v): int
    {
        if (!is_numeric($v) || (string) (int) $v !== trim((string) $v)) {
            throw new ZteErro('ZTE-VAL-002');
        }
        $n = (int) $v;
        if ($n < 1 || $n > 65535) {
            throw new ZteErro('ZTE-VAL-002');
        }
        return $n;
    }

    public static function inteiro($v, int $min, int $max): int
    {
        if (!is_numeric($v) || (string) (int) $v !== trim((string) $v)) {
            throw new ZteErro('ZTE-VAL-007', ['min' => $min, 'max' => $max]);
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            throw new ZteErro('ZTE-VAL-007', ['min' => $min, 'max' => $max]);
        }
        return $n;
    }

    public static function nomeArquivo($v): string
    {
        $v = trim((string) $v);
        if (!preg_match(self::SEGMENTO, $v) || str_contains($v, '..')) {
            throw new ZteErro('ZTE-VAL-003', ['valor' => self::amostra($v)]);
        }
        return $v;
    }

    /**
     * Caminho RELATIVO dentro da raiz de um repositorio: "firmware/f601". Vazio = a propria raiz.
     * Recusa "..", barra inicial, contrabarra, segmento vazio e qualquer caractere fora da lista.
     */
    public static function caminhoRelativo($v): string
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '.') {
            return '';
        }
        if ($v[0] === '/' || str_contains($v, '\\') || strlen($v) > 200) {
            throw new ZteErro('ZTE-VAL-004');
        }
        $partes = explode('/', rtrim($v, '/'));
        if (count($partes) > 8) {
            throw new ZteErro('ZTE-VAL-004');
        }
        foreach ($partes as $p) {
            if (!preg_match(self::SEGMENTO, $p) || str_contains($p, '..')) {
                throw new ZteErro('ZTE-VAL-004');
            }
        }
        return implode('/', $partes);
    }

    /** Raiz ABSOLUTA de um repositorio: "/" ou "/firmwares/zte". Mesmas regras por segmento. */
    public static function raiz($v): string
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '/') {
            return '/';
        }
        if ($v[0] !== '/') {
            throw new ZteErro('ZTE-VAL-004');
        }
        $rel = self::caminhoRelativo(ltrim($v, '/'));
        return '/' . $rel;
    }

    /** Junta raiz + relativo e confere que o resultado continua dentro da raiz. */
    public static function dentroDaRaiz(string $raiz, string $relativo): string
    {
        $raiz = self::raiz($raiz);
        $rel  = self::caminhoRelativo($relativo);
        $final = $rel === '' ? $raiz : rtrim($raiz, '/') . '/' . $rel;
        $prefixo = rtrim($raiz, '/') . '/';
        if ($final !== $raiz && !str_starts_with($final, $prefixo)) {
            throw new ZteErro('ZTE-VAL-004');
        }
        return $final;
    }

    public static function login($v): string
    {
        $v = trim((string) $v);
        if (!preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $v)) {
            throw new ZteErro('ZTE-VAL-005');
        }
        return $v;
    }

    /** Usuario de equipamento/servidor (OLT, FTP): sem espaco nem metacaractere de shell/CLI. */
    public static function usuarioRemoto($v): string
    {
        $v = trim((string) $v);
        if (!preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $v)) {
            throw new ZteErro('ZTE-VAL-009', ['campo' => 'usuario']);
        }
        return $v;
    }

    /**
     * Senha de equipamento/servidor. Aceita qualquer caractere imprimivel, menos os que podem
     * quebrar a linha de comando da OLT (espaco, aspas, ponto e virgula, crase, controle).
     * Se uma OLT exigir senha com esses caracteres, o driver dela trata o escape — ate la, recusa.
     */
    public static function senhaRemota($v): string
    {
        $v = (string) $v;
        if ($v === '' || strlen($v) > 128 || preg_match('/[\s"\'`;\\\\\x00-\x1F\x7F]/', $v)) {
            throw new ZteErro('ZTE-VAL-009', ['campo' => 'senha']);
        }
        return $v;
    }

    public static function hora($v): string
    {
        $v = trim((string) $v);
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $v)) {
            throw new ZteErro('ZTE-VAL-006');
        }
        return $v;
    }

    /** Texto livre de exibicao: tira controle, limita tamanho. Vazio permitido se !$obrigatorio. */
    public static function texto($v, int $max, bool $obrigatorio = false): string
    {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $v));
        if ($obrigatorio && $v === '') {
            throw new ZteErro('ZTE-VAL-008');
        }
        return mb_substr($v, 0, $max);
    }

    /** Nome curto (OLT, repositorio, regra): obrigatorio, sem controle, ate $max. */
    public static function nome($v, int $max = 80): string
    {
        return self::texto($v, $max, true);
    }

    public static function bool($v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'on', 'sim'], true);
    }

    private static function amostra(string $v): string
    {
        return mb_substr($v, 0, 40);
    }
}
