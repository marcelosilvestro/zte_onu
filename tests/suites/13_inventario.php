<?php
/**
 * Suite 13 :: inventario (somente leitura na OLT), topologia e painel — sobre a OLT simulada,
 * que responde com as saidas reais da OLT de referencia.
 */
T::suite('Parser ZTE C320 :: show gpon onu baseinfo');

$b = ParserZteC320V21::baseInfo(Fixtures::corpo(Fixtures::DIR_PADRAO, 'show_gpon_onu_baseinfo.txt'));
T::igual('27 ONUs no baseinfo', 27, count($b));
T::igual('SN e fabricante da ONU 1', ['ZTEGD0000001', 'ZTEG', 'HRT'], [$b[1]['sn'], $b[1]['fornecedor'], $b[1]['tipo']]);
T::igual('Furukawa (HBR/FRKW) nas posicoes 2, 3, 17 e 38', [2, 3, 17, 38],
    array_values(array_keys(array_filter($b, fn($x) => $x['fornecedor'] === 'FRKW'))));
T::igual('baseinfo de PON vazia', [], ParserZteC320V21::baseInfo('No related information to show.'));
T::recusa('baseinfo sem cabecalho', fn() => ParserZteC320V21::baseInfo('lixo'), 'ZTE-OLT-009');
T::igual('comando baseinfo da lista fechada', 'show gpon onu baseinfo gpon-olt_1/1/2', ComandosZteC320V21::basePon(1, 1, 2));

T::suite('Parser ZTE C320 :: show remote-unit information (versao de software)');

$sw = ParserZteC320V21::remoteUnitInfo(Fixtures::corpo(Fixtures::DIR_PADRAO, 'show_remote_unit_information.txt'));
T::igual('versao em uso = banco ativado', 'V9.0.11P1N52', $sw['ativa']);
T::igual('outro banco (imagem anterior)', 'V9.0.11P1N48', $sw['standby']);
T::igual('fornecedor e modelo', ['ZTEG', 'F670LV9.0'], [$sw['fornecedor'], $sw['modelo']]);
T::igual('dois bancos com commited/ativado/valido', [[1, true, true, true], [2, false, false, true]],
    array_map(fn($b) => [$b['banco'], $b['commited'], $b['ativado'], $b['valido']], $sw['bancos']));
$dois = str_replace("Activated     : No", "Activated     : Yes", Fixtures::corpo(Fixtures::DIR_PADRAO, 'show_remote_unit_information.txt'));
T::igual('dois bancos ativos (anomalia): nao arrisca dizer qual roda', null, ParserZteC320V21::remoteUnitInfo($dois)['ativa']);
T::recusa('saida sem Region e recusada', fn() => ParserZteC320V21::remoteUnitInfo("RuType : X\n"), 'ZTE-OLT-009');
T::igual('comando da lista fechada', 'show remote-unit information gpon-olt_1/1/1 7', ComandosZteC320V21::versaoSw(1, 1, 1, 7));

$l = ParserZteC320V21::remoteUnitInfoLista(Fixtures::corpo(Fixtures::DIR_PADRAO, 'show_remote_unit_information_lista.txt'));
T::igual('lista real 1-5: 5 ONUs', [1, 2, 3, 4, 5], array_keys($l));
T::igual('lista: ONU 4 com o banco 2 ativo', ['V9.0.11P1N52', 'V9.0.11P1N10B'], [$l[4]['ativa'], $l[4]['standby']]);
T::igual('lista: ONU 5 ja em P3N10', 'V9.0.11P3N10', $l[5]['ativa']);
T::igual('lista: Furukawa tambem responde', ['FRKW', '630-10B', 'V4.0.2'], [$l[2]['fornecedor'], $l[2]['modelo'], $l[2]['ativa']]);
T::igual('lista: Furukawa com os dois bancos na mesma versao', 'V4.0.2', $l[2]['standby']);
T::igual('comando em faixa', 'show remote-unit information gpon-olt_1/1/1 1-50', ComandosZteC320V21::versaoSwFaixa(1, 1, 1, 1, 50));
T::recusa('faixa invertida', fn() => ComandosZteC320V21::versaoSwFaixa(1, 1, 1, 9, 2), 'ZTE-VAL-007');

T::suite('Inventario :: regra de "desatualizada"');

