<?php
declare(strict_types=1);

use Peakmon\Models\Loja;

require_once __DIR__ . '/app/sessao.php';
require_once __DIR__ . '/app/autoload.php';
require_once __DIR__ . '/app/Models/Loja.php';

if (!isset($_SESSION['usuario_id'], $_SESSION['perfil_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Sua sessao expirou. Entre novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: perfis.php');
    exit;
}

$idUsuario = (int) $_SESSION['usuario_id'];
$idPerfil = (int) $_SESSION['perfil_id'];
if (empty($_SESSION['csrf_loja'])) {
    $_SESSION['csrf_loja'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_loja'];

try {
    $pdo = require __DIR__ . '/config/database.php';
    $loja = new Loja($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'A solicitacao expirou. Atualize a pagina.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (($_POST['acao'] ?? '') !== 'comprar') {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Acao desconhecida.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $idItem = filter_var($_POST['id_item'] ?? null, FILTER_VALIDATE_INT);
        $quantidade = filter_var($_POST['quantidade'] ?? null, FILTER_VALIDATE_INT);
        if ($idItem === false || $idItem === null || $quantidade === false || $quantidade === null) {
            throw new DomainException('Dados da compra invalidos.');
        }

        $resultado = $loja->comprar($idPerfil, $idUsuario, (int) $idItem, (int) $quantidade);
        echo json_encode(['sucesso' => true] + $resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dadosLoja = $loja->carregarLoja($idPerfil, $idUsuario);
    $itens = $dadosLoja['itens'];
    $dinheiro = $dadosLoja['dinheiro'];
} catch (Throwable $erro) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code($erro instanceof DomainException ? 400 : 500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $erro instanceof DomainException ? $erro->getMessage() : 'Ocorreu um erro ao realizar a compra.',
        ], JSON_UNESCAPED_UNICODE);
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
<style>
:root{--ink:#24332c;--deep:#203d31;--green:#477b55;--mint:#dcebd2;--paper:#f5edda;--paper2:#e8ddc3;--line:#35483c;--muted:#748174;--shadow:#1c2b24;--gold:#d8b96f;--red:#a94e43}
*{box-sizing:border-box}body{margin:0;min-height:100vh;color:var(--ink);font-family:ui-monospace,Consolas,monospace;background:radial-gradient(ellipse at 50% 0,#a9d6a0 0,#6d9c70 48%,#365b48 100%);padding:24px 16px 48px}button,input{font:inherit}button{cursor:pointer}.shell{width:min(1180px,100%);margin:auto}.topbar{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:12px;color:#f8f2df;text-shadow:2px 2px #294433}.brand-mark{width:48px;height:48px;display:grid;place-items:center;background:#f4e9ca;border:3px solid var(--line);box-shadow:4px 4px var(--shadow);font-size:25px}.brand small{display:block;font-size:10px;letter-spacing:2px;color:#e4edcf}.brand h1{font-size:clamp(18px,3vw,27px);margin:2px 0}.back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;background:var(--paper);border:3px solid var(--line);box-shadow:3px 3px var(--shadow);color:var(--ink);text-decoration:none;font-weight:900}.back:hover{background:#fff6df}.frame{background:var(--paper);border:4px solid var(--line);box-shadow:7px 7px var(--shadow);padding:clamp(12px,2.5vw,24px)}.frame-head{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;border-bottom:3px solid var(--line);padding-bottom:12px;margin-bottom:16px}.eyebrow{font-size:10px;letter-spacing:2px;color:var(--green);font-weight:900}.frame-head h2{margin:4px 0 0;font-size:clamp(20px,3vw,29px)}.wallet{background:var(--mint);border:3px solid var(--line);padding:10px 14px;font-weight:900;white-space:nowrap}.layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(270px,.8fr);gap:14px}.panel{border:3px solid var(--line);background:#fff9e9;min-width:0}.panel-title{margin:0;padding:10px 12px;background:var(--deep);color:#fff4d8;font-size:12px;letter-spacing:1px;border-bottom:3px solid var(--line);display:flex;justify-content:space-between;align-items:center}.panel-body{padding:12px}.filters{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}.filter{border:2px solid var(--line);background:var(--paper2);padding:8px 11px;font-size:10px;font-weight:900}.filter.active,.filter:hover{background:var(--green);color:white}.search{width:100%;padding:10px;border:2px solid #899783;background:#fffdf5;margin-bottom:10px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:8px;max-height:570px;overflow:auto;padding:2px}.card-item{position:relative;text-align:left;border:2px solid #9a9b7e;background:#f5edda;padding:10px;min-height:142px;color:var(--ink);display:flex;flex-direction:column;gap:6px}.card-item:hover,.card-item.selected{border:3px solid var(--green);background:#e7f0d9;padding:9px}.item-icon{font-size:27px}.item-name{font-weight:900;font-size:12px;line-height:1.3}.item-desc{font-size:10px;color:#657266;line-height:1.45}.kind{font-size:9px;color:#53745a;text-transform:uppercase;font-weight:900;letter-spacing:1px}.price{margin-top:auto;font-size:12px;font-weight:900;color:#315f42}.detail{min-height:330px;display:flex;flex-direction:column}.detail-inner{padding:16px;display:flex;flex-direction:column;gap:12px;flex:1}.detail-icon{width:76px;height:76px;display:grid;place-items:center;font-size:40px;background:#e5edda;border:3px solid #91a184}.detail h3{margin:0;font-size:20px}.desc{font-size:12px;line-height:1.65;color:#5e6b60;margin:0}.chip{background:#e6e2cc;border:1px solid #a5a58a;padding:6px 8px;font-size:10px;font-weight:900}.label{font-size:10px;font-weight:900;letter-spacing:1px;color:#526c56;display:block;margin-bottom:5px}.field{width:100%;padding:10px;border:2px solid #8d9b83;background:#fffdf5;color:var(--ink)}.primary{border:3px solid var(--line);background:var(--green);color:white;padding:12px 14px;font-weight:900;box-shadow:3px 3px #b7b08f}.primary:hover{background:#315f42}.primary:disabled{opacity:.45;cursor:not-allowed}.total{font-weight:900;font-size:13px;background:#e5edda;border:2px solid #a3ad91;padding:10px}.empty{padding:24px 12px;text-align:center;color:var(--muted);font-size:12px;line-height:1.7}.toast{position:fixed;right:18px;bottom:18px;max-width:min(420px,calc(100% - 36px));padding:13px 16px;background:#203d31;color:white;border:3px solid #f4e9ca;box-shadow:4px 4px #1c2b24;font-size:12px;z-index:10}.toast.error{background:#8e3d35}.hidden{display:none!important}.hint{font-size:10px;color:#6b776b;line-height:1.6}
@media(max-width:800px){.layout{grid-template-columns:1fr}.detail{min-height:unset}.grid{max-height:400px}}@media(max-width:480px){body{padding:12px 8px 32px}.frame-head{align-items:flex-start;flex-direction:column}.wallet{white-space:normal}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<div class="shell">
<header class="topbar">
    <div class="brand"><div class="brand-mark">🛒</div><div><small>PEAKMON · THE BIGOLIN CRISIS</small><h1>Mercado do Treinador</h1></div></div>
    <a class="back" href="mapa.php">← VOLTAR AO MAPA</a>
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
                        data-status="<?= $h($item['status_curado'] ?? '') ?>">
                        <span class="item-icon"><?= $iconeItem((string) $item['tipo_efeito']) ?></span>
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
                <div class="detail-icon" id="icone"><?= $iconeItem((string) $itens[0]['tipo_efeito']) ?></div>
                <div><h3 id="nome"><?= $h($itens[0]['nome']) ?></h3><p class="desc" id="descricao"><?= $h($itens[0]['descricao']) ?></p></div>
                <div><span class="chip" id="categoria"><?= $h($rotuloTipo((string) $itens[0]['tipo_efeito'])) ?></span> <span class="chip" id="preco-unitario">Preco: <?= number_format((int) $itens[0]['preco'], 0, ',', '.') ?> P$</span></div>
                <div><label class="label" for="quantidade">QUANTIDADE</label><input class="field" type="number" id="quantidade" min="1" max="99" value="1"></div>
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
        document.getElementById('icone').textContent = icones[selecionado.tipo] || '🎒';
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
    document.querySelectorAll('[data-filter]').forEach(btn => btn.addEventListener('click', () => {
        document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('active', b === btn));
        const filtro = btn.dataset.filter;
        cards.forEach(card => {
            const tipo = card.dataset.tipo;
            const grupo = tipo === 'CURA_HP' || tipo === 'CURA_TOTAL' || tipo === 'REVIVER' ? 'CURA' : tipo === 'CURA_STATUS' ? 'STATUS' : tipo === 'PEDRA_EVOLUCAO' ? 'EVOLUCAO' : 'OUTROS';
            card.classList.toggle('hidden', filtro !== 'TODOS' && grupo !== filtro);
        });
    }));
    document.getElementById('buscar')?.addEventListener('input', e => {
        const termo = e.target.value.toLocaleLowerCase('pt-BR');
        cards.forEach(card => card.classList.toggle('hidden', !card.dataset.nome.toLocaleLowerCase('pt-BR').includes(termo)));
    });
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
            const resposta = await fetch('loja.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
            const resultado = await resposta.json();
            if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Nao foi possivel concluir a compra.');
            saldoAtual = Number(resultado.dinheiro);
            saldoEl.textContent = fmt(saldoAtual);
            toast(resultado.mensagem + ' Total: ' + fmt(resultado.total) + ' P$.');
            setTimeout(() => { window.location.reload(); }, 1000);
        } catch (erro) {
            toast(erro.message, true);
            botao.disabled = false;
        }
    });
})();
</script>
</body>
</html>
