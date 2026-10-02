<?php
/**
 * zte_onu :: leitura das saidas da CLI do ZTE C320 V2.1.x.
 *
 * Escrito contra as saidas REAIS da OLT de referencia (lib/Olt/Simulado/fixtures). Regra de
 * ouro: saida fora do formato conhecido vira OltFalha('formato') — nunca um resultado "vazio"
 * ou "ok" presumido. Erro da propria OLT (%Error ...) vira OltFalha('comando').
 */
require_once __DIR__ . '/../../Transporte.php';

final class ParserZteC320V21
{
    /** Estados de fase que contam como ONU online. */
    public const FASES_ONLINE = ['working'];

    /**
     * show version-running
     * @return array{versao:string,versoes:string[],placas:array<int,array{local:string,slot:int,tipo:string,versoes:array<string,string>}>}
     */
    public static function versaoRunning(string $t): array
    {
        self::exigirSemErro($t);
        if (!preg_match('/PhyLoc\s+FileType\s+VerType\s+VerTag/', $t)) {
            throw new OltFalha('formato', 'version-running sem cabecalho');
        }
        $placas = [];
        $mvr = [];
        foreach (explode("\n", $t) as $l) {
            if (!preg_match('#^\s*(\d+)/(\d+)/(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}:\d{2}\s+\d+\s*$#', $l, $m)) {
                continue;
            }
            $local = "$m[1]/$m[2]/$m[3]";
            $placas[$local] ??= ['local' => $local, 'slot' => (int) $m[3], 'tipo' => $m[4], 'versoes' => []];
            $placas[$local]['versoes'][$m[5]] = $m[6];
            if ($m[5] === 'MVR') {
                $mvr[$m[6]] = true;
            }
        }
        if (!$placas || !$mvr) {
            throw new OltFalha('formato', 'version-running sem placas/MVR');
        }
        $versoes = array_keys($mvr);
        sort($versoes);
        return [
            // Placas com MVR diferente: a versao vira "V1/V2" e a compatibilidade nao bate com
            // nenhuma versao testada — o addon cai para somente leitura, de proposito.
            'versao'  => implode('/', $versoes),
            'versoes' => $versoes,
            'placas'  => array_values($placas),
        ];
    }

    /**
     * show gpon onu state gpon-olt_S/s/p
     * @return array{onus:array<int,array{shelf:int,slot:int,pon:int,onu:int,admin:string,omcc:string,fase:string,online:bool}>,
     *               total:int,online:int,aviso:?string}
     */
    public static function estadoPon(string $t): array
    {
        // PON sem ONUs (vazia ou desativada): a OLT real responde com %Code, nao e recusa (01/10:
        // "%Code 62310-GPONSRV : No related information to show." na 2/12 desativada).
        if (self::semInformacao($t)) {
            return ['onus' => [], 'total' => 0, 'online' => 0, 'aviso' => null];
        }
        self::exigirSemErro($t);
        if (!preg_match('/OnuIndex\s+Admin State\s+OMCC State\s+Phase State/', $t)) {
            throw new OltFalha('formato', 'onu state sem cabecalho');
        }
        $onus = [];
        foreach (explode("\n", $t) as $l) {
            if (preg_match('#^\s*(\d+)/(\d+)/(\d+):(\d+)\s+(\S+)\s+(\S+)\s+(\S+)#', $l, $m)) {
                $onus[] = ['shelf' => (int) $m[1], 'slot' => (int) $m[2], 'pon' => (int) $m[3], 'onu' => (int) $m[4],
                           'admin' => $m[5], 'omcc' => $m[6], 'fase' => $m[7],
                           'online' => in_array($m[7], self::FASES_ONLINE, true)];
            }
        }
        if (!preg_match('#ONU Number:\s*(\d+)/(\d+)#', $t, $n)) {
            throw new OltFalha('formato', 'onu state sem totalizador');
        }
        // A contagem das linhas TEM de bater com o total que a propria OLT informa: se nao bate,
        // perdemos linhas (paginacao, corte) e o inventario estaria errado.
        if (count($onus) !== (int) $n[2]) {
            throw new OltFalha('formato', sprintf('onu state: %d linhas, OLT informa %d', count($onus), (int) $n[2]));
        }
        $online = count(array_filter($onus, fn($o) => $o['online']));
        return [
            'onus'   => $onus,
            'total'  => (int) $n[2],
            'online' => $online,
            'aviso'  => $online !== (int) $n[1]
                ? sprintf('A OLT informa %d ONUs ativas e o estado de fase indica %d online.', (int) $n[1], $online) : null,
        ];
    }

