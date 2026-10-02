<?php
/**
 * zte_onu :: contrato do cliente FTP do addon.
 *
 * O addon fala com o repositorio por aqui para GERENCIAR (listar, enviar, verificar, excluir).
 * A OLT nao usa este cliente: no upgrade, ela busca o arquivo direto no FTP.
 *
 * Todo caminho recebido aqui ja e ABSOLUTO e ja passou por Validar::dentroDaRaiz — o cliente
 * nao decide nada sobre o que pode ou nao ser acessado.
 *
 * Implementacoes: ClienteFtpNativo (ext-ftp, FTP e FTPS explicito) e ClienteFtpCurl (curl,
 * inclusive FTPS implicito). As duas lancam FtpFalha ja classificada.
 */
require_once __DIR__ . '/../Core/ZteErro.php';

interface ClienteFtp
{
    public function conectar(): void;

    /** Conecta e faz login; depois disso, as demais operacoes. */
    public function login(): void;

    /** @return array<int,array{nome:string,tipo:string,tamanho:?int,modificado:?string}> pastas primeiro */
    public function listar(string $dir): array;

    public function existeDir(string $dir): bool;

    /** Tamanho em bytes, ou null se o servidor nao informar / arquivo nao existir. */
    public function tamanho(string $arquivo): ?int;

    public function enviar(string $local, string $remoto): void;

    /** Baixa para um stream (php://temp), sem tocar a pasta do addon. Devolve o stream no inicio. */
    public function baixar(string $remoto);

    public function renomear(string $de, string $para): void;

    public function excluir(string $arquivo): void;

    public function criarDir(string $dir): void;

    public function fechar(): void;

    /** Qual implementacao esta em uso (vai para o diagnostico). */
    public function implementacao(): string;
}

/** Falha de FTP ja classificada. O tipo decide a mensagem e o contador de falhas de login. */
final class FtpFalha extends ZteErro
{
    public const CODIGOS = [
        'conexao'        => 'ZTE-REP-006',
        'autenticacao'   => 'ZTE-REP-007',
        'timeout'        => 'ZTE-REP-008',
        'raiz'           => 'ZTE-REP-009',
        'recusado'       => 'ZTE-REP-012',
        'ftps'           => 'ZTE-REP-014',
        'queda'          => 'ZTE-REP-016',
        'nao_encontrado' => 'ZTE-REP-019',
        'existe'         => 'ZTE-REP-020',
        'formato'        => 'ZTE-REP-021',
    ];

    private string $tipo;

    public function __construct(string $tipo, string $detalheTecnico = '')
    {
        $this->tipo = $tipo;
        parent::__construct(self::CODIGOS[$tipo] ?? 'ZTE-REP-021',
            $detalheTecnico !== '' ? ['tecnico' => mb_substr($detalheTecnico, 0, 300)] : [], null, 502);
    }

    public function tipo(): string
    {
        return $this->tipo;
    }
}

/**
 * Leitura de listagem de diretorio (LIST) nos dois formatos que aparecem em campo:
 *   Unix:    drwxr-xr-x 2 ftp ftp 4096 Oct 01 12:00 firmwares
 *            -rw-r--r-- 1 ftp ftp 12345 Oct 01  2026 F670L_V9.bin
 *   Windows: 10-01-26  12:00PM       <DIR>          firmwares
 *            10-01-26  12:00PM             12345 F670L_V9.bin
 * Linha que nao casa com nenhum dos dois e ignorada (ex.: "total 12").
 */
final class ListagemFtp
{
    /** @return array<int,array{nome:string,tipo:string,tamanho:?int,modificado:?string}> */
    public static function ler(array $linhas): array
    {
        $saida = [];
        foreach ($linhas as $l) {
            $l = rtrim((string) $l, "\r\n");
            if (preg_match('/^([dl-])[rwxsStT-]{9}\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w{3}\s+\d{1,2}\s+(?:\d{1,2}:\d{2}|\d{4}))\s+(.+)$/', $l, $m)) {
                $nome = $m[1] === 'l' ? preg_replace('/ -> .*$/', '', $m[4]) : $m[4];
                $saida[] = ['nome' => $nome, 'tipo' => $m[1] === 'd' ? 'dir' : 'arquivo',
                            'tamanho' => $m[1] === 'd' ? null : (int) $m[2], 'modificado' => preg_replace('/\s+/', ' ', $m[3])];
            } elseif (preg_match('/^(\d{2}-\d{2}-\d{2,4})\s+(\d{1,2}:\d{2}[AP]M)\s+(<DIR>|\d+)\s+(.+)$/i', $l, $m)) {
                $dir = strtoupper($m[3]) === '<DIR>';
                $saida[] = ['nome' => $m[4], 'tipo' => $dir ? 'dir' : 'arquivo',
                            'tamanho' => $dir ? null : (int) $m[3], 'modificado' => $m[1] . ' ' . $m[2]];
            }
        }
        return self::ordenar(array_values(array_filter($saida, fn($e) => $e['nome'] !== '.' && $e['nome'] !== '..')));
    }

    /** Resultado do MLSD (ext-ftp ftp_mlsd) no mesmo formato. */
    public static function deMlsd(array $mlsd): array
    {
        $saida = [];
        foreach ($mlsd as $e) {
            $tipo = strtolower((string) ($e['type'] ?? ''));
            if (!in_array($tipo, ['dir', 'file'], true)) {
                continue;
            }
            $mod = isset($e['modify']) && preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})/', $e['modify'], $m)
                ? "$m[3]/$m[2]/$m[1] $m[4]:$m[5]" : null;
            $saida[] = ['nome' => (string) $e['name'], 'tipo' => $tipo === 'dir' ? 'dir' : 'arquivo',
                        'tamanho' => $tipo === 'file' && isset($e['size']) ? (int) $e['size'] : null, 'modificado' => $mod];
        }
        return self::ordenar($saida);
    }

    private static function ordenar(array $l): array
    {
        usort($l, fn($a, $b) => [$a['tipo'] === 'dir' ? 0 : 1, strtolower($a['nome'])] <=> [$b['tipo'] === 'dir' ? 0 : 1, strtolower($b['nome'])]);
        return $l;
    }
}
