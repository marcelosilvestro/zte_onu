<?php
/**
 * Suite 09 :: transporte telnet contra uma OLT falsa (tests/apoio/olt_falsa.php) em outro processo.
 */
T::suite('Telnet :: OLT falsa');

/** Sobe a OLT falsa num modo e devolve [porta, processo]. */
function zte_olt_falsa(string $modo): array
{
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../apoio/olt_falsa.php', $modo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $porta = (int) trim((string) fgets($pipes[1]));
    return [$porta, $proc];
}

function zte_parar($proc): void
{
    @proc_terminate($proc);
    @proc_close($proc);
}

function zte_telnet(int $porta, string $senha = 'Senha#Teste1', ?string $enable = null, int $tCmd = 5): TransporteTelnet
{
    return new TransporteTelnet('127.0.0.1', $porta, 'teste', $senha, $enable, 3, $tCmd);
}

// normal
[$porta, $proc] = zte_olt_falsa('normal');
$t = zte_telnet($porta);
$t->conectar();
T::igual('login ok e nome do prompt lido (banner com "fail" nao confunde)', 'OLT-c320_1', $t->nomeEquipamento());
$saida = $t->executar('show version-running');
T::certo('saida sem eco do comando e sem prompt',
    str_starts_with($saida, 'PhyLoc') && !str_contains($saida, 'OLT-c320_1#'), substr($saida, 0, 60));
T::igual('saida identica a do fixture', Fixtures::corpo(Fixtures::DIR_PADRAO, 'show_version_running.txt'), $saida);
$drv = new DriverZteC320V21($t);
T::igual('driver real sobre telnet identifica V2.1.0', 'V2.1.0', $drv->identificar()['versao']);
T::igual('lista de 27 ONUs por telnet', 27, $drv->estadoPon(1, 1)['total']);
$t->fechar();
zte_parar($proc);

// paginacao --More-- com backspaces
[$porta, $proc] = zte_olt_falsa('paginado');
$t = zte_telnet($porta);
$t->conectar();
$e = ParserZteC320V21::estadoPon($t->executar('show gpon onu state gpon-olt_1/1/1'));
T::igual('paginacao --More-- tratada: as 27 ONUs chegam inteiras', 27, $e['total']);
$t->fechar();
zte_parar($proc);

// senha errada
[$porta, $proc] = zte_olt_falsa('senha_errada');
try {
    zte_telnet($porta, 'SenhaErrada#9')->conectar();
    T::certo('senha errada e detectada', false, 'conectou');
} catch (OltFalha $f) {
    T::igual('senha errada e detectada como autenticacao', 'autenticacao', $f->tipo());
    T::certo('a mensagem nao carrega a senha', !str_contains(json_encode([$f->getMessage(), $f->detalhes()]), 'SenhaErrada#9'));
}
zte_parar($proc);

// enable
[$porta, $proc] = zte_olt_falsa('enable');
try {
    zte_telnet($porta)->conectar();
    T::certo('modo > sem senha de enable e recusado', false, 'conectou');
} catch (OltFalha $f) {
    T::igual('modo > sem senha de enable e recusado', 'modo_usuario', $f->tipo());
}
zte_parar($proc);
[$porta, $proc] = zte_olt_falsa('enable');
$t = zte_telnet($porta, 'Senha#Teste1', 'Enable#1');
$t->conectar();
T::igual('com a senha de enable, sobe para #', 'V2.1.0', (new DriverZteC320V21($t))->identificar()['versao']);
$t->fechar();
zte_parar($proc);

// OLT muda: timeout de comando
[$porta, $proc] = zte_olt_falsa('mudo');
$t = zte_telnet($porta, 'Senha#Teste1', null, 2);
$t->conectar();
$ini = microtime(true);
try {
    $t->executar('show version-running');
    T::certo('comando sem resposta estoura o tempo', false, 'respondeu');
} catch (OltFalha $f) {
    T::igual('comando sem resposta vira timeout', 'timeout', $f->tipo());
    T::certo('respeita o tempo configurado (2 s)', microtime(true) - $ini < 4, round(microtime(true) - $ini, 1) . ' s');
}
$t->fechar();
zte_parar($proc);

// conexao cai no meio
[$porta, $proc] = zte_olt_falsa('cai');
$t = zte_telnet($porta);
$t->conectar();
try {
    $t->executar('show version-running');
    T::certo('queda no meio do comando e detectada', false, 'respondeu');
} catch (OltFalha $f) {
    T::igual('queda no meio do comando e detectada', 'queda', $f->tipo());
}
zte_parar($proc);

// servidor que aceita e nao fala
[$porta, $proc] = zte_olt_falsa('silencio');
$ini = microtime(true);
try {
    zte_telnet($porta)->conectar();
    T::certo('OLT que nao pede usuario estoura o tempo', false, 'conectou');
} catch (OltFalha $f) {
    T::igual('OLT que nao pede usuario vira timeout', 'timeout', $f->tipo());
}
zte_parar($proc);

// porta fechada
try {
    zte_telnet(1)->conectar();
    T::certo('porta fechada', false, 'conectou');
} catch (OltFalha $f) {
    T::igual('porta fechada vira erro de conexao', 'conexao', $f->tipo());
}
