<?php
/**
 * zte_onu :: elementos fixos de toda pagina — loading, toasts e o modal de confirmacao.
 *
 * Fica DEPOIS do baixo.php, como filho direto do <body>: dentro do wrapper do addon, um
 * stacking context prende o modal abaixo do #sistema-rodape e o botao para de responder
 * ao clique (addon-mkauth-anatomia).
 */
?>
<div class="lc-loading-overlay" id="zte-loading">
    <div class="lc-loading-box">
        <div class="lc-loading-spinner"></div>
        <div class="lc-loading-text" id="zte-loading-texto">Carregando...</div>
    </div>
</div>

<div id="zte-toasts" class="zte-toasts"></div>

<div class="lc-overlay" id="zte-modal-confirma" onclick="ZTE.fecharSeFora(event, 'zte-modal-confirma')">
    <div class="lc-modal-box">
        <div class="lc-modal-header" id="zte-confirma-cab">
            <span class="lc-modal-title" id="zte-confirma-titulo">Confirmar</span>
            <button type="button" class="lc-modal-close" onclick="ZTE.fecharModal('zte-modal-confirma')">&times;</button>
        </div>
        <div class="lc-modal-body" style="text-align:center">
            <div class="lc-confirm-icon fechar" id="zte-confirma-icone"><i class="bi bi-question-lg"></i></div>
            <div class="lc-confirm-msg" id="zte-confirma-msg"></div>
            <p class="lc-confirm-sub" id="zte-confirma-sub"></p>
            <div id="zte-confirma-digitar" style="display:none;margin-top:12px;text-align:left">
                <label class="lc-label" id="zte-confirma-digitar-rotulo"></label>
                <input type="text" class="lc-input" id="zte-confirma-digitar-input" autocomplete="off">
            </div>
        </div>
        <div class="lc-modal-footer">
            <button type="button" class="lc-btn-cancel" onclick="ZTE.fecharModal('zte-modal-confirma')">Cancelar</button>
            <button type="button" class="lc-btn-confirm" id="zte-confirma-ok">Confirmar</button>
        </div>
    </div>
</div>

<div style="position: fixed; bottom: 10px; right: 15px; font-size: 11px; color: #9ca3af; z-index: 9999; font-family: 'Inter', sans-serif; pointer-events: none;">
    By Marcelo Silvestro
</div>