    /**
     * show gpon onu baseinfo gpon-olt_S/s/p — SN e perfil de todas as ONUs da PON num comando.
     * @return array<int,array{onu:int,tipo:string,modo:string,sn:string,fornecedor:string,estado:string}> indexado pelo numero da ONU
     */
    public static function baseInfo(string $t): array
    {
        if (self::semInformacao($t)) {
            return [];
        }
        self::exigirSemErro($t);
        if (!preg_match('/OnuIndex\s+Type\s+Mode\s+AuthInfo\s+State/', $t)) {
            throw new OltFalha('formato', 'baseinfo sem cabecalho');
        }
        $saida = [];
        foreach (explode("\n", $t) as $l) {
            if (preg_match('#^\s*gpon-onu_\d+/\d+/\d+:(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s*$#', $l, $m)) {
                $auth = $m[4];
                $sn = preg_match('/^SN:([A-Za-z0-9]{12,16})$/', $auth, $s) ? strtoupper($s[1]) : '';
                $saida[(int) $m[1]] = ['onu' => (int) $m[1], 'tipo' => $m[2], 'modo' => $m[3], 'sn' => $sn,
                                       'fornecedor' => $sn !== '' ? substr($sn, 0, 4) : '', 'estado' => $m[5]];
            }
        }
        return $saida;
    }

    /**
     * show gpon onu detail-info gpon-onu_S/s/p:o
     * @return array{interface:string,nome:string,tipo:string,estado:string,admin:string,fase:string,sn:string,descricao:string,distancia:string,online_ha:string}
     */
    public static function detalheOnu(string $t): array
    {
        self::exigirSemErro($t);
        $c = self::chaveValor($t, '/^-{6,}/');
        if (!isset($c['ONU interface'])) {
            throw new OltFalha('formato', 'detail-info sem "ONU interface"');
        }
        return [
            'interface' => $c['ONU interface'],
            'nome'      => $c['Name'] ?? '',
            'tipo'      => $c['Type'] ?? '',
            'estado'    => $c['State'] ?? '',
            'admin'     => $c['Admin state'] ?? '',
            'fase'      => $c['Phase state'] ?? '',
            'sn'        => strtoupper($c['Serial number'] ?? ''),
            'descricao' => $c['Description'] ?? '',
            'distancia' => $c['ONU Distance'] ?? '',
            'online_ha' => $c['Online Duration'] ?? '',
        ];
    }

    /**
     * show gpon remote-onu equip gpon-onu_S/s/p:o
     * "Version" aqui e a revisao de HARDWARE (ex.: V9.0); a versao de software nao aparece.
     * @return array{fornecedor:string,modelo:string,hw_versao:string,sn:string,equipamento:string}
     */
    public static function equipOnu(string $t): array
    {
        self::exigirSemErro($t);
        $c = self::chaveValor($t);
        $modelo = $c['Model'] ?? $c['Equipment ID'] ?? '';
        if ($modelo === '' || !isset($c['Vendor ID'])) {
            throw new OltFalha('formato', 'remote-onu equip sem Model/Vendor ID');
        }
        return [
            'fornecedor'  => $c['Vendor ID'],
            'modelo'      => $modelo,
            'hw_versao'   => $c['Version'] ?? '',
            'sn'          => strtoupper($c['SN'] ?? ''),
            'equipamento' => $c['Equipment ID'] ?? '',
        ];
    }

