<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'diagnostico';
$zte_perm_pagina = 'ver';
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="lc-filter-bar">
        <div class="lc-filter-group">
            <span class="lc-filter-label">Diagnóstico por componente</span>
            <span class="zte-sub" id="zte-diag-quando">–</span>
        </div>
        <div style="flex:1"></div>
        <button type="button" class="lc-btn-black" id="btn-atualizar"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
        <?php if (Permissao::tem('admin')): ?>
        <button type="button" class="lc-btn-outline" id="btn-pacote" title="Arquivo JSON para suporte remoto, sem senhas">
            <i class="bi bi-download"></i> Pacote de suporte</button>
        <?php endif; ?>
    </div>

    <div id="zte-diag"><div class="lc-loading">Verificando...</div></div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    function render(d) {
        $('#zte-diag-quando').text('Gerado em ' + d.gerado_em);
        var html = '';
        d.componentes.forEach(function (c) {
            html += '<div class="lc-section-panel zte-mt" style="height:auto">' +
                '<div class="lc-section-header"><span class="lc-section-title">' + ZTE.esc(c.titulo) + '</span>' + ZTE.badge(c.resultado) + '</div>' +
                '<div class="lc-table-wrap" style="min-height:0"><table class="lc-table"><tbody>';
            c.itens.forEach(function (i) {
                html += '<tr><td style="width:240px"><strong>' + ZTE.esc(i.titulo) + '</strong></td>' +
                    '<td style="width:110px">' + ZTE.badge(i.resultado) + '</td>' +
                    '<td class="zte-quebra">' + ZTE.esc(i.detalhe) +
                    (i.acao ? '<div class="zte-acao"><i class="bi bi-lightbulb"></i> ' + ZTE.esc(i.acao) + '</div>' : '') +
                    '</td></tr>';
            });
            html += '</tbody></table></div></div>';
        });
        $('#zte-diag').html(html);
    }

    function carregar() {
        $('#zte-diag').html('<div class="lc-loading">Verificando...</div>');
        ZTE.api('diagnostico.componentes').then(render).catch(function (e) {
            $('#zte-diag').html('<div class="lc-empty">Não foi possível gerar o diagnóstico.</div>');
            ZTE.erro(e);
        });
    }

    $(function () {
        carregar();
        $('#btn-atualizar').on('click', carregar);
        $('#btn-pacote').on('click', function () {
            ZTE.loading('Montando o pacote...');
            ZTE.api('diagnostico.pacote')
                .then(function (d) {
                    ZTE.baixarJson('zte_onu-suporte-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '') + '.json', d);
                })
                .catch(ZTE.erro)
                .finally(ZTE.fimLoading);
        });
    });
})();
</script>
</body>
</html>
