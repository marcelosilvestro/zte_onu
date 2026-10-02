<?php
/**
 * zte_onu :: biblioteca de firmwares.
 *
 * O ciclo de vida de um firmware, com estados que NUNCA se confundem:
 *
 *   hash_origem_calculado   o arquivo chegou ao addon e o SHA-256 foi calculado aqui
 *   enviado                 o arquivo esta no FTP (tamanho conferido)
 *   integridade_remota_verificada  o arquivo foi LIDO DE VOLTA do FTP e o SHA-256 bate
 *   disponivel              integridade ok + ao menos uma compatibilidade modelo x HW
 *
 *   invalido        o arquivo no FTP nao confere com o original
 *   ausente_no_ftp  o arquivo sumiu do FTP (reverificacao ou sincronizacao)
 *   desativado      retirado de uso pelo operador (o historico fica)
 *
 * Regras:
 *   - nada e sobrescrito no FTP: nome que ja existe na pasta e recusado;
 *   - envio com nome temporario + rename, quando o repositorio permite renomear: um upload
 *     interrompido nunca deixa um arquivo pela metade com o nome final;
 *   - com a politica "tamanho", o firmware pode ficar disponivel sem leitura de volta, mas a
 *     tela mostra que a integridade remota so foi conferida por tamanho;
 *   - firmware adotado de um arquivo que ja estava no FTP nao tem hash de origem independente:
 *     so fica disponivel com confirmacao explicita (ou com o SHA-256 do fabricante informado);
 *   - o arquivo temporario do upload e apagado em qualquer desfecho.
 */
require_once __DIR__ . '/../Core/carregar.php';

final class FirmwareServico
{
    public const EXTENSOES = ['bin', 'img', 'tar', 'gz', 'tgz', 'zip', 'pkg'];
    private const RE_MODELO = '/^[A-Za-z0-9][A-Za-z0-9._ \/+-]{0,59}$/';

    /** Pasta onde o upload espera antes de ir ao FTP (AppArmor: /opt/mk-auth/dados e gravavel). */
    public static string $dirTemporario = ZTE_DIR_DADOS . '/tmp';

    // ================================================================ consulta

    public static function listar(): array
    {
        $fws = Db::todos('SELECT f.*, r.nome AS repositorio_nome FROM tab_zte_firmware f
                            JOIN tab_zte_repositorio r ON r.id = f.repositorio_id
                        ORDER BY f.estado = \'desativado\', f.modelo_familia, f.versao_firmware');
        $compat = [];
        foreach (Db::todos('SELECT firmware_id, modelo, hw_versao FROM tab_zte_firmware_compat ORDER BY modelo, hw_versao') as $c) {
            $compat[(int) $c['firmware_id']][] = ['modelo' => $c['modelo'], 'hw_versao' => $c['hw_versao']];
        }
        return array_map(fn($f) => self::paraTela($f, $compat[(int) $f['id']] ?? []), $fws);
    }

