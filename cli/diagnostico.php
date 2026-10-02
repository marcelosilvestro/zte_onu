<?php
/**
 * zte_onu :: diagnostico pela linha de comando (o instalador chama no fim).
 *
 *   php cli/diagnostico.php [--conf=ARQ] [--json]
 *
 * Saida: 0 sem erro, 2 com algum componente em erro, 3 sem configuracao de banco.
 */
require_once __DIR__ . '/bootstrap.php';

$args = zte_cli_args($argv);
zte_cli_conectar($args);

$componentes = Diagnostico::componentes();
if (isset($args['json'])) {
    echo json_encode($componentes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} else {
    $cores = ['ok' => '32', 'aviso' => '33', 'erro' => '31', 'nao_testavel' => '90'];
    $tty = function_exists('posix_isatty') && posix_isatty(STDOUT);
    foreach ($componentes as $c) {
        $r = $c['resultado'];
        $rot = str_pad(strtoupper($r === 'nao_testavel' ? 'n/t' : $r), 5);
        echo ($tty ? "\033[{$cores[$r]}m$rot\033[0m" : $rot), ' ', $c['titulo'], "\n";
        foreach ($c['itens'] as $i) {
            if ($i['resultado'] !== 'ok' && $i['resultado'] !== 'nao_testavel') {
                echo '        - ', $i['titulo'], ': ', $i['detalhe'], "\n";
                if ($i['acao'] !== '') {
                    echo '          => ', $i['acao'], "\n";
                }
            }
        }
    }
}
exit(Diagnostico::pior($componentes) === 'erro' ? 2 : 0);
