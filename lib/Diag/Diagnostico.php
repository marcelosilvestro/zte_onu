<?php
/**
 * zte_onu :: diagnostico por componente.
 *
 * Cada componente responde ok | aviso | erro | nao_testavel, com mensagem tecnica util e sem
 * segredo. O objetivo e apontar ONDE esta a falha (addon, banco, cofre, worker, OLT, FTP,
 * OLT->FTP, firmware, driver, campanha) sem o operador precisar ler log.
 *
 * Componentes cujos modulos ainda nao existem nesta versao aparecem como nao_testavel com
 * essa explicacao — nunca como "ok" presumido.
 */
require_once __DIR__ . '/PreRequisitos.php';

final class Diagnostico
{
    /** @return array<int,array{componente:string,titulo:string,resultado:string,itens:array}> */
    public static function componentes(): array
    {
        $saida = [];

        $pre = PreRequisitos::verificar();
        $saida[] = self::componente('configuracao_local', 'Configuração local do addon', $pre);

        $saida[] = self::componente('banco', 'Banco de dados', self::banco());
        $saida[] = self::componente('cofre', 'Cofre de credenciais', self::cofre());
        $saida[] = self::componente('permissoes', 'Permissões', self::permissoes());
        $saida[] = self::componente('worker', 'Worker (execução em segundo plano)', self::worker());

        $saida[] = self::componente('addon_olt', 'Comunicação do addon com a OLT', self::olts());
        $saida[] = self::componente('addon_ftp', 'Comunicação do addon com o FTP', self::repositorios());
        $saida[] = self::componente('olt_ftp', 'Comunicação da OLT com o FTP', self::vinculos());
        $saida[] = self::componente('firmwares', 'Disponibilidade e integridade dos firmwares', self::firmwares());
        $saida[] = self::componente('driver', 'Compatibilidade do driver da OLT', self::drivers());
        $saida[] = self::componente('campanhas', 'Execução das campanhas e estado dos jobs', self::campanhas());
        return $saida;
    }

    /** Pacote para suporte remoto: tudo o que ajuda a entender o problema, nenhum segredo. */
    public static function pacoteSuporte(): array
    {
        $log = [];
        $arq = Log::arquivoDoDia();
        if (is_file($arq) && is_readable($arq)) {
            $linhas = @file($arq, FILE_IGNORE_NEW_LINES) ?: [];
            $log = array_map([Log::class, 'mascararTexto'], array_slice($linhas, -200));
        }
        return [
            'gerado_em'   => date('c'),
            'addon'       => 'zte_onu',
            'versao'      => function_exists('zte_versao') ? zte_versao() : '?',
            'php'         => PHP_VERSION,
            'sapi'        => PHP_SAPI,
            'extensoes'   => array_values(array_intersect(get_loaded_extensions(),
                                ['pdo_mysql', 'sodium', 'ftp', 'curl', 'openssl', 'mbstring', 'sockets'])),
            'mysql'       => self::versaoMysql(),
            'componentes' => self::componentes(),
            'log_hoje'    => $log,
        ];
    }

    /** Pior resultado de uma lista: erro > aviso > nao_testavel > ok. */
    public static function pior(array $itens): string
    {
        $peso = ['ok' => 0, 'nao_testavel' => 1, 'aviso' => 2, 'erro' => 3];
        $pior = 'ok';
        foreach ($itens as $i) {
            if (($peso[$i['resultado']] ?? 3) > $peso[$pior]) {
                $pior = $i['resultado'];
            }
        }
        return $pior;
    }

    // ---------------------------------------------------------------- componentes

    private static function banco(): array
    {
        try {
            $e = (new Schema())->estado();
        } catch (Throwable $ex) {
            return [self::item('schema', 'Tabelas do addon', 'erro', 'Não consegui consultar o banco.', 'Verifique o arquivo de configuração do banco.')];
        }
        $itens = [];
        $itens[] = self::item('schema', 'Tabelas do addon',
            $e['instalado'] ? 'ok' : 'erro',
            $e['instalado'] ? count(Schema::TABELAS) . ' tabelas presentes.' : 'Faltando: ' . implode(', ', $e['faltando']),
            'Rode o instalador para aplicar o schema.');
        $u = $e['ultima_aplicacao'];
        $itens[] = self::item('schema_versao', 'Schema em dia com o código',
            $e['desatualizado'] ? 'aviso' : 'ok',
            $u ? sprintf('Última aplicação em %s (versão %s, %s).', $u['executed_at'], $u['versao'], $u['resultado']) : 'Nunca aplicado pelo instalador.',
            'O código foi atualizado sem aplicar o schema: rode o instalador de novo.');
        return $itens;
    }

