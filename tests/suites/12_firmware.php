<?php
/**
 * Suite 12 :: biblioteca de firmwares — envio, verificacao, estados, compatibilidade, adocao,
 * exclusao. Contra o FTP falso (suite 11 deixa as funcoes e a pasta prontas).
 */
T::suite('Firmware :: preparacao');

Config::limparCache();
Config::set('integridade_politica', 'completa', 'teste');
@mkdir($ZTE_FTP_RAIZ . '/firmwares/zte', 0700, true);
[$porta, $procFw] = zte_ftp_falso('normal');
$repoFw = RepoServico::salvar(['nome' => 'FTP FW', 'protocolo' => 'ftp', 'host' => '127.0.0.1', 'porta' => $porta, 'usuario' => 'ftpuser',
    'senha' => 'Ftp#Teste1', 'raiz' => '/firmwares', 'timeout_conexao_s' => 3, 'timeout_transferencia_s' => 10,
    'perm_listar' => 1, 'perm_enviar' => 1, 'perm_renomear' => 1, 'perm_excluir' => 1], 'teste');
$rid = $repoFw['id'];

/** Arquivo local de firmware de teste (o servico apaga ao final, como faz com o upload). */
function zte_fw_local(string $conteudo): string
{
    $f = tempnam(sys_get_temp_dir(), 'zfw');
    file_put_contents($f, $conteudo);
    return $f;
}

$meta = ['fabricante' => 'ZTE', 'modelo_familia' => 'F670L', 'versao_firmware' => 'V9.0.10P1N2', 'pasta' => 'zte',
         'nome_remoto' => 'F670L_V9.0.10P1N2.bin', 'compat' => [['modelo' => 'F670LV9.0', 'hw_versao' => 'V9.0']]];

$loc = zte_fw_local('x');
T::recusa('repositorio ainda nao testado: envio recusado', fn() => FirmwareServico::enviar($rid, $loc, 'a.bin', $meta, 'teste'), 'ZTE-FW-006');
T::certo('o arquivo local e apagado mesmo na recusa', !is_file($loc));
RepoServico::testar($rid, 'teste');

T::suite('Firmware :: validacoes do envio');

$loc = zte_fw_local('x');
T::recusa('extensao nao aceita', fn() => FirmwareServico::enviar($rid, $loc, 'a.exe', ['nome_remoto' => 'a.exe'] + $meta, 'teste'), 'ZTE-FW-002');
$loc = zte_fw_local('');
T::recusa('arquivo vazio', fn() => FirmwareServico::enviar($rid, $loc, 'a.bin', $meta, 'teste'), 'ZTE-FW-003');
$loc = zte_fw_local('x');
T::recusa('nome no FTP com barra', fn() => FirmwareServico::enviar($rid, $loc, 'a.bin', ['nome_remoto' => '../a.bin'] + $meta, 'teste'), 'ZTE-VAL-003');
$loc = zte_fw_local('x');
T::recusa('pasta fora da raiz', fn() => FirmwareServico::enviar($rid, $loc, 'a.bin', ['pasta' => '../etc'] + $meta, 'teste'), 'ZTE-VAL-004');
$loc = zte_fw_local('x');
T::recusa('compatibilidade com caractere invalido', fn() => FirmwareServico::enviar($rid, $loc, 'a.bin',
    ['compat' => [['modelo' => 'F670L;reboot', 'hw_versao' => 'V9']]] + $meta, 'teste'), 'ZTE-FW-018');

T::suite('Firmware :: envio completo');

