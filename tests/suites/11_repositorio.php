<?php
/**
 * Suite 11 :: repositorios FTP — listagem, clientes nativo e curl, teste em etapas, navegacao,
 * sincronizacao e acesso da OLT ao FTP. Contra um FTP falso em outro processo.
 */
T::suite('Listagem FTP :: formatos');

$unix = ListagemFtp::ler([
    'total 8',
    'drwxr-xr-x 2 ftp ftp 4096 Oct 01 12:00 zte',
    '-rw-r--r-- 1 ftp ftp 12345678 Oct 01  2026 F670L_V9.0.10P1N2.bin',
    'lrwxrwxrwx 1 ftp ftp 9 Oct 01 12:00 atual -> F670L.bin',
]);
T::igual('unix: pasta primeiro, depois arquivos', ['zte', 'atual', 'F670L_V9.0.10P1N2.bin'], array_column($unix, 'nome'));
T::igual('unix: tamanho do arquivo', 12345678, $unix[2]['tamanho']);
$win = ListagemFtp::ler(['10-01-26  12:00PM       <DIR>          firmwares', '10-01-26  01:15AM             12345 F601.bin']);
T::igual('windows (IIS): pasta e arquivo', [['firmwares', 'dir'], ['F601.bin', 'arquivo']],
    array_map(fn($e) => [$e['nome'], $e['tipo']], $win));
T::igual('windows: tamanho', 12345, $win[1]['tamanho']);

// ---------------------------------------------------------------- FTP falso
$ZTE_FTP_RAIZ = $ZTE_TMP . '/ftp';
@mkdir($ZTE_FTP_RAIZ . '/firmwares/zte', 0700, true);
file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.bin', str_repeat('Z', 5000));
file_put_contents($ZTE_FTP_RAIZ . '/fora_da_raiz.txt', 'nao deve aparecer');

function zte_ftp_falso(string $modo): array
{
    global $ZTE_FTP_RAIZ;
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../apoio/ftp_falso.php', $modo, $ZTE_FTP_RAIZ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [(int) trim((string) fgets($pipes[1])), $proc];
}

T::suite('Clientes FTP :: nativo e curl');

foreach (['nativo' => ClienteFtpNativo::class, 'curl' => ClienteFtpCurl::class] as $rot => $classe) {
    foreach (($rot === 'nativo' ? ['normal', 'sem_mlsd', 'windows'] : ['normal', 'windows']) as $modo) {
        [$porta, $proc] = zte_ftp_falso($modo);
        $c = new $classe('127.0.0.1', $porta, 'ftp', true, 'ftpuser', 'Ftp#Teste1', 3, 10);
        $c->conectar();
        $c->login();
        $l = $c->listar('/firmwares/zte');
        T::igual("$rot/$modo: lista o arquivo", ['F670L_V9.bin', 5000], [$l[0]['nome'] ?? null, $l[0]['tamanho'] ?? null]);
        T::igual("$rot/$modo: tamanho", 5000, $c->tamanho('/firmwares/zte/F670L_V9.bin'));
        T::igual("$rot/$modo: dir existe", [true, false], [$c->existeDir('/firmwares'), $c->existeDir('/nao_existe')]);
        $c->fechar();
        zte_parar($proc);
    }
    [$porta, $proc] = zte_ftp_falso('normal');
    $c = new $classe('127.0.0.1', $porta, 'ftp', true, 'ftpuser', 'Ftp#Teste1', 3, 10);
    $c->conectar();
    $c->login();
    $tmp = tempnam(sys_get_temp_dir(), 'zt');
    file_put_contents($tmp, random_bytes(3000) . "\r\n\n binario");
    $c->enviar($tmp, '/firmwares/' . $rot . '.bin');
    $s = $c->baixar('/firmwares/' . $rot . '.bin');
    T::igual("$rot: envio e leitura binaria identicos", hash_file('sha256', $tmp), hash('sha256', stream_get_contents($s)));
    $c->renomear('/firmwares/' . $rot . '.bin', '/firmwares/' . $rot . '2.bin');
    $c->excluir('/firmwares/' . $rot . '2.bin');
    T::igual("$rot: renomeou e excluiu", null, $c->tamanho('/firmwares/' . $rot . '2.bin'));
    $c->criarDir('/firmwares/novo_' . $rot);
    T::recusa("$rot: criar pasta que existe", fn() => $c->criarDir('/firmwares/novo_' . $rot), 'ZTE-REP-020');
    T::recusa("$rot: excluir arquivo inexistente", fn() => $c->excluir('/firmwares/nada.bin'), 'ZTE-REP-019');
    @unlink($tmp);
    $c->fechar();
    zte_parar($proc);

    [$porta, $proc] = zte_ftp_falso('senha_errada');
    $c = new $classe('127.0.0.1', $porta, 'ftp', true, 'ftpuser', 'Errada#1', 3, 10);
    try {
        $c->conectar();
        $c->login();
        T::certo("$rot: senha errada detectada", false, 'logou');
    } catch (FtpFalha $f) {
        T::igual("$rot: senha errada vira autenticacao", 'autenticacao', $f->tipo());
    }
    zte_parar($proc);

    try {
        $c = new $classe('127.0.0.1', 1, 'ftp', true, 'ftpuser', 'x123', 2, 5);
        $c->conectar();
        $c->login();
        T::certo("$rot: porta fechada", false, 'conectou');
    } catch (FtpFalha $f) {
        T::igual("$rot: porta fechada vira conexao", 'conexao', $f->tipo());
    }
}

