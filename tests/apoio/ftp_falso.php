<?php
/**
 * Servidor FTP falso para os testes do repositorio (sem depender de vsftpd na VM).
 *
 *   php ftp_falso.php <modo> <pasta_raiz>
 *
 * Imprime a porta na primeira linha e atende conexoes de controle em sequencia, ate ser morto.
 * So modo passivo (PASV); EPSV e recusado para o cliente cair no PASV.
 * Usuario "ftpuser", senha "Ftp#Teste1".
 *
 * Modos:
 *   normal        tudo permitido, com MLSD
 *   sem_mlsd      MLSD recusado (cliente cai para LIST)
 *   windows       LIST no formato do IIS (DOS); sem MLSD
 *   somente_leitura  STOR/DELE/RNFR/MKD recusados (550)
 *   senha_errada  qualquer senha e recusada
 *   cai_retr      derruba a conexao de dados no meio do RETR
 */
$modo = $argv[1] ?? 'normal';
$raiz = rtrim($argv[2] ?? sys_get_temp_dir(), '/');

$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$n = stream_socket_get_name($srv, false);
echo substr($n, strrpos($n, ':') + 1), "\n";
fflush(STDOUT);

while (true) {
    $c = @stream_socket_accept($srv, 600);
    if (!$c) {
        continue;
    }
    sessao($c, $modo, $raiz);
    @fclose($c);
}

function r($c, string $l): void
{
    fwrite($c, $l . "\r\n");
}

/** Caminho virtual -> real, sempre dentro da raiz. */
function real(string $raiz, string $cwd, string $arg): ?string
{
    $v = $arg === '' ? $cwd : ($arg[0] === '/' ? $arg : rtrim($cwd, '/') . '/' . $arg);
    $partes = [];
    foreach (explode('/', $v) as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..') {
            array_pop($partes);
            continue;
        }
        $partes[] = $p;
    }
    return $raiz . '/' . implode('/', $partes);
}

function virtual(string $raiz, string $real): string
{
    $v = substr($real, strlen($raiz));
    return $v === '' ? '/' : rtrim($v, '/');
}

