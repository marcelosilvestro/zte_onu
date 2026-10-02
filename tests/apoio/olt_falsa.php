<?php
/**
 * OLT falsa por telnet, para testar o TransporteTelnet sem equipamento.
 *
 *   php olt_falsa.php <modo>
 *
 * Abre uma porta livre em 127.0.0.1, imprime o numero na primeira linha da saida e atende UMA
 * conexao. As respostas aos comandos sao as mesmas da OLT simulada (Fixtures::responder), ou
 * seja, as saidas reais gravadas.
 *
 * Modos:
 *   normal        login usuario "teste" / senha "Senha#Teste1", prompt OLT-c320_1#
 *   senha_errada  recusa qualquer senha com "Bad password!" e volta a pedir usuario
 *   enable        entra em ">" e exige "enable" com a senha "Enable#1"
 *   mudo          faz login e nunca responde aos comandos (timeout)
 *   cai           faz login e derruba a conexao no primeiro comando
 *   paginado      responde com "--More--" a cada 10 linhas (com backspaces, como a ZTE)
 *   silencio      aceita a conexao e nao manda nada (nem pede usuario)
 */
require_once __DIR__ . '/../../lib/Olt/Simulado/Fixtures.php';

$modo = $argv[1] ?? 'normal';
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) {
    fwrite(STDERR, "falha: $errstr\n");
    exit(1);
}
$nome = stream_socket_get_name($srv, false);
echo substr($nome, strrpos($nome, ':') + 1), "\n";
fflush(STDOUT);

$c = @stream_socket_accept($srv, 20);
if (!$c) {
    exit(0);
}

/** Le uma linha do cliente, descartando a negociacao telnet (IAC x y). */
function lerLinha($c): ?string
{
    $linha = '';
    while (true) {
        $b = fread($c, 1);
        if ($b === false || $b === '') {
            return null;
        }
        if (ord($b) === 255) {
            fread($c, 2);
            continue;
        }
        if ($b === "\n") {
            return rtrim($linha, "\r");
        }
        $linha .= $b;
    }
}

function env($c, string $t): void
{
    fwrite($c, str_replace("\n", "\r\n", $t));
}

if ($modo === 'silencio') {
    sleep(30);
    exit(0);
}

// IAC WILL ECHO + IAC WILL SGA, como uma OLT de verdade, e um banner com a palavra "fail"
// para provar que banner nao e confundido com recusa de senha.
fwrite($c, "\xff\xfb\x01\xff\xfb\x03");
env($c, "\n************************************************\nWelcome to ZXAN product C320 (failsafe banner)\n************************************************\n\nUsername:");

while (true) {
    $u = lerLinha($c);
    if ($u === null) {
        exit(0);
    }
    env($c, $u . "\nPassword:");
    $s = lerLinha($c);
    if ($s === null) {
        exit(0);
    }
    if ($modo === 'senha_errada' || $s !== 'Senha#Teste1') {
        env($c, "\n%Error 20209: Bad password!\nUsername:");
        continue;
    }
    break;
}

$prompt = Fixtures::NOME_OLT . ($modo === 'enable' ? '>' : '#');
env($c, "\n\n" . $prompt);

while (($cmd = lerLinha($c)) !== null) {
    env($c, $cmd . "\n");                         // eco
    if ($cmd === 'exit') {
        break;
    }
    if ($modo === 'enable' && $cmd === 'enable') {
        env($c, "Password:");
        $e = lerLinha($c);
        if ($e === 'Enable#1') {
            $prompt = Fixtures::NOME_OLT . '#';
            env($c, "\n" . $prompt);
        } else {
            env($c, "\n%Error 20210: Bad enable password\n" . $prompt);
        }
        continue;
    }
    if ($modo === 'mudo') {
        continue;
    }
    if ($modo === 'cai') {
        fclose($c);
        exit(0);
    }
    $resp = Fixtures::responder($cmd);
    if ($modo === 'paginado') {
        $linhas = explode("\n", $resp);
        foreach (array_chunk($linhas, 10) as $i => $bloco) {
            if ($i > 0) {
                env($c, ' --More--');
                lerLinha_espaco($c);
                env($c, str_repeat("\x08", 9) . str_repeat(' ', 9) . str_repeat("\x08", 9));
            }
            env($c, implode("\n", $bloco) . "\n");
        }
    } else {
        env($c, $resp . "\n");
    }
    env($c, $prompt);
}

/** Espera o espaco que o cliente manda para continuar a paginacao. */
function lerLinha_espaco($c): void
{
    while (($b = fread($c, 1)) !== false && $b !== '') {
        if ($b === ' ') {
            return;
        }
    }
}
