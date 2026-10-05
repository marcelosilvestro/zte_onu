<?php
/**
 * zte_onu :: inventario de ONUs — SOMENTE LEITURA na OLT.
 *
 * Fluxo, sempre pelo OltServico::executarLeitura (trava por OLT, bloqueio por falha de login):
 *   1. descobrir   quais slots/portas sao PON (uma consulta por porta; erro de parametro = nao existe)
 *   2. lerPon      por PON: state + baseinfo (2 comandos) e, so quando preciso, detail-info/equip
 *                  por ONU — cada chamada e curta, a tela mostra o progresso PON a PON
 *   3. finalizar   snapshot para o painel
 *
 * Detalhe por ONU (o comando caro) so e lido quando: a posicao e nova, o SN mudou, faltam dados,
 * ou o modo e "completo". equip (modelo/HW) e versao de software sao lidos de QUALQUER fabricante
 * online: a Furukawa bridge (chipset ZTE) responde aos dois pelo OMCI (validado 05/10). Ler nao e
 * atualizar: so FORNECEDOR_ATUALIZAVEL entra em regra, campanha e avulsa.
 *
 * Posicao que sumiu da OLT nao e apagada (historico de campanha aponta para ela): ganha
 * ausente_desde e estado "desconhecido".
 */
require_once __DIR__ . '/../Core/carregar.php';

final class InventarioServico
{
    public const FORNECEDOR_ATUALIZAVEL = 'ZTEG';
    private const MAX_PORTAS = 16;
    /** Idade maxima da versao de software lida antes de reler na atualizacao rapida. */
    public const HORAS_SW = 24;
    /** Valor do filtro "versao em uso" para as ONUs sem versao lida. */
    public const SW_NAO_LIDA = '__nao_lida__';

    /** Versoes em ordem natural decrescente (a mais nova primeiro: P3N10 antes de P1N52). */
    private static function versoesOrdenadas(array $v): array
    {
        usort($v, fn($a, $b) => strnatcasecmp($b, $a));
        return $v;
    }

    // ================================================================ leitura na OLT

    /** Descobre as PONs da OLT e grava em tab_zte_olt.pons_detectadas. */
    public static function descobrir(int $oltId, string $usuario, ?Transporte $t = null): array
    {
        $olt = OltServico::linha($oltId);
        $placas = $olt['placas_detectadas'] ? (json_decode($olt['placas_detectadas'], true) ?: []) : [];
        if (!$placas) {
            throw new ZteErro('ZTE-INV-001');
        }
        $pons = OltServico::executarLeitura($oltId, function ($drv) use ($placas) {
            $achadas = [];
            foreach ($placas as $p) {
                $slot = (int) $p['slot'];
                $respondeu = false;
                for ($porta = 1; $porta <= self::MAX_PORTAS; $porta++) {
                    try {
                        $e = $drv->estadoPon($slot, $porta);
                        $achadas[] = ['slot' => $slot, 'pon' => $porta, 'onus' => $e['total'], 'formato' => 'ok'];
                        $respondeu = true;
                    } catch (OltFalha $f) {
                        if ($f->tipo() === 'comando') {
                            // Recusada ja na porta 1: a placa nao e de PON (ex.: controladora).
                            // Depois de uma porta valida, uma recusa so pula AQUELA porta: a OLT
                            // real recusou a 2/12 e havia ONUs na 2/13-2/16 (01/10).
                            if (!$respondeu) {
                                break;
                            }
                            continue;
                        }
                        if ($f->tipo() !== 'formato') {
                            throw $f;            // queda/timeout: aborta, nao inventa resultado
                        }
                        // ⏳ porta existe mas a saida (provavelmente PON vazia) tem formato desconhecido
                        $achadas[] = ['slot' => $slot, 'pon' => $porta, 'onus' => null, 'formato' => 'desconhecido'];
                    }
                }
            }
            return $achadas;
        }, $t);

        Db::exec('UPDATE tab_zte_olt SET pons_detectadas = ? WHERE id = ?', [json_encode($pons), $oltId]);
        Auditoria::registrar('inventario_descobrir', 'olt', $oltId, null, ['pons' => count($pons)]);
        return $pons;
    }

