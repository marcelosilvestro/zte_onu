<?php
/**
 * zte_onu :: carrega o nucleo. Web (config.php), CLI (cli/bootstrap.php) e testes incluem so isto.
 */
require_once __DIR__ . '/Erros.php';
require_once __DIR__ . '/ZteErro.php';
require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Validar.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Cofre.php';
require_once __DIR__ . '/Permissao.php';
require_once __DIR__ . '/Credenciais.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/../Diag/PreRequisitos.php';
require_once __DIR__ . '/../Diag/Diagnostico.php';
require_once __DIR__ . '/../Olt/OltServico.php';
require_once __DIR__ . '/../Repo/RepoServico.php';
require_once __DIR__ . '/../Repo/VinculoServico.php';
require_once __DIR__ . '/../Firmware/FirmwareServico.php';
require_once __DIR__ . '/../Inventario/InventarioServico.php';
require_once __DIR__ . '/../Campanha/RegraServico.php';
require_once __DIR__ . '/../Campanha/JobServico.php';
require_once __DIR__ . '/../Campanha/CampanhaServico.php';
require_once __DIR__ . '/../Worker/Worker.php';

if (!defined('ZTE_DIR_DADOS')) {
    define('ZTE_DIR_DADOS', '/opt/mk-auth/dados/zte_onu');
}
if (!defined('ZTE_DIR_LOGS')) {
    define('ZTE_DIR_LOGS', '/opt/mk-auth/log/zte_onu');
}

/** Versao declarada no manifest.json. */
function zte_versao(): string
{
    static $v = null;
    if ($v === null) {
        $m = @json_decode((string) @file_get_contents(__DIR__ . '/../../manifest.json'), true);
        $v = isset($m['version']) ? (string) $m['version'] : '0';
    }
    return $v;
}
