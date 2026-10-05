<?php
/**
 * zte_onu :: driver ZTE C320, MVR V2.1.x.
 *
 * Leitura (identificacao, estado das ONUs por PON, detalhe/equipamento/bancos de uma ONU, ping) e
 * a atualizacao manual por ONU (update -> activate -> commit). Nenhum comando de configuracao da
 * OLT existe aqui.
 */
require_once __DIR__ . '/../../Driver.php';
require_once __DIR__ . '/Comandos.php';
require_once __DIR__ . '/Parser.php';

class DriverZteC320V21 extends DriverOlt
{
    public static function manifesto(): array
    {
        return [
            'id'               => 'zte_c320_v21',
            'nome'             => 'ZTE C320 (MVR V2.1.x)',
            'fabricante'       => 'ZTE',
            'modelos'          => ['C320'],
            'versoes_testadas' => ['V2.1.0'],
            'recursos' => [
                'identificar'      => 'validado',
                'inventario'       => 'validado',
                'detalhe_onu'      => 'validado',
                'teste_ftp_olt'    => 'experimental',
                'versao_sw_onu'    => 'validado',
                'carregar_firmware_na_olt' => 'indisponivel',
                // Promovidos em 02/10/2026: 3 ONUs isoladas + piloto de 2 ONUs na OLT real, todos ok.
                'upgrade_onu'      => 'validado',
                'status_upgrade'   => 'validado',
            ],
            'comandos' => ComandosZteC320V21::TEMPLATES,
            'limitacoes' => [
                'Versão de software lida por "show remote-unit information" (um comando por ONU; a forma em lista ainda não foi validada).',
                'Atualização por ONU em 3 passos (remote-unit update → activate → commit), cada um confirmado pela leitura dos bancos da ONU. Validada na OLT de referência (01–02/10/2026): 3 ONUs isoladas (F670L e F6201B) e piloto de 2 ONUs, todas concluídas. Tempos: 3–8 min de transferência, ~1 min de reinício, commit 1 min depois da volta.',
                'A senha do FTP vai na linha de comando da OLT (exigência do próprio comando): use para a OLT uma conta só de leitura.',
                '"update-status" mostra o % da transferência e é usado só para exibir o andamento; o texto de uma falha ainda não foi visto, então a falha é decidida pela leitura dos bancos e pelo tempo limite.',
                'Commit enviado logo que a ONU volta é aceito mas NÃO vale (visto nos 3 upgrades reais): o addon espera o ciclo seguinte (45 s) e reenvia a cada 2 min se não confirmar, até 3 vezes.',
                'No modo remote a OLT baixa o firmware para a PRÓPRIA FLASH antes de enviar à ONU: o firmware precisa caber no espaço livre (na OLT de referência, 24,9 MB de 126 MB). O addon confere antes de enviar e nunca apaga arquivos do sistema da OLT.',
                'A OLT reaproveita a imagem já baixada (pasta "other", até o aging-time de 30 min): numa campanha só a 1ª ONU de cada firmware busca no FTP.',
                'Falha de download aparece em "show remote-unit summary-of manual" (com o motivo); a lista não tem data, então só conta o que surgiu depois do comando.',
                'Acesso somente por telnet. Login conferido na OLT de referência (01/10/2026); a paginação "--More--" ainda não foi exercitada numa saída longa real.',
                'PON sem ONUs (vazia ou desativada) responde "%Code 62310-GPONSRV : No related information to show.": entra no inventário com 0 ONUs. Uma porta recusada no meio da placa é pulada, sem parar a descoberta.',
                'ONUs de outros fabricantes (ex.: Furukawa 630-10B, SN FRKW) têm modelo, HW e versão lidos pelo OMCI, como as ZTE (validado em 05/10/2026), mas só para consulta: nunca são atualizadas.',
            ],
            'prerequisitos' => [
                'Usuário da OLT com acesso ao modo privilegiado (#), direto ou por senha de enable.',
                'Acesso telnet liberado do servidor MK-AUTH até a OLT.',
            ],
        ];
    }

    public function identificar(): array
    {
        $v = ParserZteC320V21::versaoRunning($this->t->executar(ComandosZteC320V21::versao()));
        return $v + ['identificador' => $this->t->nomeEquipamento()];
    }

    public function estadoPon(int $slot, int $pon): array
    {
        return ParserZteC320V21::estadoPon($this->t->executar(ComandosZteC320V21::estadoPon(1, $slot, $pon)));
    }

    public function basePon(int $slot, int $pon): array
    {
        return ParserZteC320V21::baseInfo($this->t->executar(ComandosZteC320V21::basePon(1, $slot, $pon)));
    }

    public function detalheOnu(int $slot, int $pon, int $onu): array
    {
        return ParserZteC320V21::detalheOnu($this->t->executar(ComandosZteC320V21::detalheOnu(1, $slot, $pon, $onu)));
    }

