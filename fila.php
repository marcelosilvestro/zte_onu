<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'fila';
$zte_perm_pagina = 'ver';
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="lc-filter-bar">
        <div class="lc-filter-group"><span class="lc-filter-label">Campanha</span><select class="lc-input-date w200" id="f-camp"><option value="">Todas</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Estado</span><select class="lc-input-date" id="f-estado">
            <option value="">Todos</option><option value="pendente">Na fila</option><option value="enviando">Enviando</option><option value="ativando">Ativando</option>
            <option value="verificando">Verificando</option><option value="concluido">Concluído</option><option value="falha">Falha</option><option value="inconclusivo">Inconclusivo</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Buscar</span><input type="text" class="lc-input-date w200" id="f-busca" placeholder="nome ou SN"></div>
        <button type="button" class="lc-btn-black" id="btn-filtrar"><i class="bi bi-funnel"></i> Filtrar</button>
        <span id="zte-worker"></span>
        <span class="zte-sub">Jobs são executados pelo worker, dentro da janela da campanha. A lista se atualiza a cada 30 s.</span>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-list-task"></i> Jobs <span class="lc-badge-count" id="zte-total">0</span></span>
        </div>
        <div class="lc-table-wrap">
            <table class="lc-table">
                <thead><tr><th>ONU</th><th class="prio-7">Campanha</th><th>Estado</th><th class="prio-6">Tentativas</th><th class="prio-8">Versão</th><th></th></tr></thead>
                <tbody id="zte-linhas"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
        <div class="zte-paginacao" id="zte-pag"></div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-ev" onclick="ZTE.fecharSeFora(event, 'modal-ev')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="ev-titulo">Eventos do job</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-ev')">&times;</button>
        </div>
        <div class="lc-modal-body" id="ev-corpo"></div>
        <div class="lc-modal-footer"><button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-ev')">Fechar</button></div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var linhas = [], pagina = 1, podeRep = false;
    var ROT = { pendente: 'Na fila', enviando: 'Enviando', ativando: 'Ativando', verificando: 'Verificando', concluido: 'Concluído', falha: 'Falha', inconclusivo: 'Inconclusivo' };
    var COR = { pendente: 'nao_testavel', enviando: 'pendente', ativando: 'pendente', verificando: 'pendente', concluido: 'ok', falha: 'erro', inconclusivo: 'aviso' };
    var ORIGEM = { repositorio: 'repositório', comunicacao_olt: 'comunicação com a OLT', procedimento_firmware: 'procedimento de firmware', verificacao: 'verificação' };
    var inicialCamp = new URLSearchParams(window.location.search).get('campanha') || '';

    function badge(e) { return '<span class="zte-res ' + COR[e] + '">' + ROT[e] + '</span>'; }

    // % da transferencia lido do update-status da OLT (so enquanto "enviando").
    function andamento(j) {
        if (j.estado !== 'enviando' || j.progresso === null || j.progresso === undefined) return '';
        var p = Math.max(0, Math.min(100, parseInt(j.progresso, 10) || 0));
        return '<div class="zte-sub">Transferência ' + p + '%</div><div class="zte-progresso" style="max-width:160px;height:6px"><div style="width:' + p + '%"></div></div>';
    }

    // Versao DESTE job: final quando concluiu; senao "origem -> alvo" da tentativa (nunca a
    // versao atual da ONU, que pode ter mudado depois por outro job).
    function versao(j) {
        if (j.estado === 'concluido' && j.sw_versao_final) return ZTE.esc(j.sw_versao_final);
        if (j.sw_inicial || j.sw_alvo) return ZTE.esc(j.sw_inicial || '?') + '<div class="zte-sub">→ ' + ZTE.esc(j.sw_alvo || '?') + '</div>';
        return ZTE.esc(j.sw_atual || '—');
    }

    function render(d) {
        linhas = d.linhas;
        podeRep = d.pode_reprocessar;
        var $c = $('#f-camp'), atual = $c.val() || inicialCamp;
        $c.find('option:not(:first)').remove();
        d.campanhas.forEach(function (c) { $c.append($('<option>').val(c.id).text(c.nome + ' (' + c.estado + ')')); });
        $c.val(atual);
        inicialCamp = '';
        $('#zte-total').text(d.total);
        $('#zte-linhas').html(linhas.length ? linhas.map(function (j, i) {
            var falha = j.falha_detalhe ? '<div class="zte-sub zte-quebra">' + (j.falha_origem ? ZTE.esc(ORIGEM[j.falha_origem] || j.falha_origem) + ': ' : '') + ZTE.esc(j.falha_detalhe) + '</div>' : '';
            var a = '<div class="zte-acoes"><button type="button" class="lc-btn-outline" data-ev="' + i + '" title="Eventos"><i class="bi bi-clock-history"></i></button>';
            if (podeRep && (j.estado === 'falha' || j.estado === 'inconclusivo') && ['aprovada', 'executando', 'pausada'].indexOf(j.campanha_estado) !== -1) {
                a += '<button type="button" class="lc-btn-outline" data-rep="' + i + '" title="Reprocessar"><i class="bi bi-arrow-counterclockwise"></i></button>';
            }
            a += '</div>';
            return '<tr><td><strong>' + ZTE.esc(j.onu_nome || '—') + '</strong><div class="zte-sub zte-mono">' + ZTE.esc(j.olt_nome + ' ' + j.posicao + ' · ' + (j.sn || '')) + '</div></td>' +
                '<td class="prio-7"><a href="campanhas.php?id=' + j.campanha_id + '">' + ZTE.esc(j.campanha_nome) + '</a><div class="zte-sub">' + ZTE.dataHora(j.iniciado_em || j.criado_em) + '</div></td>' +
                '<td>' + badge(j.estado) + andamento(j) + falha + '</td>' +
                '<td class="prio-6">' + j.tentativas + ' de ' + j.max_tentativas + '</td>' +
                '<td class="prio-8 zte-mono">' + versao(j) + '</td><td>' + a + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="lc-empty">Nenhum job.</td></tr>');
        ZTE.paginacao($('#zte-pag'), d.pagina, d.por_pagina, d.total, function (p) { pagina = p; carregar(); });
    }

    function carregar() {
        return ZTE.api('job.listar', { pagina: pagina, campanha_id: $('#f-camp').val() || inicialCamp, estado: $('#f-estado').val(), busca: $('#f-busca').val() })
            .then(render).catch(ZTE.erro);
    }

    $(function () {
        carregar();
        ZTE.estadoWorker($('#zte-worker'));
        setInterval(function () { if (!document.hidden && !$('.lc-overlay.ativo').length) { carregar(); ZTE.estadoWorker($('#zte-worker')); } }, 30000);
        $('#btn-filtrar').on('click', function () { pagina = 1; carregar(); });
        $('#zte-linhas').on('click', 'button[data-ev]', function () {
            var j = linhas[+$(this).attr('data-ev')];
            ZTE.api('job.eventos', { id: j.id }).then(function (x) {
                $('#ev-titulo').text('Eventos — ' + (j.onu_nome || j.posicao));
                $('#ev-corpo').html(x.eventos.length ? x.eventos.map(function (e) {
                    return '<div class="zte-etapa"><span class="zte-sub" style="white-space:nowrap">' + ZTE.dataHora(e.criado_em) + '</span><div>' +
                        (e.de_estado ? badge(e.de_estado) + ' → ' : '') + badge(e.para_estado) + '<div class="zte-sub zte-quebra">' + ZTE.esc(e.detalhe || '') +
                        (e.op_id ? ' · <span class="zte-mono">' + ZTE.esc(e.op_id) + '</span>' : '') + '</div>' +
                        (e.saida_cli ? '<pre class="zte-detalhe-json zte-mono">' + ZTE.esc(e.saida_cli) + '</pre>' : '') + '</div></div>';
                }).join('') : '<div class="lc-empty">Nenhum evento: o job ainda não começou.</div>');
                ZTE.abrirModal('modal-ev');
            }).catch(ZTE.erro);
        });
        $('#zte-linhas').on('click', 'button[data-rep]', function () {
            var j = linhas[+$(this).attr('data-rep')];
            ZTE.confirmar({ titulo: 'Reprocessar job', perigo: j.estado === 'inconclusivo', textoOk: 'Reprocessar',
                            msg: 'Devolver ' + (j.onu_nome || j.posicao) + ' para a fila?',
                            sub: j.estado === 'inconclusivo' ? 'O estado real desta ONU não foi confirmado. O worker sempre lê a ONU antes de tentar de novo.' : 'As tentativas voltam a zero. Fica registrado na auditoria.' })
                .then(function (ok) {
                    if (ok) ZTE.api('job.reprocessar', { id: j.id }, 'POST').then(function () { ZTE.toast('ok', 'Job devolvido à fila.'); carregar(); }).catch(ZTE.erro);
                });
        });
    });
})();
</script>
</body>
</html>
