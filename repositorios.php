<?php
require_once __DIR__ . '/config.php';
$zte_pagina = 'repositorios';
$zte_perm_pagina = 'ver';
include('nav/header.php');
$zte_pode = $zte_schema_ok && Permissao::tem('repositorio.configurar');
?>
<body>
<?php include('../../topo.php'); ?>

<div class="container-fluid px-3 py-0 zte-wrap">
<?php include('nav/abas.php'); ?>
<?php if (!$zte_bloqueado): ?>

    <div class="zte-aviso info">
        <i class="bi bi-info-circle"></i>
        <div>O servidor FTP é o <strong>repositório de firmwares</strong>. O addon usa a conta dele só para gerenciar os arquivos.
            No upgrade, a <strong>OLT busca o arquivo direto no FTP</strong>, com o endereço e a conta cadastrados em "Acesso das OLTs ao FTP".</div>
    </div>

    <div class="lc-section-panel" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-server"></i> Repositórios FTP <span class="lc-badge-count" id="zte-total-repo">0</span></span>
            <?php if ($zte_pode): ?>
            <button type="button" class="lc-btn-black" id="btn-novo-repo"><i class="bi bi-plus-lg"></i> Novo repositório</button>
            <?php endif; ?>
        </div>
        <div class="lc-table-wrap" style="min-height:0">
            <table class="lc-table">
                <thead><tr>
                    <th>Nome</th><th class="prio-7">Servidor</th><th class="prio-8">Raiz</th>
                    <th>Operações</th><th class="prio-6">Último teste</th><th></th>
                </tr></thead>
                <tbody id="zte-repos"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="lc-section-panel zte-mt" style="height:auto">
        <div class="lc-section-header">
            <span class="lc-section-title"><i class="bi bi-arrow-left-right"></i> Acesso das OLTs ao FTP <span class="lc-badge-count" id="zte-total-vin">0</span></span>
            <?php if ($zte_pode): ?>
            <button type="button" class="lc-btn-black" id="btn-novo-vin"><i class="bi bi-plus-lg"></i> Novo acesso</button>
            <?php endif; ?>
        </div>
        <div class="lc-table-wrap" style="min-height:0">
            <table class="lc-table">
                <thead><tr>
                    <th>OLT</th><th>Repositório</th><th class="prio-7">Endereço visto pela OLT</th><th class="prio-8">Conta</th>
                    <th>Conectividade</th><th></th>
                </tr></thead>
                <tbody id="zte-vins"><tr><td colspan="6" class="lc-loading">Carregando...</td></tr></tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div>

<?php include('../../baixo.php'); ?>
<?php include('nav/comum.php'); ?>

