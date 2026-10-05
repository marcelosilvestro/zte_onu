<?php
/**
 * zte_onu :: respostas da OLT simulada, a partir das saidas REAIS gravadas em fixtures/.
 *
 * Usado pelo TransporteFixture (OLT simulada no cadastro) e pela OLT falsa dos testes de telnet:
 * as duas respondem exatamente o mesmo texto, entao o parser e testado contra o formato real.
 *
 * Mapa da simulacao (OLT de referencia, slot 1 com GTGH):
 *   PON 1/1/1 -> as 27 ONUs do fixture; demais PONs -> vazias
 *   detail-info / equip de qualquer ONU listada -> o fixture, com nome/SN/indice trocados
 */
final class Fixtures
{
    public const DIR_PADRAO = __DIR__ . '/fixtures/zte_c320_v2.1.0';
    public const NOME_OLT   = 'OLT-c320_1';

    public static function responder(string $linha, string $dir = self::DIR_PADRAO): string
    {
        $linha = trim(preg_replace('/\s+/', ' ', $linha));

        if ($linha === 'show version-running') {
            return self::corpo($dir, 'show_version_running.txt');
        }
        if (preg_match('#^show gpon onu (state|baseinfo) gpon-olt_(\d+)/(\d+)/(\d+)$#', $linha, $m)) {
            // Placas PON da OLT de referencia: slots 1 e 2 (o "show remote-unit information ?"
            // real lista gpon-olt_1/1 e gpon-olt_1/2), com 16 portas. Fora disso, erro de parametro.
            if ($m[2] !== '1' || !in_array($m[3], ['1', '2'], true) || (int) $m[4] < 1 || (int) $m[4] > 16) {
                return "                                        ^\n%Error 20202: Invalid input detected at '^' marker.Invalid parameter";
            }
            if ($m[3] === '1' && $m[4] === '1') {
                return self::corpo($dir, $m[1] === 'state' ? 'show_gpon_onu_state.txt' : 'show_gpon_onu_baseinfo.txt');
            }
            return self::corpo($dir, 'show_gpon_onu_state_pon_vazia.txt');   // formato real (01/10)
        }
        if (preg_match('#^show gpon onu detail-info gpon-onu_1/1/1:(\d+)$#', $linha, $m) && self::existe((int) $m[1], $dir)) {
            return self::personalizar(self::corpo($dir, 'show_gpon_onu_detail_info.txt'), (int) $m[1]);
        }
        if (preg_match('#^show gpon remote-onu equip gpon-onu_1/1/1:(\d+)$#', $linha, $m) && self::existe((int) $m[1], $dir)) {
            // Furukawa (bridge, chipset ZTE) responde ao OMCI com o proprio equip (saida real, 05/10).
            return self::personalizar(self::corpo($dir, self::furukawa((int) $m[1])
                ? 'show_gpon_remote_onu_equip_furukawa.txt' : 'show_gpon_remote_onu_equip.txt'), (int) $m[1]);
        }
        if (preg_match('#^show remote-unit information gpon-olt_1/1/1 (\d+)-(\d+)$#', $linha, $m)) {
            $blocos = [];
            for ($n = (int) $m[1]; $n <= (int) $m[2]; $n++) {
                if (self::existe($n, $dir)) {
                    $b = self::responder('show remote-unit information gpon-olt_1/1/1 ' . $n, $dir);
                    $blocos[] = $b;
                }
            }
            return implode("\n\n", $blocos);
        }
        if (preg_match('#^show remote-unit information gpon-olt_1/1/1 (\d+)$#', $linha, $m) && self::existe((int) $m[1], $dir)) {
            $n = (int) $m[1];
            // Furukawa responde com o proprio bloco (RuType 630-10B), saida real de 05/10.
            if (self::furukawa($n, $dir)) {
                return str_replace('gpon-onu_1/1/1: 2', 'gpon-onu_1/1/1: ' . $n, self::corpo($dir, 'show_remote_unit_information_furukawa.txt'));
            }
            $t = self::corpo($dir, 'show_remote_unit_information.txt');
            $t = str_replace('gpon-onu_1/1/1: 1', 'gpon-onu_1/1/1: ' . $n, $t);
            // Uma em cada tres ONUs ja esta na versao mais nova (V9.0.11P3N10), as outras na
            // P1N52 do fixture real: da para simular campanha com quem entra e quem ja esta em dia.
            if ($n % 3 === 0) {
                $t = str_replace(['V9.0.11P1N52', 'V9.0.11P1N48'], ['V9.0.11P3N10', 'V9.0.11P1N52'], $t);
            }
            return $t;
        }
        // Flash: so "other" tem arquivos (e por isso mostra o espaco livre), como na OLT real.
        if ($linha === 'show file other device flash') {
            return self::corpo($dir, 'show_file_other_flash.txt');
        }
        if (preg_match('#^show file [a-z-]+ device flash$#', $linha)) {
            return self::corpo($dir, 'show_file_version_ru_flash_vazia.txt');
        }
        if ($linha === 'show remote-unit summary-of manual') {
            return "Download:\n\nOperating:\n\nWaiting:\n\nFail:\n\nSuccess:\n";
        }
        if (preg_match('#^ping ([0-9a-fA-F.:]+)$#', $linha, $m)) {
            return str_replace('192.0.2.10', $m[1], self::corpo($dir, 'ping_ok.txt'));
        }
        return '%Error 20206: Unrecognized command';
    }

    /** Fixture sem a primeira linha (o prompt + comando que foi colado junto). */
    public static function corpo(string $dir, string $arquivo): string
    {
        $t = (string) @file_get_contents($dir . '/' . $arquivo);
        $t = str_replace("\r\n", "\n", $t);
        $linhas = explode("\n", rtrim($t, "\n"));
        if ($linhas && str_starts_with($linhas[0], self::NOME_OLT)) {
            array_shift($linhas);
        }
        return implode("\n", $linhas);
    }

    private static function existe(int $onu, string $dir): bool
    {
        return (bool) preg_match('#^1/1/1:' . $onu . '\s#m', self::corpo($dir, 'show_gpon_onu_state.txt'));
    }

    /** Posicao Furukawa no baseinfo do fixture (SN FRKW, perfil HBR). */
    private static function furukawa(int $onu, string $dir = self::DIR_PADRAO): bool
    {
        return (bool) preg_match('#gpon-onu_1/1/1:' . $onu . '\s+\S+\s+\S+\s+SN:FRKW#', self::corpo($dir, 'show_gpon_onu_baseinfo.txt'));
    }

    private static function personalizar(string $t, int $onu): string
    {
        // O SN segue o baseinfo: nas posicoes Furukawa (HBR) ele e FRKW.
        $furukawa = self::furukawa($onu);
        $sn = $furukawa ? sprintf('FRKW%08d', $onu) : sprintf('ZTEGD%07d', $onu);
        $t = str_replace('FRKW00000002', $sn, $t);    // fixture do equip da Furukawa
        $t = str_replace('gpon-onu_1/1/1:1', 'gpon-onu_1/1/1:' . $onu, $t);
        $t = str_replace('cliente_teste_01', sprintf('cliente_teste_%02d', $onu), $t);
        $t = str_replace('ZTEGD0000001', $sn, $t);
        if ($furukawa) {
            $t = str_replace(['Type:                HRT', 'from operador by HelpFiber'], ['Type:                HBR', 'bridge'], $t);
        }
        return str_replace('ZTEGd0000001', $furukawa ? $sn : sprintf('ZTEGd%07d', $onu), $t);
    }
}