$conteudo = random_bytes(20000) . 'FIRMWARE-F670L';
$loc = zte_fw_local($conteudo);
$fw = FirmwareServico::enviar($rid, $loc, 'F670L original.bin', $meta, 'teste');
clearstatcache();
T::igual('enviado, conferido e com compatibilidade: DISPONIVEL', 'disponivel', $fw['estado']);
T::igual('SHA-256 de origem = SHA-256 lido do FTP', [hash('sha256', $conteudo), hash('sha256', $conteudo)], [$fw['sha256_origem'], $fw['sha256_remoto']]);
T::igual('integridade "verificada"', 'verificada', $fw['integridade']);
T::igual('arquivo no FTP identico ao original', hash('sha256', $conteudo), hash_file('sha256', $ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.0.10P1N2.bin'));
T::igual('nenhum arquivo parcial ficou no FTP', [], glob($ZTE_FTP_RAIZ . '/firmwares/zte/zte_onu_parcial_*'));
T::certo('o temporario local foi apagado', !is_file($loc));
T::igual('verificacao de upload registrada', 'upload', $fw['verificacoes'][0]['tipo'] ?? null);
T::igual('caminho remoto absoluto', '/firmwares/zte/F670L_V9.0.10P1N2.bin', $fw['caminho_remoto']);

$loc = zte_fw_local('outro');
T::recusa('mesmo caminho ja cadastrado', fn() => FirmwareServico::enviar($rid, $loc, 'x.bin', $meta, 'teste'), 'ZTE-FW-005');
file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/ja_existe.bin', 'conteudo de outra pessoa');
$loc = zte_fw_local('novo');
T::recusa('arquivo que ja existe no FTP NUNCA e sobrescrito', fn() => FirmwareServico::enviar($rid, $loc, 'x.bin', ['nome_remoto' => 'ja_existe.bin'] + $meta, 'teste'), 'ZTE-FW-004');
clearstatcache();
T::igual('o arquivo de terceiros ficou intacto', 'conteudo de outra pessoa', file_get_contents($ZTE_FTP_RAIZ . '/firmwares/zte/ja_existe.bin'));
T::igual('e nada foi cadastrado', 0, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_firmware WHERE nome_remoto = 'ja_existe.bin'"));

T::suite('Firmware :: sem compatibilidade nao fica disponivel');

$loc = zte_fw_local(random_bytes(3000));
$fw2 = FirmwareServico::enviar($rid, $loc, 'f601.bin', ['modelo_familia' => 'F601', 'versao_firmware' => 'V6.0', 'nome_remoto' => 'F601_V6.bin', 'compat' => []] + $meta, 'teste');
T::igual('integro, mas sem compatibilidade: nao disponivel', 'integridade_remota_verificada', $fw2['estado']);
T::recusa('disponibilizar sem compatibilidade e recusado', fn() => FirmwareServico::disponibilizar($fw2['id'], 'teste', false), 'ZTE-FW-011');
$fw2 = FirmwareServico::definirCompat($fw2['id'], [['modelo' => 'F601', 'hw_versao' => 'V6.0'], ['modelo' => 'f601', 'hw_versao' => 'v6.0']], 'teste');
T::igual('compatibilidade cadastrada (duplicado ignorado)', 1, count($fw2['compat']));
T::igual('com compatibilidade, promovido a disponivel', 'disponivel', $fw2['estado']);
$fw2 = FirmwareServico::definirCompat($fw2['id'], [], 'teste');
T::igual('tirar toda a compatibilidade tira do ar', 'integridade_remota_verificada', $fw2['estado']);
FirmwareServico::definirCompat($fw2['id'], [['modelo' => 'F601', 'hw_versao' => 'V6.0']], 'teste');

T::suite('Firmware :: arquivo adulterado ou sumido no FTP');

$arq = $ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.0.10P1N2.bin';
$adulterado = $conteudo;
$adulterado[100] = chr(ord($adulterado[100]) ^ 0xFF);
file_put_contents($arq, $adulterado);
$v = FirmwareServico::verificar($fw['id'], 'reverificacao', 'teste');
T::igual('mesmo tamanho, 1 byte diferente: INVALIDO', ['erro', 'invalido'], [$v['resultado'], $v['estado']]);
T::recusa('firmware invalido nao volta a disponivel', fn() => FirmwareServico::disponibilizar($fw['id'], 'teste', false), 'ZTE-FW-012');
file_put_contents($arq, $conteudo);
$v = FirmwareServico::verificar($fw['id'], 'reverificacao', 'teste');
T::igual('arquivo restaurado: integro de novo', 'integridade_remota_verificada', $v['estado']);
FirmwareServico::disponibilizar($fw['id'], 'teste', false);

rename($arq, $arq . '.sumiu');
$v = FirmwareServico::verificar($fw['id'], 'reverificacao', 'teste');
T::igual('arquivo sumiu: AUSENTE_NO_FTP', 'ausente_no_ftp', $v['estado']);
rename($arq . '.sumiu', $arq);
FirmwareServico::verificar($fw['id'], 'reverificacao', 'teste');
FirmwareServico::disponibilizar($fw['id'], 'teste', false);

// Sincronizacao so marca estado.
rename($arq, $arq . '.sumiu');
RepoServico::sincronizar($rid, 'teste');
T::igual('sincronizacao marca o firmware ausente (sem apagar o cadastro)', 'ausente_no_ftp', FirmwareServico::linha($fw['id'])['estado']);
rename($arq . '.sumiu', $arq);
FirmwareServico::verificar($fw['id'], 'reverificacao', 'teste');
FirmwareServico::disponibilizar($fw['id'], 'teste', false);

T::suite('Firmware :: politica "tamanho"');

Config::set('integridade_politica', 'tamanho', 'teste');
$loc = zte_fw_local(random_bytes(4000));
$fw3 = FirmwareServico::enviar($rid, $loc, 'h.bin', ['modelo_familia' => 'H298', 'versao_firmware' => 'V1', 'nome_remoto' => 'H298.bin',
    'compat' => [['modelo' => 'H298A', 'hw_versao' => 'V1.0']]] + $meta, 'teste');
T::igual('politica tamanho: disponivel sem leitura de volta', ['disponivel', null], [$fw3['estado'], $fw3['sha256_remoto']]);
T::igual('a tela mostra que so o tamanho foi conferido', 'so_tamanho', $fw3['integridade']);
T::igual('verificacao registrada como aviso', 'aviso', $fw3['verificacoes'][0]['resultado']);
Config::set('integridade_politica', 'completa', 'teste');

T::suite('Firmware :: cadastrar arquivo que ja estava no FTP');

file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/orfao_F660.bin', $orfao = random_bytes(5000));
$ad = FirmwareServico::adotar($rid, 'zte/orfao_F660.bin', ['modelo_familia' => 'F660', 'versao_firmware' => 'V8',
    'compat' => [['modelo' => 'F660V8.0', 'hw_versao' => 'V8.0']]] + $meta, 'teste');
T::igual('sem hash do fabricante: fica ENVIADO, marcado sem origem', ['enviado', 1], [$ad['estado'], $ad['hash_sem_origem']]);
T::igual('SHA-256 calculado do FTP', hash('sha256', $orfao), $ad['sha256_remoto']);
T::recusa('disponibilizar sem confirmacao explicita e recusado', fn() => FirmwareServico::disponibilizar($ad['id'], 'teste', false), 'ZTE-FW-013');
$ad = FirmwareServico::disponibilizar($ad['id'], 'teste', true);
T::igual('com confirmacao explicita, disponivel', 'disponivel', $ad['estado']);
$aud = Db::um("SELECT depois FROM tab_zte_auditoria WHERE acao = 'firmware_disponibilizar' AND entidade_id = ? ORDER BY id DESC LIMIT 1", [$ad['id']]);
T::certo('a aceitacao sem origem fica na auditoria', str_contains((string) $aud['depois'], '"aceito_sem_origem":true'));

file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/fab_ok.bin', $c1 = random_bytes(3000));
$ok = FirmwareServico::adotar($rid, 'zte/fab_ok.bin', ['modelo_familia' => 'F670', 'versao_firmware' => 'V2', 'sha256_esperado' => hash('sha256', $c1),
    'compat' => [['modelo' => 'F670V2.0', 'hw_versao' => 'V2.0']]] + $meta, 'teste');
T::igual('com o SHA-256 do fabricante conferindo: disponivel', ['disponivel', 0], [$ok['estado'], $ok['hash_sem_origem']]);
file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/fab_ruim.bin', random_bytes(3000));
$ruim = FirmwareServico::adotar($rid, 'zte/fab_ruim.bin', ['modelo_familia' => 'F670', 'versao_firmware' => 'V3', 'sha256_esperado' => str_repeat('a', 64)] + $meta, 'teste');
T::igual('SHA-256 do fabricante diferente: INVALIDO', 'invalido', $ruim['estado']);
T::recusa('SHA-256 mal formado', fn() => FirmwareServico::adotar($rid, 'zte/fab_ok.bin', ['sha256_esperado' => 'xyz'] + $meta, 'teste'), 'ZTE-FW-015');
T::recusa('adotar arquivo inexistente', fn() => FirmwareServico::adotar($rid, 'zte/nao_existe.bin', $meta, 'teste'), 'ZTE-FW-009');

T::suite('Firmware :: falha no envio nao cadastra nada');

zte_parar($procFw);
[$porta, $procFw] = zte_ftp_falso('somente_leitura');
Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $rid]);
$antes = (int) Db::valor('SELECT COUNT(*) FROM tab_zte_firmware');
$loc = zte_fw_local(random_bytes(1000));
T::recusa('servidor recusa gravar: ZTE-FW-007', fn() => FirmwareServico::enviar($rid, $loc, 'r.bin', ['nome_remoto' => 'recusado.bin'] + $meta, 'teste'), 'ZTE-FW-007');
T::igual('nenhum cadastro sobrou', $antes, (int) Db::valor('SELECT COUNT(*) FROM tab_zte_firmware'));
T::certo('a falha ficou na auditoria', (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao = 'firmware_envio_falhou'") > 0);
T::certo('o temporario local foi apagado', !is_file($loc));
zte_parar($procFw);
[$porta, $procFw] = zte_ftp_falso('normal');
Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $rid]);

T::suite('Firmware :: campanha, regra, desativar e excluir');

$olt = (int) Db::valor('SELECT id FROM tab_zte_olt LIMIT 1');
Db::exec("INSERT INTO tab_zte_regra (nome, modelo, hw_aceitos, versoes_origem, firmware_id, criado_em) VALUES ('R1', 'F670LV9.0', '[]', '[]', ?, NOW())", [$fw['id']]);
$regra = Db::ultimoId();
Db::exec("INSERT INTO tab_zte_campanha (uuid, nome, olt_id, regra_id, firmware_id, estado, max_por_pon, max_concorrentes, max_falhas, max_falhas_pct,
          retentativas, janela_inicio, janela_fim, criado_em) VALUES (UUID(), 'C1', ?, ?, ?, 'executando', 1, 1, 1, 10, 0, '02:00', '05:00', NOW())",
    [$olt, $regra, $fw['id']]);
