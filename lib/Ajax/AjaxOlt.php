<?php
/**
 * zte_onu :: cadastro e teste de OLTs.
 */
final class AjaxOlt
{
    public static function listar(array $e): array
    {
        $protocolos = [];
        foreach (RegistroOlt::PROTOCOLOS as $id => $p) {
            $protocolos[] = ['id' => $id] + $p;
        }
        return [
            'olts'       => OltServico::listar(),
            'modelos'    => RegistroOlt::modelos(),
            'protocolos' => $protocolos,
            'pode_editar' => Permissao::tem('olt.configurar'),
        ];
    }

    public static function obter(array $e): array
    {
        return OltServico::obter(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX));
    }

    public static function salvar(array $e): array
    {
        return OltServico::salvar($e, Permissao::login());
    }

    public static function testar(array $e): array
    {
        @set_time_limit(180);
        return OltServico::testar(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX), Permissao::login());
    }

    public static function ativar(array $e): array
    {
        return OltServico::definirAtivo(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX),
            Validar::bool($e['ativo'] ?? false), Permissao::login());
    }

    public static function remover(array $e): array
    {
        OltServico::remover(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX), (string) ($e['confirmacao'] ?? ''), Permissao::login());
        return ['removida' => true];
    }

    public static function testes(array $e): array
    {
        return ['testes' => OltServico::historicoTestes(Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX))];
    }

    public static function drivers(array $e): array
    {
        return ['drivers' => RegistroOlt::manifestos()];
    }
}
