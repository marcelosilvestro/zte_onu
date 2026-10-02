<?php
/**
 * Suite 10 :: cadastro e teste de OLT (servico), com OLT simulada e OLT falsa por telnet.
 */
T::suite('OLT :: cadastro');

Config::limparCache();
$sim = OltServico::salvar(['nome' => 'OLT Simulada', 'protocolo' => 'simulado', 'fabricante' => 'ZTE', 'modelo' => 'C320'], 'teste');
T::igual('OLT simulada criada sem senha', ['simulado', false], [$sim['protocolo'], $sim['tem_senha']]);
T::recusa('nome repetido e recusado', fn() => OltServico::salvar(['nome' => 'OLT Simulada', 'protocolo' => 'simulado'], 'teste'), 'ZTE-OLT-002');
T::recusa('OLT real sem senha e recusada', fn() => OltServico::salvar(
    ['nome' => 'OLT B', 'protocolo' => 'telnet', 'host' => '192.0.2.1', 'porta' => 23, 'usuario' => 'adm'], 'teste'), 'ZTE-OLT-003');
T::recusa('SSH ainda nao suportado', fn() => OltServico::salvar(
    ['nome' => 'OLT C', 'protocolo' => 'ssh', 'host' => '192.0.2.1', 'usuario' => 'adm', 'senha' => 'x123'], 'teste'), 'ZTE-OLT-010');
T::recusa('modelo sem driver e recusado', fn() => OltServico::salvar(
    ['nome' => 'OLT D', 'protocolo' => 'simulado', 'fabricante' => 'Huawei', 'modelo' => 'MA5800'], 'teste'), 'ZTE-OLT-012');
T::recusa('host com injecao e recusado', fn() => OltServico::salvar(
    ['nome' => 'OLT E', 'protocolo' => 'telnet', 'host' => '192.0.2.1;reboot', 'usuario' => 'adm', 'senha' => 'x123'], 'teste'), 'ZTE-VAL-001');

$real = OltServico::salvar(['nome' => 'OLT Telnet', 'protocolo' => 'telnet', 'host' => '127.0.0.1', 'porta' => 1,
    'usuario' => 'teste', 'senha' => 'Senha#Teste1', 'timeout_conexao_s' => 3, 'timeout_comando_s' => 5], 'teste');
T::igual('OLT real criada com senha no cofre', true, $real['tem_senha']);
T::certo('a senha nao esta na linha da OLT', !str_contains(json_encode(OltServico::linha($real['id'])), 'Senha#Teste1'));
T::certo('a senha nao esta no que vai para a tela', !str_contains(json_encode($real), 'Senha#Teste1'));
$aud = Db::um("SELECT * FROM tab_zte_auditoria WHERE acao = 'olt_criar' AND entidade_id = ?", [$real['id']]);
T::certo('criacao auditada, sem a senha', $aud !== null && !str_contains($aud['depois'], 'Senha#Teste1') && str_contains($aud['depois'], '"credencial_trocada":true'));

T::recusa('edicao com versao velha e recusada (trava otimista)', fn() => OltServico::salvar(
    ['id' => $real['id'], 'versao' => 99, 'nome' => 'OLT Telnet', 'protocolo' => 'telnet', 'host' => '127.0.0.1', 'porta' => 1, 'usuario' => 'teste'], 'teste'), 'ZTE-CONC-001');
$ed = OltServico::salvar(['id' => $real['id'], 'versao' => $real['versao'], 'nome' => 'OLT Telnet', 'protocolo' => 'telnet',
    'host' => '127.0.0.1', 'porta' => 1, 'usuario' => 'teste', 'observacao' => 'sem trocar senha'], 'teste');
T::igual('editar sem senha mantem a senha atual', 'Senha#Teste1', Cofre::ler('olt', $real['id']));
T::igual('versao avancou', $real['versao'] + 1, $ed['versao']);

T::suite('OLT :: teste de acesso');

