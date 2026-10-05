<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'topologia';
$zte_perm_pagina = 'ver';
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>
    <div class="lc-filter-bar" style="align-items:center" id="topo-filtros">
        <div class="zte-chips">
            <button type="button" class="zte-chip ativo" data-f="todas">Todas <span class="zte-chip-n" id="n-todas">0</span></button>
            <button type="button" class="zte-chip" data-f="problemas" title="PONs em atenção ou críticas (ONUs offline acima do limiar, ONUs que sumiram ou sem modelo lido)">
                <i class="bi bi-exclamation-triangle-fill"></i> Com problemas <span class="zte-chip-n" id="n-problemas">0</span></button>
            <button type="button" class="zte-chip" data-f="offline" title="PONs com pelo menos uma ONU offline">
                <i class="bi bi-x-circle-fill"></i> Com offline <span class="zte-chip-n" id="n-offline">0</span></button>
            <button type="button" class="zte-chip" data-f="firmware" title="PONs com pelo menos uma ONU de firmware desatualizado">
                <i class="bi bi-cpu"></i> Firmware <span class="zte-chip-n" id="n-firmware">0</span></button>
            <div class="zte-busca">
                <i class="bi bi-search"></i>
                <input type="search" class="lc-input-date w200" id="topo-busca" placeholder="Buscar PON, slot, placa, modelo..." autocomplete="off">
            </div>
        </div>
        <div class="zte-dir">
            <span class="zte-sub" id="topo-lido"></span>
            <button type="button" class="lc-btn-outline" id="topo-recarregar" title="Relê os números do banco (não acessa a OLT)"><i class="bi bi-arrow-clockwise"></i> Recarregar</button>
            <a class="lc-btn-outline" href="inventario.php" title="A leitura da OLT é feita na aba Inventário"><i class="bi bi-arrow-repeat"></i> Atualizar inventário</a>
        </div>
    </div>
    <div id="zte-topo"><div class="lc-loading">Carregando...</div></div>
