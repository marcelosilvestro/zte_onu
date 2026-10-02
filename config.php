<?php
/**
 * zte_onu :: bootstrap web do addon.
 *
 * Ordem obrigatoria (addon-mkauth-anatomia):
 *   config.php -> (ajax.php responde e sai) -> nav/header.php -> ../../topo.php
 *
 * Addon AUTOSSUFICIENTE: nada de outro addon e incluido. Do core do MK-AUTH vem apenas
 * topo.php, baixo.php, menu.js e scripts/jquery.js.
 *
 * Nada de IP, usuario, senha ou caminho de infraestrutura aqui: OLTs e repositorios FTP sao
 * cadastrados pela interface. Os unicos caminhos fixos sao os da plataforma MK-AUTH.
 */
include('addons.class.php');

$zte_ajax = defined('ZTE_AJAX');

// ---------------------------------------------------------------- sessao do painel
if (!file_exists(__DIR__ . '/../../login.hhvm')) {
    $ext_mk = '.php';
    session_name('mka');
    if (!isset($_SESSION)) session_start();
    if (!isset($_SESSION['mka_logado'])) {
        if ($zte_ajax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false, 'data' => null, 'warnings' => [],
                'errors' => [['code' => 'ZTE-AUTH-001', 'message' => 'Sessão expirada.', 'details' => []]],
                'sessao_expirada' => true,
            ]);
            exit;
        }
        exit('Acesso negado. <a href="/admin/login.php">Fazer Login</a>');
    }
} else {
    $ext_mk = '.hhvm';
    // Sessao gerenciada pelo MK-AUTH em modo HHVM
}

require_once __DIR__ . '/lib/Core/carregar.php';

// ---------------------------------------------------------------- banco
$ZTE_DB = Credenciais::descobrir();
if ($ZTE_DB === null) {
    if ($zte_ajax) {
        Resultado::erro('ZTE-SYS-005')->enviar(500);
    }
    exit(htmlspecialchars(Credenciais::comoResolver()));
}

// mysqli exigido pelo topo.php do MK-AUTH
$link = @mysqli_connect($ZTE_DB['host'], $ZTE_DB['user'], $ZTE_DB['pass'], $ZTE_DB['name'], $ZTE_DB['port']);
if (!$link) {
    exit('Falha na conexao com o banco de dados MySQL.');
}

try {
    $pdo = Db::conectar($ZTE_DB);
} catch (PDOException $e) {
    Log::excecao('config.conectar', $e);
    if ($zte_ajax) {
        Resultado::erro('ZTE-SYS-001')->enviar(500);
    }
    exit('Erro de conexao com o banco de dados.');
}
unset($ZTE_DB);

$usuario_logado = (string) ($_SESSION['MKA_Usuario'] ?? $_SESSION['MM_Usuario'] ?? 'sistema');

Log::configurar(ZTE_DIR_LOGS, $usuario_logado);
Auditoria::configurar($usuario_logado, $_SERVER['REMOTE_ADDR'] ?? null);
Permissao::configurar($usuario_logado);

// O schema minimo para qualquer tela: sem ele, nem a permissao pode ser checada.
$zte_schema_ok = Db::tabelaExiste('tab_zte_permissao') && Db::tabelaExiste('tab_zte_config')
              && Db::tabelaExiste('tab_zte_auditoria');
if (!$zte_schema_ok && $zte_ajax) {
    Resultado::erro('ZTE-SYS-006')->enviar(500);
}

// ---------------------------------------------------------------- CSRF
if (empty($_SESSION['zte_csrf'])) {
    $_SESSION['zte_csrf'] = bin2hex(random_bytes(16));
}
$zte_csrf = (string) $_SESSION['zte_csrf'];

// AJAX so LE a sessao daqui em diante. Soltar o lock impede que uma requisicao lenta (teste
// de OLT, envio ao FTP) prenda o usuario no painel inteiro do MK-AUTH.
if ($zte_ajax && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/** Escapa para HTML. */
function zte_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
