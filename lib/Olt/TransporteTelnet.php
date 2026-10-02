<?php
/**
 * zte_onu :: telnet com a OLT.
 *
 * ⏳ O fluxo de login (Username:/Password:, prompt "NOME#") e a paginacao "--More--" seguem o
 * comportamento conhecido das OLTs ZTE, mas ainda NAO foram conferidos na sessao real da OLT de
 * referencia (Fase 0, rodada 2). Os testes usam uma OLT falsa (tests/apoio/olt_falsa.php).
 *
 * O que este arquivo garante, independentemente do equipamento:
 *   - timeout em toda espera (conexao, login, comando) — nada fica pendurado;
 *   - a senha nunca vai para log nem para mensagem de erro;
 *   - negociacao telnet (IAC) respondida com o minimo e removida do texto;
 *   - a saida volta sem eco do comando, sem prompt, sem "--More--" e sem codigos de terminal.
 */
require_once __DIR__ . '/Transporte.php';
require_once __DIR__ . '/../Core/Log.php';

final class TransporteTelnet implements Transporte
{
    private const IAC = 255, DONT = 254, DO_ = 253, WONT = 252, WILL = 251, SB = 250, SE = 240;
    private const ECHO_ = 1, SGA = 3;
    private const MAX_SAIDA = 4194304;

    private const RE_USUARIO = '/(user ?name|login)\s*:\s*$/i';
    private const RE_SENHA   = '/password\s*:\s*$/i';
    private const RE_PROMPT  = '/(?:^|\n)([A-Za-z0-9._-]{1,64})(\([^)\n]*\))?([#>])\s*$/';
    // So conta como recusa se for a ULTIMA linha recebida: um banner com "fail" no meio nao engana.
    private const RE_RECUSA  = '/(bad password|invalid|fail|denied|incorrect|no username)[^\n]*\n?\s*$/i';

    /** @var resource|null */
    private $sock = null;
    private string $buffer = '';
    private string $iacResto = '';
    private string $nome = '';

    private string $host;
    private int $porta;
    private string $usuario;
    private string $senha;
    private ?string $enable;
    private int $tConexao;
    private int $tComando;

    public function __construct(string $host, int $porta, string $usuario, string $senha, ?string $enable,
                                int $timeoutConexao, int $timeoutComando)
    {
        $this->host = $host;
        $this->porta = $porta;
        $this->usuario = $usuario;
        $this->senha = $senha;
        $this->enable = $enable;
        $this->tConexao = max(1, $timeoutConexao);
        $this->tComando = max(1, $timeoutComando);
        Log::segredo($senha);
        Log::segredo($enable);
    }

    public function conectar(): void
    {
        $alvo = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $this->host . ']' : $this->host;
        $sock = @stream_socket_client('tcp://' . $alvo . ':' . $this->porta, $errno, $errstr, $this->tConexao);
        if (!$sock) {
            throw new OltFalha('conexao', trim($errno . ' ' . $errstr));
        }
        stream_set_blocking($sock, false);
        $this->sock = $sock;

        $this->esperar([self::RE_USUARIO], $this->tConexao, 'a OLT nao pediu o usuario');
        $this->enviar($this->usuario);
        $this->esperar([self::RE_SENHA], $this->tConexao, 'a OLT nao pediu a senha');
        $this->enviar($this->senha, true);

        [$i, $m] = $this->esperar([self::RE_PROMPT, self::RE_USUARIO, self::RE_RECUSA], $this->tConexao, 'sem prompt apos o login');
        if ($i !== 0) {
            throw new OltFalha('autenticacao');
        }
        if ($m[3] === '>') {
            if ($this->enable === null || $this->enable === '') {
                throw new OltFalha('modo_usuario');
            }
            $this->enviar('enable');
            $this->esperar([self::RE_SENHA], $this->tConexao, 'a OLT nao pediu a senha de enable');
            $this->enviar($this->enable, true);
            [$i, $m] = $this->esperar([self::RE_PROMPT, self::RE_RECUSA], $this->tConexao, 'sem prompt apos o enable');
            if ($i !== 0 || $m[3] !== '#') {
                throw new OltFalha('autenticacao', 'enable recusado');
            }
        }
        $this->nome = $m[1];
        $this->buffer = '';
    }

    public function executar(string $linha): string
    {
        if ($this->sock === null) {
            throw new OltFalha('queda', 'executar sem conexao');
        }
        $linha = zte_linha_segura($linha);
        $this->enviar($linha);

        $rePrompt = '/(?:^|\n)' . preg_quote($this->nome, '/') . '(\([^)\n]*\))?#\s*$/';
        $limite = microtime(true) + $this->tComando;
        while (true) {
            // Paginacao: responde com espaco e tira o marcador do texto.
            if (stripos($this->buffer, '--More--') !== false) {
                $this->buffer = preg_replace('/\s*--More--\s*/i', "\n", $this->buffer);
                $this->escreverBruto(' ');
                $limite = microtime(true) + $this->tComando;
            }
            if (preg_match($rePrompt, $this->limparTerminal($this->buffer))) {
                break;
            }
            if (microtime(true) > $limite) {
                throw new OltFalha('timeout', 'comando sem prompt de volta');
            }
            $this->ler(0.2);
        }
        $texto = $this->limparTerminal($this->buffer);
        $this->buffer = '';

        $linhas = explode("\n", $texto);
        array_pop($linhas);                                   // o prompt final
        if ($linhas && str_contains($linhas[0], $linha)) {    // o eco do comando
            array_shift($linhas);
        }
        return trim(implode("\n", $linhas), "\n");
    }

