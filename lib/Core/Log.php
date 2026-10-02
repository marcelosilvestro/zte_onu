<?php
/**
 * zte_onu :: log tecnico, com mascaramento de segredos.
 *
 * Responde "o que o sistema fez": request_id, correlacao, usuario, operacao, excecao.
 * NAO e auditoria (essa responde "quem alterou o que", em tab_zte_auditoria).
 *
 * Duas camadas impedem senha no log:
 *   1. por CHAVE: qualquer campo cujo nome lembre segredo (senha, password, token...) vira ***;
 *   2. por VALOR: toda senha que o Cofre decifra e registrada aqui (Log::segredo) e, dali em
 *      diante, qualquer ocorrencia dela em qualquer texto — inclusive saida de CLI da OLT —
 *      e trocada por ***.
 * A mesma rotina (Log::mascarar) limpa o que vai para a auditoria e para os eventos de job.
 */
require_once __DIR__ . '/Resultado.php';

final class Log
{
    /** Tamanho maximo de uma linha: acima disso o append deixa de ser atomico. */
    private const LIMITE = 3500;

    /** Nomes de campo que nunca vao para log/auditoria com valor. */
    private const CHAVES_SECRETAS = '/(senha|password|passwd|^pass$|_pass$|secret|segredo|token|chave_cofre|cifrado|enable_password)/i';

    private static string $dir = '/opt/mk-auth/log/zte_onu';
    private static string $usuario = 'sistema';
    /** @var array<string,true> */
    private static array $segredos = [];
    private static array $contexto = [];

    public static function configurar(string $dir, string $usuario): void
    {
        self::$dir = rtrim($dir, '/');
        self::$usuario = $usuario;
    }

    /** Registra um valor secreto para ser mascarado em tudo o que for escrito depois. */
    public static function segredo(?string $valor): void
    {
        if ($valor !== null && strlen($valor) >= 3) {
            self::$segredos[$valor] = true;
        }
    }

    /** Campos fixos acrescentados a toda linha (ex.: correlacao da campanha em execucao). */
    public static function contexto(array $ctx): void
    {
        self::$contexto = $ctx;
    }

    /** Limpa segredos de qualquer estrutura (array, string, escalar). */
    public static function mascarar($dado)
    {
        if (is_array($dado)) {
            $saida = [];
            foreach ($dado as $k => $v) {
                if (is_string($k) && preg_match(self::CHAVES_SECRETAS, $k)) {
                    $saida[$k] = ($v === null || $v === '') ? $v : '***';
                } else {
                    $saida[$k] = self::mascarar($v);
                }
            }
            return $saida;
        }
        if (is_string($dado)) {
            return self::mascararTexto($dado);
        }
        return $dado;
    }

    public static function mascararTexto(string $texto): string
    {
        if (!self::$segredos || $texto === '') {
            return $texto;
        }
        // Os maiores primeiro: uma senha que contem outra nao pode sobrar pela metade.
        $lista = array_keys(self::$segredos);
        usort($lista, fn($a, $b) => strlen($b) <=> strlen($a));
        return str_replace($lista, '***', $texto);
    }

    public static function info(string $operacao, array $ctx = []): void
    {
        self::escrever('INFO', $operacao, $ctx);
    }

    public static function aviso(string $operacao, array $ctx = []): void
    {
        self::escrever('WARN', $operacao, $ctx);
    }

    public static function erro(string $operacao, array $ctx = []): void
    {
        self::escrever('ERRO', $operacao, $ctx);
    }

    /** Excecao vai inteira para o log (mascarada) e NUNCA para a tela. */
    public static function excecao(string $operacao, Throwable $e, array $ctx = []): void
    {
        self::escrever('ERRO', $operacao, $ctx + [
            'excecao' => get_class($e),
            'msg'     => $e->getMessage(),
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
            'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    /** Caminho do arquivo do dia — o diagnostico e a varredura de segredos dos testes usam. */
    public static function arquivoDoDia(): string
    {
        return self::$dir . '/zte-' . date('Y-m-d') . '.log';
    }

    private static function escrever(string $nivel, string $operacao, array $ctx): void
    {
        $registro = [
            'ts'         => date('c'),
            'nivel'      => $nivel,
            'request_id' => Resultado::requestId(),
            'usuario'    => self::$usuario,
            'operacao'   => $operacao,
        ] + self::$contexto + ['ctx' => self::mascarar($ctx)];

        $linha = self::serializar($registro);

        if (strlen($linha) > self::LIMITE) {
            $registro['ctx'] = [
                'truncado' => true,
                'resumo'   => substr(self::serializar(self::mascarar($ctx)), 0, 800),
            ];
            $linha = self::serializar($registro);
        }
        // Cinto e suspensorio: o texto final passa de novo pela troca por valor.
        $linha = self::mascararTexto($linha);

        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0770, true);
        }

        // Sem LOCK_EX de proposito: o AppArmor do painel nega flock em /opt/mk-auth/log e o
        // flock negado derruba a escrita inteira (arquivo com 0 bytes). Append de linha curta
        // ja e atomico no Linux, e o LIMITE acima garante que ela e curta.
        if (@file_put_contents(self::arquivoDoDia(), $linha . PHP_EOL, FILE_APPEND) === false) {
            error_log('zte_onu: falha ao escrever log :: ' . $linha);
        }
    }

    private static function serializar($dado): string
    {
        $json = json_encode($dado,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? '{"erro":"contexto nao serializavel"}' : $json;
    }
}
