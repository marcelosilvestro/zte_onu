<?php
/**
 * Suite 15 :: worker — execucao das campanhas na OLT simulada, ciclo a ciclo.
 * Usa a OLT simulada ($oid), o firmware alvo ($fwAlvo), o acesso OLT->FTP e o repositorio das
 * suites anteriores. O relogio da simulacao avanca mexendo em tab_zte_sim_upgrade.iniciado_em.
 */
T::suite('Worker :: preparacao');

// Comeca limpo: campanhas e jobs das suites anteriores saem (eventos e escopo vao em cascata).
Db::exec('DELETE FROM tab_zte_job');
Db::exec('DELETE FROM tab_zte_campanha');
Db::exec('DELETE FROM tab_zte_sim_upgrade');
Config::limparCache();
Config::set('modo_seguro', '0', 'teste');
Config::set('job_timeout_min', '15', 'teste');
Config::set('max_transferencias_olt', '4', 'teste');      // a campanha de 15 ONUs testa o paralelismo
[$porta, $procW] = zte_ftp_falso('normal');
Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $rid]);
Worker::$relogio = fn() => '12:00:00';
Worker::$fabricaTransporte = null;

function zte_ciclo(int $avancarSeg = 0): array
{
    if ($avancarSeg > 0) {
        Db::exec('UPDATE tab_zte_sim_upgrade SET iniciado_em = iniciado_em - INTERVAL ? SECOND,
                         ativado_em = IF(ativado_em IS NULL, NULL, ativado_em - INTERVAL ? SECOND),
                         confirmado_em = IF(confirmado_em IS NULL, NULL, confirmado_em - INTERVAL ? SECOND)', [$avancarSeg, $avancarSeg, $avancarSeg]);
        // O relogio do worker tambem anda para quem espera o commit (tempo desde que a ONU voltou e
        // desde o ultimo commit pedido).
        Db::exec("UPDATE tab_zte_job SET heartbeat = heartbeat - INTERVAL ? SECOND WHERE estado = 'verificando'", [$avancarSeg]);
        Db::exec('UPDATE tab_zte_job_evento SET criado_em = criado_em - INTERVAL ? SECOND WHERE detalhe = ?', [$avancarSeg, Worker::COMMIT_PEDIDO]);
    }
    return Worker::executar(['inventario' => false, 'sincronizacao' => false, 'limite_s' => 60]);
}
function zte_job_da_onu(int $campanha, int $onuNum): array
{
    global $oid;
    return Db::um('SELECT j.* FROM tab_zte_job j JOIN tab_zte_onu o ON o.id = j.onu_id WHERE j.campanha_id = ? AND o.olt_id = ? AND o.onu_num = ?',
        [$campanha, $oid, $onuNum]) ?? [];
}
function zte_nova_campanha(string $nome, array $extra): array
{
    global $oid, $regra;
    // $extra primeiro: no "+" do PHP vale a chave da ESQUERDA.
    $c = CampanhaServico::salvar($extra + ['nome' => $nome, 'olt_id' => $oid, 'regra_id' => $regra['id'], 'janela_inicio' => '00:00', 'janela_fim' => '23:59',
        'max_concorrentes' => 4, 'max_por_pon' => 4, 'max_falhas' => 5, 'max_falhas_pct' => 100, 'retentativas' => 1], 'ana');
    CampanhaServico::simular($c['id'], 'ana');
    return CampanhaServico::aprovar($c['id'], 'bruno', true, false);
}

T::igual('janela normal', [true, false], [Worker::dentroDaJanela('02:00', '05:00', '03:10'), Worker::dentroDaJanela('02:00', '05:00', '05:00')]);
T::igual('janela que cruza a meia-noite', [true, true, false],
    [Worker::dentroDaJanela('22:00', '04:00', '23:30'), Worker::dentroDaJanela('22:00', '04:00', '01:00'), Worker::dentroDaJanela('22:00', '04:00', '12:00')]);

$r = zte_ciclo();
T::igual('sem trabalho: o ciclo roda e registra o batimento', [true, 'ok'], [$r['executado'], Db::valor("SELECT resultado FROM tab_zte_worker WHERE nome = 'principal'")]);
$outra = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}", $cfg['user'], $cfg['pass']);
$outra->query("SELECT GET_LOCK('zte_onu_worker', 0)");
T::igual('com outro worker rodando, sai sem fazer nada', false, zte_ciclo()['executado']);
$outra->query("SELECT RELEASE_LOCK('zte_onu_worker')");