<div class="lc-overlay" id="modal-repo" onclick="ZTE.fecharSeFora(event, 'modal-repo')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="repo-titulo">Novo repositório</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-repo')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input type="hidden" id="r-id"><input type="hidden" id="r-versao">
            <div class="zte-form-2">
                <div><label class="lc-label">Nome</label><input class="lc-input" id="r-nome" maxlength="80" placeholder="ex.: FTP de firmwares"></div>
                <div><label class="lc-label">Protocolo</label><select class="lc-input" id="r-protocolo"></select></div>
                <div><label class="lc-label">Endereço (IP ou hostname)</label><input class="lc-input" id="r-host" maxlength="253"></div>
                <div><label class="lc-label">Porta</label><input class="lc-input" id="r-porta" type="number" min="1" max="65535"></div>
                <div><label class="lc-label">Usuário do addon</label><input class="lc-input" id="r-usuario" maxlength="60" autocomplete="off"></div>
                <div><label class="lc-label">Senha do addon</label><input class="lc-input" id="r-senha" type="password" autocomplete="new-password">
                    <div class="lc-input-hint" id="r-senha-dica"></div></div>
                <div><label class="lc-label">Diretório raiz</label><input class="lc-input" id="r-raiz" maxlength="255" placeholder="/firmwares">
                    <div class="lc-input-hint">O addon nunca sai desta pasta.</div></div>
                <div style="align-self:center"><label class="zte-chk"><input type="checkbox" id="r-passivo" checked> Modo passivo</label>
                    <div class="lc-input-hint">Desligue só se o servidor exigir modo ativo.</div></div>
                <div><label class="lc-label">Tempo de conexão (s)</label><input class="lc-input" id="r-tcon" type="number" min="3" max="60"></div>
                <div><label class="lc-label">Tempo de transferência (s)</label><input class="lc-input" id="r-ttra" type="number" min="10" max="3600"></div>
                <div class="span2"><label class="lc-label">Operações permitidas ao addon</label>
                    <div class="zte-ops" style="gap:14px">
                        <label class="zte-chk"><input type="checkbox" id="r-perm-listar"> Listar</label>
                        <label class="zte-chk"><input type="checkbox" id="r-perm-enviar"> Enviar</label>
                        <label class="zte-chk"><input type="checkbox" id="r-perm-renomear"> Renomear</label>
                        <label class="zte-chk"><input type="checkbox" id="r-perm-excluir"> Excluir</label>
                    </div>
                    <div class="lc-input-hint">Cada operação só fica disponível depois que o teste confirmar que o servidor permite.</div></div>
                <div class="span2"><label class="lc-label">Observação</label><input class="lc-input" id="r-obs" maxlength="500"></div>
            </div>
            <div class="zte-aviso zte-mt" id="r-aviso-ftp" style="display:none;margin-bottom:0"><i class="bi bi-unlock"></i>
                <div>FTP sem criptografia: usuário, senha e arquivos trafegam abertos na rede. Prefira FTPS para o addon se o servidor suportar, e use uma rede de gerência.</div></div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-repo')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar-repo">Salvar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-vin" onclick="ZTE.fecharSeFora(event, 'modal-vin')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="vin-titulo">Novo acesso da OLT ao FTP</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-vin')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input type="hidden" id="v-id"><input type="hidden" id="v-versao">
            <div class="zte-form-2">
                <div><label class="lc-label">OLT</label><select class="lc-input" id="v-olt"></select></div>
                <div><label class="lc-label">Repositório</label><select class="lc-input" id="v-repo"></select></div>
                <div><label class="lc-label">IP do FTP visto pela OLT</label><input class="lc-input" id="v-host" maxlength="45">
                    <div class="lc-input-hint">Pode ser diferente do usado pelo addon (VLAN de gerência, NAT).</div></div>
                <div><label class="lc-label">Porta</label><input class="lc-input" id="v-porta" type="number" min="1" max="65535" value="21"></div>
                <div><label class="lc-label">Usuário da OLT no FTP</label><input class="lc-input" id="v-usuario" maxlength="60" autocomplete="off"></div>
                <div><label class="lc-label">Senha da OLT no FTP</label><input class="lc-input" id="v-senha" type="password" autocomplete="new-password">
                    <div class="lc-input-hint" id="v-senha-dica"></div></div>
                <div class="span2"><label class="lc-label">Pasta vista pela OLT</label><input class="lc-input" id="v-caminho" maxlength="255" placeholder="/">
                    <div class="lc-input-hint">O caminho do firmware como a conta da OLT o enxerga (a raiz dela pode ser outra).</div></div>
            </div>
            <div class="zte-aviso zte-mt" style="margin-bottom:0"><i class="bi bi-shield-exclamation"></i>
                <div>Recomendado: uma conta só de <strong>leitura</strong> para a OLT. Dependendo do comando da OLT, a senha pode aparecer no histórico de comandos do próprio equipamento.</div></div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-vin')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="btn-salvar-vin">Salvar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-arq" onclick="ZTE.fecharSeFora(event, 'modal-arq')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="arq-titulo">Arquivos</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-arq')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <div class="zte-trilha" id="arq-trilha"></div>
            <div class="lc-table-wrap" style="max-height:360px;min-height:0">
                <table class="lc-table"><thead><tr><th>Nome</th><th>Tamanho</th><th class="prio-6">Modificado</th><th></th></tr></thead>
                    <tbody id="arq-itens"></tbody></table>
            </div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-outline" id="btn-nova-pasta"><i class="bi bi-folder-plus"></i> Nova pasta</button>
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-arq')">Fechar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-res" onclick="ZTE.fecharSeFora(event, 'modal-res')">
    <div class="lc-modal-box lg">
        <div class="lc-modal-header lc-modal-header-dark">
            <span class="lc-modal-title" id="res-titulo">Resultado</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-res')">&times;</button>
        </div>
        <div class="lc-modal-body" id="res-corpo"></div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-res')">Fechar</button>
        </div>
    </div>
</div>

