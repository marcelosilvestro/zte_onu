<?php
/**
 * Suite 04 :: nenhuma senha em log nem em auditoria.
 */
T::suite('Log :: mascaramento');

// Senha decifrada pelo cofre passa a ser mascarada em qualquer texto dali em diante.
$senha = Cofre::ler('repo', 1);
T::igual('cofre devolve a senha', 'SenhaFtp#1', $senha);
T::igual('mascara a senha no meio de uma saida de CLI',
    'login ftp usuario olt senha *** ok', Log::mascararTexto('login ftp usuario olt senha SenhaFtp#1 ok'));

$m = Log::mascarar(['host' => '10.0.0.1', 'senha' => 'qualquer', 'password' => 'x1', 'enable_password' => 'y1',
                    'token' => 'abc', 'passivo' => 1, 'saida' => 'conectado com SenhaFtp#1']);
T::igual('chave "senha" vira ***', '***', $m['senha']);
T::igual('chave "password" vira ***', '***', $m['password']);
T::igual('chave "enable_password" vira ***', '***', $m['enable_password']);
T::igual('chave "token" vira ***', '***', $m['token']);
T::igual('"passivo" (FTP) NAO e confundido com senha', 1, $m['passivo']);
T::igual('valor conhecido some de texto livre', 'conectado com ***', $m['saida']);
T::igual('host continua legivel', '10.0.0.1', $m['host']);

Log::info('teste.login_olt', ['comando' => 'ftp 10.0.0.9 user olt pass SenhaFtp#1', 'senha' => 'SenhaOlt#2']);
Log::excecao('teste.excecao', new RuntimeException('falha ao autenticar com SenhaFtp#1'));
$conteudo = (string) @file_get_contents(Log::arquivoDoDia());
T::certo('o log foi escrito', $conteudo !== '');
T::certo('varredura: nenhuma senha conhecida no arquivo de log',
    !str_contains($conteudo, 'SenhaFtp#1') && !str_contains($conteudo, 'SenhaOlt#2'));

T::suite('Auditoria');

Auditoria::registrar('repositorio_alterar', 'repositorio', 1,
    ['host' => '10.0.0.1', 'senha' => 'SenhaFtp#1'], ['host' => '10.0.0.2', 'senha' => 'NovaSenha#9', 'obs' => 'troquei de SenhaFtp#1']);
$a = Db::um('SELECT * FROM tab_zte_auditoria ORDER BY id DESC LIMIT 1');
T::igual('acao gravada', 'repositorio_alterar', $a['acao']);
T::igual('usuario e IP gravados', ['teste', '127.0.0.1'], [$a['usuario'], $a['ip']]);
T::certo('request_id gravado', str_starts_with((string) $a['request_id'], 'REQ-'));
T::certo('nenhuma senha no antes/depois',
    !str_contains($a['antes'] . $a['depois'], 'SenhaFtp#1') && !str_contains($a['antes'] . $a['depois'], 'NovaSenha#9'));
T::certo('o resto do registro continua legivel', str_contains((string) $a['depois'], '10.0.0.2'));

$lista = Auditoria::listar(['acao' => 'repositorio_alterar'], 1);
T::igual('listagem filtra por acao', 1, $lista['total']);