T::suite('Worker :: campanha de 15 ONUs');

$c = zte_nova_campanha('Execucao', ['pons' => ['1/1']]);
$cid = $c['id'];
T::igual('15 ONUs na campanha', 15, $c['jobs']['pendente']);

$r = zte_ciclo();
T::igual('ciclo 1: 4 iniciadas (limite global e por PON)', 4, $r['iniciados']);
T::igual('campanha passa a executando', 'executando', CampanhaServico::linha($cid)['estado']);
$j4 = zte_job_da_onu($cid, 4);
T::igual('job da ONU 4: enviando, 1 tentativa, com dono', ['enviando', 1, true], [$j4['estado'], (int) $j4['tentativas'], $j4['dono'] !== null]);
$ev = Db::todos('SELECT saida_cli FROM tab_zte_job_evento WHERE job_id = ? AND saida_cli IS NOT NULL', [$j4['id']]);
T::certo('saida da OLT com a senha mascarada', $ev && str_contains($ev[0]['saida_cli'], 'password ***') && !str_contains($ev[0]['saida_cli'], 'Olt#Ftp1'));
T::certo('comando real de atualizacao: arquivo, ONU, FTP direto e pasta na visao da OLT', $ev && str_contains($ev[0]['saida_cli'],
    'remote-unit update F670L_V9.0.11P3N10.bin gpon-olt_1/1/1 4 remote ftp ipaddress 192.0.2.10 path /zte user olt_leitura password ***'));

Db::exec('UPDATE tab_zte_sim_upgrade SET iniciado_em = iniciado_em - INTERVAL 10 SECOND');
zte_ciclo();
$pct = Db::valor('SELECT progresso FROM tab_zte_job WHERE id = ?', [$j4['id']]);
T::certo('meio da transferencia: % do update-status gravado no job (' . var_export($pct, true) . ')', $pct !== null && (int) $pct >= 50 && (int) $pct < 100);
T::igual('ciclo do worker conta os logins na OLT (1 sessao por OLT)', 1, Worker::executar(['inventario' => false, 'sincronizacao' => false])['logins_olt']);