    public static function obter(int $id): array
    {
        $f = self::linha($id);
        $f['repositorio_nome'] = Db::valor('SELECT nome FROM tab_zte_repositorio WHERE id = ?', [$f['repositorio_id']]);
        $saida = self::paraTela($f, self::compat($id));
        $saida['verificacoes'] = Db::todos('SELECT tipo, resultado, tamanho_remoto, sha256_calculado, detalhe, correlacao, criado_por, criado_em
                                              FROM tab_zte_firmware_verificacao WHERE firmware_id = ? ORDER BY id DESC LIMIT 50', [$id]);
        return $saida;
    }

    // ================================================================ envio

    /**
     * Envia um arquivo local (ja recebido do navegador) ao FTP e cadastra o firmware.
     * $local e SEMPRE apagado ao final.
     *
     * @param array $m fabricante, modelo_familia, versao_firmware, pasta (relativa a raiz), nome_remoto,
     *                 observacao, compat[] = [{modelo, hw_versao}]
     */
    public static function enviar(int $repoId, string $local, string $nomeOriginal, array $m, string $usuario): array
    {
        try {
            $repo = RepoServico::linha($repoId);
            if (!RepoServico::operacaoLiberada($repo, 'enviar')) {
                throw new ZteErro('ZTE-FW-006', [], null, 409);
            }
            RepoServico::exigirOperacao($repo, 'enviar');

            $meta = self::validarMeta($m);
            $nomeRemoto = Validar::nomeArquivo($m['nome_remoto'] ?? '');
            self::exigirExtensao($nomeRemoto);
            $tamanho = is_file($local) ? (int) filesize($local) : 0;
            if ($tamanho <= 0 || $tamanho > Config::int('firmware_max_mb') * 1048576) {
                throw new ZteErro('ZTE-FW-003', ['max_mb' => Config::int('firmware_max_mb')]);
            }
            $pasta = Validar::caminhoRelativo($m['pasta'] ?? '');
            $caminho = Validar::dentroDaRaiz($repo['raiz'], ($pasta === '' ? '' : $pasta . '/') . $nomeRemoto);
            if ((int) Db::valor('SELECT COUNT(*) FROM tab_zte_firmware WHERE repositorio_id = ? AND caminho_remoto = ?', [$repoId, $caminho])) {
                throw new ZteErro('ZTE-FW-005', [], null, 409);
            }
            $compat = self::validarCompat($m['compat'] ?? []);
            $shaOrigem = hash_file('sha256', $local);
            $correlacao = 'FW-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

            // 1. Cadastro com o hash da origem, antes de tocar o FTP.
            $id = Db::transacao(function () use ($repoId, $meta, $nomeOriginal, $nomeRemoto, $caminho, $tamanho, $shaOrigem, $usuario, $compat) {
                Db::exec("INSERT INTO tab_zte_firmware (fabricante, modelo_familia, versao_firmware, nome_original, nome_remoto, repositorio_id,
                                 caminho_remoto, tamanho_bytes, sha256_origem, estado, observacao, criado_por, criado_em)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'hash_origem_calculado', ?, ?, NOW())",
                    [$meta['fabricante'], $meta['modelo_familia'], $meta['versao_firmware'], mb_substr($nomeOriginal, 0, 160),
                     $nomeRemoto, $repoId, $caminho, $tamanho, $shaOrigem, $meta['observacao'], $usuario]);
                $id = Db::ultimoId();
                self::gravarCompat($id, $compat);
                return $id;
            });

            // 2. Envio: nome temporario + rename quando der; nunca sobrescreve.
            try {
                RepoServico::comCliente($repo, function (ClienteFtp $c) use ($repo, $local, $caminho) {
                    if ($c->tamanho($caminho) !== null) {
                        throw new ZteErro('ZTE-FW-004', [], null, 409);
                    }
                    if (RepoServico::operacaoLiberada($repo, 'renomear')) {
                        $parcial = dirname($caminho) . '/zte_onu_parcial_' . bin2hex(random_bytes(4)) . '.tmp';
                        $c->enviar($local, $parcial);
                        try {
                            $c->renomear($parcial, $caminho);
                        } catch (Throwable $e) {
                            try {
                                $c->excluir($parcial);
                            } catch (Throwable $ignorar) {
                            }
                            throw $e;
                        }
                    } else {
                        $c->enviar($local, $caminho);
                    }
                }, true);
            } catch (Throwable $e) {
                Db::exec('DELETE FROM tab_zte_firmware WHERE id = ?', [$id]);
                Auditoria::registrar('firmware_envio_falhou', 'firmware', null, null,
                    ['caminho' => $caminho, 'erro' => $e instanceof ZteErro ? $e->codigo() : get_class($e)], $correlacao);
                if ($e instanceof ZteErro && $e->codigo() === 'ZTE-FW-004') {
                    throw $e;
                }
                Log::excecao('firmware.enviar', $e, ['caminho' => $caminho]);
                $tec = $e instanceof ZteErro ? ($e->detalhes()['tecnico'] ?? $e->getMessage()) : '';
                throw new ZteErro('ZTE-FW-007', $tec !== '' ? ['tecnico' => $tec] : [], null, 502);
            }
            Db::exec("UPDATE tab_zte_firmware SET estado = 'enviado' WHERE id = ?", [$id]);
            Auditoria::registrar('firmware_enviar', 'firmware', $id, null,
                ['caminho' => $caminho, 'tamanho' => $tamanho, 'sha256' => $shaOrigem, 'modelo' => $meta['modelo_familia'],
                 'versao' => $meta['versao_firmware'], 'compat' => $compat], $correlacao);

            // 3. Verificacao do arquivo remoto e promocao.
            self::verificar($id, 'upload', $usuario, $correlacao);
            self::promoverSePuder($id, $usuario, false);
            return self::obter($id);
        } finally {
            if (is_file($local)) {
                @unlink($local);
            }
        }
    }

    /**
     * Cadastra um arquivo que JA esta no FTP (orfao da sincronizacao). Sem hash de origem: o
     * SHA-256 vem da leitura do proprio FTP, a nao ser que o operador informe o do fabricante.
     */
    public static function adotar(int $repoId, string $relativo, array $m, string $usuario): array
    {
        $repo = RepoServico::linha($repoId);
        RepoServico::exigirOperacao($repo, 'listar');
        $meta = self::validarMeta($m);
        $esperado = strtolower(trim((string) ($m['sha256_esperado'] ?? '')));
        if ($esperado !== '' && !preg_match('/^[0-9a-f]{64}$/', $esperado)) {
            throw new ZteErro('ZTE-FW-015');
        }
        $rel = Validar::caminhoRelativo($relativo);
        if ($rel === '') {
            throw new ZteErro('ZTE-VAL-004');
        }
        $nome = Validar::nomeArquivo(basename($rel));
        self::exigirExtensao($nome);
        $caminho = Validar::dentroDaRaiz($repo['raiz'], $rel);
        if ((int) Db::valor('SELECT COUNT(*) FROM tab_zte_firmware WHERE repositorio_id = ? AND caminho_remoto = ?', [$repoId, $caminho])) {
            throw new ZteErro('ZTE-FW-005', [], null, 409);
        }
        $compat = self::validarCompat($m['compat'] ?? []);
        $tam =RepoServico::comCliente($repo, fn(ClienteFtp $c) => $c->tamanho($caminho));
        if ($tam === null) {
            throw new ZteErro('ZTE-FW-009', [], null, 404);
        }
        $correlacao = 'FW-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $id = Db::transacao(function () use ($repoId, $meta, $nome, $caminho, $tam, $esperado, $usuario, $compat, $correlacao) {
            Db::exec("INSERT INTO tab_zte_firmware (fabricante, modelo_familia, versao_firmware, nome_original, nome_remoto, repositorio_id,
                             caminho_remoto, tamanho_bytes, sha256_origem, hash_sem_origem, estado, observacao, criado_por, criado_em)
                      VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 'enviado', ?, ?, NOW())",
                [$meta['fabricante'], $meta['modelo_familia'], $meta['versao_firmware'], $nome, $repoId, $caminho, $tam,
                 $esperado !== '' ? $esperado : null, $esperado === '' ? 1 : 0, $meta['observacao'], $usuario]);
            $id = Db::ultimoId();
            self::gravarCompat($id, $compat);
            Auditoria::registrar('firmware_adotar', 'firmware', $id, null,
                ['caminho' => $caminho, 'tamanho' => $tam, 'sha256_fabricante' => $esperado ?: null, 'compat' => $compat], $correlacao);
            return $id;
        });
        self::verificar($id, 'upload', $usuario, $correlacao);
        self::promoverSePuder($id, $usuario, false);
        return self::obter($id);
    }

    // ================================================================ verificacao

    /**
     * Le o arquivo do FTP e compara com o esperado. Atualiza o estado:
     *   ok (completa)  -> integridade_remota_verificada (ou mantem disponivel)
     *   ok (tamanho)   -> mantem enviado/disponivel, registra que so o tamanho foi conferido
     *   divergente     -> invalido        ausente -> ausente_no_ftp
     */
    public static function verificar(int $id, string $tipo, string $usuario, ?string $correlacao = null): array
    {
        $f = self::linha($id);
        $repo = RepoServico::linha((int) $f['repositorio_id']);
        $correlacao ??= 'FW-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $politica = Config::get('integridade_politica');
        $res = ['resultado' => 'erro', 'tamanho' => null, 'sha256' => null, 'detalhe' => ''];

        try {
            RepoServico::comCliente($repo, function (ClienteFtp $c) use ($f, $politica, &$res) {
                $tam = $c->tamanho($f['caminho_remoto']);
                $res['tamanho'] = $tam;
                if ($tam === null) {
                    $res['resultado'] = 'ausente';
                    return;
                }
                if ($f['tamanho_bytes'] !== null && (int) $f['tamanho_bytes'] !== $tam && !(int) $f['hash_sem_origem']) {
                    $res['resultado'] = 'divergente';
                    $res['detalhe'] = sprintf('Tamanho no FTP %d bytes, esperado %d.', $tam, (int) $f['tamanho_bytes']);
                    return;
                }
                if ($politica !== 'completa' && $f['sha256_origem'] !== null) {
                    $res['resultado'] = 'ok_tamanho';
                    return;
                }
                $s = $c->baixar($f['caminho_remoto']);
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $s);
                fclose($s);
                $res['sha256'] = hash_final($ctx);
                $referencia = $f['sha256_origem'];
                if ($referencia === null) {
                    $res['resultado'] = 'ok_sem_origem';
                } elseif (hash_equals($referencia, $res['sha256'])) {
                    $res['resultado'] = 'ok';
                } else {
                    $res['resultado'] = 'divergente';
                    $res['detalhe'] = 'SHA-256 do arquivo no FTP difere do original.';
                }
            });
        } catch (ZteErro $e) {
            $res['detalhe'] = $e->getMessage() . (isset($e->detalhes()['tecnico']) ? ' (' . $e->detalhes()['tecnico'] . ')' : '');
        }

        $estadoAntes = $f['estado'];
        $novo = $estadoAntes;
        $resultado = 'ok';
        switch ($res['resultado']) {
            case 'ok':
                $detalhe = 'Arquivo lido de volta do FTP: SHA-256 confere.';
                if (!in_array($estadoAntes, ['disponivel', 'desativado'], true)) {
                    $novo = 'integridade_remota_verificada';
                }
                break;
            case 'ok_sem_origem':
                $detalhe = 'SHA-256 calculado a partir do FTP (sem hash de origem independente).';
                $resultado = 'aviso';
                break;
            case 'ok_tamanho':
                $detalhe = 'Política "tamanho": conferido só o tamanho (' . $res['tamanho'] . ' bytes), sem leitura de volta.';
                $resultado = 'aviso';
                if (in_array($estadoAntes, ['ausente_no_ftp', 'hash_origem_calculado'], true)) {
                    $novo = 'enviado';
                }
                break;
            case 'ausente':
                $detalhe = Erros::mensagem('ZTE-FW-009');
                $resultado = 'erro';
                if ($estadoAntes !== 'desativado') {
                    $novo = 'ausente_no_ftp';
                }
                break;
            case 'divergente':
                $detalhe = $res['detalhe'];
                $resultado = 'erro';
                if ($estadoAntes !== 'desativado') {
                    $novo = 'invalido';
                }
                break;
            default:
                // Falha de comunicacao: nao muda o estado — nao da para concluir nada sobre o arquivo.
                $detalhe = 'Não foi possível verificar: ' . $res['detalhe'];
                $resultado = 'erro';
        }

        Db::transacao(function () use ($id, $tipo, $resultado, $res, $detalhe, $correlacao, $usuario, $novo, $estadoAntes, $f) {
            Db::exec('INSERT INTO tab_zte_firmware_verificacao (firmware_id, tipo, resultado, tamanho_remoto, sha256_calculado, detalhe, correlacao, criado_por, criado_em)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$id, $tipo, $resultado, $res['tamanho'], $res['sha256'], mb_substr($detalhe, 0, 500), $correlacao, $usuario]);
            $sets = ['verificado_em = NOW()'];
            $p = [];
            if ($res['sha256'] !== null) {
                $sets[] = 'sha256_remoto = ?';
                $p[] = $res['sha256'];
            }
            if ($res['resultado'] === 'ok_sem_origem' && $f['tamanho_bytes'] === null) {
                $sets[] = 'tamanho_bytes = ?';
                $p[] = $res['tamanho'];
            }
            if ($novo !== $estadoAntes) {
                $sets[] = 'estado = ?';
                $p[] = $novo;
            }
            $p[] = $id;
            Db::exec('UPDATE tab_zte_firmware SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);
            if ($novo !== $estadoAntes) {
                Auditoria::registrar('firmware_estado', 'firmware', $id, ['estado' => $estadoAntes], ['estado' => $novo, 'motivo' => $detalhe], $correlacao);
            }
        });
        return ['resultado' => $resultado, 'detalhe' => $detalhe, 'estado' => $novo, 'correlacao' => $correlacao];
    }

    // ================================================================ disponibilizar

    /**
     * Torna o firmware disponivel para campanhas. Exige compatibilidade e integridade:
     *   - verificada por leitura de volta, ou
     *   - so tamanho, quando a politica configurada e "tamanho", ou
     *   - hash calculado do FTP (sem origem), com confirmacao explicita.
     */
    public static function disponibilizar(int $id, string $usuario, bool $aceitarSemOrigem): array
    {
        $f = self::linha($id);
        if ($f['estado'] === 'desativado') {
            throw new ZteErro('ZTE-FW-017');
        }
        if (!self::compat($id)) {
            throw new ZteErro('ZTE-FW-011');
        }
        $ok = self::integridadeAceita($f);
        if (!$ok && (int) $f['hash_sem_origem'] && $f['sha256_remoto'] !== null && $f['estado'] === 'enviado') {
            if (!$aceitarSemOrigem) {
                throw new ZteErro('ZTE-FW-013');
            }
            $ok = true;
        }
        if (!$ok) {
            throw new ZteErro('ZTE-FW-012');
        }
        Db::exec("UPDATE tab_zte_firmware SET estado = 'disponivel', versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?", [$usuario, $id]);
        Auditoria::registrar('firmware_disponibilizar', 'firmware', $id, ['estado' => $f['estado']],
            ['estado' => 'disponivel', 'aceito_sem_origem' => $aceitarSemOrigem && (int) $f['hash_sem_origem'] === 1]);
        return self::obter($id);
    }

    public static function definirCompat(int $id, array $lista, string $usuario): array
    {
        $f = self::linha($id);
        self::exigirForaDeCampanhaAtiva($id);
        $nova = self::validarCompat($lista);
        $antes = self::compat($id);
        Db::transacao(function () use ($id, $nova, $f, $usuario, $antes) {
            Db::exec('DELETE FROM tab_zte_firmware_compat WHERE firmware_id = ?', [$id]);
            self::gravarCompat($id, $nova);
            // Sem compatibilidade, deixa de ser disponivel.
            if (!$nova && $f['estado'] === 'disponivel') {
                Db::exec("UPDATE tab_zte_firmware SET estado = ? WHERE id = ?",
                    [$f['sha256_remoto'] !== null && !(int) $f['hash_sem_origem'] ? 'integridade_remota_verificada' : 'enviado', $id]);
            }
            Db::exec('UPDATE tab_zte_firmware SET versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?', [$usuario, $id]);
            Auditoria::registrar('firmware_compat', 'firmware', $id, ['compat' => $antes], ['compat' => $nova]);
        });
        self::promoverSePuder($id, $usuario, false);
        return self::obter($id);
    }

    public static function editar(int $id, array $m, string $usuario): array
    {
        $f = self::linha($id);
        self::exigirForaDeCampanhaAtiva($id);
        $meta = self::validarMeta($m);
        $versaoLida = Validar::inteiro($m['versao'] ?? 0, 1, PHP_INT_MAX);
        $n = Db::exec('UPDATE tab_zte_firmware SET fabricante = ?, modelo_familia = ?, versao_firmware = ?, observacao = ?,
                              versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ? AND versao = ?',
            [$meta['fabricante'], $meta['modelo_familia'], $meta['versao_firmware'], $meta['observacao'], $usuario, $id, $versaoLida]);
        if ($n !== 1) {
            throw new ZteErro('ZTE-CONC-001', [], null, 409);
        }
        Auditoria::registrar('firmware_editar', 'firmware', $id,
            array_intersect_key($f, $meta), $meta);
        return self::obter($id);
    }

    public static function desativar(int $id, string $usuario): array
    {
        $f = self::linha($id);
        self::exigirForaDeCampanhaAtiva($id);
        Db::exec("UPDATE tab_zte_firmware SET estado = 'desativado', versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?", [$usuario, $id]);
        Auditoria::registrar('firmware_desativar', 'firmware', $id, ['estado' => $f['estado']], ['estado' => 'desativado']);
        return self::obter($id);
    }

    /** Reativar volta para "enviado" e reverifica: o arquivo pode ter mudado enquanto esteve fora de uso. */
    public static function reativar(int $id, string $usuario): array
    {
        $f = self::linha($id);
        if ($f['estado'] !== 'desativado') {
            return self::obter($id);
        }
        Db::exec("UPDATE tab_zte_firmware SET estado = 'enviado', versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?", [$usuario, $id]);
        Auditoria::registrar('firmware_reativar', 'firmware', $id, ['estado' => 'desativado'], ['estado' => 'enviado']);
        self::verificar($id, 'reverificacao', $usuario);
        self::promoverSePuder($id, $usuario, false);
        return self::obter($id);
    }

    /**
     * Exclui o cadastro (e, se pedido e permitido, o arquivo no FTP). So sem regras/campanhas.
     */
    public static function excluir(int $id, string $confirmacao, bool $apagarRemoto, string $usuario): void
    {
        $f = self::linha($id);
        if ($confirmacao !== $f['nome_remoto']) {
            throw new ZteErro('ZTE-FW-014');
        }
        $uso = (int) Db::valor('SELECT (SELECT COUNT(*) FROM tab_zte_regra WHERE firmware_id = ?) + (SELECT COUNT(*) FROM tab_zte_campanha WHERE firmware_id = ?)', [$id, $id]);
        if ($uso > 0) {
            throw new ZteErro('ZTE-FW-010', [], null, 409);
        }
        $repo = RepoServico::linha((int) $f['repositorio_id']);
        if ($apagarRemoto) {
            RepoServico::exigirOperacao($repo, 'excluir');
        }
        Db::transacao(function () use ($id, $f) {
            Db::exec('DELETE FROM tab_zte_firmware_verificacao WHERE firmware_id = ?', [$id]);
            Db::exec('UPDATE tab_zte_sincronizacao_item SET firmware_id = NULL WHERE firmware_id = ?', [$id]);
            Db::exec('DELETE FROM tab_zte_firmware WHERE id = ?', [$id]);
            Auditoria::registrar('firmware_excluir', 'firmware', $id,
                array_intersect_key($f, array_flip(['modelo_familia', 'versao_firmware', 'caminho_remoto', 'sha256_origem', 'estado'])), null);
        });
        if ($apagarRemoto) {
            try {
                RepoServico::comCliente($repo, fn(ClienteFtp $c) => $c->excluir($f['caminho_remoto']), true);
                Auditoria::registrar('repositorio_excluir_arquivo', 'repositorio', (int) $repo['id'], ['caminho' => $f['caminho_remoto']], null);
            } catch (FtpFalha $e) {
                if ($e->tipo() !== 'nao_encontrado') {
                    throw $e;
                }
            }
        }
    }

    /** Chamado pela sincronizacao do repositorio: so marca estado, nunca apaga. */
    public static function marcarPelaSincronizacao(int $id, string $tipo, string $usuario): void
    {
        $f = Db::um('SELECT id, estado FROM tab_zte_firmware WHERE id = ?', [$id]);
        if ($f === null || $f['estado'] === 'desativado') {
            return;
        }
        $novo = $tipo === 'ausente_no_ftp' ? 'ausente_no_ftp' : 'invalido';
        if ($f['estado'] === $novo) {
            return;
        }
        Db::exec('UPDATE tab_zte_firmware SET estado = ? WHERE id = ?', [$novo, $id]);
        Db::exec("INSERT INTO tab_zte_firmware_verificacao (firmware_id, tipo, resultado, detalhe, criado_por, criado_em)
                  VALUES (?, 'sincronizacao', 'erro', ?, ?, NOW())",
            [$id, $tipo === 'ausente_no_ftp' ? 'Arquivo ausente no FTP na sincronização.' : 'Tamanho no FTP diferente do cadastro na sincronização.', $usuario]);
        Auditoria::registrar('firmware_estado', 'firmware', $id, ['estado' => $f['estado']], ['estado' => $novo, 'motivo' => 'sincronizacao']);
    }

    // ================================================================ apoio

    public static function linha(int $id): array
    {
        $f = Db::um('SELECT * FROM tab_zte_firmware WHERE id = ?', [$id]);
        if ($f === null) {
            throw new ZteErro('ZTE-FW-001', [], null, 404);
        }
        return $f;
    }

    /** @return array<int,array{modelo:string,hw_versao:string}> */
    public static function compat(int $id): array
    {
        return Db::todos('SELECT modelo, hw_versao FROM tab_zte_firmware_compat WHERE firmware_id = ? ORDER BY modelo, hw_versao', [$id]);
    }

    private static function promoverSePuder(int $id, string $usuario, bool $aceitarSemOrigem): void
    {
        $f = self::linha($id);
        if ($f['estado'] !== 'disponivel' && self::compat($id) && self::integridadeAceita($f)) {
            self::disponibilizar($id, $usuario, $aceitarSemOrigem);
        }
    }

    private static function integridadeAceita(array $f): bool
    {
        if ($f['estado'] === 'integridade_remota_verificada') {
            return true;
        }
        // Politica "tamanho": enviado com hash de origem e tamanho conferido basta.
        return $f['estado'] === 'enviado' && Config::get('integridade_politica') === 'tamanho'
            && $f['sha256_origem'] !== null && !(int) $f['hash_sem_origem'];
    }

    private static function exigirForaDeCampanhaAtiva(int $id): void
    {
        $n = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_campanha WHERE firmware_id = ? AND estado IN ('aprovada','executando','pausada')", [$id]);
        if ($n > 0) {
            throw new ZteErro('ZTE-FW-019', [], null, 409);
        }
    }

    private static function exigirExtensao(string $nome): void
    {
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSOES, true)) {
            throw new ZteErro('ZTE-FW-002', ['aceitas' => self::EXTENSOES]);
        }
    }

    private static function validarMeta(array $m): array
    {
        return [
            'fabricante'      => Validar::texto($m['fabricante'] ?? 'ZTE', 40, true),
            'modelo_familia'  => Validar::nome($m['modelo_familia'] ?? '', 60),
            'versao_firmware' => Validar::nome($m['versao_firmware'] ?? '', 80),
            'observacao'      => Validar::texto($m['observacao'] ?? '', 500),
        ];
    }

    /** @return array<int,array{modelo:string,hw_versao:string}> sem duplicados */
    private static function validarCompat($lista): array
    {
        if (!is_array($lista)) {
            return [];
        }
        $saida = [];
        foreach ($lista as $c) {
            if (!is_array($c)) {
                continue;
            }
            $mod = trim((string) ($c['modelo'] ?? ''));
            $hw = trim((string) ($c['hw_versao'] ?? ''));
            if ($mod === '' && $hw === '') {
                continue;
            }
            if (!preg_match(self::RE_MODELO, $mod) || !preg_match(self::RE_MODELO, $hw)) {
                throw new ZteErro('ZTE-FW-018', ['modelo' => mb_substr($mod, 0, 60), 'hw_versao' => mb_substr($hw, 0, 60)]);
            }
            $saida[strtoupper($mod) . '|' . strtoupper($hw)] = ['modelo' => $mod, 'hw_versao' => $hw];
        }
        return array_values($saida);
    }

    private static function gravarCompat(int $id, array $compat): void
    {
        foreach ($compat as $c) {
            Db::exec('INSERT INTO tab_zte_firmware_compat (firmware_id, modelo, hw_versao) VALUES (?, ?, ?)', [$id, $c['modelo'], $c['hw_versao']]);
        }
    }

    private static function paraTela(array $f, array $compat): array
    {
        foreach (['id', 'repositorio_id', 'hash_sem_origem', 'versao'] as $c) {
            $f[$c] = (int) $f[$c];
        }
        $f['tamanho_bytes'] = $f['tamanho_bytes'] === null ? null : (int) $f['tamanho_bytes'];
        $f['compat'] = $compat;
        $f['integridade'] = $f['sha256_remoto'] !== null && $f['sha256_origem'] !== null && hash_equals($f['sha256_origem'], $f['sha256_remoto'])
            ? 'verificada' : ($f['sha256_remoto'] !== null ? 'calculada_no_ftp' : ($f['estado'] === 'enviado' || $f['estado'] === 'disponivel' ? 'so_tamanho' : 'pendente'));
        $f['pode_disponibilizar'] = $f['estado'] !== 'disponivel' && $f['estado'] !== 'desativado' && $compat
            && (self::integridadeAceita($f) || ($f['hash_sem_origem'] && $f['sha256_remoto'] !== null && $f['estado'] === 'enviado'));
        return $f;
    }
}
