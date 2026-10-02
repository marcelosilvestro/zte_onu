<?php
/**
 * zte_onu :: barra de navegacao do addon + guarda de acesso da pagina.
 *
 * Antes de incluir, a pagina define:
 *   $zte_pagina       id da aba atual
 *   $zte_perm_pagina  papel exigido ('logado' = qualquer usuario do painel)
 *
 * Depois do include, $zte_bloqueado diz se o conteudo pode ser mostrado. A guarda aqui e de
 * conveniencia: cada operacao AJAX confere a permissao de novo no servidor.
 */
$zte_abas = [
    'painel'        => ['index.php',         'bi-speedometer2',     'Painel'],
    'olts'          => ['olts.php',          'bi-hdd-network',      'OLTs'],
    'repositorios'  => ['repositorios.php',  'bi-server',           'Repositórios'],
    'firmwares'     => ['firmwares.php',     'bi-file-earmark-binary', 'Firmwares'],
    'inventario'    => ['inventario.php',    'bi-router',           'Inventário'],
    'topologia'     => ['topologia.php',     'bi-diagram-3',        'Topologia'],
    'regras'        => ['regras.php',        'bi-funnel',           'Regras'],
    'campanhas'     => ['campanhas.php',     'bi-rocket-takeoff',   'Campanhas'],
    'fila'          => ['fila.php',          'bi-list-task',        'Fila'],
    'diagnostico'   => ['diagnostico.php',   'bi-heart-pulse',      'Diagnóstico'],
    'auditoria'     => ['auditoria.php',     'bi-journal-text',     'Auditoria'],
    'configuracoes' => ['configuracoes.php', 'bi-gear',             'Configurações'],
];
// Telas ja entregues; as demais aparecem apagadas, com "em breve".
$zte_abas_prontas = array_keys($zte_abas);

$zte_bloqueado = false;
$zte_motivo = '';
if (!$zte_schema_ok) {
    $zte_bloqueado = true;
    $zte_motivo = 'schema';
} elseif (($zte_perm_pagina ?? 'ver') !== 'logado' && !Permissao::tem($zte_perm_pagina ?? 'ver')) {
    $zte_bloqueado = true;
    $zte_motivo = 'permissao';
}
?>
<nav class="zte-abas">
    <span class="zte-marca"><i class="bi bi-router-fill"></i> ONUs ZTE</span>
    <?php foreach ($zte_abas as $id => [$arq, $ico, $rot]): ?>
        <?php if (in_array($id, $zte_abas_prontas, true)): ?>
            <a href="<?= $arq ?>" class="zte-aba<?= ($zte_pagina ?? '') === $id ? ' ativa' : '' ?>"><i class="bi <?= $ico ?>"></i> <?= $rot ?></a>
        <?php else: ?>
            <span class="zte-aba em-breve" title="<?= $rot ?> — disponível numa próxima etapa"><i class="bi <?= $ico ?>"></i></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<?php if ($zte_motivo === 'schema'): ?>
    <div class="zte-aviso erro">
        <i class="bi bi-database-exclamation"></i>
        <div><strong>O banco do addon não está instalado.</strong>
            Rode o instalador no terminal do servidor (como root) para criar as tabelas e a chave do cofre.</div>
    </div>
<?php elseif ($zte_motivo === 'permissao'): ?>
    <div class="zte-aviso">
        <i class="bi bi-shield-lock"></i>
        <div><strong>Você não tem acesso a esta tela.</strong>
            Peça ao administrador do addon para liberar o seu login (<?= zte_h(Permissao::login()) ?>) em Configurações › Permissões.</div>
    </div>
<?php endif; ?>