$r = zte_ciclo(50);
T::igual('ciclo 2: versao no banco inativo -> ativacao pedida (4 ativando)', 4,
    (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado = 'ativando'", [$cid]));
T::igual('nada concluido sem a versao nova ativa', 0, $r['concluidos']);
$evA = Db::valor("SELECT saida_cli FROM tab_zte_job_evento WHERE job_id = ? AND para_estado = 'ativando'", [$j4['id']]);
T::certo('ativacao com o comando real', str_contains((string) $evA, 'remote-unit activate gpon-olt_1/1/1 4'));
T::igual('fora da transferencia o % e zerado', null, Db::valor('SELECT progresso FROM tab_zte_job WHERE id = ?', [$j4['id']]));

$r = zte_ciclo(50);
$commits = fn() => (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job_evento WHERE job_id = ? AND detalhe = ?', [$j4['id'], Worker::COMMIT_PEDIDO]);
T::igual('ciclo 3: ONU voltou na versao nova -> verificando, SEM commit ainda (commit logo na volta e ignorado pela OLT real)', [0, 4, 0],
    [$r['concluidos'], (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado = 'verificando'", [$cid]), $commits()]);
zte_ciclo(50);
T::igual('ciclo seguinte: commit pedido', 1, $commits());
$r = zte_ciclo(50);
T::igual('ciclo 4: commit valeu -> as 4 concluidas', 4, $r['concluidos']);
T::igual('um unico commit por ONU', 1,
    (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job_evento WHERE job_id = ? AND detalhe = ?', [$j4['id'], Worker::COMMIT_PEDIDO]));
$j4 = zte_job_da_onu($cid, 4);
T::igual('job concluido com a versao final e a ONU liberada', ['concluido', 'V9.0.11P3N10', null], [$j4['estado'], $j4['sw_versao_final'], $j4['onu_ativa']]);
T::igual('inventario da ONU com a versao nova e a anterior no outro banco', ['V9.0.11P3N10', 'V9.0.11P1N52'],
    array_values(Db::um('SELECT sw_versao, sw_standby FROM tab_zte_onu WHERE id = ?', [$j4['onu_id']])));
T::igual('eventos: update, aceite, ativacao, verificacao, commit, concluido',
    ['enviando', 'enviando', 'ativando', 'verificando', 'verificando', 'concluido'],
    array_column(Db::todos('SELECT para_estado FROM tab_zte_job_evento WHERE job_id = ? ORDER BY id', [$j4['id']]), 'para_estado'));
T::certo('commit com o comando real', str_contains((string) Db::valor("SELECT saida_cli FROM tab_zte_job_evento WHERE job_id = ? AND detalhe LIKE 'Confirma%'", [$j4['id']]),
    'remote-unit commit gpon-olt_1/1/1 4'));
T::igual('ciclo 4: mais 4 iniciadas', 4, $r['iniciados']);

$r = zte_ciclo(50);
T::certo('ONU 13: a OLT informou falha na transferencia e ela voltou para a fila (retentativa)', $r['retentativas'] >= 1);
T::igual('ONU 16 (travada) continua enviando', 'enviando', zte_job_da_onu($cid, 16)['estado']);

for ($i = 0; $i < 8; $i++) {
    zte_ciclo(50);
}
$j13 = zte_job_da_onu($cid, 13);
T::igual('ONU 13: falha definitiva depois de esgotar as tentativas', ['falha', 2, 'procedimento_firmware'], [$j13['estado'], (int) $j13['tentativas'], $j13['falha_origem']]);
T::igual('ONU 19 (sumiu depois de ativar) aguarda em ativando', 'ativando', zte_job_da_onu($cid, 19)['estado']);
T::igual('ONU 16 travada continua enviando', 'enviando', zte_job_da_onu($cid, 16)['estado']);

T::suite('Worker :: sem sinal, reconciliacao e disjuntor');

Db::exec('UPDATE tab_zte_job SET heartbeat = NOW() - INTERVAL 20 MINUTE WHERE id IN (?, ?)', [zte_job_da_onu($cid, 16)['id'], zte_job_da_onu($cid, 19)['id']]);
$r = zte_ciclo();
$j19 = zte_job_da_onu($cid, 19);
T::igual('ONU 19 sem resposta depois do tempo: INCONCLUSIVO, segurando a ONU', ['inconclusivo', true], [$j19['estado'], $j19['onu_ativa'] !== null]);
$j16 = zte_job_da_onu($cid, 16);
T::igual('ONU 16 travada: reconciliada lendo a ONU (continua na versao antiga) e devolvida para retentativa', 'pendente', $j16['estado']);
$cc = CampanhaServico::linha($cid);
T::igual('disjuntor: inconclusivo pausa a campanha', 'pausada', $cc['estado']);
T::certo('motivo da pausa explica', str_contains((string) $cc['pausa_motivo'], 'inconclusivo'));
$r = zte_ciclo(50);
T::igual('pausada: nenhum job novo', 0, $r['iniciados']);

Db::exec("UPDATE tab_zte_sim_upgrade SET falha = NULL WHERE onu_num = 19");
zte_ciclo();
T::igual('ONU 19 voltou na versao nova sem commit: reconciliacao pede o commit e aguarda', ['inconclusivo', 1],
    [zte_job_da_onu($cid, 19)['estado'], (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job_evento WHERE job_id = ? AND detalhe = ?', [zte_job_da_onu($cid, 19)['id'], Worker::COMMIT_PEDIDO])]);
zte_ciclo(15);
T::igual('ONU 19 voltou: reconciliacao le a versao alvo e conclui', 'concluido', zte_job_da_onu($cid, 19)['estado']);

CampanhaServico::retomar($cid, 'bruno');
zte_ciclo();
T::igual('retomada: a ONU 16 recomeca (2a tentativa)', ['enviando', 2], [zte_job_da_onu($cid, 16)['estado'], (int) zte_job_da_onu($cid, 16)['tentativas']]);
Db::exec('UPDATE tab_zte_job SET heartbeat = NOW() - INTERVAL 20 MINUTE WHERE id = ?', [zte_job_da_onu($cid, 16)['id']]);
zte_ciclo(50);
for ($i = 0; $i < 5; $i++) {
    zte_ciclo(50);
}
$c = CampanhaServico::obter($cid);
T::igual('ONU 16: falha definitiva', 'falha', zte_job_da_onu($cid, 16)['estado']);
T::igual('campanha concluida: 13 ok, 2 falhas', ['concluida', 13, 2], [$c['estado'], $c['jobs']['concluido'], $c['jobs']['falha']]);
T::igual('conclusao auditada', 1, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = 'campanha_concluir' AND entidade_id = ?", [$cid]));
$todas = json_encode(Db::todos('SELECT saida_cli, detalhe FROM tab_zte_job_evento')) . json_encode(Db::todos('SELECT detalhe FROM tab_zte_worker'));
T::certo('nenhuma senha em eventos de job nem no batimento', !str_contains($todas, 'Olt#Ftp1'));

T::suite('Worker :: janela, abortar com job em curso');

$c2 = zte_nova_campanha('Janela', ['onus' => [(int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 13', [$oid])],
    'janela_inicio' => '02:00', 'janela_fim' => '05:00']);
Worker::$relogio = fn() => '06:00:00';
T::igual('fora da janela: nada inicia', 0, zte_ciclo()['iniciados']);
Worker::$relogio = fn() => '03:00:00';
T::igual('dentro da janela: inicia', 1, zte_ciclo()['iniciados']);
CampanhaServico::abortar($c2['id'], 'bruno');
zte_ciclo(50);
$j = Db::um('SELECT * FROM tab_zte_job WHERE campanha_id = ?', [$c2['id']]);
T::igual('abortada: o job em curso foi acompanhado ate o fim, sem retentativa', ['falha', 1], [$j['estado'], (int) $j['tentativas']]);
T::igual('campanha continua abortada', 'abortada', CampanhaServico::linha($c2['id'])['estado']);
Worker::$relogio = fn() => '12:00:00';

T::suite('Worker :: modo seguro, arquivo sumido, OLT fora do ar, disjuntor por falhas');

$id13 = (int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 13', [$oid]);
$id16 = (int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 16', [$oid]);

$c3 = zte_nova_campanha('Duas ONUs', ['onus' => [$id13, $id16]]);
Config::set('modo_seguro', '1', 'teste');
zte_ciclo();
$x = CampanhaServico::linha($c3['id']);
T::igual('modo seguro religado: campanha de 2 ONUs e pausada pelo worker', ['pausada', 0],
    [$x['estado'], (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado <> 'pendente'", [$c3['id']])]);
Config::set('modo_seguro', '0', 'teste');
CampanhaServico::abortar($c3['id'], 'bruno');

$c4 = zte_nova_campanha('Arquivo sumiu', ['onus' => [$id13]]);
$arqFw = $ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.0.11P3N10.bin';
rename($arqFw, $arqFw . '.x');
zte_ciclo();
$x = CampanhaServico::linha($c4['id']);
T::igual('arquivo sumiu do FTP antes do lote: pausada, nada iniciado', ['pausada', 0],
    [$x['estado'], (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado <> 'pendente'", [$c4['id']])]);
T::igual('e o firmware ficou marcado ausente', 'ausente_no_ftp', FirmwareServico::linha($fwAlvo['id'])['estado']);
rename($arqFw . '.x', $arqFw);
FirmwareServico::verificar($fwAlvo['id'], 'reverificacao', 'teste');
FirmwareServico::disponibilizar($fwAlvo['id'], 'teste', false);
CampanhaServico::abortar($c4['id'], 'bruno');

$c5 = zte_nova_campanha('OLT fora', ['onus' => [$id16]]);
Worker::$fabricaTransporte = fn($olt) => new class implements Transporte {
    public function conectar(): void { throw new OltFalha('conexao', 'teste: sem rota'); }
    public function executar(string $l): string { return ''; }
    public function nomeEquipamento(): string { return ''; }
    public function fechar(): void {}
};
$r = zte_ciclo();
T::certo('OLT inalcancavel: o ciclo registra o erro', count($r['erros']) > 0);
T::igual('e nenhum job muda de estado sem a OLT', 'pendente', Db::valor('SELECT estado FROM tab_zte_job WHERE campanha_id = ?', [$c5['id']]));
T::igual('batimento em aviso', 'aviso', Db::valor("SELECT resultado FROM tab_zte_worker WHERE nome = 'principal'"));
Worker::$fabricaTransporte = null;
CampanhaServico::abortar($c5['id'], 'bruno');

$c6 = zte_nova_campanha('Disjuntor', ['onus' => [$id13], 'max_falhas' => 1, 'retentativas' => 0]);
zte_ciclo();
zte_ciclo(50);
$x = CampanhaServico::linha($c6['id']);
T::igual('1 falha com limite 1: disjuntor pausa', 'pausada', $x['estado']);
T::certo('motivo do disjuntor', str_contains((string) $x['pausa_motivo'], 'falha'));
T::igual('pausa automatica auditada como worker', 'worker',
    Db::valor("SELECT usuario FROM tab_zte_auditoria WHERE acao = 'campanha_pausa_automatica' ORDER BY id DESC LIMIT 1"));
CampanhaServico::abortar($c6['id'], 'bruno');

T::suite('Worker :: commit ignorado pela OLT');

$c11 = zte_nova_campanha('Commit ignorado', ['onus' => [$id16]]);
zte_ciclo();
Db::exec("UPDATE tab_zte_sim_upgrade SET falha = 'commit_ignorado' WHERE onu_num = 16");
zte_ciclo(50);                                    // ativando
zte_ciclo(50);                                    // verificando
zte_ciclo(50);                                    // 1o commit (ignorado)
$j11 = Db::um('SELECT * FROM tab_zte_job WHERE campanha_id = ?', [$c11['id']]);
$nCommits = fn() => (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job_evento WHERE job_id = ? AND detalhe = ?', [$j11['id'], Worker::COMMIT_PEDIDO]);
T::igual('commit ignorado: continua verificando, 1 pedido', ['verificando', 1], [$j11['estado'], $nCommits()]);
zte_ciclo(60);
T::igual('menos de 2 min: nao reenvia', 1, $nCommits());
zte_ciclo(70);
T::igual('2 min sem confirmacao: reenvia', 2, $nCommits());
Db::exec("UPDATE tab_zte_sim_upgrade SET falha = NULL WHERE onu_num = 16");
zte_ciclo(130);
zte_ciclo(20);
T::igual('3o commit vale -> concluido', ['concluido', 3], [Db::valor('SELECT estado FROM tab_zte_job WHERE id = ?', [$j11['id']]), $nCommits()]);
// A ONU 16 volta a ser "antiga" para os testes seguintes.
Db::exec('DELETE FROM tab_zte_sim_upgrade WHERE onu_num = 16');
Db::exec("UPDATE tab_zte_onu SET sw_versao = 'V9.0.11P1N52' WHERE id = ?", [$id16]);

T::suite('Worker :: flash da OLT, resumo da OLT e abort');

$encerrar = function (int $campanha) {
    // Job preso em "enviando" (ONU 16 trava): tempo limite -> reconciliacao -> abort -> falha.
    Db::exec("UPDATE tab_zte_job SET heartbeat = NOW() - INTERVAL 20 MINUTE WHERE campanha_id = ? AND estado = 'enviando'", [$campanha]);
    try { CampanhaServico::abortar($campanha, 'bruno'); } catch (ZteErro $e) {}
    zte_ciclo();
};

DriverSimulado::$flashLivre = 1000;
$c7 = zte_nova_campanha('Sem flash', ['onus' => [$id16]]);
zte_ciclo();
$x = CampanhaServico::linha($c7['id']);
T::igual('flash da OLT sem espaco: pausada antes de enviar, nada iniciado', ['pausada', 0],
    [$x['estado'], (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado <> 'pendente'", [$c7['id']])]);
T::certo('motivo fala da flash', str_contains((string) $x['pausa_motivo'], 'Flash da OLT sem espaço'));
T::igual('leitura da flash guardada na OLT', 1000, (int) Db::valor('SELECT flash_livre FROM tab_zte_olt WHERE id = ?', [$oid]));
$tamFw = (int) FirmwareServico::linha($fwAlvo['id'])['tamanho_bytes'];
DriverSimulado::$flashArquivos = ['f670l_v9.0.11p3n10.bin' => $tamFw];
CampanhaServico::retomar($c7['id'], 'bruno');
T::igual('flash cheia mas com a MESMA imagem ja baixada (aging-time): inicia', 1, zte_ciclo()['iniciados']);
$encerrar((int) $c7['id']);
DriverSimulado::$flashArquivos = null;
$c7 = zte_nova_campanha('Sem flash 2', ['onus' => [$id16]]);
zte_ciclo();
$sim7 = CampanhaServico::calcular(CampanhaServico::linha($c7['id']));
T::igual('a simulacao tambem bloqueia pela flash (leitura recente do cache, sem novo login)', [true, 'erro'],
    [array_column($sim7['verificacoes'], null, 'id')['flash']['bloqueia'], array_column($sim7['verificacoes'], null, 'id')['flash']['resultado']]);
DriverSimulado::$flashLivre = null;
Db::exec('UPDATE tab_zte_olt SET flash_lido_em = NULL WHERE id = ?', [$oid]);
CampanhaServico::abortar($c7['id'], 'bruno');

$c8 = zte_nova_campanha('Download recusado', ['onus' => [$id16], 'retentativas' => 0]);
T::igual('com espaco: inicia', 1, zte_ciclo()['iniciados']);
$j8 = Db::um('SELECT * FROM tab_zte_job WHERE campanha_id = ?', [$c8['id']]);
T::certo('foto do resumo da OLT guardada antes do update', $j8['resumo_antes'] !== null);
Db::exec("UPDATE tab_zte_sim_upgrade SET falha = 'download' WHERE onu_num = 16");
zte_ciclo();
$j8 = Db::um('SELECT * FROM tab_zte_job WHERE campanha_id = ?', [$c8['id']]);
T::igual('download recusado pela OLT: falha NA HORA (sem esperar 15 min), origem procedimento', ['falha', 'procedimento_firmware'],
    [$j8['estado'], $j8['falha_origem']]);
T::certo('motivo da OLT no detalhe', str_contains((string) $j8['falha_detalhe'], 'Remain space not enough'));
T::certo('e a tarefa foi limpa na OLT (remote-unit abort)', str_contains((string) Db::valor("SELECT saida_cli FROM tab_zte_job_evento WHERE job_id = ? AND para_estado = 'falha'", [$j8['id']]),
    'remote-unit abort gpon-olt_1/1/1 16'));
T::igual('abort apagou a tarefa simulada', 0, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_sim_upgrade WHERE onu_num = 16'));
try { CampanhaServico::abortar($c8['id'], 'bruno'); } catch (ZteErro $e) {}

// Erro ANTIGO do mesmo arquivo no resumo (a lista nao tem data): nao pode derrubar um job novo.
Db::exec("INSERT INTO tab_zte_sim_upgrade (olt_id, slot, porta, onu_num, versao_alvo, falha, arquivo, iniciado_em)
          VALUES (?, 1, 1, 99, 'V9.0.11P3N10', 'download', 'F670L_V9.0.11P3N10.bin', NOW())", [$oid]);
$c9 = zte_nova_campanha('Erro antigo', ['onus' => [$id16]]);
zte_ciclo();
zte_ciclo(5);
T::igual('erro antigo do mesmo arquivo no resumo: o job novo segue', 'enviando', Db::valor('SELECT estado FROM tab_zte_job WHERE campanha_id = ?', [$c9['id']]));
$encerrar((int) $c9['id']);
$j9 = Db::um('SELECT * FROM tab_zte_job WHERE campanha_id = ?', [$c9['id']]);
T::igual('tempo limite com a ONU na versao antiga: falha de procedimento (nao "comunicacao")', ['falha', 'procedimento_firmware'], [$j9['estado'], $j9['falha_origem']]);
T::certo('com abort e a ultima saida da OLT no evento', str_contains((string) Db::valor("SELECT saida_cli FROM tab_zte_job_evento WHERE job_id = ? AND para_estado = 'falha'", [$j9['id']]), 'remote-unit abort'));
Db::exec('DELETE FROM tab_zte_sim_upgrade WHERE onu_num = 99');

Config::set('max_transferencias_olt', '1', 'teste');
$c10 = zte_nova_campanha('Uma por vez', ['onus' => [$id13, $id16]]);
T::igual('uma transferencia por vez na OLT (padrao)', 1, zte_ciclo()['iniciados']);
$encerrar((int) $c10['id']);
Config::set('max_transferencias_olt', '4', 'teste');

T::suite('Worker :: driver real (validado) e diagnostico');

$gravador = new class implements Transporte {
    public array $linhas = [];
    public string $resposta = '';
    public function conectar(): void {}
    public function executar(string $l): string { $this->linhas[] = $l; return $this->resposta; }
    public function nomeEquipamento(): string { return 'OLT-c320_1'; }
    public function fechar(): void {}
};
$real = new DriverZteC320V21($gravador);
$fwCtx = ['arquivo' => 'F670L_V9.0.11P3N10.bin', 'caminho' => '/firmware', 'host' => '192.0.2.10', 'usuario' => 'olt_leitura', 'senha' => 'Olt#Ftp1', 'versao' => 'V9.0.11P3N10'];
$real->iniciarUpgrade(2, 11, 4, $fwCtx);
$real->ativar(2, 11, 4);
$real->confirmar(2, 11, 4);
T::igual('driver real: os 3 comandos exatos', [
    'remote-unit update F670L_V9.0.11P3N10.bin gpon-olt_1/2/11 4 remote ftp ipaddress 192.0.2.10 path /firmware user olt_leitura password Olt#Ftp1',
    'remote-unit activate gpon-olt_1/2/11 4',
    'remote-unit commit gpon-olt_1/2/11 4'], $gravador->linhas);
$gravador->resposta = '%Error 20300: The RU is operating';
T::recusa('erro da OLT ao comando vira recusa (a ONU nao foi tocada)', fn() => $real->ativar(2, 11, 4), 'ZTE-OLT-016');
T::recusa('pasta com menos de 3 caracteres e recusada', fn() => ComandosZteC320V21::atualizar(1, 1, 1, 1, ['caminho' => '/'] + $fwCtx), 'ZTE-VIN-006');
T::recusa('senha com ponto e virgula nunca vira comando', fn() => ComandosZteC320V21::atualizar(1, 1, 1, 1, ['senha' => 'x;reboot'] + $fwCtx), 'ZTE-VAL-009');
T::recusa('arquivo com espaco e recusado', fn() => ComandosZteC320V21::atualizar(1, 1, 1, 1, ['arquivo' => 'a b.bin'] + $fwCtx), 'ZTE-VAL-003');
T::recusa('host do FTP precisa ser IPv4', fn() => ComandosZteC320V21::atualizar(1, 1, 1, 1, ['host' => 'ftp.x.com'] + $fwCtx), 'ZTE-VIN-003');
T::igual('nivel de upgrade da OLT real: validado (piloto de 02/10)', 'validado',
    RegistroOlt::nivelUpgrade(['protocolo' => 'telnet', 'driver' => 'zte_c320_v21', 'fabricante' => 'ZTE', 'modelo' => 'C320']));

// Experimental: campanha na OLT "real" so ate o tamanho do piloto (2 ONUs).
Db::exec("INSERT INTO tab_zte_olt (nome, host, porta, protocolo, usuario, driver, compatibilidade, inventario_em, criado_em)
          VALUES ('OLT Real Teste', '127.0.0.1', 1, 'telnet', 'u', 'zte_c320_v21', 'validada', NOW(), NOW())");
$oltReal = Db::ultimoId();
foreach ([1, 2, 3] as $n) {
    Db::exec("INSERT INTO tab_zte_onu (olt_id, slot, porta, onu_num, sn, fornecedor, modelo, hw_versao, sw_versao, estado, atualizado_em)
              VALUES (?, 1, 1, ?, ?, 'ZTEG', 'F670LV9.0', 'V9.0', 'V9.0.11P1N52', 'online', NOW())", [$oltReal, $n, 'ZTEGR000000' . $n]);
}
VinculoServico::salvar(['olt_id' => $oltReal, 'repositorio_id' => $rid, 'host_olt' => '192.0.2.10', 'porta_olt' => 21,
    'usuario_olt' => 'olt_leitura', 'senha_olt' => 'Olt#Ftp1', 'caminho_olt' => '/'], 'teste');
$cr = CampanhaServico::salvar(['nome' => 'Real 2 ONUs', 'olt_id' => $oltReal, 'regra_id' => $regra['id'], 'janela_inicio' => '00:00', 'janela_fim' => '23:59'], 'ana');
$cr = CampanhaServico::simular($cr['id'], 'ana');
$bl = array_column($cr['simulacao']['verificacoes'], null, 'id');
T::igual('driver validado: 3 ONUs sem a trava experimental (so o modo seguro limita)', false, isset($bl['experimental']));
$dois = array_map('intval', array_column(Db::todos('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num IN (1, 2)', [$oltReal]), 'id'));
$cr = CampanhaServico::salvar(['id' => $cr['id'], 'versao' => $cr['versao'], 'nome' => 'Real piloto', 'olt_id' => $oltReal, 'regra_id' => $regra['id'],
    'janela_inicio' => '00:00', 'janela_fim' => '23:59', 'onus' => $dois], 'ana');
$cr = CampanhaServico::simular($cr['id'], 'ana');
T::igual('piloto de 2 ONUs (modo seguro desligado): sem a trava experimental', false,
    isset(array_column($cr['simulacao']['verificacoes'], null, 'id')['experimental']));
$um = (int) Db::valor('SELECT id FROM tab_zte_onu WHERE olt_id = ? AND onu_num = 1', [$oltReal]);
$cr = CampanhaServico::salvar(['id' => $cr['id'], 'versao' => $cr['versao'], 'nome' => 'Real 1 ONU', 'olt_id' => $oltReal, 'regra_id' => $regra['id'],
    'janela_inicio' => '00:00', 'janela_fim' => '23:59', 'onus' => [$um]], 'ana');
$cr = CampanhaServico::simular($cr['id'], 'ana');
T::igual('com 1 ONU: aprovavel, driver validado = ok', [0, 'ok'],
    [$cr['simulacao']['bloqueios'], array_column($cr['simulacao']['verificacoes'], null, 'id')['driver']['resultado']]);
CampanhaServico::abortar($cr['id'], 'ana');
$l0 = OltServico::$logins;
OltServico::executarLeitura($oid, fn($d) => $d->estadoPon(1, 1));
OltServico::executarLeitura($oid, fn($d) => $d->estadoPon(1, 1));
T::igual('fora de sessao: cada leitura e um login', 2, OltServico::$logins - $l0);
$l0 = OltServico::$logins;
$tot = OltServico::emSessao($oid, function () use ($oid) {
    $a = OltServico::executarLeitura($oid, fn($d) => $d->estadoPon(1, 1));
    $b = OltServico::executarLeitura($oid, fn($d) => $d->estadoPon(1, 2));
    return $a['total'] + $b['total'];
});
T::igual('em sessao: varias leituras, um login so', [1, 27], [OltServico::$logins - $l0, $tot]);
$l0 = OltServico::$logins;
$outraSessao = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}", $cfg['user'], $cfg['pass']);
$outraSessao->query("SELECT GET_LOCK('zte_olt_" . $oid . "', 0)");
T::recusa('OLT ocupada por outra sessao do addon: espera e desiste, nunca abre uma segunda',
    fn() => OltServico::executarLeitura($oid, fn($d) => $d->estadoPon(1, 1)), 'ZTE-OLT-014');
$outraSessao->query("SELECT RELEASE_LOCK('zte_olt_" . $oid . "')");
T::igual('e nenhum login foi feito', 0, OltServico::$logins - $l0);

$porId = array_column(Diagnostico::componentes(), null, 'componente');
T::certo('diagnostico mostra o ultimo ciclo do worker', str_contains(json_encode($porId['worker'], JSON_UNESCAPED_UNICODE), 'Último ciclo'));
T::igual('estado do worker para a tela', true, Worker::estado()['registro'] !== null);

zte_parar($procW);
Worker::$relogio = null;
Config::set('modo_seguro', '1', 'teste');
