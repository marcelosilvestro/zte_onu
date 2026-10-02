<?php
/**
 * zte_onu :: cadastro e teste de OLTs.
 *
 * Regras:
 *   - nada de IP/usuario/senha fixo: tudo vem daqui, cadastrado na tela;
 *   - senha so no cofre; a tela recebe apenas "tem senha: sim/nao";
 *   - versao, placas e nome do equipamento sao DETECTADOS no teste, nunca digitados;
 *   - se o equipamento que responde muda de nome entre dois testes, o resultado e AVISO: pode ser
 *     outro equipamento no mesmo IP, e o addon nao deve tratar como a mesma OLT sem o operador ver;
 *   - falhas de login seguidas bloqueiam novas tentativas por um tempo (nao travar a conta na OLT);
 *   - uma operacao por OLT de cada vez (GET_LOCK), contra outra aba ou o worker.
 */
require_once __DIR__ . '/Registro.php';
require_once __DIR__ . '/../Core/carregar.php';

final class OltServico
{
    private const CAMPOS_LISTA = 'id, nome, fabricante, modelo, host, porta, protocolo, usuario, timeout_conexao_s,
        timeout_comando_s, driver, versao_detectada, placas_detectadas, identificador_detectado, compatibilidade,
        ultimo_teste_em, ultimo_teste_resultado, ultimo_teste_detalhe, falhas_auth, bloqueado_ate, observacao, ativo,
        versao, criado_por, criado_em, alterado_por, alterado_em, desativado_em';

    /** @return array<int,array> */
    public static function listar(): array
    {
        $linhas = Db::todos('SELECT ' . self::CAMPOS_LISTA . ' FROM tab_zte_olt ORDER BY ativo DESC, nome');
        return array_map([self::class, 'paraTela'], $linhas);
    }

    public static function obter(int $id): array
    {
        return self::paraTela(self::linha($id));
    }

