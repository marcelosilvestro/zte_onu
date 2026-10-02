<?php
/**
 * zte_onu :: o worker — quem EXECUTA as campanhas aprovadas. Roda pelo cron, a cada minuto.
 *
 * Um ciclo:
 *   1. trava global (GET_LOCK): dois workers nunca rodam juntos; o segundo sai na hora
 *   2. por OLT, numa sessao so:
 *        a) acompanha os jobs em andamento (status na OLT; ao terminar, LE A VERSAO NA ONU)
 *        b) reconcilia jobs sem sinal e inconclusivos lendo a ONU real — nunca repete um upgrade
 *           sem saber em que versao a ONU esta
 *        c) disjuntor: falhas demais, ou qualquer inconclusivo, pausam a campanha
 *        d) dentro da janela, inicia jobs novos respeitando o limite global e o limite por PON,
 *           depois de conferir que o arquivo continua no FTP
 *   3. com o tempo que sobrar: inventario e sincronizacao do FTP agendados (so leitura)
 *
 * O resultado de um job vem SEMPRE da leitura da versao na ONU, nunca so do "concluido" da OLT.
 */
require_once __DIR__ . '/../Core/carregar.php';

final class Worker
{
    public const NOME = 'principal';
    public const TRAVA = 'zte_onu_worker';
    public const COMMIT_PEDIDO = 'Confirmação (commit) solicitada.';

    /** Relogio injetavel (testes): devolve "HH:MM:SS". */
    public static ?Closure $relogio = null;
    /** Data injetavel (testes de agenda): devolve "Y-m-d". */
    public static ?Closure $hoje = null;
    /** Transporte injetavel (testes de queda de OLT). */
    public static ?Closure $fabricaTransporte = null;

    private static string $dono = 'worker';
    private static array $r = [];
    /** summary-of manual lido neste ciclo (null = ainda nao; false = nao deu para ler). */
    private static $resumoCiclo = null;

    /**
     * @param array $op limite_s (orcamento do ciclo), inventario (bool), sincronizacao (bool)
     * @return array resumo do ciclo
     */
    public static function executar(array $op = []): array
    {
        $limite = (int) ($op['limite_s'] ?? 50);
        if (!Db::travar(self::TRAVA, 0)) {
            return ['executado' => false, 'motivo' => 'Outro worker está em execução.'];
        }
        $ini = microtime(true);
        // Tudo o que o ciclo decide (pausa automatica, conclusao) fica na auditoria como "worker",
        // quem quer que tenha chamado.
        $auditoriaAntes = Auditoria::atual();
        Auditoria::configurar('worker', null);
        self::$dono = substr('worker-' . gethostname() . '-' . getmypid(), 0, 64);
        self::$r = ['executado' => true, 'iniciados' => 0, 'concluidos' => 0, 'falhas' => 0, 'inconclusivos' => 0,
                    'retentativas' => 0, 'pausas' => [], 'campanhas_concluidas' => 0, 'inventarios' => 0, 'sincronizacoes' => 0, 'erros' => [],
                    'logins_olt' => 0, 'rodadas' => []];
        $loginsAntes = OltServico::$logins;
        self::batimento('rodando', 'Ciclo iniciado.', true);
        try {
            // Campanhas recorrentes: cria as rodadas devidas agora (uma por dia de agenda).
            try {
                self::$r['rodadas'] = CampanhaServico::rodadasAgendadas(self::$hoje ? (self::$hoje)() : date('Y-m-d'),
                    self::$relogio ? (self::$relogio)() : date('H:i:s'));
            } catch (Throwable $e) {
                self::$r['erros'][] = 'Rodadas: ' . ($e instanceof ZteErro ? $e->getMessage() : get_class($e));
                Log::excecao('worker.rodadas', $e);
            }
            foreach (self::oltsComTrabalho() as $oltId) {
                try {
                    self::processarOlt($oltId);
                } catch (Throwable $e) {
                    self::$r['erros'][] = 'OLT ' . $oltId . ': ' . ($e instanceof ZteErro ? $e->getMessage() : get_class($e));
                    Log::excecao('worker.olt', $e, ['olt' => $oltId]);
                }
            }
            if (($op['inventario'] ?? true) && microtime(true) - $ini < $limite) {
                self::inventarioAgendado($ini, $limite);
            }
            if (($op['sincronizacao'] ?? true) && microtime(true) - $ini < $limite) {
                self::sincronizacaoAgendada();
            }
            self::$r['logins_olt'] = OltServico::$logins - $loginsAntes;
            $res = self::$r['erros'] ? 'aviso' : 'ok';
            self::batimento($res, self::resumoTexto(), false);
        } catch (Throwable $e) {
            Log::excecao('worker.ciclo', $e);
            self::$r['erros'][] = $e->getMessage();
            self::batimento('erro', 'Ciclo interrompido: ' . mb_substr($e->getMessage(), 0, 300), false);
        } finally {
            Db::destravar(self::TRAVA);
            Auditoria::configurar($auditoriaAntes[0], $auditoriaAntes[1]);
        }
        self::$r['duracao_ms'] = (int) ((microtime(true) - $ini) * 1000);
        return self::$r;
    }

    /** Estado do worker para a tela e o diagnostico. */
    public static function estado(): array
    {
        $w = Db::um('SELECT * FROM tab_zte_worker WHERE nome = ?', [self::NOME]);
        return [
            'registro' => $w,
            'idade_s'  => $w && $w['heartbeat'] ? (int) Db::valor('SELECT TIMESTAMPDIFF(SECOND, ?, NOW())', [$w['heartbeat']]) : null,
            'cron'     => is_file('/etc/cron.d/zte_onu'),
        ];
    }

