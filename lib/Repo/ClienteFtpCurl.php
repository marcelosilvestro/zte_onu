<?php
/**
 * zte_onu :: cliente FTP com curl. Cobre o FTPS implicito (porta 990), que a ext-ftp nao faz,
 * e serve de alternativa quando a ext-ftp nao esta instalada.
 *
 * Certificado TLS: nao e verificado. Servidor FTP de provedor quase sempre usa certificado
 * autoassinado, e a ext-ftp tambem nao verifica. O ganho do FTPS aqui e cifrar senha e dados
 * na rede, nao autenticar o servidor — esta limitacao esta na documentacao.
 */
require_once __DIR__ . '/ClienteFtp.php';
require_once __DIR__ . '/../Core/Log.php';

final class ClienteFtpCurl implements ClienteFtp
{
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
        return 'curl' . ($this->protocolo !== 'ftp' ? ' (TLS)' : '');
    }

    /** curl conecta a cada operacao; aqui so confere que da para chegar. */
    public function conectar(): void
    {
        if ($this->protocolo !== 'ftp' && !(curl_version()['features'] & CURL_VERSION_SSL)) {
            throw new FtpFalha('ftps', 'curl sem SSL');
        }
    }

    public function login(): void
    {
        $this->req('/', [CURLOPT_NOBODY => true]);
    }

    public function listar(string $dir): array
    {
        $txt = $this->req(rtrim($dir, '/') . '/', []);
        return ListagemFtp::ler(preg_split('/\r?\n/', trim($txt)));
    }

    public function existeDir(string $dir): bool
    {
        try {
            $this->req(rtrim($dir, '/') . '/', [CURLOPT_NOBODY => true]);
            return true;
        } catch (FtpFalha $f) {
            if (in_array($f->tipo(), ['nao_encontrado', 'raiz', 'recusado'], true)) {
                return false;
            }
            throw $f;
        }
    }

    public function tamanho(string $arquivo): ?int
    {
        try {
            $info = [];
            $this->req($arquivo, [CURLOPT_NOBODY => true], $info);
        } catch (FtpFalha $f) {
            if ($f->tipo() === 'nao_encontrado' || $f->tipo() === 'recusado') {
                return null;
            }
            throw $f;
        }
        $n = (int) ($info['download_content_length'] ?? -1);
        return $n >= 0 ? $n : null;
    }

    public function enviar(string $local, string $remoto): void
    {
        $f = fopen($local, 'rb');
        try {
            $this->req($remoto, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => filesize($local)]);
        } finally {
            fclose($f);
        }
    }

    public function baixar(string $remoto)
    {
        $s = fopen('php://temp/maxmemory:2097152', 'w+b');
        try {
            // RETURNTRANSFER precisa ir desligado AQUI: ligado, ele sobrepoe o CURLOPT_FILE e o
            // conteudo volta como string, deixando o stream vazio.
            $this->req($remoto, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $s]);
        } catch (FtpFalha $f) {
            fclose($s);
            throw $f;
        }
        rewind($s);
        return $s;
    }

    public function renomear(string $de, string $para): void
    {
        $this->req('/', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['RNFR ' . $de, 'RNTO ' . $para]]);
    }

    public function excluir(string $arquivo): void
    {
        if ($this->tamanho($arquivo) === null) {
            throw new FtpFalha('nao_encontrado', $arquivo);
        }
        $this->req('/', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['DELE ' . $arquivo]]);
    }

    public function criarDir(string $dir): void
    {
        if ($this->existeDir($dir)) {
            throw new FtpFalha('existe', $dir);
        }
        $this->req('/', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['MKD ' . $dir]]);
    }

    public function fechar(): void
    {
    }

    /** Executa uma requisicao e devolve o corpo. Erro do curl vira FtpFalha classificada. */
    private function req(string $caminho, array $opcoes, ?array &$info = null): string
    {
        $esquema = $this->protocolo === 'ftps_implicito' ? 'ftps' : 'ftp';
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $this->host . ']' : $this->host;
        $partes = array_map('rawurlencode', explode('/', ltrim($caminho, '/')));
        $url = $esquema . '://' . $host . ':' . $this->porta . '/' . implode('/', $partes);

        $ch = curl_init($url);
        $base = [
            CURLOPT_USERPWD           => $this->usuario . ':' . $this->senha,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT    => $this->tConexao,
            CURLOPT_TIMEOUT           => $this->tTransf,
            CURLOPT_FTP_FILEMETHOD    => CURLFTPMETHOD_SINGLECWD,
            CURLOPT_FTP_SKIP_PASV_IP  => true,
            CURLOPT_FTP_USE_EPSV      => false,
        ];
        if (!$this->passivo) {
            $base[CURLOPT_FTPPORT] = '-';
        }
        if ($this->protocolo !== 'ftp') {
            $base[CURLOPT_SSL_VERIFYPEER] = false;
            $base[CURLOPT_SSL_VERIFYHOST] = 0;
            if ($this->protocolo === 'ftps_explicito') {
                $base[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
            }
        }
        curl_setopt_array($ch, $opcoes + $base);
        $corpo = curl_exec($ch);
        $errno = curl_errno($ch);
        $erro = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new FtpFalha(self::tipoDe($errno, $erro, $caminho), Log::mascararTexto($erro));
        }
        return is_string($corpo) ? $corpo : '';
    }

    private static function tipoDe(int $errno, string $erro, string $caminho): string
    {
        switch ($errno) {
            case 6: case 7:              return 'conexao';
            case 67:                     return 'autenticacao';
            case 28:                     return 'timeout';
            case 78:                     return 'nao_encontrado';
            case 9:                      return $caminho === '/' ? 'raiz' : 'recusado';
            case 21: case 25:            return 'recusado';
            case 35: case 64: case 60:   return 'ftps';
            case 55: case 56: case 18:   return 'queda';
            case 8: case 13: case 14:    return 'formato';
        }
        return stripos($erro, 'ssl') !== false ? 'ftps' : 'queda';
    }
}
