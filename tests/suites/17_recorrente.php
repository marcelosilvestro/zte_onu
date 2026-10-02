<?php
/**
 * Suite 17 :: campanhas RECORRENTES (rodadas pela agenda), excluir e arquivar.
 * Usa a OLT simulada ($oid), a regra ($regra), o repositorio ($rid) e zte_ciclo() da suite 15.
 * Datas: 2026-10-05 e segunda-feira, 2026-10-06 terca, 2026-10-07 quarta.
 */
T::suite('Recorrente :: preparacao e cadastro');

Db::exec('DELETE FROM tab_zte_job');
Db::exec('DELETE FROM tab_zte_campanha');
Db::exec('DELETE FROM tab_zte_sim_upgrade');
Db::exec("UPDATE tab_zte_onu SET sw_versao = IF(MOD(onu_num, 3) = 0, 'V9.0.11P3N10', 'V9.0.11P1N52') WHERE olt_id = ? AND fornecedor = 'ZTEG'", [$oid]);
Db::exec('UPDATE tab_zte_olt SET inventario_em = NOW(), flash_lido_em = NULL WHERE id = ?', [$oid]);
[$porta, $procR] = zte_ftp_falso('normal');
Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $rid]);
Config::limparCache();
Config::set('modo_seguro', '0', 'teste');
Config::set('max_transferencias_olt', '4', 'teste');
Worker::$relogio = fn() => '03:00:00';
Worker::$hoje = fn() => '2026-10-05';

$base = ['nome' => 'Diaria PON 1/1', 'olt_id' => $oid, 'regra_id' => $regra['id'], 'tipo' => 'recorrente', 'pons' => ['1/1'],
         'janela_inicio' => '02:00', 'janela_fim' => '05:00', 'max_concorrentes' => 4, 'max_por_pon' => 4, 'max_falhas' => 5, 'retentativas' => 0];
T::recusa('recorrente sem dia da semana e recusada', fn() => CampanhaServico::salvar($base + ['dias_semana' => []], 'ana'), 'ZTE-CAM-016');
$p = CampanhaServico::salvar(['dias_semana' => [3, 1], 'teto_rodada' => 3] + $base, 'ana');
T::igual('recorrente salva: dias ordenados, teto, sem ONUs fixas', ['recorrente', [1, 3], 3, []], [$p['tipo'], $p['dias'], $p['teto_rodada'], $p['escopo']['onus']]);
$p = CampanhaServico::simular($p['id'], 'ana');
$vs = array_column($p['simulacao']['verificacoes'], null, 'id');
T::igual('simulacao da recorrente: agenda informada e nada bloqueia', [true, 0], [isset($vs['agenda']), $p['simulacao']['bloqueios']]);
$p = CampanhaServico::aprovar($p['id'], 'bruno', true, false);
T::igual('aprovada SEM jobs (quem executa sao as rodadas)', ['aprovada', 0, true], [$p['estado'], $p['total_jobs'], Db::valor('SELECT aprovacao_assinatura FROM tab_zte_campanha WHERE id = ?', [$p['id']]) !== null]);
$pid = $p['id'];

T::suite('Recorrente :: agenda e rodadas');