<div class="lc-overlay" id="modal-nome" onclick="ZTE.fecharSeFora(event, 'modal-nome')">
    <div class="lc-modal-box sm">
        <div class="lc-modal-header">
            <span class="lc-modal-title" id="nome-titulo">Nome</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('modal-nome')">&times;</button>
        </div>
        <div class="lc-modal-body">
            <input class="lc-input" id="nome-valor" maxlength="120" autocomplete="off">
            <div class="lc-input-hint">Letras, números, ponto, hífen e sublinhado.</div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('modal-nome')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="nome-ok">OK</button>
        </div>
    </div>
</div>

<script src="../../menu.js<?= $ext_mk ?>"></script>
<script>
(function () {
    var PODE = <?= $zte_pode ? 'true' : 'false' ?>;
    var PODE_EXCLUIR = false;
    var d = { repositorios: [], vinculos: [], olts: [], protocolos: [] };
    var nav = { repo: null, caminho: '' };

    function repoPorId(id) { return d.repositorios.filter(function (r) { return r.id === id; })[0]; }
    function vinPorId(id) { return d.vinculos.filter(function (v) { return v.id === id; })[0]; }
    function tam(n) {
        if (n === null || n === undefined) return '—';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1).replace('.', ',') + ' KB';
        return (n / 1048576).toFixed(1).replace('.', ',') + ' MB';
    }
    function btn(acao, id, ico, txt, titulo, preto) {
        return '<button type="button" class="' + (preto ? 'lc-btn-black' : 'lc-btn-outline') + '" data-acao="' + acao + '" data-id="' + id + '"' +
               (titulo ? ' title="' + ZTE.esc(titulo) + '"' : '') + '><i class="bi ' + ico + '"></i>' + (txt ? ' ' + txt : '') + '</button>';
    }

    // ------------------------------------------------------------ repositorios
    function ops(r) {
        return '<div class="zte-ops">' + ['listar', 'enviar', 'renomear', 'excluir'].map(function (op) {
            var cls = !r['perm_' + op] ? '' : (r['confirmado_' + op] === 1 ? 'ok' : (r['confirmado_' + op] === 0 ? 'falha' : 'pendente'));
            var tit = !r['perm_' + op] ? 'desabilitado' : (cls === 'ok' ? 'confirmado pelo teste' : (cls === 'falha' ? 'o servidor recusou no teste' : 'ainda não testado'));
            return '<span class="zte-op ' + cls + '" title="' + tit + '">' + op + '</span>';
        }).join('') + '</div>';
    }

    function linhaRepo(r) {
        var nome = '<strong>' + ZTE.esc(r.nome) + '</strong>' + (!r.ativo ? ' ' + ZTE.badge('inativa') : '') +
            (r.bloqueado ? ' ' + ZTE.badge('bloqueada') : '') + (r.protocolo === 'ftp' ? ' ' + ZTE.badge('sem_cripto') : '') +
            (r.firmwares ? '<div class="zte-sub">' + r.firmwares + ' firmware(s)</div>' : '');
        var teste = r.ultimo_teste_resultado ? ZTE.badge(r.ultimo_teste_resultado) + '<div class="zte-sub">' + ZTE.dataHora(r.ultimo_teste_em) + '</div>'
                                             : '<span class="zte-sub">nunca</span>';
        var a = '<div class="zte-acoes">';
        if (PODE && r.ativo) a += btn('testar', r.id, 'bi-plug', 'Testar', '', true);
        if (PODE && r.ativo && r.liberado_listar) a += btn('arquivos', r.id, 'bi-folder2-open', '', 'Arquivos');
        a += btn('menu', r.id, 'bi-three-dots', '', 'Mais ações') + '</div>';
        return '<tr' + (r.ativo ? '' : ' style="opacity:.6"') + '><td>' + nome + '</td>' +
            '<td class="prio-7">' + ZTE.esc(r.host) + ':' + r.porta + ' <span class="zte-sub">' + ZTE.esc(r.protocolo) + (r.passivo ? '' : ', ativo') + '</span></td>' +
            '<td class="prio-8 zte-mono">' + ZTE.esc(r.raiz) + '</td><td>' + ops(r) + '</td><td class="prio-6">' + teste + '</td><td>' + a + '</td></tr>';
    }

    function linhaVin(v) {
        var mapa = { validado: 'validado_ftp', potencial: 'potencial', desconhecido: 'desconhecida', nao_testavel: 'nao_testavel', falhou: 'falhou' };
        var a = '<div class="zte-acoes">';
        if (PODE) a += btn('vtestar', v.id, 'bi-broadcast', 'Testar', 'Testa a partir da OLT', true);
        a += btn('vmenu', v.id, 'bi-three-dots', '', 'Mais ações') + '</div>';
        return '<tr><td><strong>' + ZTE.esc(v.olt_nome) + '</strong></td><td>' + ZTE.esc(v.repo_nome) + '</td>' +
            '<td class="prio-7">' + ZTE.esc(v.host_olt) + ':' + v.porta_olt + ' <span class="zte-mono zte-sub">' + ZTE.esc(v.caminho_olt) + '</span></td>' +
            '<td class="prio-8">' + ZTE.esc(v.usuario_olt) + '</td>' +
            '<td>' + ZTE.badge(mapa[v.estado_conectividade] || 'desconhecida') +
            (v.estado_em ? '<div class="zte-sub">' + ZTE.dataHora(v.estado_em) + '</div>' : '') + '</td><td>' + a + '</td></tr>';
    }

    function render() {
        $('#zte-total-repo').text(d.repositorios.length);
        $('#zte-total-vin').text(d.vinculos.length);
        $('#zte-repos').html(d.repositorios.length ? d.repositorios.map(linhaRepo).join('')
            : '<tr><td colspan="6" class="lc-empty">Nenhum repositório cadastrado.</td></tr>');
        $('#zte-vins').html(d.vinculos.length ? d.vinculos.map(linhaVin).join('')
            : '<tr><td colspan="6" class="lc-empty">Nenhuma OLT com acesso ao FTP cadastrado.</td></tr>');
    }

    function carregar() {
        return ZTE.api('repo.listar').then(function (r) { d = r; PODE_EXCLUIR = r.pode_excluir; render(); }).catch(ZTE.erro);
    }

    // ------------------------------------------------------------ formulario repositorio
    function avisoFtp() { $('#r-aviso-ftp').toggle($('#r-protocolo').val() === 'ftp'); }

    function abrirRepo(r) {
        $('#repo-titulo').text(r ? 'Editar repositório' : 'Novo repositório');
        $('#r-protocolo').html(d.protocolos.map(function (p) {
            return '<option value="' + p.id + '"' + (p.disponivel ? '' : ' disabled') + '>' + ZTE.esc(p.rotulo) + (p.disponivel ? '' : ' (sem suporte no PHP)') + '</option>';
        }).join(''));
        $('#r-id').val(r ? r.id : ''); $('#r-versao').val(r ? r.versao : '');
        $('#r-nome').val(r ? r.nome : ''); $('#r-protocolo').val(r ? r.protocolo : 'ftp');
        $('#r-host').val(r ? r.host : ''); $('#r-porta').val(r ? r.porta : 21);
        $('#r-usuario').val(r ? r.usuario : ''); $('#r-senha').val('');
        $('#r-senha-dica').text(r && r.tem_senha ? '••• definida — deixe em branco para manter.' : 'Obrigatória.');
        $('#r-raiz').val(r ? r.raiz : '/'); $('#r-passivo').prop('checked', r ? !!r.passivo : true);
        $('#r-tcon').val(r ? r.timeout_conexao_s : 10); $('#r-ttra').val(r ? r.timeout_transferencia_s : 300);
        ['listar', 'enviar', 'renomear', 'excluir'].forEach(function (op) {
            $('#r-perm-' + op).prop('checked', r ? !!r['perm_' + op] : op !== 'excluir');
        });
        $('#r-obs').val(r ? (r.observacao || '') : '');
        avisoFtp();
        ZTE.abrirModal('modal-repo');
    }

    function salvarRepo() {
        var f = { id: $('#r-id').val() || 0, versao: $('#r-versao').val(), nome: $('#r-nome').val(), protocolo: $('#r-protocolo').val(),
                  host: $('#r-host').val(), porta: $('#r-porta').val(), usuario: $('#r-usuario').val(), senha: $('#r-senha').val(),
                  raiz: $('#r-raiz').val(), passivo: $('#r-passivo').is(':checked') ? 1 : 0,
                  timeout_conexao_s: $('#r-tcon').val(), timeout_transferencia_s: $('#r-ttra').val(), observacao: $('#r-obs').val() };
        ['listar', 'enviar', 'renomear', 'excluir'].forEach(function (op) { f['perm_' + op] = $('#r-perm-' + op).is(':checked') ? 1 : 0; });
        ZTE.loading('Salvando...');
        ZTE.api('repo.salvar', f, 'POST')
            .then(function (r) { $('#r-senha').val(''); ZTE.fecharModal('modal-repo'); ZTE.toast('ok', 'Repositório ' + r.nome + ' salvo.', 'Use "Testar" para confirmar as operações.'); carregar(); })
            .catch(ZTE.erro).finally(ZTE.fimLoading);
    }

    // ------------------------------------------------------------ resultados
    function etapasHtml(etapas) {
        return etapas.map(function (e) {
            var ms = e.ms !== undefined ? e.ms : e.duracao_ms;
            return '<div class="zte-etapa">' + ZTE.badge(e.resultado) + '<div><strong>' + ZTE.esc(e.titulo || e.etapa) + '</strong>' +
                '<div class="zte-sub zte-quebra">' + ZTE.esc(e.detalhe) + (ms ? ' · ' + ms + ' ms' : '') + '</div></div></div>';
        }).join('');
    }
    function mostrar(titulo, html) { $('#res-titulo').text(titulo); $('#res-corpo').html(html); ZTE.abrirModal('modal-res'); }
    function historicoHtml(testes) {
        return testes.length ? testes.map(function (t) {
            return '<div class="lc-section-panel zte-mt" style="height:auto"><div class="lc-section-header"><span class="lc-section-title">' +
                ZTE.dataHora(t.em) + ' · ' + ZTE.esc(t.por || '') + '</span>' + ZTE.badge(t.resultado) + '</div>' +
                '<div style="padding:4px 14px">' + etapasHtml(t.etapas) + '</div></div>';
        }).join('') : '<div class="lc-empty">Nenhum teste ainda.</div>';
    }
    function syncHtml(s) {
        if (!s) return '<div class="lc-empty">Nenhuma sincronização ainda.</div>';
        var rot = { orfao_remoto: 'No FTP, sem cadastro', ausente_no_ftp: 'Cadastrado, ausente no FTP', tamanho_divergente: 'Tamanho diferente do cadastro' };
        return '<div style="margin-bottom:8px">' + ZTE.badge(s.resultado || 'nao_testavel') + ' <span class="zte-sub">' + ZTE.dataHora(s.iniciado_em) +
            ' · ' + (s.total_remoto || 0) + ' arquivo(s) no FTP · ' + (s.orfaos || 0) + ' sem cadastro · ' + (s.ausentes || 0) + ' divergência(s)</span>' +
            (s.detalhe ? '<div class="zte-acao">' + ZTE.esc(s.detalhe) + '</div>' : '') + '</div>' +
            (s.itens.length ? '<table class="lc-table"><thead><tr><th>Situação</th><th>Caminho</th><th>Tamanho</th></tr></thead><tbody>' +
                s.itens.map(function (i) {
                    return '<tr><td>' + ZTE.esc(rot[i.tipo] || i.tipo) + '</td><td class="zte-mono zte-quebra">' + ZTE.esc(i.caminho) + '</td><td>' + tam(i.tamanho === null ? null : +i.tamanho) + '</td></tr>';
                }).join('') + '</tbody></table>' : '<div class="lc-empty">FTP e catálogo coincidem.</div>') +
            '<div class="lc-input-hint zte-mt">A sincronização só aponta diferenças: nada é apagado.</div>';
    }

    // ------------------------------------------------------------ navegador
    function abrirArquivos(r, caminho) {
        nav.repo = r; nav.caminho = caminho || '';
        $('#arq-titulo').text('Arquivos — ' + r.nome);
        $('#arq-itens').html('<tr><td colspan="4" class="lc-loading">Carregando...</td></tr>');
        ZTE.abrirModal('modal-arq');
        ZTE.api('repo.navegar', { id: r.id, caminho: nav.caminho }).then(function (x) {
            var partes = x.relativo ? x.relativo.split('/') : [];
            var trilha = '<a data-cam="">' + ZTE.esc(r.raiz) + '</a>';
            partes.forEach(function (p, i) { trilha += ' / <a data-cam="' + ZTE.esc(partes.slice(0, i + 1).join('/')) + '">' + ZTE.esc(p) + '</a>'; });
            $('#arq-trilha').html(trilha);
            $('#btn-nova-pasta').toggle(PODE && x.pode.enviar);
            $('#arq-itens').html(x.itens.length ? x.itens.map(function (i) {
                var nome = i.tipo === 'dir'
                    ? '<span class="zte-arq-nome dir" data-cam="' + ZTE.esc(i.caminho) + '"><i class="bi bi-folder-fill"></i> ' + ZTE.esc(i.nome) + '</span>'
                    : '<span class="zte-arq-nome"><i class="bi bi-file-earmark"></i> ' + ZTE.esc(i.nome) + '</span>' +
                      (i.firmware ? ' <span class="zte-papel">firmware</span>' : '') + (i.sonda ? ' <span class="zte-papel">sonda de teste</span>' : '');
                var acoes = '';
                if (i.tipo !== 'dir' && !i.firmware && PODE) {
                    if (x.pode.renomear) acoes += '<button type="button" class="lc-btn-outline" data-renomear="' + ZTE.esc(i.caminho) + '" title="Renomear"><i class="bi bi-input-cursor-text"></i></button>';
                    if (x.pode.excluir && PODE_EXCLUIR) acoes += '<button type="button" class="lc-btn-outline" data-excluir="' + ZTE.esc(i.caminho) + '" title="Excluir"><i class="bi bi-trash"></i></button>';
                }
                return '<tr><td class="zte-quebra">' + nome + '</td><td>' + tam(i.tamanho) + '</td><td class="prio-6 zte-sub">' + ZTE.esc(i.modificado || '') +
                    '</td><td><div class="zte-acoes">' + acoes + '</div></td></tr>';
            }).join('') : '<tr><td colspan="4" class="lc-empty">Pasta vazia.</td></tr>');
        }).catch(function (e) { ZTE.fecharModal('modal-arq'); ZTE.erro(e); });
    }

    function pedirNome(titulo, valor) {
        return new Promise(function (resolve) {
            $('#nome-titulo').text(titulo); $('#nome-valor').val(valor || '');
            $('#nome-ok').off('click').on('click', function () { ZTE.fecharModal('modal-nome'); resolve($('#nome-valor').val()); });
            ZTE.abrirModal('modal-nome');
            setTimeout(function () { $('#nome-valor').trigger('focus'); }, 50);
        });
    }

    // ------------------------------------------------------------ acesso OLT -> FTP
    function abrirVin(v) {
        $('#vin-titulo').text(v ? 'Editar acesso da OLT ao FTP' : 'Novo acesso da OLT ao FTP');
        $('#v-olt').html(d.olts.map(function (o) { return '<option value="' + o.id + '">' + ZTE.esc(o.nome) + (o.ativo ? '' : ' (inativa)') + '</option>'; }).join(''))
            .prop('disabled', !!v);
        $('#v-repo').html(d.repositorios.map(function (r) { return '<option value="' + r.id + '">' + ZTE.esc(r.nome) + '</option>'; }).join(''))
            .prop('disabled', !!v);
        $('#v-id').val(v ? v.id : ''); $('#v-versao').val(v ? v.versao : '');
        if (v) { $('#v-olt').val(v.olt_id); $('#v-repo').val(v.repositorio_id); }
        var r = v ? null : d.repositorios[0];
        $('#v-host').val(v ? v.host_olt : (r && /^[0-9.:]+$/.test(r.host) ? r.host : ''));
        $('#v-porta').val(v ? v.porta_olt : 21);
        $('#v-usuario').val(v ? v.usuario_olt : ''); $('#v-senha').val('');
        $('#v-senha-dica').text(v && v.tem_senha ? '••• definida — deixe em branco para manter.' : 'Obrigatória.');
        $('#v-caminho').val(v ? v.caminho_olt : (r ? r.raiz : '/'));
        ZTE.abrirModal('modal-vin');
    }

    function salvarVin() {
        var f = { id: $('#v-id').val() || 0, versao: $('#v-versao').val(), olt_id: $('#v-olt').val(), repositorio_id: $('#v-repo').val(),
                  host_olt: $('#v-host').val(), porta_olt: $('#v-porta').val(), usuario_olt: $('#v-usuario').val(),
                  senha_olt: $('#v-senha').val(), caminho_olt: $('#v-caminho').val() };
        ZTE.loading('Salvando...');
        ZTE.api('vinculo.salvar', f, 'POST')
            .then(function () { $('#v-senha').val(''); ZTE.fecharModal('modal-vin'); ZTE.toast('ok', 'Acesso da OLT salvo.', 'Use "Testar" para verificar a partir da OLT.'); carregar(); })
            .catch(ZTE.erro).finally(ZTE.fimLoading);
    }

    // ------------------------------------------------------------ eventos
    function acaoRepo(acao, r) {
        if (acao === 'testar') {
            ZTE.loading('Testando ' + r.nome + '...');
            ZTE.api('repo.testar', { id: r.id }, 'POST')
                .then(function (x) {
                    mostrar('Teste — ' + r.nome, '<div style="margin-bottom:8px">' + ZTE.badge(x.resultado) + ' <span class="zte-sub zte-mono">' + ZTE.esc(x.correlacao) + '</span></div>' + etapasHtml(x.etapas));
                    carregar();
                }).catch(function (e) { ZTE.erro(e); carregar(); }).finally(ZTE.fimLoading);
        } else if (acao === 'arquivos') {
            abrirArquivos(r, '');
        } else if (acao === 'sincronizar') {
            ZTE.loading('Comparando FTP e catálogo...');
            ZTE.api('repo.sincronizar', { id: r.id }, 'POST')
                .then(function (x) { mostrar('Sincronização — ' + r.nome, syncHtml(x.sincronizacao)); })
                .catch(ZTE.erro).finally(ZTE.fimLoading);
        } else if (acao === 'ultima_sync') {
            ZTE.api('repo.ultima_sincronizacao', { id: r.id }).then(function (x) { mostrar('Última sincronização — ' + r.nome, syncHtml(x.sincronizacao)); }).catch(ZTE.erro);
        } else if (acao === 'historico') {
            ZTE.api('repo.testes', { id: r.id }).then(function (x) { mostrar('Histórico de testes — ' + r.nome, historicoHtml(x.testes)); }).catch(ZTE.erro);
        } else if (acao === 'editar') {
            abrirRepo(r);
        } else if (acao === 'ativar') {
            ZTE.confirmar({ titulo: r.ativo ? 'Desativar repositório' : 'Reativar repositório', textoOk: r.ativo ? 'Desativar' : 'Reativar',
                            msg: (r.ativo ? 'Desativar ' : 'Reativar ') + r.nome + '?' })
                .then(function (ok) { if (ok) ZTE.api('repo.ativar', { id: r.id, ativo: r.ativo ? 0 : 1 }, 'POST').then(carregar).catch(ZTE.erro); });
        } else if (acao === 'remover') {
            ZTE.confirmar({ titulo: 'Remover repositório', perigo: true, digitar: r.nome, textoOk: 'Remover',
                            msg: 'Remover o cadastro de ' + r.nome + '?', sub: 'Os arquivos no FTP não são apagados. Só é possível sem firmwares e sem acessos de OLT.' })
                .then(function (conf) {
                    if (conf) ZTE.api('repo.remover', { id: r.id, confirmacao: conf }, 'POST').then(function () { ZTE.toast('ok', 'Repositório removido.'); carregar(); }).catch(ZTE.erro);
                });
        }
    }

    function acaoVin(acao, v) {
        if (acao === 'vtestar') {
            ZTE.loading('Testando a partir da OLT ' + v.olt_nome + '...');
            ZTE.api('vinculo.testar', { id: v.id }, 'POST')
                .then(function (x) {
                    mostrar('Acesso de ' + v.olt_nome + ' a ' + v.repo_nome, '<div style="margin-bottom:8px">' + ZTE.badge(x.resultado) +
                        ' <span class="zte-sub zte-mono">' + ZTE.esc(x.correlacao) + '</span></div>' + etapasHtml(x.etapas));
                    carregar();
                }).catch(function (e) { ZTE.erro(e); carregar(); }).finally(ZTE.fimLoading);
        } else if (acao === 'vhistorico') {
            ZTE.api('vinculo.testes', { id: v.id }).then(function (x) { mostrar('Histórico — ' + v.olt_nome + ' → ' + v.repo_nome, historicoHtml(x.testes)); }).catch(ZTE.erro);
        } else if (acao === 'veditar') {
            abrirVin(v);
        } else if (acao === 'vremover') {
            ZTE.confirmar({ titulo: 'Remover acesso', perigo: true, textoOk: 'Remover', msg: 'Remover o acesso de ' + v.olt_nome + ' a ' + v.repo_nome + '?',
                            sub: 'A senha da OLT neste FTP também é apagada.' })
                .then(function (ok) { if (ok) ZTE.api('vinculo.remover', { id: v.id }, 'POST').then(carregar).catch(ZTE.erro); });
        }
    }

    $(function () {
        carregar();
        $('#btn-novo-repo').on('click', function () { abrirRepo(null); });
        $('#btn-salvar-repo').on('click', salvarRepo);
        $('#r-protocolo').on('change', function () {
            var p = d.protocolos.filter(function (x) { return x.id === $('#r-protocolo').val(); })[0];
            if (p) $('#r-porta').val(p.porta);
            avisoFtp();
        });
        $('#btn-novo-vin').on('click', function () {
            if (!d.olts.length || !d.repositorios.length) { ZTE.toast('avis', 'Cadastre antes uma OLT e um repositório.'); return; }
            abrirVin(null);
        });
        $('#btn-salvar-vin').on('click', salvarVin);
        $('#zte-repos').on('click', 'button[data-acao]', function () {
            var r = repoPorId(+$(this).attr('data-id')), a = $(this).attr('data-acao');
            if (a !== 'menu') { acaoRepo(a, r); return; }
            var itens = [];
            if (PODE && r.ativo && r.liberado_listar) itens.push({ texto: 'Sincronizar com o catálogo', icone: 'bi-arrow-repeat', acao: function () { acaoRepo('sincronizar', r); } });
            itens.push({ texto: 'Última sincronização', icone: 'bi-list-check', acao: function () { acaoRepo('ultima_sync', r); } },
                       { texto: 'Histórico de testes', icone: 'bi-clock-history', acao: function () { acaoRepo('historico', r); } });
            if (PODE) {
                itens.push('-', { texto: 'Editar', icone: 'bi-pencil', acao: function () { acaoRepo('editar', r); } },
                           { texto: r.ativo ? 'Desativar' : 'Reativar', icone: r.ativo ? 'bi-pause-circle' : 'bi-play-circle', acao: function () { acaoRepo('ativar', r); } },
                           '-', { texto: 'Remover', icone: 'bi-trash', perigo: true, acao: function () { acaoRepo('remover', r); } });
            }
            ZTE.menu(this, itens);
        });
        $('#zte-vins').on('click', 'button[data-acao]', function () {
            var v = vinPorId(+$(this).attr('data-id')), a = $(this).attr('data-acao');
            if (a !== 'vmenu') { acaoVin(a, v); return; }
            var itens = [{ texto: 'Histórico', icone: 'bi-clock-history', acao: function () { acaoVin('vhistorico', v); } }];
            if (PODE) {
                itens.push({ texto: 'Editar', icone: 'bi-pencil', acao: function () { acaoVin('veditar', v); } },
                           '-', { texto: 'Remover', icone: 'bi-trash', perigo: true, acao: function () { acaoVin('vremover', v); } });
            }
            ZTE.menu(this, itens);
        });

        $('#arq-trilha').on('click', 'a[data-cam]', function () { abrirArquivos(nav.repo, $(this).attr('data-cam')); });
        $('#arq-itens').on('click', '.zte-arq-nome.dir', function () { abrirArquivos(nav.repo, $(this).attr('data-cam')); });
        $('#arq-itens').on('click', 'button[data-renomear]', function () {
            var cam = $(this).attr('data-renomear');
            pedirNome('Novo nome', cam.split('/').pop()).then(function (novo) {
                if (!novo) return;
                ZTE.api('repo.renomear', { id: nav.repo.id, caminho: cam, novo_nome: novo }, 'POST')
                    .then(function () { ZTE.toast('ok', 'Renomeado.'); abrirArquivos(nav.repo, nav.caminho); }).catch(ZTE.erro);
            });
        });
        $('#arq-itens').on('click', 'button[data-excluir]', function () {
            var cam = $(this).attr('data-excluir'), nome = cam.split('/').pop();
            ZTE.confirmar({ titulo: 'Excluir arquivo do FTP', perigo: true, digitar: nome, textoOk: 'Excluir',
                            msg: 'Excluir ' + nome + ' do servidor FTP?', sub: 'Não há como desfazer.' })
                .then(function (conf) {
                    if (conf) ZTE.api('repo.excluir_arquivo', { id: nav.repo.id, caminho: cam, confirmacao: conf }, 'POST')
                        .then(function () { ZTE.toast('ok', 'Arquivo excluído.'); abrirArquivos(nav.repo, nav.caminho); }).catch(ZTE.erro);
                });
        });
        $('#btn-nova-pasta').on('click', function () {
            pedirNome('Nova pasta', '').then(function (nome) {
                if (!nome) return;
                ZTE.api('repo.criar_pasta', { id: nav.repo.id, caminho: nav.caminho, nome: nome }, 'POST')
                    .then(function () { ZTE.toast('ok', 'Pasta criada.'); abrirArquivos(nav.repo, nav.caminho); }).catch(ZTE.erro);
            });
        });
    });
})();
</script>
</body>
</html>
