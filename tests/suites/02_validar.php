<?php
/**
 * Suite 02 :: validacao de entrada — tudo que chega a banco, FTP ou CLI da OLT.
 */
T::suite('Validar :: endereco e porta');

T::igual('IPv4 valido', '10.200.255.1', Validar::host(' 10.200.255.1 '));
T::igual('IPv6 valido', '2001:db8::1', Validar::host('2001:DB8::1'));
T::igual('hostname valido (normaliza caixa)', 'ftp.provedor.com.br', Validar::host('FTP.Provedor.com.br'));
T::recusa('IPv4 fora de faixa', fn() => Validar::host('300.1.1.1'), 'ZTE-VAL-001');
T::recusa('host com espaco', fn() => Validar::host('10.0.0.1 ; reboot'), 'ZTE-VAL-001');
T::recusa('host com barra', fn() => Validar::host('ftp/../x'), 'ZTE-VAL-001');
T::recusa('host vazio', fn() => Validar::host(''), 'ZTE-VAL-001');
T::igual('porta 21', 21, Validar::porta('21'));
T::recusa('porta 0', fn() => Validar::porta('0'), 'ZTE-VAL-002');
T::recusa('porta 70000', fn() => Validar::porta('70000'), 'ZTE-VAL-002');
T::recusa('porta com texto', fn() => Validar::porta('21a'), 'ZTE-VAL-002');

T::suite('Validar :: arquivos e caminhos');

T::igual('nome de firmware comum', 'F601_V6.0.10P3N12.bin', Validar::nomeArquivo('F601_V6.0.10P3N12.bin'));
T::recusa('nome com ..', fn() => Validar::nomeArquivo('a..b'), 'ZTE-VAL-003');
T::recusa('nome com espaco', fn() => Validar::nomeArquivo('meu firmware.bin'), 'ZTE-VAL-003');
T::recusa('nome com barra', fn() => Validar::nomeArquivo('x/y.bin'), 'ZTE-VAL-003');
T::recusa('nome comecando com ponto', fn() => Validar::nomeArquivo('.htaccess'), 'ZTE-VAL-003');

T::igual('caminho relativo valido', 'zte/f601', Validar::caminhoRelativo('zte/f601/'));
T::igual('caminho vazio = raiz', '', Validar::caminhoRelativo(''));
T::recusa('caminho com ..', fn() => Validar::caminhoRelativo('../../etc'), 'ZTE-VAL-004');
T::recusa('caminho absoluto', fn() => Validar::caminhoRelativo('/etc/passwd'), 'ZTE-VAL-004');
T::recusa('caminho com contrabarra', fn() => Validar::caminhoRelativo('a\\b'), 'ZTE-VAL-004');
T::recusa('caminho com segmento vazio', fn() => Validar::caminhoRelativo('a//b'), 'ZTE-VAL-004');
T::recusa('caminho fundo demais', fn() => Validar::caminhoRelativo('a/b/c/d/e/f/g/h/i'), 'ZTE-VAL-004');

T::igual('raiz absoluta', '/firmwares/zte', Validar::raiz('/firmwares/zte/'));
T::igual('raiz "/"', '/', Validar::raiz('/'));
T::recusa('raiz relativa', fn() => Validar::raiz('firmwares'), 'ZTE-VAL-004');
T::igual('dentro da raiz', '/firmwares/zte/f601', Validar::dentroDaRaiz('/firmwares/zte', 'f601'));
T::igual('relativo vazio fica na raiz', '/firmwares', Validar::dentroDaRaiz('/firmwares', ''));
T::recusa('escapar da raiz', fn() => Validar::dentroDaRaiz('/firmwares', '../etc'), 'ZTE-VAL-004');

T::suite('Validar :: credenciais e parametros que podem chegar a CLI');

T::igual('usuario remoto comum', 'olt_ftp', Validar::usuarioRemoto('olt_ftp'));
T::recusa('usuario com ponto e virgula', fn() => Validar::usuarioRemoto('a;reboot'), 'ZTE-VAL-009');
T::recusa('usuario com espaco', fn() => Validar::usuarioRemoto('a b'), 'ZTE-VAL-009');
T::igual('senha com simbolos comuns', 'S3nh@#Forte!', Validar::senhaRemota('S3nh@#Forte!'));
T::recusa('senha com espaco', fn() => Validar::senhaRemota('a b'), 'ZTE-VAL-009');
T::recusa('senha com aspas', fn() => Validar::senhaRemota('a"b'), 'ZTE-VAL-009');
T::recusa('senha com ponto e virgula', fn() => Validar::senhaRemota('x;reboot'), 'ZTE-VAL-009');
T::recusa('senha com quebra de linha', fn() => Validar::senhaRemota("x\nreboot"), 'ZTE-VAL-009');
T::recusa('senha vazia', fn() => Validar::senhaRemota(''), 'ZTE-VAL-009');

T::igual('login MK-AUTH', 'marcelo.s', Validar::login('marcelo.s'));
T::recusa('login com aspas', fn() => Validar::login("x' OR 1=1"), 'ZTE-VAL-005');
T::igual('hora valida', '02:30', Validar::hora('02:30'));
T::recusa('hora 24:00', fn() => Validar::hora('24:00'), 'ZTE-VAL-006');
T::igual('inteiro na faixa', 5, Validar::inteiro('5', 1, 10));
T::recusa('inteiro fora da faixa', fn() => Validar::inteiro('11', 1, 10), 'ZTE-VAL-007');
T::igual('texto tira caractere de controle', 'ab', Validar::texto("a\x07b", 10));
T::recusa('nome obrigatorio', fn() => Validar::nome('   '), 'ZTE-VAL-008');
