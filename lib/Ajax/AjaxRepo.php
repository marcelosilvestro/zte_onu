<?php
/**
 * zte_onu :: repositorios FTP e acesso das OLTs a eles.
 */
final class AjaxRepo
{
    public static function listar(array $e): array
    {
        $protocolos = [];
        foreach (RepoServico::PROTOCOLOS as $id => $p) {
            $protocolos[] = ['id' => $id] + $p + ['disponivel' => $id === 'ftp' || extension_loaded('openssl')];
        }
        return [
            'repositorios' => RepoServico::listar(),
            'vinculos'     => VinculoServico::listar(),
            'olts'         => array_map(fn($o) => ['id' => $o['id'], 'nome' => $o['nome'], 'ativo' => $o['ativo']], OltServico::listar()),
            'protocolos'   => $protocolos,
            'pode_editar'  => Permissao::tem('repositorio.configurar'),
            'pode_excluir' => Permissao::tem('repositorio.excluir'),
        ];
    }

    private static function id(array $e, string $campo = 'id'): int
    {
        return Validar::inteiro($e[$campo] ?? 0, 1, PHP_INT_MAX);
    }

    public static function salvar(array $e): array
    {
        return RepoServico::salvar($e, Permissao::login());
    }

    public static function testar(array $e): array
    {
        @set_time_limit(300);
        return RepoServico::testar(self::id($e), Permissao::login());
    }

    public static function ativar(array $e): array
    {
        return RepoServico::definirAtivo(self::id($e), Validar::bool($e['ativo'] ?? false), Permissao::login());
    }

    public static function remover(array $e): array
    {
        RepoServico::remover(self::id($e), (string) ($e['confirmacao'] ?? ''), Permissao::login());
        return ['removido' => true];
    }

    public static function testes(array $e): array
    {
        return ['testes' => RepoServico::historicoTestes(self::id($e))];
    }

    public static function navegar(array $e): array
    {
        @set_time_limit(120);
        return RepoServico::navegar(self::id($e), (string) ($e['caminho'] ?? ''));
    }

    public static function criarPasta(array $e): array
    {
        RepoServico::criarPasta(self::id($e), (string) ($e['caminho'] ?? ''), (string) ($e['nome'] ?? ''), Permissao::login());
        return ['ok' => true];
    }

    public static function renomear(array $e): array
    {
        RepoServico::renomear(self::id($e), (string) ($e['caminho'] ?? ''), (string) ($e['novo_nome'] ?? ''), Permissao::login());
        return ['ok' => true];
    }

    public static function excluirArquivo(array $e): array
    {
        RepoServico::excluirArquivo(self::id($e), (string) ($e['caminho'] ?? ''), (string) ($e['confirmacao'] ?? ''), Permissao::login());
        return ['ok' => true];
    }

    public static function sincronizar(array $e): array
    {
        @set_time_limit(600);
        return ['sincronizacao' => RepoServico::sincronizar(self::id($e), Permissao::login())];
    }

    public static function ultimaSincronizacao(array $e): array
    {
        return ['sincronizacao' => RepoServico::ultimaSincronizacao(self::id($e))];
    }

    // ---------------------------------------------------------------- acesso da OLT ao FTP

    public static function salvarVinculo(array $e): array
    {
        return VinculoServico::salvar($e, Permissao::login());
    }

    public static function testarVinculo(array $e): array
    {
        @set_time_limit(180);
        return VinculoServico::testar(self::id($e), Permissao::login());
    }

    public static function removerVinculo(array $e): array
    {
        VinculoServico::remover(self::id($e), Permissao::login());
        return ['removido' => true];
    }

    public static function testesVinculo(array $e): array
    {
        VinculoServico::linha(self::id($e));
        return ['testes' => RepoServico::historico('olt_ftp', 'olt_repositorio', self::id($e), 10)];
    }
}
