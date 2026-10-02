<?php
/**
 * zte_onu :: auditoria de negocio.
 *
 * Responde "quem fez o que": o login X aprovou a campanha 12 as 14:32, de qual IP, com qual
 * estado antes e depois. Antes/depois passam pelo Log::mascarar — nenhuma senha entra aqui.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Resultado.php';

final class Auditoria
{
    private static string $usuario = 'sistema';
    private static ?string $ip = null;
    private static ?string $correlacao = null;

    public static function configurar(string $usuario, ?string $ip = null): void
    {
        self::$usuario = $usuario;
        self::$ip = $ip;
    }

    /** @return array{0:string,1:?string} usuario e IP atuais (para quem precisa trocar e restaurar). */
    public static function atual(): array
    {
        return [self::$usuario, self::$ip];
    }

    /** Correlacao padrao das proximas linhas (uuid da campanha em execucao, por exemplo). */
    public static function correlacao(?string $c): void
    {
        self::$correlacao = $c;
    }

    public static function registrar(
        string $acao,
        string $entidade,
        ?int $entidadeId = null,
        ?array $antes = null,
        ?array $depois = null,
        ?string $correlacao = null
    ): void {
        Db::exec(
            'INSERT INTO tab_zte_auditoria
                (criado_em, usuario, ip, acao, entidade, entidade_id, antes, depois, correlacao, request_id)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                substr(self::$usuario, 0, 60),
                self::$ip,
                substr($acao, 0, 60),
                substr($entidade, 0, 40),
                $entidadeId,
                self::json($antes),
                self::json($depois),
                $correlacao ?? self::$correlacao,
                Resultado::requestId(),
            ]
        );
    }

    /**
     * Consulta paginada para a tela de auditoria.
     * @return array{total:int,linhas:array}
     */
    public static function listar(array $filtro, int $pagina, int $porPagina = 50): array
    {
        $onde = [];
        $p = [];
        if (!empty($filtro['usuario']))  { $onde[] = 'usuario = ?';  $p[] = $filtro['usuario']; }
        if (!empty($filtro['entidade'])) { $onde[] = 'entidade = ?'; $p[] = $filtro['entidade']; }
        if (!empty($filtro['acao']))     { $onde[] = 'acao = ?';     $p[] = $filtro['acao']; }
        if (!empty($filtro['correlacao'])) { $onde[] = 'correlacao = ?'; $p[] = $filtro['correlacao']; }
        if (!empty($filtro['de']))  { $onde[] = 'criado_em >= ?'; $p[] = $filtro['de'] . ' 00:00:00'; }
        if (!empty($filtro['ate'])) { $onde[] = 'criado_em <= ?'; $p[] = $filtro['ate'] . ' 23:59:59'; }
        $where = $onde ? 'WHERE ' . implode(' AND ', $onde) : '';

        $total = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_auditoria $where", $p);
        $pagina = max(1, $pagina);
        $off = ($pagina - 1) * $porPagina;
        $linhas = Db::todos(
            "SELECT id, criado_em, usuario, ip, acao, entidade, entidade_id, antes, depois, correlacao, request_id
               FROM tab_zte_auditoria $where ORDER BY id DESC LIMIT $porPagina OFFSET $off", $p);
        return ['total' => $total, 'linhas' => $linhas];
    }

    private static function json(?array $dado): ?string
    {
        if ($dado === null) {
            return null;
        }
        return json_encode(Log::mascarar($dado), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
