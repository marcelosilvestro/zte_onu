<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'olts';
$zte_perm_pagina = 'ver';
include('nav/header.php');
$zte_pode = $zte_schema_ok && Permissao::tem('olt.configurar');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="zte-aviso info">
        <i class="bi bi-info-circle"></i>
        <div>O teste de acesso só <strong>lê</strong> a OLT: faz login e consulta a versão e as placas (<span class="zte-mono">show version-running</span>).
            Nenhuma configuração é alterada. Para conhecer a tela sem equipamento, cadastre uma OLT com o protocolo <strong>Simulada</strong>.</div>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-hdd-network"></i> OLTs cadastradas <span class="lc-badge-count" id="zte-total">0</span></span>
            <div class="zte-acoes">
                <button type="button" class="lc-btn-outline" id="btn-drivers"><i class="bi bi-cpu"></i> Drivers e compatibilidade</button>
                <?php if ($zte_pode): ?>
                <button type="button" class="lc-btn-black" id="btn-nova"><i class="bi bi-plus-lg"></i> Nova OLT</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="lc-table-wrap">
            <table class="lc-table">
                <thead><tr>
                    <th>Nome</th><th class="prio-7">Acesso</th><th class="prio-6">Versão</th>
                    <th>Compatibilidade</th><th class="prio-8">Último teste</th><th></th>
                </tr></thead>
                <tbody id="zte-linhas"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-olt" onclick="ZTE.fecharSeFora(event, 'modal-olt')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="olt-titulo">Nova OLT</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-olt')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input type="hidden" id="f-id"><input type="hidden" id="f-versao">
            <div class="zte-form-2">
                <div><label class="lc-label">Nome</label><input class="lc-input" id="f-nome" maxlength="80" placeholder="ex.: OLT Centro"></div>
                <div><label class="lc-label">Modelo</label><select class="lc-input" id="f-modelo"></select></div>
                <div><label class="lc-label">Protocolo</label><select class="lc-input" id="f-protocolo"></select></div>
                <div class="so-real"><label class="lc-label">Porta</label><input class="lc-input" id="f-porta" type="number" min="1" max="65535"></div>
                <div class="so-real span2"><label class="lc-label">Endereço (IP ou hostname)</label><input class="lc-input" id="f-host" maxlength="253"></div>
                <div class="so-real"><label class="lc-label">Usuário</label><input class="lc-input" id="f-usuario" maxlength="60" autocomplete="off"></div>
                <div class="so-real"><label class="lc-label">Senha</label><input class="lc-input" id="f-senha" type="password" autocomplete="new-password">
                    <div class="lc-input-hint" id="f-senha-dica"></div></div>
                <div class="so-real"><label class="lc-label">Senha de enable (opcional)</label><input class="lc-input" id="f-enable" type="password" autocomplete="new-password">
                    <div class="lc-input-hint" id="f-enable-dica">Só se o usuário entrar em modo &gt;.</div></div>
                <div class="so-real" id="f-limpar-enable-box" style="display:none;align-self:end">
                    <label class="zte-chk"><input type="checkbox" id="f-limpar-enable"> Apagar a senha de enable</label></div>
                <div><label class="lc-label">Tempo de conexão (s)</label><input class="lc-input" id="f-tcon" type="number" min="3" max="60"></div>
                <div><label class="lc-label">Tempo por comando (s)</label><input class="lc-input" id="f-tcmd" type="number" min="5" max="300"></div>
                <div class="span2"><label class="lc-label">Observação</label><input class="lc-input" id="f-obs" maxlength="500"></div>
            </div>
            <div class="lc-input-hint zte-mt">As senhas são guardadas cifradas e nunca voltam para a tela.</div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-olt')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar">Salvar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-teste" onclick="ZTE.fecharSeFora(event, 'modal-teste')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="teste-titulo">Teste de acesso</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-teste')">&times;</button>
        </div>
        <div class="lc-modal-body" id="teste-corpo"></div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-teste')">Fechar</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var PODE = <?= $zte_pode ? 'true' : 'false' ?>;
    var dados = { olts: [], modelos: [], protocolos: [] };

    function porId(id) { return dados.olts.filter(function (o) { return o.id === id; })[0]; }

    function linha(o) {
        var nome = '<strong>' + ZTE.esc(o.nome) + '</strong>' +
            (o.protocolo === 'simulado' ? ' ' + ZTE.badge('simulada') : '') +
            (!o.ativo ? ' ' + ZTE.badge('inativa') : '') + (o.bloqueado ? ' ' + ZTE.badge('bloqueada') : '') +
            (o.identificador_detectado ? '<div class="zte-sub">' + ZTE.esc(o.identificador_detectado) + '</div>' : '');
        var acesso = o.protocolo === 'simulado' ? '<span class="zte-sub">sem rede</span>'
            : ZTE.esc(o.host) + ':' + o.porta + ' <span class="zte-sub">' + ZTE.esc(o.protocolo) + '</span>';
        var teste = o.ultimo_teste_resultado
            ? ZTE.badge(o.ultimo_teste_resultado) + '<div class="zte-sub">' + ZTE.dataHora(o.ultimo_teste_em) + '</div>'
            : '<span class="zte-sub">nunca</span>';
        var acoes = '<div class="zte-acoes">';
        if (PODE && o.ativo) acoes += '<button type="button" class="lc-btn-black" data-acao="testar" data-id="' + o.id + '"><i class="bi bi-plug"></i> Testar</button>';
        acoes += '<button type="button" class="lc-btn-outline" data-acao="menu" data-id="' + o.id + '" title="Mais ações"><i class="bi bi-three-dots"></i></button></div>';
        return '<tr' + (o.ativo ? '' : ' style="opacity:.6"') + '><td>' + nome + '</td><td class="prio-7">' + acesso + '</td>' +
            '<td class="prio-6">' + ZTE.esc(o.versao_detectada || '—') + '</td><td>' + ZTE.badge(o.compatibilidade) + '</td>' +
            '<td class="prio-8">' + teste + '</td><td>' + acoes + '</td></tr>';
    }

    function render() {
        $('#zte-total').text(dados.olts.length);
        $('#zte-linhas').html(dados.olts.length ? dados.olts.map(linha).join('')
            : '<tr><td colspan="6" class="lc-empty">Nenhuma OLT cadastrada.' + (PODE ? ' Use "Nova OLT".' : '') + '</td></tr>');
    }

    function carregar() {
        return ZTE.api('olt.listar').then(function (d) { dados = d; render(); }).catch(ZTE.erro);
    }

    // ------------------------------------------------------------ formulario
    function ajustarProtocolo() {
        var p = $('#f-protocolo').val();
        $('#modal-olt .so-real').toggle(p !== 'simulado');
        var def = (dados.protocolos.filter(function (x) { return x.id === p; })[0] || {}).porta;
        if (!$('#f-porta').val() && def) $('#f-porta').val(def);
    }

    function abrirForm(o) {
        $('#olt-titulo').text(o ? 'Editar OLT' : 'Nova OLT');
        $('#f-modelo').html(dados.modelos.map(function (m) {
            return '<option value="' + ZTE.esc(m.fabricante + '|' + m.modelo) + '">' + ZTE.esc(m.fabricante + ' ' + m.modelo) + ' — ' + ZTE.esc(m.driver) + '</option>';
        }).join(''));
        $('#f-protocolo').html(dados.protocolos.map(function (p) {
            return '<option value="' + p.id + '"' + (p.disponivel ? '' : ' disabled') + '>' + ZTE.esc(p.rotulo) + '</option>';
        }).join(''));
        $('#f-id').val(o ? o.id : '');
        $('#f-versao').val(o ? o.versao : '');
        $('#f-nome').val(o ? o.nome : '');
        if (o) $('#f-modelo').val(o.fabricante + '|' + o.modelo);
        $('#f-protocolo').val(o ? o.protocolo : 'telnet');
        $('#f-host').val(o && o.protocolo !== 'simulado' ? o.host : '');
        $('#f-porta').val(o ? o.porta : '');
        $('#f-usuario').val(o && o.protocolo !== 'simulado' ? o.usuario : '');
        $('#f-senha, #f-enable').val('');
        $('#f-senha-dica').text(o && o.tem_senha ? '••• definida — deixe em branco para manter.' : 'Obrigatória.');
        $('#f-enable-dica').text(o && o.tem_enable ? '••• definida — deixe em branco para manter.' : 'Só se o usuário entrar em modo >.');
        $('#f-limpar-enable').prop('checked', false);
        $('#f-limpar-enable-box').toggle(!!(o && o.tem_enable));
        $('#f-tcon').val(o ? o.timeout_conexao_s : 10);
        $('#f-tcmd').val(o ? o.timeout_comando_s : 30);
        $('#f-obs').val(o ? (o.observacao || '') : '');
        ajustarProtocolo();
        ZTE.abrirModal('modal-olt');
        setTimeout(function () { $('#f-nome').trigger('focus'); }, 50);
    }

    function salvar() {
        var fm = ($('#f-modelo').val() || '|').split('|');
        var dadosForm = {
            id: $('#f-id').val() || 0, versao: $('#f-versao').val(), nome: $('#f-nome').val(),
            fabricante: fm[0], modelo: fm[1], protocolo: $('#f-protocolo').val(), host: $('#f-host').val(),
            porta: $('#f-porta').val(), usuario: $('#f-usuario').val(), senha: $('#f-senha').val(),
            senha_enable: $('#f-enable').val(), limpar_enable: $('#f-limpar-enable').is(':checked') ? 1 : 0,
            timeout_conexao_s: $('#f-tcon').val(), timeout_comando_s: $('#f-tcmd').val(), observacao: $('#f-obs').val()
        };
        ZTE.loading('Salvando...');
        ZTE.api('olt.salvar', dadosForm, 'POST')
            .then(function (o) {
                $('#f-senha, #f-enable').val('');
                ZTE.fecharModal('modal-olt');
                ZTE.toast('ok', 'OLT ' + o.nome + ' salva.', 'Use "Testar" para conferir o acesso.');
                carregar();
            })
            .catch(ZTE.erro)
            .finally(ZTE.fimLoading);
    }

    // ------------------------------------------------------------ teste
    function etapasHtml(etapas) {
        return etapas.map(function (e) {
            return '<div class="zte-etapa">' + ZTE.badge(e.resultado) + '<div><strong>' + ZTE.esc(e.titulo) + '</strong>' +
                '<div class="zte-sub zte-quebra">' + ZTE.esc(e.detalhe) + (e.ms ? ' · ' + e.ms + ' ms' : (e.duracao_ms ? ' · ' + e.duracao_ms + ' ms' : '')) + '</div></div></div>';
        }).join('');
    }

    function manifestoHtml(m) {
        if (!m) return '';
        var rec = Object.keys(m.recursos).map(function (k) {
            return '<tr><td class="zte-mono">' + ZTE.esc(k) + '</td><td>' + ZTE.badge(m.recursos[k]) + '</td></tr>';
        }).join('');
        return '<label class="lc-label zte-mt">Driver: ' + ZTE.esc(m.nome) + ' — versões testadas: ' + ZTE.esc(m.versoes_testadas.join(', ')) + '</label>' +
            '<table class="lc-table"><tbody>' + rec + '</tbody></table>' +
            '<label class="lc-label zte-mt">Limitações conhecidas</label><ul class="zte-lista">' +
            m.limitacoes.map(function (l) { return '<li>' + ZTE.esc(l) + '</li>'; }).join('') + '</ul>';
    }

    function placasHtml(placas) {
        if (!placas || !placas.length) return '';
        return '<label class="lc-label zte-mt">Placas detectadas</label><table class="lc-table"><thead><tr><th>Local</th><th>Tipo</th><th>Versões</th></tr></thead><tbody>' +
            placas.map(function (p) {
                return '<tr><td class="zte-mono">' + ZTE.esc(p.local) + '</td><td>' + ZTE.esc(p.tipo) + '</td><td class="zte-mono zte-sub">' +
                    Object.keys(p.versoes).map(function (k) { return ZTE.esc(k + ' ' + p.versoes[k]); }).join(' · ') + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    function testar(o) {
        ZTE.loading('Testando ' + o.nome + '... (até ' + (o.timeout_conexao_s * 3 + o.timeout_comando_s) + ' s)');
        ZTE.api('olt.testar', { id: o.id }, 'POST')
            .then(function (r) {
                $('#teste-titulo').text('Teste de acesso — ' + o.nome);
                $('#teste-corpo').html(
                    '<div style="margin-bottom:8px">' + ZTE.badge(r.resultado) + ' ' + ZTE.badge(r.compatibilidade) +
                    ' <span class="zte-sub zte-mono">' + ZTE.esc(r.correlacao) + '</span></div>' +
                    etapasHtml(r.etapas) + placasHtml(r.placas) + manifestoHtml(r.manifesto));
                ZTE.abrirModal('modal-teste');
                carregar();
            })
            .catch(function (e) { ZTE.erro(e); carregar(); })
            .finally(ZTE.fimLoading);
    }

    function historico(o) {
        ZTE.api('olt.testes', { id: o.id }).then(function (d) {
            $('#teste-titulo').text('Histórico de testes — ' + o.nome);
            $('#teste-corpo').html(d.testes.length ? d.testes.map(function (t) {
                return '<div class="lc-section-panel zte-mt" style="height:auto"><div class="lc-section-header"><span class="lc-section-title">' +
                    ZTE.dataHora(t.em) + ' · ' + ZTE.esc(t.por || '') + '</span>' + ZTE.badge(t.resultado) + '</div>' +
                    '<div style="padding:4px 14px">' + etapasHtml(t.etapas.map(function (e) {
                        return { titulo: e.titulo || e.etapa, resultado: e.resultado, detalhe: e.detalhe, duracao_ms: e.duracao_ms };
                    })) + '</div></div>';
            }).join('') : '<div class="lc-empty">Nenhum teste ainda.</div>');
            ZTE.abrirModal('modal-teste');
        }).catch(ZTE.erro);
    }

    function drivers() {
        ZTE.api('olt.drivers').then(function (d) {
            $('#teste-titulo').text('Drivers e compatibilidade');
            $('#teste-corpo').html(d.drivers.map(function (m) {
                return '<div><strong>' + ZTE.esc(m.nome) + '</strong> <span class="zte-sub">modelos ' + ZTE.esc(m.modelos.join(', ')) + '</span></div>' +
                    manifestoHtml(m) + '<label class="lc-label zte-mt">Pré-requisitos</label><ul class="zte-lista">' +
                    m.prerequisitos.map(function (p) { return '<li>' + ZTE.esc(p) + '</li>'; }).join('') + '</ul>';
            }).join('<hr>'));
            ZTE.abrirModal('modal-teste');
        }).catch(ZTE.erro);
    }

    function ativar(o) {
        ZTE.confirmar({ titulo: o.ativo ? 'Desativar OLT' : 'Reativar OLT', textoOk: o.ativo ? 'Desativar' : 'Reativar',
                        msg: (o.ativo ? 'Desativar ' : 'Reativar ') + o.nome + '?',
                        sub: o.ativo ? 'Uma OLT desativada não é testada, inventariada nem atualizada. O histórico fica.' : '' })
            .then(function (ok) {
                if (!ok) return;
                ZTE.api('olt.ativar', { id: o.id, ativo: o.ativo ? 0 : 1 }, 'POST').then(carregar).catch(ZTE.erro);
            });
    }

    function remover(o) {
        ZTE.confirmar({ titulo: 'Remover OLT', perigo: true, digitar: o.nome, textoOk: 'Remover',
                        msg: 'Remover ' + o.nome + ' e as senhas guardadas dela?',
                        sub: 'Só é possível sem histórico (inventário, regras, campanhas). Caso contrário, desative.' })
            .then(function (conf) {
                if (!conf) return;
                ZTE.api('olt.remover', { id: o.id, confirmacao: conf }, 'POST')
                    .then(function () { ZTE.toast('ok', 'OLT removida.'); carregar(); })
                    .catch(ZTE.erro);
            });
    }

    $(function () {
        carregar();
        $('#btn-nova').on('click', function () { abrirForm(null); });
        $('#btn-salvar').on('click', salvar);
        $('#btn-drivers').on('click', drivers);
        $('#f-protocolo').on('change', function () { $('#f-porta').val(''); ajustarProtocolo(); });
        $('#zte-linhas').on('click', 'button[data-acao]', function () {
            var o = porId(parseInt($(this).attr('data-id'), 10));
            var a = $(this).attr('data-acao');
            if (a === 'testar') { testar(o); return; }
            var itens = [{ texto: 'Histórico de testes', icone: 'bi-clock-history', acao: function () { historico(o); } }];
            if (PODE) {
                itens.push({ texto: 'Editar', icone: 'bi-pencil', acao: function () { abrirForm(o); } },
                           { texto: o.ativo ? 'Desativar' : 'Reativar', icone: o.ativo ? 'bi-pause-circle' : 'bi-play-circle', acao: function () { ativar(o); } },
                           '-', { texto: 'Remover', icone: 'bi-trash', perigo: true, acao: function () { remover(o); } });
            }
            ZTE.menu(this, itens);
        });
    });
})();
</script>
</body>
</html>
