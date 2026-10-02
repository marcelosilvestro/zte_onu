<?php
/**
 * zte_onu :: repositorios FTP de firmware.
 *
 * Regras:
 *   - o usuario so escolhe pasta e nome DENTRO da raiz cadastrada (Validar::dentroDaRaiz);
 *   - uma operacao so e oferecida se estiver HABILITADA no cadastro (perm_*) e CONFIRMADA pelo
 *     ultimo teste (confirmado_*): o addon nao descobre no meio de um upload que nao pode gravar;
 *   - o teste de escrita usa um arquivo-sonda e so roda se excluir tambem estiver habilitado,
 *     para nunca deixar lixo no servidor da empresa;
 *   - arquivo que pertence a firmware cadastrado nao e renomeado nem excluido por aqui;
 *   - sincronizar so APONTA diferencas (orfao, ausente, tamanho): nunca apaga nada;
 *   - credencial do addon no cofre (tipo 'repo'); falhas de login seguidas bloqueiam por um tempo.
 */
require_once __DIR__ . '/ClienteFtp.php';
require_once __DIR__ . '/ClienteFtpNativo.php';
require_once __DIR__ . '/ClienteFtpCurl.php';
require_once __DIR__ . '/../Core/carregar.php';

final class RepoServico
{
    public const PROTOCOLOS = [
        'ftp'            => ['rotulo' => 'FTP (sem criptografia)', 'porta' => 21],
        'ftps_explicito' => ['rotulo' => 'FTPS explícito (AUTH TLS)', 'porta' => 21],
        'ftps_implicito' => ['rotulo' => 'FTPS implícito', 'porta' => 990],
    ];
    public const OPERACOES = ['listar', 'enviar', 'renomear', 'excluir'];
    public const PREFIXO_SONDA = 'zte_onu_sonda_';
    private const MAX_ITENS_SYNC = 3000;
    private const MAX_PROFUNDIDADE_SYNC = 4;

    /** Fabrica injetavel (testes forcam curl ou nativo). */
    public static ?Closure $fabricaCliente = null;

    // ================================================================ cadastro

    public static function listar(): array
    {
        return array_map([self::class, 'paraTela'], Db::todos('SELECT * FROM tab_zte_repositorio ORDER BY ativo DESC, nome'));
    }

    public static function obter(int $id): array
    {
        return self::paraTela(self::linha($id));
    }