$alvos = ['F670LV9.0|V9.0' => 'V9.0.11P3N10'];
$onu = ['fornecedor' => 'ZTEG', 'modelo' => 'F670LV9.0', 'hw_versao' => 'V9.0'];
T::igual('P1N52 < P3N10: desatualizada', true, InventarioServico::desatualizada($onu + ['sw_versao' => 'V9.0.11P1N52'], $alvos));
T::igual('na versao alvo: em dia', false, InventarioServico::desatualizada($onu + ['sw_versao' => 'V9.0.11P3N10'], $alvos));
T::igual('mais nova que o alvo: em dia (nunca rebaixa)', false, InventarioServico::desatualizada($onu + ['sw_versao' => 'V9.0.12P1N1'], $alvos));
T::igual('comparacao natural: P10 > P9', true, InventarioServico::desatualizada($onu + ['sw_versao' => 'V9.0.11P9N1'], ['F670LV9.0|V9.0' => 'V9.0.11P10N1']));
T::igual('sem firmware para o HW: nao da para dizer', null, InventarioServico::desatualizada(['hw_versao' => 'V8.0', 'sw_versao' => 'x'] + $onu, $alvos));
T::igual('versao nao lida: nao da para dizer', null, InventarioServico::desatualizada($onu + ['sw_versao' => null], $alvos));
T::igual('outro fabricante: nunca', null, InventarioServico::desatualizada(['fornecedor' => 'FRKW', 'sw_versao' => 'x'] + $onu, $alvos));

T::suite('Inventario :: descobrir PONs');

$sim = OltServico::salvar(['nome' => 'OLT Inventario', 'protocolo' => 'simulado'], 'teste');
$oid = $sim['id'];
T::recusa('sem teste de acesso nao ha placas para descobrir', fn() => InventarioServico::descobrir($oid, 'teste'), 'ZTE-INV-001');
OltServico::testar($oid, 'teste');
$pons = InventarioServico::descobrir($oid, 'teste');
T::igual('32 PONs (slots 1 e 2, 16 portas cada); o slot da controladora fica de fora', 32, count($pons));
T::igual('nenhuma PON no slot 4 (SMXA)', 0, count(array_filter($pons, fn($p) => $p['slot'] === 4)));
T::igual('PON 1/1 com 27 ONUs', 27, $pons[0]['onus']);
T::igual('lista de PONs gravada na OLT', 32, count(json_decode(OltServico::linha($oid)['pons_detectadas'], true)));
// OLT real (01/10): a 2/12 foi recusada e havia ONUs na 2/13-2/16 — a descoberta nao pode parar ali.
$buraco = new class implements Transporte {
    public function conectar(): void {}
    public function executar(string $l): string {
        return str_contains($l, 'gpon-olt_1/2/12') ? "%Error 20202: Invalid input detected at '^' marker.Invalid parameter" : Fixtures::responder($l);
    }
    public function nomeEquipamento(): string { return Fixtures::NOME_OLT; }
    public function fechar(): void {}
};
$pb = InventarioServico::descobrir($oid, 'teste', $buraco);
T::igual('porta recusada no meio da placa: so ela fica de fora, 2/13-2/16 continuam', [31, false, true],
    [count($pb), in_array('2/12', array_map(fn($p) => $p['slot'] . '/' . $p['pon'], $pb), true),
     in_array('2/16', array_map(fn($p) => $p['slot'] . '/' . $p['pon'], $pb), true)]);
InventarioServico::descobrir($oid, 'teste');

T::suite('Inventario :: leitura de uma PON');

$r = InventarioServico::lerPon($oid, 1, 1, false, 'teste');
T::igual('27 ONUs, 25 online, 27 novas', [27, 25, 27], [$r['total'], $r['online'], $r['novas']]);
T::igual('detalhes: 27 detail-info + 21 equip + 1 versao em faixa (um comando para a PON)', 49, $r['detalhes_lidos']);
$o7 = Db::um('SELECT * FROM tab_zte_onu WHERE olt_id = ? AND slot = 1 AND porta = 1 AND onu_num = 7', [$oid]);
T::igual('ONU 7: nome = login, modelo e HW lidos', ['cliente_teste_07', 'cliente_teste_07', 'F670LV9.0', 'V9.0', 'ZTEG', 'online'],
    [$o7['nome'], $o7['login_cliente'], $o7['modelo'], $o7['hw_versao'], $o7['fornecedor'], $o7['estado']]);
T::igual('ONU 7: versao lida nos dois bancos', ['V9.0.11P1N52', 'V9.0.11P1N48'], [$o7['sw_versao'], $o7['sw_standby']]);
$o9 = Db::um('SELECT sw_versao FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 9', [$oid]);
T::igual('ONU 9 (simulada ja atualizada): P3N10', 'V9.0.11P3N10', $o9['sw_versao']);
$o2 = Db::um('SELECT * FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 2', [$oid]);
T::igual('Furukawa: SN e perfil lidos, modelo NAO (fora do escopo)', ['FRKW00000002', 'FRKW', 'HBR', null],
    [$o2['sn'], $o2['fornecedor'], $o2['tipo_perfil'], $o2['modelo']]);
