<?php
/**
 * zte_onu :: chave do cofre de credenciais.
 *
 *   php cli/cofre.php gerar  [--arquivo=/opt/mk-auth/conf/zte_onu.key]
 *       cria a chave se NAO existir (nunca sobrescreve: trocar a chave invalida todas as
 *       senhas cadastradas). Quem ajusta dono/permissao e o instalador.
 *   php cli/cofre.php estado [--conf=ARQ]
 *       mostra se a chave esta legivel e quantas senhas precisam ser recadastradas.
 *
 * Saida: 0 ok, 1 falha.
 */
require_once __DIR__ . '/bootstrap.php';

$args = zte_cli_args($argv);
$comando = $args['_'][0] ?? 'estado';
$arquivo = is_string($args['arquivo'] ?? null) ? $args['arquivo'] : Cofre::ARQUIVO_PADRAO;

if ($comando === 'gerar') {
    if (is_file($arquivo)) {
        Cofre::configurar($arquivo);
        echo Cofre::disponivel() ? "Chave ja existe e e valida: $arquivo\n" : "ATENCAO: $arquivo existe mas nao e uma chave valida.\n";
        exit(Cofre::disponivel() ? 0 : 1);
    }
    try {
        Cofre::gerarChave($arquivo);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo "Chave criada: $arquivo\n";
    exit(0);
}

if ($comando === 'estado') {
    zte_cli_conectar($args);
    Cofre::configurar($arquivo);
    $e = Cofre::estado();
    echo 'Chave: ', $e['chave'], ($e['digital'] ? ' (digital ' . $e['digital'] . ')' : ''), "\n";
    echo 'Senhas cadastradas: ', $e['total'], "\n";
    echo 'Precisam ser recadastradas: ', count($e['incompativeis']), "\n";
    exit($e['chave'] === 'ok' && !$e['incompativeis'] ? 0 : 1);
}

fwrite(STDERR, "Comando desconhecido: $comando (use gerar ou estado)\n");
exit(1);