    public static function salvar(array $e, string $usuario): array
    {
        $id = (int) ($e['id'] ?? 0);
        $protocolo = (string) ($e['protocolo'] ?? 'ftp');
        if (!isset(self::PROTOCOLOS[$protocolo])) {
            throw new ZteErro('ZTE-SYS-002', ['campo' => 'protocolo']);
        }
        $d = [
            'nome'      => Validar::nome($e['nome'] ?? '', 80),
            'host'      => Validar::host($e['host'] ?? ''),
            'porta'     => Validar::porta($e['porta'] ?? self::PROTOCOLOS[$protocolo]['porta']),
            'protocolo' => $protocolo,
            'passivo'   => Validar::bool($e['passivo'] ?? true) ? 1 : 0,
            'raiz'      => Validar::raiz($e['raiz'] ?? '/'),
            'usuario'   => Validar::usuarioRemoto($e['usuario'] ?? ''),
            'timeout_conexao_s'       => Validar::inteiro($e['timeout_conexao_s'] ?? 10, 3, 60),
            'timeout_transferencia_s' => Validar::inteiro($e['timeout_transferencia_s'] ?? 300, 10, 3600),
            'observacao' => Validar::texto($e['observacao'] ?? '', 500),
        ];
        foreach (self::OPERACOES as $op) {
            $d['perm_' . $op] = Validar::bool($e['perm_' . $op] ?? ($op !== 'excluir')) ? 1 : 0;
        }
        $senha = (string) ($e['senha'] ?? '');
        $senha = $senha !== '' ? Validar::senhaRemota($senha) : '';

        return Db::transacao(function () use ($id, $d, $senha, $usuario, $e) {
            $cols = array_keys($d);
            if ($id === 0) {
                if ($senha === '') {
                    throw new ZteErro('ZTE-REP-003');
                }
                try {
                    Db::exec('INSERT INTO tab_zte_repositorio (' . implode(', ', $cols) . ', criado_por, criado_em)
                              VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, NOW())',
                        array_merge(array_values($d), [$usuario]));
                } catch (PDOException $ex) {
                    self::traduzirDuplicado($ex);
                }
                $id = Db::ultimoId();
                $antes = null;
            } else {
                $antes = self::linha($id);
                $versaoLida = Validar::inteiro($e['versao'] ?? 0, 1, PHP_INT_MAX);
                $mudouAcesso = false;
                foreach (['host', 'porta', 'protocolo', 'passivo', 'raiz', 'usuario'] as $c) {
                    $mudouAcesso = $mudouAcesso || (string) $antes[$c] !== (string) $d[$c];
                }
                // Mudou como se chega ao FTP: tudo o que o teste confirmou deixa de valer.
                $reset = $mudouAcesso || $senha !== ''
                    ? ', confirmado_listar = NULL, confirmado_enviar = NULL, confirmado_renomear = NULL, confirmado_excluir = NULL, ultimo_teste_resultado = NULL'
                    : '';
                try {
                    $n = Db::exec('UPDATE tab_zte_repositorio SET ' . implode(' = ?, ', $cols) . ' = ?, versao = versao + 1,
                                          alterado_por = ?, alterado_em = NOW()' . $reset
                                  . ($senha !== '' ? ', falhas_auth = 0, bloqueado_ate = NULL' : '') .
                                  ' WHERE id = ? AND versao = ?', array_merge(array_values($d), [$usuario, $id, $versaoLida]));
                } catch (PDOException $ex) {
                    self::traduzirDuplicado($ex);
                }
                if ($n !== 1) {
                    throw new ZteErro('ZTE-CONC-001', [], null, 409);
                }
            }
            if ($senha !== '') {
                Cofre::guardar('repo', $id, $senha, $usuario);
            }
            $depois = self::linha($id);
            Auditoria::registrar($antes === null ? 'repositorio_criar' : 'repositorio_alterar', 'repositorio', $id,
                $antes === null ? null : self::paraAuditoria($antes),
                self::paraAuditoria($depois) + ['credencial_trocada' => $senha !== '']);
            return self::paraTela($depois);
        });
    }

    public static function definirAtivo(int $id, bool $ativo, string $usuario): array
    {
        $antes = self::linha($id);
        Db::exec('UPDATE tab_zte_repositorio SET ativo = ?, desativado_em = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                   WHERE id = ?', [$ativo ? 1 : 0, $ativo ? null : date('Y-m-d H:i:s'), $usuario, $id]);
        Auditoria::registrar($ativo ? 'repositorio_reativar' : 'repositorio_desativar', 'repositorio', $id,
            ['ativo' => (int) $antes['ativo']], ['ativo' => $ativo ? 1 : 0]);
        return self::obter($id);
    }

    public static function remover(int $id, string $confirmacao, string $usuario): void
    {
        $r = self::linha($id);
        if ($confirmacao !== $r['nome']) {
            throw new ZteErro('ZTE-REP-017');
        }
        $uso = (int) Db::valor('SELECT (SELECT COUNT(*) FROM tab_zte_firmware WHERE repositorio_id = ?)
                                     + (SELECT COUNT(*) FROM tab_zte_olt_repositorio WHERE repositorio_id = ?)', [$id, $id]);
        if ($uso > 0) {
            throw new ZteErro('ZTE-REP-004', [], null, 409);
        }
        Db::transacao(function () use ($id, $r) {
            Cofre::apagar('repo', $id);
            Db::exec('DELETE FROM tab_zte_sincronizacao WHERE repositorio_id = ?', [$id]);
            Db::exec('DELETE FROM tab_zte_repositorio WHERE id = ?', [$id]);
            Auditoria::registrar('repositorio_remover', 'repositorio', $id, self::paraAuditoria($r), null);
        });
    }

    // ================================================================ teste

    /**
     * conexao -> login -> raiz -> listar -> escrita (sonda) -> leitura (confere conteudo)
     * -> renomear -> excluir. Cada etapa com resultado proprio; o que estiver desabilitado no
     * cadastro aparece como nao_testavel e fica sem confirmacao.
     */
    public static function testar(int $id, string $usuario, ?ClienteFtp $cliente = null): array
    {
        $r = self::linha($id);
        self::exigirUtilizavel($r);
        $trava = 'zte_repo_' . $id;
        if (!Db::travar($trava, 0)) {
            throw new ZteErro('ZTE-REP-018', [], null, 409);
        }

        $correlacao = 'TST-REP' . $id . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
        $etapas = [];
        $conf = ['listar' => null, 'enviar' => null, 'renomear' => null, 'excluir' => null];
        $falhaAuth = false;
        $c = null;
        $tmp = null;

        try {
            $t = microtime(true);
            try {
                $c = $cliente ?? self::cliente($r);
                $c->conectar();
                $etapas[] = self::etapa('conexao', 'Conexão', 'ok', $r['host'] . ':' . $r['porta'] . ' (' . $c->implementacao() . ').', $t);
                $t = microtime(true);
                $c->login();
                $etapas[] = self::etapa('login', 'Autenticação', 'ok', 'Usuário ' . $r['usuario'] . ' aceito.', $t);
            } catch (FtpFalha $f) {
                $falhaAuth = $f->tipo() === 'autenticacao';
                $etapas[] = self::etapa($falhaAuth ? 'login' : 'conexao', $falhaAuth ? 'Autenticação' : 'Conexão', 'erro', self::textoFalha($f), $t);
            } catch (ZteErro $z) {
                $etapas[] = self::etapa('conexao', 'Conexão', 'erro', $z->getMessage(), $t);
            }

            if (self::semErro($etapas)) {
                $raiz = $r['raiz'];
                $t = microtime(true);
                $okRaiz = false;
                try {
                    $okRaiz = $c->existeDir($raiz);
                } catch (FtpFalha $f) {
                }
                $etapas[] = self::etapa('raiz', 'Diretório raiz', $okRaiz ? 'ok' : 'erro',
                    $okRaiz ? $raiz . ' acessível.' : Erros::mensagem('ZTE-REP-009') . ' (' . $raiz . ')', $t);

                if ($okRaiz) {
                    // listar
                    if ((int) $r['perm_listar']) {
                        $t = microtime(true);
                        try {
                            $n = count($c->listar($raiz));
                            $conf['listar'] = 1;
                            $etapas[] = self::etapa('listar', 'Listar arquivos', 'ok', $n . ' item(ns) na raiz.', $t);
                        } catch (FtpFalha $f) {
                            $conf['listar'] = 0;
                            $etapas[] = self::etapa('listar', 'Listar arquivos', 'erro', self::textoFalha($f), $t);
                        }
                    } else {
                        $etapas[] = self::etapa('listar', 'Listar arquivos', 'nao_testavel', 'Desabilitado no cadastro.', microtime(true));
                    }

                    // escrita/leitura/renomear/excluir com sonda
                    if (!(int) $r['perm_enviar']) {
                        $etapas[] = self::etapa('escrita', 'Gravar arquivo', 'nao_testavel', 'Desabilitado no cadastro.', microtime(true));
                    } elseif (!(int) $r['perm_excluir']) {
                        $etapas[] = self::etapa('escrita', 'Gravar arquivo', 'nao_testavel',
                            'Para testar a gravação sem deixar arquivo no servidor, a exclusão também precisa estar habilitada.', microtime(true));
                    } else {
                        $sonda = rtrim($raiz, '/') . '/' . self::PREFIXO_SONDA . bin2hex(random_bytes(6)) . '.tmp';
                        $conteudo = 'zte_onu ' . $correlacao . ' ' . bin2hex(random_bytes(16));
                        $tmp = tempnam(sys_get_temp_dir(), 'zte');
                        file_put_contents($tmp, $conteudo);
                        $t = microtime(true);
                        try {
                            $c->enviar($tmp, $sonda);
                            $conf['enviar'] = 1;
                            $etapas[] = self::etapa('escrita', 'Gravar arquivo', 'ok', 'Arquivo de teste gravado.', $t);

                            $t = microtime(true);
                            $s = $c->baixar($sonda);
                            $lido = stream_get_contents($s);
                            fclose($s);
                            $etapas[] = self::etapa('leitura', 'Ler de volta e conferir', $lido === $conteudo ? 'ok' : 'erro',
                                $lido === $conteudo ? 'Conteúdo idêntico ao enviado.' : 'O conteúdo lido difere do enviado (transferência em modo texto?).', $t);

                            if ((int) $r['perm_renomear']) {
                                $t = microtime(true);
                                try {
                                    $c->renomear($sonda, $sonda . '.ren');
                                    $sonda .= '.ren';
                                    $conf['renomear'] = 1;
                                    $etapas[] = self::etapa('renomear', 'Renomear arquivo', 'ok', 'Renomeado.', $t);
                                } catch (FtpFalha $f) {
                                    $conf['renomear'] = 0;
                                    $etapas[] = self::etapa('renomear', 'Renomear arquivo', 'erro', self::textoFalha($f), $t);
                                }
                            } else {
                                $etapas[] = self::etapa('renomear', 'Renomear arquivo', 'nao_testavel', 'Desabilitado no cadastro.', microtime(true));
                            }

                            $t = microtime(true);
                            try {
                                $c->excluir($sonda);
                                $conf['excluir'] = 1;
                                $etapas[] = self::etapa('excluir', 'Excluir arquivo', 'ok', 'Arquivo de teste removido.', $t);
                            } catch (FtpFalha $f) {
                                $conf['excluir'] = 0;
                                $etapas[] = self::etapa('excluir', 'Excluir arquivo', 'erro',
                                    self::textoFalha($f) . ' O arquivo de teste ' . basename($sonda) . ' ficou no servidor.', $t);
                            }
                        } catch (FtpFalha $f) {
                            $conf['enviar'] = $conf['enviar'] ?? 0;
                            $etapas[] = self::etapa($conf['enviar'] ? 'leitura' : 'escrita', $conf['enviar'] ? 'Ler de volta e conferir' : 'Gravar arquivo',
                                'erro', self::textoFalha($f), $t);
                            if ($conf['enviar']) {
                                try {
                                    $c->excluir($sonda);            // nao deixar a sonda para tras
                                } catch (Throwable $ignorar) {
                                }
                            }
                        }
                    }
                }
            }
        } finally {
            if ($c !== null) {
                $c->fechar();
            }
            if ($tmp !== null) {
                @unlink($tmp);
            }
            Db::destravar($trava);
        }

        $resultado = Diagnostico::pior(array_filter($etapas, fn($e) => $e['resultado'] !== 'nao_testavel')) ;
        $resumo = implode(' | ', array_map(fn($e) => $e['titulo'] . ': ' . $e['detalhe'],
                      array_filter($etapas, fn($e) => $e['resultado'] === 'erro'))) ?: 'Repositório acessível.';

        Db::transacao(function () use ($id, $r, $conf, $resultado, $resumo, $falhaAuth, $etapas, $correlacao, $usuario) {
            $sets = ['ultimo_teste_em = NOW()', 'ultimo_teste_resultado = ?', 'ultimo_teste_detalhe = ?'];
            $p = [$resultado, mb_substr($resumo, 0, 500)];
            foreach ($conf as $op => $v) {
                $sets[] = 'confirmado_' . $op . ' = ?';
                $p[] = $v;
            }
            if ($falhaAuth) {
                $falhas = (int) $r['falhas_auth'] + 1;
                $sets[] = 'falhas_auth = ?';
                $p[] = $falhas;
                if ($falhas >= Config::int('bloqueio_auth_falhas')) {
                    $sets[] = 'bloqueado_ate = NOW() + INTERVAL ? MINUTE';
                    $p[] = Config::int('bloqueio_auth_min');
                }
            } elseif (count($etapas) > 1) {
                $sets[] = 'falhas_auth = 0';
                $sets[] = 'bloqueado_ate = NULL';
            }
            $p[] = $id;
            Db::exec('UPDATE tab_zte_repositorio SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);
            self::gravarEtapas('addon_ftp', 'repositorio', $id, $etapas, $correlacao, $usuario);
            Auditoria::registrar('repositorio_testar', 'repositorio', $id, null, ['resultado' => $resultado, 'confirmado' => $conf], $correlacao);
        });
        Log::info('repositorio.testar', ['repositorio' => $id, 'resultado' => $resultado, 'correlacao' => $correlacao]);

        return ['resultado' => $resultado, 'etapas' => $etapas, 'correlacao' => $correlacao, 'repositorio' => self::obter($id)];
    }

    public static function historicoTestes(int $id, int $limite = 10): array
    {
        self::linha($id);
        return self::historico('addon_ftp', 'repositorio', $id, $limite);
    }

    // ================================================================ navegacao

    /** Lista uma pasta relativa a raiz. */
    public static function navegar(int $id, string $relativo): array
    {
        $r = self::linha($id);
        self::exigirOperacao($r, 'listar');
        $rel = Validar::caminhoRelativo($relativo);
        $abs = Validar::dentroDaRaiz($r['raiz'], $rel);
        $itens = self::comCliente($r, fn(ClienteFtp $c) => $c->listar($abs));

        $usados = self::caminhosDeFirmware($id);
        foreach ($itens as &$i) {
            $i['caminho'] = ($rel === '' ? '' : $rel . '/') . $i['nome'];
            $i['firmware'] = isset($usados[rtrim($abs, '/') . '/' . $i['nome']]);
            $i['sonda'] = str_starts_with($i['nome'], self::PREFIXO_SONDA);
        }
        unset($i);
        return [
            'relativo' => $rel,
            'absoluto' => $abs,
            'itens'    => $itens,
            'pode'     => [
                'renomear' => self::operacaoLiberada($r, 'renomear'),
                'excluir'  => self::operacaoLiberada($r, 'excluir'),
                'enviar'   => self::operacaoLiberada($r, 'enviar'),
            ],
        ];
    }

    public static function criarPasta(int $id, string $relPai, string $nome, string $usuario): void
    {
        $r = self::linha($id);
        self::exigirOperacao($r, 'enviar');
        $rel = Validar::caminhoRelativo($relPai);
        $nome = Validar::nomeArquivo($nome);
        $abs = Validar::dentroDaRaiz($r['raiz'], ($rel === '' ? '' : $rel . '/') . $nome);
        self::comCliente($r, fn(ClienteFtp $c) => $c->criarDir($abs), true);
        Auditoria::registrar('repositorio_criar_pasta', 'repositorio', $id, null, ['caminho' => $abs]);
    }

    public static function renomear(int $id, string $relativo, string $novoNome, string $usuario): void
    {
        $r = self::linha($id);
        self::exigirOperacao($r, 'renomear');
        $rel = Validar::caminhoRelativo($relativo);
        if ($rel === '') {
            throw new ZteErro('ZTE-VAL-004');
        }
        $novoNome = Validar::nomeArquivo($novoNome);
        $de = Validar::dentroDaRaiz($r['raiz'], $rel);
        $pai = dirname($rel) === '.' ? '' : dirname($rel);
        $para = Validar::dentroDaRaiz($r['raiz'], ($pai === '' ? '' : $pai . '/') . $novoNome);
        self::exigirLivreDeFirmware($id, $de);
        self::comCliente($r, function (ClienteFtp $c) use ($para, $de) {
            if ($c->tamanho($para) !== null) {
                throw new FtpFalha('existe', $para);
            }
            $c->renomear($de, $para);
        }, true);
        Auditoria::registrar('repositorio_renomear', 'repositorio', $id, ['caminho' => $de], ['caminho' => $para]);
    }

    /** Exclui um ARQUIVO (pastas ficam a cargo do administrador do servidor). Exige o nome digitado. */
    public static function excluirArquivo(int $id, string $relativo, string $confirmacao, string $usuario): void
    {
        $r = self::linha($id);
        self::exigirOperacao($r, 'excluir');
        $rel = Validar::caminhoRelativo($relativo);
        if ($rel === '' || $confirmacao !== basename($rel)) {
            throw new ZteErro('ZTE-REP-017');
        }
        $abs = Validar::dentroDaRaiz($r['raiz'], $rel);
        self::exigirLivreDeFirmware($id, $abs);
        $tam = self::comCliente($r, function (ClienteFtp $c) use ($abs) {
            $tam = $c->tamanho($abs);
            $c->excluir($abs);
            return $tam;
        }, true);
        Auditoria::registrar('repositorio_excluir_arquivo', 'repositorio', $id, ['caminho' => $abs, 'tamanho' => $tam], null);
    }

    // ================================================================ sincronizacao

    /**
     * Compara o que esta no FTP com o catalogo de firmwares deste repositorio. So aponta.
     * Percorre subpastas ate MAX_PROFUNDIDADE_SYNC niveis e MAX_ITENS_SYNC arquivos.
     */
    public static function sincronizar(int $id, string $usuario, string $origem = 'manual'): array
    {
        $r = self::linha($id);
        self::exigirOperacao($r, 'listar');
        Db::exec('INSERT INTO tab_zte_sincronizacao (repositorio_id, iniciado_em, criado_por) VALUES (?, NOW(), ?)', [$id, $usuario]);
        $syncId = Db::ultimoId();

        try {
            $remotos = self::comCliente($r, function (ClienteFtp $c) use ($r) {
                $achados = [];
                $fila = [[$r['raiz'], 0]];
                $truncado = false;
                while ($fila) {
                    [$dir, $nivel] = array_shift($fila);
                    foreach ($c->listar($dir) as $i) {
                        $abs = rtrim($dir, '/') . '/' . $i['nome'];
                        if ($i['tipo'] === 'dir') {
                            if ($nivel + 1 < self::MAX_PROFUNDIDADE_SYNC) {
                                $fila[] = [$abs, $nivel + 1];
                            }
                        } elseif (!str_starts_with($i['nome'], self::PREFIXO_SONDA)) {
                            $achados[$abs] = $i['tamanho'];
                            if (count($achados) >= self::MAX_ITENS_SYNC) {
                                $truncado = true;
                                break 2;
                            }
                        }
                    }
                }
                return ['arquivos' => $achados, 'truncado' => $truncado];
            });
        } catch (Throwable $e) {
            Db::exec("UPDATE tab_zte_sincronizacao SET concluido_em = NOW(), resultado = 'erro', detalhe = ? WHERE id = ?",
                [mb_substr($e->getMessage(), 0, 500), $syncId]);
            throw $e;
        }

        $catalogo = Db::todos("SELECT id, caminho_remoto, tamanho_bytes, estado FROM tab_zte_firmware WHERE repositorio_id = ?", [$id]);
        $itens = [];
        $porCaminho = [];
        foreach ($catalogo as $f) {
            $porCaminho[$f['caminho_remoto']] = $f;
            if (!array_key_exists($f['caminho_remoto'], $remotos['arquivos'])) {
                if ($f['estado'] !== 'desativado') {
                    $itens[] = ['tipo' => 'ausente_no_ftp', 'caminho' => $f['caminho_remoto'], 'firmware_id' => (int) $f['id'], 'tamanho' => null];
                }
            } elseif ($f['tamanho_bytes'] !== null && $remotos['arquivos'][$f['caminho_remoto']] !== null
                      && (int) $f['tamanho_bytes'] !== (int) $remotos['arquivos'][$f['caminho_remoto']]) {
                $itens[] = ['tipo' => 'tamanho_divergente', 'caminho' => $f['caminho_remoto'], 'firmware_id' => (int) $f['id'],
                            'tamanho' => $remotos['arquivos'][$f['caminho_remoto']]];
            }
        }
        foreach ($remotos['arquivos'] as $caminho => $tam) {
            if (!isset($porCaminho[$caminho])) {
                $itens[] = ['tipo' => 'orfao_remoto', 'caminho' => $caminho, 'firmware_id' => null, 'tamanho' => $tam];
            }
        }

        $cont = array_count_values(array_column($itens, 'tipo'));
        $resultado = ($cont['ausente_no_ftp'] ?? 0) + ($cont['tamanho_divergente'] ?? 0) > 0 ? 'aviso' : 'ok';
        $detalhe = $remotos['truncado'] ? 'Listagem interrompida em ' . self::MAX_ITENS_SYNC . ' arquivos.' : null;
        if ($remotos['truncado']) {
            $resultado = 'aviso';
        }

        Db::transacao(function () use ($syncId, $itens, $remotos, $cont, $resultado, $detalhe, $id, $origem, $usuario) {
            foreach ($itens as $i) {
                Db::exec('INSERT INTO tab_zte_sincronizacao_item (sincronizacao_id, tipo, caminho, firmware_id, tamanho) VALUES (?, ?, ?, ?, ?)',
                    [$syncId, $i['tipo'], mb_substr($i['caminho'], 0, 255), $i['firmware_id'], $i['tamanho']]);
                // O firmware afetado muda de estado (nunca e apagado): sai de uso ate ser reverificado.
                if ($i['firmware_id'] !== null && class_exists('FirmwareServico')) {
                    FirmwareServico::marcarPelaSincronizacao($i['firmware_id'], $i['tipo'], $usuario);
                }
            }
            Db::exec('UPDATE tab_zte_sincronizacao SET concluido_em = NOW(), resultado = ?, total_remoto = ?, orfaos = ?, ausentes = ?, detalhe = ?
                       WHERE id = ?', [$resultado, count($remotos['arquivos']), $cont['orfao_remoto'] ?? 0,
                                       ($cont['ausente_no_ftp'] ?? 0) + ($cont['tamanho_divergente'] ?? 0), $detalhe, $syncId]);
            Auditoria::registrar('repositorio_sincronizar', 'repositorio', $id, null,
                ['origem' => $origem, 'remotos' => count($remotos['arquivos']), 'diferencas' => $cont]);
        });
        return self::ultimaSincronizacao($id);
    }

    public static function ultimaSincronizacao(int $id): ?array
    {
        $s = Db::um('SELECT * FROM tab_zte_sincronizacao WHERE repositorio_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        if ($s === null) {
            return null;
        }
        $s['itens'] = Db::todos('SELECT tipo, caminho, firmware_id, tamanho FROM tab_zte_sincronizacao_item
                                  WHERE sincronizacao_id = ? ORDER BY tipo, caminho LIMIT 500', [$s['id']]);
        return $s;
    }

    // ================================================================ cliente

    /** Monta o cliente certo para o protocolo e o que o PHP oferece. A senha sai do cofre so aqui. */
    public static function cliente(array $r): ClienteFtp
    {
        $senha = Cofre::ler('repo', (int) $r['id']);
        if ($senha === null) {
            throw new ZteErro('ZTE-REP-003');
        }
        $args = [(string) $r['host'], (int) $r['porta'], (string) $r['protocolo'], (bool) (int) $r['passivo'],
                 (string) $r['usuario'], $senha, (int) $r['timeout_conexao_s'], (int) $r['timeout_transferencia_s']];
        if (self::$fabricaCliente !== null) {
            return (self::$fabricaCliente)(...$args);
        }
        if ($r['protocolo'] !== 'ftps_implicito' && extension_loaded('ftp')) {
            return new ClienteFtpNativo(...$args);
        }
        if (extension_loaded('curl')) {
            return new ClienteFtpCurl(...$args);
        }
        throw new FtpFalha($r['protocolo'] === 'ftp' ? 'conexao' : 'ftps', 'sem ext-ftp nem curl');
    }

    /** Abre, executa e fecha, com trava do repositorio. */
    public static function comCliente(array $r, callable $fn, bool $escrita = false)
    {
        $trava = 'zte_repo_' . $r['id'];
        if (!Db::travar($trava, $escrita ? 5 : 10)) {
            throw new ZteErro('ZTE-REP-018', [], null, 409);
        }
        $c = null;
        try {
            $c = self::cliente($r);
            $c->conectar();
            $c->login();
            return $fn($c);
        } finally {
            if ($c !== null) {
                $c->fechar();
            }
            Db::destravar($trava);
        }
    }

    // ================================================================ apoio

    public static function linha(int $id): array
    {
        $r = Db::um('SELECT * FROM tab_zte_repositorio WHERE id = ?', [$id]);
        if ($r === null) {
            throw new ZteErro('ZTE-REP-001', [], null, 404);
        }
        return $r;
    }

    public static function operacaoLiberada(array $r, string $op): bool
    {
        return (int) $r['perm_' . $op] === 1 && (string) $r['confirmado_' . $op] === '1';
    }

    private static function exigirUtilizavel(array $r): void
    {
        if (!(int) $r['ativo']) {
            throw new ZteErro('ZTE-REP-015');
        }
        if ($r['bloqueado_ate'] !== null && strtotime($r['bloqueado_ate']) > time()) {
            throw new ZteErro('ZTE-REP-005', ['ate' => $r['bloqueado_ate']],
                Erros::mensagem('ZTE-REP-005') . ' Nova tentativa a partir de ' . date('H:i', strtotime($r['bloqueado_ate'])) . '.', 423);
        }
    }

    public static function exigirOperacao(array $r, string $op): void
    {
        self::exigirUtilizavel($r);
        if (!(int) $r['perm_' . $op]) {
            throw new ZteErro('ZTE-REP-010', ['operacao' => $op], null, 403);
        }
        if ((string) $r['confirmado_' . $op] !== '1') {
            throw new ZteErro('ZTE-REP-011', ['operacao' => $op], null, 409);
        }
    }

    /** @return array<string,true> caminhos absolutos usados por firmwares do repositorio */
    private static function caminhosDeFirmware(int $id): array
    {
        $saida = [];
        foreach (Db::todos('SELECT caminho_remoto FROM tab_zte_firmware WHERE repositorio_id = ?', [$id]) as $f) {
            $saida[$f['caminho_remoto']] = true;
        }
        return $saida;
    }

    private static function exigirLivreDeFirmware(int $id, string $abs): void
    {
        $n = (int) Db::valor('SELECT COUNT(*) FROM tab_zte_firmware WHERE repositorio_id = ? AND (caminho_remoto = ? OR caminho_remoto LIKE ?)',
            [$id, $abs, rtrim($abs, '/') . '/%']);
        if ($n > 0) {
            throw new ZteErro('ZTE-REP-013', [], null, 409);
        }
    }

    public static function gravarEtapas(string $componente, string $alvoTipo, int $alvoId, array $etapas, string $correlacao, string $usuario): void
    {
        foreach ($etapas as $e) {
            Db::exec('INSERT INTO tab_zte_teste_conectividade (componente, alvo_tipo, alvo_id, etapa, resultado, detalhe,
                             duracao_ms, correlacao, criado_por, criado_em)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$componente, $alvoTipo, $alvoId, $e['etapa'], $e['resultado'], mb_substr($e['detalhe'], 0, 500), $e['ms'], $correlacao, $usuario]);
        }
    }

    /** Titulo de cada etapa gravada em tab_zte_teste_conectividade (o banco guarda so o id). */
    public const TITULOS_ETAPA = [
        'conexao' => 'Conexão', 'login' => 'Autenticação', 'raiz' => 'Diretório raiz', 'listar' => 'Listar arquivos',
        'escrita' => 'Gravar arquivo', 'leitura' => 'Ler de volta e conferir', 'renomear' => 'Renomear arquivo',
        'excluir' => 'Excluir arquivo', 'identificacao' => 'Identificação do equipamento',
        'compatibilidade' => 'Compatibilidade do driver', 'identidade' => 'Mesmo equipamento do teste anterior',
        'credencial' => 'Credencial da OLT no FTP', 'alcance' => 'OLT alcança o FTP', 'download' => 'OLT baixa um arquivo do FTP',
    ];

    public static function historico(string $componente, string $alvoTipo, int $alvoId, int $limite): array
    {
        $linhas = Db::todos('SELECT correlacao, etapa, resultado, detalhe, duracao_ms, criado_por, criado_em
                               FROM tab_zte_teste_conectividade
                              WHERE componente = ? AND alvo_tipo = ? AND alvo_id = ?
                           ORDER BY id DESC LIMIT 300', [$componente, $alvoTipo, $alvoId]);
        $grupos = [];
        foreach ($linhas as $l) {
            $c = (string) $l['correlacao'];
            if (!isset($grupos[$c])) {
                if (count($grupos) >= $limite) {
                    break;
                }
                $grupos[$c] = ['correlacao' => $c, 'em' => $l['criado_em'], 'por' => $l['criado_por'], 'etapas' => []];
            }
            $l['titulo'] = self::TITULOS_ETAPA[$l['etapa']] ?? $l['etapa'];
            array_unshift($grupos[$c]['etapas'], $l);
        }
        foreach ($grupos as &$g) {
            $g['resultado'] = Diagnostico::pior(array_filter($g['etapas'], fn($e) => $e['resultado'] !== 'nao_testavel')) ;
        }
        unset($g);
        return array_values($grupos);
    }

    public static function etapa(string $etapa, string $titulo, string $resultado, string $detalhe, float $inicio): array
    {
        return ['etapa' => $etapa, 'titulo' => $titulo, 'resultado' => $resultado, 'detalhe' => $detalhe,
                'ms' => max(0, (int) ((microtime(true) - $inicio) * 1000))];
    }

    private static function semErro(array $etapas): bool
    {
        foreach ($etapas as $e) {
            if ($e['resultado'] === 'erro') {
                return false;
            }
        }
        return true;
    }

    private static function textoFalha(ZteErro $f): string
    {
        $tec = $f->detalhes()['tecnico'] ?? '';
        return $f->getMessage() . ($tec !== '' ? ' (' . Log::mascararTexto($tec) . ')' : '');
    }

    private static function paraTela(array $r): array
    {
        $id = (int) $r['id'];
        foreach (['id', 'porta', 'passivo', 'ativo', 'versao', 'timeout_conexao_s', 'timeout_transferencia_s', 'falhas_auth'] as $c) {
            $r[$c] = (int) $r[$c];
        }
        foreach (self::OPERACOES as $op) {
            $r['perm_' . $op] = (int) $r['perm_' . $op];
            $r['confirmado_' . $op] = $r['confirmado_' . $op] === null ? null : (int) $r['confirmado_' . $op];
            $r['liberado_' . $op] = self::operacaoLiberada($r, $op);
        }
        $r['tem_senha'] = Cofre::existe('repo', $id);
        $r['bloqueado'] = $r['bloqueado_ate'] !== null && strtotime($r['bloqueado_ate']) > time();
        $r['firmwares'] = (int) Db::valor('SELECT COUNT(*) FROM tab_zte_firmware WHERE repositorio_id = ?', [$id]);
        return $r;
    }

    private static function paraAuditoria(array $r): array
    {
        return array_intersect_key($r, array_flip(['nome', 'host', 'porta', 'protocolo', 'passivo', 'raiz', 'usuario',
            'timeout_conexao_s', 'timeout_transferencia_s', 'perm_listar', 'perm_enviar', 'perm_renomear', 'perm_excluir', 'observacao', 'ativo']));
    }

    private static function traduzirDuplicado(PDOException $ex): void
    {
        if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
            throw new ZteErro('ZTE-REP-002', [], null, 409);
        }
        throw $ex;
    }
}