T::suite('Repositorio :: cadastro e teste em etapas');

Config::limparCache();
$base = ['nome' => 'FTP Teste', 'protocolo' => 'ftp', 'host' => '127.0.0.1', 'porta' => 21, 'usuario' => 'ftpuser',
         'senha' => 'Ftp#Teste1', 'raiz' => '/firmwares', 'timeout_conexao_s' => 3, 'timeout_transferencia_s' => 10,
         'perm_listar' => 1, 'perm_enviar' => 1, 'perm_renomear' => 1, 'perm_excluir' => 1];
T::recusa('repositorio sem senha e recusado', fn() => RepoServico::salvar(['senha' => ''] + $base, 'teste'), 'ZTE-REP-003');
T::recusa('raiz com .. e recusada', fn() => RepoServico::salvar(['raiz' => '/firmwares/../etc'] + $base, 'teste'), 'ZTE-VAL-004');
$repo = RepoServico::salvar($base, 'teste');
T::certo('senha do FTP so no cofre', !str_contains(json_encode(RepoServico::linha($repo['id'])), 'Ftp#Teste1') && $repo['tem_senha']);
T::recusa('antes do teste, nada esta liberado', fn() => RepoServico::navegar($repo['id'], ''), 'ZTE-REP-011');

function zte_repo_porta(int $id, int $porta): void
{
    Db::exec('UPDATE tab_zte_repositorio SET porta = ? WHERE id = ?', [$porta, $id]);
}

[$porta, $proc] = zte_ftp_falso('normal');
zte_repo_porta($repo['id'], $porta);
$r = RepoServico::testar($repo['id'], 'teste');
T::igual('teste completo ok', 'ok', $r['resultado']);
T::igual('etapas na ordem', ['conexao', 'login', 'raiz', 'listar', 'escrita', 'leitura', 'renomear', 'excluir'], array_column($r['etapas'], 'etapa'));
T::igual('as 4 operacoes confirmadas', [true, true, true, true],
    [$r['repositorio']['liberado_listar'], $r['repositorio']['liberado_enviar'], $r['repositorio']['liberado_renomear'], $r['repositorio']['liberado_excluir']]);
T::igual('a sonda nao ficou no servidor', [], glob($ZTE_FTP_RAIZ . '/firmwares/' . RepoServico::PREFIXO_SONDA . '*'));

T::suite('Repositorio :: navegacao dentro da raiz');

$n = RepoServico::navegar($repo['id'], '');
T::certo('raiz do repositorio e /firmwares: a pasta zte aparece e "firmwares" nao',
    in_array('zte', array_column($n['itens'], 'nome'), true) && !in_array('firmwares', array_column($n['itens'], 'nome'), true));
T::igual('caminho absoluto informado a tela', '/firmwares', $n['absoluto']);
T::certo('arquivo fora da raiz nunca aparece', !in_array('fora_da_raiz.txt', array_column($n['itens'], 'nome'), true));
T::recusa('navegar com .. e recusado', fn() => RepoServico::navegar($repo['id'], '../'), 'ZTE-VAL-004');
T::recusa('navegar com caminho absoluto e recusado', fn() => RepoServico::navegar($repo['id'], '/etc'), 'ZTE-VAL-004');
$z = RepoServico::navegar($repo['id'], 'zte');
T::igual('subpasta lista o firmware', 'F670L_V9.bin', $z['itens'][0]['nome']);