    /**
     * Cria (id = 0) ou altera. Senha vazia na alteracao mantem a atual.
     * @return array a OLT gravada, no formato da tela
     */
    public static function salvar(array $e, string $usuario): array
    {
        $id = (int) ($e['id'] ?? 0);
        $protocolo = (string) ($e['protocolo'] ?? 'telnet');
        if (!isset(RegistroOlt::PROTOCOLOS[$protocolo])) {
            throw new ZteErro('ZTE-SYS-002', ['campo' => 'protocolo']);
        }
        if (!RegistroOlt::PROTOCOLOS[$protocolo]['disponivel']) {
            throw new ZteErro('ZTE-OLT-010');
        }
        $simulada = $protocolo === 'simulado';

        $fabricante = Validar::texto($e['fabricante'] ?? 'ZTE', 40, true);
        $modelo     = Validar::texto($e['modelo'] ?? 'C320', 40, true);
        if (RegistroOlt::driverPara($fabricante, $modelo) === null) {
            throw new ZteErro('ZTE-OLT-012', ['fabricante' => $fabricante, 'modelo' => $modelo]);
        }

        $d = [
            'nome'              => Validar::nome($e['nome'] ?? '', 80),
            'fabricante'        => $fabricante,
            'modelo'            => $modelo,
            'host'              => $simulada ? 'simulado' : Validar::host($e['host'] ?? ''),
            'porta'             => $simulada ? 23 : Validar::porta($e['porta'] ?? RegistroOlt::PROTOCOLOS[$protocolo]['porta']),
            'protocolo'         => $protocolo,
            'usuario'           => $simulada ? 'simulado' : Validar::usuarioRemoto($e['usuario'] ?? ''),
            'timeout_conexao_s' => Validar::inteiro($e['timeout_conexao_s'] ?? 10, 3, 60),
            'timeout_comando_s' => Validar::inteiro($e['timeout_comando_s'] ?? 30, 5, 300),
            'observacao'        => Validar::texto($e['observacao'] ?? '', 500),
        ];
        $senha  = (string) ($e['senha'] ?? '');
        $enable = (string) ($e['senha_enable'] ?? '');
        $senha  = $senha  !== '' ? Validar::senhaRemota($senha) : '';
        $enable = $enable !== '' ? Validar::senhaRemota($enable) : '';
        $limparEnable = Validar::bool($e['limpar_enable'] ?? false);

        return Db::transacao(function () use ($id, $d, $senha, $enable, $limparEnable, $simulada, $usuario, $e) {
            if ($id === 0) {
                if (!$simulada && $senha === '') {
                    throw new ZteErro('ZTE-OLT-003');
                }
                try {
                    Db::exec('INSERT INTO tab_zte_olt (nome, fabricante, modelo, host, porta, protocolo, usuario, timeout_conexao_s,
                                   timeout_comando_s, observacao, criado_por, criado_em)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                        [$d['nome'], $d['fabricante'], $d['modelo'], $d['host'], $d['porta'], $d['protocolo'], $d['usuario'],
                         $d['timeout_conexao_s'], $d['timeout_comando_s'], $d['observacao'], $usuario]);
                } catch (PDOException $ex) {
                    self::traduzirDuplicado($ex);
                }
                $id = Db::ultimoId();
                $antes = null;
            } else {
                $antes = self::linha($id);
                $versaoLida = Validar::inteiro($e['versao'] ?? 0, 1, PHP_INT_MAX);
                // Mudou como se chega a OLT: o teste anterior deixa de valer.
                $mudouAcesso = $antes['host'] !== $d['host'] || (int) $antes['porta'] !== $d['porta']
                            || $antes['protocolo'] !== $d['protocolo'] || $antes['usuario'] !== $d['usuario']
                            || $antes['modelo'] !== $d['modelo'] || $antes['fabricante'] !== $d['fabricante'];
                try {
                    $n = Db::exec('UPDATE tab_zte_olt SET nome = ?, fabricante = ?, modelo = ?, host = ?, porta = ?, protocolo = ?,
                                          usuario = ?, timeout_conexao_s = ?, timeout_comando_s = ?, observacao = ?,
                                          versao = versao + 1, alterado_por = ?, alterado_em = NOW()'
                                  . ($mudouAcesso ? ", compatibilidade = 'desconhecida', ultimo_teste_resultado = NULL" : '')
                                  . ($senha !== '' ? ', falhas_auth = 0, bloqueado_ate = NULL' : '') .
                                  ' WHERE id = ? AND versao = ?',
                        [$d['nome'], $d['fabricante'], $d['modelo'], $d['host'], $d['porta'], $d['protocolo'], $d['usuario'],
                         $d['timeout_conexao_s'], $d['timeout_comando_s'], $d['observacao'], $usuario, $id, $versaoLida]);
                } catch (PDOException $ex) {
                    self::traduzirDuplicado($ex);
                }
                if ($n !== 1) {
                    throw new ZteErro('ZTE-CONC-001', [], null, 409);
                }
            }
            if ($senha !== '') {
                Cofre::guardar('olt', $id, $senha, $usuario);
            }
            if ($enable !== '') {
                Cofre::guardar('olt_enable', $id, $enable, $usuario);
            } elseif ($limparEnable) {
                Cofre::apagar('olt_enable', $id);
            }

            $depois = self::linha($id);
            Auditoria::registrar($antes === null ? 'olt_criar' : 'olt_alterar', 'olt', $id,
                $antes === null ? null : self::paraAuditoria($antes),
                // "credencial", nao "senha": campo com "senha" no nome e mascarado pelo Log::mascarar.
                self::paraAuditoria($depois) + ['credencial_trocada' => $senha !== '', 'enable_trocado' => $enable !== '' || $limparEnable]);
            return self::paraTela($depois);
        });
    }

    public static function definirAtivo(int $id, bool $ativo, string $usuario): array
    {
        $antes = self::linha($id);
        Db::exec('UPDATE tab_zte_olt SET ativo = ?, desativado_em = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                   WHERE id = ?', [$ativo ? 1 : 0, $ativo ? null : date('Y-m-d H:i:s'), $usuario, $id]);
        Auditoria::registrar($ativo ? 'olt_reativar' : 'olt_desativar', 'olt', $id,
            ['ativo' => (int) $antes['ativo']], ['ativo' => $ativo ? 1 : 0]);
        return self::obter($id);
    }

    /** Remove so OLT sem historico. Exige o nome digitado. */
    public static function remover(int $id, string $confirmacao, string $usuario): void
    {
        $olt = self::linha($id);
        if ($confirmacao !== $olt['nome']) {
            throw new ZteErro('ZTE-OLT-017');
        }
        $uso = (int) Db::valor('SELECT (SELECT COUNT(*) FROM tab_zte_onu WHERE olt_id = ?)
                                     + (SELECT COUNT(*) FROM tab_zte_campanha WHERE olt_id = ?)
                                     + (SELECT COUNT(*) FROM tab_zte_regra WHERE olt_id = ?)
                                     + (SELECT COUNT(*) FROM tab_zte_olt_repositorio WHERE olt_id = ?)', [$id, $id, $id, $id]);
        if ($uso > 0) {
            throw new ZteErro('ZTE-OLT-004', [], null, 409);
        }
        Db::transacao(function () use ($id, $olt) {
            Cofre::apagar('olt', $id);
            Cofre::apagar('olt_enable', $id);
            Db::exec('DELETE FROM tab_zte_olt WHERE id = ?', [$id]);
            Auditoria::registrar('olt_remover', 'olt', $id, self::paraAuditoria($olt), null);
        });
    }

    /**
     * Teste de acesso: conexao -> login -> identificacao -> compatibilidade.
     * Falha de rede/login NAO lanca: vira etapa com erro, gravada no historico.
     * Lanca ZteErro so quando o teste nem pode comecar (desativada, bloqueada, em uso).
     */
    public static function testar(int $id, string $usuario, ?Transporte $transporte = null): array
    {
        $olt = self::linha($id);
        if (!(int) $olt['ativo']) {
            throw new ZteErro('ZTE-OLT-011');
        }
        if ($olt['bloqueado_ate'] !== null && strtotime($olt['bloqueado_ate']) > time()) {
            throw new ZteErro('ZTE-OLT-005', ['ate' => $olt['bloqueado_ate']],
                Erros::mensagem('ZTE-OLT-005') . ' Nova tentativa a partir de ' . date('H:i', strtotime($olt['bloqueado_ate'])) . '.', 423);
        }
        $trava = 'zte_olt_' . $id;
        if (!Db::travar($trava, 0)) {
            throw new ZteErro('ZTE-OLT-014', [], null, 409);
        }

        $correlacao = 'TST-OLT' . $id . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
        $etapas = [];
        $ident = null;
        $compat = $olt['compatibilidade'];
        $driverId = $olt['driver'];
        $falhaAuth = false;
        $t = null;

        try {
            $t0 = microtime(true);
            try {
                $t = $transporte ?? RegistroOlt::transporte($olt);
                $t->conectar();
                $etapas[] = self::etapa('conexao', 'Conexão e login', 'ok',
                    'Conectado como ' . $olt['usuario'] . ' (' . $olt['protocolo'] . ').', $t0);
            } catch (OltFalha $f) {
                $falhaAuth = in_array($f->tipo(), ['autenticacao', 'modo_usuario'], true);
                $etapas[] = self::etapa('conexao', 'Conexão e login', 'erro', self::textoFalha($f), $t0);
            } catch (ZteErro $z) {
                $etapas[] = self::etapa('conexao', 'Conexão e login', 'erro', $z->getMessage(), $t0);
            }

            if (self::semErro($etapas)) {
                $t1 = microtime(true);
                $driver = RegistroOlt::driverPara($olt['fabricante'], $olt['modelo']);
                if ($driver === null) {
                    $compat = 'nao_suportada';
                    $etapas[] = self::etapa('identificacao', 'Identificação do equipamento', 'erro', Erros::mensagem('ZTE-OLT-012'), $t1);
                } else {
                    try {
                        $ident = (new $driver($t))->identificar();
                        $driverId = $driver::manifesto()['id'];
                        $etapas[] = self::etapa('identificacao', 'Identificação do equipamento', 'ok',
                            sprintf('%s, versão %s, %d placa(s).', $ident['identificador'] ?: 'sem nome', $ident['versao'], count($ident['placas'])), $t1);
                    } catch (OltFalha $f) {
                        $etapas[] = self::etapa('identificacao', 'Identificação do equipamento', 'erro', self::textoFalha($f), $t1);
                    }
                }

                if ($ident !== null) {
                    $compat = RegistroOlt::compatibilidade($driver, $ident['versao']);
                    $m = $driver::manifesto();
                    $etapas[] = self::etapa('compatibilidade', 'Compatibilidade do driver',
                        $compat === 'validada' ? 'ok' : 'aviso',
                        $compat === 'validada'
                            ? 'Versão ' . $ident['versao'] . ' testada com o driver ' . $m['nome'] . '.'
                            : 'Versão ' . $ident['versao'] . ' não está entre as testadas (' . implode(', ', $m['versoes_testadas']) . '): somente leitura.',
                        microtime(true));

                    $anterior = (string) ($olt['identificador_detectado'] ?? '');
                    if ($anterior !== '' && $ident['identificador'] !== '' && $anterior !== $ident['identificador']) {
                        $etapas[] = self::etapa('identidade', 'Mesmo equipamento do teste anterior', 'aviso',
                            'Antes respondia como "' . $anterior . '", agora como "' . $ident['identificador'] .
                            '". Confirme que o endereço aponta para a OLT certa.', microtime(true));
                    }
                }
            }
        } finally {
            if ($t !== null) {
                $t->fechar();
            }
            Db::destravar($trava);
        }

        $resultado = Diagnostico::pior($etapas);
        $resumo = implode(' | ', array_map(fn($e) => $e['titulo'] . ': ' . $e['detalhe'], array_filter($etapas, fn($e) => $e['resultado'] !== 'ok')))
               ?: 'Acesso e identificação ok.';

        Db::transacao(function () use ($id, $olt, $ident, $compat, $driverId, $resultado, $resumo, $falhaAuth, $etapas, $correlacao, $usuario) {
            $sets = ['ultimo_teste_em = NOW()', 'ultimo_teste_resultado = ?', 'ultimo_teste_detalhe = ?', 'compatibilidade = ?', 'driver = ?'];
            $p = [$resultado, mb_substr($resumo, 0, 500), $compat, $driverId];
            if ($ident !== null) {
                $sets[] = 'versao_detectada = ?';
                $sets[] = 'placas_detectadas = ?';
                $sets[] = 'identificador_detectado = ?';
                array_push($p, mb_substr($ident['versao'], 0, 60), json_encode($ident['placas'], JSON_UNESCAPED_UNICODE),
                    mb_substr($ident['identificador'], 0, 120));
            }
            if ($falhaAuth) {
                $falhas = (int) $olt['falhas_auth'] + 1;
                $sets[] = 'falhas_auth = ?';
                $p[] = $falhas;
                if ($falhas >= Config::int('bloqueio_auth_falhas')) {
                    $sets[] = 'bloqueado_ate = NOW() + INTERVAL ? MINUTE';
                    $p[] = Config::int('bloqueio_auth_min');
                }
            } elseif (self::semErro(array_slice($etapas, 0, 1))) {
                $sets[] = 'falhas_auth = 0';
                $sets[] = 'bloqueado_ate = NULL';
            }
            $p[] = $id;
            Db::exec('UPDATE tab_zte_olt SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);

            foreach ($etapas as $e) {
                Db::exec('INSERT INTO tab_zte_teste_conectividade (componente, alvo_tipo, alvo_id, etapa, resultado, detalhe,
                                 duracao_ms, correlacao, criado_por, criado_em)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    ['addon_olt', 'olt', $id, $e['etapa'], $e['resultado'], mb_substr($e['detalhe'], 0, 500), $e['ms'], $correlacao, $usuario]);
            }
            Auditoria::registrar('olt_testar', 'olt', $id, null,
                ['resultado' => $resultado, 'versao' => $ident['versao'] ?? null, 'compatibilidade' => $compat], $correlacao);
        });
        Log::info('olt.testar', ['olt' => $id, 'resultado' => $resultado, 'correlacao' => $correlacao]);

        $driverClasse = RegistroOlt::driverPorId($driverId);
        return [
            'resultado'       => $resultado,
            'etapas'          => $etapas,
            'versao'          => $ident['versao'] ?? null,
            'placas'          => $ident['placas'] ?? [],
            'identificador'   => $ident['identificador'] ?? null,
            'compatibilidade' => $compat,
            'manifesto'       => $driverClasse ? $driverClasse::manifesto() : null,
            'correlacao'      => $correlacao,
            'olt'             => self::obter($id),
        ];
    }

    /**
     * Abre a OLT, entrega o driver a $fn e fecha — sempre com a trava da OLT. Unico caminho para
     * qualquer outro modulo (teste de FTP, inventario) usar a OLT. Falha de login conta para o
     * bloqueio, igual ao teste de acesso.
     *
     * @param callable(DriverOlt):mixed $fn
     */
    public static function executarLeitura(int $id, callable $fn, ?Transporte $transporte = null)
    {
        // Dentro de emSessao() para a mesma OLT: reaproveita a conexao aberta (sem novo login).
        if (isset(self::$sessoes[$id])) {
            return $fn(self::$sessoes[$id]);
        }
        $olt = self::linha($id);
        if (!(int) $olt['ativo']) {
            throw new ZteErro('ZTE-OLT-011');
        }
        if ($olt['bloqueado_ate'] !== null && strtotime($olt['bloqueado_ate']) > time()) {
            throw new ZteErro('ZTE-OLT-005', ['ate' => $olt['bloqueado_ate']], null, 423);
        }
        if ($olt['compatibilidade'] === 'nao_suportada' || RegistroOlt::driverPara($olt['fabricante'], $olt['modelo']) === null) {
            throw new ZteErro('ZTE-OLT-012');
        }
        $trava = 'zte_olt_' . $id;
        if (!Db::travar($trava, 10)) {
            throw new ZteErro('ZTE-OLT-014', [], null, 409);
        }
        $t = null;
        try {
            $t = $transporte ?? RegistroOlt::transporte($olt);
            try {
                $t->conectar();
            } catch (OltFalha $f) {
                if (in_array($f->tipo(), ['autenticacao', 'modo_usuario'], true)) {
                    $falhas = (int) $olt['falhas_auth'] + 1;
                    $bloquear = $falhas >= Config::int('bloqueio_auth_falhas');
                    Db::exec('UPDATE tab_zte_olt SET falhas_auth = ?' . ($bloquear ? ', bloqueado_ate = NOW() + INTERVAL ? MINUTE' : '') . ' WHERE id = ?',
                        $bloquear ? [$falhas, Config::int('bloqueio_auth_min'), $id] : [$falhas, $id]);
                }
                throw $f;
            }
            self::$logins++;
            return $fn(RegistroOlt::criarDriver($olt, $t));
        } finally {
            if ($t !== null) {
                $t->fechar();
            }
            Db::destravar($trava);
        }
    }

    /** Folga exigida na flash alem do tamanho do firmware (a F670L de 24,7 MB passou com 280 KB). */
    public const MARGEM_FLASH = 262144;
    /** Por quanto tempo a ultima leitura do espaco vale (simulacao/aprovacao seguidas = 1 login). */
    public const FLASH_VALIDADE_S = 300;

    /**
     * Espaco livre na flash da OLT, do cache se recente, senao lido (1 login). null = nao foi
     * possivel ler (OLT fora, driver sem suporte, nenhuma pasta mostrou o espaco).
     * @return array{total:int,livre:int,lido_em:string}|null
     */
    public static function espacoFlash(int $id, bool $forcar = false): ?array
    {
        $o = self::linha($id);
        if (!$forcar && $o['flash_livre'] !== null && $o['flash_lido_em'] !== null
            && strtotime($o['flash_lido_em']) > time() - self::FLASH_VALIDADE_S) {
            return ['total' => (int) $o['flash_total'], 'livre' => (int) $o['flash_livre'], 'lido_em' => $o['flash_lido_em'],
                    'arquivos' => json_decode((string) ($o['flash_arquivos'] ?? ''), true) ?: []];
        }
        try {
            $e = self::executarLeitura($id, fn(DriverOlt $d) => $d->espacoFlash());
        } catch (Throwable $t) {
            Log::excecao('olt.flash', $t, ['olt' => $id]);
            return null;
        }
        return self::registrarFlash($id, $e);
    }

    /** Grava a leitura feita (pelo worker, dentro da sessao dele) e devolve no formato de espacoFlash(). */
    public static function registrarFlash(int $id, ?array $e): ?array
    {
        if ($e === null) {
            return null;
        }
        $e['arquivos'] = $e['arquivos'] ?? [];
        Db::exec('UPDATE tab_zte_olt SET flash_total = ?, flash_livre = ?, flash_arquivos = ?, flash_lido_em = NOW() WHERE id = ?',
            [$e['total'], $e['livre'], json_encode($e['arquivos']), $id]);
        return $e + ['lido_em' => date('Y-m-d H:i:s')];
    }

    /**
     * O firmware cabe? Se a MESMA imagem (nome e tamanho) ja esta na flash — a OLT guarda a ultima
     * baixada ate o aging-time (padrao 30 min) — nao exige espaco novo. Se mesmo assim a OLT
     * baixar de novo e faltar espaco, o summary-of acusa e o job falha na hora, sem tocar a ONU.
     * @return array{cabe:bool,ja_na_flash:bool}
     */
    public static function avaliarFlash(array $fl, string $arquivo, int $tamanho): array
    {
        $ja = ($fl['arquivos'][strtolower(basename($arquivo))] ?? null) === $tamanho;
        return ['cabe' => $ja || $tamanho + self::MARGEM_FLASH <= $fl['livre'], 'ja_na_flash' => $ja];
    }

    /** "24,9 MB" */
    public static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    /** Driver da sessao aberta por emSessao(), por OLT. */
    private static array $sessoes = [];
    /** Logins feitos por este processo (o worker mostra quantos fez por ciclo). */
    public static int $logins = 0;

    /**
     * Uma sessao (um login) para varias leituras seguidas: toda chamada a executarLeitura() da
     * mesma OLT dentro de $fn usa esta conexao. Menos logins = menos risco de esgotar as sessoes
     * de gerencia da OLT (ex.: inventario agendado, que le PON a PON).
     */
    public static function emSessao(int $id, callable $fn, ?Transporte $transporte = null)
    {
        if (isset(self::$sessoes[$id])) {
            return $fn();
        }
        return self::executarLeitura($id, function (DriverOlt $drv) use ($id, $fn) {
            self::$sessoes[$id] = $drv;
            try {
                return $fn();
            } finally {
                unset(self::$sessoes[$id]);
            }
        }, $transporte);
    }

    /** Ultimos testes, agrupados por execucao. */
    public static function historicoTestes(int $id, int $limite = 10): array
    {
        self::linha($id);
        return RepoServico::historico('addon_olt', 'olt', $id, $limite);
    }

    // ---------------------------------------------------------------- apoio

    public static function linha(int $id): array
    {
        $r = Db::um('SELECT * FROM tab_zte_olt WHERE id = ?', [$id]);
        if ($r === null) {
            throw new ZteErro('ZTE-OLT-001', [], null, 404);
        }
        return $r;
    }

    private static function paraTela(array $r): array
    {
        $id = (int) $r['id'];
        $r['id'] = $id;
        $r['porta'] = (int) $r['porta'];
        $r['ativo'] = (int) $r['ativo'];
        $r['versao'] = (int) $r['versao'];
        $r['placas_detectadas'] = $r['placas_detectadas'] ? json_decode($r['placas_detectadas'], true) : [];
        $r['tem_senha'] = Cofre::existe('olt', $id);
        $r['tem_enable'] = Cofre::existe('olt_enable', $id);
        $r['bloqueado'] = $r['bloqueado_ate'] !== null && strtotime($r['bloqueado_ate']) > time();
        return $r;
    }

    private static function paraAuditoria(array $r): array
    {
        return array_intersect_key($r, array_flip(['nome', 'fabricante', 'modelo', 'host', 'porta', 'protocolo', 'usuario',
            'timeout_conexao_s', 'timeout_comando_s', 'observacao', 'ativo']));
    }

    private static function etapa(string $etapa, string $titulo, string $resultado, string $detalhe, float $inicio): array
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

    private static function textoFalha(OltFalha $f): string
    {
        $tec = $f->detalhes()['tecnico'] ?? '';
        return $f->getMessage() . ($tec !== '' ? ' (' . Log::mascararTexto($tec) . ')' : '');
    }

    private static function traduzirDuplicado(PDOException $ex): void
    {
        if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
            throw new ZteErro('ZTE-OLT-002', [], null, 409);
        }
        throw $ex;
    }
}