    public function nomeEquipamento(): string
    {
        return $this->nome;
    }

    public function fechar(): void
    {
        if ($this->sock !== null) {
            @fwrite($this->sock, "exit\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        $this->fechar();
    }

    // ---------------------------------------------------------------- interno

    /**
     * Le ate um dos padroes casar no FIM do buffer. Devolve [indice, matches].
     * @return array{0:int,1:array}
     */
    private function esperar(array $padroes, int $timeoutS, string $oQueFaltou): array
    {
        $limite = microtime(true) + $timeoutS;
        while (true) {
            $texto = $this->limparTerminal($this->buffer);
            foreach ($padroes as $i => $re) {
                if (preg_match($re, $texto, $m)) {
                    $this->buffer = '';
                    return [$i, $m];
                }
            }
            if (microtime(true) > $limite) {
                throw new OltFalha('timeout', $oQueFaltou);
            }
            $this->ler(0.2);
        }
    }

    private function ler(float $esperaS): void
    {
        $ler = [$this->sock];
        $nada = null;
        $n = @stream_select($ler, $nada, $nada, 0, (int) ($esperaS * 1000000));
        if ($n === false) {
            throw new OltFalha('queda', 'falha no select');
        }
        if ($n === 0) {
            return;
        }
        $dados = @fread($this->sock, 8192);
        if ($dados === false || ($dados === '' && feof($this->sock))) {
            $this->sock = null;
            throw new OltFalha('queda', 'conexao encerrada pela OLT');
        }
        $this->buffer .= $this->tratarIac($dados);
        if (strlen($this->buffer) > self::MAX_SAIDA) {
            throw new OltFalha('formato', 'saida grande demais');
        }
    }

    private function enviar(string $texto, bool $secreto = false): void
    {
        $this->escreverBruto($texto . "\r\n");
    }

    private function escreverBruto(string $bytes): void
    {
        if ($this->sock === null || @fwrite($this->sock, $bytes) === false) {
            $this->sock = null;
            throw new OltFalha('queda', 'falha ao escrever');
        }
    }

    /**
     * Retira a negociacao telnet do fluxo e responde o minimo: aceita ECHO e SGA do servidor,
     * recusa todo o resto. Um IAC partido entre duas leituras fica guardado para a proxima.
     */
    private function tratarIac(string $dados): string
    {
        $dados = $this->iacResto . $dados;
        $this->iacResto = '';
        $saida = '';
        $n = strlen($dados);
        for ($i = 0; $i < $n; $i++) {
            $b = ord($dados[$i]);
            if ($b !== self::IAC) {
                $saida .= $dados[$i];
                continue;
            }
            if ($i + 1 >= $n) {
                $this->iacResto = substr($dados, $i);
                break;
            }
            $cmd = ord($dados[$i + 1]);
            if ($cmd === self::IAC) {               // 255 literal
                $saida .= chr(255);
                $i++;
                continue;
            }
            if (in_array($cmd, [self::DO_, self::DONT, self::WILL, self::WONT], true)) {
                if ($i + 2 >= $n) {
                    $this->iacResto = substr($dados, $i);
                    break;
                }
                $opt = ord($dados[$i + 2]);
                if ($cmd === self::DO_) {
                    $this->escreverBruto(chr(self::IAC) . chr(self::WONT) . chr($opt));
                } elseif ($cmd === self::WILL) {
                    $aceita = in_array($opt, [self::ECHO_, self::SGA], true);
                    $this->escreverBruto(chr(self::IAC) . chr($aceita ? self::DO_ : self::DONT) . chr($opt));
                }
                $i += 2;
                continue;
            }
            if ($cmd === self::SB) {                // subnegociacao: pula ate IAC SE
                $fim = strpos($dados, chr(self::IAC) . chr(self::SE), $i + 2);
                if ($fim === false) {
                    $this->iacResto = substr($dados, $i);
                    break;
                }
                $i = $fim + 1;
                continue;
            }
            $i++;                                   // outros comandos de 2 bytes
        }
        return $saida;
    }

    /** CRLF -> LF, tira codigos ANSI e apaga o que veio com backspace. */
    private function limparTerminal(string $t): string
    {
        $t = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $t);
        $t = str_replace(["\r\n", "\r"], ["\n", ''], $t);
        $t = str_replace("\0", '', $t);
        // "abc\x08\x08" vira "a": cada backspace apaga o caractere anterior da mesma linha.
        do {
            $t = preg_replace('/[^\x08\n]\x08/', '', $t, -1, $qtd);
        } while ($qtd > 0);
        return str_replace("\x08", '', $t);
    }
}
