<?php
/**
 * zte_onu :: bootstrap de linha de comando.
 *
 * O config.php serve a web (sessao do painel, mysqli do topo.php, addons.class.php, que so roda
 * dentro do painel). A CLI tem o seu proprio caminho: mesmas classes, nada de HTTP.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este arquivo so roda por linha de comando.\n");
}

require_once __DIR__ . '/../lib/Core/carregar.php';

/** @return array<string,string|bool> argumentos --chave=valor e --flag */
function zte_cli_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z_-]+)=(.*)$/', $a, $m)) {
            $args[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-z_-]+)$/', $a, $m)) {
            $args[$m[1]] = true;
        } else {
            $args['_'][] = $a;
        }
    }
    return $args;
}

/** Conecta ao banco pela cadeia de Credenciais (ou --conf=ARQ). Sai com 3 se nao houver. */
function zte_cli_conectar(array $args): void
{
    $cfg = isset($args['conf']) && is_string($args['conf'])
        ? Credenciais::doArquivo($args['conf'])
        : Credenciais::descobrir();
    if ($cfg === null) {
        fwrite(STDERR, Credenciais::comoResolver() . "\n");
        exit(3);
    }
    Db::conectar($cfg);
    $usuario = zte_usuario_cli();
    Log::configurar(ZTE_DIR_LOGS, $usuario);
    Auditoria::configurar($usuario, null);
}

function zte_usuario_cli(): string
{
    $login = getenv('SUDO_USER') ?: (getenv('USER') ?: 'cli');
    return substr('cli:' . $login, 0, 60);
}
