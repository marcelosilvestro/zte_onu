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
    <div id="zte-topo"><div class="lc-loading">Carregando...</div></div>
<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>
<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    function pon(p, oltId) {
        var mods = p.modelos.map(function (m) {
            return '<span class="zte-papel" title="' + m.n + ' ONU(s)">' + ZTE.esc(m.modelo + (m.hw ? ' · ' + m.hw : '')) + ' <strong>' + m.n + '</strong></span>';
        }).join('');
        return '<div class="zte-pon">' +
            '<div class="zte-pon-cab"><a class="zte-mono" href="inventario.php?olt=' + oltId + '&pon=' + encodeURIComponent(p.slot + '/' + p.pon) + '">PON ' + p.slot + '/' + p.pon + '</a>' +
            '<span class="zte-sub">' + p.total + ' ONU(s)</span></div>' +
            '<div class="zte-pon-num"><span><strong>' + p.online + '</strong> online</span><span><strong>' + p.offline + '</strong> offline</span>' +
            (p.ausentes ? '<span><strong>' + p.ausentes + '</strong> sumiram</span>' : '') +
            (p.total - p.zte ? '<span><strong>' + (p.total - p.zte) + '</strong> outro fabricante</span>' : '') + '</div>' +
            (mods ? '<div class="zte-pon-mods">' + mods + '</div>' : '') +
            (p.sem_modelo ? '<div class="zte-acao"><i class="bi bi-info-circle"></i> ' + p.sem_modelo + ' ONU(s) ZTE online sem modelo lido</div>' : '') +
            '</div>';
    }

    function render(d) {
        if (!d.olts.length) { $('#zte-topo').html('<div class="lc-empty">Nenhuma OLT cadastrada.</div>'); return; }
        $('#zte-topo').html(d.olts.map(function (o) {
            var slots = {};
            o.placas.forEach(function (pl) { slots[pl.slot] = { placa: pl, pons: [] }; });
            o.pons.forEach(function (p) { (slots[p.slot] = slots[p.slot] || { placa: { tipo: '?' }, pons: [] }).pons.push(p); });
            var detectadas = {};
            o.pons_detectadas.forEach(function (p) { detectadas[p.slot + '/' + p.pon] = p; });
            var corpo = Object.keys(slots).sort(function (a, b) { return a - b; }).map(function (s) {
                var sl = slots[s];
                var vazias = o.pons_detectadas.filter(function (p) {
                    return p.slot === +s && !sl.pons.some(function (x) { return x.pon === p.pon; });
                }).length;
                if (!sl.pons.length && !vazias) {
                    return '<div class="zte-slot zte-slot-sem"><span class="zte-mono">Slot ' + s + '</span> · ' + ZTE.esc(sl.placa.tipo) + ' <span class="zte-sub">sem PON</span></div>';
                }
                return '<div class="zte-slot"><div class="zte-slot-cab"><span class="zte-mono">Slot ' + s + '</span> · ' + ZTE.esc(sl.placa.tipo) +
                    (vazias ? ' <span class="zte-sub">· ' + vazias + ' PON(s) sem ONU</span>' : '') + '</div>' +
                    '<div class="zte-pons">' + sl.pons.map(function (p) { return pon(p, o.id); }).join('') + '</div></div>';
            }).join('');
            var tot = o.pons.reduce(function (a, p) { return a + p.total; }, 0), on = o.pons.reduce(function (a, p) { return a + p.online; }, 0);
            return '<div class="lc-section-panel zte-mt" style="height:auto"><div class="lc-section-header"><span class="lc-section-title"><i class="bi bi-hdd-network"></i> ' +
                ZTE.esc(o.nome) + (o.simulada ? ' ' + ZTE.badge('simulada') : '') + (o.ativo ? '' : ' ' + ZTE.badge('inativa')) +
                ' <span class="zte-sub">' + ZTE.esc(o.versao || '') + '</span></span><span class="zte-sub">' + tot + ' ONU(s) · ' + on + ' online · inventário ' +
                (o.inventario_em ? ZTE.dataHora(o.inventario_em) : 'nunca lido') + '</span></div><div style="padding:10px 14px">' +
                (corpo || '<div class="lc-empty">Nenhuma placa identificada: teste a OLT e atualize o inventário.</div>') + '</div></div>';
        }).join(''));
    }

    $(function () {
        ZTE.api('inventario.topologia').then(render).catch(ZTE.erro);
    });
})();
</script>
</body>
</html>
