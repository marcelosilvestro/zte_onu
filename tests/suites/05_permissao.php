<?php
/**
 * Suite 05 :: permissoes por operacao e o primeiro administrador.
 */
T::suite('Permissao');

Permissao::configurar('ana');
T::igual('sem papel: nao ve nada', false, Permissao::tem('ver'));
T::igual('sem papel: nao aprova', false, Permissao::tem('campanha.aprovar'));
T::igual('ainda nao ha admin', false, Permissao::haAdmin());

Permissao::assumirAdmin('ana');
T::igual('ana assumiu a administracao', true, Permissao::tem('admin'));
T::igual('admin pode tudo', true, Permissao::tem('campanha.aprovar') && Permissao::tem('repositorio.excluir'));
T::recusa('segundo "primeiro admin" e recusado', fn() => Permissao::assumirAdmin('bruno'), 'ZTE-AUTH-004');

[$antes, $depois] = Permissao::definir('bruno', ['campanha.criar', 'inexistente', 'campanha.criar'], 'ana');
T::igual('papel desconhecido e duplicado sao descartados', ['campanha.criar'], $depois);
T::igual('bruno pode criar campanha', true, Permissao::tem('campanha.criar', 'bruno'));
T::igual('bruno NAO aprova campanha (configuracao separada da execucao)', false, Permissao::tem('campanha.aprovar', 'bruno'));
T::igual('qualquer papel da direito a ver', true, Permissao::tem('ver', 'bruno'));
T::igual('bruno nao configura OLT', false, Permissao::tem('olt.configurar', 'bruno'));

T::recusa('nao deixa o addon sem administrador', fn() => Permissao::definir('ana', ['ver'], 'ana'), 'ZTE-AUTH-005');
Permissao::definir('bruno', ['admin'], 'ana');
Permissao::definir('ana', ['ver'], 'bruno');
T::igual('com outro admin, ana pode deixar de ser admin', false, Permissao::tem('admin', 'ana'));
T::igual('listar devolve os dois logins', 2, count(Permissao::listar()));
T::recusa('login invalido e recusado', fn() => Permissao::definir("x' OR 1=1", ['ver'], 'bruno'), 'ZTE-VAL-005');

T::suite('Rotas :: toda operacao declara metodo e permissao validos');

$problemas = [];
foreach (Rotas::MAPA as $acao => [$metodo, $perm, $handler]) {
    if (!in_array($metodo, ['GET', 'POST'], true)) {
        $problemas[] = "$acao: metodo $metodo";
    }
    if ($perm !== 'logado' && !isset(Permissao::PAPEIS[$perm])) {
        $problemas[] = "$acao: papel $perm inexistente";
    }
    if (!is_callable($handler)) {
        $problemas[] = "$acao: handler inexistente";
    }
    // Escrita sempre por POST (CSRF): o nome da operacao denuncia.
    if (preg_match('/\.(salvar|definir|assumir_admin|excluir|aprovar|criar|testar|ativar|remover|criar_pasta|renomear|excluir_arquivo|sincronizar|enviar|adotar|verificar|disponibilizar|compat|editar)$/', $acao) && $metodo !== 'POST') {
        $problemas[] = "$acao: escrita por GET";
    }
}
T::igual('tabela de rotas consistente', [], $problemas);
T::igual('cadastro e teste de OLT exigem olt.configurar', ['olt.configurar', 'olt.configurar'],
    [Rotas::MAPA['olt.salvar'][1], Rotas::MAPA['olt.testar'][1]]);
T::igual('quem so ve nao testa OLT', false, Permissao::tem('olt.configurar', 'ana'));
$logado = array_keys(array_filter(Rotas::MAPA, fn($r) => $r[1] === 'logado'));
sort($logado);
T::igual('so o estado inicial e o primeiro admin dispensam papel', ['inicio.estado', 'permissao.assumir_admin'], $logado);

Db::exec('DELETE FROM tab_zte_permissao');
Permissao::esquecer();
Permissao::configurar('teste');
