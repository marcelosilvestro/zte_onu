<?php
/**
 * zte_onu :: campanhas — a execucao AUTORIZADA de uma regra numa OLT.
 *
 *   rascunho -> simulada -> aprovada -> executando <-> pausada -> concluida | abortada
 *
 * Simulacao (obrigatoria): calcula, a partir do inventario, quem entra e quem fica de fora — com
 * o motivo de cada um — e roda as verificacoes de seguranca. Nada e executado.
 *
 * Aprovacao: refaz a simulacao NA HORA e so aceita se (1) nenhuma verificacao bloqueia, (2) o
 * conjunto de ONUs e exatamente o que o operador viu na ultima simulacao e (3) o firmware passa
 * pela verificacao pre-campanha. O escopo e congelado (tab_zte_campanha_onu) e os jobs nascem
 * pendentes. Quem executa e o worker, dentro da janela.
 */
require_once __DIR__ . '/../Core/carregar.php';
require_once __DIR__ . '/RegraServico.php';
require_once __DIR__ . '/JobServico.php';

final class CampanhaServico
{
    public const MOTIVOS = [
        'outro_modelo'        => 'Outro modelo',
        'modelo_desconhecido' => 'Modelo não lido (ONU offline no inventário?)',
        'hw_incompativel'     => 'Revisão de hardware fora da regra',
        'hw_desconhecido'     => 'Revisão de HW não lida (abra a ONU no inventário e use "Ler agora na OLT")',
        'versao_desconhecida' => 'Versão de software não lida',
        'ja_na_versao'        => 'Já está na versão do firmware',
        'versao_mais_nova'    => 'Versão mais nova que o firmware (nunca rebaixa)',
        'origem_nao_aceita'   => 'Versão de origem não aceita pela regra',
        'offline'             => 'Offline',
        'fora_do_escopo'      => 'Fora das PONs escolhidas',
        'em_outra_campanha'   => 'Em job ativo de outra campanha',
        'falhou_em_rodada'    => 'Falhou numa rodada anterior (fica de fora até ser liberada na campanha recorrente)',
        'outro_fabricante'    => 'Outro fabricante (fora do escopo do addon)',
    ];
    private const MAX_LISTA = 3000;
    /** Tamanho do piloto permitido enquanto o driver da OLT e "experimental". */
    public const MAX_ONUS_EXPERIMENTAL = 2;

    // ================================================================ cadastro

    /** Dias da semana (ISO: 1 = segunda ... 7 = domingo). */
    public const DIAS = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
    public const TETO_PADRAO = 20;

    /** @param bool $arquivadas true = so as arquivadas; false = so as da lista normal */
    public static function listar(bool $arquivadas = false): array
    {
        $cs = Db::todos("SELECT c.*, o.nome AS olt_nome, g.nome AS regra_nome, f.modelo_familia, f.versao_firmware
                           FROM tab_zte_campanha c JOIN tab_zte_olt o ON o.id = c.olt_id
                           LEFT JOIN tab_zte_regra g ON g.id = c.regra_id JOIN tab_zte_firmware f ON f.id = c.firmware_id
                          WHERE c.arquivada = ?
                       ORDER BY FIELD(c.estado, 'executando', 'pausada', 'aprovada', 'simulada', 'rascunho', 'concluida', 'abortada'), c.id DESC",
            [$arquivadas ? 1 : 0]);
        return array_map(fn($c) => self::paraTela($c, false), $cs);
    }

