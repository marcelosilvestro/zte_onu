<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'firmwares';
$zte_perm_pagina = 'ver';
include('nav/header.php');
$zte_pode = $zte_schema_ok && Permissao::tem('firmware.gerenciar');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="zte-aviso info">
        <i class="bi bi-info-circle"></i>
        <div>O firmware é enviado <strong>direto ao FTP</strong>. O addon calcula o SHA-256 na origem, lê o arquivo de volta do FTP para conferir
            e só o marca como <strong>Disponível</strong> com a integridade conferida e ao menos uma compatibilidade modelo × revisão de hardware.
            <span id="zte-politica"></span></div>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-file-earmark-binary"></i> Biblioteca de firmwares <span class="lc-badge-count" id="zte-total">0</span></span>
            <?php if ($zte_pode): ?>
            <div class="zte-acoes">
                <button type="button" class="lc-btn-outline" id="btn-adotar" title="Cadastrar um arquivo que já está no FTP"><i class="bi bi-box-arrow-in-down"></i> Cadastrar do FTP</button>
                <button type="button" class="lc-btn-black" id="btn-enviar"><i class="bi bi-cloud-upload"></i> Enviar firmware</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="lc-table-wrap" style="min-height:0">
            <table class="lc-table">
                <thead><tr>
                    <th>Firmware</th><th class="prio-7">Arquivo</th><th class="prio-8">SHA-256</th>
                    <th class="prio-6">Compatibilidade</th><th>Estado</th><th></th>
                </tr></thead>
                <tbody id="zte-linhas"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<datalist id="dl-modelos"></datalist>
<datalist id="dl-hw"></datalist>

