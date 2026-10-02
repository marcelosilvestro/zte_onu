<?php
/**
 * zte_onu :: cofre de credenciais.
 *
 * Senhas de OLT e de FTP nunca ficam em texto puro: sao cifradas com libsodium
 * (XChaCha20-Poly1305, AEAD) e gravadas em tab_zte_credencial, longe dos registros operacionais.
 *
 * A chave (32 bytes, base64) mora em /opt/mk-auth/conf/zte_onu.key, criada pelo instalador com
 * 640 root:www-data. Ela fica FORA da pasta do addon, entao sobrevive a atualizacoes; e fica
 * FORA do banco, entao um dump do banco sozinho nao revela senha nenhuma.
 *
 * O "dado associado" do AEAD amarra cada ciframento ao seu dono (tipo + id): copiar o valor
 * cifrado da senha de uma OLT para outra linha nao decifra — a autenticacao falha.
 *
 * Se a chave for perdida ou trocada, cada linha guarda com qual chave foi cifrada
 * (digital_chave) e o diagnostico lista exatamente o que precisa ser recadastrado.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/ZteErro.php';

final class Cofre
{
    public const ARQUIVO_PADRAO = '/opt/mk-auth/conf/zte_onu.key';

    /** Donos possiveis de uma credencial. */
    public const TIPOS = [
        'olt'        => 'Senha de acesso à OLT',
        'olt_enable' => 'Senha de enable da OLT',
        'repo'       => 'Senha do addon no FTP',
        'olt_repo'   => 'Senha da OLT no FTP',
    ];

    private const VERSAO = 'v1';

    private static string $arquivo = self::ARQUIVO_PADRAO;
    private static ?string $chave = null;

    public static function configurar(string $arquivo): void
    {
        self::$arquivo = $arquivo;
        self::$chave = null;
    }

    public static function arquivo(): string
    {
        return self::$arquivo;
    }

    /** Gera uma chave nova. So o instalador/CLI usa — nunca sobrescreve uma existente. */
    public static function gerarChave(string $arquivo): void
    {
        if (is_file($arquivo)) {
            throw new RuntimeException('Ja existe uma chave em ' . $arquivo . '; nao vou sobrescrever.');
        }
        $chave = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $antes = umask(0027);
        $ok = file_put_contents($arquivo, base64_encode($chave) . "\n", LOCK_EX);
        umask($antes);
        if ($ok === false) {
            throw new RuntimeException('Nao consegui gravar ' . $arquivo);
        }
        @chmod($arquivo, 0640);
    }

    public static function disponivel(): bool
    {
        try {
            self::chave();
            return true;
        } catch (ZteErro $e) {
            return false;
        }
    }

    /** Impressao digital curta da chave atual: identifica a chave sem revela-la. */
    public static function digital(): string
    {
        return substr(hash('sha256', 'zte_onu-cofre|' . self::chave()), 0, 16);
    }

    public static function guardar(string $tipo, int $donoId, string $segredo, string $usuario): void
    {
        self::exigirTipo($tipo);
        $nonce  = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cifra  = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($segredo, self::aad($tipo, $donoId), $nonce, self::chave());
        $valor  = self::VERSAO . ':' . base64_encode($nonce . $cifra);
        Log::segredo($segredo);

        Db::exec(
            'INSERT INTO tab_zte_credencial (tipo, dono_id, cifrado, digital_chave, alterado_por, alterado_em)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE cifrado = VALUES(cifrado), digital_chave = VALUES(digital_chave),
                                     alterado_por = VALUES(alterado_por), alterado_em = NOW()',
            [$tipo, $donoId, $valor, self::digital(), $usuario]
        );
    }

    /** Devolve a senha decifrada, ou null se nunca foi cadastrada. */
    public static function ler(string $tipo, int $donoId): ?string
    {
        self::exigirTipo($tipo);
        $r = Db::um('SELECT cifrado, digital_chave FROM tab_zte_credencial WHERE tipo = ? AND dono_id = ?',
            [$tipo, $donoId]);
        if ($r === null) {
            return null;
        }
        if (!hash_equals(self::digital(), (string) $r['digital_chave'])) {
            throw new ZteErro('ZTE-COF-002', ['tipo' => $tipo, 'dono_id' => $donoId]);
        }
        if (!str_starts_with((string) $r['cifrado'], self::VERSAO . ':')) {
            throw new ZteErro('ZTE-COF-003', ['tipo' => $tipo, 'dono_id' => $donoId]);
        }
        $bruto = base64_decode(substr((string) $r['cifrado'], strlen(self::VERSAO) + 1), true);
        $tamNonce = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($bruto === false || strlen($bruto) <= $tamNonce) {
            throw new ZteErro('ZTE-COF-003', ['tipo' => $tipo, 'dono_id' => $donoId]);
        }
        $claro = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($bruto, $tamNonce), self::aad($tipo, $donoId), substr($bruto, 0, $tamNonce), self::chave());
        if ($claro === false) {
            throw new ZteErro('ZTE-COF-003', ['tipo' => $tipo, 'dono_id' => $donoId]);
        }
        Log::segredo($claro);
        return $claro;
    }

    public static function existe(string $tipo, int $donoId): bool
    {
        self::exigirTipo($tipo);
        return (bool) Db::valor('SELECT COUNT(*) FROM tab_zte_credencial WHERE tipo = ? AND dono_id = ?',
            [$tipo, $donoId]);
    }

    public static function apagar(string $tipo, int $donoId): void
    {
        self::exigirTipo($tipo);
        Db::exec('DELETE FROM tab_zte_credencial WHERE tipo = ? AND dono_id = ?', [$tipo, $donoId]);
    }

    /**
     * Retrato para o diagnostico, sem decifrar nada.
     * @return array{chave:string,arquivo:string,digital:?string,total:int,incompativeis:array}
     */
    public static function estado(): array
    {
        $estado = ['chave' => 'ok', 'arquivo' => self::$arquivo, 'digital' => null, 'total' => 0, 'incompativeis' => []];
        try {
            $estado['digital'] = self::digital();
        } catch (ZteErro $e) {
            $estado['chave'] = $e->codigo() === 'ZTE-COF-004' ? 'invalida' : 'ausente';
        }
        try {
            $linhas = Db::todos('SELECT tipo, dono_id, digital_chave FROM tab_zte_credencial ORDER BY tipo, dono_id');
        } catch (Throwable $e) {
            $linhas = [];
        }
        $estado['total'] = count($linhas);
        foreach ($linhas as $l) {
            if ($estado['digital'] === null || $l['digital_chave'] !== $estado['digital']) {
                $estado['incompativeis'][] = ['tipo' => $l['tipo'], 'dono_id' => (int) $l['dono_id']];
            }
        }
        return $estado;
    }

    // ---------------------------------------------------------------- interno

    private static function chave(): string
    {
        if (self::$chave !== null) {
            return self::$chave;
        }
        if (!is_file(self::$arquivo) || !is_readable(self::$arquivo)) {
            throw new ZteErro('ZTE-COF-001', [], null, 500);
        }
        $bruto = base64_decode(trim((string) @file_get_contents(self::$arquivo)), true);
        if ($bruto === false || strlen($bruto) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new ZteErro('ZTE-COF-004', [], null, 500);
        }
        return self::$chave = $bruto;
    }

    private static function aad(string $tipo, int $donoId): string
    {
        return 'zte_onu|' . $tipo . '|' . $donoId;
    }

    private static function exigirTipo(string $tipo): void
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new InvalidArgumentException('Tipo de credencial desconhecido: ' . $tipo);
        }
    }
}
