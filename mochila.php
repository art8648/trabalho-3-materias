<?php
declare(strict_types=1);

use Peakmon\Models\Mochila;
use Peakmon\Models\Time;

require_once __DIR__ . '/app/sessao.php';
require_once __DIR__ . '/app/autoload.php';
require_once __DIR__ . '/app/Models/Mochila.php';

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
if (empty($_SESSION['csrf_mochila'])) {
    $_SESSION['csrf_mochila'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_mochila'];

try {
    $pdo = require __DIR__ . '/config/database.php';
    $mochila = new Mochila($pdo);
    $time = new Time($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'A solicitacao expirou. Atualize a pagina.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Evita que uma segunda aba altere HP, itens ou time durante uma luta em rodadas.
        if (!empty($_SESSION['peakmon_batalha_interativa'][$idPerfil])) {
            throw new DomainException('Ha uma batalha em andamento. Use os itens na tela de batalha ou termine a luta.');
        }
        $acao = (string) ($_POST['acao'] ?? '');
        $id = static function (string $campo): int {
            $valor = filter_var($_POST[$campo] ?? null, FILTER_VALIDATE_INT);
            if ($valor === false || $valor === null || $valor <= 0) {
                throw new DomainException('Dados invalidos. Atualize a pagina e tente novamente.');
            }
            return (int) $valor;
        };

        switch ($acao) {
            case 'usar_item':
                $mensagem = $mochila->usarItem($idPerfil, $idUsuario, $id('id_item'), $id('id_capturado'));
                echo json_encode(['sucesso' => true, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
                exit;
            case 'trocar_reserva':
                $mochila->trocarComReserva($idPerfil, $idUsuario, $id('id_capturado'), $id('posicao'));
                echo json_encode(['sucesso' => true, 'mensagem' => 'Equipe atualizada! O antigo integrante foi para o Arquivo Bigolin.'], JSON_UNESCAPED_UNICODE);
                exit;
            case 'enviar_reserva':
                $mochila->enviarParaReserva($idPerfil, $idUsuario, $id('posicao'));
                echo json_encode(['sucesso' => true, 'mensagem' => 'Pokemon enviado para o Arquivo Bigolin.'], JSON_UNESCAPED_UNICODE);
                exit;
            case 'mover_time':
                $time->mover($idPerfil, $idUsuario, $id('de'), $id('para'));
                echo json_encode(['sucesso' => true, 'mensagem' => 'Ordem da equipe atualizada!'], JSON_UNESCAPED_UNICODE);
                exit;
            case 'listar_evolucoes':
                $opcoes = $mochila->listarEvolucoes($idPerfil, $idUsuario, $id('id_capturado'));
                echo json_encode(['sucesso' => true, 'evolucoes' => $opcoes], JSON_UNESCAPED_UNICODE);
                exit;
            case 'evoluir':
                $mensagem = $mochila->evoluirComPedra($idPerfil, $idUsuario, $id('id_capturado'), $id('id_destino'));
                echo json_encode(['sucesso' => true, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
                exit;
            default:
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'mensagem' => 'Acao desconhecida.'], JSON_UNESCAPED_UNICODE);
                exit;
        }
    }

    $itens = $mochila->listarItens($idPerfil, $idUsuario);
    $equipe = $mochila->listarEquipe($idPerfil, $idUsuario);
    $reserva = $mochila->listarReserva($idPerfil, $idUsuario);
    $todosPokemon = [];
    foreach ($equipe as $pokemon) {
        if ($pokemon !== null) {
            $todosPokemon[] = $pokemon;
        }
    }
    foreach ($reserva as $pokemon) {
        $todosPokemon[] = $pokemon;
    }
    usort($todosPokemon, static fn(array $a, array $b): int => strcasecmp((string) $a['nome'], (string) $b['nome']));
} catch (Throwable $erro) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code($erro instanceof DomainException ? 400 : 500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => $erro instanceof DomainException ? $erro->getMessage() : 'Ocorreu um erro ao acessar a mochila.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    $erroPagina = $erro instanceof DomainException ? $erro->getMessage() : 'Nao foi possivel carregar a mochila. Confira a conexao com o banco.';
    $itens = $equipe = $reserva = $todosPokemon = [];
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
$imagemPokemon = static function (array $pokemon) use ($h): string {
    $src = trim((string) ($pokemon['imagem'] ?? ''));
    if ($src === '') return '';
    return '<img class="pokemon-sprite" src="' . $h($src) . '" alt="' . $h($pokemon['nome'] ?? 'Pokemon') . '" loading="lazy" onerror="this.hidden=true">';
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mochila | Peakmon</title>
<link rel="stylesheet" href="mochila.css">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand"><div class="brand-mark">🎒</div><div><small>PEAKMON · THE BIGOLIN CRISIS</small><h1>Central do Treinador</h1></div></div>
        <a class="back" href="mapa.php">← VOLTAR AO MAPA</a>
    </header>

    <main class="frame">
        <div class="frame-head"><div><div class="eyebrow">SEU EQUIPAMENTO E SEUS PARCEIROS</div><h2>Mochila</h2></div><div class="save-tag">ARQUIVO BIGOLIN · DADOS SALVOS</div></div>
        <?php if (isset($erroPagina)): ?><div class="error-box"><?= $h($erroPagina) ?></div><?php endif; ?>

        <div class="tabs" role="tablist" aria-label="Categorias de itens">
            <button class="tab active" type="button" data-filter="TODOS">Todos</button>
            <button class="tab" type="button" data-filter="CURA">Cura</button>
            <button class="tab" type="button" data-filter="STATUS">Status</button>
            <button class="tab" type="button" data-filter="EVOLUCAO">Evolucao</button>
        </div>

        <section class="inventory-layout">
            <div class="panel">
                <h3 class="panel-title"><span>ITENS DISPONIVEIS</span><span><?= count($itens) ?> TIPOS</span></h3>
                <div class="panel-body">
                    <input class="search" id="buscar-item" type="search" placeholder="Pesquisar item..." autocomplete="off">
                    <div class="item-grid" id="lista-itens">
                        <?php foreach ($itens as $indice => $item): ?>
                            <button type="button" class="item-card <?= $indice === 0 ? 'selected' : '' ?>"
                                data-id="<?= (int) $item['id'] ?>"
                                data-nome="<?= $h($item['nome']) ?>"
                                data-descricao="<?= $h($item['descricao']) ?>"
                                data-tipo="<?= $h($item['tipo_efeito']) ?>"
                                data-quantidade="<?= (int) $item['quantidade'] ?>"
                                data-valor="<?= $h($item['valor_efeito'] ?? '') ?>"
                                data-status="<?= $h($item['status_curado'] ?? '') ?>">
                                <span class="qty">x<?= (int) $item['quantidade'] ?></span>
                                <span class="item-icon"><?= $iconeItem((string) $item['tipo_efeito']) ?></span>
                                <span class="item-name"><?= $h($item['nome']) ?></span>
                                <span class="kind"><?= $h($rotuloTipo((string) $item['tipo_efeito'])) ?></span>
                                <span class="item-desc"><?= $h($item['descricao']) ?></span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (!$itens): ?><div class="empty" style="grid-column:1/-1">Sua mochila esta vazia.<br>Itens encontrados e comprados aparecerao aqui.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <aside class="panel detail" aria-live="polite">
                <h3 class="panel-title"><span>DETALHES DO ITEM</span><span>INFO</span></h3>
                <div class="detail-inner" id="detalhe-item">
                    <?php if ($itens): ?>
                        <div class="detail-icon" id="detalhe-icone"><?= $iconeItem((string) $itens[0]['tipo_efeito']) ?></div>
                        <div><h3 id="detalhe-nome"><?= $h($itens[0]['nome']) ?></h3><p class="detail-desc" id="detalhe-descricao"><?= $h($itens[0]['descricao']) ?></p></div>
                        <div class="detail-meta"><span class="meta-chip" id="detalhe-quantidade">Quantidade: <?= (int) $itens[0]['quantidade'] ?></span><span class="meta-chip" id="detalhe-categoria"><?= $h($rotuloTipo((string) $itens[0]['tipo_efeito'])) ?></span></div>
                        <div id="acao-normal" class="<?= $itens[0]['tipo_efeito'] === 'PEDRA_EVOLUCAO' ? 'hidden' : '' ?>">
                            <label class="field-label" for="pokemon-alvo">USAR EM QUAL POKEMON?</label>
                            <select class="field" id="pokemon-alvo">
                                <option value="">Selecione um Pokemon</option>
                                <?php foreach ($todosPokemon as $pokemon): ?><option value="<?= (int) $pokemon['id_capturado'] ?>"><?= $h($pokemon['nome']) ?><?= !empty($pokemon['desmaiado']) ? ' · Desmaiado' : '' ?></option><?php endforeach; ?>
                            </select>
                            <button class="primary" id="botao-usar" type="button" style="width:100%;margin-top:10px">USAR ITEM</button>
                        </div>
                        <div id="acao-evolucao" class="<?= $itens[0]['tipo_efeito'] === 'PEDRA_EVOLUCAO' ? '' : 'hidden' ?>">
                            <label class="field-label" for="pokemon-evoluir">ESCOLHA O POKEMON</label>
                            <select class="field" id="pokemon-evoluir"><option value="">Selecione um Pokemon</option><?php foreach ($todosPokemon as $pokemon): ?><option value="<?= (int) $pokemon['id_capturado'] ?>"><?= $h($pokemon['nome']) ?></option><?php endforeach; ?></select>
                            <label class="field-label" for="destino-evolucao" style="margin-top:10px">EVOLUCAO DISPONIVEL</label>
                            <select class="field" id="destino-evolucao" disabled><option value="">Primeiro selecione um Pokemon</option></select>
                            <button class="primary" id="botao-evoluir" type="button" style="width:100%;margin-top:10px" disabled>USAR PEDRA UNIVERSAL</button>
                        </div>
                    <?php else: ?>
                        <div class="empty">Selecione um item para ver seus detalhes e descobrir como utiliza-lo.</div>
                    <?php endif; ?>
                </div>
            </aside>
        </section>

        <section class="lower">
            <div class="panel">
                <h3 class="panel-title"><span>EQUIPE ATIVA</span><span><?= count(array_filter($equipe)) ?>/5</span></h3>
                <div class="panel-body"><div class="team-list">
                    <?php foreach ($equipe as $posicao => $pokemon): ?>
                        <?php if ($pokemon): $hpPercent = max(0, min(100, (int) round(((int) $pokemon['hp_atual'] / max(1, (int) $pokemon['hp_max'])) * 100))); ?>
                            <div class="pokemon-row">
                                <span class="slot-number"><?= (int) $posicao ?></span><?= $imagemPokemon($pokemon) ?>
                                <div class="pokemon-info"><strong><?= $h($pokemon['nome']) ?></strong><small><?= $h($pokemon['tipo']) ?> · <?= (int) $pokemon['hp_atual'] ?>/<?= (int) $pokemon['hp_max'] ?> HP<?php if ($pokemon['status_condicao']): ?> · <?= $h($pokemon['status_condicao']) ?><?php endif; ?><?php if ((bool) $pokemon['desmaiado']): ?> · <span class="status">DESMAIADO</span><?php endif; ?></small><div class="hp-track"><div class="hp-fill" style="width:<?= $hpPercent ?>%"></div></div></div>
                                <button class="danger" type="button" data-enviar-reserva="<?= (int) $posicao ?>">RESERVA</button>
                            </div>
                        <?php else: ?><div class="slot-empty">POSICAO <?= (int) $posicao ?> · VAZIA</div><?php endif; ?>
                    <?php endforeach; ?>
                </div><p class="hint">A equipe normal tem cinco espacos. O sexto fica reservado para a entrada especial de Rayquaza na historia.</p></div>
            </div>

            <div class="panel">
                <h3 class="panel-title"><span>ARQUIVO BIGOLIN</span><span><?= count($reserva) ?> GUARDADOS</span></h3>
                <div class="panel-body">
                    <?php if ($reserva): ?><div class="reserve-list">
                        <?php foreach ($reserva as $pokemon): ?>
                            <div class="reserve-card">
                                <?= $imagemPokemon($pokemon) ?>
                                <div class="pokemon-info"><strong><?= $h($pokemon['nome']) ?></strong><small><?= $h($pokemon['tipo']) ?> · <?= (int) $pokemon['hp_atual'] ?>/<?= (int) $pokemon['hp_max'] ?> HP</small><div class="reserve-actions"><select aria-label="Posicao de destino" id="posicao-<?= (int) $pokemon['id_capturado'] ?>"><option value="1">Slot 1</option><option value="2">Slot 2</option><option value="3">Slot 3</option><option value="4">Slot 4</option><option value="5">Slot 5</option></select><button class="secondary" type="button" data-colocar="<?= (int) $pokemon['id_capturado'] ?>">COLOCAR</button></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="empty">Nenhum Pokemon guardado por enquanto.<br>Quando capturar mais parceiros, eles aparecerao aqui.</div><?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>
<div class="toast hidden" id="toast" role="status"></div>
<script>
(() => {
    const chaveScrollMochila = 'peakmon_mochila_scroll';
    const scrollSalvo = sessionStorage.getItem(chaveScrollMochila);
    if (scrollSalvo !== null) {
        sessionStorage.removeItem(chaveScrollMochila);
        window.addEventListener('load', () => window.scrollTo(0, Number(scrollSalvo) || 0), {once:true});
    }
    const recarregarPreservandoScroll = () => {
        sessionStorage.setItem(chaveScrollMochila, String(window.scrollY));
        window.location.reload();
    };
    const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
    const form = async (acao, dados = {}) => {
        const body = new URLSearchParams({acao, csrf, ...dados});
        const resposta = await fetch('mochila.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
        const texto = await resposta.text();
        let resultado;
        try { resultado = JSON.parse(texto); }
        catch (erroJson) {
            console.error('Resposta inesperada do servidor:', texto);
            throw new Error('O servidor retornou uma resposta invalida. Confira o log do PHP.');
        }
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Nao foi possivel concluir a acao.');
        return resultado;
    };
    const toast = (mensagem, erro = false) => {
        const el = document.getElementById('toast');
        el.textContent = mensagem; el.classList.remove('hidden','error'); if (erro) el.classList.add('error');
        window.clearTimeout(toast.timer); toast.timer = window.setTimeout(() => el.classList.add('hidden'), 2600);
    };
    const icones = {CURA_HP:'🧪',CURA_TOTAL:'🧪',CURA_STATUS:'💠',REVIVER:'✨',PEDRA_EVOLUCAO:'🔮'};
    const categorias = {CURA_HP:'Cura',CURA_TOTAL:'Cura',REVIVER:'Cura',CURA_STATUS:'Status',PEDRA_EVOLUCAO:'Evolucao'};
    let itemSelecionado = null;
    const cards = [...document.querySelectorAll('.item-card')];
    const botaoUsar = document.getElementById('botao-usar');
    const botaoEvoluir = document.getElementById('botao-evoluir');
    const acaoNormal = document.getElementById('acao-normal');
    const acaoEvolucao = document.getElementById('acao-evolucao');

    function selecionarItem(card) {
        if (!card) return;
        cards.forEach(c => c.classList.toggle('selected', c === card));
        itemSelecionado = {id:card.dataset.id, nome:card.dataset.nome, descricao:card.dataset.descricao, tipo:card.dataset.tipo, quantidade:card.dataset.quantidade};
        document.getElementById('detalhe-icone').textContent = icones[itemSelecionado.tipo] || '🎒';
        document.getElementById('detalhe-nome').textContent = itemSelecionado.nome;
        document.getElementById('detalhe-descricao').textContent = itemSelecionado.descricao;
        document.getElementById('detalhe-quantidade').textContent = 'Quantidade: ' + itemSelecionado.quantidade;
        document.getElementById('detalhe-categoria').textContent = categorias[itemSelecionado.tipo] || 'Outros';
        const pedra = itemSelecionado.tipo === 'PEDRA_EVOLUCAO';
        acaoNormal.classList.toggle('hidden', pedra);
        acaoEvolucao.classList.toggle('hidden', !pedra);
        if (pedra) resetarEvolucoes();
    }
    cards.forEach(card => card.addEventListener('click', () => selecionarItem(card)));
    if (cards.length) selecionarItem(cards[0]);

    let filtroAtual = 'TODOS';
    let termoBusca = '';
    const aplicarFiltros = () => cards.forEach(card => {
        const tipo = card.dataset.tipo;
        const grupo = tipo === 'CURA_HP' || tipo === 'CURA_TOTAL' || tipo === 'REVIVER' ? 'CURA' : (tipo === 'CURA_STATUS' ? 'STATUS' : (tipo === 'PEDRA_EVOLUCAO' ? 'EVOLUCAO' : 'OUTROS'));
        const categoriaOk = filtroAtual === 'TODOS' || grupo === filtroAtual;
        const buscaOk = card.dataset.nome.toLocaleLowerCase('pt-BR').includes(termoBusca);
        card.classList.toggle('hidden', !(categoriaOk && buscaOk));
    });
    document.querySelectorAll('[data-filter]').forEach(botao => botao.addEventListener('click', () => {
        document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('active', b === botao));
        filtroAtual = botao.dataset.filter;
        aplicarFiltros();
    }));
    document.getElementById('buscar-item')?.addEventListener('input', e => {
        termoBusca = e.target.value.toLocaleLowerCase('pt-BR');
        aplicarFiltros();
    });

    botaoUsar?.addEventListener('click', async () => {
        const alvo = document.getElementById('pokemon-alvo').value;
        if (!itemSelecionado || !alvo) return toast('Selecione um Pokemon primeiro.', true);
        botaoUsar.disabled = true;
        try { const r = await form('usar_item', {id_item:itemSelecionado.id, id_capturado:alvo}); toast(r.mensagem); setTimeout(recarregarPreservandoScroll, 850); }
        catch (e) { toast(e.message, true); botaoUsar.disabled = false; }
    });

    document.querySelectorAll('[data-colocar]').forEach(botao => botao.addEventListener('click', async () => {
        const id = botao.dataset.colocar;
        const posicao = document.getElementById('posicao-' + id).value;
        botao.disabled = true;
        try { const r = await form('trocar_reserva', {id_capturado:id, posicao}); toast(r.mensagem); setTimeout(() => location.reload(), 750); }
        catch (e) { toast(e.message, true); botao.disabled = false; }
    }));
    document.querySelectorAll('[data-enviar-reserva]').forEach(botao => botao.addEventListener('click', async () => {
        if (!confirm('Enviar este Pokemon para o Arquivo Bigolin?')) return;
        botao.disabled = true;
        try { const r = await form('enviar_reserva', {posicao:botao.dataset.enviarReserva}); toast(r.mensagem); setTimeout(() => location.reload(), 750); }
        catch (e) { toast(e.message, true); botao.disabled = false; }
    }));

    function resetarEvolucoes() {
        const destino = document.getElementById('destino-evolucao');
        if (!destino) return;
        destino.innerHTML = '<option value="">Primeiro selecione um Pokemon</option>';
        destino.disabled = true; botaoEvoluir.disabled = true;
    }
    document.getElementById('pokemon-evoluir')?.addEventListener('change', async e => {
        resetarEvolucoes();
        if (!e.target.value) return;
        try {
            const r = await form('listar_evolucoes', {id_capturado:e.target.value});
            const destino = document.getElementById('destino-evolucao');
            destino.innerHTML = '';
            if (!r.evolucoes.length) { destino.innerHTML = '<option value="">Nenhuma evolucao disponivel</option>'; return; }
            destino.innerHTML = '<option value="">Selecione a evolucao</option>' + r.evolucoes.map(p => `<option value="${Number(p.id_destino)}">${String(p.pokemon_destino).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</option>`).join('');
            destino.disabled = false;
        } catch (e2) { toast(e2.message, true); }
    });
    document.getElementById('destino-evolucao')?.addEventListener('change', e => { botaoEvoluir.disabled = !e.target.value; });
    botaoEvoluir?.addEventListener('click', async () => {
        const capturado = document.getElementById('pokemon-evoluir').value;
        const destino = document.getElementById('destino-evolucao').value;
        if (!capturado || !destino) return toast('Escolha o Pokemon e a evolucao.', true);
        if (!confirm('Usar uma Pedra Universal para realizar esta evolucao?')) return;
        botaoEvoluir.disabled = true;
        try { const r = await form('evoluir', {id_capturado:capturado, id_destino:destino}); toast(r.mensagem); setTimeout(() => location.reload(), 900); }
        catch (e) { toast(e.message, true); botaoEvoluir.disabled = false; }
    });
})();
</script>
</body>
</html>