T::igual('fora da janela: nenhuma rodada', [], CampanhaServico::rodadasAgendadas('2026-10-05', '12:00:00'));
T::igual('terca nao esta na agenda: nenhuma rodada', [], CampanhaServico::rodadasAgendadas('2026-10-06', '03:00:00'));
$r = CampanhaServico::rodadasAgendadas('2026-10-05', '03:00:00');
$filhas = Db::todos('SELECT * FROM tab_zte_campanha WHERE pai_id = ?', [$pid]);
T::igual('segunda na janela: 1 rodada criada, aprovada, com o teto (3 ONUs)', [1, 'aprovada', 3],
    [count($filhas), $filhas[0]['estado'] ?? null, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ?', [$filhas[0]['id'] ?? 0])]);
T::certo('resumo diz quantas havia e que o resto fica para as proximas', str_contains(implode(' ', $r), 'o resto fica para as próximas'));
T::igual('rodada aprovada pelo sistema e marcada como filha', ['worker', 'rodada', $pid], [$filhas[0]['aprovada_por'], $filhas[0]['tipo'], (int) $filhas[0]['pai_id']]);
T::igual('mesmo dia de novo: nada (uma rodada por dia de agenda)', [], CampanhaServico::rodadasAgendadas('2026-10-05', '04:00:00'));

$janelaNoite = ['janela_inicio' => '22:00:00', 'janela_fim' => '04:00:00', 'dias_semana' => '1'];
T::igual('janela que cruza a meia-noite: 01h de terca e a rodada de SEGUNDA', '2026-10-05', CampanhaServico::dataDaRodada($janelaNoite, '2026-10-06', '01:00:00'));
T::igual('23h de terca abre a rodada de terca (fora da agenda)', null, CampanhaServico::dataDaRodada($janelaNoite, '2026-10-06', '23:00:00'));

for ($i = 0; $i < 6; $i++) {
    zte_ciclo(50);
}
$f1 = (int) $filhas[0]['id'];
T::igual('worker executa a rodada como campanha comum: concluida, 3 ok', ['concluida', 3], [CampanhaServico::linha($f1)['estado'], JobServico::contagem($f1)['concluido']]);
T::igual('a recorrente NAO e "concluida" pelo worker (continua ativa)', 'aprovada', CampanhaServico::linha($pid)['estado']);

T::suite('Recorrente :: modo seguro, falhas e pausa');

Config::set('modo_seguro', '1', 'teste');
CampanhaServico::rodadasAgendadas('2026-10-07', '03:00:00');
$f2 = (int) Db::valor('SELECT MAX(id) FROM tab_zte_campanha WHERE pai_id = ?', [$pid]);
T::igual('modo seguro ligado: a rodada fica com 1 ONU', 1, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ?', [$f2]));
Config::set('modo_seguro', '0', 'teste');
$jf = Db::um('SELECT * FROM tab_zte_job WHERE campanha_id = ?', [$f2]);
JobServico::transicionar((int) $jf['id'], 'enviando', 'teste');
JobServico::transicionar((int) $jf['id'], 'falha', 'teste', ['falha_origem' => 'procedimento_firmware']);
T::igual('ONU que falhou fica de fora das proximas rodadas', [(int) $jf['onu_id']], CampanhaServico::falhasDaFamilia($pid));
CampanhaServico::pausarAutomatico($f2, 'Disjuntor: teste');
T::igual('rodada pausada pelo disjuntor pausa a recorrente', 'pausada', CampanhaServico::linha($pid)['estado']);
T::recusa('rodada nao e retomada sozinha com a recorrente pausada', fn() => CampanhaServico::retomar($f2, 'bruno'), 'ZTE-CAM-019');
CampanhaServico::retomar($pid, 'bruno');
T::igual('retomar a recorrente retoma a rodada pausada', ['aprovada', 'aprovada'], [CampanhaServico::linha($pid)['estado'], CampanhaServico::linha($f2)['estado']]);
$ob = CampanhaServico::obter($pid);
T::igual('detalhe mostra as rodadas e as ONUs excluidas por falha', [2, 1], [$ob['rodadas_total'], $ob['falhas_excluidas']]);
CampanhaServico::liberarFalhas($pid, 'bruno');
T::igual('liberar: as ONUs que falharam voltam a ser candidatas', [], CampanhaServico::falhasDaFamilia($pid));
CampanhaServico::pausar($pid, 'manual', 'bruno');
T::igual('pausar a recorrente pausa as rodadas em curso', 'pausada', CampanhaServico::linha($f2)['estado']);

T::suite('Recorrente :: regra mudou = nova aprovacao');

CampanhaServico::retomar($pid, 'bruno');
T::igual('a recorrente nao trava a edicao da regra (so as rodadas ativas travam)', 0,
    (int) Db::valor("SELECT COUNT(*) FROM tab_zte_campanha WHERE regra_id = ? AND tipo <> 'recorrente' AND estado IN ('aprovada','executando','pausada') AND id <> ?", [$regra['id'], $f2]));
Db::exec('UPDATE tab_zte_regra SET versao = versao + 1 WHERE id = ?', [$regra['id']]);
$r = CampanhaServico::rodadasAgendadas('2026-10-12', '03:00:00');
T::igual('regra editada: nenhuma rodada nova e a recorrente volta a rascunho', ['rascunho', 2],
    [CampanhaServico::linha($pid)['estado'], (int) Db::valor('SELECT COUNT(*) FROM tab_zte_campanha WHERE pai_id = ?', [$pid])]);
T::certo('motivo explica que precisa aprovar de novo', str_contains((string) CampanhaServico::linha($pid)['pausa_motivo'], 'aprove de novo'));
CampanhaServico::simular($pid, 'ana');
CampanhaServico::aprovar($pid, 'bruno', true, false);
T::igual('simulada e aprovada de novo: volta a rodar', 'aprovada', CampanhaServico::linha($pid)['estado']);

T::suite('Campanhas :: excluir e arquivar');

$rasc = CampanhaServico::salvar(['nome' => 'Rascunho descartavel', 'olt_id' => $oid, 'regra_id' => $regra['id'], 'janela_inicio' => '00:00', 'janela_fim' => '23:59'], 'ana');
CampanhaServico::excluir($rasc['id'], 'bruno');
T::igual('rascunho que nunca executou: excluido de verdade (e auditado)', [0, 1],
    [(int) Db::valor('SELECT COUNT(*) FROM tab_zte_campanha WHERE id = ?', [$rasc['id']]),
     (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = 'campanha_excluir' AND entidade_id = ?", [$rasc['id']])]);
T::recusa('recorrente ativa nao e excluida', fn() => CampanhaServico::excluir($pid, 'bruno'), 'ZTE-CAM-002');
T::recusa('arquivar so concluida ou abortada', fn() => CampanhaServico::arquivar($pid, true, 'bruno'), 'ZTE-CAM-018');
CampanhaServico::abortar($pid, 'bruno');
T::igual('encerrar a recorrente aborta as rodadas com fila', 'abortada', CampanhaServico::linha($f2)['estado']);
T::recusa('recorrente que ja executou rodadas: so arquiva', fn() => CampanhaServico::excluir($pid, 'bruno'), 'ZTE-CAM-017');
CampanhaServico::arquivar($pid, true, 'bruno');
T::igual('arquivada some da lista normal e aparece nas arquivadas, com as rodadas', [false, true, true],
    [in_array($pid, array_column(CampanhaServico::listar(false), 'id'), true), in_array($pid, array_column(CampanhaServico::listar(true), 'id'), true),
     in_array($f1, array_column(CampanhaServico::listar(true), 'id'), true)]);
T::igual('historico preservado: os jobs da rodada continuam', 3, JobServico::contagem($f1)['concluido']);
CampanhaServico::arquivar($pid, false, 'bruno');
T::igual('desarquivar volta para a lista', true, in_array($pid, array_column(CampanhaServico::listar(false), 'id'), true));
T::igual('rotas novas exigem campanha.operar', ['campanha.operar', 'campanha.operar', 'campanha.operar'],
    [Rotas::MAPA['campanha.excluir'][1], Rotas::MAPA['campanha.arquivar'][1], Rotas::MAPA['campanha.liberar_falhas'][1]]);

zte_parar($procR);
Worker::$relogio = null;
Worker::$hoje = null;
Config::set('modo_seguro', '1', 'teste');
