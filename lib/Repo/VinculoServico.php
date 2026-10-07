<?php
/**
 * zte_onu :: como a OLT enxerga um repositorio (tab_zte_olt_repositorio).
 *
 * A OLT pode chegar ao FTP por outro endereco (VLAN de gerencia, NAT) e usar outra conta que
 * o addon. Por isso este cadastro e separado do repositorio, com credencial propria no cofre
 * (tipo 'olt_repo').
 *
 * Os quatro estados da conectividade OLT -> FTP, sem nunca presumir sucesso:
 *   potencial     a OLT alcanca o endereco do FTP (ping a partir da OLT)
 *   validado      a OLT baixou um arquivo de fato (upgrade real concluido); o teste nao rebaixa
 *   desconhecido  nao deu para concluir (ping sem resposta pode ser so ICMP bloqueado)
 *   nao_testavel  o driver nao tem como testar
 */
require_once __DIR__ . '/RepoServico.php';
require_once __DIR__ . '/../Olt/OltServico.php';

final class VinculoServico
{
    public static function listar(): array
    {
        $linhas = Db::todos('SELECT v.*, o.nome AS olt_nome, o.ativo AS olt_ativa, r.nome AS repo_nome
                               FROM tab_zte_olt_repositorio v
                               JOIN tab_zte_olt o ON o.id = v.olt_id
                               JOIN tab_zte_repositorio r ON r.id = v.repositorio_id
                           ORDER BY o.nome, r.nome');
        return array_map(function ($v) {
            foreach (['id', 'olt_id', 'repositorio_id', 'porta_olt', 'versao', 'olt_ativa'] as $c) {
                $v[$c] = (int) $v[$c];
            }
            $v['tem_senha'] = Cofre::existe('olt_repo', $v['id']);
            return $v;
        }, $linhas);
    }

    public static function salvar(array $e, string $usuario): array
    {
        $id = (int) ($e['id'] ?? 0);
        $hostOlt = Validar::host($e['host_olt'] ?? '');
        if (filter_var($hostOlt, FILTER_VALIDATE_IP) === false) {
            throw new ZteErro('ZTE-VIN-003');
        }
        $d = [
            'host_olt'    => $hostOlt,
            'porta_olt'   => Validar::porta($e['porta_olt'] ?? 21),
            'usuario_olt' => Validar::usuarioRemoto($e['usuario_olt'] ?? ''),
            'caminho_olt' => Validar::raiz($e['caminho_olt'] ?? '/'),
        ];
        $senha = (string) ($e['senha_olt'] ?? '');
        $senha = $senha !== '' ? Validar::senhaRemota($senha) : '';

        return Db::transacao(function () use ($id, $d, $senha, $usuario, $e) {
            if ($id === 0) {
                $olt = OltServico::linha(Validar::inteiro($e['olt_id'] ?? 0, 1, PHP_INT_MAX));
                $repo = RepoServico::linha(Validar::inteiro($e['repositorio_id'] ?? 0, 1, PHP_INT_MAX));
                if ($senha === '') {
                    throw new ZteErro('ZTE-VIN-005');
                }
                try {
                    Db::exec('INSERT INTO tab_zte_olt_repositorio (olt_id, repositorio_id, host_olt, porta_olt, usuario_olt, caminho_olt, criado_por, criado_em)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                        [$olt['id'], $repo['id'], $d['host_olt'], $d['porta_olt'], $d['usuario_olt'], $d['caminho_olt'], $usuario]);
                } catch (PDOException $ex) {
                    if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                        throw new ZteErro('ZTE-VIN-002', [], null, 409);
                    }
                    throw $ex;
                }
                $id = Db::ultimoId();
                $antes = null;
            } else {
                $antes = self::linha($id);
                $versaoLida = Validar::inteiro($e['versao'] ?? 0, 1, PHP_INT_MAX);
                $n = Db::exec("UPDATE tab_zte_olt_repositorio SET host_olt = ?, porta_olt = ?, usuario_olt = ?, caminho_olt = ?,
                                      estado_conectividade = 'desconhecido', estado_em = NULL, estado_detalhe = NULL,
                                      versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                                WHERE id = ? AND versao = ?",
                    [$d['host_olt'], $d['porta_olt'], $d['usuario_olt'], $d['caminho_olt'], $usuario, $id, $versaoLida]);
                if ($n !== 1) {
                    throw new ZteErro('ZTE-CONC-001', [], null, 409);
                }
            }
            if ($senha !== '') {
                Cofre::guardar('olt_repo', $id, $senha, $usuario);
            }
            $depois = self::linha($id);
            Auditoria::registrar($antes === null ? 'acesso_olt_ftp_criar' : 'acesso_olt_ftp_alterar', 'olt_repositorio', $id,
                $antes === null ? null : self::paraAuditoria($antes), self::paraAuditoria($depois) + ['credencial_trocada' => $senha !== '']);
            return $depois;
        });
    }

    public static function remover(int $id, string $usuario): void
    {
        $v = self::linha($id);
        Db::transacao(function () use ($id, $v) {
            Cofre::apagar('olt_repo', $id);
            Db::exec('DELETE FROM tab_zte_olt_repositorio WHERE id = ?', [$id]);
            Auditoria::registrar('acesso_olt_ftp_remover', 'olt_repositorio', $id, self::paraAuditoria($v), null);
        });
    }

    /**
     * Teste a partir da OLT. Hoje: ping (estado "potencial"). O download de verdade fica
     * registrado como nao testavel ate o comando ser validado na OLT real.
     */
    public static function testar(int $id, string $usuario): array
    {
        $v = self::linha($id);
        $olt = OltServico::linha((int) $v['olt_id']);
        $correlacao = 'TST-VIN' . $id . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
        $etapas = [];
        $estado = 'desconhecido';
        $driver = RegistroOlt::driverPorId($olt['driver']) ?? RegistroOlt::driverPara($olt['fabricante'], $olt['modelo']);

        $t = microtime(true);
        $etapas[] = RepoServico::etapa('credencial', 'Credencial da OLT no FTP',
            Cofre::existe('olt_repo', $id) ? 'ok' : 'erro',
            Cofre::existe('olt_repo', $id) ? 'Cadastrada (usuário ' . $v['usuario_olt'] . ').' : Erros::mensagem('ZTE-VIN-005'), $t);

        if ($driver === null || $driver::nivel('teste_ftp_olt') === 'indisponivel' || !method_exists($driver, 'ping')) {
            $estado = 'nao_testavel';
            $etapas[] = RepoServico::etapa('alcance', 'OLT alcança o FTP', 'nao_testavel', Erros::mensagem('ZTE-VIN-004'), microtime(true));
        } else {
            $t = microtime(true);
            try {
                $p = OltServico::executarLeitura((int) $olt['id'], fn($d) => $d->ping($v['host_olt']));
                if ($p['recebidos'] > 0) {
                    $estado = 'potencial';
                    $etapas[] = RepoServico::etapa('alcance', 'OLT alcança o FTP', 'ok',
                        sprintf('Ping da OLT até %s: %d%% (%d/%d).', $v['host_olt'], $p['percentual'], $p['recebidos'], $p['enviados']), $t);
                } else {
                    $etapas[] = RepoServico::etapa('alcance', 'OLT alcança o FTP', 'aviso',
                        'A OLT não recebeu resposta ao ping. ICMP pode estar bloqueado no caminho: não dá para concluir sobre o FTP.', $t);
                }
            } catch (ZteErro $z) {
                $tec = $z->detalhes()['tecnico'] ?? '';
                $etapas[] = RepoServico::etapa('alcance', 'OLT alcança o FTP', 'erro',
                    'Não foi possível usar a OLT para o teste: ' . $z->getMessage() . ($tec ? ' (' . $tec . ')' : ''), $t);
            }
        }
        // Um upgrade real ja comprovou o download: o ping nunca rebaixa essa prova (rebaixar faz a
        // proxima rodada da recorrente exigir ciencia de novo e ser recusada).
        $prova = self::comprovacao($v);
        if ($prova !== null || $v['estado_conectividade'] === 'validado') {
            $estado = 'validado';
            $etapas[] = RepoServico::etapa('download', 'OLT baixa um arquivo do FTP', 'ok', $prova !== null
                ? 'Comprovado: a OLT baixou ' . $prova['nome_remoto'] . ' e a ONU concluiu a atualização (job ' . $prova['id'] . ', ' . $prova['concluido_em'] . ').'
                : ($v['estado_detalhe'] ?: 'Comprovado por um upgrade real.'), microtime(true));
        } else {
            $etapas[] = RepoServico::etapa('download', 'OLT baixa um arquivo do FTP', 'nao_testavel',
                'Não testável nesta versão: o comando de download pela OLT (file download version-ru) ainda não foi validado na OLT real. '
                . 'O acesso só fica comprovado no primeiro upgrade, feito em 1 ONU no modo seguro.', microtime(true));
        }

        $resumo = implode(' | ', array_map(fn($e) => $e['titulo'] . ': ' . $e['detalhe'],
                    array_filter($etapas, fn($e) => $e['resultado'] !== 'ok')));
        Db::transacao(function () use ($id, $estado, $resumo, $etapas, $correlacao, $usuario) {
            Db::exec('UPDATE tab_zte_olt_repositorio SET estado_conectividade = ?, estado_em = NOW(), estado_detalhe = ? WHERE id = ?',
                [$estado, mb_substr($resumo, 0, 500), $id]);
            RepoServico::gravarEtapas('olt_ftp', 'olt_repositorio', $id, $etapas, $correlacao, $usuario);
            Auditoria::registrar('acesso_olt_ftp_testar', 'olt_repositorio', $id, null, ['estado' => $estado], $correlacao);
        });
        return ['estado' => $estado, 'etapas' => $etapas, 'correlacao' => $correlacao,
                'resultado' => Diagnostico::pior(array_filter($etapas, fn($e) => $e['resultado'] !== 'nao_testavel'))];
    }

    /**
     * Ultimo upgrade concluido numa OLT real com firmware deste repositorio, depois da ultima
     * edicao do acesso (editar volta o estado a desconhecido). Mesma prova que o worker usa.
     * @return array{id:int,concluido_em:string,nome_remoto:string}|null
     */
    public static function comprovacao(array $v): ?array
    {
        $j = Db::um("SELECT j.id, j.concluido_em, f.nome_remoto
                       FROM tab_zte_job j
                       JOIN tab_zte_campanha c ON c.id = j.campanha_id
                       JOIN tab_zte_firmware f ON f.id = c.firmware_id
                       JOIN tab_zte_olt o ON o.id = c.olt_id
                      WHERE c.olt_id = ? AND f.repositorio_id = ? AND j.estado = 'concluido' AND o.protocolo <> 'simulado'
                        AND j.concluido_em >= ?
                   ORDER BY j.concluido_em DESC LIMIT 1",
            [(int) $v['olt_id'], (int) $v['repositorio_id'], $v['alterado_em'] ?? $v['criado_em']]);
        if ($j === null) {
            return null;
        }
        $j['id'] = (int) $j['id'];
        return $j;
    }

    public static function linha(int $id): array
    {
        $v = Db::um('SELECT * FROM tab_zte_olt_repositorio WHERE id = ?', [$id]);
        if ($v === null) {
            throw new ZteErro('ZTE-VIN-001', [], null, 404);
        }
        return $v;
    }

    private static function paraAuditoria(array $v): array
    {
        return array_intersect_key($v, array_flip(['olt_id', 'repositorio_id', 'host_olt', 'porta_olt', 'usuario_olt', 'caminho_olt']));
    }
}
