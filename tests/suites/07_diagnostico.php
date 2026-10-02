<?php
/**
 * Suite 07 :: diagnostico por componente.
 */
T::suite('Diagnostico');

$comp = Diagnostico::componentes();
$ids = array_column($comp, 'componente');
T::igual('os 11 componentes do plano aparecem',
    ['configuracao_local', 'banco', 'cofre', 'permissoes', 'worker', 'addon_olt', 'addon_ftp', 'olt_ftp', 'firmwares', 'driver', 'campanhas'],
    $ids);

$porId = array_column($comp, null, 'componente');
T::igual('banco instalado e em dia', 'ok', $porId['banco']['resultado']);
T::igual('cofre ok com a chave de teste', 'ok', $porId['cofre']['resultado']);
T::igual('sem admin: permissoes em aviso', 'aviso', $porId['permissoes']['resultado']);
T::igual('sem cron instalado: worker em aviso (nunca "ok" presumido)', is_file('/etc/cron.d/zte_onu') ? 'nao_testavel' : 'aviso', $porId['worker']['resultado']);
T::igual('sem firmware cadastrado: aviso', 'aviso', $porId['firmwares']['resultado']);
T::igual('sem repositorio cadastrado: aviso', 'aviso', $porId['addon_ftp']['resultado']);
T::igual('sem OLT cadastrada: aviso', 'aviso', $porId['addon_olt']['resultado']);

// Senha cifrada com outra chave vira ERRO de cofre, com a lista do que recadastrar.
Db::exec("UPDATE tab_zte_credencial SET digital_chave = 'ffffffffffffffff' WHERE tipo = 'repo' AND dono_id = 1");
$porId = array_column(Diagnostico::componentes(), null, 'componente');
T::igual('credencial de outra chave: cofre em erro', 'erro', $porId['cofre']['resultado']);
T::certo('o detalhe diz qual senha recadastrar',
    str_contains(json_encode($porId['cofre'], JSON_UNESCAPED_UNICODE), 'Senha do addon no FTP #1'));

T::igual('pior(): erro vence aviso', 'erro', Diagnostico::pior([['resultado' => 'aviso'], ['resultado' => 'erro'], ['resultado' => 'ok']]));
T::igual('pior(): nao testavel nao vira erro', 'nao_testavel', Diagnostico::pior([['resultado' => 'ok'], ['resultado' => 'nao_testavel']]));

$pacote = Diagnostico::pacoteSuporte();
$json = json_encode($pacote, JSON_UNESCAPED_UNICODE);
T::certo('pacote de suporte nao carrega senha nenhuma',
    !str_contains($json, 'SenhaFtp#1') && !str_contains($json, 'SenhaOlt#2') && !str_contains($json, 'NovaSenha#9'));
T::certo('pacote de suporte traz versao e componentes', isset($pacote['versao'], $pacote['componentes']));