    public function equipOnu(int $slot, int $pon, int $onu): array
    {
        return ParserZteC320V21::equipOnu($this->t->executar(ComandosZteC320V21::equipOnu(1, $slot, $pon, $onu)));
    }

    public function versaoSw(int $slot, int $pon, int $onu): array
    {
        return ParserZteC320V21::remoteUnitInfo($this->t->executar(ComandosZteC320V21::versaoSw(1, $slot, $pon, $onu)));
    }

    /**
     * Versao de varias ONUs num comando so (faixa de/ate). ⏳ Validado so com faixa contigua de
     * ONUs existentes (1-5); com lacunas na faixa, quem chama cai para a leitura uma a uma se a
     * OLT recusar.
     */
    public function versaoSwFaixa(int $slot, int $pon, int $de, int $ate): array
    {
        return ParserZteC320V21::remoteUnitInfoLista($this->t->executar(ComandosZteC320V21::versaoSwFaixa(1, $slot, $pon, $de, $ate)));
    }

    // ---------------------------------------------------------------- atualizacao (experimental)

    public function iniciarUpgrade(int $slot, int $pon, int $onu, array $fw): array
    {
        $cmd = ComandosZteC320V21::atualizar(1, $slot, $pon, $onu, $fw);
        $saida = $this->t->executar($cmd);
        $this->exigirAceite($saida);
        return ['op_id' => 'RU-' . date('YmdHis') . '-' . $slot . '-' . $pon . '-' . $onu, 'saida' => '> ' . $cmd . "\n" . $saida];
    }

    /**
     * Andamento pelo update-status: so o % da transferencia (Action Update). Nunca diz "falhou" —
     * o texto de falha da OLT ainda nao foi visto; quem decide e a leitura dos bancos e o tempo limite.
     */
    public function statusUpgrade(int $slot, int $pon, int $onu): array
    {
        $saida = $this->t->executar(ComandosZteC320V21::statusUpgrade(1, $slot, $pon, $onu));
        $s = ParserZteC320V21::updateStatus($saida);
        $transferindo = strcasecmp($s['acao'], 'Update') === 0 || strcasecmp($s['acao'], 'Unknown') === 0;
        return ['fase' => 'desconhecido', 'detalhe' => $s['acao'] . ' ' . $s['status'], 'saida' => $saida,
                'progresso' => $transferindo ? $s['progresso'] : null];
    }

    /** Lista pastas da flash (so leitura) ate uma mostrar a linha de espaco: pasta vazia nao mostra. */
    public function espacoFlash(): ?array
    {
        foreach (ComandosZteC320V21::PASTAS_FLASH as $pasta) {
            try {
                $e = ParserZteC320V21::espacoFlash($this->t->executar(ComandosZteC320V21::pastaFlash($pasta)));
            } catch (OltFalha $f) {
                if ($f->tipo() !== 'comando') {
                    throw $f;
                }
                continue;
            }
            if ($e !== null) {
                if ($pasta !== 'other') {
                    $e['arquivos'] = [];    // "other" vazia: nenhuma imagem de ONU guardada
                }
                return $e;
            }
        }
        return null;
    }

    public function resumoManual(): array
    {
        return ParserZteC320V21::resumoManual($this->t->executar(ComandosZteC320V21::resumoManual()));
    }

    public function ativar(int $slot, int $pon, int $onu): string
    {
        $cmd = ComandosZteC320V21::ativar(1, $slot, $pon, $onu);
        $saida = $this->t->executar($cmd);
        $this->exigirAceite($saida);
        return '> ' . $cmd . "\n" . $saida;
    }

    public function confirmar(int $slot, int $pon, int $onu): string
    {
        $cmd = ComandosZteC320V21::confirmar(1, $slot, $pon, $onu);
        $saida = $this->t->executar($cmd);
        $this->exigirAceite($saida);
        return '> ' . $cmd . "\n" . $saida;
    }

    /** Na OLT real o abort (modo #) responde vazio; em (config)# da %Error 20200. */
    public function abortar(int $slot, int $pon, int $onu): string
    {
        $cmd = ComandosZteC320V21::abortar(1, $slot, $pon, $onu);
        $saida = $this->t->executar($cmd);
        $this->exigirAceite($saida);
        return '> ' . $cmd . "\n" . $saida;
    }

    /** Erro da OLT ao comando vira OltFalha('comando'): a ONU nao foi tocada. */
    private function exigirAceite(string $saida): void
    {
        if (preg_match('/^\s*%(Error|Code)\b[^\n]*/mi', $saida, $m)) {
            throw new OltFalha('comando', trim($m[0]));
        }
    }

    public function ping(string $ip): array
    {
        return ParserZteC320V21::ping($this->t->executar(ComandosZteC320V21::ping($ip)));
    }
}