$o14 = Db::um('SELECT * FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 14', [$oid]);
T::igual('ONU offline (DyingGasp): estado e fase gravados, equip nao lido', ['offline', 'DyingGasp', null], [$o14['estado'], $o14['fase'], $o14['modelo']]);

$r = InventarioServico::lerPon($oid, 1, 1, false, 'teste');
T::igual('releitura rapida: nada novo e nenhum comando por ONU', [0, 0], [$r['novas'], $r['detalhes_lidos']]);
$r = InventarioServico::lerPon($oid, 1, 1, true, 'teste');
T::igual('leitura completa: rele todas', 49, $r['detalhes_lidos']);
$r = InventarioServico::lerPon($oid, 1, 2, false, 'teste');
T::igual('PON vazia', [0, 0], [$r['total'], $r['novas']]);

T::suite('Inventario :: ONU que some e ONU trocada');

// Transporte que tira a ONU 50 da OLT e troca o SN da ONU 5.
$mudada = new class implements Transporte {
    public function conectar(): void {}
    public function nomeEquipamento(): string { return Fixtures::NOME_OLT; }
    public function fechar(): void {}
    public function executar(string $l): string
    {
        $t = Fixtures::responder($l);
        if (str_starts_with($l, 'show gpon onu state gpon-olt_1/1/1')) {
            $t = preg_replace('#^1/1/1:50 .*\n?#m', '', $t);
            $t = str_replace('ONU Number: 25/27', 'ONU Number: 24/26', $t);
        }
        if (str_starts_with($l, 'show gpon onu baseinfo gpon-olt_1/1/1')) {
            $t = preg_replace('#^gpon-onu_1/1/1:50 .*\n?#m', '', $t);
            $t = str_replace('SN:ZTEGD0000005', 'SN:ZTEGD9999995', $t);
        }
        return $t;
    }
};
$r = InventarioServico::lerPon($oid, 1, 1, false, 'teste', $mudada);
$o50 = Db::um('SELECT * FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 50', [$oid]);
T::igual('ONU que sumiu da OLT: marcada, nao apagada', ['desconhecido', true], [$o50['estado'], $o50['ausente_desde'] !== null]);
T::igual('ausentes contadas', 1, $r['ausentes']);
$o5 = Db::um('SELECT * FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 5', [$oid]);
T::igual('SN trocado na mesma posicao: SN novo gravado; detalhe, equip e versao relidos', ['ZTEGD9999995', 3], [$o5['sn'], $r['detalhes_lidos']]);
$r = InventarioServico::lerPon($oid, 1, 1, false, 'teste');
T::igual('ONU que voltou deixa de ser ausente', null, Db::valor('SELECT ausente_desde FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 50', [$oid]));

T::suite('Inventario :: falha da OLT no meio nao grava pela metade');

$caindo = new class implements Transporte {
    private int $n = 0;
    public function conectar(): void {}
    public function nomeEquipamento(): string { return Fixtures::NOME_OLT; }
    public function fechar(): void {}
    public function executar(string $l): string
    {
        // state e baseinfo passam; cai no detail-info da ONU 7 (a unica com detalhe pendente)
        if (++$this->n > 2) {
            throw new OltFalha('queda', 'teste');
        }
        return Fixtures::responder($l);
    }
};
$antes = Db::valor('SELECT MAX(atualizado_em) FROM tab_zte_onu WHERE olt_id = ?', [$oid]);
Db::exec('UPDATE tab_zte_onu SET detalhe_em = NULL WHERE olt_id = ? AND onu_num = 7', [$oid]);
T::recusa('queda no meio da PON e propagada', fn() => InventarioServico::lerPon($oid, 1, 1, false, 'teste', $caindo), 'ZTE-OLT-015');
T::igual('nada foi gravado da leitura interrompida', null, Db::valor('SELECT detalhe_em FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 7', [$oid]));
InventarioServico::lerPon($oid, 1, 1, false, 'teste');

T::suite('Inventario :: consulta, cliente do MK-AUTH, topologia e painel');

// sis_cliente do MK-AUTH e latin1: a busca do nome nao pode fazer JOIN por texto com tabela utf8mb4.
Db::exec("CREATE TABLE IF NOT EXISTS sis_cliente (login VARCHAR(60), nome VARCHAR(100), cli_ativado CHAR(1)) ENGINE=InnoDB DEFAULT CHARSET=latin1");
Db::exec("INSERT INTO sis_cliente VALUES ('cliente_teste_07', 'João da Conceição', 's'), ('cliente_teste_09', 'Maria', 'n')");
Db::tabelaExiste(Db::ESQUECER);

