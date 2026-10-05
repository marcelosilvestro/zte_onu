<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'inventario';
$zte_perm_pagina = 'ver';
include('nav/header.php');
$zte_pode_ler = $zte_schema_ok && Permissao::tem('olt.configurar');
$zte_pode_avulso = $zte_schema_ok && Permissao::tem('onu.atualizar_avulso');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <?php if ($zte_pode_ler): ?>
    <div class="lc-filter-bar" id="barra-atualizar">
        <div class="lc-filter-group">
            <span class="lc-filter-label">Atualizar inventário da OLT</span>
            <select class="lc-input-date w200" id="a-olt"></select>
        </div>
        <label class="zte-chk" title="Relê nome do cliente e modelo de todas as ONUs (mais lento)"><input type="checkbox" id="a-completo"> Leitura completa</label>
        <button type="button" class="lc-btn-black" id="btn-atualizar"><i class="bi bi-arrow-repeat"></i> Atualizar</button>
        <button type="button" class="lc-btn-outline" id="btn-descobrir" title="Refaz a lista de PONs da OLT"><i class="bi bi-search"></i> Redescobrir PONs</button>
        <div style="flex:1;min-width:200px">
            <div class="zte-progresso" id="a-barra-box" style="display:none"><div id="a-barra"></div></div>
            <div class="zte-sub" id="a-status"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="lc-filter-bar">
        <div class="lc-filter-group"><span class="lc-filter-label">Buscar</span>
            <input type="text" class="lc-input-date w200" id="f-busca" placeholder="nome, login ou SN"></div>
        <div class="lc-filter-group"><span class="lc-filter-label">OLT</span><select class="lc-input-date" id="f-olt"><option value="">Todas</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">PON</span><select class="lc-input-date" id="f-pon"><option value="">Todas</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Estado</span><select class="lc-input-date" id="f-estado">
            <option value="">Todos</option><option value="online">Online</option><option value="offline">Offline</option><option value="desconhecido">Desconhecido</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Modelo</span><select class="lc-input-date" id="f-modelo"><option value="">Todos</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">HW</span><select class="lc-input-date" id="f-hw"><option value="">Todos</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Versão em uso</span><select class="lc-input-date" id="f-sw"><option value="">Todas</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Fabricante</span><select class="lc-input-date" id="f-fornecedor"><option value="">Todos</option></select></div>
        <div class="lc-filter-group"><span class="lc-filter-label">Posições</span><select class="lc-input-date" id="f-ausentes">
            <option value="">Presentes na OLT</option><option value="1">Sumiram da OLT</option><option value="todas">Todas</option></select></div>
        <button type="button" class="lc-btn-black" id="btn-filtrar"><i class="bi bi-funnel"></i> Filtrar</button>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-router"></i> ONUs <span class="lc-badge-count" id="zte-total">0</span></span>
            <span class="zte-sub">"Desatualizada" = há firmware disponível e compatível mais novo que a versão em uso.</span>
        </div>
        <div class="lc-table-wrap">
            <table class="lc-table">
                <thead><tr>
                    <th>Posição</th><th>Nome na OLT / cliente</th><th class="prio-7">SN</th><th class="prio-6">Modelo · HW · versão</th>
                    <th>Estado</th><th class="prio-8">Visto em</th><th style="width:1%"></th>
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

