<?php
/**
 * zte_onu :: tabela de operacoes AJAX.
 *
 * Toda operacao do addon esta listada aqui com o metodo HTTP e a permissao exigida. O
 * roteador (ajax.php) recusa o que nao estiver na tabela: nao ha como chamar um metodo
 * qualquer de uma classe pela URL.
 *
 * perm:
 *   'logado'  qualquer usuario logado no painel (so o que e seguro sem papel: o estado
 *             inicial da tela e assumir o PRIMEIRO admin, que a propria regra limita)
 *   outro     um papel de Permissao::PAPEIS (admin sempre passa)
 */
final class Rotas
{
    public const MAPA = [
        'inicio.estado'           => ['GET',  'logado', ['AjaxInicio', 'estado']],

        'permissao.assumir_admin' => ['POST', 'logado', ['AjaxPermissao', 'assumirAdmin']],
        'permissao.listar'        => ['GET',  'admin',  ['AjaxPermissao', 'listar']],
        'permissao.definir'       => ['POST', 'admin',  ['AjaxPermissao', 'definir']],

        'config.listar'           => ['GET',  'ver',    ['AjaxConfig', 'listar']],
        'config.salvar'           => ['POST', 'admin',  ['AjaxConfig', 'salvar']],

        'diagnostico.componentes' => ['GET',  'ver',    ['AjaxDiagnostico', 'componentes']],
        'diagnostico.pacote'      => ['GET',  'admin',  ['AjaxDiagnostico', 'pacote']],

        'auditoria.listar'        => ['GET',  'admin',  ['AjaxAuditoria', 'listar']],

        'olt.listar'              => ['GET',  'ver',            ['AjaxOlt', 'listar']],
        'olt.obter'               => ['GET',  'ver',            ['AjaxOlt', 'obter']],
        'olt.testes'              => ['GET',  'ver',            ['AjaxOlt', 'testes']],
        'olt.drivers'             => ['GET',  'ver',            ['AjaxOlt', 'drivers']],
        'olt.salvar'              => ['POST', 'olt.configurar', ['AjaxOlt', 'salvar']],
        'olt.testar'              => ['POST', 'olt.configurar', ['AjaxOlt', 'testar']],
        'olt.ativar'              => ['POST', 'olt.configurar', ['AjaxOlt', 'ativar']],
        'olt.remover'             => ['POST', 'olt.configurar', ['AjaxOlt', 'remover']],

        'repo.listar'             => ['GET',  'ver',                    ['AjaxRepo', 'listar']],
        'repo.testes'             => ['GET',  'ver',                    ['AjaxRepo', 'testes']],
        'repo.ultima_sincronizacao' => ['GET', 'ver',                   ['AjaxRepo', 'ultimaSincronizacao']],
        'repo.navegar'            => ['GET',  'repositorio.configurar', ['AjaxRepo', 'navegar']],
        'repo.salvar'             => ['POST', 'repositorio.configurar', ['AjaxRepo', 'salvar']],
        'repo.testar'             => ['POST', 'repositorio.configurar', ['AjaxRepo', 'testar']],
        'repo.ativar'             => ['POST', 'repositorio.configurar', ['AjaxRepo', 'ativar']],
        'repo.remover'            => ['POST', 'repositorio.configurar', ['AjaxRepo', 'remover']],
        'repo.criar_pasta'        => ['POST', 'repositorio.configurar', ['AjaxRepo', 'criarPasta']],
        'repo.renomear'           => ['POST', 'repositorio.configurar', ['AjaxRepo', 'renomear']],
        'repo.sincronizar'        => ['POST', 'repositorio.configurar', ['AjaxRepo', 'sincronizar']],
        'repo.excluir_arquivo'    => ['POST', 'repositorio.excluir',    ['AjaxRepo', 'excluirArquivo']],

        'firmware.listar'         => ['GET',  'ver',                ['AjaxFirmware', 'listar']],
        'firmware.obter'          => ['GET',  'ver',                ['AjaxFirmware', 'obter']],
        'firmware.enviar'         => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'enviar']],
        'firmware.adotar'         => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'adotar']],
        'firmware.verificar'      => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'verificar']],
        'firmware.disponibilizar' => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'disponibilizar']],
        'firmware.compat'         => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'compat']],
        'firmware.editar'         => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'editar']],
        'firmware.ativar'         => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'ativar']],
        'firmware.excluir'        => ['POST', 'firmware.gerenciar', ['AjaxFirmware', 'excluir']],

        'inventario.listar'       => ['GET',  'ver',            ['AjaxInventario', 'listar']],
        'inventario.onu'          => ['GET',  'ver',            ['AjaxInventario', 'onu']],
        'inventario.olts'         => ['GET',  'ver',            ['AjaxInventario', 'olts']],
        'inventario.topologia'    => ['GET',  'ver',            ['AjaxInventario', 'topologia']],
        'inventario.painel'       => ['GET',  'ver',            ['AjaxInventario', 'painel']],
        // Ler a OLT abre sessao telnet no equipamento: so quem configura OLT dispara.
        'inventario.descobrir'    => ['POST', 'olt.configurar', ['AjaxInventario', 'descobrir']],
        'inventario.ler_pon'      => ['POST', 'olt.configurar', ['AjaxInventario', 'lerPon']],
        'inventario.finalizar'    => ['POST', 'olt.configurar', ['AjaxInventario', 'finalizar']],
        'inventario.ler_onu'      => ['POST', 'olt.configurar', ['AjaxInventario', 'lerOnu']],
        'inventario.avulsa_previa' => ['GET', 'onu.atualizar_avulso', ['AjaxInventario', 'avulsaPrevia']],
        'inventario.avulsa'       => ['POST', 'onu.atualizar_avulso', ['AjaxInventario', 'avulsa']],

        'regra.listar'            => ['GET',  'ver',              ['AjaxCampanha', 'regras']],
        'regra.salvar'            => ['POST', 'regra.gerenciar',  ['AjaxCampanha', 'salvarRegra']],
        'regra.ativar'            => ['POST', 'regra.gerenciar',  ['AjaxCampanha', 'ativarRegra']],
        'regra.remover'           => ['POST', 'regra.gerenciar',  ['AjaxCampanha', 'removerRegra']],

        'campanha.listar'         => ['GET',  'ver',              ['AjaxCampanha', 'campanhas']],
        'campanha.obter'          => ['GET',  'ver',              ['AjaxCampanha', 'campanha']],
        'campanha.salvar'         => ['POST', 'campanha.criar',   ['AjaxCampanha', 'salvarCampanha']],
        'campanha.simular'        => ['POST', 'campanha.criar',   ['AjaxCampanha', 'simular']],
        'campanha.aprovar'        => ['POST', 'campanha.aprovar', ['AjaxCampanha', 'aprovar']],
        'campanha.pausar'         => ['POST', 'campanha.operar',  ['AjaxCampanha', 'pausar']],
        'campanha.retomar'        => ['POST', 'campanha.operar',  ['AjaxCampanha', 'retomar']],
        'campanha.abortar'        => ['POST', 'campanha.operar',  ['AjaxCampanha', 'abortar']],
        'campanha.excluir'        => ['POST', 'campanha.operar',  ['AjaxCampanha', 'excluir']],
        'campanha.arquivar'       => ['POST', 'campanha.operar',  ['AjaxCampanha', 'arquivar']],
        'campanha.liberar_falhas' => ['POST', 'campanha.operar',  ['AjaxCampanha', 'liberarFalhas']],

        'worker.estado'           => ['GET',  'ver',              ['AjaxCampanha', 'worker']],
        'job.listar'              => ['GET',  'ver',              ['AjaxCampanha', 'jobs']],
        'job.eventos'             => ['GET',  'ver',              ['AjaxCampanha', 'eventos']],
        'job.reprocessar'         => ['POST', 'job.reprocessar',  ['AjaxCampanha', 'reprocessar']],

        'vinculo.testes'          => ['GET',  'ver',                    ['AjaxRepo', 'testesVinculo']],
        'vinculo.salvar'          => ['POST', 'repositorio.configurar', ['AjaxRepo', 'salvarVinculo']],
        'vinculo.testar'          => ['POST', 'repositorio.configurar', ['AjaxRepo', 'testarVinculo']],
        'vinculo.remover'         => ['POST', 'repositorio.configurar', ['AjaxRepo', 'removerVinculo']],
    ];
}
