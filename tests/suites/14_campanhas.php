<?php
/**
 * Suite 14 :: regras, campanhas (simulacao e aprovacao), maquina de estados dos jobs.
 * Usa a OLT simulada e o inventario da suite 13 ($oid), o repositorio da suite 12 ($rid).
 */
T::suite('Campanhas :: preparacao');

Config::limparCache();
Config::set('modo_seguro', '1', 'teste');
Config::set('separar_criar_aprovar', '1', 'teste');
[$porta, $procCamp] = zte_ftp_falso('normal');
Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $rid]);
$fwAlvo = FirmwareServico::enviar($rid, zte_fw_local(random_bytes(6000)), 'novo.bin', ['fabricante' => 'ZTE', 'modelo_familia' => 'F670L',
    'versao_firmware' => 'V9.0.11P3N10', 'pasta' => 'zte', 'nome_remoto' => 'F670L_V9.0.11P3N10.bin',
    'compat' => [['modelo' => 'F670LV9.0', 'hw_versao' => 'V9.0']]], 'teste');
T::igual('firmware alvo disponivel', 'disponivel', $fwAlvo['estado']);
$vin = VinculoServico::salvar(['olt_id' => $oid, 'repositorio_id' => $rid, 'host_olt' => '192.0.2.10', 'porta_olt' => 21,
    'usuario_olt' => 'olt_leitura', 'senha_olt' => 'Olt#Ftp1', 'caminho_olt' => '/'], 'teste');

T::suite('Regras');

$base = ['nome' => 'F670L para P3N10', 'modelo' => 'F670LV9.0', 'hw_aceitos' => ['V9.0'], 'firmware_id' => $fwAlvo['id'], 'versoes_origem' => []];
T::recusa('HW fora da compatibilidade do firmware', fn() => RegraServico::salvar(['hw_aceitos' => ['V8.0']] + $base, 'teste'), 'ZTE-REG-003');
T::recusa('sem HW aceito', fn() => RegraServico::salvar(['hw_aceitos' => []] + $base, 'teste'), 'ZTE-REG-007');
T::recusa('versao de origem com caractere invalido', fn() => RegraServico::salvar(['versoes_origem' => ['V9;reboot']] + $base, 'teste'), 'ZTE-REG-008');
$regra = RegraServico::salvar($base, 'teste');
T::igual('regra criada', ['F670LV9.0', ['V9.0'], []], [$regra['modelo'], $regra['hw_aceitos'], $regra['versoes_origem']]);

$rg = ['modelo' => 'F670LV9.0', 'hw_aceitos' => ['V9.0'], 'versoes_origem' => []];
$on = ['modelo' => 'F670LV9.0', 'hw_versao' => 'V9.0'];
T::igual('mais antiga entra', null, RegraServico::motivoFora($rg, $on + ['sw_versao' => 'V9.0.11P1N52'], 'V9.0.11P3N10'));
T::igual('igual ao alvo fica fora', 'ja_na_versao', RegraServico::motivoFora($rg, $on + ['sw_versao' => 'v9.0.11p3n10'], 'V9.0.11P3N10'));
T::igual('mais nova fica fora (nunca rebaixa)', 'versao_mais_nova', RegraServico::motivoFora($rg, $on + ['sw_versao' => 'V9.0.12P1N1'], 'V9.0.11P3N10'));
T::igual('HW diferente fica fora', 'hw_incompativel', RegraServico::motivoFora($rg, ['hw_versao' => 'V8.0', 'sw_versao' => 'x'] + $on, 'V9.0.11P3N10'));
T::igual('versao nao lida fica fora', 'versao_desconhecida', RegraServico::motivoFora($rg, $on + ['sw_versao' => null], 'V9.0.11P3N10'));
T::igual('origem fora da lista', 'origem_nao_aceita',
    RegraServico::motivoFora(['versoes_origem' => ['V9.0.11P1N48']] + $rg, $on + ['sw_versao' => 'V9.0.11P1N52'], 'V9.0.11P3N10'));

T::suite('Campanhas :: simulacao');

$cb = ['nome' => 'Campanha geral', 'olt_id' => $oid, 'regra_id' => $regra['id'], 'janela_inicio' => '00:00', 'janela_fim' => '23:59'];
T::recusa('janela com inicio = fim', fn() => CampanhaServico::salvar(['janela_fim' => '00:00'] + $cb, 'ana'), 'ZTE-CAM-013');
$outraOlt = OltServico::salvar(['nome' => 'OLT Outra', 'protocolo' => 'simulado'], 'teste');
$regraOutra = RegraServico::salvar(['nome' => 'So da outra', 'olt_id' => $outraOlt['id']] + $base, 'teste');
T::recusa('regra de outra OLT', fn() => CampanhaServico::salvar(['regra_id' => $regraOutra['id']] + $cb, 'ana'), 'ZTE-CAM-010');

