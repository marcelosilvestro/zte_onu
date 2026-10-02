<?php
/**
 * Suite 08 :: parser do ZTE C320 V2.1.x contra as saidas REAIS (fixtures da Fase 0).
 */
T::suite('Parser ZTE C320 :: show version-running');

$fx = fn(string $arq) => Fixtures::corpo(Fixtures::DIR_PADRAO, $arq);

$v = ParserZteC320V21::versaoRunning($fx('show_version_running.txt'));
T::igual('versao MVR detectada', 'V2.1.0', $v['versao']);
T::igual('3 placas (GTGH, GTXK, SMXA)', ['GTGHG', 'GTXK', 'SMXA'], array_column($v['placas'], 'tipo'));
T::igual('slot da placa PON', 1, $v['placas'][0]['slot']);
T::igual('linha com hora de um digito (" 0:52:45") e lida', 'V4.0.14', $v['placas'][1]['versoes']['BT']);
T::igual('controladora tem MVR, BT e FW', ['MVR', 'BT', 'FW'], array_keys($v['placas'][2]['versoes']));

$misto = str_replace('1/1/2   GTXK       MVR        V2.1.0', '1/1/2   GTXK       MVR        V2.0.9', $fx('show_version_running.txt'));
T::igual('placas com MVR diferentes: versao "V2.0.9/V2.1.0" (nao bate com testada)', 'V2.0.9/V2.1.0',
    ParserZteC320V21::versaoRunning($misto)['versao']);
T::recusa('saida sem cabecalho e recusada', fn() => ParserZteC320V21::versaoRunning('qualquer coisa'), 'ZTE-OLT-009');
T::recusa('%Error da OLT vira erro de comando', fn() => ParserZteC320V21::versaoRunning('%Error 20206: Unrecognized command'), 'ZTE-OLT-016');

T::suite('Parser ZTE C320 :: show gpon onu state');

$e = ParserZteC320V21::estadoPon($fx('show_gpon_onu_state.txt'));
T::igual('27 ONUs listadas', 27, count($e['onus']));
T::igual('total informado pela OLT', 27, $e['total']);
T::igual('25 online (fase working)', 25, $e['online']);
T::igual('sem aviso: online bate com "ONU Number: 25/27"', null, $e['aviso']);
T::igual('indice com lacunas (1/1/1:50) lido', 50, end($e['onus'])['onu']);
$o14 = array_values(array_filter($e['onus'], fn($o) => $o['onu'] === 14))[0];
T::igual('ONU 14 em DyingGasp, offline', ['DyingGasp', false], [$o14['fase'], $o14['online']]);

$cortada = preg_replace('/^1\/1\/1:50.*\n/m', '', $fx('show_gpon_onu_state.txt'));
T::recusa('lista cortada (26 linhas, OLT diz 27) e recusada — inventario nunca fica pela metade',
    fn() => ParserZteC320V21::estadoPon($cortada), 'ZTE-OLT-009');
T::igual('PON vazia', 0, ParserZteC320V21::estadoPon('No related information to show.')['total']);
T::igual('PON vazia/desativada no formato REAL (%Code 62310) e vazia, nao recusa', 0,
    ParserZteC320V21::estadoPon($fx('show_gpon_onu_state_pon_vazia.txt'))['total']);
T::igual('baseinfo da PON vazia no formato real', [], ParserZteC320V21::baseInfo($fx('show_gpon_onu_state_pon_vazia.txt')));
T::recusa('outro %Code continua sendo recusa', fn() => ParserZteC320V21::estadoPon('%Code 90474: This mainboard does not support sdcard.'), 'ZTE-OLT-016');

T::suite('Parser ZTE C320 :: detail-info e remote-onu equip');

$d = ParserZteC320V21::detalheOnu($fx('show_gpon_onu_detail_info.txt'));
T::igual('nome da ONU = login do cliente', 'cliente_teste_01', $d['nome']);
T::igual('tipo (perfil) HRT', 'HRT', $d['tipo']);
T::igual('SN em maiusculas', 'ZTEGD0000001', $d['sn']);
T::igual('fase', 'working', $d['fase']);
T::igual('descricao', 'from operador by HelpFiber', $d['descricao']);
T::igual('historico de quedas abaixo do separador nao vira campo', 'gpon-onu_1/1/1:1', $d['interface']);

