<?php
/**
 * Suite 06 :: configuracao geral e a trava do modo seguro.
 */
T::suite('Config');

Config::limparCache();
T::igual('modo seguro nasce LIGADO', true, Config::ligado('modo_seguro'));
T::igual('padrao vem do codigo, sem seed', 2, Config::int('max_por_pon'));
T::igual('janela padrao', '02:00', Config::get('janela_inicio'));

[$a, $d] = Config::set('max_por_pon', '4', 'teste');
T::igual('set devolve antes e depois', ['2', '4'], [$a, $d]);
T::igual('valor novo vale', 4, Config::int('max_por_pon'));
Config::limparCache();
T::igual('valor novo persiste', 4, Config::int('max_por_pon'));

T::recusa('inteiro fora da faixa', fn() => Config::set('max_por_pon', '0', 'teste'), 'ZTE-VAL-007');
T::recusa('hora invalida', fn() => Config::set('janela_inicio', '25:00', 'teste'), 'ZTE-VAL-006');
T::recusa('opcao de enum invalida', fn() => Config::set('integridade_politica', 'nenhuma', 'teste'), 'ZTE-SYS-002');
T::recusa('chave desconhecida', fn() => Config::set('rm_rf', '1', 'teste'), 'ZTE-CFG-001');
T::recusa('chave interna nao se altera pela interface', fn() => Config::set('cofre_digital', 'x', 'teste'), 'ZTE-CFG-002');
T::certo('tela nao mostra chaves internas',
    !in_array('cofre_digital', array_column(Config::paraTela(), 'chave'), true));

T::suite('Config :: salvar pela operacao AJAX');

Permissao::configurar('teste');
T::recusa('desligar o modo seguro sem a confirmacao digitada e recusado',
    fn() => AjaxConfig::salvar(['valores' => ['modo_seguro' => '0']]), 'ZTE-SYS-002');
Config::limparCache();
T::igual('modo seguro continua ligado', true, Config::ligado('modo_seguro'));

T::recusa('lote com um valor invalido nao grava nenhum (tudo ou nada)',
    fn() => AjaxConfig::salvar(['valores' => ['max_concorrentes' => '8', 'max_falhas' => '-1']]), 'ZTE-VAL-007');
Config::limparCache();
T::igual('max_concorrentes nao foi gravado', 4, Config::int('max_concorrentes'));

$r = AjaxConfig::salvar(['valores' => ['modo_seguro' => '0', 'retentativas' => '2'], 'confirmacao' => 'DESLIGAR']);
T::igual('com a confirmacao, desliga', false, Config::ligado('modo_seguro'));
T::igual('informa o que mudou', ['modo_seguro', 'retentativas'], $r['alteradas']);
$aud = Db::um("SELECT * FROM tab_zte_auditoria WHERE acao = 'config_alterar' ORDER BY id DESC LIMIT 1");
T::certo('a mudanca ficou na auditoria com antes e depois',
    $aud !== null && str_contains((string) $aud['antes'], '"modo_seguro":"1"') && str_contains((string) $aud['depois'], '"modo_seguro":"0"'));
Config::set('modo_seguro', '1', 'teste');