$c = CampanhaServico::salvar($cb, 'ana');
T::igual('campanha nasce em rascunho', 'rascunho', $c['estado']);
T::recusa('aprovar sem simular', fn() => CampanhaServico::aprovar($c['id'], 'bruno', true, false), 'ZTE-CAM-002');
$c = CampanhaServico::simular($c['id'], 'ana');
$s = $c['simulacao'];
T::igual('simulada', 'simulada', $c['estado']);
T::igual('16 ONUs entram (ZTE online, abaixo do alvo)', 16, $s['resumo']['entram']);
$mot = $s['resumo']['por_motivo'];
ksort($mot);
T::igual('motivos de quem fica de fora (offline sem modelo lido nao vira "outro modelo")',
    ['ja_na_versao' => 5, 'modelo_desconhecido' => 2, 'outro_fabricante' => 4], $mot);
T::certo('ONU sem modelo lido aparece na lista de quem fica de fora', in_array('modelo_desconhecido', array_column($s['fora'], 'motivo'), true));
T::igual('lotes estimados: o gargalo entre o limite global e o da PON',
    max((int) ceil(16 / $c['max_concorrentes']), (int) ceil(16 / $c['max_por_pon'])), $s['lotes']);
$porId = array_column($s['verificacoes'], null, 'id');
T::igual('verificacoes ok: firmware, arquivo no FTP, compatibilidade', ['ok', 'ok', 'ok'],
    [$porId['firmware']['resultado'], $porId['arquivo']['resultado'], $porId['compatibilidade']['resultado']]);