$q = ParserZteC320V21::equipOnu($fx('show_gpon_remote_onu_equip.txt'));
T::igual('modelo', 'F670LV9.0', $q['modelo']);
T::igual('"Version" e a revisao de hardware', 'V9.0', $q['hw_versao']);
T::igual('SN normalizado em maiusculas (OLT mostra "ZTEGd...")', 'ZTEGD0000001', $q['sn']);
T::recusa('equip sem Model e recusado', fn() => ParserZteC320V21::equipOnu("Vendor ID: ZTEG\n"), 'ZTE-OLT-009');

T::suite('Parser ZTE C320 :: update-status (saidas do 1o upgrade real)');

$u = ParserZteC320V21::updateStatus($fx('update_status_inicio.txt'));
T::igual('logo apos o update: "unknown-ru", Unknown, 0%', ['Unknown', 'In-progress', 0], [$u['acao'], $u['status'], $u['progresso']]);
$u = ParserZteC320V21::updateStatus($fx('update_status_transferindo.txt'));
T::igual('transferindo: Update/Remote 13%', ['Update', 'Remote', 'In-progress', 13, 'None'], [$u['acao'], $u['local'], $u['status'], $u['progresso'], $u['motivo']]);
T::igual('transferido: Success 100%', ['Success', 100], array_values(array_intersect_key(ParserZteC320V21::updateStatus($fx('update_status_transferido.txt')), ['status' => 1, 'progresso' => 1])));
T::igual('ativado: Activate/Local', ['Activate', 'Local'], array_values(array_intersect_key(ParserZteC320V21::updateStatus($fx('update_status_ativado.txt')), ['acao' => 1, 'local' => 1])));
T::igual('commit: hora com ":" lida inteira', ['Commit', '2026-10-01 21:20:34'], array_values(array_intersect_key(ParserZteC320V21::updateStatus($fx('update_status_commit.txt')), ['acao' => 1, 'commit_em' => 1])));
T::recusa('saida sem Status/Action e recusada', fn() => ParserZteC320V21::updateStatus('qualquer coisa'), 'ZTE-OLT-009');
$gravUs = new class implements Transporte {
    public string $r = '';
    public function conectar(): void {}
    public function executar(string $l): string { return $this->r; }
    public function nomeEquipamento(): string { return ''; }
    public function fechar(): void {}
};
$gravUs->r = $fx('update_status_transferindo.txt');
$st = (new DriverZteC320V21($gravUs))->statusUpgrade(1, 2, 71);
T::igual('driver real: andamento 13%, mas NUNCA decide falha', ['desconhecido', 13], [$st['fase'], $st['progresso']]);
$gravUs->r = $fx('update_status_ativado.txt');
T::igual('fora da transferencia nao ha %', null, (new DriverZteC320V21($gravUs))->statusUpgrade(1, 2, 71)['progresso']);
T::igual('comando de andamento', 'show remote-unit update-status gpon-olt_1/1/2 71', ComandosZteC320V21::statusUpgrade(1, 1, 2, 71));

T::suite('Parser ZTE C320 :: flash e summary-of manual (falha real da F6201B, 01/10)');

T::igual('espaco da flash lido da listagem de "other"', ['total' => 132120576, 'livre' => 26136576, 'arquivos' => ['sdlog' => 4198, 'timelog' => 56]],
    ParserZteC320V21::espacoFlash($fx('show_file_other_flash.txt')));
$fc = ParserZteC320V21::espacoFlash($fx('show_file_other_flash_com_imagem.txt'));
T::igual('imagem de ONU baixada fica em "other" (nome em minusculas => bytes)', [692224, 25856080],
    [$fc['livre'], $fc['arquivos']['f670l_v9.0.11p3n10.bin'] ?? null]);
T::igual('mesma imagem ja na flash: cabe sem espaco novo', ['cabe' => true, 'ja_na_flash' => true],
    OltServico::avaliarFlash($fc, '/firmware/F670L_V9.0.11P3N10.bin', 25856080));
T::igual('outra imagem com a flash cheia: nao cabe', ['cabe' => false, 'ja_na_flash' => false],
    OltServico::avaliarFlash($fc, '/firmware/F6201B_V9.3.10P7N9.bin', 30015568));
T::igual('mesmo nome com tamanho diferente nao conta como a mesma imagem', false,
    OltServico::avaliarFlash($fc, '/firmware/F670L_V9.0.11P3N10.bin', 25856081)['ja_na_flash']);
T::igual('pasta vazia nao mostra o espaco: null (nunca inventa)', null, ParserZteC320V21::espacoFlash($fx('show_file_version_ru_flash_vazia.txt')));
$rs = ParserZteC320V21::resumoManual($fx('summary_of_manual_erro_espaco.txt'));
T::igual('summary-of: download recusado com o motivo, nome em minusculas',
    [['arquivo' => 'f6201b_v9.3.10p7n9.bin', 'motivo' => 'Remain space not enough']], $rs['downloads']);
