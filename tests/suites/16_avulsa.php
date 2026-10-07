<?php
/**
 * Suite 16 :: atualizacao AVULSA pelo inventario (tecnico no local).
 * Usa a OLT simulada ($oid), o firmware alvo ($fwAlvo), o repositorio ($rid) e as funcoes de
 * ciclo da suite 15 (zte_ciclo, zte_job_da_onu).
 */
T::suite('Avulsa :: preparacao');

[$porta, $procA] = zte_ftp_falso('normal');
Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $rid]);
Config::limparCache();
Config::set('modo_seguro', '1', 'teste');
Worker::$relogio = fn() => '03:33:00';           // fora de qualquer janela comum: a avulsa nao tem janela
Worker::$fabricaTransporte = null;
$avulsas = fn() => (int) Db::valor("SELECT COUNT(*) FROM tab_zte_campanha WHERE tipo = 'avulsa'");

// ONU desatualizada no simulado: ZTE, online, com modelo, fora das falhas injetadas e sem upgrade
// simulado (as de numero multiplo de 3 ja estao na versao nova nos fixtures).
Db::exec('DELETE FROM tab_zte_sim_upgrade');
$onu = Db::um("SELECT * FROM tab_zte_onu WHERE olt_id = ? AND fornecedor = 'ZTEG' AND estado = 'online' AND modelo = 'F670LV9.0'
                  AND MOD(onu_num, 3) <> 0 AND onu_num NOT IN (13, 16, 19) AND ausente_desde IS NULL ORDER BY onu_num DESC LIMIT 1", [$oid]);
Db::exec("UPDATE tab_zte_onu SET sw_versao = 'V9.0.11P1N52' WHERE id = ?", [$onu['id']]);
T::certo('ONU de teste escolhida (' . $onu['slot'] . '/' . $onu['porta'] . ':' . $onu['onu_num'] . ')', $onu !== null);
T::igual('rotas da avulsa exigem o papel proprio', ['onu.atualizar_avulso', 'onu.atualizar_avulso', 'POST'],
    [Rotas::MAPA['inventario.avulsa_previa'][1], Rotas::MAPA['inventario.avulsa'][1], Rotas::MAPA['inventario.avulsa'][0]]);
T::certo('papel novo existe', isset(Permissao::PAPEIS['onu.atualizar_avulso']));

T::suite('Avulsa :: quem pode e quem nao pode');

$furukawa = (int) Db::valor("SELECT id FROM tab_zte_onu WHERE olt_id = ? AND fornecedor <> 'ZTEG' LIMIT 1", [$oid]);
if ($furukawa) {
    T::recusa('outro fabricante e recusado', fn() => CampanhaServico::previaAvulsa($furukawa), 'ZTE-AVU-001');
}
$jaNova = Db::um("SELECT * FROM tab_zte_onu WHERE olt_id = ? AND fornecedor = 'ZTEG' AND estado = 'online' AND modelo = 'F670LV9.0' AND id <> ? LIMIT 1", [$oid, $onu['id']]);
Db::exec("UPDATE tab_zte_onu SET sw_versao = 'V9.0.11P3N10' WHERE id = ?", [$jaNova['id']]);
T::recusa('ja na versao mais nova: nada a fazer', fn() => CampanhaServico::previaAvulsa((int) $jaNova['id']), 'ZTE-AVU-002');
Db::exec("UPDATE tab_zte_onu SET estado = 'offline', sw_versao = 'V9.0.11P1N52' WHERE id = ?", [$jaNova['id']]);
T::recusa('offline e recusada', fn() => CampanhaServico::previaAvulsa((int) $jaNova['id']), 'ZTE-AVU-003');
Db::exec("UPDATE tab_zte_onu SET estado = 'online' WHERE id = ?", [$jaNova['id']]);

T::suite('Avulsa :: previa e liberacao');

$p = CampanhaServico::previaAvulsa((int) $onu['id']);
T::igual('previa: firmware mais novo compativel, versao atual, sem bloqueio', [$fwAlvo['versao_firmware'], 'V9.0.11P1N52', 0],
    [$p['firmware']['versao'], $p['onu']['sw_versao'], $p['bloqueios']]);
T::igual('previa nao grava campanha', 0, $avulsas());
T::certo('previa confere a flash da OLT (24,9 MB livres no fixture real)', str_contains(array_column($p['verificacoes'], null, 'id')['flash']['detalhe'], '24,9 MB livres'));

$vin = Db::um('SELECT * FROM tab_zte_olt_repositorio WHERE olt_id = ? AND repositorio_id = ?', [$oid, $rid]);
if ($p['precisa_ciencia']) {
    T::recusa('acesso OLT->FTP nao comprovado: exige ciencia', fn() => CampanhaServico::atualizarAvulsa((int) $onu['id'], 'tecnico', false), 'ZTE-CAM-007');
}
$arqFw = $ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.0.11P3N10.bin';
rename($arqFw, $arqFw . '.x');
T::recusa('arquivo sumiu do FTP: bloqueia ANTES de criar a campanha', fn() => CampanhaServico::atualizarAvulsa((int) $onu['id'], 'tecnico', true), 'ZTE-CAM-004');
T::igual('e nenhuma campanha ficou criada', 0, $avulsas());
rename($arqFw . '.x', $arqFw);

$l0 = OltServico::$logins;
$c = CampanhaServico::atualizarAvulsa((int) $onu['id'], 'tecnico', true);
T::igual('rele a ONU na OLT antes de liberar (1 login)', 1, OltServico::$logins - $l0);
T::igual('campanha avulsa aprovada na hora, sem regra, 1 job, sem retentativa', ['avulsa', 'aprovada', null, 1, 0],
    [$c['tipo'], $c['estado'], $c['regra_id'], $c['jobs']['pendente'], $c['retentativas']]);
T::igual('quem pediu aprovou (papel proprio, sem separacao criar/aprovar)', ['tecnico', 'tecnico'],
    [Db::valor('SELECT criado_por FROM tab_zte_campanha WHERE id = ?', [$c['id']]), Db::valor('SELECT aprovada_por FROM tab_zte_campanha WHERE id = ?', [$c['id']])]);
T::igual('auditada como atualizacao avulsa', 1, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = 'onu_atualizar_avulso' AND entidade_id = ?", [$onu['id']]));
T::recusa('segundo pedido para a mesma ONU e recusado', fn() => CampanhaServico::previaAvulsa((int) $onu['id']), 'ZTE-AVU-004');
T::recusa('avulsa nao e editavel', fn() => CampanhaServico::salvar(['id' => $c['id'], 'versao' => $c['versao'], 'nome' => 'x', 'olt_id' => $oid,
    'regra_id' => $regra['id'], 'janela_inicio' => '00:00', 'janela_fim' => '23:59'], 'ana'), 'ZTE-CAM-015');
T::igual('aparece na lista de campanhas', true, in_array($c['id'], array_column(CampanhaServico::listar(), 'id'), true));

T::suite('Avulsa :: execucao pelo worker');

$r = zte_ciclo();
T::igual('sem janela: o worker inicia as 03:33', 1, $r['iniciados']);
for ($i = 0; $i < 4; $i++) {
    zte_ciclo(50);
}
$j = zte_job_da_onu($c['id'], (int) $onu['onu_num']);
T::igual('concluida com a versao nova, um unico commit', ['concluido', 'V9.0.11P3N10', 1],
    [$j['estado'], $j['sw_versao_final'], (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job_evento WHERE job_id = ? AND detalhe = ?', [$j['id'], Worker::COMMIT_PEDIDO])]);
T::igual('campanha avulsa concluida', 'concluida', CampanhaServico::linha($c['id'])['estado']);
T::igual('OLT simulada nunca "comprova" o acesso ao FTP', $vin['estado_conectividade'],
    Db::valor('SELECT estado_conectividade FROM tab_zte_olt_repositorio WHERE id = ?', [$vin['id']]));

// Producao 07/10: "Testar acesso" regravou 'validado' como 'potencial' e a rodada seguinte da
// recorrente (aprovada sem ciencia) seria recusada. O teste nao rebaixa e reconhece a prova real.
T::igual('comprovacao: job concluido em OLT simulada nao conta', null, VinculoServico::comprovacao(VinculoServico::linha((int) $vin['id'])));
Db::exec("UPDATE tab_zte_olt SET protocolo = 'telnet' WHERE id = ?", [$oid]);
$prova = VinculoServico::comprovacao(VinculoServico::linha((int) $vin['id']));
Db::exec("UPDATE tab_zte_olt SET protocolo = 'simulado' WHERE id = ?", [$oid]);
T::igual('comprovacao: job concluido em OLT real com firmware do repositorio conta', (int) $j['id'], $prova['id'] ?? null);
$estadoVin = Db::um('SELECT estado_conectividade, estado_detalhe FROM tab_zte_olt_repositorio WHERE id = ?', [$vin['id']]);
Db::exec("UPDATE tab_zte_olt_repositorio SET estado_conectividade = 'validado', estado_detalhe = 'Comprovado: teste.' WHERE id = ?", [$vin['id']]);
$t = VinculoServico::testar((int) $vin['id'], 'teste');
T::igual('testar acesso nao rebaixa um acesso ja validado', ['validado', 'validado', 'ok'],
    [$t['estado'], VinculoServico::linha((int) $vin['id'])['estado_conectividade'], array_column($t['etapas'], 'resultado', 'etapa')['download']]);
Db::exec('UPDATE tab_zte_olt_repositorio SET estado_conectividade = ?, estado_detalhe = ? WHERE id = ?',
    [$estadoVin['estado_conectividade'], $estadoVin['estado_detalhe'], $vin['id']]);

$lj = array_values(array_filter(JobServico::listar(['campanha_id' => $c['id']], 1)['linhas'], fn($x) => (int) $x['id'] === (int) $j['id']))[0];
T::igual('Fila: cada job mostra a versao de origem e o alvo DAQUELA tentativa', ['V9.0.11P1N52', 'V9.0.11P3N10'], [$lj['sw_inicial'], $lj['sw_alvo']]);

T::igual('ONU com modelo mas sem HW lido: motivo proprio (nao "HW incompativel")', 'hw_desconhecido',
    RegraServico::motivoFora(['modelo' => 'F670LV9.0', 'hw_aceitos' => ['V9.0'], 'versoes_origem' => []],
        ['modelo' => 'F670LV9.0', 'hw_versao' => null, 'sw_versao' => 'V9.0.11P1N52'], 'V9.0.11P3N10'));

$aud = AjaxAuditoria::listar(['acao_filtro' => 'onu_atualizar_avulso']);
T::igual('auditoria: filtro de acao chega como "acao_filtro" (nao colide com a operacao do roteador)', ['onu_atualizar_avulso'],
    array_values(array_unique(array_column($aud['linhas'], 'acao'))));

zte_parar($procA);
Worker::$relogio = null;
