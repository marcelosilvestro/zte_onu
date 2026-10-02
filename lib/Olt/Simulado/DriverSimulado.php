<?php
/**
 * zte_onu :: driver da OLT SIMULADA — leitura igual a da OLT real (fixtures) + uma atualizacao
 * de mentira em 3 passos, igual ao procedimento real (update -> activate -> commit), com estado
 * em tab_zte_sim_upgrade. Nenhuma ONU e tocada.
 *
 * Linha do tempo (relogio do banco):
 *   update     +20 s  a versao nova aparece no banco INATIVO (valida)
 *   activate   +20 s  a ONU "reinicia": nesse intervalo nao responde; depois, versao nova ATIVA
 *   commit     +10 s  a versao ativa passa a confirmada (a OLT aceita e grava depois, como a real)
 *
 * Falhas injetadas (documentadas no LEIAME dos fixtures):
 *   ONU 13   a transferencia falha aos 5 s (a OLT informa falha)
 *   ONU 16   trava: a versao nunca chega ao banco inativo
 *   ONU 19   some depois de ativar (nao responde) ate a coluna "falha" ser limpa
 */
require_once __DIR__ . '/../Zte/C320V21/DriverZteC320V21.php';
require_once __DIR__ . '/../../Core/Db.php';

final class DriverSimulado extends DriverZteC320V21
{
    public const SEG_TRANSFERENCIA = 20;
    public const SEG_REINICIO = 20;
    public const SEG_COMMIT = 10;
    public const FALHAS = [13 => 'transferencia', 16 => 'travada', 19 => 'some'];

    private int $oltId;

    public function __construct(Transporte $transporte, int $oltId)
    {
        parent::__construct($transporte);
        $this->oltId = $oltId;
    }

    public static function manifesto(): array
    {
        $m = parent::manifesto();
        $m['id'] = 'zte_c320_v21_simulado';
        $m['nome'] = 'ZTE C320 (simulada)';
        $m['recursos']['upgrade_onu'] = 'simulado';
        $m['recursos']['status_upgrade'] = 'simulado';
        return $m;
    }