    /**
     * Le uma PON e atualiza tab_zte_onu.
     * @return array{slot:int,pon:int,total:int,online:int,novas:int,alteradas:int,ausentes:int,detalhes_lidos:int,aviso:?string}
     */
    public static function lerPon(int $oltId, int $slot, int $pon, bool $completo, string $usuario, ?Transporte $t = null): array
    {
        $existentes = [];
        foreach (Db::todos('SELECT * FROM tab_zte_onu WHERE olt_id = ? AND slot = ? AND porta = ?', [$oltId, $slot, $pon]) as $r) {
            $existentes[(int) $r['onu_num']] = $r;
        }

        $lido = OltServico::executarLeitura($oltId, function ($drv) use ($slot, $pon, $completo, $existentes) {
            $estado = $drv->estadoPon($slot, $pon);
            $base = $estado['total'] > 0 ? $drv->basePon($slot, $pon) : [];
            $onus = [];
            $detalhes = 0;

            // Versao de software: qualquer fabricante online; relida quando falta, quando a ONU mudou,
            // a cada HORAS_SW ou na leitura completa (uma ONU pode ter sido atualizada por fora).
            $precisaSw = [];
            foreach ($estado['onus'] as $o) {
                $n = $o['onu'];
                $b = $base[$n] ?? null;
                $velha = $existentes[$n] ?? null;
                $sn = $b['sn'] ?? '';
                if (($b['fornecedor'] ?? '') !== '' && $o['online']
                    && ($completo || $velha === null || ($sn !== '' && $velha['sn'] !== $sn) || $velha['sw_versao'] === null
                        || $velha['sw_lido_em'] === null || strtotime($velha['sw_lido_em']) < time() - self::HORAS_SW * 3600)) {
                    $precisaSw[$n] = true;
                }
            }
            // Uma faixa (min-max) num comando so; se a OLT recusar a faixa, uma a uma.
            $versoes = [];
            if (count($precisaSw) > 1) {
                try {
                    $versoes = $drv->versaoSwFaixa($slot, $pon, min(array_keys($precisaSw)), max(array_keys($precisaSw)));
                    $detalhes++;
                } catch (OltFalha $f) {
                    if (!in_array($f->tipo(), ['comando', 'formato'], true)) {
                        throw $f;
                    }
                }
            }
            foreach (array_keys($precisaSw) as $n) {
                if (!isset($versoes[$n])) {
                    try {
                        $versoes[$n] = $drv->versaoSw($slot, $pon, $n);
                        $detalhes++;
                    } catch (OltFalha $f) {
                        if (!in_array($f->tipo(), ['comando', 'formato'], true)) {
                            throw $f;
                        }
                    }
                }
            }

            foreach ($estado['onus'] as $o) {
                $n = $o['onu'];
                $b = $base[$n] ?? null;
                $velha = $existentes[$n] ?? null;
                $sn = $b['sn'] ?? '';
                $fornecedor = $b['fornecedor'] ?? '';
                $linha = ['onu' => $n, 'fase' => $o['fase'], 'online' => $o['online'], 'sn' => $sn ?: null,
                          'fornecedor' => $fornecedor ?: null, 'tipo_perfil' => $b['tipo'] ?? null, 'detalhe' => null, 'equip' => null,
                          'sw' => isset($precisaSw[$n]) ? ($versoes[$n] ?? null) : null];

                $precisaDetalhe = $completo || $velha === null || ($sn !== '' && $velha['sn'] !== $sn) || $velha['detalhe_em'] === null;
                if ($precisaDetalhe) {
                    try {
                        $linha['detalhe'] = $drv->detalheOnu($slot, $pon, $n);
                        $detalhes++;
                    } catch (OltFalha $f) {
                        if (!in_array($f->tipo(), ['comando', 'formato'], true)) {
                            throw $f;
                        }
                    }
                    if ($fornecedor !== '' && $o['online']
                        // hw_versao vazio tambem: o modelo pode ter vindo do RuType (que nao traz o
                        // HW) num inventario em que a ONU estava offline — sem isso ela nunca entra
                        // em regra nenhuma (02/10: 5 ONUs da olt1 assim).
                        && ($completo || $velha === null || $velha['modelo'] === null || $velha['hw_versao'] === null
                            || ($sn !== '' && $velha['sn'] !== $sn))) {
                        try {
                            $linha['equip'] = $drv->equipOnu($slot, $pon, $n);
                            $detalhes++;
                        } catch (OltFalha $f) {
                            if (!in_array($f->tipo(), ['comando', 'formato'], true)) {
                                throw $f;
                            }
                        }
                    }
                }
                $onus[] = $linha;
            }
            return ['estado' => $estado, 'onus' => $onus, 'detalhes' => $detalhes];
        }, $t);

        $novas = 0;
        $alteradas = 0;
        $vistas = [];
        Db::transacao(function () use ($oltId, $slot, $pon, $lido, $existentes, &$novas, &$alteradas, &$vistas) {
            foreach ($lido['onus'] as $o) {
                $n = $o['onu'];
                $vistas[$n] = true;
                $velha = $existentes[$n] ?? null;
                $d = $o['detalhe'];
                $q = $o['equip'];
                $sw = $o['sw'];
                $estado = $o['online'] ? 'online' : 'offline';
                if ($velha === null) {
                    Db::exec('INSERT INTO tab_zte_onu (olt_id, slot, porta, onu_num, sn, fornecedor, tipo_perfil, modelo, hw_versao, sw_versao, sw_standby,
                                     sw_lido_em, estado, fase, nome, descricao, login_cliente, visto_em, detalhe_em, atualizado_em)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())',
                        [$oltId, $slot, $pon, $n, $o['sn'], $o['fornecedor'], $o['tipo_perfil'], $q['modelo'] ?? null, $q['hw_versao'] ?? null,
                         $sw['ativa'] ?? null, $sw['standby'] ?? null, $sw !== null ? date('Y-m-d H:i:s') : null,
                         $estado, $o['fase'], $d['nome'] ?? null, isset($d['descricao']) ? mb_substr($d['descricao'], 0, 255) : null,
                         self::login($d['nome'] ?? null), $d !== null ? date('Y-m-d H:i:s') : null]);
                    $novas++;
                    continue;
                }
                $snMudou = $o['sn'] !== null && $velha['sn'] !== null && $o['sn'] !== $velha['sn'];
                $sets = ['estado = ?', 'fase = ?', 'visto_em = NOW()', 'ausente_desde = NULL', 'atualizado_em = NOW()'];
                $p = [$estado, $o['fase']];
                foreach (['sn' => $o['sn'], 'fornecedor' => $o['fornecedor'], 'tipo_perfil' => $o['tipo_perfil']] as $c => $v) {
                    if ($v !== null) {
                        $sets[] = "$c = ?";
                        $p[] = $v;
                    }
                }
                if ($snMudou && $sw === null) {
                    // Outra ONU na mesma posicao: o modelo/HW/versao antigos nao valem mais.
                    $sets[] = 'modelo = NULL';
                    $sets[] = 'hw_versao = NULL';
                    $sets[] = 'sw_versao = NULL';
                    $sets[] = 'sw_standby = NULL';
                    $sets[] = 'sw_lido_em = NULL';
                } elseif ($snMudou && $q === null) {
                    $sets[] = 'hw_versao = NULL';
                }
                if ($d !== null) {
                    array_push($sets, 'nome = ?', 'descricao = ?', 'login_cliente = ?', 'detalhe_em = NOW()');
                    array_push($p, $d['nome'], mb_substr($d['descricao'], 0, 255), self::login($d['nome']));
                }
                if ($q !== null) {
                    array_push($sets, 'modelo = ?', 'hw_versao = ?');
                    array_push($p, $q['modelo'], $q['hw_versao']);
                }
                if ($sw !== null) {
                    array_push($sets, 'sw_versao = ?', 'sw_standby = ?', 'sw_lido_em = NOW()');
                    array_push($p, $sw['ativa'], $sw['standby']);
                    // O RuType da OLT tambem traz o modelo: completa quando o equip nao foi lido.
                    if ($q === null && ($velha['modelo'] === null || $snMudou) && $sw['modelo'] !== '') {
                        $sets[] = 'modelo = ?';
                        $p[] = $sw['modelo'];
                    }
                }
                $p[] = $velha['id'];
                Db::exec('UPDATE tab_zte_onu SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);
                if ($snMudou || $velha['estado'] !== $estado || ($d !== null && $d['nome'] !== $velha['nome'])) {
                    $alteradas++;
                }
            }
            // Posicoes que sumiram da OLT: marcadas, nunca apagadas.
            foreach ($existentes as $n => $velha) {
                if (!isset($vistas[$n]) && $velha['ausente_desde'] === null) {
                    Db::exec("UPDATE tab_zte_onu SET estado = 'desconhecido', ausente_desde = NOW(), atualizado_em = NOW() WHERE id = ?", [$velha['id']]);
                }
            }
        });
        $ausentes = count(array_diff_key($existentes, $vistas));
        Log::info('inventario.pon', ['olt' => $oltId, 'slot' => $slot, 'pon' => $pon, 'total' => $lido['estado']['total'], 'detalhes' => $lido['detalhes']]);
        return ['slot' => $slot, 'pon' => $pon, 'total' => $lido['estado']['total'], 'online' => $lido['estado']['online'],
                'novas' => $novas, 'alteradas' => $alteradas, 'ausentes' => $ausentes, 'detalhes_lidos' => $lido['detalhes'],
                'aviso' => $lido['estado']['aviso']];
    }

    /** Fecha uma passada de inventario: snapshot para o painel e auditoria. */
    public static function finalizar(int $oltId, array $resumoPons, string $usuario): array
    {
        OltServico::linha($oltId);
        $r = Db::um("SELECT COUNT(*) AS total, SUM(estado = 'online') AS online, SUM(estado = 'offline') AS offline
                       FROM tab_zte_onu WHERE olt_id = ? AND ausente_desde IS NULL", [$oltId]);
        $porModelo = Db::todos("SELECT COALESCE(modelo, '') AS modelo, COALESCE(hw_versao, '') AS hw_versao, COALESCE(sw_versao, '') AS sw_versao, COUNT(*) AS n
                                  FROM tab_zte_onu WHERE olt_id = ? AND ausente_desde IS NULL GROUP BY 1, 2, 3", [$oltId]);
        Db::exec('INSERT INTO tab_zte_onu_snapshot (olt_id, tirado_em, total, online, offline, por_versao) VALUES (?, NOW(), ?, ?, ?, ?)',
            [$oltId, (int) $r['total'], (int) $r['online'], (int) $r['offline'], json_encode($porModelo)]);
        Db::exec('UPDATE tab_zte_olt SET inventario_em = NOW() WHERE id = ?', [$oltId]);
        Auditoria::registrar('inventario_atualizar', 'olt', $oltId, null,
            ['total' => (int) $r['total'], 'online' => (int) $r['online'], 'pons' => count($resumoPons),
             'novas' => array_sum(array_column($resumoPons, 'novas'))]);
        return ['total' => (int) $r['total'], 'online' => (int) $r['online'], 'offline' => (int) $r['offline']];
    }

    /** Releitura de UMA ONU (detalhe + equip), a pedido do operador. */
    public static function lerOnu(int $onuId, string $usuario, ?Transporte $t = null): array
    {
        $o = self::linhaOnu($onuId);
        $res = OltServico::executarLeitura((int) $o['olt_id'], function ($drv) use ($o) {
            $d = $drv->detalheOnu((int) $o['slot'], (int) $o['porta'], (int) $o['onu_num']);
            $q = null;
            $sw = null;
            // Qualquer fabricante: falha de comando/formato so deixa o campo sem leitura.
            if (($o['fornecedor'] ?? '') !== '' || $d['sn'] !== '') {
                foreach (['q' => 'equipOnu', 'sw' => 'versaoSw'] as $var => $metodo) {
                    try {
                        $$var = $drv->$metodo((int) $o['slot'], (int) $o['porta'], (int) $o['onu_num']);
                    } catch (OltFalha $f) {
                        if (!in_array($f->tipo(), ['comando', 'formato'], true)) {
                            throw $f;
                        }
                    }
                }
            }
            return ['d' => $d, 'q' => $q, 'sw' => $sw];
        }, $t);
        $d = $res['d'];
        $q = $res['q'];
        $sw = $res['sw'];
        $online = in_array($d['fase'], ParserZteC320V21::FASES_ONLINE, true);
        Db::exec('UPDATE tab_zte_onu SET nome = ?, descricao = ?, login_cliente = ?, sn = ?, fornecedor = ?, fase = ?, estado = ?,
                         modelo = COALESCE(?, modelo), hw_versao = COALESCE(?, hw_versao),
                         sw_versao = IF(? = 1, ?, sw_versao), sw_standby = IF(? = 1, ?, sw_standby), sw_lido_em = IF(? = 1, NOW(), sw_lido_em),
                         detalhe_em = NOW(), visto_em = NOW(), ausente_desde = NULL, atualizado_em = NOW() WHERE id = ?',
            [$d['nome'], mb_substr($d['descricao'], 0, 255), self::login($d['nome']), $d['sn'] ?: $o['sn'], $d['sn'] ? substr($d['sn'], 0, 4) : $o['fornecedor'],
             $d['fase'], $online ? 'online' : 'offline', $q['modelo'] ?? null, $q['hw_versao'] ?? null,
             $sw ? 1 : 0, $sw['ativa'] ?? null, $sw ? 1 : 0, $sw['standby'] ?? null, $sw ? 1 : 0, $onuId]);
        return self::obterOnu($onuId);
    }

    // ================================================================ consulta

    /**
     * Lista paginada com filtros combinaveis.
     * @return array{total:int,linhas:array,pagina:int,por_pagina:int}
     */
    public static function listar(array $f, int $pagina, int $porPagina = 50): array
    {
        [$where, $p] = self::filtro($f);
        $total = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_onu o JOIN tab_zte_olt t ON t.id = o.olt_id $where", $p);
        $pagina = max(1, $pagina);
        $off = ($pagina - 1) * $porPagina;
        $ordem = [
            'posicao' => 't.nome, o.slot, o.porta, o.onu_num', 'nome' => 'o.nome, t.nome, o.slot, o.porta, o.onu_num',
            'modelo' => 'o.modelo, o.hw_versao, o.slot, o.porta, o.onu_num', 'estado' => 'o.estado, o.slot, o.porta, o.onu_num',
        ][$f['ordem'] ?? 'posicao'] ?? 't.nome, o.slot, o.porta, o.onu_num';
        $linhas = Db::todos("SELECT o.*, t.nome AS olt_nome FROM tab_zte_onu o JOIN tab_zte_olt t ON t.id = o.olt_id
                              $where ORDER BY $ordem LIMIT $porPagina OFFSET $off", $p);
        $nomes = self::nomesClientes(array_filter(array_column($linhas, 'login_cliente')));
        $alvos = self::alvos();
        foreach ($linhas as &$l) {
            $l = self::paraTela($l, $alvos);
            $l['cliente'] = $nomes[strtolower((string) $l['login_cliente'])] ?? null;
        }
        unset($l);
        return ['total' => $total, 'linhas' => $linhas, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public static function obterOnu(int $id): array
    {
        $o = self::paraTela(Db::um('SELECT o.*, t.nome AS olt_nome FROM tab_zte_onu o JOIN tab_zte_olt t ON t.id = o.olt_id WHERE o.id = ?', [$id])
            ?? throw new ZteErro('ZTE-INV-002', [], null, 404), self::alvos());
        $o['cliente'] = self::nomesClientes([$o['login_cliente']])[strtolower((string) $o['login_cliente'])] ?? null;
        $o['firmwares_compativeis'] = $o['modelo'] ? Db::todos(
            "SELECT f.id, f.modelo_familia, f.versao_firmware, f.estado FROM tab_zte_firmware f
               JOIN tab_zte_firmware_compat c ON c.firmware_id = f.id
              WHERE c.modelo = ? AND c.hw_versao = ? AND f.estado <> 'desativado' ORDER BY f.versao_firmware", [$o['modelo'], $o['hw_versao']]) : [];
        return $o;
    }

    /** Valores distintos para os filtros da tela. */
    public static function opcoesFiltro(): array
    {
        return [
            'olts'        => Db::todos('SELECT id, nome FROM tab_zte_olt ORDER BY nome'),
            'pons'        => Db::todos('SELECT DISTINCT olt_id, slot, porta FROM tab_zte_onu ORDER BY olt_id, slot, porta'),
            'modelos'     => array_column(Db::todos("SELECT DISTINCT modelo FROM tab_zte_onu WHERE modelo IS NOT NULL ORDER BY modelo"), 'modelo'),
            'hws'         => array_column(Db::todos("SELECT DISTINCT hw_versao FROM tab_zte_onu WHERE hw_versao IS NOT NULL ORDER BY hw_versao"), 'hw_versao'),
            'fornecedores' => array_column(Db::todos("SELECT DISTINCT fornecedor FROM tab_zte_onu WHERE fornecedor IS NOT NULL ORDER BY fornecedor"), 'fornecedor'),
            'fases'       => array_column(Db::todos("SELECT DISTINCT fase FROM tab_zte_onu WHERE fase IS NOT NULL ORDER BY fase"), 'fase'),
            'versoes'     => self::versoesOrdenadas(array_column(Db::todos(
                                 "SELECT DISTINCT sw_versao FROM tab_zte_onu WHERE sw_versao IS NOT NULL AND ausente_desde IS NULL"), 'sw_versao')),
        ];
    }

    /**
     * Arvore OLT -> slot -> PON com contagens, distribuicao de modelos (com as versoes de cada um),
     * fabricantes e a situacao do firmware (em dia / desatualizada / sem referencia, pela mesma
     * regra de desatualizada()).
     */
    public static function topologia(): array
    {
        $olts = [];
        foreach (Db::todos('SELECT id, nome, protocolo, versao_detectada, placas_detectadas, pons_detectadas, inventario_em, ativo,
                                   ultimo_teste_em, ultimo_teste_resultado FROM tab_zte_olt ORDER BY nome') as $t) {
            $olts[(int) $t['id']] = [
                'id' => (int) $t['id'], 'nome' => $t['nome'], 'simulada' => $t['protocolo'] === 'simulado', 'ativo' => (int) $t['ativo'],
                'versao' => $t['versao_detectada'], 'inventario_em' => $t['inventario_em'],
                'teste_em' => $t['ultimo_teste_em'], 'teste_resultado' => $t['ultimo_teste_resultado'],
                'placas' => $t['placas_detectadas'] ? (json_decode($t['placas_detectadas'], true) ?: []) : [],
                'pons_detectadas' => $t['pons_detectadas'] ? (json_decode($t['pons_detectadas'], true) ?: []) : [],
                'pons' => [],
            ];
        }
        $linhas = Db::todos("SELECT olt_id, slot, porta, COUNT(*) AS total, SUM(estado = 'online') AS online, SUM(estado = 'offline') AS offline,
                                    SUM(ausente_desde IS NOT NULL) AS ausentes, SUM(fornecedor = ?) AS zte,
                                    SUM(fornecedor = ? AND estado = 'online' AND modelo IS NULL) AS sem_modelo
                               FROM tab_zte_onu GROUP BY olt_id, slot, porta ORDER BY olt_id, slot, porta",
            [self::FORNECEDOR_ATUALIZAVEL, self::FORNECEDOR_ATUALIZAVEL]);
        $alvos = self::alvos();
        $modelos = $firmware = $outros = [];
        foreach (Db::todos("SELECT olt_id, slot, porta, fornecedor, modelo, hw_versao, sw_versao, COUNT(*) AS n
                              FROM tab_zte_onu WHERE ausente_desde IS NULL GROUP BY 1, 2, 3, 4, 5, 6, 7") as $m) {
            $k = $m['olt_id'] . '/' . $m['slot'] . '/' . $m['porta'];
            $n = (int) $m['n'];
            if ($m['fornecedor'] !== self::FORNECEDOR_ATUALIZAVEL) {
                // Outro fabricante: modelo e versao so para consulta (nunca entra em firmware/regra).
                $km = ($m['fornecedor'] ?? '?') . '|' . ($m['modelo'] ?? '?') . '|' . ($m['hw_versao'] ?? '');
                $outros[$k][$km] = $outros[$k][$km] ?? ['fornecedor' => $m['fornecedor'] ?? '?', 'modelo' => $m['modelo'] ?? '?',
                                                        'hw' => $m['hw_versao'] ?? '', 'n' => 0, 'versoes' => []];
                $outros[$k][$km]['n'] += $n;
                $outros[$k][$km]['versoes'][] = ['sw' => $m['sw_versao'], 'n' => $n, 'desatualizada' => null];
                continue;
            }
            $des = self::desatualizada($m, $alvos);
            $firmware[$k] = $firmware[$k] ?? ['em_dia' => 0, 'desatualizadas' => 0, 'sem_referencia' => 0];
            $firmware[$k][$des === null ? 'sem_referencia' : ($des ? 'desatualizadas' : 'em_dia')] += $n;
            $km = ($m['modelo'] ?? '?') . '|' . ($m['hw_versao'] ?? '');
            $modelos[$k][$km] = $modelos[$k][$km] ?? ['modelo' => $m['modelo'] ?? '?', 'hw' => $m['hw_versao'] ?? '', 'n' => 0, 'versoes' => []];
            $modelos[$k][$km]['n'] += $n;
            $modelos[$k][$km]['versoes'][] = ['sw' => $m['sw_versao'], 'n' => $n, 'desatualizada' => $des];
        }
        $fabricantes = [];
        foreach (Db::todos("SELECT olt_id, slot, porta, COALESCE(fornecedor, '?') AS fornecedor, COUNT(*) AS n
                              FROM tab_zte_onu WHERE ausente_desde IS NULL GROUP BY 1, 2, 3, 4 ORDER BY n DESC") as $f) {
            $fabricantes[$f['olt_id'] . '/' . $f['slot'] . '/' . $f['porta']][] = ['fornecedor' => $f['fornecedor'], 'n' => (int) $f['n']];
        }
        foreach ($linhas as $l) {
            if (!isset($olts[(int) $l['olt_id']])) {
                continue;
            }
            $k = $l['olt_id'] . '/' . $l['slot'] . '/' . $l['porta'];
            $olts[(int) $l['olt_id']]['pons'][] = [
                'slot' => (int) $l['slot'], 'pon' => (int) $l['porta'], 'total' => (int) $l['total'], 'online' => (int) $l['online'],
                'offline' => (int) $l['offline'], 'ausentes' => (int) $l['ausentes'], 'zte' => (int) $l['zte'],
                'sem_modelo' => (int) $l['sem_modelo'], 'modelos' => array_values($modelos[$k] ?? []),
                'modelos_outros' => array_values($outros[$k] ?? []),
                'firmware' => $firmware[$k] ?? ['em_dia' => 0, 'desatualizadas' => 0, 'sem_referencia' => 0],
                'fabricantes' => $fabricantes[$k] ?? [],
            ];
        }
        return array_values($olts);
    }

    /** Numeros do painel. */
    public static function resumoPainel(): array
    {
        $modelos = Db::todos("SELECT COALESCE(modelo, 'Modelo não lido') AS modelo, COALESCE(hw_versao, '') AS hw,
                                     COALESCE(sw_versao, 'versão não lida') AS sw, COUNT(*) AS n
                                FROM tab_zte_onu WHERE ausente_desde IS NULL AND fornecedor = ?
                            GROUP BY 1, 2, 3 ORDER BY n DESC LIMIT 14", [self::FORNECEDOR_ATUALIZAVEL]);
        $alvos = self::alvos();
        foreach ($modelos as &$m) {
            $m['desatualizada'] = self::desatualizada(['fornecedor' => self::FORNECEDOR_ATUALIZAVEL, 'modelo' => $m['modelo'],
                                                       'hw_versao' => $m['hw'], 'sw_versao' => $m['sw'] === 'versão não lida' ? null : $m['sw']], $alvos);
        }
        unset($m);
        $outros = Db::todos("SELECT COALESCE(fornecedor, '?') AS fornecedor, COUNT(*) AS n FROM tab_zte_onu
                              WHERE ausente_desde IS NULL AND (fornecedor IS NULL OR fornecedor <> ?) GROUP BY 1 ORDER BY n DESC",
            [self::FORNECEDOR_ATUALIZAVEL]);
        $pons = Db::todos("SELECT t.nome AS olt, o.slot, o.porta, COUNT(*) AS total, SUM(o.estado = 'online') AS online
                             FROM tab_zte_onu o JOIN tab_zte_olt t ON t.id = o.olt_id
                            WHERE o.ausente_desde IS NULL GROUP BY t.nome, o.slot, o.porta ORDER BY total DESC LIMIT 16");
        $hist = Db::todos("SELECT DATE(tirado_em) AS dia, MAX(total) AS total, MAX(online) AS online FROM tab_zte_onu_snapshot
                            WHERE tirado_em >= NOW() - INTERVAL 30 DAY GROUP BY DATE(tirado_em) ORDER BY dia");
        $sw = (int) Db::valor('SELECT COUNT(*) FROM tab_zte_onu WHERE sw_versao IS NOT NULL');
        return ['modelos' => $modelos, 'outros_fornecedores' => $outros, 'pons' => $pons, 'historico' => $hist,
                'versao_sw_disponivel' => $sw > 0, 'desatualizadas' => self::contarDesatualizadas()];
    }

    /** Quantas ONUs presentes estao abaixo da versao disponivel para o seu modelo x HW. */
    public static function contarDesatualizadas(): int
    {
        $alvos = self::alvos();
        if (!$alvos) {
            return 0;
        }
        $n = 0;
        foreach (Db::todos("SELECT modelo, hw_versao, sw_versao, COUNT(*) AS q FROM tab_zte_onu
                             WHERE ausente_desde IS NULL AND fornecedor = ? AND sw_versao IS NOT NULL GROUP BY 1, 2, 3",
                     [self::FORNECEDOR_ATUALIZAVEL]) as $g) {
            if (self::desatualizada(['fornecedor' => self::FORNECEDOR_ATUALIZAVEL] + $g, $alvos)) {
                $n += (int) $g['q'];
            }
        }
        return $n;
    }

    // ================================================================ apoio

    public static function linhaOnu(int $id): array
    {
        $o = Db::um('SELECT * FROM tab_zte_onu WHERE id = ?', [$id]);
        if ($o === null) {
            throw new ZteErro('ZTE-INV-002', [], null, 404);
        }
        return $o;
    }

    /** O nome da ONU na OLT e o login do cliente no MK-AUTH (padrao do provisionamento). */
    private static function login(?string $nome): ?string
    {
        $nome = trim((string) $nome);
        return $nome !== '' && preg_match('/^[A-Za-z0-9._@-]{1,60}$/', $nome) ? $nome : null;
    }

    /**
     * Nome do cliente no MK-AUTH (sis_cliente, somente leitura). Consulta separada com parametros:
     * as tabelas nativas sao latin1 e um JOIN por texto com as nossas (utf8mb4) da erro de collation.
     *
     * @return array<string,array{nome:string,ativo:bool}> por login em minusculas
     */
    private static function nomesClientes(array $logins): array
    {
        $logins = array_values(array_unique(array_filter(array_map('strval', $logins))));
        if (!$logins || !Db::tabelaExiste('sis_cliente')) {
            return [];
        }
        $saida = [];
        foreach (array_chunk($logins, 200) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            foreach (Db::todos("SELECT login, nome, cli_ativado FROM sis_cliente WHERE login IN ($in)", $lote) as $c) {
                $saida[strtolower((string) $c['login'])] = ['nome' => (string) $c['nome'], 'ativo' => ($c['cli_ativado'] ?? 's') === 's'];
            }
        }
        return $saida;
    }

    private static function filtro(array $f): array
    {
        $w = [];
        $p = [];
        if (!empty($f['olt_id']))    { $w[] = 'o.olt_id = ?'; $p[] = (int) $f['olt_id']; }
        if (!empty($f['pon']) && preg_match('#^(\d+)/(\d+)$#', (string) $f['pon'], $m)) { $w[] = 'o.slot = ? AND o.porta = ?'; $p[] = (int) $m[1]; $p[] = (int) $m[2]; }
        if (!empty($f['estado']))    { $w[] = 'o.estado = ?'; $p[] = (string) $f['estado']; }
        if (!empty($f['fase']))      { $w[] = 'o.fase = ?'; $p[] = (string) $f['fase']; }
        if (!empty($f['modelo']))    { $w[] = 'o.modelo = ?'; $p[] = (string) $f['modelo']; }
        if (!empty($f['hw']))        { $w[] = 'o.hw_versao = ?'; $p[] = (string) $f['hw']; }
        if (!empty($f['fornecedor'])) { $w[] = 'o.fornecedor = ?'; $p[] = (string) $f['fornecedor']; }
        if (($f['sw'] ?? '') === self::SW_NAO_LIDA) {
            $w[] = 'o.sw_versao IS NULL';
        } elseif (!empty($f['sw'])) {
            $w[] = 'o.sw_versao = ?';
            $p[] = (string) $f['sw'];
        }
        if (($f['ausentes'] ?? '') === '1') {
            $w[] = 'o.ausente_desde IS NOT NULL';
        } elseif (($f['ausentes'] ?? '') !== 'todas') {
            $w[] = 'o.ausente_desde IS NULL';
        }
        if (!empty($f['busca'])) {
            $b = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['busca']) . '%';
            $w[] = '(o.nome LIKE ? OR o.sn LIKE ? OR o.login_cliente LIKE ? OR o.descricao LIKE ?)';
            array_push($p, $b, $b, $b, $b);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /**
     * A versao mais nova DISPONIVEL para cada modelo x HW (comparacao natural: P3N10 > P1N52).
     * @return array<string,string> "MODELO|HW" em maiusculas => versao
     */
    public static function alvos(): array
    {
        $alvos = [];
        foreach (Db::todos("SELECT c.modelo, c.hw_versao, f.versao_firmware FROM tab_zte_firmware f
                              JOIN tab_zte_firmware_compat c ON c.firmware_id = f.id WHERE f.estado = 'disponivel'") as $r) {
            $k = strtoupper($r['modelo'] . '|' . $r['hw_versao']);
            if (!isset($alvos[$k]) || strnatcasecmp($r['versao_firmware'], $alvos[$k]) > 0) {
                $alvos[$k] = $r['versao_firmware'];
            }
        }
        return $alvos;
    }

    /** true/false; null quando nao da para dizer (versao nao lida, sem firmware para o modelo, fora do escopo). */
    public static function desatualizada(array $o, array $alvos): ?bool
    {
        if (($o['fornecedor'] ?? '') !== self::FORNECEDOR_ATUALIZAVEL || empty($o['sw_versao']) || empty($o['modelo'])) {
            return null;
        }
        $alvo = $alvos[strtoupper($o['modelo'] . '|' . ($o['hw_versao'] ?? ''))] ?? null;
        return $alvo === null ? null : strnatcasecmp($alvo, (string) $o['sw_versao']) > 0;
    }

    private static function paraTela(array $o, array $alvos = []): array
    {
        foreach (['id', 'olt_id', 'slot', 'porta', 'onu_num'] as $c) {
            $o[$c] = (int) $o[$c];
        }
        $o['posicao'] = $o['slot'] . '/' . $o['porta'] . ':' . $o['onu_num'];
        $o['atualizavel'] = $o['fornecedor'] === self::FORNECEDOR_ATUALIZAVEL;
        $o['versao_alvo'] = $alvos[strtoupper(($o['modelo'] ?? '') . '|' . ($o['hw_versao'] ?? ''))] ?? null;
        $o['desatualizada'] = self::desatualizada($o, $alvos);
        return $o;
    }
}