<div class="lc-overlay" id="modal-onu" onclick="ZTE.fecharSeFora(event, 'modal-onu')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="onu-titulo">ONU</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-onu')">&times;</button>
        </div>
        <div class="lc-modal-body" id="onu-corpo"></div>
        <div class="lc-modal-footer">
            <?php if ($zte_pode_ler): ?>
            <button type="button" class="lc-btn-outline" id="btn-ler-onu"><i class="bi bi-arrow-repeat"></i> Ler agora na OLT</button>
            <?php endif; ?>
            <?php if ($zte_pode_avulso): ?>
            <button type="button" class="lc-btn-black" id="btn-avulsa-onu" style="display:none"><i class="bi bi-cloud-arrow-up"></i> Atualizar agora</button>
            <?php endif; ?>
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-onu')">Fechar</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var linhas = [], pagina = 1, opcoes = null, oltsAtualizar = [], onuAtual = null, rodando = false;
    var podeAvulso = <?= $zte_pode_avulso ? 'true' : 'false' ?>;
    // Filtro vindo da topologia (inventario.php?olt=1&pon=1/1): vale ate as opcoes chegarem.
    var url = new URLSearchParams(window.location.search);
    var inicial = { olt_id: url.get('olt') || '', pon: url.get('pon') || '' };

    function estado(o) {
        var b = ZTE.badge(o.estado);
        return b + (o.fase && o.fase !== 'working' ? '<div class="zte-sub">' + ZTE.esc(o.fase) + '</div>' : '') +
               (o.ausente_desde ? '<div class="zte-sub">sumiu em ' + ZTE.dataHora(o.ausente_desde) + '</div>' : '');
    }

    function nome(o) {
        var c = o.cliente;
        return '<strong>' + ZTE.esc(o.nome || '—') + '</strong>' +
            (c ? '<div class="zte-sub">' + ZTE.esc(c.nome) + (c.ativo ? '' : ' (inativo)') + '</div>'
               : (o.login_cliente ? '<div class="zte-sub">login não encontrado no MK-AUTH</div>' : ''));
    }

    function modelo(o) {
        if (!o.atualizavel) {
            // Outro fabricante: modelo/versao lidos so para consulta.
            return (o.modelo ? ZTE.esc(o.modelo) + ' <span class="zte-sub">· ' + ZTE.esc(o.hw_versao || '?') + '</span>'
                             : '<span class="zte-sub">' + ZTE.esc(o.fornecedor || '?') + '</span>') +
                '<div>' + (o.sw_versao ? '<span class="zte-mono">' + ZTE.esc(o.sw_versao) + '</span> ' : '') +
                '<span class="zte-res nao_testavel" title="Fabricante ' + ZTE.esc(o.fornecedor || '?') + ': lido para consulta, nunca atualizado">' +
                ZTE.esc(o.fornecedor || '?') + ' · só leitura</span></div>';
        }
        var m = o.modelo ? ZTE.esc(o.modelo) + ' <span class="zte-sub">· ' + ZTE.esc(o.hw_versao || '?') + '</span>' : '<span class="zte-sub">modelo não lido</span>';
        return m + '<div><span class="zte-mono">' + ZTE.esc(o.sw_versao || '—') + '</span>' + versaoSelo(o) + '</div>';
    }

    function versaoSelo(o) {
        if (o.desatualizada === true) return ' ' + ZTE.badge('desatualizada') + ' <span class="zte-sub">→ ' + ZTE.esc(o.versao_alvo) + '</span>';
        if (o.desatualizada === false) return ' ' + ZTE.badge('em_dia');
        return '';
    }

    // Botao "Atualizar agora": so ONU online, desatualizada e presente na OLT (o servidor confere tudo de novo).
    function avulsavel(o) {
        return podeAvulso && o.desatualizada === true && o.estado === 'online' && !o.ausente_desde;
    }

    function atualizarAvulsa(o) {
        ZTE.loading('Conferindo a ONU...');
        ZTE.api('inventario.avulsa_previa', { id: o.id }).then(function (p) {
            ZTE.fimLoading();
            if (p.bloqueios > 0) {
                ZTE.toast('erro', 'Não é possível atualizar: ' + p.verificacoes.filter(function (v) { return v.bloqueia; })
                    .map(function (v) { return v.titulo + ' — ' + v.detalhe; }).join(' | '));
                return;
            }
            return ZTE.confirmar({
                titulo: 'Atualizar ONU agora', perigo: p.precisa_ciencia, textoOk: 'Atualizar agora', digitar: p.precisa_ciencia ? 'CIENTE' : null,
                msg: 'Atualizar ' + (p.onu.nome || p.onu.sn) + ' (' + p.onu.posicao + ') de ' + p.onu.sw_versao + ' para ' + p.firmware.versao + '?',
                sub: 'O cliente fica sem internet por 1 a 2 minutos quando a ONU reiniciar. A ONU é relida na OLT antes de liberar; o worker começa no próximo minuto e o andamento aparece na Fila.' +
                     (p.precisa_ciencia ? ' Atenção: o acesso desta OLT ao FTP ainda não foi comprovado.' : '')
            }).then(function (ok) {
                if (!ok) return;
                ZTE.loading('Relendo a ONU na OLT e liberando...');
                return ZTE.api('inventario.avulsa', { id: o.id, ciencia: p.precisa_ciencia ? 1 : 0 }, 'POST').then(function (c) {
                    ZTE.fimLoading();
                    ZTE.toast('ok', 'Atualização liberada. Abrindo a Fila...');
                    setTimeout(function () { window.location.href = 'fila.php?campanha=' + c.id; }, 800);
                });
            });
        }).catch(function (e) { ZTE.fimLoading(); ZTE.erro(e); carregar(false); });
    }

    function render(d) {
        linhas = d.linhas;
        $('#zte-total').text(d.total);
        $('#zte-linhas').html(linhas.length ? linhas.map(function (o, i) {
            return '<tr data-i="' + i + '" style="cursor:pointer' + (o.ausente_desde ? ';opacity:.6' : '') + '">' +
                '<td style="white-space:nowrap"><span class="zte-mono">' + ZTE.esc(o.posicao) + '</span><div class="zte-sub">' + ZTE.esc(o.olt_nome) + '</div></td>' +
                '<td>' + nome(o) + '</td><td class="prio-7 zte-mono">' + ZTE.esc(o.sn || '—') + '</td>' +
                '<td class="prio-6">' + modelo(o) + '</td><td>' + estado(o) + '</td>' +
                '<td class="prio-8 zte-sub" style="white-space:nowrap">' + ZTE.dataHora(o.visto_em) + '</td>' +
                '<td>' + (avulsavel(o) ? '<button type="button" class="lc-btn-outline" data-avulsa="' + i + '" title="Atualizar agora (técnico no local)"><i class="bi bi-cloud-arrow-up"></i></button>' : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="7" class="lc-empty">Nenhuma ONU encontrada.' + (pagina === 1 ? ' Atualize o inventário de uma OLT.' : '') + '</td></tr>');
        ZTE.paginacao($('#zte-pag'), d.pagina, d.por_pagina, d.total, function (p) { pagina = p; carregar(false); });
        if (d.opcoes) preencherOpcoes(d.opcoes);
    }

    function opc($s, lista, rot) {
        var atual = $s.val();
        $s.find('option:not(:first)').remove();
        lista.forEach(function (v) { $s.append($('<option>').val(v.v !== undefined ? v.v : v).text(rot ? rot(v) : v)); });
        $s.val(atual);
    }
    function preencherOpcoes(o) {
        opcoes = o;
        opc($('#f-olt'), o.olts.map(function (x) { return { v: x.id, t: x.nome }; }), function (x) { return x.t; });
        var pons = {};
        o.pons.forEach(function (p) { pons[p.slot + '/' + p.porta] = 1; });
        opc($('#f-pon'), Object.keys(pons));
        opc($('#f-modelo'), o.modelos); opc($('#f-hw'), o.hws); opc($('#f-fornecedor'), o.fornecedores);
        opc($('#f-sw'), o.versoes.map(function (v) { return { v: v, t: v }; }).concat([{ v: '__nao_lida__', t: 'Não lida' }]),
            function (x) { return x.t; });
        if (inicial) { $('#f-olt').val(inicial.olt_id); $('#f-pon').val(inicial.pon); inicial = null; }
    }

    function filtros() {
        return { busca: $('#f-busca').val(), olt_id: inicial ? inicial.olt_id : $('#f-olt').val(), pon: inicial ? inicial.pon : $('#f-pon').val(), estado: $('#f-estado').val(),
                 modelo: $('#f-modelo').val(), hw: $('#f-hw').val(), sw: $('#f-sw').val(), fornecedor: $('#f-fornecedor').val(), ausentes: $('#f-ausentes').val() };
    }
    function carregar(comOpcoes) {
        return ZTE.api('inventario.listar', $.extend(filtros(), { pagina: pagina, com_opcoes: comOpcoes ? 1 : 0 })).then(render).catch(ZTE.erro);
    }

    // ------------------------------------------------------------ detalhe
    function abrirOnu(id) {
        ZTE.api('inventario.onu', { id: id }).then(function (o) {
            onuAtual = o;
            $('#onu-titulo').text((o.nome || 'ONU') + ' — ' + o.olt_nome + ' ' + o.posicao);
            var fws = o.firmwares_compativeis.length
                ? o.firmwares_compativeis.map(function (f) { return ZTE.esc(f.modelo_familia + ' ' + f.versao_firmware) + ' ' + ZTE.badge(f.estado); }).join('<br>')
                : (o.atualizavel ? '<span class="zte-sub">nenhum firmware cadastrado para ' + ZTE.esc((o.modelo || '?') + ' · ' + (o.hw_versao || '?')) + '</span>'
                                 : '<span class="zte-sub">fabricante fora do escopo de atualização</span>');
            var c = o.cliente;
            var ln = function (r, v) { return '<tr><td style="width:180px"><strong>' + r + '</strong></td><td class="zte-quebra">' + v + '</td></tr>'; };
            $('#onu-corpo').html('<table class="lc-table"><tbody>' +
                ln('Estado', estado(o)) +
                ln('Nome na OLT', ZTE.esc(o.nome || '—')) +
                ln('Cliente no MK-AUTH', c ? ZTE.esc(c.nome) + (c.ativo ? '' : ' <span class="zte-sub">(inativo)</span>') : '<span class="zte-sub">não encontrado</span>') +
                ln('Descrição', ZTE.esc(o.descricao || '—')) +
                ln('SN', '<span class="zte-mono">' + ZTE.esc(o.sn || '—') + '</span> <span class="zte-sub">' + ZTE.esc(o.fornecedor || '') + '</span>') +
                ln('Perfil na OLT', ZTE.esc(o.tipo_perfil || '—')) +
                ln('Modelo · HW', ZTE.esc((o.modelo || '—') + ' · ' + (o.hw_versao || '—'))) +
                ln('Versão em uso', o.sw_versao ? '<span class="zte-mono">' + ZTE.esc(o.sw_versao) + '</span>' + versaoSelo(o)
                                                : '<span class="zte-sub">' + (o.estado === 'online' ? 'ainda não lida' : 'não lida (ONU offline)') + '</span>') +
                ln('Outro banco', o.sw_standby ? '<span class="zte-mono">' + ZTE.esc(o.sw_standby) + '</span> <span class="zte-sub">imagem anterior, inativa</span>' : '—') +
                ln('Versão lida em', ZTE.dataHora(o.sw_lido_em)) +
                ln('Firmwares compatíveis', fws) +
                ln('Visto em', ZTE.dataHora(o.visto_em)) +
                ln('Detalhe lido em', ZTE.dataHora(o.detalhe_em)) +
                '</tbody></table>');
            $('#btn-avulsa-onu').toggle(avulsavel(o));
            ZTE.abrirModal('modal-onu');
        }).catch(ZTE.erro);
    }

    // ------------------------------------------------------------ atualizacao PON a PON
    function status(t) { $('#a-status').text(t); }
    function barra(p) { $('#a-barra-box').show(); $('#a-barra').css('width', p + '%'); }

    function carregarOlts() {
        return ZTE.api('inventario.olts').then(function (d) {
            oltsAtualizar = d.olts;
            $('#a-olt').html(d.olts.length ? d.olts.map(function (o) {
                return '<option value="' + o.id + '">' + ZTE.esc(o.nome) + (o.inventario_em ? ' (lido ' + ZTE.dataHora(o.inventario_em) + ')' : ' (nunca lido)') + '</option>';
            }).join('') : '<option value="">Nenhuma OLT ativa</option>');
        });
    }

    function atualizar(redescobrir) {
        if (rodando) return;
        var o = oltsAtualizar.filter(function (x) { return String(x.id) === $('#a-olt').val(); })[0];
        if (!o) return;
        rodando = true;
        $('#btn-atualizar, #btn-descobrir').prop('disabled', true);
        var completo = $('#a-completo').is(':checked') ? 1 : 0;
        var resumo = [];
        var passo = (redescobrir || !o.pons_detectadas.length)
            ? (status('Descobrindo as PONs de ' + o.nome + '...'), barra(2), ZTE.api('inventario.descobrir', { olt_id: o.id }, 'POST').then(function (d) { return d.pons; }))
            : Promise.resolve(o.pons_detectadas);

        passo.then(function (pons) {
            var i = 0;
            function proxima() {
                if (i >= pons.length) {
                    status('Finalizando...');
                    return ZTE.api('inventario.finalizar', { olt_id: o.id, resumo: resumo }, 'POST').then(function (f) {
                        barra(100);
                        status(o.nome + ': ' + f.total + ' ONUs, ' + f.online + ' online, ' + f.offline + ' offline.');
                        ZTE.toast('ok', 'Inventário de ' + o.nome + ' atualizado.');
                    });
                }
                var p = pons[i];
                status('Lendo PON ' + p.slot + '/' + p.pon + ' (' + (i + 1) + ' de ' + pons.length + ')...');
                return ZTE.api('inventario.ler_pon', { olt_id: o.id, slot: p.slot, pon: p.pon, completo: completo }, 'POST')
                    .then(function (r) {
                        resumo.push({ slot: r.slot, pon: r.pon, total: r.total, novas: r.novas });
                        if (r.aviso) ZTE.toast('avis', 'PON ' + r.slot + '/' + r.pon + ': ' + r.aviso);
                    })
                    .catch(function (e) {
                        // Uma PON com formato desconhecido nao derruba o inventario inteiro.
                        if (e.codigo === 'ZTE-OLT-009') { ZTE.toast('avis', 'PON ' + p.slot + '/' + p.pon + ': ' + e.mensagem); return; }
                        throw e;
                    })
                    .then(function () { i++; barra(Math.round(i * 95 / pons.length) + 3); return proxima(); });
            }
            return proxima();
        }).catch(function (e) { ZTE.erro(e); status('Interrompido: ' + e.mensagem); })
          .finally(function () {
              rodando = false;
              $('#btn-atualizar, #btn-descobrir').prop('disabled', false);
              carregarOlts();
              carregar(true);
          });
    }

    $(function () {
        carregar(true);
        if ($('#barra-atualizar').length) carregarOlts().catch(ZTE.erro);
        $('#btn-filtrar').on('click', function () { pagina = 1; carregar(false); });
        $('#f-busca').on('keydown', function (e) { if (e.key === 'Enter') { pagina = 1; carregar(false); } });
        $('#zte-linhas').on('click', 'tr[data-i]', function () { abrirOnu(linhas[+$(this).attr('data-i')].id); });
        $('#zte-linhas').on('click', 'button[data-avulsa]', function (ev) { ev.stopPropagation(); atualizarAvulsa(linhas[+$(this).attr('data-avulsa')]); });
        $('#btn-avulsa-onu').on('click', function () { if (onuAtual) { ZTE.fecharModal('modal-onu'); atualizarAvulsa(onuAtual); } });
        $('#btn-atualizar').on('click', function () { atualizar(false); });
        $('#btn-descobrir').on('click', function () { atualizar(true); });
        $('#btn-ler-onu').on('click', function () {
            if (!onuAtual) return;
            ZTE.loading('Lendo a ONU na OLT...');
            ZTE.api('inventario.ler_onu', { id: onuAtual.id }, 'POST')
                .then(function () { ZTE.fecharModal('modal-onu'); abrirOnu(onuAtual.id); carregar(false); })
                .catch(ZTE.erro).finally(ZTE.fimLoading);
        });
    });
})();
</script>
</body>
</html>