<div class="lc-overlay" id="modal-enviar" onclick="ZTE.fecharSeFora(event, 'modal-enviar')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="env-titulo">Enviar firmware</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-enviar')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input type="hidden" id="e-modo">
            <div class="zte-form-2">
                <div><label class="lc-label">Repositório</label><select class="lc-input" id="e-repo"></select></div>
                <div class="so-envio"><label class="lc-label">Pasta no repositório</label><input class="lc-input" id="e-pasta" maxlength="200" placeholder="(raiz)">
                    <div class="lc-input-hint">Relativa à raiz do repositório. Ex.: zte/f670l</div></div>
                <div class="so-adotar"><label class="lc-label">Arquivo no FTP</label><input class="lc-input" id="e-caminho" maxlength="200" placeholder="zte/F670L_V9.0.10P1N2.bin">
                    <div class="lc-input-hint">Caminho relativo à raiz, como aparece na sincronização.</div></div>
                <div class="span2 so-envio"><label class="lc-label">Arquivo</label><input class="lc-input" id="e-arquivo" type="file">
                    <div class="lc-input-hint" id="e-arquivo-dica"></div></div>
                <div class="span2 so-envio"><label class="lc-label">Nome no FTP</label><input class="lc-input" id="e-nome" maxlength="120">
                    <div class="lc-input-hint">Letras, números, ponto, hífen e sublinhado. Um arquivo com o mesmo nome nunca é sobrescrito.</div></div>
                <div><label class="lc-label">Fabricante</label><input class="lc-input" id="e-fabricante" maxlength="40" value="ZTE"></div>
                <div><label class="lc-label">Modelo / família</label><input class="lc-input" id="e-modelo" maxlength="60" list="dl-modelos" placeholder="F670L"></div>
                <div class="span2"><label class="lc-label">Versão do firmware</label><input class="lc-input" id="e-versao" maxlength="80" placeholder="V9.0.10P1N2"></div>
                <div class="span2 so-adotar"><label class="lc-label">SHA-256 do fabricante (opcional)</label><input class="lc-input zte-mono" id="e-sha" maxlength="64">
                    <div class="lc-input-hint">Com ele, o arquivo do FTP é conferido contra uma referência independente. Sem ele, o firmware fica marcado "sem hash de origem".</div></div>
                <div class="span2"><label class="lc-label">Compatibilidade (modelo × revisão de hardware)</label><div id="e-compat"></div></div>
                <div class="span2"><label class="lc-label">Observação</label><input class="lc-input" id="e-obs" maxlength="500"></div>
            </div>
            <div class="zte-mt" id="e-progresso-box" style="display:none">
                <div class="zte-progresso"><div id="e-progresso"></div></div>
                <div class="lc-input-hint" id="e-progresso-txt"></div>
            </div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-enviar')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-confirmar-envio">Enviar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-compat" onclick="ZTE.fecharSeFora(event, 'modal-compat')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="c-titulo">Compatibilidade</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-compat')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <div class="lc-input-hint" style="margin:0 0 8px">Só as ONUs com exatamente este modelo e esta revisão de hardware recebem o firmware. Sem curinga: firmware errado inutiliza a ONU.</div>
            <div id="c-lista"></div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-compat')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar-compat">Salvar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-editar" onclick="ZTE.fecharSeFora(event, 'modal-editar')">
    <div class="lc-modal-box">
        <div class="lc-modal-header">
            <span class="lc-modal-title">Dados do firmware</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-editar')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <div class="zte-form-2">
                <div><label class="lc-label">Fabricante</label><input class="lc-input" id="d-fabricante" maxlength="40"></div>
                <div><label class="lc-label">Modelo / família</label><input class="lc-input" id="d-modelo" maxlength="60" list="dl-modelos"></div>
                <div class="span2"><label class="lc-label">Versão do firmware</label><input class="lc-input" id="d-versao" maxlength="80"></div>
                <div class="span2"><label class="lc-label">Observação</label><input class="lc-input" id="d-obs" maxlength="500"></div>
            </div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-editar')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar-editar">Salvar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-det" onclick="ZTE.fecharSeFora(event, 'modal-det')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="det-titulo">Firmware</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-det')">&times;</button>
        </div>
        <div class="lc-modal-body" id="det-corpo"></div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-det')">Fechar</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var PODE = <?= $zte_pode ? 'true' : 'false' ?>;
    var d = { firmwares: [], repositorios: [], extensoes: [], modelos_inventario: [] };
    var atual = null;

    function porId(id) { return d.firmwares.filter(function (f) { return f.id === id; })[0]; }

    // ------------------------------------------------------------ editor de compatibilidade
    function linhaCompat(m, hw) {
        return '<div class="zte-compat-linha"><input class="lc-input c-mod" list="dl-modelos" placeholder="Modelo (ex.: F670LV9.0)" value="' + ZTE.esc(m || '') + '">' +
               '<input class="lc-input c-hw" list="dl-hw" placeholder="Revisão de HW (ex.: V9.0)" value="' + ZTE.esc(hw || '') + '">' +
               '<button type="button" class="lc-btn-outline c-rem" title="Remover"><i class="bi bi-x-lg"></i></button></div>';
    }
    function editorCompat($alvo, lista) {
        $alvo.html((lista && lista.length ? lista : [{}]).map(function (c) { return linhaCompat(c.modelo, c.hw_versao); }).join('') +
                   '<button type="button" class="lc-btn-outline c-add"><i class="bi bi-plus-lg"></i> Adicionar</button>');
    }
    function lerCompat($alvo) {
        var mods = [], hws = [];
        $alvo.find('.zte-compat-linha').each(function () {
            var m = $(this).find('.c-mod').val().trim(), h = $(this).find('.c-hw').val().trim();
            if (m || h) { mods.push(m); hws.push(h); }
        });
        return { compat_modelo: mods, compat_hw: hws };
    }
    $(document).on('click', '.c-add', function () { $(this).before(linhaCompat('', '')); });
    $(document).on('click', '.c-rem', function () { $(this).closest('.zte-compat-linha').remove(); });

    // ------------------------------------------------------------ tabela
    function linha(f) {
        var compat = f.compat.length ? f.compat.map(function (c) { return '<span class="zte-papel">' + ZTE.esc(c.modelo) + ' · ' + ZTE.esc(c.hw_versao) + '</span>'; }).join('')
                                     : '<span class="zte-sub">nenhuma</span>';
        var integ = { verificada: 'lido de volta e conferido', calculada_no_ftp: 'calculado no FTP', so_tamanho: 'só tamanho conferido', pendente: 'pendente' }[f.integridade];
        var acoes = '<div class="zte-acoes">';
        if (PODE && f.pode_disponibilizar) acoes += '<button type="button" class="lc-btn-black" data-acao="disponibilizar" data-id="' + f.id + '"><i class="bi bi-check2-circle"></i> Disponibilizar</button>';
        acoes += '<button type="button" class="lc-btn-outline" data-acao="menu" data-id="' + f.id + '" title="Mais ações"><i class="bi bi-three-dots"></i></button></div>';
        return '<tr' + (f.estado === 'desativado' ? ' style="opacity:.6"' : '') + '>' +
            '<td><strong>' + ZTE.esc(f.modelo_familia) + '</strong> <span class="zte-mono">' + ZTE.esc(f.versao_firmware) + '</span><div class="zte-sub">' + ZTE.esc(f.fabricante) + '</div></td>' +
            '<td class="prio-7 zte-quebra"><span class="zte-mono">' + ZTE.esc(f.caminho_remoto) + '</span><div class="zte-sub">' + ZTE.esc(f.repositorio_nome) + ' · ' + ZTE.tamanho(f.tamanho_bytes) + '</div></td>' +
            '<td class="prio-8"><span class="zte-hash" title="' + ZTE.esc(f.sha256_origem || f.sha256_remoto || '') + '">' + ZTE.esc((f.sha256_origem || f.sha256_remoto || '—').slice(0, 16)) + (f.sha256_origem || f.sha256_remoto ? '…' : '') + '</span>' +
                '<div class="zte-sub">' + ZTE.esc(integ) + '</div></td>' +
            '<td class="prio-6">' + compat + '</td>' +
            '<td>' + ZTE.badge(f.estado) + (f.hash_sem_origem ? '<div>' + ZTE.badge('sem_origem') + '</div>' : '') + '</td>' +
            '<td>' + acoes + '</td></tr>';
    }

    function render() {
        $('#zte-total').text(d.firmwares.length);
        $('#zte-politica').text(d.politica === 'tamanho' ? 'Política atual: só o tamanho é conferido (mais rápido, menos seguro).' : '');
        $('#zte-linhas').html(d.firmwares.length ? d.firmwares.map(linha).join('')
            : '<tr><td colspan="6" class="lc-empty">Nenhum firmware cadastrado.' + (PODE ? ' Use "Enviar firmware".' : '') + '</td></tr>');
        var mods = {}, hws = {};
        d.modelos_inventario.forEach(function (m) { mods[m.modelo] = 1; if (m.hw_versao) hws[m.hw_versao] = 1; });
        $('#dl-modelos').html(Object.keys(mods).map(function (m) { return '<option value="' + ZTE.esc(m) + '">'; }).join(''));
        $('#dl-hw').html(Object.keys(hws).map(function (h) { return '<option value="' + ZTE.esc(h) + '">'; }).join(''));
    }

    function carregar() { return ZTE.api('firmware.listar').then(function (x) { d = x; render(); }).catch(ZTE.erro); }

    // ------------------------------------------------------------ envio e cadastro do FTP
    function nomeSeguro(n) {
        n = String(n || '').replace(/[^A-Za-z0-9._-]+/g, '_').replace(/\.{2,}/g, '.').replace(/^[^A-Za-z0-9]+/, '');
        return n.slice(0, 120);
    }

    function abrirEnvio(modo) {
        var repos = d.repositorios.filter(function (r) { return r.ativo && (modo === 'envio' ? r.enviar : r.listar); });
        if (!repos.length) {
            ZTE.toast('avis', modo === 'envio' ? 'Nenhum repositório liberado para envio.' : 'Nenhum repositório liberado para listar.',
                      'Teste o repositório na aba Repositórios.');
            return;
        }
        $('#e-modo').val(modo);
        $('#env-titulo').text(modo === 'envio' ? 'Enviar firmware' : 'Cadastrar firmware que já está no FTP');
        $('#modal-enviar .so-envio').toggle(modo === 'envio');
        $('#modal-enviar .so-adotar').toggle(modo !== 'envio');
        $('#e-repo').html(repos.map(function (r) { return '<option value="' + r.id + '">' + ZTE.esc(r.nome) + ' (' + ZTE.esc(r.raiz) + ')</option>'; }).join(''));
        $('#e-pasta, #e-caminho, #e-nome, #e-modelo, #e-versao, #e-sha, #e-obs').val('');
        $('#e-arquivo').val('').attr('accept', d.extensoes.map(function (x) { return '.' + x; }).join(','));
        $('#e-arquivo-dica').text('Aceitos: ' + d.extensoes.join(', ') + ' · até ' + d.max_mb + ' MB.');
        $('#e-progresso-box').hide();
        $('#btn-confirmar-envio').prop('disabled', false).text(modo === 'envio' ? 'Enviar' : 'Cadastrar');
        editorCompat($('#e-compat'), []);
        ZTE.abrirModal('modal-enviar');
    }

    function confirmarEnvio() {
        var modo = $('#e-modo').val();
        var meta = { repositorio_id: $('#e-repo').val(), fabricante: $('#e-fabricante').val(), modelo_familia: $('#e-modelo').val(),
                     versao_firmware: $('#e-versao').val(), observacao: $('#e-obs').val() };
        var compat = lerCompat($('#e-compat'));
        var $btn = $('#btn-confirmar-envio');

        if (modo !== 'envio') {
            ZTE.loading('Lendo o arquivo no FTP e calculando o SHA-256...');
            ZTE.api('firmware.adotar', $.extend(meta, compat, { caminho: $('#e-caminho').val(), sha256_esperado: $('#e-sha').val() }), 'POST')
                .then(function (f) { ZTE.fecharModal('modal-enviar'); ZTE.toast('ok', 'Firmware cadastrado: ' + f.modelo_familia + ' ' + f.versao_firmware, 'Estado: ' + f.estado); carregar(); })
                .catch(ZTE.erro).finally(ZTE.fimLoading);
            return;
        }
        var arq = $('#e-arquivo')[0].files[0];
        if (!arq) { ZTE.toast('avis', 'Escolha o arquivo.'); return; }
        if (arq.size > d.max_mb * 1048576) { ZTE.toast('erro', 'Arquivo acima de ' + d.max_mb + ' MB.'); return; }
        var fd = new FormData();
        Object.keys(meta).forEach(function (k) { fd.append(k, meta[k]); });
        compat.compat_modelo.forEach(function (m, i) { fd.append('compat_modelo[]', m); fd.append('compat_hw[]', compat.compat_hw[i]); });
        fd.append('pasta', $('#e-pasta').val());
        fd.append('nome_remoto', $('#e-nome').val());
        fd.append('arquivo', arq);
        $btn.prop('disabled', true).text('Enviando...');
        $('#e-progresso-box').show();
        $('#e-progresso').css('width', '0');
        ZTE.enviarArquivo('firmware.enviar', fd, function (pct) {
            $('#e-progresso').css('width', pct + '%');
            $('#e-progresso-txt').text(pct < 100 ? 'Enviando ao servidor do addon: ' + pct + '%' : 'Gravando no FTP, lendo de volta e conferindo o SHA-256...');
        }).then(function (f) {
            ZTE.fecharModal('modal-enviar');
            ZTE.toast('ok', 'Firmware enviado: ' + f.modelo_familia + ' ' + f.versao_firmware,
                      f.estado === 'disponivel' ? 'Íntegro e disponível.' : 'Estado: ' + f.estado);
            carregar();
        }).catch(function (e) {
            ZTE.erro(e);
            $btn.prop('disabled', false).text('Enviar');
            $('#e-progresso-box').hide();
        });
    }

    // ------------------------------------------------------------ acoes
    function detalhe(f) {
        ZTE.api('firmware.obter', { id: f.id }).then(function (x) {
            $('#det-titulo').text(x.modelo_familia + ' ' + x.versao_firmware);
            var verif = x.verificacoes.length ? '<table class="lc-table"><thead><tr><th>Quando</th><th>Tipo</th><th>Resultado</th><th>Detalhe</th></tr></thead><tbody>' +
                x.verificacoes.map(function (v) {
                    return '<tr><td style="white-space:nowrap">' + ZTE.dataHora(v.criado_em) + '</td><td>' + ZTE.esc(v.tipo) + '</td><td>' + ZTE.badge(v.resultado) +
                        '</td><td class="zte-quebra">' + ZTE.esc(v.detalhe || '') + '</td></tr>';
                }).join('') + '</tbody></table>' : '<div class="lc-empty">Nenhuma verificação.</div>';
            $('#det-corpo').html(
                '<div style="margin-bottom:8px">' + ZTE.badge(x.estado) + (x.hash_sem_origem ? ' ' + ZTE.badge('sem_origem') : '') + '</div>' +
                '<table class="lc-table"><tbody>' +
                '<tr><td style="width:170px"><strong>Arquivo</strong></td><td class="zte-mono zte-quebra">' + ZTE.esc(x.caminho_remoto) + '</td></tr>' +
                '<tr><td><strong>Repositório</strong></td><td>' + ZTE.esc(x.repositorio_nome) + '</td></tr>' +
                '<tr><td><strong>Nome original</strong></td><td>' + ZTE.esc(x.nome_original || '—') + '</td></tr>' +
                '<tr><td><strong>Tamanho</strong></td><td>' + ZTE.tamanho(x.tamanho_bytes) + (x.tamanho_bytes ? ' (' + x.tamanho_bytes + ' bytes)' : '') + '</td></tr>' +
                '<tr><td><strong>SHA-256 de origem</strong></td><td class="zte-hash">' + ZTE.esc(x.sha256_origem || '— (sem hash de origem)') + '</td></tr>' +
                '<tr><td><strong>SHA-256 lido do FTP</strong></td><td class="zte-hash">' + ZTE.esc(x.sha256_remoto || '— (não lido)') + '</td></tr>' +
                '<tr><td><strong>Compatibilidade</strong></td><td>' + (x.compat.map(function (c) { return ZTE.esc(c.modelo + ' · ' + c.hw_versao); }).join('<br>') || '—') + '</td></tr>' +
                '<tr><td><strong>Cadastro</strong></td><td>' + ZTE.dataHora(x.criado_em) + ' · ' + ZTE.esc(x.criado_por || '') + '</td></tr>' +
                (x.observacao ? '<tr><td><strong>Observação</strong></td><td>' + ZTE.esc(x.observacao) + '</td></tr>' : '') +
                '</tbody></table><label class="lc-label zte-mt">Verificações</label>' + verif);
            ZTE.abrirModal('modal-det');
        }).catch(ZTE.erro);
    }

    function verificar(f) {
        ZTE.loading('Lendo o arquivo no FTP e conferindo...');
        ZTE.api('firmware.verificar', { id: f.id }, 'POST')
            .then(function (r) { ZTE.toast(r.resultado === 'ok' ? 'ok' : (r.resultado === 'aviso' ? 'avis' : 'erro'), r.detalhe, 'Estado: ' + r.estado); carregar(); })
            .catch(ZTE.erro).finally(ZTE.fimLoading);
    }

    function disponibilizar(f) {
        var semOrigem = f.hash_sem_origem && f.estado === 'enviado';
        ZTE.confirmar({
            titulo: 'Disponibilizar firmware', perigo: semOrigem, digitar: semOrigem ? 'ACEITO' : null, textoOk: 'Disponibilizar',
            msg: 'Disponibilizar ' + f.modelo_familia + ' ' + f.versao_firmware + ' para campanhas?',
            sub: semOrigem ? 'Este arquivo não tem hash de origem independente: o SHA-256 foi calculado do próprio FTP. Confirme só se você conferiu a origem do arquivo.'
                           : 'Compatível com: ' + f.compat.map(function (c) { return c.modelo + ' · ' + c.hw_versao; }).join(', ')
        }).then(function (ok) {
            if (!ok) return;
            ZTE.api('firmware.disponibilizar', { id: f.id, aceitar_sem_origem: semOrigem ? 1 : 0 }, 'POST')
                .then(function () { ZTE.toast('ok', 'Firmware disponível.'); carregar(); }).catch(ZTE.erro);
        });
    }

    function abrirCompat(f) {
        atual = f;
        $('#c-titulo').text('Compatibilidade — ' + f.modelo_familia + ' ' + f.versao_firmware);
        editorCompat($('#c-lista'), f.compat);
        ZTE.abrirModal('modal-compat');
    }

    function abrirEditar(f) {
        atual = f;
        $('#d-fabricante').val(f.fabricante); $('#d-modelo').val(f.modelo_familia); $('#d-versao').val(f.versao_firmware); $('#d-obs').val(f.observacao || '');
        ZTE.abrirModal('modal-editar');
    }

    function ativar(f) {
        var desativar = f.estado !== 'desativado';
        ZTE.confirmar({ titulo: desativar ? 'Desativar firmware' : 'Reativar firmware', textoOk: desativar ? 'Desativar' : 'Reativar',
                        msg: (desativar ? 'Desativar ' : 'Reativar ') + f.modelo_familia + ' ' + f.versao_firmware + '?',
                        sub: desativar ? 'Ele deixa de ser usado em campanhas novas. O arquivo e o histórico ficam.' : 'O arquivo será verificado de novo no FTP.' })
            .then(function (ok) {
                if (!ok) return;
                ZTE.loading('Gravando...');
                ZTE.api('firmware.ativar', { id: f.id, ativo: desativar ? 0 : 1 }, 'POST').then(carregar).catch(ZTE.erro).finally(ZTE.fimLoading);
            });
    }

    function excluir(f) {
        ZTE.confirmar({ titulo: 'Excluir firmware', perigo: true, digitar: f.nome_remoto, textoOk: 'Excluir cadastro',
                        msg: 'Excluir o cadastro de ' + f.modelo_familia + ' ' + f.versao_firmware + '?',
                        sub: 'Só é possível se nenhuma regra ou campanha usou este firmware. Caso contrário, desative.' })
            .then(function (conf) {
                if (!conf) return;
                var repo = d.repositorios.filter(function (r) { return r.id === f.repositorio_id; })[0];
                var podeFtp = d.pode_excluir_ftp && repo && repo.excluir;
                (podeFtp ? ZTE.confirmar({ titulo: 'Arquivo no FTP', textoOk: 'Apagar do FTP também', msg: 'Apagar também o arquivo ' + f.nome_remoto + ' do FTP?',
                                          sub: 'Cancelar mantém o arquivo no servidor (ele aparecerá como "sem cadastro" na sincronização).' })
                         : Promise.resolve(false))
                    .then(function (apagar) {
                        ZTE.api('firmware.excluir', { id: f.id, confirmacao: conf, apagar_remoto: apagar ? 1 : 0 }, 'POST')
                            .then(function () { ZTE.toast('ok', 'Firmware excluído' + (apagar ? ' (cadastro e arquivo).' : ' (o arquivo ficou no FTP).')); carregar(); })
                            .catch(ZTE.erro);
                    });
            });
    }

    $(function () {
        carregar();
        $('#btn-enviar').on('click', function () { abrirEnvio('envio'); });
        $('#btn-adotar').on('click', function () { abrirEnvio('adotar'); });
        $('#btn-confirmar-envio').on('click', confirmarEnvio);
        $('#e-arquivo').on('change', function () {
            var a = this.files[0];
            if (a && !$('#e-nome').val()) $('#e-nome').val(nomeSeguro(a.name));
        });
        $('#btn-salvar-compat').on('click', function () {
            ZTE.api('firmware.compat', $.extend({ id: atual.id }, lerCompat($('#c-lista'))), 'POST')
                .then(function (f) { ZTE.fecharModal('modal-compat'); ZTE.toast('ok', 'Compatibilidade salva.', 'Estado: ' + f.estado); carregar(); })
                .catch(ZTE.erro);
        });
        $('#btn-salvar-editar').on('click', function () {
            ZTE.api('firmware.editar', { id: atual.id, versao: atual.versao, fabricante: $('#d-fabricante').val(), modelo_familia: $('#d-modelo').val(),
                                         versao_firmware: $('#d-versao').val(), observacao: $('#d-obs').val() }, 'POST')
                .then(function () { ZTE.fecharModal('modal-editar'); ZTE.toast('ok', 'Dados salvos.'); carregar(); })
                .catch(ZTE.erro);
        });
        $('#zte-linhas').on('click', 'button[data-acao]', function () {
            var f = porId(+$(this).attr('data-id')), a = $(this).attr('data-acao');
            if (a === 'disponibilizar') { disponibilizar(f); return; }
            var itens = [{ texto: 'Detalhes e verificações', icone: 'bi-card-text', acao: function () { detalhe(f); } }];
            if (PODE) {
                itens.push({ texto: 'Compatibilidade', icone: 'bi-diagram-2', acao: function () { abrirCompat(f); } },
                           { texto: 'Verificar no FTP agora', icone: 'bi-shield-check', acao: function () { verificar(f); } },
                           { texto: 'Editar dados', icone: 'bi-pencil', acao: function () { abrirEditar(f); } },
                           '-', { texto: f.estado === 'desativado' ? 'Reativar' : 'Desativar', icone: f.estado === 'desativado' ? 'bi-play-circle' : 'bi-pause-circle', acao: function () { ativar(f); } },
                           { texto: 'Excluir', icone: 'bi-trash', perigo: true, acao: function () { excluir(f); } });
            }
            ZTE.menu(this, itens);
        });
    });
})();
</script>
</body>
</html>
