/*
 * zte_onu :: componentes de tela reutilizaveis.
 *
 * Vanilla + jQuery do core, sem framework (padrao do Livro Caixa). Toda chamada ao servidor
 * passa por ZTE.api(): ela poe o token CSRF, trata sessao expirada e devolve sempre o
 * envelope { ok, data, errors, request_id }.
 */
var ZTE = (function ($) {
    'use strict';

    var URL_LOGIN = '/admin/';
    var ROTULOS_RES = { ok: 'OK', aviso: 'Aviso', erro: 'Erro', nao_testavel: 'Não testável',
                        feito: 'Feito', pendente: 'Pendente', em_breve: 'Em breve',
                        validada: 'Validada', somente_leitura: 'Somente leitura', nao_suportada: 'Não suportada',
                        desconhecida: 'Não testada', validado: 'Validado', experimental: 'Experimental',
                        indisponivel: 'Indisponível', simulada: 'Simulada', inativa: 'Inativa', bloqueada: 'Bloqueada',
                        potencial: 'Potencial', validado_ftp: 'Validado', falhou: 'Falhou', sem_cripto: 'Sem criptografia',
                        hash_origem_calculado: 'Hash calculado', enviado: 'Enviado', integridade_remota_verificada: 'Íntegro no FTP',
                        disponivel: 'Disponível', invalido: 'Inválido', incompativel: 'Incompatível', ausente_no_ftp: 'Ausente no FTP',
                        desativado: 'Desativado', sem_origem: 'Sem hash de origem',
                        online: 'Online', offline: 'Offline', desatualizada: 'Desatualizada', em_dia: 'Em dia' };

    function csrf() {
        var m = document.querySelector('meta[name="zte-csrf"]');
        return m ? m.getAttribute('content') : '';
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function pareceLogin(texto) {
        return typeof texto === 'string' &&
            (texto.indexOf('Acesso negado') !== -1 || texto.indexOf('login.hhvm') !== -1);
    }

    /**
     * Chama ajax.php?acao=...  Devolve uma Promise que resolve com `data` e rejeita com
     * { mensagem, codigo, resp }. A mensagem ja vem pronta para mostrar ao usuario.
     */
    function api(acao, dados, metodo) {
        metodo = metodo || 'GET';
        // "acao" e o nome da operacao na URL: um dado com esse nome a sobrescreveria no GET.
        if (dados && Object.prototype.hasOwnProperty.call(dados, 'acao')) {
            return Promise.reject({ mensagem: 'Erro interno da tela: parâmetro "acao" reservado (' + acao + ').', codigo: null, resp: null });
        }
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: 'ajax.php?acao=' + encodeURIComponent(acao),
                method: metodo,
                data: dados || {},
                dataType: 'text',
                headers: metodo === 'POST' ? { 'X-CSRF-Token': csrf() } : {}
            }).always(function (a, status, b) {
                var texto = (status === 'success') ? a : (a && a.responseText);
                if (pareceLogin(texto)) { window.location.href = URL_LOGIN; return; }
                var resp;
                try { resp = JSON.parse(texto); } catch (e) {
                    var trecho = String(texto || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 300);
                    reject({ mensagem: 'Resposta inesperada do servidor.' + (trecho ? ' ' + trecho : ''), codigo: null, resp: null });
                    return;
                }
                if (resp.sessao_expirada) { window.location.href = URL_LOGIN; return; }
                if (resp.ok) { resolve(resp.data, resp); return; }
                var err = (resp.errors && resp.errors[0]) || {};
                reject({ mensagem: (err.message || 'Falha na operação.') + (resp.request_id ? ' (' + resp.request_id + ')' : ''),
                         codigo: err.code || null, detalhes: err.details || {}, resp: resp });
            });
        });
    }

    // ------------------------------------------------------------ loading e toast
    function loading(texto) {
        $('#zte-loading-texto').text(texto || 'Carregando...');
        $('#zte-loading').addClass('ativo');
    }
    function fimLoading() { $('#zte-loading').removeClass('ativo'); }

    /** tipo: ok | info | avis | erro */
    function toast(tipo, texto, sub) {
        var icones = { ok: 'bi-check-circle-fill', info: 'bi-info-circle-fill',
                       avis: 'bi-exclamation-triangle-fill', erro: 'bi-x-octagon-fill' };
        var $t = $('<div class="zte-toast ' + esc(tipo) + '"><i class="bi ' + (icones[tipo] || icones.info) + '"></i><div>' +
                   esc(texto) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</div></div>');
        $t.on('click', function () { $t.remove(); });
        $('#zte-toasts').append($t);
        setTimeout(function () { $t.fadeOut(300, function () { $t.remove(); }); }, tipo === 'erro' ? 9000 : 4500);
    }

    function erro(e) { toast('erro', (e && e.mensagem) || String(e)); }

    // ------------------------------------------------------------ modais
    function abrirModal(id)  { $('#' + id).addClass('ativo'); }
    function fecharModal(id) { $('#' + id).removeClass('ativo'); }
    // Decisao do Marcelo (02/10): modal NAO fecha com clique fora (perdia formulario e deixava a
    // confirmacao sem resposta). Fecha so pelo X, Fechar/Cancelar ou pela acao. Mantida como
    // no-op porque todas as telas a chamam no overlay.
    function fecharSeFora(ev, id) { }

    /**
     * Confirmacao. opts: { titulo, msg, sub, perigo, textoOk, digitar }
     * Com `digitar`, o botao so libera quando o usuario digitar exatamente aquele texto.
     */
    function confirmar(opts) {
        return new Promise(function (resolve) {
            var perigo = !!opts.perigo;
            $('#zte-confirma-titulo').text(opts.titulo || 'Confirmar');
            $('#zte-confirma-cab').toggleClass('lc-modal-header-danger', perigo);
            $('#zte-confirma-icone').attr('class', 'lc-confirm-icon ' + (perigo ? 'excluir' : 'fechar'))
                .html('<i class="bi ' + (perigo ? 'bi-exclamation-triangle' : 'bi-question-lg') + '"></i>');
            $('#zte-confirma-msg').text(opts.msg || '');
            $('#zte-confirma-sub').text(opts.sub || '');
            var $ok = $('#zte-confirma-ok').text(opts.textoOk || 'Confirmar').toggleClass('danger', perigo);
            var $inp = $('#zte-confirma-digitar-input').val('');
            if (opts.digitar) {
                $('#zte-confirma-digitar').show();
                $('#zte-confirma-digitar-rotulo').text('Digite ' + opts.digitar + ' para confirmar');
                $ok.prop('disabled', true);
                $inp.off('input').on('input', function () { $ok.prop('disabled', $inp.val() !== opts.digitar); });
            } else {
                $('#zte-confirma-digitar').hide();
                $ok.prop('disabled', false);
            }
            $ok.off('click').on('click', function () {
                fecharModal('zte-modal-confirma');
                resolve(opts.digitar ? $inp.val() : true);
            });
            $('#zte-modal-confirma .lc-btn-cancel, #zte-modal-confirma .lc-modal-close')
                .off('click.zte').on('click.zte', function () { resolve(false); });
            abrirModal('zte-modal-confirma');
            if (opts.digitar) setTimeout(function () { $inp.trigger('focus'); }, 50);
        });
    }

    // ------------------------------------------------------------ formatacao
    function badge(res) {
        return '<span class="zte-res ' + esc(res) + '">' + esc(ROTULOS_RES[res] || res) + '</span>';
    }

    function dataHora(iso) {
        if (!iso) return '—';
        var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : esc(iso);
    }

    /** Barra de paginacao. onIr(pagina) e chamado no clique. */
    function paginacao($alvo, pagina, porPagina, total, onIr) {
        var paginas = Math.max(1, Math.ceil(total / porPagina));
        $alvo.html(
            '<span>' + total + ' registro(s) — página ' + pagina + ' de ' + paginas + '</span>' +
            '<button type="button" class="lc-btn-outline" data-p="' + (pagina - 1) + '"' + (pagina <= 1 ? ' disabled' : '') + '>&#10094;</button>' +
            '<button type="button" class="lc-btn-outline" data-p="' + (pagina + 1) + '"' + (pagina >= paginas ? ' disabled' : '') + '>&#10095;</button>'
        );
        $alvo.find('button').on('click', function () { onIr(parseInt($(this).attr('data-p'), 10)); });
    }

    /**
     * Menu de acoes "⋯". itens: [{ texto, icone, acao: fn, perigo: bool }] ou '-' para separador.
     * Fica preso ao body com position fixed, alinhado ao botao que o abriu.
     */
    function menu(botao, itens) {
        fecharMenu();
        var $m = $('<div class="zte-menu" id="zte-menu"></div>');
        itens.forEach(function (it) {
            if (it === '-') { $m.append('<hr>'); return; }
            var $b = $('<button type="button"' + (it.perigo ? ' class="perigo"' : '') + '><i class="bi ' + it.icone + '"></i> ' + esc(it.texto) + '</button>');
            $b.on('click', function (ev) { ev.stopPropagation(); fecharMenu(); it.acao(); });
            $m.append($b);
        });
        $('body').append($m);
        var r = botao.getBoundingClientRect();
        var largura = $m.outerWidth(), altura = $m.outerHeight();
        var esquerda = Math.max(8, Math.min(r.right - largura, window.innerWidth - largura - 8));
        var topo = r.bottom + 4 + altura > window.innerHeight ? Math.max(8, r.top - altura - 4) : r.bottom + 4;
        $m.css({ left: esquerda + 'px', top: topo + 'px' });
        setTimeout(function () { $(document).one('click.zteMenu', fecharMenu); }, 0);
        $(window).one('scroll.zteMenu resize.zteMenu', fecharMenu);
    }
    function fecharMenu() { $('#zte-menu').remove(); $(document).off('click.zteMenu'); }

    /**
     * Upload multipart com progresso. onProgresso(pct) recebe 0..100 do envio ao addon; a
     * etapa seguinte (addon -> FTP -> verificacao) nao tem progresso parcial.
     */
    function enviarArquivo(acao, formData, onProgresso) {
        formData.append('csrf', csrf());
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: 'ajax.php?acao=' + encodeURIComponent(acao), method: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'text', headers: { 'X-CSRF-Token': csrf() },
                xhr: function () {
                    var x = $.ajaxSettings.xhr();
                    if (x.upload && onProgresso) {
                        x.upload.addEventListener('progress', function (ev) {
                            if (ev.lengthComputable) onProgresso(Math.round(ev.loaded * 100 / ev.total));
                        });
                    }
                    return x;
                }
            }).always(function (a, status) {
                var texto = (status === 'success') ? a : (a && a.responseText);
                if (pareceLogin(texto)) { window.location.href = URL_LOGIN; return; }
                var resp;
                try { resp = JSON.parse(texto); } catch (e) {
                    reject({ mensagem: 'Resposta inesperada do servidor (o arquivo pode ter excedido o limite do PHP).', codigo: null });
                    return;
                }
                if (resp.sessao_expirada) { window.location.href = URL_LOGIN; return; }
                if (resp.ok) { resolve(resp.data); return; }
                var err = (resp.errors && resp.errors[0]) || {};
                reject({ mensagem: (err.message || 'Falha no envio.') + (resp.request_id ? ' (' + resp.request_id + ')' : ''), codigo: err.code || null });
            });
        });
    }

    function tamanho(n) {
        if (n === null || n === undefined) return '—';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1).replace('.', ',') + ' KB';
        return (n / 1048576).toFixed(1).replace('.', ',') + ' MB';
    }

    /** Linha de estado do worker (Campanhas e Fila). */
    function estadoWorker($alvo) {
        api('worker.estado').then(function (w) {
            var r = w.registro;
            if (!w.cron) {
                $alvo.html('<span class="zte-res erro">Worker sem agendamento</span> <span class="zte-sub">campanhas aprovadas não serão executadas — rode o instalador</span>');
            } else if (!r) {
                $alvo.html('<span class="zte-res nao_testavel">Worker ainda não rodou</span>');
            } else {
                var atrasado = w.idade_s === null || w.idade_s > 180;
                $alvo.html('<span class="zte-res ' + (atrasado ? 'aviso' : (r.resultado === 'erro' ? 'erro' : 'ok')) + '">Worker ' +
                    (atrasado ? 'parado?' : 'ativo') + '</span> <span class="zte-sub" title="' + esc(r.detalhe || '') + '">último ciclo há ' +
                    (w.idade_s === null ? '?' : w.idade_s) + ' s</span>');
            }
        }).catch(function () { $alvo.empty(); });
    }

    /** Download de um objeto como arquivo JSON, gerado no navegador. */
    function baixarJson(nome, obj) {
        var blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    return {
        api: api, esc: esc, loading: loading, fimLoading: fimLoading, toast: toast, erro: erro,
        abrirModal: abrirModal, fecharModal: fecharModal, fecharSeFora: fecharSeFora, confirmar: confirmar,
        badge: badge, dataHora: dataHora, paginacao: paginacao, baixarJson: baixarJson,
        menu: menu, fecharMenu: fecharMenu, enviarArquivo: enviarArquivo, tamanho: tamanho, estadoWorker: estadoWorker
    };
})(jQuery);
