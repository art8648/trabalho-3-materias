<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


use Peakmon\Models\Loja;

require PEAKMON_ROOT . '/app/sessao.php';
require PEAKMON_ROOT . '/app/autoload.php';

if (!isset($_SESSION['usuario_id'], $_SESSION['perfil_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sua sessao expirou. Entre novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: index.php?pagina=perfis');
    exit;
}

$idUsuario = (int) $_SESSION['usuario_id'];
$idPerfil = (int) $_SESSION['perfil_id'];
if (empty($_SESSION['csrf_loja'])) {
    $_SESSION['csrf_loja'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_loja'];

try {
    $pdo = require PEAKMON_ROOT . '/config/database.php';
    $controller = new \Peakmon\Controllers\LojaController($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $resultado = $controller->processarAcao($idPerfil, $idUsuario, $csrf, $_POST);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dadosLoja = $controller->prepararPagina($idPerfil, $idUsuario);
    $itens = $dadosLoja['itens'];
    $dinheiro = $dadosLoja['dinheiro'];
} catch (Throwable $erro) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code($erro instanceof DomainException ? ($erro->getCode() === 403 ? 403 : 400) : 500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => $erro instanceof DomainException ? $erro->getMessage() : 'Ocorreu um erro ao realizar a compra.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    $erroPagina = $erro instanceof DomainException ? $erro->getMessage() : 'Nao foi possivel carregar a loja. Confira a conexao com o banco.';
    $itens = [];
    $dinheiro = 0;
}

$h = static fn($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
$iconeItem = static function (string $tipo): string {
    return match ($tipo) {
        'CURA_HP', 'CURA_TOTAL' => '🧪',
        'CURA_STATUS' => '💠',
        'REVIVER' => '✨',
        'PEDRA_EVOLUCAO' => '🔮',
        default => '🎒',
    };
};
$imagemItem = static function (string $nome): string {
    static $mapa = [
        'Potion' => 'potion.jpg', 'Super Potion' => 'super_potion.webp',
        'Hyper Potion' => 'hyper_potion.webp', 'Max Potion' => 'max_potion.webp',
        'Full Restore' => 'full_restore.jpg', 'Antidote' => 'antidote.webp',
        'Burn Heal' => 'burn_heal.webp', 'Ice Heal' => 'ice_heal.webp',
        'Awakening' => 'awakening.webp', 'Paralyze Heal' => 'paralyze_heal.webp',
        'Full Heal' => 'full_heal.webp', 'Revive' => 'revive.webp',
        'Pedra Universal' => 'pedra_universal.png',
    ];
    return isset($mapa[$nome]) ? 'images/itens/' . $mapa[$nome] : '';
};
$iconeHtml = static function (array $item) use ($imagemItem, $iconeItem, $h): string {
    $src = $imagemItem((string) $item['nome']);
    return $src !== ''
        ? '<img src="' . $h($src) . '" alt="' . $h($item['nome']) . '" loading="lazy">'
        : $iconeItem((string) $item['tipo_efeito']);
};
$rotuloTipo = static function (string $tipo): string {
    return match ($tipo) {
        'CURA_HP', 'CURA_TOTAL', 'REVIVER' => 'Cura',
        'CURA_STATUS' => 'Status',
        'PEDRA_EVOLUCAO' => 'Evolucao',
        default => 'Outros',
    };
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loja | Peakmon</title>
<link rel="stylesheet" href="assets/css/loja.css?v=<?= (int) @filemtime(PEAKMON_ROOT . '/assets/css/loja.css') ?>">
<link rel="stylesheet" href="assets/css/loja-complemento.css">
</head>
<body>
<div class="shell">
<header class="topbar">
    <div class="brand"><div class="brand-mark">🛒</div><div><small>PEAKMON · THE BIGOLIN CRISIS</small><h1>Mercado do Treinador</h1></div></div>
    <a class="back" href="index.php?pagina=mapa">← VOLTAR AO MAPA</a>
</header>
<main class="frame">
    <div class="frame-head"><div><div class="eyebrow">SUPRIMENTOS PARA SUA JORNADA</div><h2>Loja</h2></div><div class="wallet">🪙 <span id="saldo"><?= number_format($dinheiro, 0, ',', '.') ?></span> Pokédollars</div></div>
    <?php if (isset($erroPagina)): ?><div class="empty"><?= $h($erroPagina) ?></div><?php endif; ?>
    <section class="layout">
        <div class="panel">
            <h3 class="panel-title"><span>CATALOGO</span><span><?= count($itens) ?> ITENS</span></h3>
            <div class="panel-body">
                <div class="filters">
                    <button type="button" class="filter active" data-filter="TODOS">Todos</button>
                    <button type="button" class="filter" data-filter="CURA">Cura</button>
                    <button type="button" class="filter" data-filter="STATUS">Status</button>
                    <button type="button" class="filter" data-filter="EVOLUCAO">Evolucao</button>
                </div>
                <input class="search" id="buscar" type="search" placeholder="Pesquisar item..." autocomplete="off">
                <div class="grid" id="catalogo">
                <?php foreach ($itens as $indice => $item): ?>
                    <button type="button" class="card-item <?= $indice === 0 ? 'selected' : '' ?>"
                        data-id="<?= (int) $item['id'] ?>" data-nome="<?= $h($item['nome']) ?>"
                        data-descricao="<?= $h($item['descricao']) ?>" data-tipo="<?= $h($item['tipo_efeito']) ?>"
                        data-preco="<?= (int) $item['preco'] ?>" data-valor="<?= $h($item['valor_efeito'] ?? '') ?>"
                        data-status="<?= $h($item['status_curado'] ?? '') ?>"
                        data-imagem="<?= $h($imagemItem((string) $item['nome'])) ?>">
                        <span class="item-icon"><?= $iconeHtml($item) ?></span>
                        <span class="item-name"><?= $h($item['nome']) ?></span>
                        <span class="kind"><?= $h($rotuloTipo((string) $item['tipo_efeito'])) ?></span>
                        <span class="item-desc"><?= $h($item['descricao']) ?></span>
                        <span class="price">🪙 <?= number_format((int) $item['preco'], 0, ',', '.') ?> P$</span>
                    </button>
                <?php endforeach; ?>
                <?php if (!$itens): ?><div class="empty" style="grid-column:1/-1">Nenhum item disponivel no momento.</div><?php endif; ?>
                </div>
            </div>
        </div>
        <aside class="panel detail" aria-live="polite">
            <h3 class="panel-title"><span>DETALHES DA COMPRA</span><span>INFO</span></h3>
            <div class="detail-inner" id="detalhes">
                <?php if ($itens): ?>
                <div class="detail-icon" id="icone"><?= $iconeHtml($itens[0]) ?></div>
                <div><h3 id="nome"><?= $h($itens[0]['nome']) ?></h3><p class="desc" id="descricao"><?= $h($itens[0]['descricao']) ?></p></div>
                <div><span class="chip" id="categoria"><?= $h($rotuloTipo((string) $itens[0]['tipo_efeito'])) ?></span> <span class="chip" id="preco-unitario">Preco: <?= number_format((int) $itens[0]['preco'], 0, ',', '.') ?> P$</span></div>
                <div><label class="label" for="quantidade">QUANTIDADE</label><div class="quantity-control"><button type="button" id="diminuir-quantidade" aria-label="Diminuir quantidade">−</button><input class="field" type="text" id="quantidade" inputmode="numeric" pattern="[0-9]*" autocomplete="off" value="1" aria-label="Quantidade"><button type="button" id="aumentar-quantidade" aria-label="Aumentar quantidade">+</button></div></div>
                <div class="total">TOTAL: <span id="total"><?= number_format((int) $itens[0]['preco'], 0, ',', '.') ?></span> P$</div>
                <button class="primary" id="comprar" type="button" style="width:100%">COMPRAR</button>
                <p class="hint">A compra adiciona os itens diretamente a sua mochila. O saldo e atualizado automaticamente.</p>
                <?php else: ?><div class="empty">Selecione um item para consultar os detalhes.</div><?php endif; ?>
            </div>
        </aside>
    </section>
</main>
</div>
<div class="toast hidden" id="toast" role="status"></div>
<template id="modelo-imagem-loja"><img alt=""></template>
<script>
(() => {
    const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
    const saldoEl = document.getElementById('saldo');
    const cards = [...document.querySelectorAll('.card-item')];
    const toastEl = document.getElementById('toast');
    const fmt = n => Number(n).toLocaleString('pt-BR');
    const icones = {CURA_HP:'🧪',CURA_TOTAL:'🧪',CURA_STATUS:'💠',REVIVER:'✨',PEDRA_EVOLUCAO:'🔮'};
    const categorias = {CURA_HP:'Cura',CURA_TOTAL:'Cura',REVIVER:'Cura',CURA_STATUS:'Status',PEDRA_EVOLUCAO:'Evolucao'};
    let selecionado = null;
    let saldoAtual = Number(<?= (int) $dinheiro ?>);

    function toast(msg, erro=false) {
        toastEl.textContent = msg;
        toastEl.classList.remove('hidden','error');
        if (erro) toastEl.classList.add('error');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => toastEl.classList.add('hidden'), 3000);
    }
    function atualizarTotal() {
        const quantidade = Math.max(1, Math.min(99, Number(document.getElementById('quantidade')?.value || 1)));
        if (selecionado) document.getElementById('total').textContent = fmt(selecionado.preco * quantidade);
    }
    function selecionar(card) {
        if (!card) return;
        cards.forEach(c => c.classList.toggle('selected', c === card));
        selecionado = {id:Number(card.dataset.id),nome:card.dataset.nome,descricao:card.dataset.descricao,tipo:card.dataset.tipo,preco:Number(card.dataset.preco)};
        const caixaIcone = document.getElementById('icone');
        if (card.dataset.imagem) {
            caixaIcone.innerHTML = '';
            const imgEl = document.getElementById('modelo-imagem-loja').content.firstElementChild.cloneNode(true);
            imgEl.src = card.dataset.imagem;
            imgEl.alt = selecionado.nome;
            caixaIcone.appendChild(imgEl);
        } else {
            caixaIcone.textContent = icones[selecionado.tipo] || '🎒';
        }
        document.getElementById('nome').textContent = selecionado.nome;
        document.getElementById('descricao').textContent = selecionado.descricao;
        document.getElementById('categoria').textContent = categorias[selecionado.tipo] || 'Outros';
        document.getElementById('preco-unitario').textContent = 'Preco: ' + fmt(selecionado.preco) + ' P$';
        document.getElementById('quantidade').value = 1;
        atualizarTotal();
    }
    cards.forEach(card => card.addEventListener('click', () => selecionar(card)));
    if (cards.length) selecionar(cards[0]);
    document.getElementById('quantidade')?.addEventListener('input', atualizarTotal);
    let filtroAtual = 'TODOS';
    let termoBusca = '';
    const aplicarFiltros = () => cards.forEach(card => {
        const tipo = card.dataset.tipo;
        const grupo = tipo === 'CURA_HP' || tipo === 'CURA_TOTAL' || tipo === 'REVIVER' ? 'CURA' : tipo === 'CURA_STATUS' ? 'STATUS' : tipo === 'PEDRA_EVOLUCAO' ? 'EVOLUCAO' : 'OUTROS';
        const categoriaOk = filtroAtual === 'TODOS' || grupo === filtroAtual;
        const buscaOk = card.dataset.nome.toLocaleLowerCase('pt-BR').includes(termoBusca);
        card.classList.toggle('hidden', !(categoriaOk && buscaOk));
    });
    document.querySelectorAll('[data-filter]').forEach(btn => btn.addEventListener('click', () => {
        document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('active', b === btn));
        filtroAtual = btn.dataset.filter;
        aplicarFiltros();
    }));
    document.getElementById('buscar')?.addEventListener('input', e => {
        termoBusca = e.target.value.toLocaleLowerCase('pt-BR');
        aplicarFiltros();
    });
    const campoQuantidade = document.getElementById('quantidade');
    const botaoDiminuir = document.getElementById('diminuir-quantidade');
    const botaoAumentar = document.getElementById('aumentar-quantidade');

    function definirQuantidade(valor) {
        const apenasDigitos = String(valor).replace(/[^0-9]/g, '');
        let quantidade = Number.parseInt(apenasDigitos, 10);
        if (!Number.isFinite(quantidade)) quantidade = 1;
        quantidade = Math.max(1, Math.min(99, quantidade));
        campoQuantidade.value = String(quantidade);
        atualizarTotal();
    }

    botaoDiminuir?.addEventListener('click', () => {
        const atual = Number.parseInt(campoQuantidade.value, 10) || 1;
        definirQuantidade(atual <= 1 ? 99 : atual - 1);
    });

    botaoAumentar?.addEventListener('click', () => {
        const atual = Number.parseInt(campoQuantidade.value, 10) || 1;
        definirQuantidade(atual >= 99 ? 1 : atual + 1);
    });

    campoQuantidade?.addEventListener('input', () => {
        const somenteNumeros = campoQuantidade.value.replace(/[^0-9]/g, '');
        if (campoQuantidade.value !== somenteNumeros) campoQuantidade.value = somenteNumeros;
        if (somenteNumeros !== '' && Number(somenteNumeros) > 99) campoQuantidade.value = '99';
        atualizarTotal();
    });

    campoQuantidade?.addEventListener('change', () => definirQuantidade(campoQuantidade.value));
    campoQuantidade?.addEventListener('blur', () => definirQuantidade(campoQuantidade.value));

    document.getElementById('comprar')?.addEventListener('click', async () => {
        if (!selecionado) return toast('Selecione um item.', true);
        const quantidade = Number(document.getElementById('quantidade').value);
        if (!Number.isInteger(quantidade) || quantidade < 1 || quantidade > 99) return toast('Escolha uma quantidade entre 1 e 99.', true);
        const total = selecionado.preco * quantidade;
        if (total > saldoAtual) return toast('Voce nao tem Pokédollars suficientes para essa compra.', true);
        const botao = document.getElementById('comprar');
        botao.disabled = true;
        try {
            const body = new URLSearchParams({acao:'comprar',csrf,id_item:String(selecionado.id),quantidade:String(quantidade)});
            const resposta = await fetch('index.php?pagina=loja',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
            const resultado = await resposta.json();
            if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Nao foi possivel concluir a compra.');
            saldoAtual = Number(resultado.dinheiro);
            saldoEl.textContent = fmt(saldoAtual);
            toast(resultado.mensagem + ' Total: ' + fmt(resultado.total) + ' P$.');
            // Mantem o item selecionado, o filtro, a busca e a posicao do catalogo.
            document.getElementById('quantidade').value = '1';
            atualizarTotal();
            botao.disabled = false;
        } catch (erro) {
            toast(erro.message, true);
            botao.disabled = false;
        }
    });
})();
</script>
</body>
</html>