    public static function dentroDaJanela(string $inicio, string $fim, string $agora): bool
    {
        $inicio = substr($inicio, 0, 5);
        $fim = substr($fim, 0, 5);
        $agora = substr($agora, 0, 5);
        return $inicio < $fim ? ($agora >= $inicio && $agora < $fim) : ($agora >= $inicio || $agora < $fim);
    }

    // ================================================================ por OLT

    /** OLTs com jobs em andamento/inconclusivos ou campanhas aprovadas/em execucao. */
    private static function oltsComTrabalho(): array
    {
        return array_map('intval', array_column(Db::todos(
            "SELECT DISTINCT c.olt_id FROM tab_zte_campanha c
              WHERE c.tipo <> 'recorrente' AND c.estado IN ('aprovada','executando')
                 OR EXISTS (SELECT 1 FROM tab_zte_job j WHERE j.campanha_id = c.id AND j.estado IN ('enviando','ativando','verificando','inconclusivo'))"), 'olt_id'));
    }

    private static function processarOlt(int $oltId): void
    {
        $olt = OltServico::linha($oltId);
        self::$resumoCiclo = null;
        $agora = self::$relogio ? (self::$relogio)() : date('H:i:s');

        // Campanhas que podem iniciar jobs agora: checagens que nao precisam da OLT.
        $iniciaveis = [];
        // A recorrente nao tem jobs: quem executa sao as rodadas dela (campanhas comuns).
        foreach (Db::todos("SELECT * FROM tab_zte_campanha WHERE olt_id = ? AND tipo <> 'recorrente' AND estado IN ('aprovada','executando') ORDER BY id", [$oltId]) as $c) {
            $id = (int) $c['id'];
            if ($motivo = self::disjuntor($c)) {
                CampanhaServico::pausarAutomatico($id, $motivo);
                self::$r['pausas'][] = $c['nome'] . ': ' . $motivo;
                continue;
            }
            if (Config::ligado('modo_seguro') && (int) Db::valor('SELECT COUNT(*) FROM tab_zte_campanha_onu WHERE campanha_id = ?', [$id]) > 1) {
                CampanhaServico::pausarAutomatico($id, 'Modo seguro ligado: campanha com mais de 1 ONU não executa.');
                self::$r['pausas'][] = $c['nome'] . ': modo seguro';
                continue;
            }
            if (!self::dentroDaJanela((string) $c['janela_inicio'], (string) $c['janela_fim'], $agora)) {
                continue;
            }
            if ((int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado = 'pendente'", [$id]) === 0) {
                continue;
            }
            if (RegistroOlt::nivelUpgrade($olt) === 'indisponivel') {
                CampanhaServico::pausarAutomatico($id, Erros::mensagem('ZTE-OLT-018'));
                continue;
            }
            $ctx = self::contextoFirmware($c, $olt);
            if (is_string($ctx)) {
                CampanhaServico::pausarAutomatico($id, $ctx);
                self::$r['pausas'][] = $c['nome'] . ': ' . $ctx;
                continue;
            }
            $iniciaveis[$id] = ['c' => $c, 'fw' => $ctx];
        }

        $emCurso = Db::todos("SELECT j.*, o.slot, o.porta, o.onu_num, co.sw_versao_inicial, f.versao_firmware AS alvo, f.caminho_remoto AS fw_caminho,
                                     c.estado AS campanha_estado, c.uuid
                                FROM tab_zte_job j
                                JOIN tab_zte_campanha c ON c.id = j.campanha_id
                                JOIN tab_zte_onu o ON o.id = j.onu_id
                                JOIN tab_zte_firmware f ON f.id = c.firmware_id
                           LEFT JOIN tab_zte_campanha_onu co ON co.campanha_id = j.campanha_id AND co.onu_id = j.onu_id
                               WHERE c.olt_id = ? AND j.estado IN ('enviando','ativando','verificando','inconclusivo')
                            ORDER BY j.id", [$oltId]);
        if (!$emCurso && !$iniciaveis) {
            return;
        }

        $transporte = self::$fabricaTransporte ? (self::$fabricaTransporte)($olt) : null;
        OltServico::executarLeitura($oltId, function (DriverOlt $drv) use ($emCurso, $iniciaveis) {
            foreach ($emCurso as $j) {
                self::acompanhar($drv, $j);
            }
            foreach ($iniciaveis as $id => $x) {
                // O disjuntor e reavaliado depois do acompanhamento: falhas deste mesmo ciclo contam.
                if ($motivo = self::disjuntor(CampanhaServico::linha($id))) {
                    CampanhaServico::pausarAutomatico($id, $motivo);
                    self::$r['pausas'][] = $x['c']['nome'] . ': ' . $motivo;
                    continue;
                }
                self::iniciarJobs($drv, $x['c'], $x['fw']);
            }
        }, $transporte);

        foreach (Db::todos("SELECT id FROM tab_zte_campanha WHERE olt_id = ? AND tipo <> 'recorrente' AND estado IN ('aprovada','executando')", [$oltId]) as $c) {
            if ($motivo = self::disjuntor(CampanhaServico::linha((int) $c['id']))) {
                CampanhaServico::pausarAutomatico((int) $c['id'], $motivo);
                self::$r['pausas'][] = 'campanha ' . $c['id'] . ': ' . $motivo;
            } elseif (CampanhaServico::concluirSePuder((int) $c['id'])) {
                self::$r['campanhas_concluidas']++;
            }
        }
    }

    // ================================================================ acompanhamento e reconciliacao

    private static function acompanhar(DriverOlt $drv, array $j): void
    {
        $id = (int) $j['id'];
        $slot = (int) $j['slot'];
        $pon = (int) $j['porta'];
        $onu = (int) $j['onu_num'];
        $semSinal = (int) Db::valor('SELECT TIMESTAMPDIFF(MINUTE, heartbeat, NOW()) FROM tab_zte_job WHERE id = ?', [$id]) >= Config::int('job_timeout_min');

        if ($j['estado'] === 'inconclusivo') {
            self::reconciliar($drv, $j, 'Reconciliação de job inconclusivo.');
            return;
        }

        // A fonte da verdade e a leitura dos bancos da ONU. A OLT so e ouvida para FALHA explicita.
        // O andamento so e consultado durante a transferencia (1 comando a mais, na mesma sessao).
        $st = ['fase' => 'desconhecido', 'detalhe' => '', 'saida' => '', 'progresso' => null];
        if ($j['estado'] === 'enviando') {
            try {
                $st = $drv->statusUpgrade($slot, $pon, $onu);
            } catch (Throwable $e) {
                // sem andamento nesta passada: a leitura dos bancos decide
            }
            if (($st['progresso'] ?? null) !== null) {
                Db::exec("UPDATE tab_zte_job SET progresso = ? WHERE id = ? AND estado = 'enviando'", [(int) $st['progresso'], $id]);
            }
        }
        if ($st['fase'] === 'falhou' && $j['estado'] === 'enviando') {
            $saida = trim($st['saida'] . "\n" . self::abortarTransferencia($drv, $j));
            JobServico::transicionar($id, 'falha', $st['detalhe'], ['falha_origem' => 'procedimento_firmware', 'saida_cli' => $saida]);
            self::$r['falhas']++;
            self::talvezRetentar($drv, $j);
            return;
        }
        // Falha de download que a OLT so informa no resumo (ex.: flash sem espaco): falha na hora,
        // sem esperar o tempo limite.
        if ($j['estado'] === 'enviando' && ($erro = self::erroNovoNaOlt($drv, $j)) !== null) {
            $saida = trim($st['saida'] . "\n" . self::abortarTransferencia($drv, $j));
            JobServico::transicionar($id, 'falha', 'A OLT não conseguiu enviar o firmware: ' . $erro . '.',
                ['falha_origem' => 'procedimento_firmware', 'saida_cli' => $saida]);
            self::$r['falhas']++;
            self::talvezRetentar($drv, $j);
            return;
        }
        try {
            $v = $drv->versaoSw($slot, $pon, $onu);
        } catch (OltFalha $f) {
            // Durante a ativacao a ONU reinicia e nao responde: normal, ate o tempo limite.
            if ($semSinal) {
                self::reconciliar($drv, $j, ($j['estado'] === 'enviando' ? 'Sem leitura da ONU durante a transferência.' : 'A ONU não voltou depois da ativação.'));
            }
            return;
        }
        $alvo = $j['alvo'];
        $ativaAlvo = $v['ativa'] !== null && strcasecmp($v['ativa'], $alvo) === 0;

        switch ($j['estado']) {
            case 'enviando':
                if ($ativaAlvo) {
                    JobServico::transicionar($id, 'ativando', 'A ONU já está com a versão nova ativa.');
                    JobServico::transicionar($id, 'verificando', 'Conferindo a confirmação (commit).');
                    $j['estado'] = 'verificando';
                    self::passoConfirmacao($drv, $j, $v, $semSinal);
                } elseif ($v['standby'] !== null && strcasecmp($v['standby'], $alvo) === 0 && $v['standby_valido']) {
                    // Passo 2: a versao chegou ao banco inativo — ativar (a ONU reinicia).
                    try {
                        $saida = $drv->ativar($slot, $pon, $onu);
                    } catch (OltFalha $f) {
                        if ($f->tipo() === 'comando') {
                            JobServico::transicionar($id, 'falha', 'A OLT recusou a ativação: ' . $f->getMessage(),
                                ['falha_origem' => 'procedimento_firmware', 'saida_cli' => $f->detalhes()['tecnico'] ?? '']);
                            self::$r['falhas']++;
                        } else {
                            JobServico::transicionar($id, 'inconclusivo', 'Falha de comunicação ao ativar: ' . $f->getMessage(), ['falha_origem' => 'comunicacao_olt']);
                            self::$r['inconclusivos']++;
                        }
                        return;
                    }
                    JobServico::transicionar($id, 'ativando', 'Versão ' . $alvo . ' gravada no banco inativo; ONU reiniciando no banco novo.', ['saida_cli' => $saida]);
                } elseif ($semSinal) {
                    self::reconciliar($drv, $j, 'A versão não chegou ao banco inativo em ' . Config::int('job_timeout_min') . ' min.', $st['saida'] ?? '');
                }
                return;
            case 'ativando':
                if ($ativaAlvo) {
                    JobServico::transicionar($id, 'verificando', 'A ONU voltou com a versão ' . $v['ativa'] . ' ativa.');
                    $j['estado'] = 'verificando';
                    self::passoConfirmacao($drv, $j, $v, $semSinal);
                } elseif ($semSinal) {
                    self::reconciliar($drv, $j, 'A ONU não ficou na versão nova depois da ativação.');
                }
                return;
            case 'verificando':
                if ($ativaAlvo) {
                    self::passoConfirmacao($drv, $j, $v, $semSinal);
                } else {
                    self::reconciliar($drv, $j, 'A versão ativa mudou durante a confirmação.');
                }
                return;
        }
    }

    /**
     * Passo 3: a versao nova esta ativa — confirmar (commit) e conferir que ficou confirmada. Sem o
     * commit a ONU volta para a versao anterior no proximo reinicio, entao o job nao conclui antes.
     *
     * Visto nos 3 upgrades reais (01-02/10): o commit enviado no MESMO ciclo em que a ONU voltou
     * e aceito pela OLT mas nao vale; o seguinte vale na hora. Por isso:
     *   - o commit so sai depois de ESPERA_COMMIT_S com a ONU de volta (o ciclo seguinte);
     *   - sem confirmacao, reenvia a cada REENVIO_COMMIT_MIN, ate MAX_COMMITS pedidos;
     *   - depois disso, so o tempo limite do job (inconclusivo).
     */
    private const ESPERA_COMMIT_S = 45;
    private const REENVIO_COMMIT_MIN = 2;
    private const MAX_COMMITS = 3;

    private static function passoConfirmacao(DriverOlt $drv, array $j, array $v, bool $semSinal): void
    {
        $id = (int) $j['id'];
        if ($v['ativa_commitada']) {
            self::concluir($j, $v, 'Versão ' . $v['ativa'] . ' ativa e confirmada na ONU.');
            return;
        }
        // So espera quem ESTA em verificando no banco (a ONU acabou de voltar); a reconciliacao de um
        // inconclusivo confirma direto.
        $agora = Db::um('SELECT estado, TIMESTAMPDIFF(SECOND, heartbeat, NOW()) AS seg FROM tab_zte_job WHERE id = ?', [$id]);
        if ($agora['estado'] === 'verificando' && (int) $agora['seg'] < self::ESPERA_COMMIT_S) {
            return;                          // a ONU acabou de voltar: commit agora seria ignorado
        }
        [$idade, $pedidos] = self::commitsPedidos($id);
        if ($idade !== null && ($idade < self::REENVIO_COMMIT_MIN || $pedidos >= self::MAX_COMMITS)) {
            if ($semSinal && $j['estado'] !== 'inconclusivo') {
                JobServico::transicionar($id, 'inconclusivo', 'A versão nova está ativa mas não ficou confirmada.', ['falha_origem' => 'verificacao']);
                self::$r['inconclusivos']++;
            }
            return;                      // aguardando a OLT confirmar o commit ja pedido
        }
        $slot = (int) $j['slot'];
        $pon = (int) $j['porta'];
        $onu = (int) $j['onu_num'];
        try {
            $saida = $drv->confirmar($slot, $pon, $onu);
            Db::exec('INSERT INTO tab_zte_job_evento (job_id, de_estado, para_estado, detalhe, saida_cli, criado_em) VALUES (?, ?, ?, ?, ?, NOW())',
                [$id, $j['estado'], $j['estado'], self::COMMIT_PEDIDO, mb_substr(Log::mascararTexto($saida), 0, 200000)]);
            $v = $drv->versaoSw($slot, $pon, $onu);
        } catch (OltFalha $f) {
            if ($semSinal) {
                JobServico::transicionar($id, 'inconclusivo', 'A versão nova está ativa mas a confirmação não foi possível: ' . $f->getMessage(),
                    ['falha_origem' => 'verificacao']);
                self::$r['inconclusivos']++;
            }
            return;
        }
        if ($v['ativa'] !== null && strcasecmp($v['ativa'], $j['alvo']) === 0 && $v['ativa_commitada']) {
            self::concluir($j, $v, 'Versão ' . $v['ativa'] . ' ativa e confirmada na ONU.');
        } elseif ($semSinal) {
            JobServico::transicionar($id, 'inconclusivo', 'A versão nova está ativa mas não ficou confirmada.', ['falha_origem' => 'verificacao']);
            self::$r['inconclusivos']++;
        }
    }

    /**
     * Erro NOVO no "summary-of manual" para este job: download recusado do arquivo dele ou a
     * posicao dele em "Fail". A lista da OLT nao tem data, entao so vale o que nao estava na
     * leitura feita antes do update (resumo_antes). Sem leitura anterior, nao conclui nada.
     */
    private static function erroNovoNaOlt(DriverOlt $drv, array $j): ?string
    {
        $antes = $j['resumo_antes'] !== null ? json_decode((string) $j['resumo_antes'], true) : null;
        if (!is_array($antes)) {
            return null;
        }
        if (self::$resumoCiclo === null) {
            try {
                self::$resumoCiclo = $drv->resumoManual();      // uma leitura por OLT por ciclo
            } catch (Throwable $e) {
                self::$resumoCiclo = false;
            }
        }
        if (self::$resumoCiclo === false) {
            return null;
        }
        $arquivo = strtolower(basename((string) $j['fw_caminho']));
        $jaHavia = array_flip($antes['downloads'] ?? []);
        foreach (self::$resumoCiclo['downloads'] as $d) {
            if ($d['arquivo'] === $arquivo && !isset($jaHavia[$d['arquivo'] . '|' . $d['motivo']])) {
                return 'download para a flash da OLT recusado (' . ($d['motivo'] !== '' ? $d['motivo'] : 'sem motivo informado') . ')';
            }
        }
        $pos = $j['slot'] . '/' . $j['porta'] . ':' . $j['onu_num'];
        if (in_array($pos, self::$resumoCiclo['fail'], true) && !in_array($pos, $antes['fail'] ?? [], true)) {
            return 'a OLT lista esta ONU em "Fail" no resumo das atualizações';
        }
        return null;
    }

    /** O que vale guardar do resumo antes do update (para comparar depois). */
    private static function fotoResumo(DriverOlt $drv): ?string
    {
        try {
            $r = $drv->resumoManual();
        } catch (Throwable $e) {
            return null;
        }
        return json_encode(['downloads' => array_map(fn($d) => $d['arquivo'] . '|' . $d['motivo'], $r['downloads']), 'fail' => $r['fail']]);
    }

    /** remote-unit abort (modo #: a OLT real responde vazio). Nunca derruba o fluxo. */
    private static function abortarTransferencia(DriverOlt $drv, array $j): string
    {
        try {
            return $drv->abortar((int) $j['slot'], (int) $j['porta'], (int) $j['onu_num']) . "\n(tarefa limpa na OLT)";
        } catch (Throwable $e) {
            return 'remote-unit abort não foi aceito: ' . ($e instanceof ZteErro || $e instanceof OltFalha ? $e->getMessage() : get_class($e));
        }
    }

    /** Minutos desde o ultimo commit pedido NESTA tentativa do job (null = nenhum). */
    /** @return array{0:?int,1:int} minutos desde o ultimo commit pedido e quantos, NESTA tentativa */
    private static function commitsPedidos(int $jobId): array
    {
        $desde = (int) Db::valor("SELECT COALESCE(MAX(id), 0) FROM tab_zte_job_evento WHERE job_id = ? AND de_estado = 'pendente'", [$jobId]);
        $r = Db::um('SELECT TIMESTAMPDIFF(MINUTE, MAX(criado_em), NOW()) AS idade, COUNT(*) AS qtd
                       FROM tab_zte_job_evento WHERE job_id = ? AND id > ? AND detalhe = ?', [$jobId, $desde, self::COMMIT_PEDIDO]);
        return [$r['idade'] === null ? null : (int) $r['idade'], (int) $r['qtd']];
    }

    /**
     * Le a ONU real e decide — nunca repete um upgrade sem saber a versao:
     *   alvo ativo        -> concluido (o upgrade funcionou, so o acompanhamento se perdeu)
     *   versao inicial    -> falha (e retentativa, se couber)
     *   outra / sem leitura -> inconclusivo (segura a ONU; pausa a campanha pelo disjuntor)
     */
    private static function reconciliar(DriverOlt $drv, array $j, string $motivo, string $saida = ''): void
    {
        $id = (int) $j['id'];
        try {
            $v = $drv->versaoSw((int) $j['slot'], (int) $j['porta'], (int) $j['onu_num']);
        } catch (OltFalha $f) {
            if ($j['estado'] !== 'inconclusivo') {
                JobServico::transicionar($id, 'inconclusivo', $motivo . ' A ONU não respondeu à leitura da versão.', ['falha_origem' => 'comunicacao_olt']);
                self::$r['inconclusivos']++;
            }
            return;
        }
        if ($v['ativa'] !== null && strcasecmp($v['ativa'], $j['alvo']) === 0) {
            if ($v['ativa_commitada']) {
                self::concluir($j, $v, $motivo . ' Reconciliação: a ONU já está na versão alvo, confirmada.');
            } else {
                // Versao nova ativa, sem commit: leva o job a "verificando" e confirma.
                // (inconclusivo vai direto a concluido quando a confirmacao passar)
                $ordem = ['enviando' => ['ativando', 'verificando'], 'ativando' => ['verificando'], 'verificando' => [], 'inconclusivo' => []];
                foreach ($ordem[$j['estado']] ?? [] as $passo) {
                    try {
                        JobServico::transicionar($id, $passo, $motivo . ' Reconciliação: versão nova ativa, falta confirmar.');
                    } catch (ZteErro $e) {
                        return;
                    }
                }
                $j['estado'] = 'verificando';
                self::passoConfirmacao($drv, $j, $v, false);
            }
            return;
        }
        if ($v['ativa'] !== null && $j['sw_versao_inicial'] !== null && strcasecmp($v['ativa'], $j['sw_versao_inicial']) === 0) {
            if ($j['estado'] === 'enviando') {
                // Transferencia que nao andou: limpa a tarefa na OLT antes de qualquer nova tentativa.
                $saida = trim($saida . "\n" . self::abortarTransferencia($drv, $j));
            }
            JobServico::transicionar($id, 'falha', $motivo . ' Reconciliação: a ONU continua em ' . $v['ativa'] . '.',
                ['falha_origem' => $j['estado'] === 'inconclusivo' ? 'verificacao' : 'procedimento_firmware', 'saida_cli' => $saida]);
            self::$r['falhas']++;
            if ($j['estado'] !== 'inconclusivo') {
                self::talvezRetentar($drv, $j);
            }
            return;
        }
        if ($j['estado'] !== 'inconclusivo') {
            JobServico::transicionar($id, 'inconclusivo', $motivo . ' Versão encontrada: ' . ($v['ativa'] ?? 'nenhuma ativa') . '.', ['falha_origem' => 'verificacao']);
            self::$r['inconclusivos']++;
        }
    }

    /** Leva o job ate "concluido" pelo caminho permitido da maquina de estados. */
    private static function concluir(array $j, array $v, string $detalhe): void
    {
        $id = (int) $j['id'];
        $ordem = ['enviando' => ['ativando', 'verificando'], 'ativando' => ['verificando'], 'verificando' => [], 'inconclusivo' => []];
        foreach ($ordem[$j['estado']] ?? [] as $passo) {
            JobServico::transicionar($id, $passo, 'Reconciliação.');
        }
        JobServico::transicionar($id, 'concluido', $detalhe, ['sw_versao_final' => $v['ativa']]);
        Db::exec('UPDATE tab_zte_onu SET sw_versao = ?, sw_standby = ?, sw_lido_em = NOW(), atualizado_em = NOW() WHERE id = ?',
            [$v['ativa'], $v['standby'], (int) $j['onu_id']]);
        // A OLT real baixou o arquivo do FTP de fato: o acesso dela ao repositorio fica comprovado.
        $n = Db::exec("UPDATE tab_zte_olt_repositorio v
                         JOIN tab_zte_campanha c ON c.olt_id = v.olt_id
                         JOIN tab_zte_firmware f ON f.id = c.firmware_id AND f.repositorio_id = v.repositorio_id
                         JOIN tab_zte_olt o ON o.id = c.olt_id
                          SET v.estado_conectividade = 'validado', v.estado_em = NOW(),
                              v.estado_detalhe = CONCAT('Comprovado: a OLT baixou ', f.nome_remoto, ' e a ONU concluiu a atualização (job ', ?, ').')
                        WHERE c.id = ? AND o.protocolo <> 'simulado' AND v.estado_conectividade <> 'validado'",
            [$id, (int) $j['campanha_id']]);
        if ($n > 0) {
            Auditoria::registrar('vinculo_comprovado', 'campanha', (int) $j['campanha_id'], null, ['job' => $id], $j['uuid'] ?? null);
        }
        self::$r['concluidos']++;
    }

    /** Retentativa automatica: so com tentativas sobrando, campanha ativa e a ONU na versao inicial. */
    private static function talvezRetentar(DriverOlt $drv, array $j): void
    {
        $atual = JobServico::linha((int) $j['id']);
        $camp = CampanhaServico::linha((int) $j['campanha_id']);
        if ((int) $atual['tentativas'] >= (int) $atual['max_tentativas'] || !in_array($camp['estado'], ['aprovada', 'executando'], true)) {
            return;
        }
        try {
            $v = $drv->versaoSw((int) $j['slot'], (int) $j['porta'], (int) $j['onu_num']);
        } catch (OltFalha $f) {
            return;
        }
        if ($v['ativa'] !== null && $j['sw_versao_inicial'] !== null && strcasecmp($v['ativa'], $j['sw_versao_inicial']) === 0) {
            try {
                JobServico::transicionar((int) $j['id'], 'pendente', 'Retentativa automática (' . ((int) $atual['tentativas'] + 1) . ' de ' . $atual['max_tentativas'] . ').');
                self::$r['retentativas']++;
            } catch (ZteErro $e) {
                // ONU pega por outra campanha: fica como falha.
            }
        }
    }

    // ================================================================ inicio de jobs

    private static function iniciarJobs(DriverOlt $drv, array $c, array $fw): void
    {
        $id = (int) $c['id'];
        $emCurso = Db::todos("SELECT o.slot, o.porta FROM tab_zte_job j JOIN tab_zte_onu o ON o.id = j.onu_id
                               WHERE j.campanha_id = ? AND j.estado IN ('enviando','ativando','verificando')", [$id]);
        $vagas = (int) $c['max_concorrentes'] - count($emCurso);
        // Transferencias na OLT inteira (todas as campanhas): cada uma ocupa a flash dela.
        $transferindo = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job j JOIN tab_zte_campanha c ON c.id = j.campanha_id
                                          WHERE c.olt_id = ? AND j.estado = 'enviando'", [$c['olt_id']]);
        $vagas = min($vagas, Config::int('max_transferencias_olt') - $transferindo);
        if ($vagas <= 0) {
            return;
        }
        if ((int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado = 'pendente'", [$id]) === 0) {
            return;
        }

        // A OLT baixa o firmware para a propria flash: se nao couber, nada e enviado.
        try {
            $fl = OltServico::registrarFlash((int) $c['olt_id'], $drv->espacoFlash());
        } catch (Throwable $e) {
            $fl = null;                      // sem leitura: segue (o resumo da OLT acusa a falha na hora)
        }
        if ($fl !== null && !OltServico::avaliarFlash($fl, (string) $fw['arquivo'], (int) $fw['tamanho'])['cabe']) {
            $motivo = 'Flash da OLT sem espaço para o firmware: ' . OltServico::mb($fl['livre']) . ' livres, o firmware tem ' .
                      OltServico::mb((int) $fw['tamanho']) . '. Nada foi enviado.';
            CampanhaServico::pausarAutomatico($id, $motivo);
            self::$r['pausas'][] = $c['nome'] . ': ' . $motivo;
            return;
        }
        // Foto do resumo da OLT ANTES do update: o que ja estava la nao conta como falha deste job.
        $foto = self::fotoResumo($drv);
        $porPon = [];
        foreach ($emCurso as $e) {
            $k = $e['slot'] . '/' . $e['porta'];
            $porPon[$k] = ($porPon[$k] ?? 0) + 1;
        }
        $pendentes = Db::todos("SELECT j.id, j.onu_id, o.slot, o.porta, o.onu_num, o.estado AS onu_estado
                                  FROM tab_zte_job j JOIN tab_zte_onu o ON o.id = j.onu_id
                                 WHERE j.campanha_id = ? AND j.estado = 'pendente' ORDER BY j.id", [$id]);
        foreach ($pendentes as $p) {
            if ($vagas <= 0) {
                break;
            }
            $k = $p['slot'] . '/' . $p['porta'];
            if (($porPon[$k] ?? 0) >= (int) $c['max_por_pon']) {
                continue;
            }
            CampanhaServico::marcarExecutando($id);
            $op = 'OP-' . $id . '-' . $p['id'] . '-' . date('His') . bin2hex(random_bytes(2));
            JobServico::transicionar((int) $p['id'], 'enviando', 'Atualização solicitada à OLT.', ['dono' => self::$dono, 'op_id' => $op]);
            Db::exec('UPDATE tab_zte_job SET resumo_antes = ? WHERE id = ?', [$foto, $p['id']]);
            try {
                $res = $drv->iniciarUpgrade((int) $p['slot'], (int) $p['porta'], (int) $p['onu_num'], $fw);
                Db::exec('INSERT INTO tab_zte_job_evento (job_id, de_estado, para_estado, op_id, detalhe, saida_cli, criado_em)
                          VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [$p['id'], 'enviando', 'enviando', $res['op_id'], 'A OLT aceitou o comando.', mb_substr(Log::mascararTexto($res['saida']), 0, 200000)]);
                self::$r['iniciados']++;
            } catch (OltFalha $f) {
                if ($f->tipo() === 'comando') {
                    // A OLT RECUSOU o comando: a ONU nao foi tocada. Para a campanha — se o comando
                    // e recusado numa ONU, provavelmente sera nas outras.
                    JobServico::transicionar((int) $p['id'], 'falha', 'A OLT recusou o comando de atualização: ' . $f->getMessage(),
                        ['falha_origem' => 'procedimento_firmware', 'saida_cli' => $f->detalhes()['tecnico'] ?? '']);
                    self::$r['falhas']++;
                    CampanhaServico::pausarAutomatico($id, 'A OLT recusou o comando de atualização: ' . ($f->detalhes()['tecnico'] ?? $f->getMessage()));
                    return;
                }
                // O comando pode ou nao ter chegado a OLT: so a leitura da ONU dira. Segura a ONU.
                JobServico::transicionar((int) $p['id'], 'inconclusivo', 'Falha de comunicação ao iniciar: ' . $f->getMessage(), ['falha_origem' => 'comunicacao_olt']);
                self::$r['inconclusivos']++;
                return;
            } catch (ZteErro $e) {
                JobServico::transicionar((int) $p['id'], 'falha', $e->getMessage(), ['falha_origem' => 'procedimento_firmware']);
                CampanhaServico::pausarAutomatico($id, $e->getMessage());
                return;
            }
            $vagas--;
            $porPon[$k] = ($porPon[$k] ?? 0) + 1;
        }
    }

    /**
     * Tudo o que a OLT precisa para buscar o arquivo, conferido ANTES de cada lote: arquivo no FTP
     * com o tamanho certo, acesso da OLT ao repositorio e a senha dela. Devolve o contexto ou o
     * motivo da pausa (texto).
     *
     * @return array|string
     */
    private static function contextoFirmware(array $c, array $olt)
    {
        $fw = FirmwareServico::linha((int) $c['firmware_id']);
        if ($fw['estado'] !== 'disponivel') {
            return 'Firmware não está disponível (estado: ' . $fw['estado'] . ').';
        }
        $repo = RepoServico::linha((int) $fw['repositorio_id']);
        try {
            $tam = RepoServico::comCliente($repo, fn(ClienteFtp $cl) => $cl->tamanho($fw['caminho_remoto']));
        } catch (ZteErro $e) {
            return 'Não foi possível conferir o arquivo no FTP antes do lote: ' . $e->getMessage();
        }
        if ($tam === null || ($fw['tamanho_bytes'] !== null && (int) $fw['tamanho_bytes'] !== $tam)) {
            FirmwareServico::verificar((int) $fw['id'], 'pre_lote', 'worker', $c['uuid']);
            return 'Arquivo do firmware ausente ou alterado no FTP: ' . $fw['caminho_remoto'] . '.';
        }
        $vin = Db::um('SELECT * FROM tab_zte_olt_repositorio WHERE olt_id = ? AND repositorio_id = ?', [$olt['id'], $repo['id']]);
        if ($vin === null) {
            return 'Acesso da OLT ao repositório do firmware não está cadastrado.';
        }
        $senha = Cofre::ler('olt_repo', (int) $vin['id']);
        if ($senha === null) {
            return Erros::mensagem('ZTE-VIN-005');
        }
        // Caminho na visao da OLT: a pasta dela corresponde a raiz do repositorio.
        $relativo = ltrim(substr($fw['caminho_remoto'], strlen(rtrim($repo['raiz'], '/'))), '/');
        $caminhoOlt = rtrim($vin['caminho_olt'], '/') . '/' . $relativo;
        return ['arquivo' => basename($caminhoOlt), 'caminho' => dirname($caminhoOlt), 'host' => $vin['host_olt'],
                'porta' => (int) $vin['porta_olt'], 'usuario' => $vin['usuario_olt'], 'senha' => $senha, 'versao' => $fw['versao_firmware'],
                'tamanho' => (int) $fw['tamanho_bytes']];
    }

    /**
     * Disjuntor: devolve o motivo da pausa ou null.
     *   - qualquer job inconclusivo (estado real de ONU desconhecido)
     *   - falhas >= max_falhas
     *   - % de falhas sobre os finalizados >= max_falhas_pct (com ao menos 5 finalizados)
     */
    private static function disjuntor(array $c): ?string
    {
        $j = JobServico::contagem((int) $c['id']);
        // Falhas de quem foi abortado/cancelado nao contam: so as que vieram da execucao.
        $falhas = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado = 'falha' AND falha_origem IS NOT NULL", [$c['id']]);
        if ($j['inconclusivo'] > 0) {
            return 'Disjuntor: ' . $j['inconclusivo'] . ' job(s) inconclusivo(s) — estado real da ONU desconhecido. Verifique antes de retomar.';
        }
        if ($falhas >= (int) $c['max_falhas']) {
            return 'Disjuntor: ' . $falhas . ' falha(s), limite ' . $c['max_falhas'] . '.';
        }
        $finalizados = $j['concluido'] + $falhas;
        if ($finalizados >= 5 && $falhas * 100 / $finalizados >= (int) $c['max_falhas_pct']) {
            return sprintf('Disjuntor: %d%% de falhas (%d de %d), limite %d%%.', (int) round($falhas * 100 / $finalizados), $falhas, $finalizados, $c['max_falhas_pct']);
        }
        return null;
    }

    // ================================================================ agendados (so leitura)

    private static function inventarioAgendado(float $ini, int $limite): void
    {
        $olts = Db::todos("SELECT id, nome, pons_detectadas FROM tab_zte_olt t WHERE ativo = 1 AND compatibilidade IN ('validada','somente_leitura')
                             AND (inventario_em IS NULL OR inventario_em < NOW() - INTERVAL ? MINUTE)
                             AND (bloqueado_ate IS NULL OR bloqueado_ate < NOW())
                             AND NOT EXISTS (SELECT 1 FROM tab_zte_job j JOIN tab_zte_campanha c ON c.id = j.campanha_id
                                              WHERE c.olt_id = t.id AND j.estado IN ('enviando','ativando','verificando'))
                           ORDER BY inventario_em IS NOT NULL, inventario_em LIMIT 1", [Config::int('inventario_intervalo_min')]);
        foreach ($olts as $o) {
            try {
                // Um login so para a OLT inteira (antes: um por PON).
                $resumo = OltServico::emSessao((int) $o['id'], function () use ($o, $ini, $limite) {
                    $pons = $o['pons_detectadas'] ? (json_decode($o['pons_detectadas'], true) ?: []) : [];
                    if (!$pons) {
                        $pons = InventarioServico::descobrir((int) $o['id'], 'worker');
                    }
                    $resumo = [];
                    foreach ($pons as $p) {
                        if (microtime(true) - $ini > $limite) {
                            return null;     // sem finalizar: a proxima passada recomeca esta OLT
                        }
                        $resumo[] = InventarioServico::lerPon((int) $o['id'], (int) $p['slot'], (int) $p['pon'], false, 'worker');
                    }
                    return $resumo;
                }, self::$fabricaTransporte ? (self::$fabricaTransporte)(OltServico::linha((int) $o['id'])) : null);
                if ($resumo === null) {
                    return;
                }
                InventarioServico::finalizar((int) $o['id'], $resumo, 'worker');
                self::$r['inventarios']++;
            } catch (Throwable $e) {
                self::$r['erros'][] = 'Inventário ' . $o['nome'] . ': ' . ($e instanceof ZteErro ? $e->getMessage() : get_class($e));
                Log::excecao('worker.inventario', $e, ['olt' => $o['id']]);
            }
        }
    }

    private static function sincronizacaoAgendada(): void
    {
        $min = Config::int('sync_repo_intervalo_min');
        if ($min <= 0) {
            return;
        }
        $repos = Db::todos("SELECT r.id, r.nome FROM tab_zte_repositorio r WHERE r.ativo = 1 AND r.perm_listar = 1 AND r.confirmado_listar = 1
                              AND (r.bloqueado_ate IS NULL OR r.bloqueado_ate < NOW())
                              AND NOT EXISTS (SELECT 1 FROM tab_zte_sincronizacao s WHERE s.repositorio_id = r.id AND s.iniciado_em > NOW() - INTERVAL ? MINUTE)
                            LIMIT 1", [$min]);
        foreach ($repos as $r) {
            try {
                RepoServico::sincronizar((int) $r['id'], 'worker', 'automatica');
                self::$r['sincronizacoes']++;
            } catch (Throwable $e) {
                self::$r['erros'][] = 'Sincronização ' . $r['nome'] . ': ' . ($e instanceof ZteErro ? $e->getMessage() : get_class($e));
            }
        }
    }

    // ================================================================ batimento

    private static function batimento(string $resultado, string $detalhe, bool $inicio): void
    {
        Db::exec('INSERT INTO tab_zte_worker (nome, pid, host, iniciado_em, heartbeat, terminado_em, resultado, detalhe)
                  VALUES (?, ?, ?, NOW(), NOW(), NULL, ?, ?)
                  ON DUPLICATE KEY UPDATE pid = VALUES(pid), host = VALUES(host), heartbeat = NOW(), resultado = VALUES(resultado),
                                          detalhe = VALUES(detalhe), iniciado_em = ' . ($inicio ? 'NOW()' : 'iniciado_em') . ',
                                          terminado_em = ' . ($inicio ? 'NULL' : 'NOW()'),
            [self::NOME, getmypid(), substr((string) gethostname(), 0, 120), $resultado, mb_substr($detalhe, 0, 500)]);
    }

    private static function resumoTexto(): string
    {
        $r = self::$r;
        return ($r['rodadas'] ? 'Rodadas: ' . implode(' | ', $r['rodadas']) . '. ' : '') . sprintf('%d iniciado(s), %d concluído(s), %d falha(s), %d inconclusivo(s), %d retentativa(s), %d campanha(s) concluída(s), %d inventário(s), %d login(s) na OLT%s%s',
            $r['iniciados'], $r['concluidos'], $r['falhas'], $r['inconclusivos'], $r['retentativas'], $r['campanhas_concluidas'], $r['inventarios'], $r['logins_olt'],
            $r['pausas'] ? '. Pausas: ' . implode(' | ', $r['pausas']) : '', $r['erros'] ? '. Erros: ' . implode(' | ', $r['erros']) : '');
    }
}