<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-pon" onclick="ZTE.fecharSeFora(event, 'modal-pon')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="pd-titulo">PON</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-pon')">&times;</button>
        </div>
        <div class="lc-modal-body" id="pd-corpo"></div>
        <div class="lc-modal-footer">
            <a class="lc-btn-black" id="pd-inventario" href="inventario.php"><i class="bi bi-list-ul"></i> Ver ONUs no Inventário</a>
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-pon')">Fechar</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    // Estado da PON = so conectividade (firmware tem barra e filtro proprios).
    // Critico: >= 25% offline com pelo menos 3 ONUs, ou todas offline.
    // Atencao: >= 10% offline, ONU que sumiu da OLT ou ONU ZTE online sem modelo lido.
    var LIMIAR = { critico_pct: 0.25, critico_min: 3, atencao_pct: 0.10 };
    var ESTADOS = {
        ok:      { rotulo: 'Normal',  icone: 'bi-check-circle-fill',        cls: 'zte-ok',   cor: '#1d9e75' },
        atencao: { rotulo: 'Atenção', icone: 'bi-exclamation-triangle-fill', cls: 'zte-aten', cor: '#e0a100' },
        critico: { rotulo: 'Crítica', icone: 'bi-x-octagon-fill',           cls: 'zte-crit', cor: '#e74c3c' }
    };
    var dados = null, pons = {}, filtro = 'todas', fechados = {};
    var esc = ZTE.esc;

    function fmtPct(x) { return (x * 100).toFixed(1).replace('.', ',') + '%'; }
    function largura(n, d) { return d ? (n * 100 / d).toFixed(2) + '%' : '0'; }
    function soma(lista, f) { return lista.reduce(function (a, x) { return a + f(x); }, 0); }

    /** Calcula uma vez o que cards, filtros, busca e modal usam. */
    function preparar(o, p, placa) {
        p.k = o.id + '/' + p.slot + '/' + p.pon;
        p.olt = o;
        p.placa = placa;
        p.presentes = p.total - p.ausentes;
        p.pct_on = p.presentes ? p.online / p.presentes : 0;
        var off = p.presentes ? p.offline / p.presentes : 0, motivos = [];
        p.estado = 'ok';
        if (p.presentes && (p.online === 0 || (off >= LIMIAR.critico_pct && p.offline >= LIMIAR.critico_min))) {
            p.estado = 'critico';
            motivos.push(p.online === 0 ? 'nenhuma ONU online' : fmtPct(off) + ' das ONUs offline');
        } else if (off >= LIMIAR.atencao_pct) {
            p.estado = 'atencao';
            motivos.push(fmtPct(off) + ' das ONUs offline');
        }
        if (p.ausentes) { motivos.push(p.ausentes + ' ONU(s) sumiram da OLT'); if (p.estado === 'ok') p.estado = 'atencao'; }
        if (p.sem_modelo) { motivos.push(p.sem_modelo + ' ONU(s) ZTE sem modelo lido'); if (p.estado === 'ok') p.estado = 'atencao'; }
        p.motivos = motivos;
        p.busca = ['pon ' + p.slot + '/' + p.pon, 'slot ' + p.slot, placa, o.nome]
            .concat(p.modelos.concat(p.modelos_outros || []).map(function (m) { return m.modelo + ' ' + m.hw; })).join(' ').toLowerCase();
        pons[p.k] = p;
    }

    function anel(p) {
        var c = 2 * Math.PI * 16, e = ESTADOS[p.estado];
        var txt = p.presentes ? (p.pct_on < 1 ? Math.floor(p.pct_on * 100) : 100) + '%' : '—';
        return '<svg class="zte-anel" width="40" height="40" viewBox="0 0 40 40" aria-hidden="true">' +
            '<circle cx="20" cy="20" r="16" fill="none" stroke="#eef0f4" stroke-width="4"/>' +
            '<circle cx="20" cy="20" r="16" fill="none" stroke="' + e.cor + '" stroke-width="4" stroke-linecap="round" transform="rotate(-90 20 20)"' +
            ' stroke-dasharray="' + (p.pct_on * c).toFixed(2) + ' ' + c.toFixed(2) + '"/>' +
            '<text x="20" y="20" text-anchor="middle" dominant-baseline="central">' + txt + '</text></svg>';
    }

    function donut(seg, total) {
        var r = 24, c = 2 * Math.PI * r, ini = 0;
        var arcos = seg.filter(function (s) { return s.n > 0; }).map(function (s) {
            var l = total ? s.n / total * c : 0;
            var h = '<circle cx="32" cy="32" r="' + r + '" fill="none" stroke="' + s.cor + '" stroke-width="8" transform="rotate(-90 32 32)"' +
                ' stroke-dasharray="' + l.toFixed(2) + ' ' + c.toFixed(2) + '" stroke-dashoffset="' + (-ini).toFixed(2) + '">' +
                '<title>' + s.rotulo + ': ' + s.n + ' ONU(s) · ' + fmtPct(s.n / total) + '</title></circle>';
            ini += l;
            return h;
        }).join('');
        return '<svg width="64" height="64" viewBox="0 0 64 64" role="img" aria-label="ONUs por estado">' +
            '<circle cx="32" cy="32" r="' + r + '" fill="none" stroke="#eef0f4" stroke-width="8"/>' + arcos +
            '<text x="32" y="30" text-anchor="middle" style="font-size:13px;font-weight:800;fill:#111827">' + total + '</text>' +
            '<text x="32" y="42" text-anchor="middle" style="font-size:8px;fill:#6b7280">ONUs</text></svg>';
    }

    function pilha(fw) {
        var t = fw.em_dia + fw.desatualizadas + fw.sem_referencia;
        return '<div class="zte-pilha"><span class="on" style="width:' + largura(fw.em_dia, t) + '"></span>' +
            '<span class="des" style="width:' + largura(fw.desatualizadas, t) + '"></span>' +
            '<span class="sem" style="width:' + largura(fw.sem_referencia, t) + '"></span></div>';
    }
    function fwDica(fw) {
        return fw.em_dia + ' em dia · ' + fw.desatualizadas + ' desatualizada(s) · ' + fw.sem_referencia + ' sem referência (versão não lida ou sem firmware cadastrado para o modelo)';
    }

    function card(ico, icone, rotulo, valor, sub, barra) {
        return '<div class="zte-card"><div class="zte-card-ico ' + ico + '"><i class="bi ' + icone + '"></i></div>' +
            '<div class="zte-card-txt"><div class="zte-card-rotulo">' + rotulo + '</div><div class="zte-card-valor">' + valor + '</div>' +
            '<div class="zte-card-sub">' + sub + '</div>' + (barra || '') + '</div></div>';
    }

    function cardPon(p) {
        var e = ESTADOS[p.estado], fw = p.firmware, tfw = fw.em_dia + fw.desatualizadas + fw.sem_referencia;
        var dica = 'Estado: ' + e.rotulo + (p.motivos.length ? ' — ' + p.motivos.join('; ') : '');
        return '<div class="zte-pc ' + p.estado + '" tabindex="0" role="button" data-k="' + p.k + '" title="' + esc(dica) + '">' +
            '<i class="bi bi-chevron-right zte-pc-seta"></i>' +
            '<div class="zte-pc-topo"><div class="zte-pc-id"><div class="zte-pc-nome"><i class="bi ' + e.icone + ' ' + e.cls + '"></i>PON ' + p.slot + '/' + p.pon + '</div>' +
            '<div class="zte-sub">' + p.presentes + ' ONU(s)</div></div>' + anel(p) + '</div>' +
            '<div class="zte-pc-num"><span><span class="zte-pt on"></span><b>' + p.online + '</b> online</span>' +
            '<span><span class="zte-pt off"></span><b>' + p.offline + '</b> offline</span>' +
            (p.ausentes ? '<span class="zte-aten"><i class="bi bi-question-circle"></i> <b class="zte-aten">' + p.ausentes + '</b> sumiram</span>' : '') + '</div>' +
            (tfw ? '<div class="zte-pc-fw" title="' + esc(fwDica(fw)) + '"><span><i class="bi bi-cpu"></i> Firmware</span>' +
                   '<span><b class="zte-ok">' + fw.em_dia + '</b> / <b class="zte-aten">' + fw.desatualizadas + '</b>' +
                   (fw.sem_referencia ? ' / <b>' + fw.sem_referencia + '</b>' : '') + '</span></div>' + pilha(fw)
                 : '<div class="zte-pc-fw"><span><i class="bi bi-cpu"></i> Sem ONU ZTE</span></div>') +
            '</div>';
    }

    function idade(iso) {
        var d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d)) return null;
        return (Date.now() - d.getTime()) / 3600000;
    }

    function cabecalho(o) {
        var inv, h = o.inventario_em ? idade(o.inventario_em) : null;
        if (!o.inventario_em) {
            inv = '<span class="zte-res nao_testavel">Inventário nunca lido</span>';
        } else {
            inv = '<span class="zte-sub" title="Os números vêm do último inventário gravado, não de uma leitura ao vivo">inventário ' + ZTE.dataHora(o.inventario_em) +
                (h !== null && h >= 1 ? ' (há ' + (h < 48 ? Math.floor(h) + ' h' : Math.floor(h / 24) + ' dias') + ')' : '') + '</span>' +
                (h !== null && h > 24 ? ' <span class="zte-res aviso">Inventário antigo</span>' : '');
        }
        var teste = o.teste_resultado
            ? ' <span class="zte-res ' + esc(o.teste_resultado) + '" title="Último teste de conexão: ' + ZTE.dataHora(o.teste_em) + '">Último teste: ' +
              ({ ok: 'OK', aviso: 'Aviso', erro: 'Erro' }[o.teste_resultado] || esc(o.teste_resultado)) + '</span>'
            : ' <span class="zte-res nao_testavel">Conexão não testada</span>';
        return '<div class="lc-section-header"><span class="lc-section-title"><i class="bi bi-hdd-network"></i> ' + esc(o.nome) +
            (o.simulada ? ' ' + ZTE.badge('simulada') : '') + (o.ativo ? '' : ' ' + ZTE.badge('inativa')) +
            ' <span class="zte-sub">' + esc(o.versao || '') + '</span></span><span>' + inv + teste + '</span></div>';
    }

    function resumo(o) {
        var pres = soma(o.pons, function (p) { return p.presentes; }), on = soma(o.pons, function (p) { return p.online; }),
            off = soma(o.pons, function (p) { return p.offline; }), aus = soma(o.pons, function (p) { return p.ausentes; }),
            outros = Math.max(0, pres - on - off),
            fw = { em_dia: soma(o.pons, function (p) { return p.firmware.em_dia; }),
                   desatualizadas: soma(o.pons, function (p) { return p.firmware.desatualizadas; }),
                   sem_referencia: soma(o.pons, function (p) { return p.firmware.sem_referencia; }) },
            tfw = fw.em_dia + fw.desatualizadas + fw.sem_referencia,
            alerta = o.pons.filter(function (p) { return p.estado !== 'ok'; }).length;
        return '<div class="zte-topo-resumo">' +
            '<div class="zte-topo-donut">' + donut([{ n: on, cor: '#1d9e75', rotulo: 'Online' }, { n: off, cor: '#e74c3c', rotulo: 'Offline' },
                                                   { n: outros, cor: '#c4c8cf', rotulo: 'Estado desconhecido' }], pres) +
            '<div class="zte-leg"><div><span class="zte-pt on"></span>Online <b>' + on + '</b> <span class="zte-sub">' + fmtPct(pres ? on / pres : 0) + '</span></div>' +
            '<div><span class="zte-pt off"></span>Offline <b>' + off + '</b> <span class="zte-sub">' + fmtPct(pres ? off / pres : 0) + '</span></div>' +
            (outros ? '<div><span class="zte-pt sem"></span>Desconhecido <b>' + outros + '</b></div>' : '') + '</div></div>' +
            card('', 'bi-router', 'Total de ONUs', pres, aus ? '<span class="zte-aten">' + aus + ' sumiram da OLT</span>' : 'em ' + o.pons.length + ' PON(s)') +
            card('verde', 'bi-check-circle', 'Online', on, fmtPct(pres ? on / pres : 0), '<div class="zte-mini"><span class="on" style="width:' + largura(on, pres) + '"></span></div>') +
            card('vermelho', 'bi-x-circle', 'Offline', off, fmtPct(pres ? off / pres : 0), '<div class="zte-mini"><span class="off" style="width:' + largura(off, pres) + '"></span></div>') +
            card('cinza', 'bi-cpu', 'Firmware desatualizado', fw.desatualizadas,
                 fw.em_dia + ' em dia' + (fw.sem_referencia ? ' · ' + fw.sem_referencia + ' sem referência' : ''), tfw ? pilha(fw) : '') +
            card('', 'bi-diagram-3', 'PONs com ONU', o.pons.length,
                 (alerta ? '<span class="zte-aten"><i class="bi bi-exclamation-triangle-fill"></i> ' + alerta + ' com alerta</span>' : 'nenhuma com alerta') +
                 ' · ' + o.pons_detectadas.length + ' detectada(s)') +
            '</div>';
    }

    function slots(o) {
        var mapa = {};
        o.placas.forEach(function (pl) { mapa[pl.slot] = { placa: pl, pons: [] }; });
        o.pons.forEach(function (p) { (mapa[p.slot] = mapa[p.slot] || { placa: { tipo: '?' }, pons: [] }).pons.push(p); });
        return Object.keys(mapa).sort(function (a, b) { return a - b; }).map(function (s) {
            var sl = mapa[s], k = o.id + '/' + s;
            var vazias = o.pons_detectadas.filter(function (p) {
                return p.slot === +s && !sl.pons.some(function (x) { return x.pon === p.pon; });
            }).length;
            if (!sl.pons.length && !vazias) {
                return '<div class="zte-topo-slot-sem" data-sem-pon="1"><span class="zte-mono">Slot ' + s + '</span> · ' + esc(sl.placa.tipo) + ' <span class="zte-sub">sem PON</span></div>';
            }
            var pres = soma(sl.pons, function (p) { return p.presentes; }), on = soma(sl.pons, function (p) { return p.online; }),
                off = soma(sl.pons, function (p) { return p.offline; });
            return '<div class="zte-topo-slot' + (fechados[k] ? ' fechado' : '') + '" data-slot="' + k + '">' +
                '<div class="zte-topo-slot-cab" tabindex="0" role="button" aria-expanded="' + (fechados[k] ? 'false' : 'true') + '">' +
                '<i class="bi bi-motherboard"></i> Slot ' + s + ' · ' + esc(sl.placa.tipo) +
                ' <span class="lc-badge-count">' + sl.pons.length + ' PON(s)</span>' +
                (vazias ? ' <span class="zte-sub" style="font-weight:500">· ' + vazias + ' PON(s) sem ONU</span>' : '') +
                '<span class="zte-dir"><span>' + pres + ' ONUs</span><span class="zte-ok">' + on + ' online</span>' +
                '<span class="' + (off ? 'zte-crit' : '') + '">' + off + ' offline</span><i class="bi bi-chevron-up"></i></span></div>' +
                '<div class="zte-topo-pons">' + sl.pons.map(cardPon).join('') + '</div></div>';
        }).join('');
    }

    function render() {
        pons = {};
        if (!dados.olts.length) { $('#zte-topo').html('<div class="lc-empty">Nenhuma OLT cadastrada.</div>'); contar(); return; }
        dados.olts.forEach(function (o) {
            var placas = {};
            o.placas.forEach(function (pl) { placas[pl.slot] = pl.tipo; });
            o.pons.forEach(function (p) { preparar(o, p, placas[p.slot] || ''); });
        });
        $('#zte-topo').html(dados.olts.map(function (o) {
            var corpo;
            if (!o.placas.length && !o.pons.length) {
                corpo = '<div class="lc-empty">Nenhuma placa identificada: teste a OLT e atualize o inventário.</div>';
            } else {
                corpo = (o.pons.length ? resumo(o) : '<div class="lc-empty" style="padding:16px">Não foram encontradas ONUs nesta OLT.</div>') +
                    slots(o) + '<div class="lc-empty zte-topo-nada" style="display:none;padding:16px">Nenhuma PON desta OLT no filtro atual.</div>';
            }
            return '<div class="lc-section-panel zte-topo-olt" data-olt="' + o.id + '">' + cabecalho(o) + '<div class="zte-topo-corpo">' + corpo + '</div></div>';
        }).join(''));
        contar();
        aplicar();
    }

    function contar() {
        var l = Object.keys(pons).map(function (k) { return pons[k]; });
        $('#n-todas').text(l.length);
        $('#n-problemas').text(l.filter(function (p) { return p.estado !== 'ok'; }).length);
        $('#n-offline').text(l.filter(function (p) { return p.offline > 0; }).length);
        $('#n-firmware').text(l.filter(function (p) { return p.firmware.desatualizadas > 0; }).length);
    }

    function passa(p, termos) {
        if (filtro === 'problemas' && p.estado === 'ok') return false;
        if (filtro === 'offline' && !p.offline) return false;
        if (filtro === 'firmware' && !p.firmware.desatualizadas) return false;
        return termos.every(function (t) { return p.busca.indexOf(t) !== -1; });
    }

    /** Filtro e busca so mostram/escondem o que ja esta na tela: nada e recriado. */
    function aplicar() {
        var termos = $('#topo-busca').val().toLowerCase().split(/\s+/).filter(Boolean);
        var ativo = filtro !== 'todas' || termos.length > 0;
        $('#zte-topo').toggleClass('filtrando', ativo);
        $('.zte-topo-olt').each(function () {
            var $o = $(this), vistos = 0;
            $o.find('.zte-topo-slot').each(function () {
                var n = 0;
                $(this).find('.zte-pc').each(function () {
                    var ok = passa(pons[$(this).attr('data-k')], termos);
                    $(this).toggle(ok);
                    if (ok) n++;
                });
                $(this).toggle(!ativo || n > 0);
                vistos += n;
            });
            $o.find('[data-sem-pon]').toggle(!ativo);
            $o.find('.zte-topo-nada').toggle(ativo && vistos === 0 && $o.find('.zte-pc').length > 0);
        });
    }

    function detalhe(p) {
        var e = ESTADOS[p.estado], fw = p.firmware, tfw = fw.em_dia + fw.desatualizadas + fw.sem_referencia;
        $('#pd-titulo').text(p.olt.nome + ' — PON ' + p.slot + '/' + p.pon + (p.placa ? ' · ' + p.placa : ''));
        $('#pd-inventario').attr('href', 'inventario.php?olt=' + p.olt.id + '&pon=' + encodeURIComponent(p.slot + '/' + p.pon));
        var modelos = p.modelos.slice().sort(function (a, b) { return b.n - a.n; }).map(function (m) {
            return '<div class="zte-pd-modelo"><div class="zte-pd-modelo-cab"><span>' + esc(m.modelo + (m.hw ? ' · ' + m.hw : '')) + '</span><span>' + m.n + '</span></div>' +
                m.versoes.slice().sort(function (a, b) { return b.n - a.n; }).map(function (v) {
                    var b = v.desatualizada === true ? ZTE.badge('desatualizada') : v.desatualizada === false ? ZTE.badge('em_dia')
                          : '<span class="zte-res nao_testavel">Sem referência</span>';
                    return '<div class="zte-pd-ver"><span class="zte-mono">' + esc(v.sw || 'versão não lida') + '</span><span>' + b + ' <b>' + v.n + '</b></span></div>';
                }).join('') + '</div>';
        }).join('') || '<div class="zte-sub">Nenhuma ONU ZTE nesta PON.</div>';
        var outros = (p.modelos_outros || []).slice().sort(function (a, b) { return b.n - a.n; }).map(function (m) {
            return '<div class="zte-pd-modelo"><div class="zte-pd-modelo-cab"><span>' + esc(m.fornecedor) + ' · ' +
                esc(m.modelo === '?' ? 'modelo não lido' : m.modelo + (m.hw ? ' · ' + m.hw : '')) + '</span><span>' + m.n + '</span></div>' +
                m.versoes.slice().sort(function (a, b) { return b.n - a.n; }).map(function (v) {
                    return '<div class="zte-pd-ver"><span class="zte-mono">' + esc(v.sw || 'versão não lida') + '</span><b>' + v.n + '</b></div>';
                }).join('') + '</div>';
        }).join('');
        var fab = p.fabricantes.map(function (f) {
            return '<div style="margin-bottom:6px"><div class="zte-pd-ver"><span>' + esc(f.fornecedor) + '</span><b>' + f.n + '</b></div>' +
                '<div class="zte-mini"><span class="azul" style="width:' + largura(f.n, p.presentes) + '"></span></div></div>';
        }).join('') || '<div class="zte-sub">—</div>';
        $('#pd-corpo').html(
            '<div style="font-size:13px;margin-bottom:10px"><i class="bi ' + e.icone + ' ' + e.cls + '"></i> <b class="' + e.cls + '">' + e.rotulo + '</b>' +
            (p.motivos.length ? ' <span class="zte-sub">— ' + esc(p.motivos.join('; ')) + '</span>' : '') + '</div>' +
            '<div class="zte-topo-resumo" style="margin-bottom:0">' +
            card('', 'bi-router', 'ONUs', p.presentes, p.ausentes ? '<span class="zte-aten">' + p.ausentes + ' sumiram da OLT</span>' : 'presentes na PON') +
            card('verde', 'bi-check-circle', 'Online', p.online, fmtPct(p.pct_on), '<div class="zte-mini"><span class="on" style="width:' + largura(p.online, p.presentes) + '"></span></div>') +
            card('vermelho', 'bi-x-circle', 'Offline', p.offline, fmtPct(p.presentes ? p.offline / p.presentes : 0), '<div class="zte-mini"><span class="off" style="width:' + largura(p.offline, p.presentes) + '"></span></div>') +
            card('cinza', 'bi-cpu', 'Firmware', fw.em_dia + ' / ' + fw.desatualizadas, 'em dia / desatualizadas' + (fw.sem_referencia ? ' · ' + fw.sem_referencia + ' sem ref.' : ''), tfw ? pilha(fw) : '') +
            '</div>' +
            '<div class="zte-pd-grade"><div><div class="zte-pd-tit">Modelos e versões (ZTE)</div>' + modelos +
            (outros ? '<div class="zte-pd-tit" style="margin-top:10px">Outros fabricantes <span class="zte-res nao_testavel">só leitura</span></div>' + outros : '') +
            '</div>' +
            '<div><div class="zte-pd-tit">Fabricantes</div>' + fab +
            (p.sem_modelo ? '<div class="zte-acao" style="margin-top:8px"><i class="bi bi-info-circle"></i> ' + p.sem_modelo + ' ONU(s) ZTE online sem modelo lido</div>' : '') +
            '</div></div>');
        ZTE.abrirModal('modal-pon');
    }

    function carregar() {
        var $b = $('#topo-recarregar').prop('disabled', true);
        return ZTE.api('inventario.topologia').then(function (d) {
            dados = d;
            render();
            var a = new Date();
            $('#topo-lido').text('lido às ' + ('0' + a.getHours()).slice(-2) + ':' + ('0' + a.getMinutes()).slice(-2));
        }).catch(function (e) {
            if (dados) { ZTE.erro(e); return; }
            $('#zte-topo').html('<div class="lc-empty"><i class="bi bi-exclamation-octagon" style="font-size:22px;color:#c0392b"></i><br>' +
                'Não foi possível carregar os dados da OLT.<br><span class="zte-sub">' + esc(e && e.mensagem) + '</span><br>' +
                '<button type="button" class="lc-btn-outline" id="topo-tentar" style="margin-top:10px"><i class="bi bi-arrow-clockwise"></i> Tentar novamente</button></div>');
        }).then(function () { $b.prop('disabled', false); });
    }

    $(function () {
        if (!$('#zte-topo').length) return;
        $('#topo-filtros').on('click', '.zte-chip', function () {
            filtro = $(this).attr('data-f');
            $('#topo-filtros .zte-chip').removeClass('ativo');
            $(this).addClass('ativo');
            aplicar();
        });
        $('#topo-busca').on('input', aplicar);
        $('#topo-recarregar').on('click', carregar);
        $('#zte-topo').on('click', '#topo-tentar', function () {
            $('#zte-topo').html('<div class="lc-loading">Carregando...</div>');
            carregar();
        });
        $('#zte-topo').on('click', '.zte-pc', function () { detalhe(pons[$(this).attr('data-k')]); });
        $('#zte-topo').on('click', '.zte-topo-slot-cab', function () {
            var $s = $(this).closest('.zte-topo-slot'), k = $s.attr('data-slot');
            fechados[k] = !fechados[k];
            $s.toggleClass('fechado', fechados[k]);
            $(this).attr('aria-expanded', fechados[k] ? 'false' : 'true');
        });
        $('#zte-topo').on('keydown', '.zte-pc, .zte-topo-slot-cab', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); $(this).trigger('click'); }
        });
        carregar();
    });
})();
</script>
</body>
</html>
