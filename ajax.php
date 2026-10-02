<?php
/**
 * zte_onu :: roteador AJAX unico.
 *
 *   ajax.php?acao=<operacao>
 *
 * Para TODA operacao, nesta ordem: operacao existe na tabela (lib/Rotas.php) -> metodo HTTP
 * confere -> token CSRF (escrita) -> permissao do usuario -> handler. Negativa de permissao
 * vira 403 e linha de auditoria. Excecao inesperada vai inteira para o log e nunca para a tela.
 */
define('ZTE_AJAX', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Rotas.php';
foreach (glob(__DIR__ . '/lib/Ajax/*.php') as $arqAjax) {
    require_once $arqAjax;
}

if (ob_get_level() > 0) {
    ob_clean();
}

$acao = (string) ($_GET['acao'] ?? '');
$rota = Rotas::MAPA[$acao] ?? null;
if ($rota === null) {
    Resultado::erro('ZTE-SYS-003')->enviar(404);
}
[$metodo, $perm, $handler] = $rota;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metodo) {
    Resultado::erro('ZTE-SYS-004')->enviar(405);
}

if ($metodo === 'POST') {
    $enviado = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals($zte_csrf, $enviado)) {
        Resultado::erro('ZTE-AUTH-003')->enviar(403);
    }
}

try {
    if ($perm !== 'logado' && !Permissao::tem($perm)) {
        Auditoria::registrar('acesso_negado', 'operacao', null, null, ['acao' => $acao, 'exigido' => $perm]);
        Log::aviso('ajax.acesso_negado', ['acao' => $acao, 'exigido' => $perm]);
        Resultado::erro('ZTE-AUTH-002', ['exigido' => $perm])->enviar(403);
    }

    $entrada = $metodo === 'POST' ? $_POST : $_GET;
    unset($entrada['csrf'], $entrada['acao']);

    $saida = call_user_func($handler, $entrada);
    if ($saida instanceof Resultado) {
        $saida->enviar();
    }
    Resultado::ok($saida)->enviar();
} catch (ZteErro $e) {
    Log::info('ajax.recusado', ['acao' => $acao, 'codigo' => $e->codigo(), 'detalhes' => $e->detalhes()]);
    Resultado::erro($e->codigo(), $e->detalhes(), $e->getMessage())->enviar($e->http());
} catch (Throwable $e) {
    Log::excecao('ajax.' . $acao, $e);
    Resultado::erro('ZTE-SYS-001')->enviar(500);
}
