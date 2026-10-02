<?php
/**
 * zte_onu :: regras, campanhas e fila de jobs.
 */
final class AjaxCampanha
{
    private static function id(array $e, string $c = 'id'): int
    {
        return Validar::inteiro($e[$c] ?? 0, 1, PHP_INT_MAX);
    }

    // ---------------------------------------------------------------- regras

    public static function regras(array $e): array
    {
        return [
            'regras'    => RegraServico::listar(),
            'firmwares' => array_values(array_filter(FirmwareServico::listar(), fn($f) => $f['estado'] !== 'desativado')),
            'olts'      => Db::todos('SELECT id, nome FROM tab_zte_olt WHERE ativo = 1 ORDER BY nome'),
            'modelos'   => Db::todos("SELECT modelo, hw_versao, COUNT(*) AS onus, GROUP_CONCAT(DISTINCT sw_versao ORDER BY sw_versao SEPARATOR ',') AS versoes
                                        FROM tab_zte_onu WHERE fornecedor = ? AND modelo IS NOT NULL AND ausente_desde IS NULL
                                    GROUP BY modelo, hw_versao ORDER BY modelo, hw_versao", [InventarioServico::FORNECEDOR_ATUALIZAVEL]),
            'pode_editar' => Permissao::tem('regra.gerenciar'),
        ];
    }

    public static function salvarRegra(array $e): array
    {
        return RegraServico::salvar($e, Permissao::login());
    }

    public static function ativarRegra(array $e): array
    {
        return RegraServico::definirAtivo(self::id($e), Validar::bool($e['ativo'] ?? false), Permissao::login());
    }

    public static function removerRegra(array $e): array
    {
        RegraServico::remover(self::id($e), Permissao::login());
        return ['removida' => true];
    }

    // ---------------------------------------------------------------- campanhas

    public static function campanhas(array $e): array
    {
        return [
            'campanhas' => CampanhaServico::listar(Validar::bool($e['arquivadas'] ?? false)),
            'dias'      => CampanhaServico::DIAS,
            'teto_padrao' => CampanhaServico::TETO_PADRAO,
            'regras'    => array_values(array_filter(RegraServico::listar(), fn($r) => $r['ativo'])),
            'olts'      => Db::todos('SELECT id, nome FROM tab_zte_olt WHERE ativo = 1 ORDER BY nome'),
            'pons'      => Db::todos('SELECT DISTINCT olt_id, slot, porta FROM tab_zte_onu WHERE ausente_desde IS NULL ORDER BY olt_id, slot, porta'),
            // Para escolher ONUs especificas (teste unitario no modo seguro).
            'onus'      => Db::todos('SELECT id, olt_id, slot, porta, onu_num, nome, sw_versao, estado FROM tab_zte_onu
                                       WHERE fornecedor = ? AND ausente_desde IS NULL ORDER BY olt_id, slot, porta, onu_num LIMIT 5000',
                                     [InventarioServico::FORNECEDOR_ATUALIZAVEL]),
            'padroes'   => ['max_por_pon' => Config::int('max_por_pon'), 'max_concorrentes' => Config::int('max_concorrentes'),
                            'max_falhas' => Config::int('max_falhas'), 'max_falhas_pct' => Config::int('max_falhas_pct'),
                            'retentativas' => Config::int('retentativas'), 'janela_inicio' => Config::get('janela_inicio'),
                            'janela_fim' => Config::get('janela_fim')],
            'modo_seguro' => Config::ligado('modo_seguro'),
            'pode'      => ['criar' => Permissao::tem('campanha.criar'), 'aprovar' => Permissao::tem('campanha.aprovar'),
                            'operar' => Permissao::tem('campanha.operar')],
            'eu'        => Permissao::login(),
        ];
    }

    public static function campanha(array $e): array
    {
        return CampanhaServico::obter(self::id($e));
    }

    public static function salvarCampanha(array $e): array
    {
        return CampanhaServico::salvar($e, Permissao::login());
    }

    public static function simular(array $e): array
    {
        @set_time_limit(300);
        return CampanhaServico::simular(self::id($e), Permissao::login());
    }

    public static function aprovar(array $e): array
    {
        @set_time_limit(0);
        return CampanhaServico::aprovar(self::id($e), Permissao::login(), Validar::bool($e['ciencia'] ?? false), Permissao::tem('admin'));
    }

    public static function pausar(array $e): array
    {
        return CampanhaServico::pausar(self::id($e), (string) ($e['motivo'] ?? ''), Permissao::login());
    }

    public static function retomar(array $e): array
    {
        return CampanhaServico::retomar(self::id($e), Permissao::login());
    }

    public static function abortar(array $e): array
    {
        return CampanhaServico::abortar(self::id($e), Permissao::login());
    }

    public static function excluir(array $e): array
    {
        CampanhaServico::excluir(self::id($e), Permissao::login());
        return ['excluida' => true];
    }

    public static function arquivar(array $e): array
    {
        return CampanhaServico::arquivar(self::id($e), Validar::bool($e['arquivar'] ?? true), Permissao::login());
    }

    public static function liberarFalhas(array $e): array
    {
        return CampanhaServico::liberarFalhas(self::id($e), Permissao::login());
    }

    public static function worker(array $e): array
    {
        return Worker::estado();
    }

    // ---------------------------------------------------------------- fila

    public static function jobs(array $e): array
    {
        $f = ['campanha_id' => (int) ($e['campanha_id'] ?? 0), 'estado' => Validar::texto($e['estado'] ?? '', 20),
              'busca' => Validar::texto($e['busca'] ?? '', 60)];
        $r = JobServico::listar($f, Validar::inteiro($e['pagina'] ?? 1, 1, 100000), 50);
        $r['campanhas'] = Db::todos('SELECT id, nome, estado FROM tab_zte_campanha ORDER BY id DESC LIMIT 200');
        $r['pode_reprocessar'] = Permissao::tem('job.reprocessar');
        return $r;
    }

    public static function eventos(array $e): array
    {
        return ['eventos' => JobServico::eventos(self::id($e))];
    }

    public static function reprocessar(array $e): array
    {
        return JobServico::reprocessar(self::id($e), Permissao::login());
    }
}
