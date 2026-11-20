/* Dialogo seguro montado com <template> escrito no HTML (sem createElement). */
(function () {
    'use strict';
    let aberto = false;
    function dialogo(mensagem, opcoes) {
        return new Promise((resolver) => {
            if (aberto) { resolver(false); return; }
            const modelo = document.getElementById('peak-dialog-template');
            if (!modelo || !modelo.content.firstElementChild) {
                // Sem a estrutura HTML esperada, nao executa uma acao irreversivel.
                resolver(false);
                return;
            }
            aberto = true;
            const anterior = document.activeElement;
            const caixa = modelo.content.firstElementChild.cloneNode(true);
            const titulo = caixa.querySelector('.peak-dialog-title');
            const corpo = caixa.querySelector('.peak-dialog-text');
            const confirmar = caixa.querySelector('.peak-dialog-ok');
            let cancelar = caixa.querySelector('.peak-dialog-cancel');
            titulo.textContent = opcoes.title || 'Confirmação';
            corpo.textContent = mensagem;
            confirmar.textContent = opcoes.okText || 'Confirmar';
            if (opcoes.alertOnly) {
                cancelar.remove();
                cancelar = null;
            } else {
                cancelar.textContent = opcoes.cancelText || 'Cancelar';
            }
            document.body.appendChild(caixa);
            const estadoAnterior = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            const fechar = (resultado) => {
                document.removeEventListener('keydown', tecla, true);
                document.body.style.overflow = estadoAnterior;
                caixa.remove();
                aberto = false;
                if (anterior && typeof anterior.focus === 'function' && anterior.isConnected) anterior.focus();
                resolver(resultado);
            };
            const tecla = (event) => {
                if (event.key === 'Escape') { event.preventDefault(); fechar(false); }
                if (event.key === 'Tab') {
                    const foco = cancelar ? [confirmar, cancelar] : [confirmar];
                    const i = foco.indexOf(document.activeElement);
                    if (event.shiftKey && i === 0) { event.preventDefault(); foco[foco.length - 1].focus(); }
                    if (!event.shiftKey && i === foco.length - 1) { event.preventDefault(); foco[0].focus(); }
                }
            };
            confirmar.addEventListener('click', () => fechar(true));
            if (cancelar) cancelar.addEventListener('click', () => fechar(false));
            document.addEventListener('keydown', tecla, true);
            confirmar.focus();
        });
    }
    window.PeakUI = {
        confirm: (mensagem, opcoes = {}) => dialogo(mensagem, opcoes),
        alert: (mensagem, opcoes = {}) => dialogo(mensagem, {...opcoes, alertOnly: true, okText: opcoes.okText || 'Entendi'})
    };
})();
