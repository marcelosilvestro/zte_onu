<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'auditoria';
$zte_perm_pagina = 'admin';
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="lc-filter-bar">
        <div class="lc-filter-group">
            <span class="lc-filter-label">De</span>
            <input type="date" class="lc-input-date" id="f-de">
        </div>
        <div class="lc-filter-group">
            <span class="lc-filter-label">Até</span>
            <input type="date" class="lc-input-date" id="f-ate">
        </div>
        <div class="lc-filter-group">
            <span class="lc-filter-label">Usuário</span>
            <input type="text" class="lc-input-date w200" id="f-usuario" placeholder="login">
        </div>
        <div class="lc-filter-group">
            <span class="lc-filter-label">Entidade</span>
            <select class="lc-input-date w200" id="f-entidade"><option value="">Todas</option></select>
        </div>
        <div class="lc-filter-group">
            <span class="lc-filter-label">Ação</span>
            <select class="lc-input-date w200" id="f-acao"><option value="">Todas</option></select>
        </div>
        <button type="button" class="lc-btn-black" id="btn-filtrar"><i class="bi bi-search"></i> Filtrar</button>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-journal-text"></i> Trilha de auditoria</span>
            <span class="lc-badge-count" id="zte-total">0</span>
        </div>
        <div class="lc-table-wrap">
            <table class="lc-table">
                <thead><tr>
                    <th>Quando</th><th>Usuário</th><th>Ação</th><th>Entidade</th>
                    <th class="prio-7">IP</th><th class="prio-6">Requisição</th><th></th>
                </tr></thead>
                <tbody id="zte-linhas"><tr><td colspan="7" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
        <div class="zte-paginacao" id="zte-pag"></div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-detalhe" onclick="ZTE.fecharSeFora(event, 'modal-detalhe')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title">Detalhe do registro</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-detalhe')">&times;</button>
        </div>
        <div class="lc-modal-body" id="detalhe-corpo"></div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-detalhe')">Fechar</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var linhas = [];
    var pagina = 1;

    function opcoes($sel, lista) {
        var atual = $sel.val();
        $sel.find('option:not(:first)').remove();
        lista.forEach(function (v) { $sel.append($('<option>').val(v).text(v)); });
        $sel.val(atual);
    }

    function render(d) {
        linhas = d.linhas;
        $('#zte-total').text(d.total);
        opcoes($('#f-entidade'), d.entidades);
        opcoes($('#f-acao'), d.acoes);
        if (!linhas.length) {
            $('#zte-linhas').html('<tr><td colspan="7" class="lc-empty">Nenhum registro.</td></tr>');
        } else {
            $('#zte-linhas').html(linhas.map(function (l, i) {
                var ent = ZTE.esc(l.entidade) + (l.entidade_id ? ' #' + ZTE.esc(l.entidade_id) : '');
                return '<tr><td style="white-space:nowrap">' + ZTE.dataHora(l.criado_em) + '</td>' +
                    '<td>' + ZTE.esc(l.usuario) + '</td><td><strong>' + ZTE.esc(l.acao) + '</strong></td>' +
                    '<td>' + ent + '</td><td class="prio-7 zte-sub">' + ZTE.esc(l.ip || '') + '</td>' +
                    '<td class="prio-6 zte-mono zte-sub">' + ZTE.esc(l.request_id || '') + '</td>' +
                    '<td style="text-align:right"><button type="button" class="lc-btn-outline" data-i="' + i + '"><i class="bi bi-eye"></i></button></td></tr>';
            }).join(''));
        }
        ZTE.paginacao($('#zte-pag'), d.pagina, d.por_pagina, d.total, function (p) { pagina = p; carregar(); });
    }

    function carregar() {
        ZTE.api('auditoria.listar', {
            pagina: pagina, de: $('#f-de').val(), ate: $('#f-ate').val(), usuario: $('#f-usuario').val(),
            entidade: $('#f-entidade').val(), acao_filtro: $('#f-acao').val()
        }).then(render).catch(ZTE.erro);
    }

    function bloco(titulo, obj) {
        if (obj === null || obj === undefined) return '';
        return '<label class="lc-label zte-mt">' + titulo + '</label><pre class="zte-detalhe-json zte-mono">' +
               ZTE.esc(JSON.stringify(obj, null, 2)) + '</pre>';
    }

    $(function () {
        carregar();
        $('#btn-filtrar').on('click', function () { pagina = 1; carregar(); });
        $('#zte-linhas').on('click', 'button[data-i]', function () {
            var l = linhas[parseInt($(this).attr('data-i'), 10)];
            $('#detalhe-corpo').html(
                '<div><strong>' + ZTE.esc(l.acao) + '</strong> em ' + ZTE.esc(l.entidade) +
                (l.entidade_id ? ' #' + ZTE.esc(l.entidade_id) : '') + '</div>' +
                '<div class="zte-sub">' + ZTE.dataHora(l.criado_em) + ' · ' + ZTE.esc(l.usuario) + ' · ' + ZTE.esc(l.ip || '') +
                (l.correlacao ? ' · correlação ' + ZTE.esc(l.correlacao) : '') + '</div>' +
                bloco('Antes', l.antes) + bloco('Depois', l.depois));
            ZTE.abrirModal('modal-detalhe');
        });
    });
})();
</script>
</body>
</html>
