<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'campanhas';
$zte_perm_pagina = 'ver';
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div id="zte-modo-seguro" class="zte-aviso" style="display:none"><i class="bi bi-shield-fill-check"></i>
        <div><strong>Modo seguro ligado.</strong> Só campanhas de exatamente 1 ONU podem ser aprovadas (teste unitário).</div></div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-rocket-takeoff"></i> Campanhas <span class="lc-badge-count" id="zte-total">0</span></span>
            <div class="zte-acoes" style="align-items:center"><span id="zte-worker"></span>
            <label class="zte-chk" title="Campanhas concluídas ou abortadas que foram arquivadas"><input type="checkbox" id="f-arquivadas"> Mostrar arquivadas</label>
            <button type="button" class="lc-btn-black" id="btn-nova" style="display:none"><i class="bi bi-plus-lg"></i> Nova campanha</button></div>
        </div>
        <div class="lc-table-wrap" style="min-height:0">
            <table class="lc-table">
                <thead><tr><th>Campanha</th><th class="prio-7">OLT · regra</th><th>Estado</th><th>Progresso</th><th class="prio-8">Janela</th><th></th></tr></thead>
                <tbody id="zte-linhas"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-camp" onclick="ZTE.fecharSeFora(event, 'modal-camp')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="c-titulo">Nova campanha</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-camp')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input type="hidden" id="c-id"><input type="hidden" id="c-versao">
            <div class="zte-form-2">
                <div><label class="lc-label">Nome</label><input class="lc-input" id="c-nome" maxlength="120"></div>
                <div><label class="lc-label">Tipo</label><select class="lc-input" id="c-tipo">
                    <option value="campanha">Única (roda uma vez)</option>
                    <option value="recorrente">Recorrente (rodadas pela agenda)</option></select></div>
                <div class="span2 c-so-recorrente" style="display:none">
                    <div class="zte-form-2">
                        <div><label class="lc-label">Dias da semana</label><div id="c-dias" class="zte-ops" style="gap:10px"></div></div>
                        <div><label class="lc-label">Máximo de ONUs por rodada</label><input class="lc-input" id="c-teto" type="number" min="1" max="500"></div>
                    </div>
                    <div class="lc-input-hint">A cada dia marcado, dentro da janela, o worker pega as ONUs desatualizadas (pela regra) das PONs escolhidas, até o máximo por rodada; o resto fica para as próximas. Com o modo seguro ligado, cada rodada atualiza só 1 ONU.</div>
                </div>
                <div><label class="lc-label">OLT</label><select class="lc-input" id="c-olt"></select></div>
                <div><label class="lc-label">Regra</label><select class="lc-input" id="c-regra"></select></div>
                <div class="span2"><label class="lc-label">PONs (nenhuma marcada = todas)</label><div id="c-pons" class="zte-ops" style="gap:10px"></div></div>
                <div class="span2 c-so-unica"><label class="lc-label">ONUs específicas (opcional)</label>
                    <input class="lc-input" id="c-onu-busca" placeholder="filtrar por nome ou posição (ex.: maria ou 2/11:4)" autocomplete="off" style="margin-bottom:6px">
                    <select class="lc-input" id="c-onus" multiple size="6"></select>
                    <div class="lc-input-hint" id="c-onus-dica">Para o teste unitário do modo seguro, escolha 1 ONU. Com ONUs escolhidas, as PONs acima não restringem nada. Ctrl+clique marca mais de uma.</div></div>
                <div><label class="lc-label">Janela: início</label><input class="lc-input" id="c-ini" type="time"></div>
                <div><label class="lc-label">Janela: fim</label><input class="lc-input" id="c-fim" type="time"></div>
                <div><label class="lc-label">Máximo simultâneo por PON</label><input class="lc-input" id="c-mpp" type="number" min="1" max="64"></div>
                <div><label class="lc-label">Máximo simultâneo na OLT</label><input class="lc-input" id="c-mc" type="number" min="1" max="64"></div>
                <div><label class="lc-label">Falhas até pausar</label><input class="lc-input" id="c-mf" type="number" min="1" max="1000"></div>
                <div><label class="lc-label">Falhas até pausar (%)</label><input class="lc-input" id="c-mfp" type="number" min="1" max="100"></div>
                <div><label class="lc-label">Retentativas por ONU</label><input class="lc-input" id="c-ret" type="number" min="0" max="5"></div>
            </div>
            <div class="lc-input-hint zte-mt">Salvar não executa nada. Depois, simule para ver quem entra e quem fica de fora.</div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-camp')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar">Salvar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-det" onclick="ZTE.fecharSeFora(event, 'modal-det')">
    <div class="lc-modal-box lg" style="max-width:820px">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="det-titulo">Campanha</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-det')">&times;</button>
        </div>
        <div class="lc-modal-body" id="det-corpo"></div>
        <div class="lc-modal-footer" id="det-acoes"></div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var d = { campanhas: [], regras: [], olts: [], pons: [], padroes: {}, pode: {} };
    var ROT = { rascunho: 'Rascunho', simulada: 'Simulada', aprovada: 'Aprovada', executando: 'Executando', pausada: 'Pausada', concluida: 'Concluída', abortada: 'Abortada' };
    var COR = { rascunho: 'nao_testavel', simulada: 'pendente', aprovada: 'integridade_remota_verificada', executando: 'ok', pausada: 'aviso', concluida: 'ok', abortada: 'erro' };

    function badgeEstado(e) { return '<span class="zte-res ' + COR[e] + '">' + ROT[e] + '</span>'; }
    function porId(id) { return d.campanhas.filter(function (c) { return c.id === id; })[0]; }

    function progresso(c) {
        if (!c.total_jobs) {
            return c.simulacao_resumo ? '<span class="zte-sub">simulada: ' + c.simulacao_resumo.entram + ' entram, ' + c.simulacao_resumo.fora + ' fora</span>' : '<span class="zte-sub">—</span>';
        }
        var j = c.jobs, feitos = j.concluido + j.falha;
        var pct = Math.round(feitos * 100 / c.total_jobs);
        return '<div class="zte-progresso" title="' + feitos + ' de ' + c.total_jobs + ' finalizados"><div style="width:' + pct + '%"></div></div>' +
            '<div class="zte-sub">' + j.concluido + ' ok · ' + j.falha + ' falha · ' + (j.enviando + j.ativando + j.verificando) + ' em curso · ' +
            j.pendente + ' na fila' + (j.inconclusivo ? ' · <strong>' + j.inconclusivo + ' inconclusivo</strong>' : '') + '</div>';
    }

    function janela(c) { return c.tipo === 'avulsa' ? 'sem janela' : c.janela_inicio + '–' + c.janela_fim; }

    // Recorrente: agenda + ultima rodada no lugar da barra de progresso (ela nao tem jobs proprios).
    function selosTipo(c) {
        if (c.tipo === 'recorrente') return ' <span class="zte-res integridade_remota_verificada">recorrente</span>';
        if (c.tipo === 'rodada') return ' <span class="zte-res nao_testavel">rodada</span>';
        return '';
    }
    function progressoOuAgenda(c) {
        if (c.tipo !== 'recorrente') return progresso(c);
        return '<div class="zte-sub"><strong>' + ZTE.esc(c.dias_texto) + '</strong> · até ' + (c.teto_rodada || d.teto_padrao) + ' por rodada · ' +
            c.rodadas_total + ' rodada(s)' + (c.rodadas_ativas ? ', ' + c.rodadas_ativas + ' em curso' : '') + '</div>' +
            (c.ultima_rodada_resumo ? '<div class="zte-sub zte-quebra">Última: ' + ZTE.esc(c.ultima_rodada_resumo) + '</div>' : '');
    }

    function render() {
        $('#zte-total').text(d.campanhas.length);
        $('#zte-modo-seguro').toggle(!!d.modo_seguro);
        $('#btn-nova').toggle(!!d.pode.criar);
        $('#zte-linhas').html(d.campanhas.length ? d.campanhas.map(function (c) {
            return '<tr style="cursor:pointer" data-id="' + c.id + '"><td><strong>' + ZTE.esc(c.nome) + '</strong>' + selosTipo(c) + '<div class="zte-sub">' +
                ZTE.esc(c.modelo_familia + ' → ' + c.versao_firmware) + '</div></td>' +
                '<td class="prio-7">' + ZTE.esc(c.olt_nome) + '<div class="zte-sub">' + ZTE.esc(c.regra_nome) + '</div></td>' +
                '<td>' + badgeEstado(c.estado) + (c.pausa_motivo ? '<div class="zte-sub zte-quebra">' + ZTE.esc(c.pausa_motivo) + '</div>' : '') + '</td>' +
                '<td style="min-width:180px">' + progressoOuAgenda(c) + '</td>' +
                '<td class="prio-8 zte-sub">' + janela(c) + '</td>' +
                '<td><div class="zte-acoes"><button type="button" class="lc-btn-outline" data-abrir="' + c.id + '"><i class="bi bi-box-arrow-up-right"></i></button></div></td></tr>';
        }).join('') : '<tr><td colspan="6" class="lc-empty">' + ($('#f-arquivadas').is(':checked') ? 'Nenhuma campanha arquivada.' : 'Nenhuma campanha.') + '</td></tr>');
    }
    function carregar() {
        return ZTE.api('campanha.listar', { arquivadas: $('#f-arquivadas').is(':checked') ? 1 : 0 }).then(function (x) { d = x; render(); }).catch(ZTE.erro);
    }

    function tipoForm() {
        var rec = $('#c-tipo').val() === 'recorrente';
        $('.c-so-recorrente').toggle(rec);
        $('.c-so-unica').toggle(!rec);
    }

    // ------------------------------------------------------------ formulario
    function ponsDaOlt() {
        var olt = +$('#c-olt').val(), marcadas = ($('#c-pons').data('marcadas') || []);
        var pons = d.pons.filter(function (p) { return +p.olt_id === olt; });
        $('#c-pons').html(pons.map(function (p) {
            var v = p.slot + '/' + p.porta;
            return '<label class="zte-chk"><input type="checkbox" class="c-pon" value="' + v + '"' + (marcadas.indexOf(v) !== -1 ? ' checked' : '') + '> ' + v + '</label>';
        }).join('') || '<span class="zte-sub">sem inventário nesta OLT</span>');
        $('#c-regra').html(d.regras.filter(function (r) { return !r.olt_id || r.olt_id === olt; }).map(function (r) {
            return '<option value="' + r.id + '">' + ZTE.esc(r.nome) + ' — ' + ZTE.esc(r.modelo) + ' → ' + ZTE.esc(r.versao_firmware) + '</option>';
        }).join('') || '<option value="">Nenhuma regra ativa para esta OLT</option>');
    }

    // ONUs especificas: a escolha sobrevive ao filtro de busca.
    var onusEscolhidas = {};
    function listarOnus() {
        var olt = +$('#c-olt').val(), filtro = ($('#c-onu-busca').val() || '').toLowerCase();
        var lista = (d.onus || []).filter(function (o) {
            if (+o.olt_id !== olt) return false;
            var pos = o.slot + '/' + o.porta + ':' + o.onu_num;
            return !filtro || pos.indexOf(filtro) !== -1 || String(o.nome || '').toLowerCase().indexOf(filtro) !== -1 || onusEscolhidas[o.id];
        }).slice(0, 400);
        $('#c-onus').html(lista.map(function (o) {
            return '<option value="' + o.id + '"' + (onusEscolhidas[o.id] ? ' selected' : '') + '>' +
                ZTE.esc(o.slot + '/' + o.porta + ':' + o.onu_num + ' — ' + (o.nome || 'sem nome') + ' — ' + (o.sw_versao || 'versão não lida') + (o.estado === 'online' ? '' : ' (' + o.estado + ')')) + '</option>';
        }).join(''));
        var n = Object.keys(onusEscolhidas).length;
        $('#c-onus-dica').html(n ? '<strong>' + n + ' ONU(s) escolhida(s).</strong> As PONs acima não restringem nada.'
                                 : 'Para o teste unitário do modo seguro, escolha 1 ONU. Com ONUs escolhidas, as PONs acima não restringem nada. Ctrl+clique marca mais de uma.');
    }

    function abrirForm(c) {
        var p = c || d.padroes;
        $('#c-titulo').text(c ? 'Editar campanha' : 'Nova campanha');
        $('#c-id').val(c ? c.id : ''); $('#c-versao').val(c ? c.versao : '');
        $('#c-nome').val(c ? c.nome : '');
        $('#c-olt').html(d.olts.map(function (o) { return '<option value="' + o.id + '">' + ZTE.esc(o.nome) + '</option>'; }).join(''));
        if (c) $('#c-olt').val(c.olt_id);
        $('#c-pons').data('marcadas', c ? c.escopo.pons : []);
        ponsDaOlt();
        onusEscolhidas = {};
        (c ? c.escopo.onus : []).forEach(function (id) { onusEscolhidas[id] = true; });
        $('#c-onu-busca').val('');
        listarOnus();
        if (c) $('#c-regra').val(c.regra_id);
        $('#c-tipo').val(c && c.tipo === 'recorrente' ? 'recorrente' : 'campanha').prop('disabled', !!c);
        var marcados = c && c.dias ? c.dias : [1, 2, 3, 4, 5, 6, 7];
        $('#c-dias').html(Object.keys(d.dias).map(function (k) {
            return '<label class="zte-chk"><input type="checkbox" class="c-dia" value="' + k + '"' + (marcados.indexOf(+k) !== -1 ? ' checked' : '') + '> ' + ZTE.esc(d.dias[k]) + '</label>';
        }).join(''));
        $('#c-teto').val(c && c.teto_rodada ? c.teto_rodada : d.teto_padrao);
        tipoForm();
        $('#c-ini').val(p.janela_inicio); $('#c-fim').val(p.janela_fim);
        $('#c-mpp').val(p.max_por_pon); $('#c-mc').val(p.max_concorrentes); $('#c-mf').val(p.max_falhas);
        $('#c-mfp').val(p.max_falhas_pct); $('#c-ret').val(p.retentativas);
        ZTE.abrirModal('modal-camp');
    }

    function salvar() {
        var dados = { id: $('#c-id').val() || 0, versao: $('#c-versao').val(), nome: $('#c-nome').val(), olt_id: $('#c-olt').val(),
                      regra_id: $('#c-regra').val(), janela_inicio: $('#c-ini').val(), janela_fim: $('#c-fim').val(),
                      max_por_pon: $('#c-mpp').val(), max_concorrentes: $('#c-mc').val(), max_falhas: $('#c-mf').val(),
                      max_falhas_pct: $('#c-mfp').val(), retentativas: $('#c-ret').val(),
                      pons: $('.c-pon:checked').map(function () { return this.value; }).get(),
                      onus: Object.keys(onusEscolhidas), tipo: $('#c-tipo').val(),
                      dias_semana: $('.c-dia:checked').map(function () { return this.value; }).get(), teto_rodada: $('#c-teto').val() };
        ZTE.api('campanha.salvar', dados, 'POST')
            .then(function (c) { ZTE.fecharModal('modal-camp'); ZTE.toast('ok', 'Campanha salva.', 'Agora simule.'); carregar(); abrirDetalhe(c.id); })
            .catch(ZTE.erro);
    }

    // ------------------------------------------------------------ detalhe
    function tabela(lista, cab, linhaFn) {
        if (!lista.length) return '<div class="zte-sub" style="padding:6px 0">nenhuma</div>';
        return '<div class="lc-table-wrap" style="max-height:220px;min-height:0"><table class="lc-table"><thead><tr>' +
            cab.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody>' + lista.map(linhaFn).join('') + '</tbody></table></div>';
    }

    function abrirDetalhe(id) {
        ZTE.api('campanha.obter', { id: id }).then(function (c) {
            var s = c.simulacao;
            $('#det-titulo').text(c.nome);
            var h = '<div style="margin-bottom:8px">' + badgeEstado(c.estado) + ' <span class="zte-sub">' + ZTE.esc(c.olt_nome) + ' · ' + ZTE.esc(c.regra_nome) +
                ' · ' + ZTE.esc(c.modelo_familia + ' → ' + c.versao_firmware) + ' · ' + (c.tipo === 'avulsa' ? 'sem janela' : 'janela ' + janela(c)) +
                ' · até ' + c.max_concorrentes + ' simultâneas (' + c.max_por_pon + ' por PON)</span></div>';
            if (c.total_jobs) h += '<label class="lc-label">Progresso</label>' + progresso(c) + '<div class="zte-sub"><a href="fila.php?campanha=' + c.id + '">ver a fila de jobs</a></div>';
            if (c.tipo === 'recorrente') {
                h += '<label class="lc-label">Agenda</label><div class="zte-sub">' + ZTE.esc(c.dias_texto) + ' · janela ' + janela(c) + ' · até ' +
                     (c.teto_rodada || d.teto_padrao) + ' ONU(s) por rodada' + (c.ultima_rodada_resumo ? '<br>Última rodada: ' + ZTE.esc(c.ultima_rodada_resumo) : '') + '</div>';
                if (c.falhas_excluidas) {
                    h += '<div class="zte-sub zte-mt"><strong>' + c.falhas_excluidas + ' ONU(s)</strong> falharam em rodadas anteriores e estão fora das próximas até serem liberadas.</div>';
                }
                h += '<label class="lc-label zte-mt">Rodadas (' + c.rodadas_total + ')</label>' +
                    tabela(c.rodadas || [], ['Rodada', 'Estado', 'Resultado'], function (r) {
                        var j = r.jobs;
                        return '<tr><td><a href="campanhas.php?id=' + r.id + '">' + ZTE.esc(r.nome) + '</a></td><td>' + badgeEstado(r.estado) + '</td><td class="zte-sub">' +
                            j.concluido + ' ok · ' + j.falha + ' falha · ' + (j.enviando + j.ativando + j.verificando) + ' em curso · ' + j.pendente + ' na fila</td></tr>';
                    });
            }
            if (c.tipo === 'rodada' && c.pai_id) {
                h += '<div class="zte-sub zte-mt">Rodada da campanha recorrente <a href="campanhas.php?id=' + c.pai_id + '">#' + c.pai_id + '</a>.</div>';
            }
            if (s) {
                h += '<label class="lc-label zte-mt">Verificações ' + (c.simulacao_vencida && c.estado === 'simulada' ? '<span class="zte-res aviso">simulação vencida</span>' : '') +
                     ' <span class="zte-sub">(' + ZTE.dataHora(s.calculado_em) + ')</span></label>' +
                    s.verificacoes.map(function (v) {
                        return '<div class="zte-etapa">' + ZTE.badge(v.resultado) + '<div><strong>' + ZTE.esc(v.titulo) + '</strong>' +
                            (v.bloqueia ? ' <span class="zte-res erro">bloqueia</span>' : '') + '<div class="zte-sub zte-quebra">' + ZTE.esc(v.detalhe) + '</div></div></div>';
                    }).join('');
                var mot = Object.keys(s.resumo.por_motivo).map(function (k) { return (c.motivos[k] || k) + ': ' + s.resumo.por_motivo[k]; }).join(' · ');
                // Recorrente: o que importa e quantas RODADAS levaria com o teto (1 por rodada no modo seguro).
                var tetoEf = c.tipo === 'recorrente' ? (d.modo_seguro ? 1 : (c.teto_rodada || d.teto_padrao)) : 0;
                h += '<label class="lc-label zte-mt">' + (c.tipo === 'recorrente'
                        ? 'Desatualizadas hoje: ' + s.resumo.entram + ' ONU(s) · cerca de ' + Math.ceil(s.resumo.entram / tetoEf) + ' rodada(s) de ' + tetoEf +
                          (d.modo_seguro ? ' (modo seguro ligado)' : '') + ' · ficam de fora: ' + s.resumo.fora
                        : 'Entram: ' + s.resumo.entram + ' ONU(s) em ' + s.lotes + ' lote(s) · ficam de fora: ' + s.resumo.fora) + '</label>' +
                    (mot ? '<div class="zte-sub" style="margin-bottom:6px">' + ZTE.esc(mot) + '</div>' : '') +
                    tabela(s.entram, ['Posição', 'Nome', 'HW', 'Versão atual → alvo'], function (o) {
                        return '<tr><td class="zte-mono">' + ZTE.esc(o.posicao) + '</td><td>' + ZTE.esc(o.nome || '') + '</td><td>' + ZTE.esc(o.hw || '') +
                            '</td><td class="zte-mono">' + ZTE.esc(o.sw || '?') + ' → ' + ZTE.esc(s.resumo.versao_alvo) + '</td></tr>';
                    }) +
                    '<label class="lc-label zte-mt">Ficam de fora (com motivo)</label>' +
                    tabela(s.fora, ['Posição', 'Nome', 'Versão', 'Motivo'], function (o) {
                        return '<tr><td class="zte-mono">' + ZTE.esc(o.posicao) + '</td><td>' + ZTE.esc(o.nome || '') + '</td><td class="zte-mono">' + ZTE.esc(o.sw || '—') +
                            '</td><td>' + ZTE.esc(c.motivos[o.motivo] || o.motivo) + '</td></tr>';
                    });
            } else {
                h += '<div class="lc-empty">Ainda não simulada.</div>';
            }
            $('#det-corpo').html(h);
            acoes(c);
            ZTE.abrirModal('modal-det');
        }).catch(ZTE.erro);
    }

    function botao(id, txt, cls) { return '<button type="button" class="' + (cls || 'lc-btn-outline') + '" id="' + id + '">' + txt + '</button>'; }

    function acoes(c) {
        var p = d.pode, h = '';
        var rec = c.tipo === 'recorrente';
        if (p.criar && rec && c.estado === 'pausada') h += botao('a-editar', '<i class="bi bi-pencil"></i> Editar');
        if (p.criar && c.tipo !== 'avulsa' && c.tipo !== 'rodada' && (c.estado === 'rascunho' || c.estado === 'simulada')) h += botao('a-editar', '<i class="bi bi-pencil"></i> Editar') + botao('a-simular', '<i class="bi bi-calculator"></i> Simular', 'lc-btn-black');
        if (p.operar && rec && c.falhas_excluidas) h += botao('a-liberar', '<i class="bi bi-arrow-counterclockwise"></i> Incluir ONUs que falharam');
        if (p.operar && !c.executou && ['rascunho', 'simulada', 'abortada'].indexOf(c.estado) !== -1) h += botao('a-excluir', '<i class="bi bi-trash"></i> Excluir');
        if (p.operar && c.executou && !c.arquivada && ['concluida', 'abortada'].indexOf(c.estado) !== -1) h += botao('a-arquivar', '<i class="bi bi-archive"></i> Arquivar');
        if (p.operar && c.arquivada) h += botao('a-desarquivar', '<i class="bi bi-box-arrow-up"></i> Desarquivar');
        if (p.aprovar && c.estado === 'simulada' && c.simulacao) {
            var bloq = c.simulacao.verificacoes.filter(function (v) { return v.bloqueia; }).map(function (v) { return v.titulo; });
            h += bloq.length
                ? '<button type="button" class="lc-btn-confirm" disabled title="Bloqueado por: ' + ZTE.esc(bloq.join(', ')) + '"><i class="bi bi-check2-circle"></i> Aprovar</button>'
                : botao('a-aprovar', '<i class="bi bi-check2-circle"></i> Aprovar', 'lc-btn-confirm');
        }
        if (p.operar && (c.estado === 'aprovada' || c.estado === 'executando')) h += botao('a-pausar', '<i class="bi bi-pause-circle"></i> Pausar');
        if (p.operar && c.estado === 'pausada') h += botao('a-retomar', '<i class="bi bi-play-circle"></i> Retomar', 'lc-btn-black');
        if (p.operar && ['rascunho', 'simulada', 'aprovada', 'executando', 'pausada'].indexOf(c.estado) !== -1) h += botao('a-abortar', '<i class="bi bi-x-octagon"></i> ' + (rec ? 'Encerrar' : 'Abortar'), 'lc-btn-confirm danger');
        h += '<button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal(\'modal-det\')">Fechar</button>';
        $('#det-acoes').html(h);

        var depois = function () { carregar(); abrirDetalhe(c.id); };
        $('#a-editar').on('click', function () { ZTE.fecharModal('modal-det'); abrirForm(c); });
        $('#a-simular').on('click', function () {
            ZTE.loading('Simulando (conferindo o arquivo no FTP)...');
            ZTE.api('campanha.simular', { id: c.id }, 'POST').then(depois).catch(ZTE.erro).finally(ZTE.fimLoading);
        });
        $('#a-aprovar').on('click', function () {
            var s = c.simulacao;
            var ciencia = s.precisa_ciencia;
            ZTE.confirmar({ titulo: 'Aprovar campanha', textoOk: 'Aprovar', digitar: ciencia ? 'CIENTE' : null, perigo: ciencia,
                            msg: rec ? 'Aprovar a campanha recorrente para ' + s.resumo.versao_alvo + '?'
                                     : 'Aprovar a atualização de ' + s.resumo.entram + ' ONU(s) para ' + s.resumo.versao_alvo + '?',
                            sub: (ciencia ? 'O acesso da OLT ao FTP ainda não foi comprovado por download: digite CIENTE para confirmar que sabe disso. ' : '') +
                                 (rec ? 'Nos dias ' + c.dias_texto + ', dentro da janela ' + janela(c) + ', o worker cria uma rodada com até ' + (c.teto_rodada || d.teto_padrao) +
                                        ' ONU(s) desatualizadas. Se a regra, o firmware ou a configuração mudar, as rodadas param até nova aprovação.'
                                      : 'A simulação é refeita agora; se o conjunto de ONUs mudou, a aprovação é recusada para você revisar. O worker executa dentro da janela ' + c.janela_inicio + '–' + c.janela_fim + '.') })
                .then(function (ok) {
                    if (!ok) return;
                    ZTE.loading('Refazendo a simulação e verificando o firmware no FTP...');
                    ZTE.api('campanha.aprovar', { id: c.id, ciencia: ciencia ? 1 : 0 }, 'POST')
                        .then(function () {
                            ZTE.fecharModal('modal-det');
                            ZTE.toast('ok', 'Campanha aprovada.', rec ? 'As rodadas serão criadas pelo worker nos dias e na janela da agenda.'
                                                                      : 'O escopo foi congelado e os jobs criados. Acompanhe pela Fila.');
                            carregar();
                        })
                        .catch(function (e) { ZTE.erro(e); depois(); }).finally(ZTE.fimLoading);
                });
        });
        $('#a-pausar').on('click', function () {
            ZTE.confirmar({ titulo: 'Pausar campanha', textoOk: 'Pausar', msg: 'Pausar ' + c.nome + '?', sub: 'Nenhum job novo é iniciado. Os que já estão em curso terminam e são verificados.' })
                .then(function (ok) { if (ok) ZTE.api('campanha.pausar', { id: c.id, motivo: 'Pausada manualmente' }, 'POST').then(depois).catch(ZTE.erro); });
        });
        $('#a-retomar').on('click', function () {
            ZTE.confirmar({ titulo: 'Retomar campanha', textoOk: 'Retomar', msg: 'Retomar ' + c.nome + '?', sub: c.pausa_motivo ? 'Motivo da pausa: ' + c.pausa_motivo : '' })
                .then(function (ok) { if (ok) ZTE.api('campanha.retomar', { id: c.id }, 'POST').then(depois).catch(ZTE.erro); });
        });
        $('#a-abortar').on('click', function () {
            ZTE.confirmar({ titulo: rec ? 'Encerrar campanha recorrente' : 'Abortar campanha', perigo: true, textoOk: rec ? 'Encerrar' : 'Abortar',
                            msg: (rec ? 'Encerrar ' : 'Abortar ') + c.nome + '?',
                            sub: (rec ? 'Nenhuma rodada nova será criada, e as rodadas com fila também são abortadas. ' : '') +
                                 'Jobs na fila viram falha. Jobs em curso não são interrompidos: o worker confere a ONU real e registra o resultado.' })
                .then(function (ok) { if (ok) ZTE.api('campanha.abortar', { id: c.id }, 'POST').then(depois).catch(ZTE.erro); });
        });
        $('#a-excluir').on('click', function () {
            ZTE.confirmar({ titulo: 'Excluir campanha', perigo: true, textoOk: 'Excluir', msg: 'Excluir ' + c.nome + '?',
                            sub: 'Ela nunca enviou comandos à OLT, então nada se perde do histórico de atualizações. A exclusão fica na auditoria.' })
                .then(function (ok) {
                    if (ok) ZTE.api('campanha.excluir', { id: c.id }, 'POST')
                        .then(function () { ZTE.fecharModal('modal-det'); ZTE.toast('ok', 'Campanha excluída.'); carregar(); }).catch(ZTE.erro);
                });
        });
        $('#a-arquivar, #a-desarquivar').on('click', function () {
            var arquivar = this.id === 'a-arquivar';
            ZTE.api('campanha.arquivar', { id: c.id, arquivar: arquivar ? 1 : 0 }, 'POST')
                .then(function () {
                    ZTE.fecharModal('modal-det');
                    ZTE.toast('ok', arquivar ? 'Campanha arquivada.' : 'Campanha desarquivada.', arquivar ? 'Ela aparece em "Mostrar arquivadas"; o histórico na Fila continua.' : '');
                    carregar();
                }).catch(ZTE.erro);
        });
        $('#a-liberar').on('click', function () {
            ZTE.confirmar({ titulo: 'Incluir ONUs que falharam', textoOk: 'Incluir', msg: 'Incluir de novo as ' + c.falhas_excluidas + ' ONU(s) que falharam?',
                            sub: 'Elas voltam a ser candidatas nas próximas rodadas (se continuarem desatualizadas).' })
                .then(function (ok) { if (ok) ZTE.api('campanha.liberar_falhas', { id: c.id }, 'POST').then(depois).catch(ZTE.erro); });
        });
    }

    $(function () {
        ZTE.estadoWorker($('#zte-worker'));
        setInterval(function () { if (!document.hidden) { carregar(); ZTE.estadoWorker($('#zte-worker')); } }, 30000);
        carregar().then(function () {
            var q = new URLSearchParams(window.location.search).get('id');
            if (q) abrirDetalhe(+q);
        });
        $('#btn-nova').on('click', function () {
            if (!d.regras.length) { ZTE.toast('avis', 'Crie uma regra antes.'); return; }
            abrirForm(null);
        });
        $('#c-olt').on('change', function () { $('#c-pons').data('marcadas', []); ponsDaOlt(); onusEscolhidas = {}; listarOnus(); });
        $('#c-onu-busca').on('input', listarOnus);
        $('#c-onus').on('change', function () {
            // So as opcoes visiveis mudam; as escolhidas escondidas pelo filtro ficam.
            $(this).find('option').each(function () {
                if (this.selected) onusEscolhidas[this.value] = true; else delete onusEscolhidas[this.value];
            });
            listarOnus();
        });
        $('#btn-salvar').on('click', salvar);
        $('#c-tipo').on('change', tipoForm);
        $('#f-arquivadas').on('change', carregar);
        $('#zte-linhas').on('click', 'tr[data-id]', function () { abrirDetalhe(+$(this).attr('data-id')); });
    });
})();
</script>
</body>
</html>
