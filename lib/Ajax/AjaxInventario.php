<?php
/**
 * zte_onu :: inventario, topologia e painel.
 *
 * A atualizacao e conduzida pela tela, PON a PON (descobrir -> pon -> pon -> ... -> finalizar):
 * cada requisicao e curta, o operador ve o progresso e nenhuma OLT grande estoura o tempo do PHP.
 */
final class AjaxInventario
{
    public static function listar(array $e): array
    {
        $f = [];
        foreach (['olt_id', 'pon', 'estado', 'fase', 'modelo', 'hw', 'fornecedor', 'sw', 'ausentes', 'ordem'] as $c) {
            $f[$c] = Validar::texto($e[$c] ?? '', 60);
        }
        $f['busca'] = Validar::texto($e['busca'] ?? '', 60);
        $r = InventarioServico::listar($f, Validar::inteiro($e['pagina'] ?? 1, 1, 100000), 50);
        if (Validar::bool($e['com_opcoes'] ?? false)) {
            $r['opcoes'] = InventarioServico::opcoesFiltro();
        }
        return $r;
    }

    public static function onu(array $e): array
    {
        return InventarioServico::obterOnu(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX));
    }

    public static function olts(array $e): array
    {
        return ['olts' => array_values(array_map(fn($o) => [
            'id' => $o['id'], 'nome' => $o['nome'], 'ativo' => $o['ativo'], 'compatibilidade' => $o['compatibilidade'],
            'pons_detectadas' => $o['pons_detectadas'] ? (json_decode($o['pons_detectadas'], true) ?: []) : [],
            'inventario_em' => $o['inventario_em'],
        ], Db::todos('SELECT id, nome, ativo, compatibilidade, pons_detectadas, inventario_em FROM tab_zte_olt WHERE ativo = 1 ORDER BY nome')))];
    }

    public static function descobrir(array $e): array
    {
        @set_time_limit(300);
        return ['pons' => InventarioServico::descobrir(Validar::inteiro($e['olt_id'] ?? 0, 1, PHP_INT_MAX), Permissao::login())];
    }

    public static function lerPon(array $e): array
    {
        @set_time_limit(300);
        return InventarioServico::lerPon(Validar::inteiro($e['olt_id'] ?? 0, 1, PHP_INT_MAX),
            Validar::inteiro($e['slot'] ?? 0, 1, 21), Validar::inteiro($e['pon'] ?? 0, 1, 16),
            Validar::bool($e['completo'] ?? false), Permissao::login());
    }

    public static function finalizar(array $e): array
    {
        $resumo = isset($e['resumo']) && is_array($e['resumo']) ? $e['resumo'] : [];
        return InventarioServico::finalizar(Validar::inteiro($e['olt_id'] ?? 0, 1, PHP_INT_MAX), $resumo, Permissao::login());
    }

    public static function lerOnu(array $e): array
    {
        @set_time_limit(120);
        return InventarioServico::lerOnu(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX), Permissao::login());
    }

    /** Atualizacao avulsa (tecnico no local): o que vai acontecer, sem gravar nada. */
    public static function avulsaPrevia(array $e): array
    {
        return CampanhaServico::previaAvulsa(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX));
    }

    /** Atualizacao avulsa: rele a ONU, cria, simula e aprova — o worker executa no proximo minuto. */
    public static function avulsa(array $e): array
    {
        @set_time_limit(120);
        return CampanhaServico::atualizarAvulsa(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX), Permissao::login(),
            Validar::bool($e['ciencia'] ?? false));
    }

    public static function topologia(array $e): array
    {
        return ['olts' => InventarioServico::topologia()];
    }

    public static function painel(array $e): array
    {
        return InventarioServico::resumoPainel();
    }
}
