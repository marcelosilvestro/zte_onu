<?php
/**
 * zte_onu :: cliente FTP com a extensao ftp do PHP (FTP e FTPS explicito).
 *
 * Detalhes que existem por motivo:
 *   - FTP_USEPASVADDRESS desligado: o endereco devolvido no PASV costuma vir errado atras de NAT;
 *     usar o mesmo host da conexao de controle e o que funciona na pratica;
 *   - MLSD primeiro (formato padronizado) e LIST como alternativa, porque muito servidor
 *     embarcado nao tem MLSD;
 *   - download vai para php://temp (memoria ate 2 MB, depois arquivo temporario do sistema):
 *     a pasta do addon e somente leitura e o arquivo nunca precisa ficar em disco depois.
 */
require_once __DIR__ . '/ClienteFtp.php';
require_once __DIR__ . '/../Core/Log.php';

final class ClienteFtpNativo implements ClienteFtp
{
    /** @var \FTP\Connection|resource|null */
    private $c = null;

    private string $host;
    private int $porta;
    private string $protocolo;
    private bool $passivo;
    private string $usuario;
    private string $senha;
    private int $tConexao;
    private int $tTransf;

    public function __construct(string $host, int $porta, string $protocolo, bool $passivo, string $usuario,
                                string $senha, int $timeoutConexao, int $timeoutTransferencia)
    {
        $this->host = $host;
        $this->porta = $porta;
        $this->protocolo = $protocolo;
        $this->passivo = $passivo;
        $this->usuario = $usuario;
        $this->senha = $senha;
        $this->tConexao = max(1, $timeoutConexao);
        $this->tTransf = max(1, $timeoutTransferencia);
        Log::segredo($senha);
    }

    public function implementacao(): string
    {
        return 'ext-ftp' . ($this->protocolo === 'ftps_explicito' ? ' (TLS)' : '');
    }

    public function conectar(): void
    {
        if ($this->protocolo === 'ftps_explicito') {
            if (!function_exists('ftp_ssl_connect')) {
                throw new FtpFalha('ftps', 'ftp_ssl_connect indisponivel');
            }
            $c = @ftp_ssl_connect($this->host, $this->porta, $this->tConexao);
        } else {
            $c = @ftp_connect($this->host, $this->porta, $this->tConexao);
        }
        if (!$c) {
            throw new FtpFalha('conexao', self::ultimoErro());
        }
        $this->c = $c;
        @ftp_set_option($c, FTP_TIMEOUT_SEC, $this->tTransf);
        if (defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($c, FTP_USEPASVADDRESS, false);
        }
    }

    public function login(): void
    {
        if (!@ftp_login($this->c, $this->usuario, $this->senha)) {
            $erro = self::ultimoErro();
            // Com TLS, uma falha de negociacao tambem aparece aqui.
            throw new FtpFalha(stripos($erro, 'ssl') !== false || stripos($erro, 'tls') !== false ? 'ftps' : 'autenticacao', $erro);
        }
        if (!@ftp_pasv($this->c, $this->passivo)) {
            throw new FtpFalha('recusado', 'modo ' . ($this->passivo ? 'passivo' : 'ativo') . ' recusado');
        }
    }

    public function listar(string $dir): array
    {
        $mlsd = @ftp_mlsd($this->c, $dir);
        if (is_array($mlsd)) {
            return ListagemFtp::deMlsd($mlsd);
        }
        $raw = @ftp_rawlist($this->c, $dir);
        if (!is_array($raw)) {
            throw new FtpFalha($this->existeDir($dir) ? 'recusado' : 'nao_encontrado', 'LIST ' . $dir . ': ' . self::ultimoErro());
        }
        return ListagemFtp::ler($raw);
    }

    public function existeDir(string $dir): bool
    {
        $atual = @ftp_pwd($this->c);
        if (!@ftp_chdir($this->c, $dir)) {
            return false;
        }
        if ($atual !== false) {
            @ftp_chdir($this->c, $atual);
        }
        return true;
    }

    public function tamanho(string $arquivo): ?int
    {
        $t = @ftp_size($this->c, $arquivo);
        return $t >= 0 ? (int) $t : null;
    }

    public function enviar(string $local, string $remoto): void
    {
        if (!@ftp_put($this->c, $remoto, $local, FTP_BINARY)) {
            throw new FtpFalha($this->conexaoViva() ? 'recusado' : 'queda', 'STOR ' . $remoto . ': ' . self::ultimoErro());
        }
    }

    public function baixar(string $remoto)
    {
        $s = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!@ftp_fget($this->c, $s, $remoto, FTP_BINARY)) {
            fclose($s);
            $tipo = !$this->conexaoViva() ? 'queda' : ($this->tamanho($remoto) === null ? 'nao_encontrado' : 'recusado');
            throw new FtpFalha($tipo, 'RETR ' . $remoto . ': ' . self::ultimoErro());
        }
        rewind($s);
        return $s;
    }

    public function renomear(string $de, string $para): void
    {
        if (!@ftp_rename($this->c, $de, $para)) {
            throw new FtpFalha('recusado', 'RNFR/RNTO: ' . self::ultimoErro());
        }
    }

    public function excluir(string $arquivo): void
    {
        if (!@ftp_delete($this->c, $arquivo)) {
            throw new FtpFalha($this->tamanho($arquivo) === null ? 'nao_encontrado' : 'recusado', 'DELE: ' . self::ultimoErro());
        }
    }

    public function criarDir(string $dir): void
    {
        if (@ftp_mkdir($this->c, $dir) === false) {
            throw new FtpFalha($this->existeDir($dir) ? 'existe' : 'recusado', 'MKD: ' . self::ultimoErro());
        }
    }

    public function fechar(): void
    {
        if ($this->c) {
            @ftp_close($this->c);
            $this->c = null;
        }
    }

    public function __destruct()
    {
        $this->fechar();
    }

    private function conexaoViva(): bool
    {
        return $this->c && @ftp_pwd($this->c) !== false;
    }

    private static function ultimoErro(): string
    {
        $e = error_get_last();
        return $e ? Log::mascararTexto(preg_replace('/^ftp_\w+\(\): /', '', $e['message'])) : '';
    }
}
