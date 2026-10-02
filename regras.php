<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'regras';
$zte_perm_pagina = 'ver';
include('nav/header.php');
$zte_pode = $zte_schema_ok && Permissao::tem('regra.gerenciar');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="zte-aviso info"><i class="bi bi-info-circle"></i>
        <div>A regra só <strong>seleciona</strong>: modelo, revisões de hardware, versões de origem e o firmware de destino. Nada é executado por ela —
            a execução é sempre por uma <strong>campanha</strong> simulada e aprovada. Uma ONU nunca é rebaixada para uma versão mais antiga.</div></div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-funnel"></i> Regras <span class="lc-badge-count" id="zte-total">0</span></span>
            <?php if ($zte_pode): ?><button type="button" class="lc-btn-black" id="btn-nova"><i class="bi bi-plus-lg"></i> Nova regra</button><?php endif; ?>
        </div>
        <div class="lc-table-wrap" style="min-height:0">
            <table class="lc-table">
                <thead><tr><th>Regra</th><th>Seleciona</th><th class="prio-7">Origem</th><th>Firmware de destino</th><th class="prio-8">OLT</th><th></th></tr></thead>
                <tbody id="zte-linhas"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-regra" onclick="ZTE.fecharSeFora(event, 'modal-regra')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="r-titulo">Nova regra</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-regra')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input type="hidden" id="r-id"><input type="hidden" id="r-versao">
            <div class="zte-form-2">
                <div class="span2"><label class="lc-label">Nome</label><input class="lc-input" id="r-nome" maxlength="80" placeholder="ex.: F670L V9 → P3N10"></div>
                <div><label class="lc-label">Modelo</label><select class="lc-input" id="r-modelo"></select>
                    <div class="lc-input-hint">Modelos ZTE encontrados no inventário.</div></div>
                <div><label class="lc-label">OLT</label><select class="lc-input" id="r-olt"><option value="">Qualquer OLT</option></select></div>
                <div class="span2"><label class="lc-label">Revisões de hardware aceitas</label><div id="r-hws" class="zte-ops" style="gap:14px"></div></div>
                <div class="span2"><label class="lc-label">Firmware de destino</label><select class="lc-input" id="r-fw"></select>
                    <div class="lc-input-hint" id="r-fw-dica"></div></div>
                <div class="span2"><label class="lc-label">Versões de origem</label>
                    <label class="zte-chk"><input type="radio" name="r-origem" value="anterior" checked> Qualquer versão mais antiga que o firmware</label>
                    <label class="zte-chk"><input type="radio" name="r-origem" value="lista"> Só estas versões:</label>
                    <div id="r-versoes" class="zte-ops" style="gap:14px;margin:4px 0 0 22px"></div></div>
                <div class="span2"><label class="lc-label">Observação</label><input class="lc-input" id="r-obs" maxlength="500"></div>
            </div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-regra')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar">Salvar</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var PODE = <?= $zte_pode ? 'true' : 'false' ?>;
    var d = { regras: [], firmwares: [], olts: [], modelos: [] };
    var editando = null;

    function porId(id) { return d.regras.filter(function (r) { return r.id === id; })[0]; }

    function linha(r) {
        var a = '<div class="zte-acoes">' + (PODE ? '<button type="button" class="lc-btn-outline" data-id="' + r.id + '" title="Mais ações"><i class="bi bi-three-dots"></i></button>' : '') + '</div>';
        return '<tr' + (r.ativo ? '' : ' style="opacity:.6"') + '><td><strong>' + ZTE.esc(r.nome) + '</strong>' + (r.ativo ? '' : ' ' + ZTE.badge('inativa')) +
            (r.campanhas ? '<div class="zte-sub">' + r.campanhas + ' campanha(s)</div>' : '') + '</td>' +
            '<td>' + ZTE.esc(r.modelo) + '<div class="zte-sub">HW ' + ZTE.esc(r.hw_aceitos.join(', ')) + '</div></td>' +
            '<td class="prio-7 zte-sub">' + (r.versoes_origem.length ? ZTE.esc(r.versoes_origem.join(', ')) : 'qualquer mais antiga') + '</td>' +
            '<td><span class="zte-mono">' + ZTE.esc(r.modelo_familia + ' ' + r.versao_firmware) + '</span> ' + ZTE.badge(r.firmware_estado) + '</td>' +
            '<td class="prio-8">' + ZTE.esc(r.olt_nome || 'qualquer') + '</td><td>' + a + '</td></tr>';
    }

    function render() {
        $('#zte-total').text(d.regras.length);
        $('#zte-linhas').html(d.regras.length ? d.regras.map(linha).join('') : '<tr><td colspan="6" class="lc-empty">Nenhuma regra.</td></tr>');
    }
    function carregar() { return ZTE.api('regra.listar').then(function (x) { d = x; render(); }).catch(ZTE.erro); }

    // ------------------------------------------------------------ formulario
    function modelosUnicos() {
        var m = {};
        d.modelos.forEach(function (x) { m[x.modelo] = 1; });
        if (editando && !m[editando.modelo]) m[editando.modelo] = 1;
        return Object.keys(m);
    }

    function atualizarDependentes() {
        var modelo = $('#r-modelo').val();
        var marcados = editando ? editando.hw_aceitos.map(function (h) { return h.toUpperCase(); }) : [];
        var hws = {}, versoes = {};
        d.modelos.filter(function (x) { return x.modelo === modelo; }).forEach(function (x) {
            hws[x.hw_versao] = (hws[x.hw_versao] || 0) + (+x.onus);
            (x.versoes || '').split(',').forEach(function (v) { if (v) versoes[v] = 1; });
        });
        if (editando) editando.hw_aceitos.forEach(function (h) { if (!(h in hws)) hws[h] = 0; });
        $('#r-hws').html(Object.keys(hws).map(function (h) {
            return '<label class="zte-chk"><input type="checkbox" class="r-hw" value="' + ZTE.esc(h) + '"' + (marcados.indexOf(h.toUpperCase()) !== -1 || !editando ? ' checked' : '') + '> ' +
                ZTE.esc(h) + ' <span class="zte-sub">(' + hws[h] + ' ONUs)</span></label>';
        }).join('') || '<span class="zte-sub">Nenhuma ONU deste modelo no inventário.</span>');
        var origem = editando ? editando.versoes_origem.map(function (v) { return v.toUpperCase(); }) : [];
        if (editando) editando.versoes_origem.forEach(function (v) { versoes[v] = 1; });
        $('#r-versoes').html(Object.keys(versoes).sort().map(function (v) {
            return '<label class="zte-chk"><input type="checkbox" class="r-ver" value="' + ZTE.esc(v) + '"' + (origem.indexOf(v.toUpperCase()) !== -1 ? ' checked' : '') + '> <span class="zte-mono">' + ZTE.esc(v) + '</span></label>';
        }).join('') || '<span class="zte-sub">nenhuma versão lida no inventário</span>');
        // Firmwares que declaram este modelo.
        var fws = d.firmwares.filter(function (f) { return f.compat.some(function (c) { return c.modelo === modelo; }); });
        $('#r-fw').html(fws.map(function (f) {
            return '<option value="' + f.id + '">' + ZTE.esc(f.modelo_familia + ' ' + f.versao_firmware) + ' — ' + ZTE.esc(f.estado) + '</option>';
        }).join('') || '<option value="">Nenhum firmware declara este modelo</option>');
        if (editando) $('#r-fw').val(editando.firmware_id);
        dicaFw();
    }
    function dicaFw() {
        var f = d.firmwares.filter(function (x) { return String(x.id) === $('#r-fw').val(); })[0];
        $('#r-fw-dica').text(f ? 'Compatível com: ' + f.compat.map(function (c) { return c.modelo + ' · ' + c.hw_versao; }).join(', ') +
            '. A versão do firmware ("' + f.versao_firmware + '") precisa ser igual à que a OLT mostra para a ONU já atualizada.' : '');
    }

    function abrir(r) {
        editando = r;
        $('#r-titulo').text(r ? 'Editar regra' : 'Nova regra');
        $('#r-id').val(r ? r.id : ''); $('#r-versao').val(r ? r.versao : '');
        $('#r-nome').val(r ? r.nome : ''); $('#r-obs').val(r ? (r.observacao || '') : '');
        $('#r-olt').find('option:not(:first)').remove();
        d.olts.forEach(function (o) { $('#r-olt').append($('<option>').val(o.id).text(o.nome)); });
        $('#r-olt').val(r && r.olt_id ? r.olt_id : '');
        $('#r-modelo').html(modelosUnicos().map(function (m) { return '<option>' + ZTE.esc(m) + '</option>'; }).join(''));
        if (r) $('#r-modelo').val(r.modelo);
        $('input[name="r-origem"][value="' + (r && r.versoes_origem.length ? 'lista' : 'anterior') + '"]').prop('checked', true);
        atualizarDependentes();
        ZTE.abrirModal('modal-regra');
    }

    function salvar() {
        var lista = $('input[name="r-origem"]:checked').val() === 'lista';
        var dados = { id: $('#r-id').val() || 0, versao: $('#r-versao').val(), nome: $('#r-nome').val(), modelo: $('#r-modelo').val(),
                      olt_id: $('#r-olt').val() || 0, firmware_id: $('#r-fw').val(), observacao: $('#r-obs').val(), ativo: editando ? editando.ativo : 1,
                      hw_aceitos: $('.r-hw:checked').map(function () { return this.value; }).get(),
                      versoes_origem: lista ? $('.r-ver:checked').map(function () { return this.value; }).get() : [] };
        if (lista && !dados.versoes_origem.length) { ZTE.toast('avis', 'Marque ao menos uma versão de origem.'); return; }
        ZTE.api('regra.salvar', dados, 'POST')
            .then(function () { ZTE.fecharModal('modal-regra'); ZTE.toast('ok', 'Regra salva.'); carregar(); })
            .catch(ZTE.erro);
    }

    $(function () {
        carregar();
        $('#btn-nova').on('click', function () {
            if (!d.modelos.length) { ZTE.toast('avis', 'Não há ONUs ZTE no inventário.', 'Atualize o inventário antes de criar regras.'); return; }
            abrir(null);
        });
        $('#r-modelo').on('change', function () { editando = editando ? $.extend({}, editando, { hw_aceitos: [], versoes_origem: [] }) : null; atualizarDependentes(); });
        $('#r-fw').on('change', dicaFw);
        $('#btn-salvar').on('click', salvar);
        $('#zte-linhas').on('click', 'button[data-id]', function () {
            var r = porId(+$(this).attr('data-id'));
            ZTE.menu(this, [
                { texto: 'Editar', icone: 'bi-pencil', acao: function () { abrir(r); } },
                { texto: r.ativo ? 'Desativar' : 'Reativar', icone: r.ativo ? 'bi-pause-circle' : 'bi-play-circle', acao: function () {
                    ZTE.api('regra.ativar', { id: r.id, ativo: r.ativo ? 0 : 1 }, 'POST').then(carregar).catch(ZTE.erro); } },
                '-', { texto: 'Remover', icone: 'bi-trash', perigo: true, acao: function () {
                    ZTE.confirmar({ titulo: 'Remover regra', perigo: true, textoOk: 'Remover', msg: 'Remover a regra ' + r.nome + '?',
                                    sub: 'Só é possível se nenhuma campanha a usou. Caso contrário, desative.' })
                        .then(function (ok) { if (ok) ZTE.api('regra.remover', { id: r.id }, 'POST').then(carregar).catch(ZTE.erro); });
                } }
            ]);
        });
    });
})();
</script>
</body>
</html>