$l = InventarioServico::listar(['busca' => 'cliente_teste_07'], 1);
T::igual('busca por login', 1, $l['total']);
T::igual('nome do cliente vem do MK-AUTH (latin1 -> utf8 sem erro de collation)', 'João da Conceição', $l['linhas'][0]['cliente']['nome'] ?? null);
T::igual('cliente inativo sinalizado', false, InventarioServico::listar(['busca' => 'cliente_teste_09'], 1)['linhas'][0]['cliente']['ativo']);
T::igual('filtro por fabricante FRKW', 4, InventarioServico::listar(['fornecedor' => 'FRKW', 'olt_id' => $oid], 1)['total']);
T::igual('filtro offline', 2, InventarioServico::listar(['estado' => 'offline', 'olt_id' => $oid], 1)['total']);
T::igual('filtro modelo + HW', 21, InventarioServico::listar(['modelo' => 'F670LV9.0', 'hw' => 'V9.0', 'olt_id' => $oid], 1)['total']);
T::igual('filtro por PON', 27, InventarioServico::listar(['pon' => '1/1', 'olt_id' => $oid], 1)['total']);
T::igual('filtro por versao em uso', 5, InventarioServico::listar(['sw' => 'V9.0.11P3N10', 'olt_id' => $oid], 1)['total']);
T::igual('filtro "versao nao lida" (offline e outro fabricante)', 6, InventarioServico::listar(['sw' => InventarioServico::SW_NAO_LIDA, 'olt_id' => $oid], 1)['total']);
T::igual('opcoes de versao: a mais nova primeiro', ['V9.0.11P3N10', 'V9.0.11P1N52'],
    array_values(array_filter(InventarioServico::opcoesFiltro()['versoes'], fn($v) => str_starts_with($v, 'V9.0.11'))));
T::igual('paginacao', 7, count(InventarioServico::listar(['olt_id' => $oid], 2, 20)['linhas']));
T::igual('busca com % nao vira curinga', 0, InventarioServico::listar(['busca' => '%'], 1)['total']);

$det = InventarioServico::obterOnu((int) $o7['id']);
T::igual('detalhe da ONU com posicao montada', '1/1:7', $det['posicao']);
$fwId = (int) Db::valor("SELECT f.id FROM tab_zte_firmware f JOIN tab_zte_firmware_compat c ON c.firmware_id = f.id WHERE c.modelo = 'F670LV9.0' LIMIT 1");
T::certo('firmwares compativeis com o modelo/HW da ONU', $fwId === 0 || in_array($fwId, array_map('intval', array_column($det['firmwares_compativeis'], 'id')), true));

$re = InventarioServico::lerOnu((int) $o7['id'], 'teste');
T::igual('ler uma ONU agora', 'cliente_teste_07', $re['nome']);

$f = InventarioServico::finalizar($oid, [['novas' => 27]], 'teste');
T::igual('snapshot: 27 ONUs, 25 online', [27, 25], [$f['total'], $f['online']]);
T::igual('snapshot gravado', 1, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_onu_snapshot WHERE olt_id = ?', [$oid]));

$topo = array_values(array_filter(InventarioServico::topologia(), fn($o) => $o['id'] === $oid))[0];
T::igual('topologia: 1 PON com ONUs, 32 detectadas', [1, 32], [count($topo['pons']), count($topo['pons_detectadas'])]);
T::igual('topologia: contagem da PON', [27, 25, 2, 23], [$topo['pons'][0]['total'], $topo['pons'][0]['online'], $topo['pons'][0]['offline'], $topo['pons'][0]['zte']]);

$p = InventarioServico::resumoPainel();
$f670 = array_filter($p['modelos'], fn($m) => $m['modelo'] === 'F670LV9.0' && $m['hw'] === 'V9.0');
T::certo('painel: F670LV9.0 · V9.0 dividido por versao', count($f670) >= 2 && array_sum(array_column($f670, 'n')) >= 21);
T::certo('painel: grupo P1N52 aparece', in_array('V9.0.11P1N52', array_column($f670, 'sw'), true));
T::certo('painel: Furukawa aparece como outro fabricante', in_array('FRKW', array_column($p['outros_fornecedores'], 'fornecedor'), true));
T::igual('painel: versao de software disponivel', true, $p['versao_sw_disponivel']);

T::igual('ler a OLT exige olt.configurar', ['olt.configurar', 'olt.configurar'], [Rotas::MAPA['inventario.ler_pon'][1], Rotas::MAPA['inventario.descobrir'][1]]);
T::igual('consultar o inventario so exige ver', 'ver', Rotas::MAPA['inventario.listar'][1]);

Db::exec('DROP TABLE sis_cliente');
Db::tabelaExiste(Db::ESQUECER);