$r = OltServico::testar($sim['id'], 'teste');
T::igual('simulada: teste ok', 'ok', $r['resultado']);
T::igual('simulada: compatibilidade validada (V2.1.0)', 'validada', $r['compatibilidade']);
T::igual('etapas conexao, identificacao, compatibilidade', ['conexao', 'identificacao', 'compatibilidade'], array_column($r['etapas'], 'etapa'));
$linhaSim = OltServico::linha($sim['id']);
T::igual('versao e nome do equipamento gravados', ['V2.1.0', 'OLT-c320_1'], [$linhaSim['versao_detectada'], $linhaSim['identificador_detectado']]);
T::igual('etapas no historico de testes', 3, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_teste_conectividade WHERE alvo_id = ? AND correlacao = ?", [$sim['id'], $r['correlacao']]));
T::igual('historico agrupado por execucao', 1, count(OltServico::historicoTestes($sim['id'])));

// Equipamento trocado no mesmo cadastro
Db::exec("UPDATE tab_zte_olt SET identificador_detectado = 'OLT-outra' WHERE id = ?", [$sim['id']]);
$r = OltServico::testar($sim['id'], 'teste');
T::igual('nome do equipamento mudou: resultado AVISO', 'aviso', $r['resultado']);
T::certo('a etapa de identidade aponta os dois nomes', str_contains(json_encode($r['etapas'], JSON_UNESCAPED_UNICODE), 'OLT-outra'));

// Versao nao testada -> somente leitura
$falso = new class implements Transporte {
    public function conectar(): void {}
    public function executar(string $l): string
    {
        return str_replace('V2.1.0', 'V2.2.0', Fixtures::responder($l));
    }
    public function nomeEquipamento(): string { return 'OLT-c320_1'; }
    public function fechar(): void {}
};
$r = OltServico::testar($sim['id'], 'teste', $falso);
T::igual('versao nao testada: somente leitura', ['aviso', 'somente_leitura'], [$r['resultado'], $r['compatibilidade']]);

// Telnet real contra a OLT falsa
[$porta, $proc] = zte_olt_falsa('normal');
Db::exec('UPDATE tab_zte_olt SET porta = ? WHERE id = ?', [$porta, $real['id']]);
$r = OltServico::testar($real['id'], 'teste');
T::igual('telnet: teste ok e validada', ['ok', 'validada'], [$r['resultado'], $r['compatibilidade']]);
zte_parar($proc);

// Senha errada: conta falha, bloqueia na 3a
Cofre::guardar('olt', $real['id'], 'SenhaErrada#9', 'teste');
for ($i = 1; $i <= 3; $i++) {
    [$porta, $proc] = zte_olt_falsa('senha_errada');
    Db::exec('UPDATE tab_zte_olt SET porta = ? WHERE id = ?', [$porta, $real['id']]);
    $r = OltServico::testar($real['id'], 'teste');
    zte_parar($proc);
}
$l = OltServico::linha($real['id']);
T::igual('3 falhas de login contadas', 3, (int) $l['falhas_auth']);
T::certo('OLT bloqueada apos 3 falhas', $l['bloqueado_ate'] !== null && strtotime($l['bloqueado_ate']) > time());
T::recusa('OLT bloqueada nao tenta de novo (nao trava a conta na OLT)', fn() => OltServico::testar($real['id'], 'teste'), 'ZTE-OLT-005');
$ed = OltServico::obter($real['id']);
OltServico::salvar(['id' => $real['id'], 'versao' => $ed['versao'], 'nome' => 'OLT Telnet', 'protocolo' => 'telnet',
    'host' => '127.0.0.1', 'porta' => $porta, 'usuario' => 'teste', 'senha' => 'Senha#Teste1'], 'teste');
$l = OltServico::linha($real['id']);
T::igual('salvar senha nova libera o bloqueio', [0, null], [(int) $l['falhas_auth'], $l['bloqueado_ate']]);
$todos = json_encode(Db::todos('SELECT detalhe FROM tab_zte_teste_conectividade')) . (string) @file_get_contents(Log::arquivoDoDia());
T::certo('nenhuma senha de OLT no historico de testes nem no log',
    !str_contains($todos, 'SenhaErrada#9') && !str_contains($todos, 'Senha#Teste1'));

// Exclusao mutua
Db::travar('zte_olt_' . $sim['id']);
$outra = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']}", $cfg['user'], $cfg['pass']);
$st = $outra->query("SELECT GET_LOCK('zte_olt_" . $sim['id'] . "', 0)");
T::igual('outra sessao nao pega a trava da mesma OLT', 0, (int) $st->fetchColumn());
Db::destravar('zte_olt_' . $sim['id']);

T::suite('OLT :: desativar e remover');

OltServico::definirAtivo($sim['id'], false, 'teste');
T::recusa('OLT desativada nao e testada', fn() => OltServico::testar($sim['id'], 'teste'), 'ZTE-OLT-011');
T::recusa('remover exige o nome digitado', fn() => OltServico::remover($sim['id'], 'errado', 'teste'), 'ZTE-OLT-017');
Db::exec("INSERT INTO tab_zte_onu (olt_id, slot, porta, onu_num, atualizado_em) VALUES (?, 1, 1, 1, NOW())", [$real['id']]);
T::recusa('OLT com inventario nao pode ser removida', fn() => OltServico::remover($real['id'], 'OLT Telnet', 'teste'), 'ZTE-OLT-004');
OltServico::remover($sim['id'], 'OLT Simulada', 'teste');
T::recusa('OLT removida some', fn() => OltServico::linha($sim['id']), 'ZTE-OLT-001');
T::igual('a remocao foi auditada', 1, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = 'olt_remover'"));

T::suite('Diagnostico :: OLT');
[$porta, $proc] = zte_olt_falsa('normal');
Db::exec('UPDATE tab_zte_olt SET porta = ? WHERE id = ?', [$porta, $real['id']]);
OltServico::testar($real['id'], 'teste');
zte_parar($proc);
$porId = array_column(Diagnostico::componentes(), null, 'componente');
T::igual('addon_olt reflete o ultimo teste da OLT ativa', 'ok', $porId['addon_olt']['resultado']);
T::igual('driver: OLT ativa validada', 'ok', $porId['driver']['resultado']);

Db::exec('DELETE FROM tab_zte_onu');