    private static function cofre(): array
    {
        $e = Cofre::estado();
        $itens = [];
        $itens[] = self::item('chave', 'Chave do cofre',
            $e['chave'] === 'ok' ? 'ok' : 'erro',
            $e['chave'] === 'ok' ? 'Presente (digital ' . $e['digital'] . ').' :
                ($e['chave'] === 'invalida' ? 'O arquivo existe mas não contém uma chave válida.' : 'Arquivo não encontrado: ' . $e['arquivo']),
            'Rode o instalador: ele gera a chave. Se a chave foi perdida, as senhas já cadastradas precisam ser recadastradas.');
        $n = count($e['incompativeis']);
        $itens[] = self::item('credenciais', 'Senhas cadastradas',
            $n === 0 ? 'ok' : 'erro',
            $n === 0 ? $e['total'] . ' senha(s), todas legíveis com a chave atual.'
                     : $n . ' de ' . $e['total'] . ' senha(s) foram gravadas com outra chave: ' . self::listaDonos($e['incompativeis']),
            'Cadastre essas senhas de novo nas telas de OLT e Repositórios.');
        return $itens;
    }

    private static function permissoes(): array
    {
        try {
            $ha = Permissao::haAdmin();
        } catch (Throwable $e) {
            return [self::item('admin', 'Administrador do addon', 'erro', 'Não consegui consultar as permissões.', 'Rode o instalador.')];
        }
        return [self::item('admin', 'Administrador do addon', $ha ? 'ok' : 'aviso',
            $ha ? 'Há administrador definido.' : 'Nenhum administrador definido ainda.',
            'Abra o addon e conclua o primeiro passo da configuração inicial.')];
    }

    private static function worker(): array
    {
        try {
            $w = Db::um("SELECT * FROM tab_zte_worker WHERE nome = 'principal'");
        } catch (Throwable $e) {
            $w = null;
        }
        $cron = is_file('/etc/cron.d/zte_onu');
        $itens = [self::item('cron', 'Agendamento (cron)', $cron ? 'ok' : (PHP_SAPI === 'cli' ? 'aviso' : 'aviso'),
            $cron ? '/etc/cron.d/zte_onu presente: o worker roda a cada minuto.' : '/etc/cron.d/zte_onu não encontrado: nenhuma campanha é executada.',
            'Rode o instalador: ele cria o agendamento.')];
        if ($w === null) {
            $itens[] = self::item('heartbeat', 'Batimento do worker', 'nao_testavel', 'O worker ainda não rodou nesta instalação.', '');
            return $itens;
        }
        $idade = $w['heartbeat'] ? (int) Db::valor('SELECT TIMESTAMPDIFF(SECOND, ?, NOW())', [$w['heartbeat']]) : null;
        $res = $idade === null || $idade > 600 ? 'aviso' : ($w['resultado'] === 'erro' ? 'erro' : ($w['resultado'] === 'aviso' ? 'aviso' : 'ok'));
        $itens[] = self::item('heartbeat', 'Último ciclo do worker', $res,
            'Há ' . ($idade === null ? '?' : $idade) . ' s (' . ($w['resultado'] ?? '-') . '): ' . ($w['detalhe'] ?? ''),
            'Confira o cron e o arquivo /opt/mk-auth/log/zte_onu/worker.log.');
        return $itens;
    }

