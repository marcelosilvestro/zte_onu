<?php
/**
 * zte_onu :: de onde vem o acesso ao BANCO.
 *
 * (Senhas de OLT e FTP nao passam por aqui: elas ficam cifradas no Cofre.)
 *
 * Cadeia, primeira fonte legivel vence:
 *   1. /opt/mk-auth/conf/zte_onu.php   arquivo proprio deste addon, escrito pelo instalador
 *   2. /opt/mk-auth/conf/secrets.php   bloco 'db', quando o servidor ja tiver o arquivo
 *
 * O secrets.php e lido, NUNCA escrito: ele guarda blocos de outros addons.
 */
final class Credenciais
{
    public const ARQUIVO_ADDON = '/opt/mk-auth/conf/zte_onu.php';
    public const ARQUIVO_MKA   = '/opt/mk-auth/conf/secrets.php';

    /** @return array{host:string,port:int,name:string,user:string,pass:string,origem:string}|null */
    public static function descobrir(): ?array
    {
        foreach ([self::ARQUIVO_ADDON, self::ARQUIVO_MKA] as $caminho) {
            $cfg = self::doArquivo($caminho);
            if ($cfg !== null) {
                return $cfg;
            }
        }
        return null;
    }

    /** @return array{host:string,port:int,name:string,user:string,pass:string,origem:string}|null */
    public static function doArquivo(string $caminho): ?array
    {
        if (!is_file($caminho) || !is_readable($caminho)) {
            return null;
        }
        try {
            $conf = require $caminho;
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($conf) || !isset($conf['db']) || !is_array($conf['db'])) {
            return null;
        }
        return self::normalizar($conf['db'], $caminho);
    }

    /** @return array{host:string,port:int,name:string,user:string,pass:string,origem:string} */
    public static function normalizar(array $db, string $origem): array
    {
        return [
            'host'   => (string) ($db['host'] ?? '127.0.0.1'),
            'port'   => (int) ($db['port'] ?? 3306),
            'name'   => (string) ($db['name'] ?? 'mkradius'),
            'user'   => (string) ($db['user'] ?? 'root'),
            'pass'   => (string) ($db['pass'] ?? ''),
            'origem' => $origem,
        ];
    }

    public static function comoResolver(): string
    {
        return 'Configuracao de banco nao encontrada. Rode o instalador do addon '
             . '(ele cria ' . self::ARQUIVO_ADDON . ').';
    }
}
