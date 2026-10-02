<?php
/**
 * zte_onu :: lista FECHADA de comandos do driver ZTE C320 V2.1.x.
 *
 * Cada comando e um template fixo; os parametros sao inteiros validados por faixa ou IP
 * validado. Nao existe forma de mandar texto livre a OLT a partir daqui.
 *
 * So entram comandos cuja saida foi vista na OLT real (Fase 0). Os unicos que alteram algo sao
 * os 4 da atualizacao por ONU (remote-unit update/activate/commit/abort); nada de file download.
 */
require_once __DIR__ . '/../../../Core/ZteErro.php';
require_once __DIR__ . '/../../../Core/Validar.php';

final class ComandosZteC320V21
{
    public const TEMPLATES = [
        'versao'      => 'show version-running',
        'estado_pon'  => 'show gpon onu state gpon-olt_%d/%d/%d',
        'base_pon'    => 'show gpon onu baseinfo gpon-olt_%d/%d/%d',
        'detalhe_onu' => 'show gpon onu detail-info gpon-onu_%d/%d/%d:%d',
        'equip_onu'   => 'show gpon remote-onu equip gpon-onu_%d/%d/%d:%d',
        'versao_sw'   => 'show remote-unit information gpon-olt_%d/%d/%d %d',
        'versao_sw_faixa' => 'show remote-unit information gpon-olt_%d/%d/%d %d-%d',
        'ping'        => 'ping %s',
        // Andamento da atualizacao manual (saida vista na OLT real no 1o upgrade, 01/10).
        'status_upgrade' => 'show remote-unit update-status gpon-olt_%d/%d/%d %d',
        // Resumo das atualizacoes manuais: traz o MOTIVO de falha do download para a flash da OLT.
        'resumo_manual'  => 'show remote-unit summary-of manual',
        // Listagem de uma pasta da flash: a linha "Total disk size: N bytes (M bytes free)" e o
        // espaco livre (o modo remote baixa o firmware para a flash antes de enviar a ONU).
        'pasta_flash'    => 'show file %s device flash',
        // Atualizacao manual por ONU (Fase 0, rodada 8). A OLT busca o arquivo DIRETO no FTP e
        // grava no banco inativo; activate reinicia no banco novo; commit confirma.
        'atualizar'   => 'remote-unit update %s gpon-olt_%d/%d/%d %d remote ftp ipaddress %s path %s user %s password %s',
        'ativar'      => 'remote-unit activate gpon-olt_%d/%d/%d %d',
        'confirmar'   => 'remote-unit commit gpon-olt_%d/%d/%d %d',
        'abortar'     => 'remote-unit abort gpon-olt_%d/%d/%d %d',
    ];

    /**
     * remote-unit update — cada parametro validado contra o que a propria OLT aceita:
     * arquivo 1-64, path 3-128 (pasta na visao da conta da OLT), IP literal, usuario e senha sem
     * espaco/aspas/ponto e virgula (a linha nao pode ser quebrada nem ganhar comando extra).
     */
    public static function atualizar(int $shelf, int $slot, int $pon, int $onu, array $fw): string
    {
        $arquivo = (string) ($fw['arquivo'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $arquivo)) {
            throw new ZteErro('ZTE-VAL-003', ['campo' => 'arquivo']);
        }
        $caminho = (string) ($fw['caminho'] ?? '');
        if (!preg_match('#^[A-Za-z0-9._/-]{3,128}$#', $caminho) || str_contains($caminho, '..')) {
            throw new ZteErro('ZTE-VIN-006', ['caminho' => mb_substr($caminho, 0, 40)]);
        }
        if (filter_var($fw['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ZteErro('ZTE-VIN-003');
        }
        $usuario = Validar::usuarioRemoto($fw['usuario'] ?? '');
        $senha = Validar::senhaRemota($fw['senha'] ?? '');
        return sprintf(self::TEMPLATES['atualizar'], $arquivo, self::f($shelf, self::SHELF), self::f($slot, self::SLOT),
            self::f($pon, self::PON), self::f($onu, self::ONU), $fw['host'], $caminho, $usuario, $senha);
    }

    public static function ativar(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['ativar'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT), self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    public static function confirmar(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['confirmar'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT), self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    public static function abortar(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['abortar'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT), self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    // Faixas aceitas. C320: um shelf; slots de placa PON baixos; ate 16 PONs por placa; 128 ONUs por PON.
    private const SHELF = [1, 1];
    private const SLOT  = [1, 21];
    private const PON   = [1, 16];
    private const ONU   = [1, 128];

    public static function versao(): string
    {
        return self::TEMPLATES['versao'];
    }

    public static function estadoPon(int $shelf, int $slot, int $pon): string
    {
        return sprintf(self::TEMPLATES['estado_pon'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT), self::f($pon, self::PON));
    }

    public static function basePon(int $shelf, int $slot, int $pon): string
    {
        return sprintf(self::TEMPLATES['base_pon'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT), self::f($pon, self::PON));
    }

    public static function detalheOnu(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['detalhe_onu'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT),
            self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    public static function equipOnu(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['equip_onu'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT),
            self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    public static function versaoSw(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['versao_sw'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT),
            self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    public static function statusUpgrade(int $shelf, int $slot, int $pon, int $onu): string
    {
        return sprintf(self::TEMPLATES['status_upgrade'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT),
            self::f($pon, self::PON), self::f($onu, self::ONU));
    }

    public static function resumoManual(): string
    {
        return self::TEMPLATES['resumo_manual'];
    }

    /** Pastas da flash que podem ser LISTADAS (so leitura) para saber o espaco livre. */
    // "other" primeiro: e onde a OLT guarda a imagem de ONU baixada do FTP (01/10).
    public const PASTAS_FLASH = ['other', 'version-ru', 'log-dbg', 'debug-file'];

    public static function pastaFlash(string $pasta): string
    {
        if (!in_array($pasta, self::PASTAS_FLASH, true)) {
            throw new ZteErro('ZTE-VAL-003', ['campo' => 'pasta']);
        }
        return sprintf(self::TEMPLATES['pasta_flash'], $pasta);
    }

    public static function versaoSwFaixa(int $shelf, int $slot, int $pon, int $de, int $ate): string
    {
        if ($ate < $de) {
            throw new ZteErro('ZTE-VAL-007', ['min' => $de, 'max' => $ate]);
        }
        return sprintf(self::TEMPLATES['versao_sw_faixa'], self::f($shelf, self::SHELF), self::f($slot, self::SLOT),
            self::f($pon, self::PON), self::f($de, self::ONU), self::f($ate, self::ONU));
    }

    /** So endereco IP literal: o nome do FTP visto pela OLT tem de ser IP, nao hostname. */
    public static function ping(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ZteErro('ZTE-VAL-001', ['valor' => mb_substr($ip, 0, 40)]);
        }
        return sprintf(self::TEMPLATES['ping'], $ip);
    }

    private static function f(int $v, array $faixa): int
    {
        if ($v < $faixa[0] || $v > $faixa[1]) {
            throw new ZteErro('ZTE-VAL-007', ['min' => $faixa[0], 'max' => $faixa[1]]);
        }
        return $v;
    }
}