    /**
     * show remote-unit information gpon-olt_S/s/p N — versao de software nos DOIS bancos da ONU.
     * A versao em uso e a do banco "Activated: Yes". O outro banco guarda a imagem anterior
     * (ou a recem-gravada, antes de ativar) — e o que o upgrade vai usar.
     *
     * @return array{fornecedor:string,modelo:string,ativa:?string,standby:?string,
     *               bancos:array<int,array{banco:int,versao:string,commited:bool,ativado:bool,valido:bool}>}
     */
    public static function remoteUnitInfo(string $t): array
    {
        self::exigirSemErro($t);
        if (!preg_match('/^\s*RuType\s*:/m', $t) || !preg_match('/^\s*Region\s+\d+/m', $t)) {
            throw new OltFalha('formato', 'remote-unit information sem RuType/Region');
        }
        $forn = preg_match('/^\s*RuVendorName\s*:\s*(\S+)/m', $t, $m) ? $m[1] : '';
        $modelo = preg_match('/^\s*RuType\s*:\s*(\S+)/m', $t, $m) ? $m[1] : '';
        $bancos = [];
        foreach (preg_split('/^\s*Region\s+/m', $t) as $i => $bloco) {
            if ($i === 0 || !preg_match('/^(\d+)/', $bloco, $n)) {
                continue;
            }
            $campo = fn(string $k) => preg_match('/^\s*' . $k . '\s*:\s*(.*?)\s*$/mi', $bloco, $x) ? $x[1] : '';
            $bancos[] = ['banco' => (int) $n[1], 'versao' => $campo('Vertag'),
                         'commited' => strcasecmp($campo('Commited'), 'Yes') === 0,
                         'ativado'  => strcasecmp($campo('Activated'), 'Yes') === 0,
                         'valido'   => strcasecmp($campo('Valid'), 'Yes') === 0];
        }
        if (!$bancos) {
            throw new OltFalha('formato', 'remote-unit information sem bancos');
        }
        $ativos = array_values(array_filter($bancos, fn($b) => $b['ativado'] && $b['versao'] !== ''));
        $outros = array_values(array_filter($bancos, fn($b) => !$b['ativado'] && $b['versao'] !== ''));
        $umAtivo = count($ativos) === 1;
        $umOutro = count($outros) === 1;
        return [
            'fornecedor' => $forn,
            'modelo'     => $modelo,
            // Dois bancos ativos ou nenhum: estado anomalo — nao arrisca dizer qual roda.
            'ativa'      => $umAtivo ? $ativos[0]['versao'] : null,
            'standby'    => $umOutro ? $outros[0]['versao'] : null,
            // O upgrade so termina com a versao nova ativa E confirmada (commit): sem o commit, a
            // ONU volta para a anterior no proximo reinicio.
            'ativa_commitada' => $umAtivo && $ativos[0]['commited'],
            'standby_valido'  => $umOutro && $outros[0]['valido'],
            'bancos'     => $bancos,
        ];
    }

    /**
     * Forma em lista: "show remote-unit information gpon-olt_S/s/p 1-5" devolve um bloco por ONU,
     * cada um comecando por "gpon-onu_S/s/p: N". ONU sem bloco simplesmente nao aparece.
     * @return array<int,array> indexado pelo numero da ONU, no formato de remoteUnitInfo()
     */
    public static function remoteUnitInfoLista(string $t): array
    {
        self::exigirSemErro($t);
        $saida = [];
        $partes = preg_split('/^\s*gpon-onu_\d+\/\d+\/\d+:\s*(\d+)\s*$/m', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i + 1 < count($partes); $i += 2) {
            try {
                $saida[(int) $partes[$i]] = self::remoteUnitInfo($partes[$i + 1]);
            } catch (OltFalha $f) {
                // Um bloco estranho nao derruba os outros: aquela ONU fica sem versao nesta leitura.
            }
        }
        if (!$saida && trim($t) !== '') {
            throw new OltFalha('formato', 'remote-unit information (lista) sem blocos reconhecidos');
        }
        return $saida;
    }

    /**
     * show remote-unit update-status gpon-olt_S/s/p N — andamento da atualizacao manual:
     *   Taskname : Manual | Action : Update|Activate|Commit|Unknown | ImgLocation : Remote|Local
     *   Status : In-progress|Success | Progress : 13% | Failreason : None | Committime : ...
     * No comeco da transferencia a OLT mostra "unknown-ru_..." com Action Unknown e 0%.
     * O texto de uma FALHA ainda nao foi visto: so serve para mostrar o andamento, nunca decide.
     * @return array{acao:string,local:string,status:string,progresso:?int,motivo:string,commit_em:string}
     */
    public static function updateStatus(string $t): array
    {
        self::exigirSemErro($t);
        $c = self::chaveValor($t);
        if (!isset($c['Status'], $c['Action'])) {
            throw new OltFalha('formato', 'update-status sem Status/Action');
        }
        return [
            'acao'      => $c['Action'],
            'local'     => $c['ImgLocation'] ?? '',
            'status'    => $c['Status'],
            'progresso' => isset($c['Progress']) && preg_match('/^(\d{1,3})\s*%$/', $c['Progress'], $m) ? min(100, (int) $m[1]) : null,
            'motivo'    => $c['Failreason'] ?? '',
            'commit_em' => $c['Committime'] ?? '',
        ];
    }