T::recusa('compatibilidade nao muda com campanha em andamento', fn() => FirmwareServico::definirCompat($fw['id'], [], 'teste'), 'ZTE-FW-019');
T::recusa('nao desativa com campanha em andamento', fn() => FirmwareServico::desativar($fw['id'], 'teste'), 'ZTE-FW-019');
Db::exec("UPDATE tab_zte_campanha SET estado = 'concluida'");
T::recusa('firmware com historico de regra/campanha nao e excluido', fn() => FirmwareServico::excluir($fw['id'], $fw['nome_remoto'], false, 'teste'), 'ZTE-FW-010');
$des = FirmwareServico::desativar($fw['id'], 'teste');
T::igual('desativado', 'desativado', $des['estado']);
$re = FirmwareServico::reativar($fw['id'], 'teste');
T::igual('reativar reverifica e volta a disponivel', 'disponivel', $re['estado']);

T::recusa('excluir exige o nome do arquivo', fn() => FirmwareServico::excluir($fw2['id'], 'errado', false, 'teste'), 'ZTE-FW-014');
FirmwareServico::excluir($fw2['id'], 'F601_V6.bin', false, 'teste');
clearstatcache();
T::certo('excluir so o cadastro mantem o arquivo no FTP', is_file($ZTE_FTP_RAIZ . '/firmwares/zte/F601_V6.bin'));
FirmwareServico::excluir($fw3['id'], 'H298.bin', true, 'teste');
clearstatcache();
T::certo('excluir com "apagar do FTP" remove o arquivo', !is_file($ZTE_FTP_RAIZ . '/firmwares/zte/H298.bin'));

T::suite('Firmware :: diagnostico e segredos');

$porId = array_column(Diagnostico::componentes(), null, 'componente');
T::igual('firmware invalido aparece como erro no diagnostico', 'erro', $porId['firmwares']['resultado']);
$todos = json_encode(Db::todos('SELECT detalhe FROM tab_zte_firmware_verificacao')) . (string) @file_get_contents(Log::arquivoDoDia())
       . json_encode(Db::todos('SELECT antes, depois FROM tab_zte_auditoria'));
T::certo('nenhuma senha de FTP em verificacao, log ou auditoria', !str_contains($todos, 'Ftp#Teste1'));

zte_parar($procFw);
Db::exec('DELETE FROM tab_zte_campanha');
Db::exec('DELETE FROM tab_zte_regra');