    public static function obter(int $id): array
    {
        $c = Db::um('SELECT c.*, o.nome AS olt_nome, g.nome AS regra_nome, f.modelo_familia, f.versao_firmware
                       FROM tab_zte_campanha c JOIN tab_zte_olt o ON o.id = c.olt_id
                       LEFT JOIN tab_zte_regra g ON g.id = c.regra_id JOIN tab_zte_firmware f ON f.id = c.firmware_id WHERE c.id = ?', [$id]);
        if ($c === null) {
            throw new ZteErro('ZTE-CAM-001', [], null, 404);
        }
        return self::paraTela($c, true);
    }

    /** Cria ou edita (so em rascunho/simulada; editar volta a rascunho e descarta a simulacao). */
    public static function salvar(array $e, string $usuario): array
    {
        $id = (int) ($e['id'] ?? 0);
        $olt = OltServico::linha(Validar::inteiro($e['olt_id'] ?? 0, 1, PHP_INT_MAX));
        $regra = RegraServico::obter(Validar::inteiro($e['regra_id'] ?? 0, 1, PHP_INT_MAX));
        if ($regra['olt_id'] !== null && $regra['olt_id'] !== (int) $olt['id']) {
            throw new ZteErro('ZTE-CAM-010');
        }
        if (!$regra['ativo']) {
            throw new ZteErro('ZTE-CAM-012');
        }
        $inicio = Validar::hora($e['janela_inicio'] ?? Config::get('janela_inicio'));
        $fim = Validar::hora($e['janela_fim'] ?? Config::get('janela_fim'));
        if ($inicio === $fim) {
            throw new ZteErro('ZTE-CAM-013');
        }
        $pons = [];
        foreach ((array) ($e['pons'] ?? []) as $p) {
            if (preg_match('#^(\d{1,2})/(\d{1,2})$#', (string) $p)) {
                $pons[] = (string) $p;
            }
        }
        $onus = array_values(array_unique(array_map('intval', array_filter((array) ($e['onus'] ?? []), 'is_numeric'))));
        $recorrente = ($e['tipo'] ?? 'campanha') === 'recorrente';
        $dias = null;
        $teto = null;
        if ($recorrente) {
            // A recorrente decide as ONUs a cada rodada (pela regra): nao tem ONUs fixas.
            $onus = [];
            $ds = array_values(array_unique(array_filter(array_map('intval', (array) ($e['dias_semana'] ?? [])), fn($x) => $x >= 1 && $x <= 7)));
            sort($ds);
            if (!$ds) {
                throw new ZteErro('ZTE-CAM-016');
            }
            $dias = implode(',', $ds);
            $teto = Validar::inteiro($e['teto_rodada'] ?? self::TETO_PADRAO, 1, 500);
        }
        $d = [
            'tipo'             => $recorrente ? 'recorrente' : 'campanha',
            'nome'             => Validar::nome($e['nome'] ?? '', 120),
            'olt_id'           => (int) $olt['id'],
            'regra_id'         => $regra['id'],
            'firmware_id'      => $regra['firmware_id'],
            'escopo'           => json_encode(['pons' => array_values(array_unique($pons)), 'onus' => $onus]),
            'max_por_pon'      => Validar::inteiro($e['max_por_pon'] ?? Config::int('max_por_pon'), 1, 64),
            'max_concorrentes' => Validar::inteiro($e['max_concorrentes'] ?? Config::int('max_concorrentes'), 1, 64),
            'max_falhas'       => Validar::inteiro($e['max_falhas'] ?? Config::int('max_falhas'), 1, 1000),
            'max_falhas_pct'   => Validar::inteiro($e['max_falhas_pct'] ?? Config::int('max_falhas_pct'), 1, 100),
            'retentativas'     => Validar::inteiro($e['retentativas'] ?? Config::int('retentativas'), 0, 5),
            'janela_inicio'    => $inicio,
            'janela_fim'       => $fim,
            'dias_semana'      => $dias,
            'teto_rodada'      => $teto,
        ];
        if ($id === 0) {
            Db::exec('INSERT INTO tab_zte_campanha (uuid, ' . implode(', ', array_keys($d)) . ", estado, criado_por, criado_em)
                      VALUES (UUID(), " . implode(', ', array_fill(0, count($d), '?')) . ", 'rascunho', ?, NOW())",
                array_merge(array_values($d), [$usuario]));
            $id = Db::ultimoId();
            $c = self::obter($id);
            Auditoria::registrar('campanha_criar', 'campanha', $id, null, $d, $c['uuid']);
            return $c;
        }
        $antes = self::obter($id);
        if ($antes['tipo'] === 'avulsa') {
            throw new ZteErro('ZTE-CAM-015', [], null, 409);
        }
        if ($antes['tipo'] === 'rodada') {
            throw new ZteErro('ZTE-CAM-019', [], null, 409);
        }
        // A recorrente pausada tambem e editavel: volta a rascunho e precisa de nova aprovacao.
        $editaveis = $antes['tipo'] === 'recorrente' ? ['rascunho', 'simulada', 'pausada'] : ['rascunho', 'simulada'];
        if (!in_array($antes['estado'], $editaveis, true)) {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $antes['estado']], null, 409);
        }
        $n = Db::exec('UPDATE tab_zte_campanha SET ' . implode(' = ?, ', array_keys($d)) . " = ?, estado = 'rascunho', simulacao = NULL, pausa_motivo = NULL,
                              simulada_em = NULL, simulada_por = NULL, versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                        WHERE id = ? AND versao = ?",
            array_merge(array_values($d), [$usuario, $id, Validar::inteiro($e['versao'] ?? 0, 1, PHP_INT_MAX)]));
        if ($n !== 1) {
            throw new ZteErro('ZTE-CONC-001', [], null, 409);
        }
        Auditoria::registrar('campanha_alterar', 'campanha', $id, array_intersect_key($antes, $d), $d, $antes['uuid']);
        return self::obter($id);
    }

    // ================================================================ simulacao

    /** Simula e grava o resultado. Nada e executado na OLT nem no FTP alem da conferencia do arquivo. */
    public static function simular(int $id, string $usuario): array
    {
        $c = self::linha($id);
        if (!in_array($c['estado'], ['rascunho', 'simulada'], true)) {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
        }
        if ($c['tipo'] === 'recorrente') {
            // A recorrente segue o firmware ATUAL da regra (ex.: a regra trocou para um firmware novo).
            $fwRegra = (int) RegraServico::obter((int) $c['regra_id'])['firmware_id'];
            if ($fwRegra !== (int) $c['firmware_id']) {
                Db::exec('UPDATE tab_zte_campanha SET firmware_id = ? WHERE id = ?', [$fwRegra, $id]);
                $c['firmware_id'] = $fwRegra;
            }
        }
        $sim = self::calcular($c);
        Db::exec("UPDATE tab_zte_campanha SET simulacao = ?, simulada_em = NOW(), simulada_por = ?, estado = 'simulada' WHERE id = ?",
            [json_encode($sim, JSON_UNESCAPED_UNICODE), $usuario, $id]);
        Auditoria::registrar('campanha_simular', 'campanha', $id, null,
            ['entram' => $sim['resumo']['entram'], 'fora' => $sim['resumo']['fora'], 'bloqueios' => $sim['bloqueios'], 'assinatura' => $sim['assinatura']], $c['uuid']);
        return self::obter($id);
    }

    /**
     * O calculo da simulacao. Nao grava nada: aprovar() chama de novo e compara.
     * @return array{verificacoes:array,resumo:array,entram:array,fora:array,bloqueios:int,assinatura:string,lotes:int,precisa_ciencia:bool}
     */
    public static function calcular(array $c): array
    {
        $olt = OltServico::linha((int) $c['olt_id']);
        $fw = FirmwareServico::linha((int) $c['firmware_id']);
        $escopo = json_decode((string) $c['escopo'], true) ?: ['pons' => [], 'onus' => []];
        $regra = $c['regra_id'] !== null ? RegraServico::obter((int) $c['regra_id']) : self::regraAvulsa($escopo);
        $alvo = $fw['versao_firmware'];
        $v = [];

        // 1. firmware
        $v[] = self::verif('firmware', 'Firmware disponível', $fw['estado'] === 'disponivel' ? 'ok' : 'erro',
            $fw['modelo_familia'] . ' ' . $alvo . ' — estado: ' . $fw['estado'] . '.', $fw['estado'] !== 'disponivel');
        $compat = array_map(fn($x) => strtoupper($x['modelo'] . '|' . $x['hw_versao']), FirmwareServico::compat((int) $fw['id']));
        $faltam = array_filter($regra['hw_aceitos'], fn($hw) => !in_array(strtoupper($regra['modelo'] . '|' . $hw), $compat, true));
        $v[] = self::verif('compatibilidade', $c['regra_id'] !== null ? 'Regra × compatibilidade do firmware' : 'ONU × compatibilidade do firmware', $faltam ? 'erro' : 'ok',
            $faltam ? 'O firmware não declara: ' . implode(', ', $faltam) : $regra['modelo'] . ' · ' . implode(', ', $regra['hw_aceitos']) . '.', (bool) $faltam);

        // 2. arquivo no FTP (presenca e tamanho; o hash completo e conferido na aprovacao)
        try {
            $repo = RepoServico::linha((int) $fw['repositorio_id']);
            $tam = RepoServico::comCliente($repo, fn(ClienteFtp $cl) => $cl->tamanho($fw['caminho_remoto']));
            $okArq = $tam !== null && ($fw['tamanho_bytes'] === null || (int) $fw['tamanho_bytes'] === $tam);
            $v[] = self::verif('arquivo', 'Arquivo do firmware no FTP', $okArq ? 'ok' : 'erro',
                $tam === null ? 'Não encontrado: ' . $fw['caminho_remoto'] : $fw['caminho_remoto'] . ' (' . $tam . ' bytes' . ($okArq ? '' : ', esperado ' . $fw['tamanho_bytes']) . ').', !$okArq);
        } catch (ZteErro $e) {
            $v[] = self::verif('arquivo', 'Arquivo do firmware no FTP', 'erro', 'Não foi possível conferir: ' . $e->getMessage(), true);
        }

        // 3. OLT e driver
        $nivel = RegistroOlt::nivelUpgrade($olt);
        $okOlt = (int) $olt['ativo'] && in_array($olt['compatibilidade'], ['validada', 'somente_leitura'], true);
        $v[] = self::verif('olt', 'OLT ativa e identificada', $okOlt ? 'ok' : 'erro',
            $olt['nome'] . ' — compatibilidade: ' . $olt['compatibilidade'] . '.', !$okOlt);
        $v[] = self::verif('driver', 'Driver com atualização de ONU',
            $nivel === 'validado' ? 'ok' : ($nivel === 'simulado' || $nivel === 'experimental' ? 'aviso' : 'erro'),
            ['validado' => 'Procedimento de atualização validado na OLT real.',
             'simulado' => 'OLT simulada: a execução será simulada, nenhuma ONU real é tocada.',
             'experimental' => 'Procedimento já executado com sucesso em ONUs isoladas na OLT real; falta o piloto (até ' . self::MAX_ONUS_EXPERIMENTAL . ' ONUs) para ser promovido.',
             'indisponivel' => 'O comando de atualização desta OLT ainda não foi validado: a campanha pode ser simulada, mas não aprovada.'][$nivel] ?? $nivel,
            $nivel === 'indisponivel' || ($olt['compatibilidade'] === 'somente_leitura'));

        // 3b. espaco na flash da OLT: no modo remote ela baixa o firmware inteiro para a flash
        // antes de enviar a ONU (01/10: firmware de 28,6 MB recusado com 24,9 MB livres).
        if ($okOlt && $nivel !== 'indisponivel') {
            $fl = OltServico::espacoFlash((int) $olt['id']);
            $tam = (int) $fw['tamanho_bytes'];
            if ($fl === null) {
                $v[] = self::verif('flash', 'Espaço na flash da OLT', 'aviso',
                    'Não foi possível ler o espaço livre da OLT agora. O worker confere de novo antes de enviar.', false);
            } elseif (!($av = OltServico::avaliarFlash($fl, (string) $fw['caminho_remoto'], $tam))['cabe']) {
                $v[] = self::verif('flash', 'Espaço na flash da OLT', 'erro',
                    'A OLT baixa o firmware para a própria flash antes de enviar à ONU: ' . OltServico::mb($fl['livre']) . ' livres, o firmware tem ' .
                    OltServico::mb($tam) . '. Libere espaço na OLT (manutenção do equipamento) ou use uma imagem menor.', true);
            } elseif ($av['ja_na_flash']) {
                $v[] = self::verif('flash', 'Espaço na flash da OLT', 'ok',
                    'A imagem ' . basename((string) $fw['caminho_remoto']) . ' já está na flash da OLT (baixada há pouco; a OLT a apaga sozinha depois do aging-time, padrão 30 min).', false);
            } else {
                $v[] = self::verif('flash', 'Espaço na flash da OLT', 'ok',
                    OltServico::mb($fl['livre']) . ' livres de ' . OltServico::mb($fl['total']) . '; o firmware tem ' . OltServico::mb($tam) . '.', false);
            }
        }

        // 4. acesso da OLT ao FTP
        $vin = Db::um('SELECT * FROM tab_zte_olt_repositorio WHERE olt_id = ? AND repositorio_id = ?', [$olt['id'], $fw['repositorio_id']]);
        $precisaCiencia = false;
        if ($vin === null) {
            $v[] = self::verif('olt_ftp', 'Acesso da OLT ao FTP', 'erro', 'Nenhum acesso desta OLT ao repositório do firmware foi cadastrado.', true);
        } else {
            $estado = $vin['estado_conectividade'];
            $precisaCiencia = $estado !== 'validado';
            $v[] = self::verif('olt_ftp', 'Acesso da OLT ao FTP', $estado === 'validado' ? 'ok' : ($estado === 'falhou' ? 'erro' : 'aviso'),
                ['validado' => 'A OLT já baixou arquivo deste FTP.',
                 'potencial' => 'A OLT alcança o FTP (ping), mas o download ainda não foi comprovado: exige ciência na aprovação.',
                 'desconhecido' => 'Não testado ou inconclusivo: exige ciência na aprovação.',
                 'nao_testavel' => 'Não testável por esta OLT: exige ciência na aprovação.',
                 'falhou' => 'O último teste falhou.'][$estado] ?? $estado, $estado === 'falhou');
        }

        // 5. inventario recente
        $idade = $olt['inventario_em'] ? (time() - strtotime($olt['inventario_em'])) / 3600 : null;
        $v[] = self::verif('inventario', 'Inventário recente', $idade !== null && $idade <= Config::int('inventario_maximo_h') ? 'ok' : 'aviso',
            $idade === null ? 'Inventário desta OLT nunca foi lido.' : 'Lido há ' . round($idade, 1) . ' h.', false);

        // 6. candidatos
        $ocupadas = [];
        foreach (Db::todos('SELECT j.onu_ativa FROM tab_zte_job j WHERE j.onu_ativa IS NOT NULL AND j.campanha_id <> ?', [$c['id']]) as $o) {
            $ocupadas[(int) $o['onu_ativa']] = true;
        }
        $entram = [];
        $fora = [];
        $porMotivo = [];
        foreach (Db::todos('SELECT id, slot, porta, onu_num, nome, sn, fornecedor, modelo, hw_versao, sw_versao, estado
                              FROM tab_zte_onu WHERE olt_id = ? AND ausente_desde IS NULL ORDER BY slot, porta, onu_num', [$olt['id']]) as $o) {
            $pon = $o['slot'] . '/' . $o['porta'];
            if ($o['fornecedor'] !== InventarioServico::FORNECEDOR_ATUALIZAVEL) {
                $motivo = 'outro_fabricante';
            } elseif (in_array((int) $o['id'], $escopo['excluir'] ?? [], true)) {
                $motivo = 'falhou_em_rodada';
            } elseif ($escopo['onus'] && !in_array((int) $o['id'], $escopo['onus'], true)) {
                $motivo = 'fora_do_escopo';
            } elseif (!$escopo['onus'] && $escopo['pons'] && !in_array($pon, $escopo['pons'], true)) {
                // Com ONUs escolhidas uma a uma, as PONs nao restringem mais nada.
                $motivo = 'fora_do_escopo';
            } else {
                $motivo = RegraServico::motivoFora($regra, $o, $alvo);
                if ($motivo === null && $o['estado'] !== 'online') {
                    $motivo = 'offline';
                }
                if ($motivo === null && isset($ocupadas[(int) $o['id']])) {
                    $motivo = 'em_outra_campanha';
                }
            }
            $item = ['id' => (int) $o['id'], 'posicao' => $o['slot'] . '/' . $o['porta'] . ':' . $o['onu_num'], 'pon' => $pon,
                     'nome' => $o['nome'], 'sw' => $o['sw_versao'], 'hw' => $o['hw_versao']];
            if ($motivo === null) {
                $entram[] = $item;
            } else {
                $porMotivo[$motivo] = ($porMotivo[$motivo] ?? 0) + 1;
                // Outro fabricante e fora do escopo so contam: listar todos polui a tela.
                if (!in_array($motivo, ['outro_fabricante', 'fora_do_escopo', 'outro_modelo'], true)) {
                    $fora[] = $item + ['motivo' => $motivo];
                }
            }
        }

        // 7. modo seguro e quantidade. Na RECORRENTE elas valem por rodada (o worker reduz cada
        // rodada ao teto, a 1 ONU no modo seguro e ao piloto no driver experimental): aqui so avisam.
        if (($c['tipo'] ?? '') === 'recorrente') {
            $dias = array_map(fn($x) => self::DIAS[(int) $x] ?? '?', array_filter(explode(',', (string) $c['dias_semana'])));
            $teto = (int) ($c['teto_rodada'] ?: self::TETO_PADRAO);
            $v[] = self::verif('agenda', 'Agenda', 'ok', 'Rodadas em ' . implode(', ', $dias) . ', janela ' . substr((string) $c['janela_inicio'], 0, 5) .
                '–' . substr((string) $c['janela_fim'], 0, 5) . ', até ' . $teto . ' ONU(s) por rodada. Hoje haveria ' . count($entram) . ' desatualizada(s).', false);
            if (Config::ligado('modo_seguro')) {
                $v[] = self::verif('modo_seguro', 'Modo seguro', 'aviso', 'Ligado: enquanto estiver ligado, cada rodada atualiza só 1 ONU.', false);
            }
            if ($nivel === 'experimental') {
                $v[] = self::verif('experimental', 'Procedimento experimental', 'aviso',
                    'Driver experimental: cada rodada fica limitada a ' . self::MAX_ONUS_EXPERIMENTAL . ' ONUs.', false);
            }
        } elseif (Config::ligado('modo_seguro') && count($entram) > 1) {
            $v[] = self::verif('modo_seguro', 'Modo seguro', 'erro',
                'Modo seguro ligado: só campanha de 1 ONU pode ser aprovada. Edite a campanha e escolha uma ONU em "ONUs específicas" para o teste unitário.', true);
        } else {
            $v[] = self::verif('modo_seguro', 'Modo seguro', 'ok', Config::ligado('modo_seguro') ? 'Ligado: campanha de 1 ONU (teste unitário).' : 'Desligado.', false);
        }
        if (!$entram && ($c['tipo'] ?? '') !== 'recorrente') {
            $v[] = self::verif('escopo', 'ONUs elegíveis', 'erro', 'Nenhuma ONU elegível.', true);
        }
        // Procedimento ainda nao promovido a "validado": no maximo o PILOTO (2 ONUs) por campanha,
        // mesmo com o modo seguro desligado (o modo seguro, se ligado, segue limitando a 1).
        if (($c['tipo'] ?? '') !== 'recorrente' && $nivel === 'experimental' && count($entram) > self::MAX_ONUS_EXPERIMENTAL) {
            $v[] = self::verif('experimental', 'Procedimento experimental', 'erro',
                'O procedimento de atualização desta OLT ainda não foi validado com várias ONUs: faça primeiro o piloto, com no máximo ' .
                self::MAX_ONUS_EXPERIMENTAL . ' ONUs.', true);
        }

        // lotes estimados: o gargalo e o maior entre o limite global e o limite por PON
        $porPon = array_count_values(array_column($entram, 'pon'));
        $lotes = $entram ? max((int) ceil(count($entram) / (int) $c['max_concorrentes']),
                               (int) ceil(max($porPon) / (int) $c['max_por_pon'])) : 0;
        $ids = array_column($entram, 'id');
        sort($ids);
        return [
            'verificacoes'    => $v,
            'bloqueios'       => count(array_filter($v, fn($x) => $x['bloqueia'])),
            'precisa_ciencia' => $precisaCiencia,
            'resumo'          => ['entram' => count($entram), 'fora' => array_sum($porMotivo), 'por_motivo' => $porMotivo,
                                  'por_pon' => $porPon, 'versao_alvo' => $alvo],
            'lotes'           => $lotes,
            'entram'          => array_slice($entram, 0, self::MAX_LISTA),
            'fora'            => array_slice($fora, 0, self::MAX_LISTA),
            // impressao digital do conjunto de ONUs: a aprovacao exige a mesma
            'assinatura'      => hash('sha256', implode(',', $ids)),
            'calculado_em'    => date('Y-m-d H:i:s'),
        ];
    }

    // ================================================================ aprovacao

    public static function aprovar(int $id, string $usuario, bool $ciencia, bool $ehAdmin): array
    {
        $c = self::linha($id);
        if ($c['estado'] !== 'simulada' || !$c['simulacao']) {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
        }
        if (strtotime($c['simulada_em']) < time() - Config::int('simulacao_validade_h') * 3600) {
            throw new ZteErro('ZTE-CAM-003', [], null, 409);
        }
        if (Config::ligado('separar_criar_aprovar') && $c['criado_por'] === $usuario && !$ehAdmin) {
            throw new ZteErro('ZTE-CAM-006', [], null, 403);
        }
        $vista = json_decode($c['simulacao'], true);
        $agora = self::calcular($c);
        $recorrente = $c['tipo'] === 'recorrente';

        // Na recorrente o conjunto de ONUs muda de rodada para rodada (e esse e o objetivo):
        // o que a aprovacao fixa e a CONFIGURACAO (assinaturaConfig), nao as ONUs.
        if (!$recorrente && $agora['assinatura'] !== $vista['assinatura']) {
            // Guarda a simulacao nova para o operador revisar; a aprovacao nao acontece.
            Db::exec('UPDATE tab_zte_campanha SET simulacao = ?, simulada_em = NOW() WHERE id = ?', [json_encode($agora, JSON_UNESCAPED_UNICODE), $id]);
            throw new ZteErro('ZTE-CAM-005', ['antes' => $vista['resumo']['entram'], 'agora' => $agora['resumo']['entram']], null, 409);
        }
        if ($agora['bloqueios'] > 0) {
            throw new ZteErro('ZTE-CAM-004', ['bloqueios' => array_values(array_map(fn($x) => $x['titulo'] . ': ' . $x['detalhe'],
                array_filter($agora['verificacoes'], fn($x) => $x['bloqueia'])))], null, 409);
        }
        if (!$recorrente && !$agora['entram']) {
            throw new ZteErro('ZTE-CAM-008', [], null, 409);
        }
        if ($agora['precisa_ciencia'] && !$ciencia) {
            throw new ZteErro('ZTE-CAM-007', [], null, 409);
        }

        // Verificacao do firmware imediatamente antes de liberar (hash completo, conforme a politica).
        $vf = FirmwareServico::verificar((int) $c['firmware_id'], 'pre_campanha', $usuario, $c['uuid']);
        if ($vf['resultado'] === 'erro' || FirmwareServico::linha((int) $c['firmware_id'])['estado'] !== 'disponivel') {
            throw new ZteErro('ZTE-CAM-014', ['detalhe' => $vf['detalhe']], null, 409);
        }

        if ($recorrente) {
            $assin = self::assinaturaConfig($c);
            $n = Db::exec("UPDATE tab_zte_campanha SET estado = 'aprovada', aprovada_em = NOW(), aprovada_por = ?, ciencia_olt_ftp = ?,
                                  aprovacao_assinatura = ?, simulacao = ?, pausa_motivo = NULL, versao = versao + 1
                            WHERE id = ? AND estado = 'simulada'",
                [$usuario, $ciencia ? 1 : 0, $assin, json_encode($agora, JSON_UNESCAPED_UNICODE), $id]);
            if ($n !== 1) {
                throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
            }
            Auditoria::registrar('campanha_aprovar', 'campanha', $id, ['estado' => 'simulada'],
                ['estado' => 'aprovada', 'tipo' => 'recorrente', 'dias' => $c['dias_semana'], 'teto' => (int) $c['teto_rodada'],
                 'assinatura_config' => $assin, 'ciencia_olt_ftp' => $ciencia], $c['uuid']);
            return self::obter($id);
        }

        $maxTentativas = 1 + (int) $c['retentativas'];
        Db::transacao(function () use ($id, $c, $agora, $usuario, $ciencia, $maxTentativas) {
            $atual = Db::um('SELECT estado FROM tab_zte_campanha WHERE id = ? FOR UPDATE', [$id]);
            if ($atual['estado'] !== 'simulada') {
                throw new ZteErro('ZTE-CAM-002', ['estado' => $atual['estado']], null, 409);
            }
            $onus = [];
            foreach (Db::todos('SELECT id, slot, porta, sw_versao, hw_versao FROM tab_zte_onu WHERE id IN (' .
                         implode(',', array_map('intval', array_column($agora['entram'], 'id'))) . ')') as $o) {
                $onus[(int) $o['id']] = $o;
            }
            foreach ($agora['entram'] as $e) {
                $o = $onus[$e['id']];
                Db::exec('INSERT IGNORE INTO tab_zte_campanha_onu (campanha_id, onu_id, slot, porta, sw_versao_inicial, hw_versao) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $e['id'], $o['slot'], $o['porta'], $o['sw_versao'], $o['hw_versao']]);
                try {
                    // UNIQUE(campanha_id, onu_id) torna a criacao idempotente; UNIQUE(onu_ativa)
                    // impede a mesma ONU em dois jobs ativos.
                    Db::exec("INSERT INTO tab_zte_job (campanha_id, onu_id, onu_ativa, estado, max_tentativas, criado_em)
                              VALUES (?, ?, ?, 'pendente', ?, NOW()) ON DUPLICATE KEY UPDATE id = id",
                        [$id, $e['id'], $e['id'], $maxTentativas]);
                } catch (PDOException $ex) {
                    if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                        throw new ZteErro('ZTE-CAM-005', ['onu' => $e['posicao']], null, 409);
                    }
                    throw $ex;
                }
            }
            Db::exec("UPDATE tab_zte_campanha SET estado = 'aprovada', aprovada_em = NOW(), aprovada_por = ?, ciencia_olt_ftp = ?,
                             simulacao = ?, versao = versao + 1 WHERE id = ?",
                [$usuario, $ciencia ? 1 : 0, json_encode($agora, JSON_UNESCAPED_UNICODE), $id]);
            Auditoria::registrar('campanha_aprovar', 'campanha', $id, ['estado' => 'simulada'],
                ['estado' => 'aprovada', 'onus' => count($agora['entram']), 'assinatura' => $agora['assinatura'],
                 'ciencia_olt_ftp' => $ciencia, 'lotes' => $agora['lotes']], $c['uuid']);
        });
        return self::obter($id);
    }

    // ================================================================ atualizacao avulsa (inventario)
    //
    // Para o tecnico no local, com o cliente avisado: uma campanha de 1 ONU, sem regra, com o
    // firmware mais novo compativel, simulada e aprovada na hora e sem janela (00:00-00:00 = o
    // dia todo). Passa pelas MESMAS verificacoes e pelo MESMO worker de uma campanha comum; so
    // dispensa a separacao criar/aprovar, por isso exige o papel proprio onu.atualizar_avulso.

    /** O firmware mais novo, disponivel e compativel com o modelo/HW da ONU (ou null). */
    public static function firmwareAvulso(array $o): ?array
    {
        $fws = Db::todos("SELECT f.* FROM tab_zte_firmware f JOIN tab_zte_firmware_compat c ON c.firmware_id = f.id
                           WHERE f.estado = 'disponivel' AND c.modelo = ? AND c.hw_versao = ?", [(string) $o['modelo'], (string) $o['hw_versao']]);
        usort($fws, fn($a, $b) => strnatcasecmp($b['versao_firmware'], $a['versao_firmware']) ?: ((int) $b['id'] <=> (int) $a['id']));
        return $fws[0] ?? null;
    }

    /** O que o modal mostra antes de confirmar. Nada e gravado nem enviado a OLT. */
    public static function previaAvulsa(int $onuId): array
    {
        [$o, $fw] = self::exigirAvulsavel($onuId);
        $sim = self::calcular(self::avulsaVirtual($o, $fw));
        return [
            'onu'             => ['id' => (int) $o['id'], 'nome' => $o['nome'], 'posicao' => $o['slot'] . '/' . $o['porta'] . ':' . $o['onu_num'],
                                  'sn' => $o['sn'], 'modelo' => $o['modelo'], 'hw_versao' => $o['hw_versao'], 'sw_versao' => $o['sw_versao']],
            'firmware'        => ['id' => (int) $fw['id'], 'versao' => $fw['versao_firmware'], 'nome' => $fw['nome_remoto']],
            'verificacoes'    => $sim['verificacoes'],
            'bloqueios'       => $sim['bloqueios'],
            'precisa_ciencia' => $sim['precisa_ciencia'],
        ];
    }

    /**
     * Cria, simula e aprova. Antes, RELE a ONU na OLT (1 login): ela precisa estar online e ainda
     * na versao antiga agora, nao no ultimo inventario.
     */
    public static function atualizarAvulsa(int $onuId, string $usuario, bool $ciencia, ?Transporte $t = null): array
    {
        self::exigirAvulsavel($onuId);
        InventarioServico::lerOnu($onuId, $usuario, $t);
        [$o, $fw] = self::exigirAvulsavel($onuId);

        $sim = self::calcular(self::avulsaVirtual($o, $fw));
        if ($sim['bloqueios'] > 0) {
            throw new ZteErro('ZTE-CAM-004', ['bloqueios' => array_values(array_map(fn($x) => $x['titulo'] . ': ' . $x['detalhe'],
                array_filter($sim['verificacoes'], fn($x) => $x['bloqueia'])))], null, 409);
        }
        if ($sim['precisa_ciencia'] && !$ciencia) {
            throw new ZteErro('ZTE-CAM-007', [], null, 409);
        }

        $d = self::avulsaVirtual($o, $fw);
        unset($d['id']);
        Db::exec('INSERT INTO tab_zte_campanha (uuid, ' . implode(', ', array_keys($d)) . ", estado, criado_por, criado_em)
                  VALUES (UUID(), " . implode(', ', array_fill(0, count($d), '?')) . ", 'rascunho', ?, NOW())",
            array_merge(array_values($d), [$usuario]));
        $id = Db::ultimoId();
        $uuid = (string) Db::valor('SELECT uuid FROM tab_zte_campanha WHERE id = ?', [$id]);
        Auditoria::registrar('onu_atualizar_avulso', 'onu', (int) $o['id'], ['sw_versao' => $o['sw_versao']],
            ['campanha_id' => $id, 'firmware' => $fw['versao_firmware'], 'ciencia_olt_ftp' => $ciencia], $uuid);
        try {
            self::simular($id, $usuario);
            // Quem pede a avulsa aprova: o papel onu.atualizar_avulso existe para isso.
            self::aprovar($id, $usuario, $ciencia, true);
        } catch (Throwable $e) {
            self::abortar($id, $usuario);
            throw $e;
        }
        return self::obter($id);
    }

    /** @return array{0:array,1:array} ONU e firmware alvo */
    private static function exigirAvulsavel(int $onuId): array
    {
        $o = InventarioServico::linhaOnu($onuId);
        if ($o['fornecedor'] !== InventarioServico::FORNECEDOR_ATUALIZAVEL || $o['ausente_desde'] !== null
            || empty($o['modelo']) || empty($o['sw_versao'])) {
            throw new ZteErro('ZTE-AVU-001', [], null, 409);
        }
        if ($o['estado'] !== 'online') {
            throw new ZteErro('ZTE-AVU-003', [], null, 409);
        }
        if ((int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE onu_ativa = ?', [$onuId]) > 0) {
            throw new ZteErro('ZTE-AVU-004', [], null, 409);
        }
        $fw = self::firmwareAvulso($o);
        if ($fw === null || strnatcasecmp($fw['versao_firmware'], (string) $o['sw_versao']) <= 0) {
            throw new ZteErro('ZTE-AVU-002', [], null, 409);
        }
        return [$o, $fw];
    }

    /** Os campos de uma campanha avulsa (id 0 = ainda nao gravada). */
    private static function avulsaVirtual(array $o, array $fw): array
    {
        return [
            'id'               => 0,
            'nome'             => mb_substr('Avulsa: ' . ($o['nome'] ?: $o['sn']) . ' (' . $o['slot'] . '/' . $o['porta'] . ':' . $o['onu_num'] . ')', 0, 120),
            'tipo'             => 'avulsa',
            'olt_id'           => (int) $o['olt_id'],
            'regra_id'         => null,
            'firmware_id'      => (int) $fw['id'],
            'escopo'           => json_encode(['pons' => [], 'onus' => [(int) $o['id']]]),
            'max_por_pon'      => 1,
            'max_concorrentes' => 1,
            'max_falhas'       => 1,
            'max_falhas_pct'   => 100,
            'retentativas'     => 0,
            'janela_inicio'    => '00:00',
            'janela_fim'       => '00:00',
        ];
    }

    /** "Regra" de uma ONU so: o modelo e o HW dela, qualquer versao anterior ao firmware. */
    private static function regraAvulsa(array $escopo): array
    {
        $o = InventarioServico::linhaOnu((int) ($escopo['onus'][0] ?? 0));
        return ['id' => null, 'nome' => 'Avulsa', 'modelo' => (string) $o['modelo'], 'hw_aceitos' => [(string) $o['hw_versao']], 'versoes_origem' => []];
    }

    // ================================================================ operacao

    /** Pausar a recorrente pausa tambem as rodadas dela em curso (nada novo comeca). */
    public static function pausar(int $id, string $motivo, string $usuario): array
    {
        $motivo = Validar::texto($motivo, 300) ?: 'Pausada por ' . $usuario;
        $r = self::mudarEstado($id, ['aprovada', 'executando'], 'pausada', $usuario, 'campanha_pausar', $motivo);
        foreach (self::rodadasAtivas($id) as $f) {
            self::mudarEstado((int) $f['id'], ['aprovada', 'executando'], 'pausada', $usuario, 'campanha_pausar', 'Recorrente pausada: ' . $motivo);
        }
        return $r;
    }

    /**
     * Retomar devolve a "aprovada": o worker volta a executar dentro da janela. A recorrente so
     * volta se a configuracao aprovada nao mudou (regra, firmware...); senao volta a rascunho.
     */
    public static function retomar(int $id, string $usuario): array
    {
        $c = self::linha($id);
        if ($c['tipo'] === 'recorrente' && $c['estado'] === 'pausada' && self::assinaturaConfig($c) !== (string) $c['aprovacao_assinatura']) {
            self::voltarRascunho($c, $usuario);
            throw new ZteErro('ZTE-CAM-020', [], null, 409);
        }
        if ($c['tipo'] === 'rodada' && $c['pai_id'] !== null && self::linha((int) $c['pai_id'])['estado'] !== 'aprovada') {
            // Rodada de recorrente pausada: retome a recorrente (que decide de novo).
            throw new ZteErro('ZTE-CAM-019', [], null, 409);
        }
        if ($c['tipo'] === 'recorrente') {
            // Retomar a recorrente retoma tambem as rodadas dela que estavam pausadas.
            $r = self::mudarEstado($id, ['pausada'], 'aprovada', $usuario, 'campanha_retomar', null);
            foreach (Db::todos("SELECT id FROM tab_zte_campanha WHERE pai_id = ? AND estado = 'pausada'", [$id]) as $f) {
                self::mudarEstado((int) $f['id'], ['pausada'], 'aprovada', $usuario, 'campanha_retomar', null);
            }
            return $r;
        }
        if ($c['estado'] === 'pausada' && Config::ligado('modo_seguro')
            && (int) Db::valor('SELECT COUNT(*) FROM tab_zte_campanha_onu WHERE campanha_id = ?', [$id]) > 1) {
            throw new ZteErro('ZTE-CAM-011', [], null, 409);
        }
        return self::mudarEstado($id, ['pausada'], 'aprovada', $usuario, 'campanha_retomar', null);
    }

    /**
     * Aborta: jobs pendentes viram falha e liberam a ONU. Jobs EM ANDAMENTO nao sao tocados aqui:
     * o worker os reconcilia lendo a ONU real (nao se interrompe um upgrade no meio).
     */
    public static function abortar(int $id, string $usuario): array
    {
        $c = self::linha($id);
        if (!in_array($c['estado'], ['rascunho', 'simulada', 'aprovada', 'executando', 'pausada'], true)) {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
        }
        foreach (Db::todos("SELECT id FROM tab_zte_job WHERE campanha_id = ? AND estado = 'pendente'", [$id]) as $j) {
            JobServico::transicionar((int) $j['id'], 'falha', 'Campanha abortada por ' . $usuario, ['falha_detalhe' => 'Campanha abortada antes da execução.']);
        }
        $r = self::mudarEstado($id, [$c['estado']], 'abortada', $usuario, 'campanha_abortar', 'Abortada por ' . $usuario);
        Db::exec('UPDATE tab_zte_campanha SET concluida_em = NOW() WHERE id = ?', [$id]);
        // Encerrar a recorrente encerra as rodadas dela que ainda tem fila.
        foreach (self::rodadasAtivas($id) as $f) {
            self::abortar((int) $f['id'], $usuario);
        }
        return self::obter($id);
    }

    /**
     * Excluir de verdade: so o que NUNCA enviou comando a OLT (nenhum job saiu da fila) e nao esta
     * ativo. O resto e arquivado: o historico diz qual ONU recebeu qual firmware e quando.
     */
    public static function excluir(int $id, string $usuario): void
    {
        $c = self::linha($id);
        if (!in_array($c['estado'], ['rascunho', 'simulada', 'abortada'], true)) {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
        }
        if (self::executou($id)) {
            throw new ZteErro('ZTE-CAM-017', [], null, 409);
        }
        Db::transacao(function () use ($id, $c, $usuario) {
            // Rodadas da recorrente que tambem nunca executaram vao junto.
            foreach (Db::todos('SELECT id FROM tab_zte_campanha WHERE pai_id = ?', [$id]) as $f) {
                Db::exec('DELETE FROM tab_zte_job WHERE campanha_id = ?', [$f['id']]);
                Db::exec('DELETE FROM tab_zte_campanha_onu WHERE campanha_id = ?', [$f['id']]);
                Db::exec('DELETE FROM tab_zte_campanha WHERE id = ?', [$f['id']]);
            }
            Db::exec('DELETE FROM tab_zte_job WHERE campanha_id = ?', [$id]);
            Db::exec('DELETE FROM tab_zte_campanha_onu WHERE campanha_id = ?', [$id]);
            Db::exec('DELETE FROM tab_zte_campanha WHERE id = ?', [$id]);
            Auditoria::registrar('campanha_excluir', 'campanha', $id, ['nome' => $c['nome'], 'estado' => $c['estado'], 'tipo' => $c['tipo']], null, $c['uuid']);
        });
    }

    /** Arquiva (some da lista) ou desarquiva. A recorrente leva as rodadas junto. */
    public static function arquivar(int $id, bool $arquivar, string $usuario): array
    {
        $c = self::linha($id);
        if ($arquivar && !in_array($c['estado'], ['concluida', 'abortada'], true)) {
            throw new ZteErro('ZTE-CAM-018', [], null, 409);
        }
        Db::exec('UPDATE tab_zte_campanha SET arquivada = ?, arquivada_em = IF(? = 1, NOW(), NULL), arquivada_por = IF(? = 1, ?, NULL)
                   WHERE id = ? OR (pai_id = ? AND estado IN (\'concluida\', \'abortada\'))',
            [$arquivar ? 1 : 0, $arquivar ? 1 : 0, $arquivar ? 1 : 0, $usuario, $id, $id]);
        Auditoria::registrar($arquivar ? 'campanha_arquivar' : 'campanha_desarquivar', 'campanha', $id, null, ['arquivada' => $arquivar], $c['uuid']);
        return self::obter($id);
    }

    /** As ONUs que falharam em rodadas anteriores voltam a ser candidatas nas proximas. */
    public static function liberarFalhas(int $id, string $usuario): array
    {
        $c = self::linha($id);
        if ($c['tipo'] !== 'recorrente') {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
        }
        $antes = count(self::falhasDaFamilia($id));
        Db::exec('UPDATE tab_zte_campanha SET falhas_liberadas_em = NOW() WHERE id = ?', [$id]);
        Auditoria::registrar('campanha_liberar_falhas', 'campanha', $id, ['excluidas' => $antes], ['excluidas' => 0], $c['uuid']);
        return self::obter($id);
    }

    // ================================================================ recorrente: rodadas (worker)

    /**
     * Cria as rodadas que estao devidas agora. Chamado pelo worker a cada ciclo; idempotente (uma
     * rodada por recorrente por dia de agenda). $hoje = Y-m-d, $hora = H:i:s.
     * @return string[] resumo do que aconteceu
     */
    public static function rodadasAgendadas(string $hoje, string $hora): array
    {
        $saida = [];
        foreach (Db::todos("SELECT * FROM tab_zte_campanha WHERE tipo = 'recorrente' AND estado = 'aprovada' AND arquivada = 0 ORDER BY id") as $p) {
            $data = self::dataDaRodada($p, $hoje, $hora);
            if ($data === null || $p['ultima_rodada_data'] === $data) {
                continue;
            }
            // Inventario velho: espera (o inventario agendado do worker atualiza) sem gastar o dia.
            $olt = OltServico::linha((int) $p['olt_id']);
            if ($olt['inventario_em'] === null || strtotime($olt['inventario_em']) < time() - Config::int('inventario_maximo_h') * 3600) {
                Db::exec('UPDATE tab_zte_campanha SET ultima_rodada_resumo = ? WHERE id = ?',
                    [date('d/m', strtotime($data)) . ': aguardando inventário recente da OLT.', $p['id']]);
                continue;
            }
            // Reserva o dia (dois workers nunca criam a mesma rodada).
            if (Db::exec("UPDATE tab_zte_campanha SET ultima_rodada_data = ? WHERE id = ? AND estado = 'aprovada'
                             AND (ultima_rodada_data IS NULL OR ultima_rodada_data <> ?)", [$data, $p['id'], $data]) !== 1) {
                continue;
            }
            try {
                $saida[] = $p['nome'] . ': ' . self::criarRodada($p, $data);
            } catch (Throwable $e) {
                $msg = 'Rodada de ' . date('d/m', strtotime($data)) . ' não pôde ser criada: ' . ($e instanceof ZteErro ? self::textoErro($e) : get_class($e));
                Log::excecao('campanha.rodada', $e, ['campanha' => $p['id']]);
                self::pausarAutomatico((int) $p['id'], $msg);
                Db::exec('UPDATE tab_zte_campanha SET ultima_rodada_resumo = ? WHERE id = ?', [mb_substr($msg, 0, 300), $p['id']]);
                $saida[] = $p['nome'] . ': ' . $msg;
            }
        }
        return $saida;
    }

    /** Dia de agenda da rodada aberta agora (a janela pode cruzar a meia-noite), ou null. */
    public static function dataDaRodada(array $p, string $hoje, string $hora): ?string
    {
        $ini = substr((string) $p['janela_inicio'], 0, 5);
        $fim = substr((string) $p['janela_fim'], 0, 5);
        $h = substr($hora, 0, 5);
        if ($ini < $fim) {
            $data = ($h >= $ini && $h < $fim) ? $hoje : null;
        } elseif ($h >= $ini) {
            $data = $hoje;
        } elseif ($h < $fim) {
            $data = date('Y-m-d', strtotime($hoje . ' -1 day'));     // janela aberta ontem a noite
        } else {
            $data = null;
        }
        if ($data === null) {
            return null;
        }
        $dias = array_map('intval', explode(',', (string) $p['dias_semana']));
        return in_array((int) date('N', strtotime($data)), $dias, true) ? $data : null;
    }

    /** Uma rodada: as desatualizadas de agora, ate o teto, numa campanha comum aprovada pelo sistema. */
    private static function criarRodada(array $p, string $data): string
    {
        $id = (int) $p['id'];
        $quando = date('d/m', strtotime($data));
        if (self::assinaturaConfig($p) !== (string) $p['aprovacao_assinatura']) {
            self::voltarRascunho($p, 'worker');
            $msg = $quando . ': ' . Erros::mensagem('ZTE-CAM-020');
            Db::exec('UPDATE tab_zte_campanha SET ultima_rodada_resumo = ? WHERE id = ?', [mb_substr($msg, 0, 300), $id]);
            return $msg;
        }
        $escopo = json_decode((string) $p['escopo'], true) ?: ['pons' => [], 'onus' => []];
        $escopo['onus'] = [];
        $escopo['excluir'] = self::falhasDaFamilia($id);
        $sim = self::calcular(['id' => 0, 'escopo' => json_encode($escopo)] + $p);
        $bloq = array_filter($sim['verificacoes'], fn($x) => $x['bloqueia']);
        if ($bloq) {
            $msg = $quando . ': bloqueada — ' . implode('; ', array_map(fn($x) => $x['titulo'] . ': ' . $x['detalhe'], $bloq));
            self::pausarAutomatico($id, $msg);
            Db::exec('UPDATE tab_zte_campanha SET ultima_rodada_resumo = ? WHERE id = ?', [mb_substr($msg, 0, 300), $id]);
            return $msg;
        }
        $total = count($sim['entram']);
        if ($total === 0) {
            $msg = $quando . ': nenhuma ONU desatualizada.';
            Db::exec('UPDATE tab_zte_campanha SET ultima_rodada_resumo = ? WHERE id = ?', [$msg, $id]);
            return $msg;
        }
        $teto = (int) ($p['teto_rodada'] ?: self::TETO_PADRAO);
        if (Config::ligado('modo_seguro')) {
            $teto = 1;
        }
        if (RegistroOlt::nivelUpgrade(OltServico::linha((int) $p['olt_id'])) === 'experimental') {
            $teto = min($teto, self::MAX_ONUS_EXPERIMENTAL);
        }
        $escolhidas = array_slice(array_column($sim['entram'], 'id'), 0, $teto);

        $d = [
            'nome' => mb_substr($p['nome'] . ' — rodada ' . $quando, 0, 120), 'tipo' => 'rodada', 'pai_id' => $id,
            'olt_id' => (int) $p['olt_id'], 'regra_id' => (int) $p['regra_id'], 'firmware_id' => (int) $p['firmware_id'],
            'escopo' => json_encode(['pons' => $escopo['pons'] ?? [], 'onus' => $escolhidas]),
            'max_por_pon' => (int) $p['max_por_pon'], 'max_concorrentes' => (int) $p['max_concorrentes'], 'max_falhas' => (int) $p['max_falhas'],
            'max_falhas_pct' => (int) $p['max_falhas_pct'], 'retentativas' => (int) $p['retentativas'],
            'janela_inicio' => $p['janela_inicio'], 'janela_fim' => $p['janela_fim'],
        ];
        Db::exec('INSERT INTO tab_zte_campanha (uuid, ' . implode(', ', array_keys($d)) . ", estado, criado_por, criado_em)
                  VALUES (UUID(), " . implode(', ', array_fill(0, count($d), '?')) . ", 'rascunho', 'worker', NOW())", array_values($d));
        $filha = Db::ultimoId();
        try {
            self::simular($filha, 'worker');
            // Aprovada pelo sistema DENTRO do que o operador aprovou na recorrente (assinatura conferida acima).
            self::aprovar($filha, 'worker', (bool) (int) $p['ciencia_olt_ftp'], true);
        } catch (Throwable $e) {
            try { self::abortar($filha, 'worker'); } catch (Throwable $x) { /* ja registrado */ }
            throw $e;
        }
        $msg = $quando . ': ' . count($escolhidas) . ' ONU(s) na rodada' . ($total > count($escolhidas) ? ' (de ' . $total . ' desatualizadas; o resto fica para as próximas)' : '') . '.';
        Db::exec('UPDATE tab_zte_campanha SET ultima_rodada_resumo = ? WHERE id = ?', [mb_substr($msg, 0, 300), $id]);
        Auditoria::registrar('campanha_rodada', 'campanha', $id, null, ['rodada' => $filha, 'data' => $data, 'onus' => count($escolhidas), 'desatualizadas' => $total], $p['uuid']);
        return $msg;
    }

    /**
     * Impressao digital do que a recorrente aprovou: se qualquer coisa mudar (a regra foi editada
     * ou trocou de firmware, PONs, limites, agenda), as rodadas param ate nova aprovacao.
     */
    public static function assinaturaConfig(array $c): string
    {
        $r = Db::um('SELECT versao, firmware_id, ativo FROM tab_zte_regra WHERE id = ?', [(int) $c['regra_id']]);
        return hash('sha256', json_encode([
            (int) $c['regra_id'], $r['versao'] ?? null, $r['firmware_id'] ?? null, $r['ativo'] ?? null, (int) $c['firmware_id'], (int) $c['olt_id'],
            json_decode((string) $c['escopo'], true), (int) $c['max_por_pon'], (int) $c['max_concorrentes'], (int) $c['max_falhas'],
            (int) $c['max_falhas_pct'], (int) $c['retentativas'], substr((string) $c['janela_inicio'], 0, 5), substr((string) $c['janela_fim'], 0, 5),
            (string) $c['dias_semana'], (int) $c['teto_rodada'],
        ]));
    }

    /**
     * ONUs cujo ULTIMO job nesta recorrente falhou (ou ficou inconclusivo) depois da ultima
     * liberacao: ficam fora das proximas rodadas. Falha por abortar (sem origem) nao conta.
     * @return int[]
     */
    public static function falhasDaFamilia(int $paiId): array
    {
        $liberadas = Db::valor('SELECT falhas_liberadas_em FROM tab_zte_campanha WHERE id = ?', [$paiId]);
        $ultimo = [];
        foreach (Db::todos('SELECT j.onu_id, j.estado, j.falha_origem, COALESCE(j.concluido_em, j.criado_em) AS quando
                              FROM tab_zte_job j JOIN tab_zte_campanha c ON c.id = j.campanha_id WHERE c.pai_id = ? ORDER BY j.id', [$paiId]) as $j) {
            $ultimo[(int) $j['onu_id']] = $j;
        }
        $fora = [];
        foreach ($ultimo as $onu => $j) {
            $falhou = ($j['estado'] === 'falha' && $j['falha_origem'] !== null) || $j['estado'] === 'inconclusivo';
            if ($falhou && ($liberadas === null || $j['quando'] > $liberadas)) {
                $fora[] = $onu;
            }
        }
        return $fora;
    }

    private static function rodadasAtivas(int $paiId): array
    {
        return Db::todos("SELECT id FROM tab_zte_campanha WHERE pai_id = ? AND estado IN ('aprovada','executando','pausada')", [$paiId]);
    }

    /** Algum job desta campanha (ou das rodadas dela) chegou a sair da fila? */
    private static function executou(int $id): bool
    {
        return (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job j JOIN tab_zte_campanha c ON c.id = j.campanha_id
                                 WHERE (c.id = ? OR c.pai_id = ?) AND j.tentativas > 0', [$id, $id]) > 0;
    }

    private static function voltarRascunho(array $c, string $usuario): void
    {
        Db::exec("UPDATE tab_zte_campanha SET estado = 'rascunho', simulacao = NULL, simulada_em = NULL, aprovacao_assinatura = NULL,
                         pausa_motivo = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?",
            [Erros::mensagem('ZTE-CAM-020'), $usuario, $c['id']]);
        Auditoria::registrar('campanha_reaprovar', 'campanha', (int) $c['id'], ['estado' => $c['estado']], ['estado' => 'rascunho',
            'motivo' => 'regra/firmware/configuração mudou desde a aprovação'], $c['uuid']);
    }

    private static function textoErro(ZteErro $e): string
    {
        $det = $e->detalhes();
        return $e->getMessage() . (isset($det['bloqueios']) ? ' ' . implode('; ', (array) $det['bloqueios']) : '');
    }

    // ================================================================ usadas pelo worker

    /** aprovada -> executando, no primeiro job iniciado dentro da janela. */
    public static function marcarExecutando(int $id): void
    {
        $c = self::linha($id);
        if (Db::exec("UPDATE tab_zte_campanha SET estado = 'executando', iniciada_em = COALESCE(iniciada_em, NOW()) WHERE id = ? AND estado = 'aprovada'", [$id]) === 1) {
            Auditoria::registrar('campanha_iniciar', 'campanha', $id, ['estado' => 'aprovada'], ['estado' => 'executando'], $c['uuid']);
        }
    }

    /** Fecha a campanha quando nao sobra job na fila, em curso ou inconclusivo. */
    public static function concluirSePuder(int $id): bool
    {
        $c = self::linha($id);
        if (!in_array($c['estado'], ['aprovada', 'executando'], true)) {
            return false;
        }
        $abertos = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ? AND estado NOT IN ('concluido','falha')", [$id]);
        if ($abertos > 0 || (int) Db::valor('SELECT COUNT(*) FROM tab_zte_job WHERE campanha_id = ?', [$id]) === 0) {
            return false;
        }
        Db::exec("UPDATE tab_zte_campanha SET estado = 'concluida', concluida_em = NOW() WHERE id = ?", [$id]);
        $j = JobServico::contagem($id);
        Auditoria::registrar('campanha_concluir', 'campanha', $id, ['estado' => $c['estado']],
            ['estado' => 'concluida', 'concluidos' => $j['concluido'], 'falhas' => $j['falha']], $c['uuid']);
        return true;
    }

    /** Pausa automatica (disjuntor, modo seguro, arquivo sumido). Fica na auditoria como "worker". */
    public static function pausarAutomatico(int $id, string $motivo): void
    {
        $c = self::linha($id);
        if (!in_array($c['estado'], ['aprovada', 'executando'], true)) {
            return;
        }
        self::mudarEstado($id, ['aprovada', 'executando'], 'pausada', 'worker', 'campanha_pausa_automatica', mb_substr($motivo, 0, 300));
        Log::aviso('campanha.pausa_automatica', ['campanha' => $id, 'motivo' => $motivo]);
        // Rodada parada (disjuntor, arquivo sumido...): a recorrente para junto e espera o operador.
        if ($c['tipo'] === 'rodada' && $c['pai_id'] !== null) {
            self::pausarAutomatico((int) $c['pai_id'], mb_substr('Rodada "' . $c['nome'] . '" pausou: ' . $motivo, 0, 300));
        }
    }

    // ================================================================ apoio

    public static function linha(int $id): array
    {
        $c = Db::um('SELECT * FROM tab_zte_campanha WHERE id = ?', [$id]);
        if ($c === null) {
            throw new ZteErro('ZTE-CAM-001', [], null, 404);
        }
        return $c;
    }

    private static function mudarEstado(int $id, array $de, string $para, string $usuario, string $acao, ?string $motivo): array
    {
        $c = self::linha($id);
        $n = Db::exec('UPDATE tab_zte_campanha SET estado = ?, pausa_motivo = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                        WHERE id = ? AND estado IN (' . implode(',', array_fill(0, count($de), '?')) . ')',
            array_merge([$para, $para === 'pausada' ? $motivo : null, $usuario, $id], $de));
        if ($n !== 1) {
            throw new ZteErro('ZTE-CAM-002', ['estado' => $c['estado']], null, 409);
        }
        Auditoria::registrar($acao, 'campanha', $id, ['estado' => $c['estado']], ['estado' => $para, 'motivo' => $motivo], $c['uuid']);
        return self::obter($id);
    }

    private static function verif(string $id, string $titulo, string $resultado, string $detalhe, bool $bloqueia): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'resultado' => $resultado, 'detalhe' => $detalhe, 'bloqueia' => $bloqueia];
    }

    private static function paraTela(array $c, bool $completo): array
    {
        foreach (['id', 'olt_id', 'firmware_id', 'max_por_pon', 'max_concorrentes', 'max_falhas', 'max_falhas_pct', 'retentativas', 'versao', 'ciencia_olt_ftp'] as $k) {
            $c[$k] = (int) $c[$k];
        }
        $c['regra_id'] = $c['regra_id'] === null ? null : (int) $c['regra_id'];
        if (($c['tipo'] ?? 'campanha') === 'avulsa') {
            $c['regra_nome'] = 'Atualização avulsa (inventário)';
        }
        $c['escopo'] = json_decode((string) $c['escopo'], true) ?: ['pons' => [], 'onus' => []];
        $c['janela_inicio'] = substr((string) $c['janela_inicio'], 0, 5);
        $c['janela_fim'] = substr((string) $c['janela_fim'], 0, 5);
        $c['jobs'] = JobServico::contagem($c['id']);
        $c['total_jobs'] = array_sum($c['jobs']);
        $sim = $c['simulacao'] ? json_decode($c['simulacao'], true) : null;
        $c['simulacao_resumo'] = $sim ? ['entram' => $sim['resumo']['entram'], 'fora' => $sim['resumo']['fora'], 'bloqueios' => $sim['bloqueios'],
                                         'lotes' => $sim['lotes'], 'versao_alvo' => $sim['resumo']['versao_alvo']] : null;
        $c['simulacao_vencida'] = $c['simulada_em'] !== null && strtotime($c['simulada_em']) < time() - Config::int('simulacao_validade_h') * 3600;
        $c['pai_id'] = $c['pai_id'] === null ? null : (int) $c['pai_id'];
        $c['arquivada'] = (bool) (int) $c['arquivada'];
        $c['teto_rodada'] = $c['teto_rodada'] === null ? null : (int) $c['teto_rodada'];
        $c['dias'] = $c['dias_semana'] ? array_map('intval', explode(',', (string) $c['dias_semana'])) : [];
        $c['dias_texto'] = implode(', ', array_map(fn($x) => self::DIAS[$x] ?? '?', $c['dias']));
        $c['executou'] = self::executou((int) $c['id']);
        if ($c['tipo'] === 'recorrente') {
            $r = Db::um("SELECT COUNT(*) AS n, SUM(estado IN ('aprovada','executando','pausada')) AS ativas FROM tab_zte_campanha WHERE pai_id = ?", [$c['id']]);
            $c['rodadas_total'] = (int) $r['n'];
            $c['rodadas_ativas'] = (int) $r['ativas'];
        }
        if ($completo) {
            $c['simulacao'] = $sim;
            $c['motivos'] = self::MOTIVOS;
            if ($c['tipo'] === 'recorrente') {
                $c['rodadas'] = array_map(fn($f) => ['id' => (int) $f['id'], 'nome' => $f['nome'], 'estado' => $f['estado'], 'criado_em' => $f['criado_em'],
                                                      'jobs' => JobServico::contagem((int) $f['id'])],
                    Db::todos('SELECT id, nome, estado, criado_em FROM tab_zte_campanha WHERE pai_id = ? ORDER BY id DESC LIMIT 30', [$c['id']]));
                $c['falhas_excluidas'] = count(self::falhasDaFamilia((int) $c['id']));
            }
        } else {
            unset($c['simulacao']);
        }
        return $c;
    }
}
