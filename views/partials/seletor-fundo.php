<?php
// Botao + painel para trocar o fundo da tela (parte de tras da pagina). Compartilhado pelo mapa e pela batalha.
// Precisa de $escapar e PEAKMON_ROOT, que as duas paginas ja definem.
?>
<?php
// Fundos alem do "Padrao". Imagens f1 a f4 em images/fundos/; so aparecem as que existem no disco.
$fundosDaTela = [
    'images/fundos/f1.png' => 'Oceano',
    'images/fundos/f2.png' => 'Squirtle neon',
    'images/fundos/f3.png' => 'Noite com Pikachu',
    'images/fundos/f4.png' => 'Lagoa',
];
?>
<div class="fundo-seletor" data-fundo-seletor>
    <div class="fundo-painel" role="group" aria-label="Escolher o fundo da tela" hidden>
        <strong>FUNDO DA TELA</strong>
        <div class="fundo-opcoes">
            <button type="button" class="fundo-opcao" data-fundo="padrao" aria-pressed="false">
                <span class="fundo-mini fundo-mini-padrao"></span><em>Padrão</em>
            </button>
            <?php foreach ($fundosDaTela as $arquivo => $nomeFundo): ?>
                <?php if (is_file(PEAKMON_ROOT . '/' . $arquivo)): ?>
                    <button type="button" class="fundo-opcao" data-fundo="<?= $escapar($arquivo) ?>" aria-pressed="false">
                        <span class="fundo-mini" style="background-image: url('<?= $escapar($arquivo) ?>');"></span><em><?= $escapar($nomeFundo) ?></em>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <button type="button" class="fundo-botao" data-fundo-abrir aria-expanded="false" title="Trocar o fundo da tela">🖼 Fundo</button>
</div>