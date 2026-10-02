<?php
/**
 * Suite 03 :: cofre de credenciais.
 */
T::suite('Cofre');

$arqChave = Cofre::arquivo();
@unlink($arqChave);
Cofre::configurar($arqChave);

T::igual('sem chave: cofre indisponivel', false, Cofre::disponivel());
T::recusa('sem chave: guardar falha com COF-001', fn() => Cofre::guardar('olt', 1, 'segredo123', 't'), 'ZTE-COF-001');

Cofre::gerarChave($arqChave);
Cofre::configurar($arqChave);
T::igual('chave gerada: cofre disponivel', true, Cofre::disponivel());
T::igual('arquivo da chave nao e legivel por "outros"', 0, fileperms($arqChave) & 0007);
T::recusa('gerarChave nunca sobrescreve', fn() => Cofre::gerarChave($arqChave));

Cofre::guardar('olt', 1, 'SenhaOlt#1', 'teste');
Cofre::guardar('repo', 1, 'SenhaFtp#1', 'teste');
T::igual('le de volta a senha da OLT', 'SenhaOlt#1', Cofre::ler('olt', 1));
T::igual('le de volta a senha do FTP', 'SenhaFtp#1', Cofre::ler('repo', 1));
T::igual('senha inexistente devolve null', null, Cofre::ler('olt', 99));
T::igual('existe()', true, Cofre::existe('olt', 1));

$cru = (string) Db::valor("SELECT cifrado FROM tab_zte_credencial WHERE tipo = 'olt' AND dono_id = 1");
T::certo('o banco nao guarda a senha em claro', !str_contains($cru, 'SenhaOlt') && str_starts_with($cru, 'v1:'));
T::certo('o banco nao guarda a senha nem em base64', !str_contains($cru, base64_encode('SenhaOlt#1')));

Cofre::guardar('olt', 1, 'SenhaOlt#2', 'teste');
T::igual('regravar substitui (uma linha por dono)', 1, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_credencial WHERE tipo = 'olt' AND dono_id = 1"));
T::igual('regravar troca o valor', 'SenhaOlt#2', Cofre::ler('olt', 1));

// Copiar o valor cifrado de um dono para outro nao funciona: o AEAD amarra ao dono.
Db::exec("INSERT INTO tab_zte_credencial (tipo, dono_id, cifrado, digital_chave, alterado_em)
          SELECT 'olt', 2, cifrado, digital_chave, NOW() FROM tab_zte_credencial WHERE tipo = 'olt' AND dono_id = 1");
T::recusa('cifrado copiado para outra OLT nao decifra', fn() => Cofre::ler('olt', 2), 'ZTE-COF-003');

Db::exec("UPDATE tab_zte_credencial SET cifrado = CONCAT('v1:', TO_BASE64('lixo-lixo-lixo-lixo-lixo-lixo-lixo')) WHERE tipo = 'olt' AND dono_id = 2");
T::recusa('cifrado adulterado nao decifra', fn() => Cofre::ler('olt', 2), 'ZTE-COF-003');
Cofre::apagar('olt', 2);
T::igual('apagar remove', false, Cofre::existe('olt', 2));

// Troca de chave: tudo o que foi cifrado com a anterior aparece como "recadastrar".
$outra = dirname($arqChave) . '/cofre2.key';
@unlink($outra);
Cofre::gerarChave($outra);
Cofre::configurar($outra);
T::recusa('senha cifrada com outra chave: COF-002', fn() => Cofre::ler('olt', 1), 'ZTE-COF-002');
$e = Cofre::estado();
T::igual('estado aponta as 2 senhas a recadastrar', 2, count($e['incompativeis']));
Cofre::configurar($arqChave);
T::igual('de volta a chave original, tudo legivel', 0, count(Cofre::estado()['incompativeis']));

file_put_contents($outra, "nao-e-base64-valido\n");
Cofre::configurar($outra);
T::igual('chave corrompida: estado "invalida"', 'invalida', Cofre::estado()['chave']);
Cofre::configurar($arqChave);

T::recusa('tipo de credencial desconhecido e recusado', fn() => Cofre::guardar('qualquer', 1, 'x', 't'));
