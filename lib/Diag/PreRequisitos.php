<?php
/**
 * zte_onu :: pre-requisitos do servidor onde o addon esta instalado.
 *
 * O addon vai para MK-AUTHs de outras empresas: o que existe no servidor de referencia
 * (PHP 8.0 com ftp, curl, sodium...) nao pode ser presumido. Cada item diz o resultado e,
 * quando falha, o que fazer.
 */
final class PreRequisitos
{
    /**
     * @return array<int,array{id:string,titulo:string,resultado:string,detalhe:string,acao:string}>
     */
    public static function verificar(): array
    {
        $r = [];

        $r[] = self::item('php', 'Versão do PHP',
            PHP_VERSION_ID >= 80000 ? 'ok' : 'erro',
            'PHP ' . PHP_VERSION,
            'O addon precisa de PHP 8.0 ou superior.');

        foreach ([
            'pdo_mysql' => ['erro', 'Acesso ao banco do MK-AUTH.'],
            'sodium'    => ['erro', 'Cifra das senhas de OLT e FTP (cofre).'],
            'mbstring'  => ['erro', 'Tratamento de texto com acento.'],
            'openssl'   => ['aviso', 'Necessária só para FTPS (FTP com criptografia).'],
        ] as $ext => [$gravidade, $uso]) {
            $tem = extension_loaded($ext);
            $r[] = self::item('ext_' . $ext, 'Extensão ' . $ext, $tem ? 'ok' : $gravidade,
                $tem ? 'Carregada. ' . $uso : 'Ausente. ' . $uso,
                'Instale a extensão (ex.: apt install php-' . $ext . ') e reinicie o PHP.');
        }

        $ftp = extension_loaded('ftp');
        $curl = extension_loaded('curl');
        if ($ftp && $curl) {
            $res = 'ok';
            $det = 'ftp e curl disponíveis (ftp é o principal, curl a alternativa).';
        } elseif ($ftp || $curl) {
            $res = 'aviso';
            $det = 'Só ' . ($ftp ? 'ftp' : 'curl') . ' disponível: funciona, sem alternativa em caso de incompatibilidade.';
        } else {
            $res = 'erro';
            $det = 'Nem ftp nem curl: o addon não consegue gerenciar o repositório de firmwares.';
        }
        $r[] = self::item('ftp_cliente', 'Cliente FTP do PHP', $res, $det, 'Instale php-curl ou habilite a extensão ftp.');

        // Limites de upload: o firmware chega pelo navegador antes de ir ao FTP.
        $limiteAddon = class_exists('Config') ? self::configMb() : 64;
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post   = self::bytes((string) ini_get('post_max_size'));
        $efetivo = min($upload ?: PHP_INT_MAX, $post ?: PHP_INT_MAX);
        // Na linha de comando o php.ini e outro: o limite que vale e o do painel (php-fpm).
        $resUpload = PHP_SAPI === 'cli' ? 'nao_testavel' : ($efetivo >= $limiteAddon * 1048576 ? 'ok' : 'aviso');
        $r[] = self::item('upload', 'Limite de upload do PHP',
            $resUpload,
            sprintf('upload_max_filesize=%s, post_max_size=%s; o addon aceita firmware de até %d MB.',
                ini_get('upload_max_filesize'), ini_get('post_max_size'), $limiteAddon),
            'Aumente upload_max_filesize e post_max_size no php.ini do painel, ou reduza o limite do addon em Configurações.');

        $dados = defined('ZTE_DIR_DADOS') ? ZTE_DIR_DADOS : '/opt/mk-auth/dados/zte_onu';
        $logs  = defined('ZTE_DIR_LOGS') ? ZTE_DIR_LOGS : '/opt/mk-auth/log/zte_onu';
        $r[] = self::item('dir_dados', 'Pasta de dados', self::gravavel($dados) ? 'ok' : 'erro', $dados,
            'Rode o instalador: ele cria a pasta com dono www-data.');
        $r[] = self::item('dir_logs', 'Pasta de logs', self::gravavel($logs) ? 'ok' : 'aviso', $logs,
            'Rode o instalador: ele cria a pasta com dono www-data.');

        return $r;
    }

    public static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1073741824;
            case 'm': return $n * 1048576;
            case 'k': return $n * 1024;
        }
        return $n;
    }

    private static function configMb(): int
    {
        try {
            return Config::int('firmware_max_mb');
        } catch (Throwable $e) {
            return 64;
        }
    }

    private static function gravavel(string $dir): bool
    {
        // is_writable mente sob AppArmor; a prova e criar e apagar um arquivo de verdade.
        if (!is_dir($dir)) {
            return false;
        }
        $sonda = $dir . '/.sonda_' . bin2hex(random_bytes(4));
        if (@file_put_contents($sonda, 'x') !== 1) {
            return false;
        }
        @unlink($sonda);
        return true;
    }

    private static function item(string $id, string $titulo, string $resultado, string $detalhe, string $acao): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'resultado' => $resultado, 'detalhe' => $detalhe,
                'acao' => $resultado === 'ok' ? '' : $acao];
    }
}