    public function iniciarUpgrade(int $slot, int $pon, int $onu, array $fw): array
    {
        $antes = null;
        try {
            $antes = $this->versaoSw($slot, $pon, $onu)['ativa'];
        } catch (OltFalha $f) {
        }
        // Mesma linha de comando do procedimento real — inclusive a senha, para o mascaramento
        // ser exercitado de verdade.
        $cmd = ComandosZteC320V21::atualizar(1, $slot, $pon, $onu, $fw);
        Db::exec('REPLACE INTO tab_zte_sim_upgrade (olt_id, slot, porta, onu_num, versao_alvo, versao_anterior, falha, arquivo, iniciado_em, ativado_em, confirmado)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, 0)',
            [$this->oltId, $slot, $pon, $onu, $fw['versao'], $antes, self::FALHAS[$onu] ?? null, $fw['arquivo'] ?? null]);
        return ['op_id' => 'SIM-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)), 'saida' => "> $cmd\n[simulacao] transferencia iniciada"];
    }

    public function statusUpgrade(int $slot, int $pon, int $onu): array
    {
        $s = $this->linha($slot, $pon, $onu);
        if ($s !== null && $s['falha'] === 'transferencia' && (int) $s['dec_inicio'] >= 5) {
            return ['fase' => 'falhou', 'detalhe' => 'A OLT informa falha na transferência (simulado).', 'saida' => '[simulacao] RU update fail: download error', 'progresso' => null];
        }
        // % da transferencia como o update-status real (travada para em 40%).
        $pct = null;
        if ($s !== null && $s['ativado_em'] === null) {
            $pct = min($s['falha'] === 'travada' ? 40 : ($s['falha'] === 'download' ? 0 : 100), intdiv(max(0, (int) $s['dec_inicio']) * 100, self::SEG_TRANSFERENCIA));
        }
        return ['fase' => 'desconhecido', 'detalhe' => '', 'saida' => '', 'progresso' => $pct];
    }

    /** Teste: forca o espaco livre da flash simulada (null = o do fixture real, 24,9 MB). */
    public static ?int $flashLivre = null;
    /** Teste: imagens na pasta "other" (nome => bytes), como a OLT real ate o aging-time. */
    public static ?array $flashArquivos = null;

    public function espacoFlash(): ?array
    {
        $e = parent::espacoFlash();
        if ($e !== null && self::$flashLivre !== null) {
            $e['livre'] = self::$flashLivre;
        }
        if ($e !== null && self::$flashArquivos !== null) {
            $e['arquivos'] = self::$flashArquivos;
        }
        return $e;
    }

    /**
     * Resumo no formato real do "summary-of manual": falha "download" (injetada pelo teste na
     * coluna falha) aparece como o erro de espaco visto na OLT real em 01/10.
     */
    public function resumoManual(): array
    {
        $dl = '';
        $ok = '';
        foreach (Db::todos('SELECT * FROM tab_zte_sim_upgrade WHERE olt_id = ?', [$this->oltId]) as $s) {
            if ($s['falha'] === 'download') {
                $dl .= 'file ' . strtolower((string) $s['arquivo']) . "  download error from remote server,\n Reason:Remain space not enough\n";
            } elseif ($s['confirmado_em'] !== null) {
                $ok .= 'gpon-onu_1/' . $s['slot'] . '/' . $s['porta'] . ': ' . $s['onu_num'] . "\n";
            }
        }
        return ParserZteC320V21::resumoManual("Download:\n{$dl}\nOperating:\n\nWaiting:\n\nFail:\n\nSuccess:\n{$ok}");
    }

    public function ativar(int $slot, int $pon, int $onu): string
    {
        $s = $this->linha($slot, $pon, $onu);
        if ($s === null || !$this->transferida($s)) {
            throw new OltFalha('comando', '%Error 20300: no valid version to activate (simulado)');
        }
        Db::exec('UPDATE tab_zte_sim_upgrade SET ativado_em = NOW() WHERE olt_id = ? AND slot = ? AND porta = ? AND onu_num = ?', [$this->oltId, $slot, $pon, $onu]);
        return '> ' . ComandosZteC320V21::ativar(1, $slot, $pon, $onu) . "\n[simulacao] ONU reiniciando no banco novo";
    }

    public function confirmar(int $slot, int $pon, int $onu): string
    {
        $s = $this->linha($slot, $pon, $onu);
        if ($s === null || $s['ativado_em'] === null || (int) $s['dec_ativacao'] < self::SEG_REINICIO || $s['falha'] === 'some') {
            throw new OltFalha('comando', '%Error 20301: RU not ready to commit (simulado)');
        }
        if ($s['falha'] === 'commit_ignorado') {
            // Como a OLT real com o commit enviado logo na volta da ONU: aceita e nao vale.
            return '> ' . ComandosZteC320V21::confirmar(1, $slot, $pon, $onu);
        }
        Db::exec('UPDATE tab_zte_sim_upgrade SET confirmado = 1, confirmado_em = COALESCE(confirmado_em, NOW()) WHERE olt_id = ? AND slot = ? AND porta = ? AND onu_num = ?', [$this->oltId, $slot, $pon, $onu]);
        return '> ' . ComandosZteC320V21::confirmar(1, $slot, $pon, $onu) . "\n[simulacao] versao confirmada";
    }

    public function abortar(int $slot, int $pon, int $onu): string
    {
        Db::exec('DELETE FROM tab_zte_sim_upgrade WHERE olt_id = ? AND slot = ? AND porta = ? AND onu_num = ? AND ativado_em IS NULL',
            [$this->oltId, $slot, $pon, $onu]);
        return '> ' . ComandosZteC320V21::abortar(1, $slot, $pon, $onu);
    }

    /** Os bancos refletem a atualizacao simulada. */
    public function versaoSw(int $slot, int $pon, int $onu): array
    {
        return $this->aplicar(parent::versaoSw($slot, $pon, $onu), $slot, $pon, $onu);
    }

    public function versaoSwFaixa(int $slot, int $pon, int $de, int $ate): array
    {
        $lista = parent::versaoSwFaixa($slot, $pon, $de, $ate);
        foreach ($lista as $n => $v) {
            try {
                $lista[$n] = $this->aplicar($v, $slot, $pon, $n);
            } catch (OltFalha $f) {
                unset($lista[$n]);
            }
        }
        return $lista;
    }

    private function aplicar(array $v, int $slot, int $pon, int $onu): array
    {
        $s = $this->linha($slot, $pon, $onu);
        if ($s === null || !$this->transferida($s)) {
            return $v;
        }
        if ($s['ativado_em'] === null) {
            // gravada no banco inativo, ainda nao ativada
            $v['standby'] = $s['versao_alvo'];
            $v['standby_valido'] = true;
            return $v;
        }
        if ((int) $s['dec_ativacao'] < self::SEG_REINICIO || $s['falha'] === 'some') {
            throw new OltFalha('comando', '%Error 20203: ONU nao responde (reiniciando, simulado)');
        }
        $v['standby'] = $s['versao_anterior'] ?? $v['ativa'];
        $v['standby_valido'] = true;
        $v['ativa'] = $s['versao_alvo'];
        $v['ativa_commitada'] = $s['confirmado_em'] !== null && (int) $s['dec_confirmacao'] >= self::SEG_COMMIT;
        return $v;
    }

    private function transferida(array $s): bool
    {
        return (int) $s['dec_inicio'] >= self::SEG_TRANSFERENCIA && !in_array($s['falha'], ['transferencia', 'travada', 'download'], true);
    }

    private function linha(int $slot, int $pon, int $onu): ?array
    {
        return Db::um('SELECT *, TIMESTAMPDIFF(SECOND, iniciado_em, NOW()) AS dec_inicio,
                              TIMESTAMPDIFF(SECOND, ativado_em, NOW()) AS dec_ativacao,
                              TIMESTAMPDIFF(SECOND, confirmado_em, NOW()) AS dec_confirmacao
                         FROM tab_zte_sim_upgrade WHERE olt_id = ? AND slot = ? AND porta = ? AND onu_num = ?',
            [$this->oltId, $slot, $pon, $onu]);
    }
}
