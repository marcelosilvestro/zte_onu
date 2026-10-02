<?php
/**
 * zte_onu :: schema pela linha de comando.
 *
 *   php cli/schema.php aplicar [--conf=ARQ] [--json]   cria/atualiza as tabelas (idempotente)
 *   php cli/schema.php estado  [--conf=ARQ] [--json]   so le: o que existe e o que falta
 *
 * Saida: 0 ok, 1 falha, 3 sem configuracao de banco.
 */
require_once __DIR__ . '/bootstrap.php';

$args = zte_cli_args($argv);
$comando = $args['_'][0] ?? 'estado';
zte_cli_conectar($args);
$schema = new Schema();

try {
    if ($comando === 'aplicar') {
        $r = $schema->aplicar(zte_usuario_cli(), zte_versao());
        Auditoria::registrar('schema_aplicar', 'schema', null, null, $r);
        $saida = $r;
    } elseif ($comando === 'estado') {
        $saida = $schema->estado();
    } else {
        fwrite(STDERR, "Comando desconhecido: $comando (use aplicar ou estado)\n");
        exit(1);
    }
} catch (Throwable $e) {
    if (isset($args['json'])) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
    } else {
        fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    }
    exit(1);
}

if (isset($args['json'])) {
    echo json_encode(['ok' => true] + $saida, JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

if ($comando === 'aplicar') {
    printf("Schema aplicado: %d comandos em %d ms, %d tabelas.\n", $saida['comandos'], $saida['ms'], $saida['tabelas']);
} else {
    echo $saida['instalado'] ? "Todas as tabelas presentes.\n" : 'Faltando: ' . implode(', ', $saida['faltando']) . "\n";
    echo $saida['desatualizado'] ? "Schema desatualizado em relacao ao codigo: rode 'aplicar'.\n" : "Schema em dia com o codigo.\n";
}
exit(0);
