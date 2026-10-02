<?php
/**
 * zte_onu :: regras de atualizacao — so compatibilidade e selecao, nunca executam nada.
 *
 * Uma regra diz: "ONUs ZTE do modelo M, com uma destas revisoes de HW, que estejam numa destas
 * versoes de origem (ou em qualquer versao mais antiga que o alvo), recebem o firmware F".
 *
 * Travas:
 *   - todo HW aceito precisa estar na compatibilidade declarada pelo proprio firmware;
 *   - "qualquer versao anterior" usa comparacao natural e NUNCA inclui versao igual ou mais nova
 *     (o addon nao rebaixa ONU);
 *   - regra usada por campanha em andamento nao muda; usada em qualquer campanha nao some.
 */
require_once __DIR__ . '/../Core/carregar.php';

final class RegraServico
{
    private const RE_TEXTO = '/^[A-Za-z0-9][A-Za-z0-9._ \/+-]{0,59}$/';

    public static function listar(): array
    {
        $r = Db::todos('SELECT g.*, f.modelo_familia, f.versao_firmware, f.estado AS firmware_estado, o.nome AS olt_nome,
                               (SELECT COUNT(*) FROM tab_zte_campanha c WHERE c.regra_id = g.id) AS campanhas
                          FROM tab_zte_regra g JOIN tab_zte_firmware f ON f.id = g.firmware_id
                     LEFT JOIN tab_zte_olt o ON o.id = g.olt_id ORDER BY g.ativo DESC, g.nome');
        return array_map([self::class, 'paraTela'], $r);
    }

    public static function obter(int $id): array
    {
        $r = Db::um('SELECT g.*, f.modelo_familia, f.versao_firmware, f.estado AS firmware_estado, o.nome AS olt_nome,
                            (SELECT COUNT(*) FROM tab_zte_campanha c WHERE c.regra_id = g.id) AS campanhas
                       FROM tab_zte_regra g JOIN tab_zte_firmware f ON f.id = g.firmware_id
                  LEFT JOIN tab_zte_olt o ON o.id = g.olt_id WHERE g.id = ?', [$id]);
        if ($r === null) {
            throw new ZteErro('ZTE-REG-001', [], null, 404);
        }
        return self::paraTela($r);
    }

    public static function salvar(array $e, string $usuario): array
    {
        $id = (int) ($e['id'] ?? 0);
        $modelo = trim((string) ($e['modelo'] ?? ''));
        if (!preg_match(self::RE_TEXTO, $modelo)) {
            throw new ZteErro('ZTE-FW-018', ['campo' => 'modelo']);
        }
        $hws = self::lista($e['hw_aceitos'] ?? [], 'ZTE-FW-018');
        if (!$hws) {
            throw new ZteErro('ZTE-REG-007');
        }
        $origens = self::lista($e['versoes_origem'] ?? [], 'ZTE-REG-008');
        $fwId = Validar::inteiro($e['firmware_id'] ?? 0, 1, PHP_INT_MAX);
        $fw = Db::um('SELECT id, estado FROM tab_zte_firmware WHERE id = ?', [$fwId]);
        if ($fw === null || $fw['estado'] === 'desativado') {
            throw new ZteErro('ZTE-REG-004');
        }
        $compat = [];
        foreach (FirmwareServico::compat($fwId) as $c) {
            $compat[strtoupper($c['modelo'] . '|' . $c['hw_versao'])] = true;
        }
        foreach ($hws as $hw) {
            if (!isset($compat[strtoupper($modelo . '|' . $hw)])) {
                throw new ZteErro('ZTE-REG-003', ['modelo' => $modelo, 'hw' => $hw]);
            }
        }
        $oltId = (int) ($e['olt_id'] ?? 0);
        if ($oltId > 0) {
            OltServico::linha($oltId);
        }
        $d = [
            'nome'           => Validar::nome($e['nome'] ?? '', 80),
            'olt_id'         => $oltId > 0 ? $oltId : null,
            'modelo'         => $modelo,
            'hw_aceitos'     => json_encode($hws),
            'versoes_origem' => json_encode($origens),
            'firmware_id'    => $fwId,
            'ativo'          => Validar::bool($e['ativo'] ?? true) ? 1 : 0,
            'observacao'     => Validar::texto($e['observacao'] ?? '', 500),
        ];

        return Db::transacao(function () use ($id, $d, $usuario, $e) {
            if ($id === 0) {
                try {
                    Db::exec('INSERT INTO tab_zte_regra (nome, olt_id, modelo, hw_aceitos, versoes_origem, firmware_id, ativo, observacao, criado_por, criado_em)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', array_merge(array_values($d), [$usuario]));
                } catch (PDOException $ex) {
                    self::duplicado($ex);
                }
                $id = Db::ultimoId();
                $antes = null;
            } else {
                $antes = self::obter($id);
                self::exigirForaDeCampanhaAtiva($id);
                try {
                    $n = Db::exec('UPDATE tab_zte_regra SET nome = ?, olt_id = ?, modelo = ?, hw_aceitos = ?, versoes_origem = ?, firmware_id = ?,
                                          ativo = ?, observacao = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW()
                                    WHERE id = ? AND versao = ?',
                        array_merge(array_values($d), [$usuario, $id, Validar::inteiro($e['versao'] ?? 0, 1, PHP_INT_MAX)]));
                } catch (PDOException $ex) {
                    self::duplicado($ex);
                }
                if ($n !== 1) {
                    throw new ZteErro('ZTE-CONC-001', [], null, 409);
                }
            }
            $depois = self::obter($id);
            Auditoria::registrar($antes === null ? 'regra_criar' : 'regra_alterar', 'regra', $id,
                $antes === null ? null : self::paraAuditoria($antes), self::paraAuditoria($depois));
            return $depois;
        });
    }

    public static function definirAtivo(int $id, bool $ativo, string $usuario): array
    {
        $antes = self::obter($id);
        if (!$ativo) {
            self::exigirForaDeCampanhaAtiva($id);
        }
        Db::exec('UPDATE tab_zte_regra SET ativo = ?, versao = versao + 1, alterado_por = ?, alterado_em = NOW() WHERE id = ?', [$ativo ? 1 : 0, $usuario, $id]);
        Auditoria::registrar($ativo ? 'regra_reativar' : 'regra_desativar', 'regra', $id, ['ativo' => $antes['ativo']], ['ativo' => $ativo ? 1 : 0]);
        return self::obter($id);
    }

    public static function remover(int $id, string $usuario): void
    {
        $r = self::obter($id);
        if ($r['campanhas'] > 0) {
            throw new ZteErro('ZTE-REG-006', [], null, 409);
        }
        Db::exec('DELETE FROM tab_zte_regra WHERE id = ?', [$id]);
        Auditoria::registrar('regra_remover', 'regra', $id, self::paraAuditoria($r), null);
    }

    /**
     * A ONU passa pela regra? Devolve null se passa, ou o motivo (texto curto) se nao passa.
     * Usado pela simulacao para explicar cada ONU que fica de fora.
     */
    public static function motivoFora(array $regra, array $onu, string $versaoAlvo): ?string
    {
        // Sem modelo lido (tipicamente ONU que estava offline no inventario) nao e "outro modelo":
        // o operador precisa saber que essa ONU pode ser do modelo e ficou de fora por falta de dado.
        if (empty($onu['modelo'])) {
            return 'modelo_desconhecido';
        }
        if (strcasecmp((string) $onu['modelo'], $regra['modelo']) !== 0) {
            return 'outro_modelo';
        }
        if (empty($onu['hw_versao'])) {
            return 'hw_desconhecido';
        }
        $hws = array_map('strtoupper', $regra['hw_aceitos']);
        if (!in_array(strtoupper((string) $onu['hw_versao']), $hws, true)) {
            return 'hw_incompativel';
        }
        if (empty($onu['sw_versao'])) {
            return 'versao_desconhecida';
        }
        $c = strnatcasecmp($onu['sw_versao'], $versaoAlvo);
        if ($c === 0) {
            return 'ja_na_versao';
        }
        if ($c > 0) {
            return 'versao_mais_nova';
        }
        if ($regra['versoes_origem'] && !in_array(strtoupper($onu['sw_versao']), array_map('strtoupper', $regra['versoes_origem']), true)) {
            return 'origem_nao_aceita';
        }
        return null;
    }

    // ---------------------------------------------------------------- apoio

    private static function exigirForaDeCampanhaAtiva(int $id): void
    {
        // A recorrente NAO trava a regra: trocar o firmware da regra e o caminho normal quando sai um
        // firmware novo — a recorrente percebe pela assinatura e volta a pedir aprovacao.
        if ((int) Db::valor("SELECT COUNT(*) FROM tab_zte_campanha WHERE regra_id = ? AND tipo <> 'recorrente'
                              AND estado IN ('aprovada','executando','pausada')", [$id]) > 0) {
            throw new ZteErro('ZTE-REG-005', [], null, 409);
        }
    }

    /** Lista de textos (array ou texto separado por virgula/linha), validada e sem duplicados. */
    private static function lista($v, string $erro): array
    {
        $itens = is_array($v) ? $v : preg_split('/[\s,;]+/', (string) $v);
        $saida = [];
        foreach ($itens as $i) {
            $i = trim((string) $i);
            if ($i === '') {
                continue;
            }
            if (!preg_match(self::RE_TEXTO, $i)) {
                throw new ZteErro($erro, ['valor' => mb_substr($i, 0, 60)]);
            }
            $saida[strtoupper($i)] = $i;
        }
        return array_values($saida);
    }

    private static function paraTela(array $r): array
    {
        foreach (['id', 'firmware_id', 'ativo', 'versao', 'campanhas'] as $c) {
            $r[$c] = (int) $r[$c];
        }
        $r['olt_id'] = $r['olt_id'] === null ? null : (int) $r['olt_id'];
        $r['hw_aceitos'] = json_decode((string) $r['hw_aceitos'], true) ?: [];
        $r['versoes_origem'] = json_decode((string) $r['versoes_origem'], true) ?: [];
        return $r;
    }

    private static function paraAuditoria(array $r): array
    {
        return array_intersect_key($r, array_flip(['nome', 'olt_id', 'modelo', 'hw_aceitos', 'versoes_origem', 'firmware_id', 'ativo', 'observacao']));
    }

    private static function duplicado(PDOException $ex): void
    {
        if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
            throw new ZteErro('ZTE-REG-002', [], null, 409);
        }
        throw $ex;
    }
}