    /** Ultimo teste de cada OLT ativa. */
    private static function olts(): array
    {
        try {
            $olts = Db::todos('SELECT id, nome, protocolo, ultimo_teste_em, ultimo_teste_resultado, ultimo_teste_detalhe, bloqueado_ate
                                 FROM tab_zte_olt WHERE ativo = 1 ORDER BY nome');
        } catch (Throwable $e) {
            return [self::item('olts', 'OLTs', 'erro', 'Não consegui consultar as OLTs.', 'Rode o instalador.')];
        }
        if (!$olts) {
            return [self::item('olts', 'OLTs cadastradas', 'aviso', 'Nenhuma OLT ativa cadastrada.', 'Cadastre a OLT na aba OLTs.')];
        }
        $itens = [];
        foreach ($olts as $o) {
            $titulo = $o['nome'] . ($o['protocolo'] === 'simulado' ? ' (simulada)' : '');
            if ($o['bloqueado_ate'] !== null && strtotime($o['bloqueado_ate']) > time()) {
                $itens[] = self::item('olt_' . $o['id'], $titulo, 'erro',
                    'Bloqueada até ' . $o['bloqueado_ate'] . ' por falhas de login seguidas.',
                    'Confira usuário e senha no cadastro da OLT; salvar uma senha nova libera o bloqueio.');
            } elseif ($o['ultimo_teste_resultado'] === null) {
                $itens[] = self::item('olt_' . $o['id'], $titulo, 'nao_testavel', 'Ainda não testada.', 'Use "Testar" na aba OLTs.');
            } else {
                $itens[] = self::item('olt_' . $o['id'], $titulo, $o['ultimo_teste_resultado'],
                    'Teste de ' . $o['ultimo_teste_em'] . ': ' . $o['ultimo_teste_detalhe'],
                    'Veja o histórico de testes da OLT na aba OLTs.');
            }
        }
        return $itens;
    }

    /** Compatibilidade detectada de cada OLT ativa com o driver. */
    private static function drivers(): array
    {
        try {
            $olts = Db::todos('SELECT id, nome, versao_detectada, compatibilidade FROM tab_zte_olt WHERE ativo = 1 ORDER BY nome');
        } catch (Throwable $e) {
            $olts = [];
        }
        if (!$olts) {
            return [self::item('driver', 'Compatibilidade', 'nao_testavel', 'Nenhuma OLT ativa.', '')];
        }
        $mapa = [
            'validada'        => ['ok', 'Versão testada com o driver.'],
            'somente_leitura' => ['aviso', 'Versão não testada: o addon só lê esta OLT, nunca atualiza.'],
            'nao_suportada'   => ['erro', 'Sem driver para este equipamento.'],
            'desconhecida'    => ['nao_testavel', 'Ainda não identificada: teste a OLT.'],
        ];
        $itens = [];
        foreach ($olts as $o) {
            [$res, $txt] = $mapa[$o['compatibilidade']] ?? $mapa['desconhecida'];
            $itens[] = self::item('drv_' . $o['id'], $o['nome'], $res,
                ($o['versao_detectada'] ? 'Versão ' . $o['versao_detectada'] . '. ' : '') . $txt, '');
        }
        return $itens;
    }

    /** Ultimo teste de cada repositorio ativo e as operacoes confirmadas. */
    private static function repositorios(): array
    {
        try {
            $repos = Db::todos('SELECT * FROM tab_zte_repositorio WHERE ativo = 1 ORDER BY nome');
        } catch (Throwable $e) {
            return [self::item('repos', 'Repositórios', 'erro', 'Não consegui consultar os repositórios.', 'Rode o instalador.')];
        }
        if (!$repos) {
            return [self::item('repos', 'Repositórios FTP', 'aviso', 'Nenhum repositório ativo cadastrado.', 'Cadastre o servidor FTP na aba Repositórios.')];
        }
        $itens = [];
        foreach ($repos as $r) {
            if ($r['bloqueado_ate'] !== null && strtotime($r['bloqueado_ate']) > time()) {
                $itens[] = self::item('repo_' . $r['id'], $r['nome'], 'erro', 'Bloqueado até ' . $r['bloqueado_ate'] . ' por falhas de login seguidas.',
                    'Confira usuário e senha; salvar uma senha nova libera o bloqueio.');
                continue;
            }
            if ($r['ultimo_teste_resultado'] === null) {
                $itens[] = self::item('repo_' . $r['id'], $r['nome'], 'nao_testavel', 'Ainda não testado.', 'Use "Testar" na aba Repositórios.');
                continue;
            }
            $ops = [];
            foreach (RepoServico::OPERACOES as $op) {
                if ((int) $r['perm_' . $op]) {
                    $ops[] = $op . ($r['confirmado_' . $op] === null ? ' (não testado)' : ((int) $r['confirmado_' . $op] ? ' ✓' : ' ✗'));
                }
            }
            $itens[] = self::item('repo_' . $r['id'], $r['nome'] . ($r['protocolo'] === 'ftp' ? ' (FTP sem criptografia)' : ''),
                $r['ultimo_teste_resultado'],
                'Teste de ' . $r['ultimo_teste_em'] . ': ' . $r['ultimo_teste_detalhe'] . ' Operações: ' . implode(', ', $ops) . '.',
                'Veja o histórico de testes do repositório.');
        }
        return $itens;
    }

    /** Os quatro estados da conectividade OLT -> FTP. */
    private static function vinculos(): array
    {
        try {
            $vs = Db::todos('SELECT v.*, o.nome AS olt, r.nome AS repo FROM tab_zte_olt_repositorio v
                               JOIN tab_zte_olt o ON o.id = v.olt_id JOIN tab_zte_repositorio r ON r.id = v.repositorio_id
                              WHERE o.ativo = 1 AND r.ativo = 1 ORDER BY o.nome, r.nome');
        } catch (Throwable $e) {
            $vs = [];
        }
        if (!$vs) {
            return [self::item('vinculos', 'Acesso das OLTs ao FTP', 'aviso', 'Nenhuma OLT tem acesso ao FTP cadastrado.',
                'Na aba Repositórios, cadastre o endereço e a conta que a OLT usa para buscar o firmware.')];
        }
        $mapa = [
            'validado'     => ['ok', 'A OLT baixou um arquivo do FTP.'],
            'potencial'    => ['aviso', 'A OLT alcança o FTP; o acesso ao arquivo ainda não foi comprovado (o download pela OLT não é testável nesta versão).'],
            'desconhecido' => ['nao_testavel', 'Não foi possível concluir.'],
            'nao_testavel' => ['nao_testavel', 'O driver não tem como testar.'],
            'falhou'       => ['erro', 'A OLT não conseguiu acessar o FTP.'],
        ];
        $itens = [];
        foreach ($vs as $v) {
            [$res, $txt] = $mapa[$v['estado_conectividade']] ?? $mapa['desconhecido'];
            $itens[] = self::item('vin_' . $v['id'], $v['olt'] . ' → ' . $v['repo'], $res,
                $txt . ($v['estado_em'] ? ' (teste de ' . $v['estado_em'] . ')' : ' Ainda não testado.'), '');
        }
        return $itens;
    }

    /** Estado de cada firmware que nao foi desativado. */
    private static function firmwares(): array
    {
        try {
            $fws = Db::todos("SELECT f.id, f.modelo_familia, f.versao_firmware, f.estado, f.hash_sem_origem, f.verificado_em,
                                     (SELECT COUNT(*) FROM tab_zte_firmware_compat c WHERE c.firmware_id = f.id) AS compat
                                FROM tab_zte_firmware f WHERE f.estado <> 'desativado' ORDER BY f.modelo_familia, f.versao_firmware");
        } catch (Throwable $e) {
            return [self::item('firmwares', 'Firmwares', 'erro', 'Não consegui consultar a biblioteca.', 'Rode o instalador.')];
        }
        if (!$fws) {
            return [self::item('firmwares', 'Biblioteca', 'aviso', 'Nenhum firmware cadastrado.', 'Envie um firmware na aba Firmwares.')];
        }
        $itens = [];
        foreach ($fws as $f) {
            $nome = $f['modelo_familia'] . ' ' . $f['versao_firmware'];
            $quando = $f['verificado_em'] ? ' Última verificação: ' . $f['verificado_em'] . '.' : '';
            switch ($f['estado']) {
                case 'disponivel':
                    $itens[] = self::item('fw_' . $f['id'], $nome, (int) $f['hash_sem_origem'] ? 'aviso' : 'ok',
                        'Disponível.' . ((int) $f['hash_sem_origem'] ? ' Sem hash de origem independente.' : '') . $quando, '');
                    break;
                case 'invalido':
                case 'ausente_no_ftp':
                    $itens[] = self::item('fw_' . $f['id'], $nome, 'erro',
                        ($f['estado'] === 'invalido' ? 'O arquivo no FTP não confere com o original.' : 'O arquivo sumiu do FTP.') . $quando,
                        'Reverifique o firmware ou envie o arquivo de novo.');
                    break;
                default:
                    $itens[] = self::item('fw_' . $f['id'], $nome, 'aviso',
                        ((int) $f['compat'] === 0 ? 'Sem compatibilidade cadastrada.' : 'Ainda não disponibilizado (estado: ' . $f['estado'] . ').') . $quando,
                        'Complete a compatibilidade e a verificação na aba Firmwares.');
            }
        }
        return $itens;
    }

    /** Campanhas em andamento e jobs que pedem atencao (inconclusivos, presos, falhas). */
    private static function campanhas(): array
    {
        try {
            $cs = Db::todos("SELECT id, nome, estado, pausa_motivo FROM tab_zte_campanha WHERE estado IN ('aprovada','executando','pausada') ORDER BY id");
            $inconclusivos = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE estado = 'inconclusivo'");
            $presos = (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE estado IN (\'enviando\',\'ativando\',\'verificando\') AND heartbeat < NOW() - INTERVAL ? MINUTE',
                [Config::int('job_timeout_min')]);
            $falhas = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE estado = 'falha' AND concluido_em >= NOW() - INTERVAL 1 DAY");
        } catch (Throwable $e) {
            return [self::item('campanhas', 'Campanhas', 'erro', 'Não consegui consultar as campanhas.', 'Rode o instalador.')];
        }
        $itens = [];
        if (!$cs) {
            $itens[] = self::item('camp', 'Campanhas em andamento', 'ok', 'Nenhuma campanha aprovada ou em execução.', '');
        }
        foreach ($cs as $c) {
            $itens[] = self::item('camp_' . $c['id'], $c['nome'], $c['estado'] === 'pausada' ? 'aviso' : 'ok',
                'Estado: ' . $c['estado'] . ($c['pausa_motivo'] ? ' — ' . $c['pausa_motivo'] : '') . '.',
                $c['estado'] === 'pausada' ? 'Veja o motivo na aba Campanhas antes de retomar.' : '');
        }
        $itens[] = self::item('inconclusivos', 'Jobs inconclusivos', $inconclusivos ? 'erro' : 'ok',
            $inconclusivos ? $inconclusivos . ' ONU(s) em estado desconhecido após o upgrade: elas ficam presas até a reconciliação.' : 'Nenhum.',
            'Veja a aba Fila: o worker reconcilia lendo a ONU; se persistir, reprocesse com autorização.');
        $itens[] = self::item('presos', 'Jobs sem sinal', $presos ? 'aviso' : 'ok',
            $presos ? $presos . ' job(s) em andamento sem batimento há mais de ' . Config::int('job_timeout_min') . ' min.' : 'Nenhum.',
            'O worker reconcilia jobs sem sinal lendo a ONU real antes de qualquer nova tentativa.');
        $itens[] = self::item('falhas', 'Falhas nas últimas 24 h', $falhas ? 'aviso' : 'ok', $falhas ? $falhas . ' job(s).' : 'Nenhuma.', 'Veja a aba Fila.');
        return $itens;
    }

    private static function emBreve(string $id, string $titulo): array
    {
        return [self::item($id, $titulo, 'nao_testavel', 'Módulo ainda não disponível nesta versão do addon.', '')];
    }

    // ---------------------------------------------------------------- apoio

    private static function componente(string $id, string $titulo, array $itens): array
    {
        return ['componente' => $id, 'titulo' => $titulo, 'resultado' => self::pior($itens), 'itens' => $itens];
    }

    private static function item(string $id, string $titulo, string $resultado, string $detalhe, string $acao): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'resultado' => $resultado, 'detalhe' => $detalhe,
                'acao' => $resultado === 'ok' ? '' : $acao];
    }

    private static function listaDonos(array $donos): string
    {
        $txt = array_map(fn($d) => (Cofre::TIPOS[$d['tipo']] ?? $d['tipo']) . ' #' . $d['dono_id'], array_slice($donos, 0, 10));
        return implode('; ', $txt) . (count($donos) > 10 ? '…' : '');
    }

    private static function versaoMysql(): string
    {
        try {
            return (string) Db::valor('SELECT VERSION()');
        } catch (Throwable $e) {
            return '?';
        }
    }
}