T::igual('summary-of: sucesso anterior listado por posicao slot/pon:onu', ['1/2:71'], $rs['success']);
$ro = ParserZteC320V21::resumoManual($fx('summary_of_manual_operando.txt'));
T::igual('summary-of no piloto: "download successful" NAO conta como erro; operating e success por posicao',
    [[], ['1/8:8'], ['1/2:71', '1/16:13', '2/16:1']], [$ro['downloads'], $ro['operating'], $ro['success']]);
T::igual('summary-of vazio', [], ParserZteC320V21::resumoManual("Download:\n\nOperating:\n\nWaiting:\n\nFail:\n\nSuccess:\n")['downloads']);
T::recusa('summary-of sem secoes e recusado', fn() => ParserZteC320V21::resumoManual('qualquer coisa'), 'ZTE-OLT-009');
T::recusa('so pastas de leitura da lista fechada: "version" (sistema da OLT) nunca', fn() => ComandosZteC320V21::pastaFlash('version'), 'ZTE-VAL-003');
$tFlash = new class implements Transporte {
    public array $linhas = [];
    public function conectar(): void {}
    public function executar(string $l): string {
        $this->linhas[] = $l;
        return $l === 'show file other device flash' ? Fixtures::corpo(Fixtures::DIR_PADRAO, 'show_file_other_flash.txt')
                                                     : "No such files in master\n\nNo such files in slave";
    }
    public function nomeEquipamento(): string { return ''; }
    public function fechar(): void {}
};
T::igual('driver: le "other" primeiro (onde fica a imagem baixada) e para ao achar o espaco', [26136576, ['show file other device flash']],
    [(new DriverZteC320V21($tFlash))->espacoFlash()['livre'], $tFlash->linhas]);

$p = ParserZteC320V21::ping($fx('ping_ok.txt'));
T::igual('ping 100%', [100, 5, 5], [$p['percentual'], $p['recebidos'], $p['enviados']]);

T::suite('Comandos :: lista fechada');

T::igual('comando de estado da PON', 'show gpon onu state gpon-olt_1/1/3', ComandosZteC320V21::estadoPon(1, 1, 3));
T::recusa('PON fora da faixa', fn() => ComandosZteC320V21::estadoPon(1, 1, 17), 'ZTE-VAL-007');
T::recusa('ONU fora da faixa', fn() => ComandosZteC320V21::detalheOnu(1, 1, 1, 129), 'ZTE-VAL-007');
T::recusa('ping so com IP literal (sem injecao)', fn() => ComandosZteC320V21::ping('10.0.0.1; reboot'), 'ZTE-VAL-001');
T::recusa('ping com hostname e recusado', fn() => ComandosZteC320V21::ping('ftp.exemplo.com'), 'ZTE-VAL-001');
$escrita = array_filter(ComandosZteC320V21::TEMPLATES, fn($c) => !preg_match('/^(show |ping )/', $c));
T::igual('os unicos comandos que alteram algo sao os 4 da atualizacao por ONU', ['atualizar', 'ativar', 'confirmar', 'abortar'], array_keys($escrita));
T::certo('nenhum comando de configuracao da OLT (configure, no, write, reboot da OLT)',
    !array_filter(ComandosZteC320V21::TEMPLATES, fn($c) => preg_match('/^(configure|no |write|reboot|delete|file )/', $c)));

T::suite('OLT simulada');

$t = new TransporteFixture();
$drv = new DriverZteC320V21($t);
$t->conectar();
$id = $drv->identificar();
T::igual('simulada se identifica pelo nome do prompt real', 'OLT-c320_1', $id['identificador']);
T::igual('PON 1/1/1 simulada tem as 27 ONUs', 27, $drv->estadoPon(1, 1)['total']);
T::igual('demais PONs vazias', 0, $drv->estadoPon(1, 2)['total']);
T::igual('ONU 7 simulada tem nome proprio', 'cliente_teste_07', $drv->detalheOnu(1, 1, 7)['nome']);
T::recusa('ONU que nao existe responde erro da OLT', fn() => $drv->detalheOnu(1, 1, 20), 'ZTE-OLT-016');
T::recusa('linha com quebra nunca chega ao transporte', fn() => $t->executar("show version-running\nreboot"));
T::igual('historico de comandos so tem leitura', 0, count(array_filter($t->historico, fn($c) => !preg_match('/^show /', $c))));
