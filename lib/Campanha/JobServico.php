<?php
/**
 * zte_onu :: jobs — uma ONU dentro de uma campanha — e a maquina de estados.
 *
 *   pendente -> enviando -> ativando -> verificando -> concluido
 *                  |           |            |
 *                  +-----------+------------+--> falha | inconclusivo
 *
 *   falha        -> pendente (retentativa automatica ou reprocessamento autorizado)
 *   inconclusivo -> concluido | falha  (reconciliacao: o worker rele a ONU e decide)
 *                -> pendente           (reprocessamento autorizado)
 *
 * Toda transicao e validada aqui, numa transacao com SELECT ... FOR UPDATE, e vira um evento
 * (tab_zte_job_evento) com a saida da CLI ja mascarada. onu_ativa (UNIQUE) e o que impede dois
 * jobs ATIVOS na mesma ONU: ela e liberada so em concluido e falha. Inconclusivo SEGURA a ONU —
 * nao se sabe em que estado ela ficou, entao nenhuma outra campanha pode toca-la.
 */
require_once __DIR__ . '/../Core/carregar.php';

final class JobServico
{
    public const TRANSICOES = [
        'pendente'     => ['enviando', 'falha'],
        'enviando'     => ['ativando', 'falha', 'inconclusivo'],
        'ativando'     => ['verificando', 'falha', 'inconclusivo'],
        'verificando'  => ['concluido', 'falha', 'inconclusivo'],
        'falha'        => ['pendente'],
        'inconclusivo' => ['concluido', 'falha', 'pendente'],
        'concluido'    => [],
    ];
    public const EM_ANDAMENTO = ['enviando', 'ativando', 'verificando'];
    public const LIBERAM_ONU = ['concluido', 'falha'];
    public const ORIGENS_FALHA = ['repositorio', 'comunicacao_olt', 'procedimento_firmware', 'verificacao'];

