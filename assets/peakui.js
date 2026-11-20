/* Peakmon: dialogos personalizados. Nenhum resultado e decidido no navegador. */
(function () {
    'use strict';
    let aberto = false;
    function dialogo(mensagem, opcoes) {
        return new Promise((resolver) => {
            if (aberto) { resolver(false); return; }
            aberto = true;
            const anterior = document.activeElement;
            const caixa = document.createElement('div');
            caixa.className = 'peak-dialog-backdrop';
            const janela = document.createElement('section');
            janela.className = 'peak-dialog';
            janela.setAttribute('role', 'dialog');
            janela.setAttribute('aria-modal', 'true');
            const titulo = document.createElement('h2');
            titulo.className = 'peak-dialog-title';
            titulo.textContent = opcoes.title || 'Confirmação';
            const corpo = document.createElement('p');
            corpo.className = 'peak-dialog-text';
            corpo.textContent = mensagem;
            const botoes = document.createElement('div');
            botoes.className = 'peak-dialog-actions';
            const confirmar = document.createElement('button');
            confirmar.type = 'button';
            confirmar.className = 'peak-dialog-ok';
            confirmar.textContent = opcoes.okText || 'Confirmar';
            let cancelar = null;
            if (!opcoes.alertOnly) {
                cancelar = document.createElement('button');
                cancelar.type = 'button';
                cancelar.className = 'peak-dialog-cancel';
                cancelar.textContent = opcoes.cancelText || 'Cancelar';
            }
            botoes.appendChild(confirmar);
            if (cancelar) botoes.appendChild(cancelar);
            janela.append(titulo, corpo, botoes);
            caixa.appendChild(janela);
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
