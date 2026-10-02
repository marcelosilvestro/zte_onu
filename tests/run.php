<?php
/**
 * zte_onu :: runner de testes.
 *
 * Sem Composer, sem PHPUnit — o servidor MK-AUTH nao tem nem um nem outro. Roda por linha de
 * comando na VM de desenvolvimento, contra um SCHEMA SEPARADO, recriado do zero a cada execucao:
 *
 *   php tests/run.php --conf=/opt/mk-auth/conf/zte_onu.php [--db=mkradius_zte_test]
 *
 * A senha do MySQL vem do arquivo de configuracao (--conf), nunca da linha de comando.
 * Duas travas impedem apagar um banco de verdade: o nome do schema precisa conter 'test', e o
 * arquivo recusa rodar de dentro de /opt/mk-auth. Por isso esta pasta nunca entra no pacote.
 */
declare(strict_types=1);

if (str_contains(str_replace('\\', '/', __DIR__), '/opt/mk-auth')) {
    fwrite(STDERR, "Recusado: a suite de testes nunca roda a partir de uma instalacao do MK-AUTH.\n");
    exit(2);
}

require_once __DIR__ . '/../lib/Core/carregar.php';
require_once __DIR__ . '/../lib/Rotas.php';
foreach (glob(__DIR__ . '/../lib/Ajax/*.php') as $arq) {
    require_once $arq;
}

final class T
{
    public static int $ok = 0;
    public static int $falhou = 0;
    public static array $falhas = [];
    private static string $suite = '';

    public static function suite(string $nome): void
    {
        self::$suite = $nome;
        echo "\n\033[1m# $nome\033[0m\n";
    }

    public static function certo(string $desc, bool $cond, string $detalhe = ''): void
    {
        if ($cond) {
            self::$ok++;
            echo "  \033[32mok\033[0m   $desc\n";
        } else {
            self::$falhou++;
            self::$falhas[] = self::$suite . ' :: ' . $desc . ($detalhe ? " ($detalhe)" : '');
            echo "  \033[31mFALHOU\033[0m $desc" . ($detalhe ? " -> $detalhe" : '') . "\n";
        }
    }

    public static function igual(string $desc, $esperado, $obtido): void
    {
        self::certo($desc, $esperado === $obtido,
            'esperado=' . json_encode($esperado, JSON_UNESCAPED_UNICODE) . ' obtido=' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
    }

    /** Espera ZteErro com o codigo dado (ou qualquer excecao, se $codigo for null). */
    public static function recusa(string $desc, callable $fn, ?string $codigo = null): void
    {
        try {
            $fn();
            self::certo($desc, false, 'a operacao foi aceita, mas deveria falhar');
        } catch (ZteErro $e) {
            self::certo($desc, $codigo === null || $e->codigo() === $codigo,
                'codigo esperado ' . $codigo . ', obtido ' . $e->codigo());
        } catch (Throwable $e) {
            self::certo($desc, $codigo === null, 'excecao ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    public static function resumo(): int
    {
        $total = self::$ok + self::$falhou;
        echo "\n" . str_repeat('-', 60) . "\n";
        if (self::$falhou === 0) {
            echo "\033[32mTUDO VERDE\033[0m: {$total} verificacoes.\n";
            return 0;
        }
        echo "\033[31m{$total} verificacoes, " . self::$falhou . " falha(s):\033[0m\n";
        foreach (self::$falhas as $f) {
            echo "  - $f\n";
        }
        return 1;
    }
}

// ------------------------------------------------------------------ argumentos
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}
$base = isset($args['conf']) ? Credenciais::doArquivo($args['conf']) : Credenciais::descobrir();
if ($base === null) {
    fwrite(STDERR, "Sem configuracao de banco: use --conf=ARQ (um arquivo que devolve ['db' => [...]]).\n");
    exit(2);
}
$cfg = $base;
$cfg['name'] = $args['db'] ?? 'mkradius_zte_test';
if (!str_contains($cfg['name'], 'test')) {
    fwrite(STDERR, "Recusado: o schema de teste precisa ter 'test' no nome (recebido: {$cfg['name']}).\n");
    exit(2);
}

// ------------------------------------------------------------------ schema limpo
$admin = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS `{$cfg['name']}`");
$admin->exec("CREATE DATABASE `{$cfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

Db::conectar($cfg);

// Tudo o que os testes gravam em disco fica numa pasta temporaria propria.
$ZTE_TMP = sys_get_temp_dir() . '/zte_onu_testes_' . getmypid();
@mkdir($ZTE_TMP . '/log', 0700, true);
Log::configurar($ZTE_TMP . '/log', 'teste');
Auditoria::configurar('teste', '127.0.0.1');
Permissao::configurar('teste');
Cofre::configurar($ZTE_TMP . '/cofre.key');

echo "Banco de teste: {$cfg['name']} em {$cfg['host']}\n";

foreach (glob(__DIR__ . '/suites/*.php') as $suite) {
    require $suite;
}

// limpeza: so apaga a pasta temporaria propria desta execucao
function zte_apagar_arvore(string $dir): void
{
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $dir . '/' . $n;
        is_dir($p) && !is_link($p) ? zte_apagar_arvore($p) : @unlink($p);
    }
    @rmdir($dir);
}
if (str_starts_with($ZTE_TMP, sys_get_temp_dir() . '/zte_onu_testes_') && is_dir($ZTE_TMP)) {
    zte_apagar_arvore($ZTE_TMP);
}
$admin->exec("DROP DATABASE IF EXISTS `{$cfg['name']}`");

exit(T::resumo());
