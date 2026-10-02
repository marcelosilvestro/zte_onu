<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'painel';
// O painel abre para qualquer usuario do MK-AUTH: e nele que o primeiro administrador se
// apresenta. Os dados vem de inicio.estado, que so devolve contagens.
$zte_perm_pagina = 'logado';
include('nav/header.php');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div id="zte-sem-admin" class="zte-aviso info" style="display:none">
        <i class="bi bi-person-badge"></i>
        <div style="flex:1">
            <strong>Este addon ainda não tem administrador.</strong>
            O administrador concede as permissões dos outros usuários e é o único que pode desligar o modo seguro.
            Assuma a administração para começar a configurar.
        </div>
        <button type="button" class="lc-btn-black" id="btn-assumir"><i class="bi bi-shield-check"></i> Assumir administração</button>
    </div>

    <div id="zte-sem-acesso" class="zte-aviso" style="display:none">
        <i class="bi bi-shield-lock"></i>
        <div><strong>Você ainda não tem acesso a este addon.</strong>
            Peça ao administrador para liberar o seu login em Configurações › Permissões.</div>
    </div>

    <div id="zte-modo-seguro" class="zte-aviso" style="display:none">
        <i class="bi bi-shield-fill-check"></i>
        <div><strong>Modo seguro ligado.</strong> Atualizações em massa estão bloqueadas; só é possível atualizar uma ONU por vez, para teste.</div>
    </div>

    <div class="zte-cards" id="zte-cards"></div>

    <div class="zte-duas" id="zte-graficos" style="display:none">
        <div>
            <div class="lc-section-panel" style="height:100%">
                <div class="lc-section-header">
                    <span class="lc-section-title"><i class="bi bi-bar-chart"></i> ONUs ZTE por modelo, hardware e versão</span>
                    <span class="zte-sub" id="g-outros"></span>
                </div>
                <div class="zte-graf" id="g-modelos"></div>
                <div class="zte-sub" style="padding:0 16px 10px">"↑" marca as versões abaixo do firmware disponível para aquele modelo e hardware.</div>
            </div>
        </div>
        <div>
            <div class="lc-section-panel" style="height:100%">
                <div class="lc-section-header">
                    <span class="lc-section-title"><i class="bi bi-diagram-3"></i> ONUs por PON</span>
                    <a class="zte-sub" href="topologia.php">ver topologia</a>
                </div>
                <div class="zte-graf" id="g-pons"></div>
            </div>
        </div>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-list-check"></i> Configuração inicial</span>
            <span class="lc-badge-count" id="zte-passos-conta">–</span>
        </div>
        <ul class="zte-passos" id="zte-passos"><li class="lc-loading">Carregando...</li></ul>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    function card(ico, cor, rotulo, valor, sub) {
        return '<div class="zte-card"><div class="zte-card-ico ' + cor + '"><i class="bi ' + ico + '"></i></div>' +
               '<div class="zte-card-txt"><div class="zte-card-rotulo">' + ZTE.esc(rotulo) + '</div>' +
               '<div class="zte-card-valor">' + ZTE.esc(valor) + '</div>' +
               (sub ? '<div class="zte-card-sub">' + ZTE.esc(sub) + '</div>' : '') + '</div></div>';
    }

    function render(d) {
        $('#zte-sem-admin').toggle(!d.ha_admin);
        $('#zte-sem-acesso').toggle(d.ha_admin && !d.pode_ver);
        $('#zte-modo-seguro').toggle(d.modo_seguro && d.pode_ver);

        var c = d.contagens;
        if (d.pode_ver) {
            $('#zte-cards').html(
                card('bi-hdd-network', '', 'OLTs', c.olts, c.olts_testadas + ' testada(s)') +
                card('bi-router', 'verde', 'ONUs online', c.onus_online, 'de ' + c.onus + ' no inventário') +
                card('bi-arrow-up-circle', c.desatualizadas ? 'vermelho' : 'cinza', 'Desatualizadas', c.desatualizadas, 'ONUs com versão mais nova disponível') +
                card('bi-file-earmark-binary', 'roxo', 'Firmwares', c.firmwares, 'disponíveis') +
                card('bi-rocket-takeoff', '', 'Campanhas', c.campanhas, 'aprovadas ou em execução') +
                card('bi-x-octagon', c.falhas_24h ? 'vermelho' : 'cinza', 'Falhas 24h', c.falhas_24h, 'jobs com falha') +
                card(d.modo_seguro ? 'bi-shield-fill-check' : 'bi-shield-exclamation', d.modo_seguro ? 'verde' : 'vermelho',
                     'Modo seguro', d.modo_seguro ? 'Ligado' : 'Desligado', 'versão ' + d.versao)
            );
        } else {
            $('#zte-cards').empty();
        }

        var feitos = 0, html = '';
        d.passos.forEach(function (p, i) {
            if (p.estado === 'feito') feitos++;
            var acao = p.estado === 'pendente' && p.link
                ? '<a class="lc-btn-outline" href="' + ZTE.esc(p.link) + '">Abrir <i class="bi bi-arrow-right"></i></a>'
                : ZTE.badge(p.estado);
            html += '<li class="zte-passo ' + ZTE.esc(p.estado) + '"><span class="zte-passo-num">' +
                    (p.estado === 'feito' ? '<i class="bi bi-check-lg"></i>' : (i + 1)) + '</span>' +
                    '<div class="zte-passo-txt"><div class="zte-passo-titulo">' + ZTE.esc(p.titulo) + '</div>' +
                    '<div class="zte-passo-desc">' + ZTE.esc(p.descricao) + '</div></div>' + acao + '</li>';
        });
        $('#zte-passos').html(html);
        $('#zte-passos-conta').text(feitos + '/' + d.passos.length);
    }

    /** Barras horizontais, uma serie: comprimento = valor, rotulo direto, dica no hover. */
    function grafico($alvo, itens, vazio) {
        if (!itens.length) { $alvo.html('<div class="lc-empty" style="padding:20px">' + vazio + '</div>'); return; }
        var max = Math.max.apply(null, itens.map(function (i) { return i.valor; })) || 1;
        $alvo.html(itens.map(function (i) {
            return '<div class="zte-graf-linha"><span class="zte-graf-rot" title="' + ZTE.esc(i.rotulo) + '">' + ZTE.esc(i.rotulo) + '</span>' +
                '<div class="zte-graf-trilho"><div class="zte-graf-barra" style="width:' + (i.valor * 100 / max).toFixed(1) + '%"></div></div>' +
                '<span class="zte-graf-val">' + ZTE.esc(i.texto || i.valor) + '</span>' +
                '<span class="zte-graf-dica">' + ZTE.esc(i.dica) + '</span></div>';
        }).join(''));
    }

    function graficos() {
        ZTE.api('inventario.painel').then(function (p) {
            $('#zte-graficos').show();
            grafico($('#g-modelos'), p.modelos.map(function (m) {
                var rot = m.modelo + (m.hw ? ' · ' + m.hw : '') + ' · ' + m.sw + (m.desatualizada ? ' ↑' : '');
                return { rotulo: rot, valor: +m.n,
                         dica: rot + ': ' + m.n + ' ONU(s)' + (m.desatualizada ? ' — há versão mais nova disponível' : '') };
            }), 'Sem ONUs no inventário. Atualize o inventário de uma OLT.');
            grafico($('#g-pons'), p.pons.map(function (x) {
                var rot = x.olt + ' ' + x.slot + '/' + x.porta;
                return { rotulo: rot, valor: +x.total, texto: x.online + '/' + x.total,
                         dica: rot + ': ' + x.total + ' ONU(s), ' + x.online + ' online, ' + (x.total - x.online) + ' fora' };
            }), 'Sem ONUs no inventário.');
            $('#g-outros').text(p.outros_fornecedores.length
                ? 'Fora do escopo: ' + p.outros_fornecedores.map(function (o) { return o.n + ' ' + o.fornecedor; }).join(', ') : '');
        }).catch(function () { /* o painel funciona sem os graficos */ });
    }

    function carregar() {
        ZTE.api('inicio.estado').then(function (d) { render(d); if (d.pode_ver) graficos(); }).catch(ZTE.erro);
    }

    $(function () {
        carregar();
        $('#btn-assumir').on('click', function () {
            ZTE.confirmar({
                titulo: 'Assumir administração',
                msg: 'Você será o administrador deste addon.',
                sub: 'Esta ação fica registrada na auditoria e só pode ser feita enquanto não houver administrador.',
                textoOk: 'Assumir'
            }).then(function (ok) {
                if (!ok) return;
                ZTE.loading('Gravando...');
                ZTE.api('permissao.assumir_admin', {}, 'POST')
                    .then(function () { ZTE.toast('ok', 'Você agora é o administrador do addon.'); carregar(); })
                    .catch(ZTE.erro)
                    .finally(ZTE.fimLoading);
            });
        });
    });
})();
</script>
</body>
</html>