RepoServico::criarPasta($repo['id'], '', 'f601', 'teste');
clearstatcache();
T::certo('pasta criada dentro da raiz', is_dir($ZTE_FTP_RAIZ . '/firmwares/f601'));
T::recusa('nome de pasta com barra e recusado', fn() => RepoServico::criarPasta($repo['id'], '', 'a/b', 'teste'), 'ZTE-VAL-003');
file_put_contents($ZTE_FTP_RAIZ . '/firmwares/f601/velho.bin', 'x');
RepoServico::renomear($repo['id'], 'f601/velho.bin', 'novo.bin', 'teste');
clearstatcache();
T::certo('renomeado na mesma pasta', is_file($ZTE_FTP_RAIZ . '/firmwares/f601/novo.bin'));
T::recusa('excluir exige o nome digitado', fn() => RepoServico::excluirArquivo($repo['id'], 'f601/novo.bin', 'errado', 'teste'), 'ZTE-REP-017');
RepoServico::excluirArquivo($repo['id'], 'f601/novo.bin', 'novo.bin', 'teste');
clearstatcache();
T::certo('arquivo excluido', !is_file($ZTE_FTP_RAIZ . '/firmwares/f601/novo.bin'));
T::igual('operacoes auditadas', 3, (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria WHERE acao IN ('repositorio_criar_pasta','repositorio_renomear','repositorio_excluir_arquivo')"));

T::suite('Repositorio :: firmware protegido e sincronizacao');

Db::exec("INSERT INTO tab_zte_firmware (modelo_familia, versao_firmware, nome_remoto, repositorio_id, caminho_remoto, tamanho_bytes, estado, criado_em)
          VALUES ('F670L', 'V9', 'F670L_V9.bin', ?, '/firmwares/zte/F670L_V9.bin', 5000, 'disponivel', NOW())", [$repo['id']]);
$fwId = Db::ultimoId();
Db::exec("INSERT INTO tab_zte_firmware (modelo_familia, versao_firmware, nome_remoto, repositorio_id, caminho_remoto, tamanho_bytes, estado, criado_em)
          VALUES ('F601', 'V6', 'F601.bin', ?, '/firmwares/F601.bin', 10, 'disponivel', NOW())", [$repo['id']]);
T::recusa('arquivo de firmware cadastrado nao e renomeado', fn() => RepoServico::renomear($repo['id'], 'zte/F670L_V9.bin', 'x.bin', 'teste'), 'ZTE-REP-013');
T::recusa('arquivo de firmware cadastrado nao e excluido', fn() => RepoServico::excluirArquivo($repo['id'], 'zte/F670L_V9.bin', 'F670L_V9.bin', 'teste'), 'ZTE-REP-013');
T::igual('navegador marca o arquivo de firmware', true, RepoServico::navegar($repo['id'], 'zte')['itens'][0]['firmware']);

file_put_contents($ZTE_FTP_RAIZ . '/firmwares/f601/orfao.bin', 'abc');
$s = RepoServico::sincronizar($repo['id'], 'teste');
$tipos = [];
foreach ($s['itens'] as $i) {
    $tipos[$i['tipo']][] = $i['caminho'];
}
T::igual('orfao no FTP apontado', ['/firmwares/f601/orfao.bin'], array_values(array_filter($tipos['orfao_remoto'] ?? [], fn($c) => str_contains($c, 'orfao'))));
T::igual('firmware ausente no FTP apontado', ['/firmwares/F601.bin'], $tipos['ausente_no_ftp'] ?? []);
T::igual('resultado aviso quando falta firmware', 'aviso', $s['resultado']);
clearstatcache();
T::certo('sincronizar nao apaga nada', is_file($ZTE_FTP_RAIZ . '/firmwares/f601/orfao.bin'));
file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.bin', str_repeat('Z', 4999));
$s = RepoServico::sincronizar($repo['id'], 'teste');
T::certo('tamanho divergente apontado', in_array('tamanho_divergente', array_column($s['itens'], 'tipo'), true));
file_put_contents($ZTE_FTP_RAIZ . '/firmwares/zte/F670L_V9.bin', str_repeat('Z', 5000));
zte_parar($proc);

T::suite('Repositorio :: servidor somente leitura, queda e bloqueio');

[$porta, $proc] = zte_ftp_falso('somente_leitura');
zte_repo_porta($repo['id'], $porta);
$r = RepoServico::testar($repo['id'], 'teste');
$porEtapa = array_column($r['etapas'], 'resultado', 'etapa');
T::igual('servidor que nao deixa gravar: listar ok, escrita erro', ['ok', 'erro'], [$porEtapa['listar'], $porEtapa['escrita']]);
T::igual('enviar fica NAO confirmado', [0, false], [$r['repositorio']['confirmado_enviar'], $r['repositorio']['liberado_enviar']]);
T::recusa('com envio nao confirmado, criar pasta e recusado antes de tocar o FTP', fn() => RepoServico::criarPasta($repo['id'], '', 'x', 'teste'), 'ZTE-REP-011');
zte_parar($proc);

$ed = RepoServico::obter($repo['id']);
RepoServico::salvar(['id' => $repo['id'], 'versao' => $ed['versao'], 'porta' => $porta, 'senha' => '', 'perm_excluir' => 0] + $base, 'teste');
[$porta, $proc] = zte_ftp_falso('normal');
zte_repo_porta($repo['id'], $porta);
$r = RepoServico::testar($repo['id'], 'teste');
$porEtapa = array_column($r['etapas'], 'resultado', 'etapa');
T::igual('sem exclusao habilitada, a escrita nao e testada (nao deixa lixo)', 'nao_testavel', $porEtapa['escrita']);
zte_parar($proc);

Cofre::guardar('repo', $repo['id'], 'Errada#77', 'teste');
for ($i = 0; $i < 3; $i++) {
    [$porta, $proc] = zte_ftp_falso('senha_errada');
    zte_repo_porta($repo['id'], $porta);
    RepoServico::testar($repo['id'], 'teste');
    zte_parar($proc);
}
T::recusa('3 falhas de login bloqueiam o repositorio', fn() => RepoServico::testar($repo['id'], 'teste'), 'ZTE-REP-005');
$todos = json_encode(Db::todos('SELECT detalhe FROM tab_zte_teste_conectividade')) . (string) @file_get_contents(Log::arquivoDoDia());
T::certo('nenhuma senha de FTP no historico nem no log', !str_contains($todos, 'Ftp#Teste1') && !str_contains($todos, 'Errada#77'));
$ed = RepoServico::obter($repo['id']);
RepoServico::salvar(['id' => $repo['id'], 'versao' => $ed['versao'], 'perm_excluir' => 1] + $base, 'teste');
T::igual('senha nova libera o bloqueio', false, RepoServico::obter($repo['id'])['bloqueado']);

T::suite('Acesso da OLT ao FTP');

$olt = OltServico::salvar(['nome' => 'OLT Sim FTP', 'protocolo' => 'simulado'], 'teste');
OltServico::testar($olt['id'], 'teste');
$vbase = ['olt_id' => $olt['id'], 'repositorio_id' => $repo['id'], 'porta_olt' => 21, 'usuario_olt' => 'olt_leitura',
          'senha_olt' => 'Olt#Ftp1', 'caminho_olt' => '/'];
T::recusa('endereco visto pela OLT precisa ser IP', fn() => VinculoServico::salvar(['host_olt' => 'ftp.exemplo.com'] + $vbase, 'teste'), 'ZTE-VIN-003');
T::recusa('acesso sem senha e recusado', fn() => VinculoServico::salvar(['host_olt' => '192.0.2.10', 'senha_olt' => ''] + $vbase, 'teste'), 'ZTE-VIN-005');
$v = VinculoServico::salvar(['host_olt' => '192.0.2.10'] + $vbase, 'teste');
T::igual('credencial da OLT no cofre, separada da do addon', ['Olt#Ftp1', 'Ftp#Teste1'], [Cofre::ler('olt_repo', (int) $v['id']), Cofre::ler('repo', $repo['id'])]);
T::recusa('um acesso por par OLT x repositorio', fn() => VinculoServico::salvar(['host_olt' => '192.0.2.11'] + $vbase, 'teste'), 'ZTE-VIN-002');

$t = VinculoServico::testar((int) $v['id'], 'teste');
$porEtapa = array_column($t['etapas'], 'resultado', 'etapa');
T::igual('ping da OLT (simulada) responde: estado POTENCIAL, nunca validado', 'potencial', $t['estado']);
T::igual('download pela OLT fica nao testavel (comando nao validado)', 'nao_testavel', $porEtapa['download']);
T::igual('estado gravado', 'potencial', VinculoServico::linha((int) $v['id'])['estado_conectividade']);
T::recusa('repositorio com acesso de OLT nao pode ser removido', fn() => RepoServico::remover($repo['id'], 'FTP Teste', 'teste'), 'ZTE-REP-004');

$porId = array_column(Diagnostico::componentes(), null, 'componente');
T::igual('diagnostico olt_ftp: potencial vira aviso', 'aviso', $porId['olt_ftp']['resultado']);

VinculoServico::remover((int) $v['id'], 'teste');
T::igual('remover acesso apaga a senha da OLT', false, Cofre::existe('olt_repo', (int) $v['id']));
Db::exec('DELETE FROM tab_zte_firmware_verificacao');
Db::exec('UPDATE tab_zte_sincronizacao_item SET firmware_id = NULL');
Db::exec('DELETE FROM tab_zte_firmware');
Db::exec('DELETE FROM tab_zte_onu');
