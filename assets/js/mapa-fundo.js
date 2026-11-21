/* Troca o fundo da tela do mapa (so a parte de tras da pagina). Sem getElementById: usa querySelector. */
(() => {
    const CHAVE = 'peakmon_fundo_mapa';
    const PADRAO = 'padrao';
    const camada = document.querySelector('.fundo-mapa');
    if (!camada) return;

    // So aceita caminhos dentro de images/fundos/ (o valor vem do armazenamento do navegador).
    const valido = (valor) => valor === PADRAO || /^images\/fundos\/[\w.-]+$/.test(valor || '');
    const ler = () => { try { const v = localStorage.getItem(CHAVE); return valido(v) ? v : PADRAO; } catch (_) { return PADRAO; } };
    const gravar = (v) => { try { localStorage.setItem(CHAVE, v); } catch (_) { /* armazenamento bloqueado */ } };

    const aplicar = (valor) => {
        if (valor === PADRAO) {
            camada.classList.remove('com-imagem');
            camada.style.backgroundImage = '';
        } else {
            camada.style.backgroundImage = 'url("' + valor + '")';
            camada.classList.add('com-imagem');
        }
    };

    // Aplica logo (este script roda no topo do body), para a tela nao piscar com o fundo antigo.
    let atual = ler();
    aplicar(atual);

    const ligarSeletor = () => {
        const seletor = document.querySelector('[data-fundo-seletor]');
        if (!seletor) return;
        const botao = seletor.querySelector('[data-fundo-abrir]');
        const painel = seletor.querySelector('.fundo-painel');
        const opcoes = [...seletor.querySelectorAll('[data-fundo]')];

        const marcar = () => opcoes.forEach((o) => {
            const ativo = o.dataset.fundo === atual;
            o.classList.toggle('ativo', ativo);
            o.setAttribute('aria-pressed', String(ativo));
        });
        const abrir = (aberto) => {
            painel.hidden = !aberto;
            botao.setAttribute('aria-expanded', String(aberto));
        };

        // Se o fundo salvo nao existe mais entre as opcoes (ex.: imagem renomeada), volta ao padrao.
        if (!opcoes.some((o) => o.dataset.fundo === atual)) { atual = PADRAO; aplicar(atual); gravar(atual); }
        marcar();
        botao.addEventListener('click', () => abrir(painel.hidden));
        opcoes.forEach((o) => o.addEventListener('click', () => {
            if (!valido(o.dataset.fundo)) return;
            atual = o.dataset.fundo;
            aplicar(atual);
            gravar(atual);
            marcar();
        }));
        document.addEventListener('click', (e) => { if (!painel.hidden && !seletor.contains(e.target)) abrir(false); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !painel.hidden) { abrir(false); botao.focus(); } });
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ligarSeletor);
    else ligarSeletor();
})();
