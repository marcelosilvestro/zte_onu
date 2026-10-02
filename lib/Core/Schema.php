<?php
/**
 * zte_onu :: aplicacao do schema.
 *
 * sql/baseline.sql e idempotente e roda INTEIRO a cada instalacao/atualizacao: o que existe
 * fica, o que falta e criado. O diario (tab_zte_migration) registra cada aplicacao com o
 * checksum do arquivo e a versao do addon.
 *
 * DDL no MySQL faz commit implicito: nao ha rollback de schema pela metade. Quem chama
 * (cli/schema.php, e o instalar.sh atraves dele) faz o dump antes.
 */
require_once __DIR__ . '/Db.php';

final class Schema
{
    /** Tabelas que o baseline cria, na ordem de criacao (FKs). */
    public const TABELAS = [
        'tab_zte_migration',
        'tab_zte_config',
        'tab_zte_permissao',
        'tab_zte_auditoria',
        'tab_zte_credencial',
        'tab_zte_olt',
        'tab_zte_repositorio',
        'tab_zte_olt_repositorio',
        'tab_zte_teste_conectividade',
        'tab_zte_firmware',
        'tab_zte_firmware_compat',
        'tab_zte_firmware_verificacao',
        'tab_zte_sincronizacao',
        'tab_zte_sincronizacao_item',
        'tab_zte_onu',
        'tab_zte_onu_snapshot',
        'tab_zte_regra',
        'tab_zte_campanha',
        'tab_zte_campanha_onu',
        'tab_zte_job',
        'tab_zte_job_evento',
        'tab_zte_sim_upgrade',
        'tab_zte_worker',
    ];

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (__DIR__ . '/../../sql'), '/\\');
    }

    /** @return array{arquivo:string,comandos:int,ms:int,versao:string,tabelas:int} */
    public function aplicar(string $usuario, string $versao = ''): array
    {
        $arquivo  = 'baseline.sql';
        $caminho  = $this->dir . '/' . $arquivo;
        if (!is_file($caminho)) {
            throw new RuntimeException('Arquivo de schema nao encontrado: ' . $caminho);
        }
        $sql      = (string) file_get_contents($caminho);
        $checksum = hash('sha256', $sql);
        $comandos = self::comandos($sql);
        $ini      = microtime(true);

        try {
            foreach ($comandos as $cmd) {
                $st = Db::pdo()->query($cmd);
                if ($st instanceof PDOStatement) {
                    $st->closeCursor();
                }
            }
        } catch (Throwable $e) {
            $this->registrar($arquivo, $checksum, $usuario, $versao, (int) ((microtime(true) - $ini) * 1000), 'erro', $e->getMessage());
            throw new RuntimeException($arquivo . ': ' . $e->getMessage(), 0, $e);
        }

        $ms = (int) ((microtime(true) - $ini) * 1000);
        $this->registrar($arquivo, $checksum, $usuario, $versao, $ms, 'ok', null);
        return ['arquivo' => $arquivo, 'comandos' => count($comandos), 'ms' => $ms, 'versao' => $versao,
                'tabelas' => count($this->tabelasPresentes())];
    }

    /**
     * Retrato do banco, sem escrever nada.
     * @return array{instalado:bool,faltando:string[],ultima_aplicacao:?array,checksum_atual:string,desatualizado:bool}
     */
    public function estado(): array
    {
        $faltando = array_values(array_diff(self::TABELAS, $this->tabelasPresentes()));
        $ultima = null;
        try {
            $ultima = Db::um('SELECT migration, versao, checksum, executed_at, executed_by, resultado, erro
                                FROM tab_zte_migration WHERE migration = ?', ['baseline.sql']);
        } catch (Throwable $e) {
            $ultima = null;
        }
        $caminho = $this->dir . '/baseline.sql';
        $checksum = is_file($caminho) ? hash_file('sha256', $caminho) : '';
        return [
            'instalado'        => $faltando === [],
            'faltando'         => $faltando,
            'ultima_aplicacao' => $ultima,
            'checksum_atual'   => $checksum,
            // O arquivo do pacote mudou desde a ultima aplicacao: o instalador nao rodou depois
            // de copiar o codigo novo.
            'desatualizado'    => $ultima === null || $ultima['checksum'] !== $checksum || $ultima['resultado'] !== 'ok',
        ];
    }

    /** @return string[] */
    public function tabelasPresentes(): array
    {
        $in = implode(',', array_fill(0, count(self::TABELAS), '?'));
        $rows = Db::todos("SELECT TABLE_NAME FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", self::TABELAS);
        return array_column($rows, 'TABLE_NAME');
    }

    /**
     * Quebra o arquivo em comandos por ";" no fim da linha, descartando linhas de comentario.
     * @return string[]
     */
    public static function comandos(string $sql): array
    {
        $buffer = '';
        $saida  = [];
        foreach (preg_split('/\R/', $sql) as $linha) {
            $t = trim($linha);
            if ($t === '' || str_starts_with($t, '--')) {
                continue;
            }
            $buffer .= $linha . "\n";
            if (str_ends_with($t, ';')) {
                $saida[] = trim($buffer);
                $buffer  = '';
            }
        }
        if (trim($buffer) !== '') {
            $saida[] = trim($buffer);
        }
        return $saida;
    }

    private function registrar(string $arquivo, string $checksum, string $usuario, string $versao,
                               int $ms, string $resultado, ?string $erro): void
    {
        try {
            Db::exec(
                'INSERT INTO tab_zte_migration (migration, checksum, versao, executed_at, executed_by, duracao_ms, resultado, erro)
                 VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), versao = VALUES(versao), executed_at = NOW(),
                                         executed_by = VALUES(executed_by), duracao_ms = VALUES(duracao_ms),
                                         resultado = VALUES(resultado), erro = VALUES(erro)',
                [$arquivo, $checksum, $versao !== '' ? $versao : '0', $usuario, $ms, $resultado, $erro]
            );
        } catch (Throwable $e) {
            // Diario indisponivel: o schema e o que importa.
        }
    }
}
