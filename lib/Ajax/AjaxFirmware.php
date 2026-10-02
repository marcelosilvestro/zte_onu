<?php
/**
 * zte_onu :: biblioteca de firmwares.
 */
final class AjaxFirmware
{
    private static function id(array $e): int
    {
        return Validar::inteiro($e['id'] ?? 0, 1, PHP_INT_MAX);
    }

    public static function listar(array $e): array
    {
        return [
            'firmwares'    => FirmwareServico::listar(),
            'repositorios' => array_map(fn($r) => ['id' => $r['id'], 'nome' => $r['nome'], 'raiz' => $r['raiz'],
                                  'enviar' => $r['liberado_enviar'], 'listar' => $r['liberado_listar'], 'excluir' => $r['liberado_excluir'], 'ativo' => $r['ativo']],
                                  RepoServico::listar()),
            'extensoes'    => FirmwareServico::EXTENSOES,
            'max_mb'       => Config::int('firmware_max_mb'),
            'politica'     => Config::get('integridade_politica'),
            'pode_editar'  => Permissao::tem('firmware.gerenciar'),
            'pode_excluir_ftp' => Permissao::tem('repositorio.excluir'),
            // Modelos e HW ja vistos no inventario: sugestao para a compatibilidade.
            'modelos_inventario' => Db::todos("SELECT modelo, hw_versao, COUNT(*) AS onus FROM tab_zte_onu
                                                WHERE modelo IS NOT NULL AND modelo <> '' GROUP BY modelo, hw_versao ORDER BY modelo, hw_versao LIMIT 200"),
        ];
    }

    public static function obter(array $e): array
    {
        return FirmwareServico::obter(self::id($e));
    }

    /**
     * Upload multipart. O arquivo vai para a pasta temporaria do addon (fora do webroot) e
     * de la para o FTP; o servico apaga o temporario em qualquer desfecho.
     */
    public static function enviar(array $e): array
    {
        @set_time_limit(0);
        $arq = $_FILES['arquivo'] ?? null;
        if (!is_array($arq) || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($arq['tmp_name'])) {
            $cod = is_array($arq) ? (int) $arq['error'] : UPLOAD_ERR_NO_FILE;
            throw new ZteErro($cod === UPLOAD_ERR_INI_SIZE || $cod === UPLOAD_ERR_FORM_SIZE ? 'ZTE-FW-003' : 'ZTE-FW-016', ['codigo_php' => $cod]);
        }
        $dir = FirmwareServico::$dirTemporario;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $local = $dir . '/up_' . bin2hex(random_bytes(8)) . '.tmp';
        if (!@move_uploaded_file($arq['tmp_name'], $local)) {
            throw new ZteErro('ZTE-FW-016', ['motivo' => 'pasta temporaria sem escrita']);
        }
        $m = $e;
        $m['compat'] = self::compatDoPost($e);
        return FirmwareServico::enviar(Validar::inteiro($e['repositorio_id'] ?? 0, 1, PHP_INT_MAX), $local,
            (string) ($arq['name'] ?? ''), $m, Permissao::login());
    }

    public static function adotar(array $e): array
    {
        @set_time_limit(0);
        $m = $e;
        $m['compat'] = self::compatDoPost($e);
        return FirmwareServico::adotar(Validar::inteiro($e['repositorio_id'] ?? 0, 1, PHP_INT_MAX),
            (string) ($e['caminho'] ?? ''), $m, Permissao::login());
    }

    public static function verificar(array $e): array
    {
        @set_time_limit(0);
        $r = FirmwareServico::verificar(self::id($e), 'reverificacao', Permissao::login());
        return $r + ['firmware' => FirmwareServico::obter(self::id($e))];
    }

    public static function disponibilizar(array $e): array
    {
        return FirmwareServico::disponibilizar(self::id($e), Permissao::login(), Validar::bool($e['aceitar_sem_origem'] ?? false));
    }

    public static function compat(array $e): array
    {
        return FirmwareServico::definirCompat(self::id($e), self::compatDoPost($e), Permissao::login());
    }

    public static function editar(array $e): array
    {
        return FirmwareServico::editar(self::id($e), $e, Permissao::login());
    }

    public static function ativar(array $e): array
    {
        return Validar::bool($e['ativo'] ?? false)
            ? FirmwareServico::reativar(self::id($e), Permissao::login())
            : FirmwareServico::desativar(self::id($e), Permissao::login());
    }

    public static function excluir(array $e): array
    {
        $apagar = Validar::bool($e['apagar_remoto'] ?? false);
        if ($apagar && !Permissao::tem('repositorio.excluir')) {
            throw new ZteErro('ZTE-AUTH-002', ['exigido' => 'repositorio.excluir'], null, 403);
        }
        FirmwareServico::excluir(self::id($e), (string) ($e['confirmacao'] ?? ''), $apagar, Permissao::login());
        return ['excluido' => true];
    }

    /** compat vem como compat_modelo[] e compat_hw[] (pares na mesma posicao). */
    private static function compatDoPost(array $e): array
    {
        $mods = isset($e['compat_modelo']) && is_array($e['compat_modelo']) ? $e['compat_modelo'] : [];
        $hws  = isset($e['compat_hw']) && is_array($e['compat_hw']) ? $e['compat_hw'] : [];
        $saida = [];
        foreach ($mods as $i => $m) {
            $saida[] = ['modelo' => (string) $m, 'hw_versao' => (string) ($hws[$i] ?? '')];
        }
        return $saida;
    }
}