T::igual('OLT simulada: driver em aviso (execucao simulada)', 'aviso', $porId['driver']['resultado']);
T::igual('acesso OLT->FTP nao comprovado: aviso e exige ciencia', ['aviso', true], [$porId['olt_ftp']['resultado'], $s['precisa_ciencia']]);
T::igual('modo seguro com 16 ONUs: bloqueia', [true, 'erro'], [$porId['modo_seguro']['bloqueia'], $porId['modo_seguro']['resultado']]);
T::recusa('aprovacao bloqueada pelo modo seguro', fn() => CampanhaServico::aprovar($c['id'], 'bruno', true, false), 'ZTE-CAM-004');
T::igual('simular nao criou nenhum job', 0, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ?', [$c['id']]));

T::suite('Campanhas :: teste unitario no modo seguro');

$onu7 = (int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 7', [$oid]);
$u = CampanhaServico::salvar(['nome' => 'Teste 1 ONU', 'onus' => [$onu7]] + $cb, 'ana');
$u = CampanhaServico::simular($u['id'], 'ana');
T::igual('escopo de 1 ONU', 1, $u['simulacao']['resumo']['entram']);
T::igual('modo seguro libera 1 ONU', false, array_column($u['simulacao']['verificacoes'], null, 'id')['modo_seguro']['bloqueia']);
T::recusa('quem criou nao aprova', fn() => CampanhaServico::aprovar($u['id'], 'ana', true, false), 'ZTE-CAM-006');
T::recusa('sem ciencia sobre o FTP nao aprova', fn() => CampanhaServico::aprovar($u['id'], 'bruno', false, false), 'ZTE-CAM-007');
$u = CampanhaServico::aprovar($u['id'], 'bruno', true, false);
T::igual('aprovada por outro usuario, com ciencia', ['aprovada', 'bruno', 1], [$u['estado'], $u['aprovada_por'], $u['ciencia_olt_ftp']]);
T::igual('escopo congelado e 1 job pendente', [1, 1], [(int) Db::valor('SELECT COUNT(*) FROM tab_zte_campanha_onu WHERE campanha_id = ?', [$u['id']]), $u['jobs']['pendente']]);
T::igual('job segura a ONU (onu_ativa)', $onu7, (int) Db::valor('SELECT onu_ativa FROM tab_zte_job WHERE campanha_id = ?', [$u['id']]));
T::igual('firmware verificado antes de liberar', 1, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_firmware_verificacao WHERE firmware_id = ? AND tipo = 'pre_campanha'", [$fwAlvo['id']]));
T::recusa('aprovar de novo nao duplica nada', fn() => CampanhaServico::aprovar($u['id'], 'bruno', true, false), 'ZTE-CAM-002');
T::igual('continua 1 job', 1, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ?', [$u['id']]));

$onu10 = (int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 10', [$oid]);
$z = CampanhaServico::salvar(['nome' => 'ONU escolhida fora da PON marcada', 'onus' => [$onu10], 'pons' => ['1/2']] + $cb, 'ana');
$z = CampanhaServico::simular($z['id'], 'ana');
T::igual('com ONU escolhida, a PON marcada nao restringe', 1, $z['simulacao']['resumo']['entram']);
CampanhaServico::abortar($z['id'], 'ana');

T::suite('Campanhas :: escopo que muda entre simulacao e aprovacao');

Config::set('modo_seguro', '0', 'teste');
$c = CampanhaServico::simular($c['id'], 'ana');
$ids = array_column($c['simulacao']['entram'], 'id');
T::igual('ONU 7 (em job de outra campanha) agora fica de fora', 15, $c['simulacao']['resumo']['entram']);
T::igual('com o motivo certo', 1, $c['simulacao']['resumo']['por_motivo']['em_outra_campanha'] ?? 0);
$onu1 = (int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 1', [$oid]);
Db::exec("UPDATE tab_zte_onu SET sw_versao = 'V9.0.11P3N10' WHERE id = ?", [$onu1]);
T::recusa('inventario mudou depois da simulacao: aprovacao recusada para revisar', fn() => CampanhaServico::aprovar($c['id'], 'bruno', true, false), 'ZTE-CAM-005');
$c = CampanhaServico::obter($c['id']);
T::igual('a simulacao nova ficou para revisao', 14, $c['simulacao']['resumo']['entram']);
$c = CampanhaServico::aprovar($c['id'], 'bruno', true, false);
T::igual('aprovada com o escopo revisado: 14 jobs', [14, 'aprovada'], [$c['jobs']['pendente'], $c['estado']]);

T::suite('Jobs :: maquina de estados');

$job = (int) Db::valor('SELECT id FROM tab_zte_job WHERE campanha_id = ?', [$u['id']]);
T::recusa('pendente -> verificando nao existe', fn() => JobServico::transicionar($job, 'verificando', 'x'), 'ZTE-JOB-002');
JobServico::transicionar($job, 'enviando', 'enviando', ['dono' => 'worker-1', 'op_id' => 'OP-1']);
T::igual('enviando conta tentativa', 1, (int) JobServico::linha($job)['tentativas']);
Log::segredo('SegredoDaOlt#1');
JobServico::transicionar($job, 'ativando', 'ativando', ['saida_cli' => "file download ftp 192.0.2.10 olt_leitura SegredoDaOlt#1 ...\nOK"]);
JobServico::transicionar($job, 'verificando', 'lendo a versao');
T::recusa('verificando -> enviando nao existe', fn() => JobServico::transicionar($job, 'enviando', 'x'), 'ZTE-JOB-002');
$fim = JobServico::transicionar($job, 'concluido', 'versao confere', ['sw_versao_final' => 'V9.0.11P3N10']);
T::igual('concluido libera a ONU e registra a versao final', [null, 'V9.0.11P3N10'], [$fim['onu_ativa'], $fim['sw_versao_final']]);
T::recusa('concluido e final', fn() => JobServico::transicionar($job, 'pendente', 'x'), 'ZTE-JOB-002');
$ev = JobServico::eventos($job);
T::igual('4 eventos registrados', ['enviando', 'ativando', 'verificando', 'concluido'], array_column($ev, 'para_estado'));
T::certo('saida da CLI guardada mascarada', str_contains($ev[1]['saida_cli'], '***') && !str_contains($ev[1]['saida_cli'], 'SegredoDaOlt#1'));

// inconclusivo segura a ONU; falha libera
$j2 = (int) Db::valor('SELECT id FROM tab_zte_job WHERE campanha_id = ? ORDER BY id LIMIT 1', [$c['id']]);
JobServico::transicionar($j2, 'enviando', 'x');
JobServico::transicionar($j2, 'inconclusivo', 'conexao caiu durante a ativacao', ['falha_origem' => 'comunicacao_olt']);
T::igual('inconclusivo SEGURA a ONU (estado real desconhecido)', true, JobServico::linha($j2)['onu_ativa'] !== null);
T::igual('origem da falha registrada', 'comunicacao_olt', JobServico::linha($j2)['falha_origem']);
$j3 = (int) Db::valor("SELECT id FROM tab_zte_job WHERE campanha_id = ? AND estado = 'pendente' ORDER BY id LIMIT 1", [$c['id']]);
JobServico::transicionar($j3, 'enviando', 'x');
JobServico::transicionar($j3, 'falha', 'OLT recusou', ['falha_origem' => 'procedimento_firmware']);
T::igual('falha libera a ONU', null, JobServico::linha($j3)['onu_ativa']);

T::suite('Jobs :: reprocessamento');

$rp = JobServico::reprocessar($j3, 'bruno');
T::igual('falha -> pendente, tentativas zeradas, ONU segura de novo', ['pendente', 0, true],
    [$rp['estado'], (int) JobServico::linha($j3)['tentativas'], JobServico::linha($j3)['onu_ativa'] !== null]);
T::igual('reprocessamento auditado', 1, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = 'job_reprocessar' AND entidade_id = ?", [$j3]));
T::recusa('job pendente nao e reprocessado', fn() => JobServico::reprocessar($j3, 'bruno'), 'ZTE-JOB-003');

// ONU em job ativo de outra campanha: reprocessar e recusado
$onuJ3 = (int) JobServico::linha($j3)['onu_id'];
JobServico::transicionar($j3, 'falha', 'de novo', ['falha_origem' => 'verificacao']);
$x = CampanhaServico::salvar(['nome' => 'Pega a ONU', 'onus' => [$onuJ3]] + $cb, 'ana');
CampanhaServico::simular($x['id'], 'ana');
CampanhaServico::aprovar($x['id'], 'bruno', true, false);
T::recusa('ONU ocupada por outra campanha: reprocessar recusado', fn() => JobServico::reprocessar($j3, 'bruno'), 'ZTE-JOB-004');

T::suite('Campanhas :: pausar, retomar, abortar');

$c = CampanhaServico::pausar($c['id'], 'teste de pausa', 'bruno');
T::igual('pausada com motivo', ['pausada', 'teste de pausa'], [$c['estado'], $c['pausa_motivo']]);
Config::set('modo_seguro', '1', 'teste');
T::recusa('modo seguro ligado: campanha grande nao retoma', fn() => CampanhaServico::retomar($c['id'], 'bruno'), 'ZTE-CAM-011');
Config::set('modo_seguro', '0', 'teste');
$c = CampanhaServico::retomar($c['id'], 'bruno');
T::igual('retomada volta a aprovada', 'aprovada', $c['estado']);
$antes = $c['jobs'];
$c = CampanhaServico::abortar($c['id'], 'bruno');
T::igual('abortada: pendentes viram falha, inconclusivo NAO e tocado', ['abortada', 0, 1],
    [$c['estado'], $c['jobs']['pendente'], $c['jobs']['inconclusivo']]);
T::igual('falhas da abortagem liberaram as ONUs', 0,
    (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado = 'falha' AND onu_ativa IS NOT NULL", [$c['id']]));
T::recusa('campanha abortada nao retoma', fn() => CampanhaServico::retomar($c['id'], 'bruno'), 'ZTE-CAM-002');
T::recusa('regra em campanha ativa nao muda', fn() => RegraServico::salvar(['id' => $regra['id'], 'versao' => $regra['versao']] + $base, 'teste'), 'ZTE-REG-005');
T::recusa('regra usada nao e removida', fn() => RegraServico::remover($regra['id'], 'teste'), 'ZTE-REG-006');

T::suite('Campanhas :: permissoes, auditoria e diagnostico');

T::igual('papeis das operacoes', ['campanha.criar', 'campanha.criar', 'campanha.aprovar', 'campanha.operar', 'job.reprocessar'],
    [Rotas::MAPA['campanha.salvar'][1], Rotas::MAPA['campanha.simular'][1], Rotas::MAPA['campanha.aprovar'][1], Rotas::MAPA['campanha.abortar'][1], Rotas::MAPA['job.reprocessar'][1]]);
foreach (['campanha_criar', 'campanha_simular', 'campanha_aprovar', 'campanha_pausar', 'campanha_retomar', 'campanha_abortar'] as $a) {
    T::certo("auditoria: $a", (int) Db::valor('SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = ?', [$a]) > 0);
}
$aud = Db::um("SELECT correlacao FROM tab_zte_auditoria WHERE acao = 'campanha_aprovar' ORDER BY id DESC LIMIT 1");
T::certo('auditoria correlacionada pelo uuid da campanha', strlen((string) $aud['correlacao']) === 36);
$porId = array_column(Diagnostico::componentes(), null, 'componente');
T::igual('diagnostico aponta o job inconclusivo', 'erro', $porId['campanhas']['resultado']);

zte_parar($procCamp);
Config::set('modo_seguro', '1', 'teste');