    /**
     * show file <pasta> device flash — so a linha de espaco interessa. Pasta vazia ("No such
     * files in master") NAO mostra o espaco: devolve null.
     * Tambem devolve os arquivos da controladora principal (nome em minusculas => bytes): na
     * pasta "other" fica a imagem de ONU baixada do FTP, ate o aging-time da OLT (padrao 30 min).
     * @return array{total:int,livre:int,arquivos:array<string,int>}|null
     */
    public static function espacoFlash(string $t): ?array
    {
        self::exigirSemErro($t);
        if (!preg_match('/Total disk size:\s*(\d+)\s*bytes\s*\((\d+)\s*bytes free\)/i', $t, $m)) {
            return null;
        }
        $arquivos = [];
        foreach (explode("\n", $t) as $l) {
            if (preg_match('/No such files in slave|Total disk size/i', $l)) {
                break;                       // so a controladora principal
            }
            if (preg_match('/^\s*[-rwx]{4}\s+(\d+)\s+\S+\s+\S+\s+(\S+)\s*$/', $l, $a)) {
                $arquivos[strtolower($a[2])] = (int) $a[1];
            }
        }
        return ['total' => (int) $m[1], 'livre' => (int) $m[2], 'arquivos' => $arquivos];
    }

    /**
     * show remote-unit summary-of manual — secoes Download / Operating / Waiting / Fail / Success.
     *   Download: "file <nome>  download error from remote server," + " Reason:<motivo>"
     *             (nome em minusculas, como a OLT mostra)
     *   demais:   uma posicao por linha, "gpon-onu_1/1/2: 71"
     * A lista NAO tem data: entradas antigas continuam la. Quem usa compara com uma leitura
     * feita antes de mandar o comando.
     * @return array{downloads:array<int,array{arquivo:string,motivo:string}>,operating:string[],waiting:string[],fail:string[],success:string[]}
     */
    public static function resumoManual(string $t): array
    {
        self::exigirSemErro($t);
        $r = ['downloads' => [], 'operating' => [], 'waiting' => [], 'fail' => [], 'success' => []];
        if (!preg_match('/^\s*Download\s*:/mi', $t) || !preg_match('/^\s*Success\s*:/mi', $t)) {
            if (trim($t) === '') {
                return $r;
            }
            throw new OltFalha('formato', 'summary-of manual sem as secoes Download/Success');
        }
        $secao = null;
        $linhas = explode("\n", str_replace("\r", '', $t));
        foreach ($linhas as $i => $l) {
            if (preg_match('/^\s*(Download|Operating|Waiting|Fail|Success)\s*:\s*$/i', $l, $m)) {
                $secao = strtolower($m[1]);
                continue;
            }
            if ($secao === 'download' && preg_match('/^\s*file\s+(\S+)\s+download error/i', $l, $m)) {
                $motivo = '';
                for ($k = $i + 1; $k < count($linhas) && $k <= $i + 2; $k++) {
                    if (preg_match('/^\s*Reason\s*:\s*(.+?)\s*$/i', $linhas[$k], $x)) {
                        $motivo = $x[1];
                        break;
                    }
                }
                $r['downloads'][] = ['arquivo' => strtolower($m[1]), 'motivo' => $motivo];
            } elseif ($secao !== null && $secao !== 'download' && preg_match('/gpon-onu_(\d+)\/(\d+)\/(\d+):\s*(\d+)/', $l, $m)) {
                $r[$secao][] = $m[2] . '/' . $m[3] . ':' . $m[4];
            }
        }
        return $r;
    }

    /** @return array{enviados:int,recebidos:int,percentual:int} */
    public static function ping(string $t): array
    {
        self::exigirSemErro($t);
        if (!preg_match('/Success rate is (\d+) percent\s*\((\d+)\/(\d+)\)/i', $t, $m)) {
            throw new OltFalha('formato', 'ping sem taxa de sucesso');
        }
        return ['percentual' => (int) $m[1], 'recebidos' => (int) $m[2], 'enviados' => (int) $m[3]];
    }

    // ---------------------------------------------------------------- apoio

    /** Linhas "Chave:   valor" ate o separador opcional. */
    private static function chaveValor(string $t, ?string $pararEm = null): array
    {
        $c = [];
        foreach (explode("\n", $t) as $l) {
            if ($pararEm !== null && preg_match($pararEm, trim($l))) {
                break;
            }
            if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 +\/.()_-]*?)\s*:\s*(.*?)\s*$/', $l, $m)) {
                $c[$m[1]] ??= $m[2];
            }
        }
        return $c;
    }

    /** "No related information to show.", com ou sem o prefixo "%Code 62310-GPONSRV :". */
    private static function semInformacao(string $t): bool
    {
        return (bool) preg_match('/^\s*(%Code\s+\d+-[A-Z]+\s*:\s*)?No related information to show\.?\s*$/i', trim($t));
    }

    private static function exigirSemErro(string $t): void
    {
        if (preg_match('/^\s*%(Error|Code)\b[^\n]*/mi', $t, $m)) {
            throw new OltFalha('comando', trim($m[0]));
        }
    }
}