function sessao($c, string $modo, string $raiz): void
{
    r($c, '220 ZTE ONU teste FTP pronto');
    $logado = false;
    $usuario = null;
    $cwd = '/';
    $pasv = null;
    $rnfr = null;
    $escrita = $modo !== 'somente_leitura';

    while (($linha = fgets($c)) !== false) {
        // Processo de longa duracao: sem isto, is_file/filesize respondem do cache de stat e o
        // servidor "ve" arquivos que o teste ja apagou ou renomeou.
        clearstatcache();
        $linha = rtrim($linha, "\r\n");
        [$cmd, $arg] = array_pad(explode(' ', $linha, 2), 2, '');
        $cmd = strtoupper($cmd);

        if (!$logado && !in_array($cmd, ['USER', 'PASS', 'QUIT', 'AUTH', 'FEAT', 'SYST'], true)) {
            r($c, '530 Not logged in');
            continue;
        }
        switch ($cmd) {
            case 'USER':
                $usuario = $arg;
                r($c, '331 Password required');
                break;
            case 'PASS':
                if ($modo !== 'senha_errada' && $usuario === 'ftpuser' && $arg === 'Ftp#Teste1') {
                    $logado = true;
                    r($c, '230 Logged in');
                } else {
                    r($c, '530 Login incorrect');
                }
                break;
            case 'AUTH':
                r($c, '502 TLS not supported');
                break;
            case 'SYST':
                r($c, $modo === 'windows' ? '215 Windows_NT' : '215 UNIX Type: L8');
                break;
            case 'FEAT':
                r($c, '211-Features:');
                if (!in_array($modo, ['sem_mlsd', 'windows'], true)) {
                    r($c, ' MLSD');
                }
                r($c, ' SIZE');
                r($c, '211 End');
                break;
            case 'PWD':
                r($c, '257 "' . $cwd . '" is current directory');
                break;
            case 'CWD':
                $p = real($raiz, $cwd, $arg);
                if (is_dir($p)) {
                    $cwd = virtual($raiz, $p);
                    r($c, '250 OK');
                } else {
                    r($c, '550 No such directory');
                }
                break;
            case 'CDUP':
                $cwd = virtual($raiz, real($raiz, $cwd, '..'));
                r($c, '250 OK');
                break;
            case 'TYPE': case 'MODE': case 'STRU': case 'OPTS': case 'NOOP':
                r($c, '200 OK');
                break;
            case 'REST':
                r($c, '350 Restarting at ' . (int) $arg);
                break;
            case 'ALLO':
                r($c, '202 Not needed');
                break;
            case 'EPSV': case 'PORT': case 'EPRT':
                r($c, '500 Not supported');
                break;
            case 'PASV':
                $pasv = stream_socket_server('tcp://127.0.0.1:0');
                $nn = stream_socket_get_name($pasv, false);
                $porta = (int) substr($nn, strrpos($nn, ':') + 1);
                r($c, sprintf('227 Entering Passive Mode (127,0,0,1,%d,%d)', intdiv($porta, 256), $porta % 256));
                break;
            case 'SIZE':
                $p = real($raiz, $cwd, $arg);
                is_file($p) ? r($c, '213 ' . filesize($p)) : r($c, '550 No such file');
                break;
            case 'MDTM':
                $p = real($raiz, $cwd, $arg);
                is_file($p) ? r($c, '213 ' . gmdate('YmdHis', filemtime($p))) : r($c, '550 No such file');
                break;
            case 'LIST': case 'NLST': case 'MLSD':
                if ($cmd === 'MLSD' && in_array($modo, ['sem_mlsd', 'windows'], true)) {
                    r($c, '500 MLSD not understood');
                    break;
                }
                $alvo = preg_replace('/^-\S+\s*/', '', $arg);
                $p = real($raiz, $cwd, $alvo);
                if (!is_dir($p)) {
                    r($c, '550 No such directory');
                    break;
                }
                $linhas = [];
                foreach (scandir($p) as $nome) {
                    if ($nome === '.' || $nome === '..') {
                        continue;
                    }
                    $f = $p . '/' . $nome;
                    $d = is_dir($f);
                    if ($cmd === 'NLST') {
                        $linhas[] = $nome;
                    } elseif ($cmd === 'MLSD') {
                        $linhas[] = 'type=' . ($d ? 'dir' : 'file') . ';' . ($d ? '' : 'size=' . filesize($f) . ';') .
                                    'modify=' . gmdate('YmdHis', filemtime($f)) . '; ' . $nome;
                    } elseif ($modo === 'windows') {
                        $linhas[] = date('m-d-y  h:iA', filemtime($f)) . ($d ? '       <DIR>          ' : sprintf('%20d ', filesize($f))) . $nome;
                    } else {
                        $linhas[] = ($d ? 'drwxr-xr-x' : '-rw-r--r--') . ' 1 ftp ftp ' . sprintf('%12d', $d ? 4096 : filesize($f)) . ' ' .
                                    date('M d H:i', filemtime($f)) . ' ' . $nome;
                    }
                }
                dados($c, $pasv, function ($d) use ($linhas) {
                    fwrite($d, $linhas ? implode("\r\n", $linhas) . "\r\n" : '');
                });
                $pasv = null;
                break;
            case 'RETR':
                $p = real($raiz, $cwd, $arg);
                if (!is_file($p)) {
                    r($c, '550 No such file');
                    break;
                }
                if ($modo === 'cai_retr') {
                    r($c, '150 Opening data connection');
                    $d = @stream_socket_accept($pasv, 5);
                    if ($d) {
                        fwrite($d, substr((string) file_get_contents($p), 0, 4));
                        fclose($d);
                    }
                    fclose($c);
                    return;
                }
                dados($c, $pasv, function ($d) use ($p) {
                    fwrite($d, (string) file_get_contents($p));
                });
                $pasv = null;
                break;
            case 'STOR':
                if (!$escrita) {
                    r($c, '550 Permission denied');
                    break;
                }
                $p = real($raiz, $cwd, $arg);
                if (!is_dir(dirname($p))) {
                    r($c, '550 No such directory');
                    break;
                }
                dados($c, $pasv, function ($d) use ($p) {
                    $conteudo = '';
                    while (!feof($d)) {
                        $conteudo .= (string) fread($d, 65536);
                    }
                    file_put_contents($p, $conteudo);
                });
                $pasv = null;
                break;
            case 'DELE':
                $p = real($raiz, $cwd, $arg);
                if (!$escrita) {
                    r($c, '550 Permission denied');
                } elseif (!is_file($p)) {
                    r($c, '550 No such file');
                } else {
                    unlink($p);
                    r($c, '250 Deleted');
                }
                break;
            case 'MKD':
                $p = real($raiz, $cwd, $arg);
                if (!$escrita) {
                    r($c, '550 Permission denied');
                } elseif (file_exists($p)) {
                    r($c, '550 Exists');
                } else {
                    mkdir($p);
                    r($c, '257 "' . virtual($raiz, $p) . '" created');
                }
                break;
            case 'RNFR':
                $p = real($raiz, $cwd, $arg);
                if (!$escrita) {
                    r($c, '550 Permission denied');
                } elseif (!file_exists($p)) {
                    r($c, '550 No such file');
                } else {
                    $rnfr = $p;
                    r($c, '350 Ready for RNTO');
                }
                break;
            case 'RNTO':
                if ($rnfr === null) {
                    r($c, '503 RNFR first');
                } else {
                    rename($rnfr, real($raiz, $cwd, $arg));
                    $rnfr = null;
                    r($c, '250 Renamed');
                }
                break;
            case 'QUIT':
                r($c, '221 Bye');
                return;
            default:
                r($c, '502 Command not implemented');
        }
    }
}

function dados($c, $pasv, callable $fn): void
{
    if ($pasv === null) {
        r($c, '425 Use PASV first');
        return;
    }
    r($c, '150 Opening data connection');
    $d = @stream_socket_accept($pasv, 10);
    if (!$d) {
        r($c, '425 Cannot open data connection');
        return;
    }
    $fn($d);
    fclose($d);
    fclose($pasv);
    r($c, '226 Transfer complete');
}
