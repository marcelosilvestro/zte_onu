<?php
/**
 * Suite 01 :: schema idempotente e diario de aplicacao.
 */
T::suite('Schema');

$schema = new Schema();
$antes = $schema->estado();
T::igual('banco vazio: nada instalado', false, $antes['instalado']);
T::igual('banco vazio: todas as tabelas faltando', count(Schema::TABELAS), count($antes['faltando']));

$r1 = $schema->aplicar('teste', '0.1.0');
T::igual('primeira aplicacao cria todas as tabelas', count(Schema::TABELAS), $r1['tabelas']);
T::igual('lista de tabelas do Schema tem 23 itens', 23, count(Schema::TABELAS));

$r2 = $schema->aplicar('teste', '0.1.0');
T::igual('segunda aplicacao (idempotente) nao quebra e nao duplica', count(Schema::TABELAS), $r2['tabelas']);

$depois = $schema->estado();
T::igual('estado: instalado', true, $depois['instalado']);
T::igual('estado: em dia com o codigo', false, $depois['desatualizado']);
T::igual('diario registrou a versao', '0.1.0', $depois['ultima_aplicacao']['versao']);

$cmds = Schema::comandos("-- comentario;\nCREATE TABLE a (x INT);\n\n-- outro\nINSERT INTO a VALUES (1);\n");
T::igual('separador ignora comentario e corta por ; no fim da linha', 2, count($cmds));

// Toda tabela do addon em utf8mb4 e InnoDB (FK e transacao dependem disso).
$ruins = Db::todos("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME LIKE 'tab\\_zte\\_%' AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION NOT LIKE 'utf8mb4%')");
T::igual('todas as tabelas InnoDB/utf8mb4', [], $ruins);

T::suite('Schema :: travas de integridade');

Db::exec("INSERT INTO tab_zte_olt (nome, host, usuario, criado_em) VALUES ('OLT T', '10.0.0.1', 'u', NOW())");
$olt = Db::ultimoId();
Db::exec("INSERT INTO tab_zte_onu (olt_id, slot, porta, onu_num, atualizado_em) VALUES (?, 1, 1, 1, NOW())", [$olt]);
T::recusa('mesma posicao fisica nao entra duas vezes', function () use ($olt) {
    Db::exec("INSERT INTO tab_zte_onu (olt_id, slot, porta, onu_num, atualizado_em) VALUES (?, 1, 1, 1, NOW())", [$olt]);
});
T::recusa('OLT com ONU no inventario nao pode ser apagada (FK)', function () use ($olt) {
    Db::exec('DELETE FROM tab_zte_olt WHERE id = ?', [$olt]);
});
Db::exec('DELETE FROM tab_zte_onu');
Db::exec('DELETE FROM tab_zte_olt');