    /**
     * Aplica uma transicao. $extra: falha_origem, falha_detalhe, op_id, saida_cli, dono, sw_versao_final.
     * Lanca ZTE-JOB-002 se a transicao nao for permitida a partir do estado ATUAL (relido com trava).
     */
    public static function transicionar(int $jobId, string $para, string $detalhe, array $extra = []): array
    {
        return Db::transacao(function () use ($jobId, $para, $detalhe, $extra) {
            $j = Db::um('SELECT * FROM tab_zte_job WHERE id = ? FOR UPDATE', [$jobId]);
            if ($j === null) {
                throw new ZteErro('ZTE-JOB-001', [], null, 404);
            }
            $de = $j['estado'];
            if (!in_array($para, self::TRANSICOES[$de] ?? [], true)) {
                throw new ZteErro('ZTE-JOB-002', ['de' => $de, 'para' => $para], null, 409);
            }
            $origem = $extra['falha_origem'] ?? null;
            if ($origem !== null && !in_array($origem, self::ORIGENS_FALHA, true)) {
                throw new InvalidArgumentException('Origem de falha desconhecida: ' . $origem);
            }

            // O % e da transferencia em curso: qualquer mudanca de estado o zera.
            $sets = ['estado = ?', 'heartbeat = NOW()', 'progresso = NULL'];
            $p = [$para];
            if ($para === 'enviando') {
                array_push($sets, 'tentativas = tentativas + 1', 'iniciado_em = COALESCE(iniciado_em, NOW())', 'falha_origem = NULL', 'falha_detalhe = NULL');
            }
            if ($para === 'pendente') {
                array_push($sets, 'dono = NULL', 'onu_ativa = onu_id');
            }
            if (in_array($para, ['falha', 'inconclusivo'], true)) {
                $sets[] = 'falha_origem = ?';
                $p[] = $origem;
                $sets[] = 'falha_detalhe = ?';
                $p[] = mb_substr(Log::mascararTexto($extra['falha_detalhe'] ?? $detalhe), 0, 500);
            }
            if (in_array($para, self::LIBERAM_ONU, true)) {
                array_push($sets, 'onu_ativa = NULL', 'dono = NULL', 'concluido_em = NOW()',
                    'duracao_ms = IF(iniciado_em IS NULL, NULL, TIMESTAMPDIFF(SECOND, iniciado_em, NOW()) * 1000)');
            }
            if (isset($extra['dono'])) {
                $sets[] = 'dono = ?';
                $p[] = $extra['dono'];
            }
            if (array_key_exists('sw_versao_final', $extra)) {
                $sets[] = 'sw_versao_final = ?';
                $p[] = $extra['sw_versao_final'];
            }
            $p[] = $jobId;
            try {
                Db::exec('UPDATE tab_zte_job SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);
            } catch (PDOException $ex) {
                // pendente devolve a ONU ao job: se outra campanha a pegou nesse meio tempo, recusa.
                if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                    throw new ZteErro('ZTE-JOB-004', [], null, 409);
                }
                throw $ex;
            }
            Db::exec('INSERT INTO tab_zte_job_evento (job_id, de_estado, para_estado, op_id, detalhe, saida_cli, criado_em)
                      VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$jobId, $de, $para, $extra['op_id'] ?? null, mb_substr(Log::mascararTexto($detalhe), 0, 500),
                 isset($extra['saida_cli']) ? mb_substr(Log::mascararTexto((string) $extra['saida_cli']), 0, 200000) : null]);
            return Db::um('SELECT * FROM tab_zte_job WHERE id = ?', [$jobId]);
        });
    }

    /** Reprocessamento autorizado (papel job.reprocessar): falha/inconclusivo -> pendente, tentativas zeradas. */
    public static function reprocessar(int $jobId, string $usuario): array
    {
        $j = self::linha($jobId);
        $camp = Db::um('SELECT estado, uuid FROM tab_zte_campanha WHERE id = ?', [$j['campanha_id']]);
        if (!in_array($j['estado'], ['falha', 'inconclusivo'], true) || !in_array($camp['estado'], ['aprovada', 'executando', 'pausada'], true)) {
            throw new ZteErro('ZTE-JOB-003', ['estado_job' => $j['estado'], 'estado_campanha' => $camp['estado']], null, 409);
        }
        $novo = self::transicionar($jobId, 'pendente', 'Reprocessamento autorizado por ' . $usuario);
        Db::exec('UPDATE tab_zte_job SET tentativas = 0 WHERE id = ?', [$jobId]);
        Auditoria::registrar('job_reprocessar', 'job', $jobId, ['estado' => $j['estado'], 'falha' => $j['falha_detalhe']], ['estado' => 'pendente'], $camp['uuid']);
        return $novo;
    }

    /** @return array{total:int,linhas:array,pagina:int,por_pagina:int} */
    public static function listar(array $f, int $pagina, int $porPagina = 50): array
    {
        $w = [];
        $p = [];
        if (!empty($f['campanha_id'])) { $w[] = 'j.campanha_id = ?'; $p[] = (int) $f['campanha_id']; }
        if (!empty($f['estado']))      { $w[] = 'j.estado = ?'; $p[] = (string) $f['estado']; }
        if (!empty($f['busca'])) {
            $b = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['busca']) . '%';
            $w[] = '(o.nome LIKE ? OR o.sn LIKE ?)';
            array_push($p, $b, $b);
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $base = "FROM tab_zte_job j JOIN tab_zte_onu o ON o.id = j.onu_id JOIN tab_zte_campanha c ON c.id = j.campanha_id
                 JOIN tab_zte_olt t ON t.id = o.olt_id $where";
        $total = (int) Db::valor("SELECT COUNT(*) $base", $p);
        $pagina = max(1, $pagina);
        $off = ($pagina - 1) * $porPagina;
        $linhas = Db::todos("SELECT j.*, o.nome AS onu_nome, o.sn, o.slot, o.porta, o.onu_num, o.sw_versao AS sw_atual, t.nome AS olt_nome,
                                    c.nome AS campanha_nome, c.estado AS campanha_estado, c.uuid,
                                    (SELECT co.sw_versao_inicial FROM tab_zte_campanha_onu co WHERE co.campanha_id = j.campanha_id AND co.onu_id = j.onu_id) AS sw_inicial,
                                    (SELECT f.versao_firmware FROM tab_zte_firmware f WHERE f.id = c.firmware_id) AS sw_alvo
                             $base ORDER BY COALESCE(j.iniciado_em, j.criado_em) DESC, j.id DESC
                             LIMIT $porPagina OFFSET $off", $p);
        foreach ($linhas as &$l) {
            $l['posicao'] = $l['slot'] . '/' . $l['porta'] . ':' . $l['onu_num'];
        }
        unset($l);
        return ['total' => $total, 'linhas' => $linhas, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public static function eventos(int $jobId): array
    {
        self::linha($jobId);
        return Db::todos('SELECT de_estado, para_estado, op_id, detalhe, saida_cli, criado_em FROM tab_zte_job_evento WHERE job_id = ? ORDER BY id', [$jobId]);
    }

    /** @return array<string,int> quantidade de jobs por estado */
    public static function contagem(int $campanhaId): array
    {
        $c = array_fill_keys(array_keys(self::TRANSICOES), 0);
        foreach (Db::todos('SELECT estado, COUNT(*) AS n FROM tab_zte_job WHERE campanha_id = ? GROUP BY estado', [$campanhaId]) as $r) {
            $c[$r['estado']] = (int) $r['n'];
        }
        return $c;
    }

    public static function linha(int $id): array
    {
        $j = Db::um('SELECT * FROM tab_zte_job WHERE id = ?', [$id]);
        if ($j === null) {
            throw new ZteErro('ZTE-JOB-001', [], null, 404);
        }
        return $j;
    }
}
