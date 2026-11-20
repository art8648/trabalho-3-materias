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
    $controller = new \Peakmon\Controllers\MochilaController($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $resultado = $controller->processarAcao($idPerfil, $idUsuario, $csrf, $_POST);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pagina = $controller->prepararPagina($idPerfil, $idUsuario);
    $itens = $pagina['itens'];
    $equipe = $pagina['equipe'];
    $reserva = $pagina['reserva'];
    $todosPokemon = $pagina['todosPokemon'];
} catch (Throwable $erro) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code($erro instanceof DomainException ? ($erro->getCode() === 403 ? 403 : 400) : 500);
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
$classeTipo = static function (string $tipo): string {
    return match ($tipo) {
        'CURA_HP', 'CURA_TOTAL', 'REVIVER' => 'cat-cura',
        'CURA_STATUS' => 'cat-status',
        'PEDRA_EVOLUCAO' => 'cat-pedra',
        default => '',
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
<link rel="stylesheet" href="mochila.css?v=<?= (int) @filemtime(__DIR__ . '/mochila.css') ?>">
<style>
/* Ícones dos itens (ficam aqui para valer mesmo se o mochila.css estiver em cache) */
.item-icon { flex: none; width: 48px; height: 48px; display: grid; place-items: center; overflow: hidden; line-height: 1; }
.item-icon img { width: 48px; height: 48px; object-fit: contain; display: block; }

/* Quadradinho do painel de detalhes: a imagem fica dentro dele */
.detail-icon { overflow: hidden; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.6); }
.detail-icon img { width: 100%; height: 100%; padding: 6px; object-fit: contain; display: block; }

/* Cor do painel grande de detalhes por tipo de item (o quadradinho continua branco) */

/* CURA: potions, full restore, revive -> verde */
.detail.cat-cura { background: linear-gradient(#3fb862, #1d7a3b); box-shadow: 0 0 0 3px #0f3d1f, inset 0 0 0 3px rgba(255, 255, 255, 0.2); }
.detail.cat-cura .panel-title { background: #0f3d1f; }
.detail.cat-cura .detail-inner h3 { text-shadow: 2px 2px 0 #0f3d1f; }
.detail.cat-cura .detail-desc { color: #e9ffee; }
.detail.cat-cura .field-label { color: #c8f5d3; }
.detail.cat-cura .detail-icon { border-color: #0f3d1f; }

/* STATUS: antidote, burn heal, ice heal, awakening, paralyze heal, full heal -> azul */
.detail.cat-status { background: linear-gradient(#3fb4ee, #1d63c4); box-shadow: 0 0 0 3px #0e2a5c, inset 0 0 0 3px rgba(255, 255, 255, 0.2); }
.detail.cat-status .panel-title { background: #0e2a5c; }
.detail.cat-status .detail-inner h3 { text-shadow: 2px 2px 0 #0e2a5c; }
.detail.cat-status .detail-desc { color: #eaf6ff; }
.detail.cat-status .field-label { color: #cfe9ff; }
.detail.cat-status .detail-icon { border-color: #0e2a5c; }

/* PEDRA UNIVERSAL -> roxo escuro, mistico e meio sombrio, com brilho pulsando */
.detail.cat-pedra {
    background: radial-gradient(circle at 50% 18%, #6a24a8 0%, #2c0f4d 52%, #0c0518 100%);
    border-color: #b98cff;
    box-shadow: 0 0 0 3px #12051f, 0 0 22px 4px rgba(150, 70, 255, 0.6), inset 0 0 46px rgba(0, 0, 0, 0.65);
    animation: pulso-mistico 3.2s ease-in-out infinite;
}
.detail.cat-pedra .panel-title { background: #12051f; border-bottom-color: rgba(185, 140, 255, 0.5); }
.detail.cat-pedra .detail-inner h3 { text-shadow: 0 0 10px #b04cff, 2px 2px 0 #12051f; letter-spacing: 2px; }
.detail.cat-pedra .detail-desc { color: #e6d3ff; }
.detail.cat-pedra .field-label { color: #c9a8ff; }
.detail.cat-pedra .meta-chip { background: rgba(230, 211, 255, 0.92); color: #2c0f4d; }
.detail.cat-pedra .detail-icon { border-color: #12051f; box-shadow: 0 0 16px 3px rgba(190, 120, 255, 0.85); }
/* A pedra tem fundo opaco: cobre o quadradinho inteiro, sem margem */
.detail.cat-pedra .detail-icon img { padding: 0; object-fit: cover; }

@keyframes pulso-mistico {
    0%, 100% { box-shadow: 0 0 0 3px #12051f, 0 0 16px 2px rgba(150, 70, 255, 0.45), inset 0 0 46px rgba(0, 0, 0, 0.65); }
    50%      { box-shadow: 0 0 0 3px #12051f, 0 0 32px 8px rgba(176, 76, 255, 0.85), inset 0 0 36px rgba(0, 0, 0, 0.55); }
}
@media (prefers-reduced-motion: reduce) { .detail.cat-pedra { animation: none; } }
</style>
<link rel="stylesheet" href="assets/peakui.css">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div class="brand"><div class="brand-mark">🎒</div><div><small>PEAKMON · THE BIGOLIN CRISIS</small><h1>Central do Treinador</h1></div></div>
        <div style="display:flex;flex-wrap:wrap;gap:10px"><a class="back" href="historico.php">📜 HISTÓRICO</a><a class="back" href="mapa.php">← VOLTAR AO MAPA</a></div>
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
                                data-status="<?= $h($item['status_curado'] ?? '') ?>"
                                data-imagem="<?= $h($imagemItem((string) $item['nome'])) ?>">
                                <span class="qty">x<?= (int) $item['quantidade'] ?></span>
                                <span class="item-icon"><?= $iconeHtml($item) ?></span>
                                <span class="item-name"><?= $h($item['nome']) ?></span>
                                <span class="kind"><?= $h($rotuloTipo((string) $item['tipo_efeito'])) ?></span>
                                <span class="item-desc"><?= $h($item['descricao']) ?></span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (!$itens): ?><div class="empty" style="grid-column:1/-1">Sua mochila esta vazia.<br>Itens encontrados e comprados aparecerao aqui.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <aside class="panel detail <?= $itens ? $classeTipo((string) $itens[0]['tipo_efeito']) : '' ?>" id="painel-detalhe" aria-live="polite">
                <h3 class="panel-title"><span>DETALHES DO ITEM</span><span>INFO</span></h3>
                <div class="detail-inner" id="detalhe-item">
                    <?php if ($itens): ?>
                        <div class="detail-icon <?= $classeTipo((string) $itens[0]['tipo_efeito']) ?>" id="detalhe-icone"><?= $iconeHtml($itens[0]) ?></div>
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
                            <select class="field" id="pokemon-evoluir"><option value="">Selecione um Pokemon</option><?php foreach ($todosPokemon as $pokemon): ?><option value="<?= (int) $pokemon['id_capturado'] ?>" data-imagem="<?= $h($pokemon['imagem'] ?? '') ?>"><?= $h($pokemon['nome']) ?><?= ($pokemon['sexo'] ?? '') === 'F' ? ' ♀' : (($pokemon['sexo'] ?? '') === 'M' ? ' ♂' : '') ?></option><?php endforeach; ?></select>
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
                            <div class="pokemon-row" data-posicao-time="<?= (int) $posicao ?>">
                                <span class="slot-number"><?= (int) $posicao ?></span><?= $imagemPokemon($pokemon) ?>
                                <div class="pokemon-info"><strong><?= $h($pokemon['nome']) ?></strong><small><?= $h($pokemon['tipo']) ?> · <?= (int) $pokemon['hp_atual'] ?>/<?= (int) $pokemon['hp_max'] ?> HP<?php if ($pokemon['status_condicao']): ?> · <?= $h($pokemon['status_condicao']) ?><?php endif; ?><?php if ((bool) $pokemon['desmaiado']): ?> · <span class="status">DESMAIADO</span><?php endif; ?></small><div class="hp-track"><div class="hp-fill" style="width:<?= $hpPercent ?>%"></div></div></div>
                                <button class="team-reorder" type="button" data-mover-time="<?= (int) $posicao ?>" title="Selecionar posição para trocar ou mover" aria-label="Mover a posição <?= (int) $posicao ?>">⇄</button>
                                <?php if (count(array_filter($equipe)) > 1): ?><button class="danger" type="button" data-enviar-reserva="<?= (int) $posicao ?>">GUARDAR</button><?php endif; ?>
                            </div>
                        <?php else: ?><div class="slot-empty" data-posicao-time="<?= (int) $posicao ?>">POSICAO <?= (int) $posicao ?> · VAZIA <button class="team-reorder" type="button" data-mover-time="<?= (int) $posicao ?>" title="Mover Pokémon para esta posição" aria-label="Mover Pokémon para a posição <?= (int) $posicao ?>">⇄</button></div><?php endif; ?>
                    <?php endforeach; ?>
                </div><p class="hint">Para trocar a ordem sem usar o arquivo: clique em ⇄ de um Pokémon e depois em ⇄ do slot de destino.</p><p class="hint">A equipe normal tem cinco espacos. O sexto fica reservado para a entrada especial de Rayquaza na historia. O time precisa ter pelo menos um Pokemon.</p></div>
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
<template id="peak-dialog-template">
    <div class="peak-dialog-backdrop">
        <section class="peak-dialog" role="dialog" aria-modal="true" aria-labelledby="peak-dialog-heading">
            <h2 class="peak-dialog-title" id="peak-dialog-heading"></h2>
            <p class="peak-dialog-text"></p>
            <div class="peak-dialog-actions">
                <button type="button" class="peak-dialog-ok">Confirmar</button>
                <button type="button" class="peak-dialog-cancel">Cancelar</button>
            </div>
        </section>
    </div>
</template>
<template id="modelos-mochila"><div></div><img alt=""><p></p></template>
<script src="assets/peakui.js"></script>
<script>
(() => {
    function clonarMochila(tag) {
        const modelo = document.getElementById('modelos-mochila').content.querySelector(tag);
        if (!modelo) throw new Error('Modelo da mochila não encontrado: ' + tag);
        return modelo.cloneNode(true);
    }
    const chavePreferencias = 'peakmon_mochila_selecao_<?= (int) $idPerfil ?>';
    let preferencias = {};
    try { preferencias = JSON.parse(sessionStorage.getItem(chavePreferencias) || '{}') || {}; }
    catch (_) { preferencias = {}; }
    const salvarPreferencias = () => {
        try { sessionStorage.setItem(chavePreferencias, JSON.stringify(preferencias)); } catch (_) { /* armazenamento bloqueado */ }
    };
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
    const classesCor = {CURA_HP:'cat-cura',CURA_TOTAL:'cat-cura',REVIVER:'cat-cura',CURA_STATUS:'cat-status',PEDRA_EVOLUCAO:'cat-pedra'};
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
        preferencias.item = itemSelecionado.id;
        salvarPreferencias();
        const caixaIcone = document.getElementById('detalhe-icone');
        document.getElementById('painel-detalhe').className = 'panel detail ' + (classesCor[itemSelecionado.tipo] || '');
        if (card.dataset.imagem) {
            caixaIcone.innerHTML = '';
            const imgEl = clonarMochila('img');
            imgEl.src = card.dataset.imagem;
            imgEl.alt = itemSelecionado.nome;
            caixaIcone.appendChild(imgEl);
        } else {
            caixaIcone.textContent = icones[itemSelecionado.tipo] || '🎒';
        }
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
    const cardSalvo = cards.find(c => c.dataset.id === String(preferencias.item));
    if (cards.length) selecionarItem(cardSalvo || cards[0]);
    const alvoItem = document.getElementById('pokemon-alvo');
    const alvoEvolucao = document.getElementById('pokemon-evoluir');
    if (alvoItem && [...alvoItem.options].some(o => o.value === String(preferencias.alvo))) alvoItem.value = String(preferencias.alvo);
    if (alvoEvolucao && [...alvoEvolucao.options].some(o => o.value === String(preferencias.evoluir))) alvoEvolucao.value = String(preferencias.evoluir);
    alvoItem?.addEventListener('change', () => { preferencias.alvo = alvoItem.value; salvarPreferencias(); });
    alvoEvolucao?.addEventListener('change', () => { preferencias.evoluir = alvoEvolucao.value; salvarPreferencias(); });

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

    // Dois cliques em ⇄: primeiro escolhe origem, segundo escolhe destino.
    let posicaoOrigem = null;
    const limparTroca = () => {
        document.querySelectorAll('[data-posicao-time]').forEach(el => el.classList.remove('team-reorder-selected'));
        posicaoOrigem = null;
    };
    document.querySelectorAll('[data-mover-time]').forEach(botao => botao.addEventListener('click', async () => {
        const destino = Number(botao.dataset.moverTime);
        if (posicaoOrigem === null) {
            const origemPreenchida = !!document.querySelector('[data-posicao-time="' + destino + '"] .pokemon-info');
            if (!origemPreenchida) return toast('Selecione primeiro um Pokémon ocupado.', true);
            posicaoOrigem = destino;
            botao.closest('[data-posicao-time]')?.classList.add('team-reorder-selected');
            return;
        }
        const de = posicaoOrigem;
        limparTroca();
        if (de === destino) return;
        botao.disabled = true;
        try {
            const r = await form('mover_time', {de, para:destino});
            toast(r.mensagem);
            setTimeout(recarregarPreservandoScroll, 500);
        } catch (erro) { toast(erro.message, true); botao.disabled = false; }
    }));

    document.querySelectorAll('[data-colocar]').forEach(botao => botao.addEventListener('click', async () => {
        const id = botao.dataset.colocar;
        const posicao = document.getElementById('posicao-' + id).value;
        botao.disabled = true;
        try { const r = await form('trocar_reserva', {id_capturado:id, posicao}); toast(r.mensagem); setTimeout(recarregarPreservandoScroll, 750); }
        catch (e) { toast(e.message, true); botao.disabled = false; }
    }));
    document.querySelectorAll('[data-enviar-reserva]').forEach(botao => botao.addEventListener('click', async () => {
        if (!await PeakUI.confirm('Deseja guardar este Pokémon no Arquivo Bigolin?', {title:'Guardar Pokémon', okText:'Guardar'})) return;
        botao.disabled = true;
        try { const r = await form('enviar_reserva', {posicao:botao.dataset.enviarReserva}); toast(r.mensagem); setTimeout(recarregarPreservandoScroll, 750); }
        catch (e) { toast(e.message, true); botao.disabled = false; }
    }));

    function resetarEvolucoes() {
        const destino = document.getElementById('destino-evolucao');
        if (!destino) return;
        destino.innerHTML = '<option value="">Primeiro selecione um Pokemon</option>';
        destino.disabled = true; botaoEvoluir.disabled = true;
    }
    let consultaEvolucao = 0;
    document.getElementById('pokemon-evoluir')?.addEventListener('change', async e => {
        const meuPedido = ++consultaEvolucao;
        const capturado = e.target.value;
        resetarEvolucoes();
        if (!capturado) return;
        try {
            const r = await form('listar_evolucoes', {id_capturado:capturado});
            // Evita que a resposta de outro Pokemon sobrescreva as evolucoes atuais.
            if (meuPedido !== consultaEvolucao ||
                document.getElementById('pokemon-evoluir').value !== capturado) return;
            const destino = document.getElementById('destino-evolucao');
            destino.innerHTML = '';
            if (!r.evolucoes.length) { destino.innerHTML = '<option value="">Nenhuma evolucao disponivel</option>'; return; }
            destino.innerHTML = '<option value="">Selecione a evolucao</option>' + r.evolucoes.map(p => `<option value="${Number(p.id_destino)}" data-imagem="${String(p.imagem_destino || '').replace(/"/g, '&quot;')}">${String(p.pokemon_destino).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</option>`).join('');
            destino.disabled = false;
        } catch (e2) { if (meuPedido === consultaEvolucao) toast(e2.message, true); }
    });
    document.getElementById('destino-evolucao')?.addEventListener('change', e => { botaoEvoluir.disabled = !e.target.value; });
    if (itemSelecionado?.tipo === 'PEDRA_EVOLUCAO' && alvoEvolucao?.value) alvoEvolucao.dispatchEvent(new Event('change'));
    // ===== Animacao de evolucao (rapida: termina em ~4,6 s; clicar encerra na hora) =====
    function animarEvolucao(de, para) {
        return new Promise((resolver) => {
            const reduzir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            const overlay = clonarMochila('div');
            overlay.className = 'evo-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-live', 'polite');

            const palco = clonarMochila('div');
            palco.className = 'evo-palco';
            const brilho = clonarMochila('div');
            brilho.className = 'evo-brilho';
            const sprite = clonarMochila('img');
            sprite.className = 'evo-sprite';
            sprite.onerror = () => { sprite.style.visibility = 'hidden'; };
            const texto = clonarMochila('p');
            texto.className = 'evo-texto';

            palco.appendChild(brilho);
            palco.appendChild(sprite);
            overlay.appendChild(palco);
            overlay.appendChild(texto);
            document.body.appendChild(overlay);

            const mostrar = (info, silhueta) => {
                sprite.style.visibility = 'visible';
                sprite.src = info.imagem;
                sprite.alt = info.nome;
                sprite.classList.toggle('silhueta', silhueta);
            };

            const timers = [];
            const agendar = (fn, ms) => timers.push(setTimeout(fn, ms));
            let terminou = false;

            const encerrar = () => {
                if (terminou) return;
                terminou = true;
                timers.forEach(clearTimeout);
                overlay.classList.add('saindo');
                setTimeout(() => { overlay.remove(); resolver(); }, 220);
            };
            overlay.addEventListener('click', encerrar);

            const revelar = () => {
                overlay.classList.remove('flash');
                mostrar(para, false);
                sprite.classList.add('revelar');
                overlay.classList.add('revelado');
                texto.textContent = 'Parabéns! ' + de.nome + ' evoluiu para ' + para.nome + '!';
            };

            mostrar(de, false);
            texto.textContent = 'O quê? ' + de.nome + ' está evoluindo!';

            if (reduzir) {
                // Sem piscadas: troca direto e fecha rapido.
                agendar(revelar, 500);
                agendar(encerrar, 1800);
                return;
            }

            // Silhueta branca alternando entre a forma antiga e a nova, cada vez mais rapido.
            const intervalos = [300, 260, 230, 200, 180, 160, 140, 130, 120, 110, 110, 110];
            let tempo = 600;
            let mostrandoNova = false;
            agendar(() => { mostrar(de, true); overlay.classList.add('brilhando'); }, tempo);
            intervalos.forEach((intervalo) => {
                tempo += intervalo;
                agendar(() => {
                    mostrandoNova = !mostrandoNova;
                    mostrar(mostrandoNova ? para : de, true);
                }, tempo);
            });

            // Clarao branco e revelacao da nova forma (~2,9 s), com 1,5 s para admirar.
            agendar(() => overlay.classList.add('flash'), tempo + 120);
            agendar(revelar, tempo + 420);
            agendar(encerrar, tempo + 420 + 1500);
        });
    }

    botaoEvoluir?.addEventListener('click', async () => {
        const capturado = document.getElementById('pokemon-evoluir').value;
        const destino = document.getElementById('destino-evolucao').value;
        if (!capturado || !destino) return toast('Escolha o Pokemon e a evolucao.', true);
        if (!await PeakUI.confirm('Essa evolução consumirá uma Pedra Universal. Deseja continuar?', {title:'Confirmar evolução', okText:'Evoluir Pokémon'})) return;
        botaoEvoluir.disabled = true;
        try {
            const optOrigem = document.getElementById('pokemon-evoluir').selectedOptions[0];
            const optDestino = document.getElementById('destino-evolucao').selectedOptions[0];
            const de = { nome: optOrigem.textContent.trim(), imagem: optOrigem.dataset.imagem || '' };
            const para = { nome: optDestino.textContent.trim(), imagem: optDestino.dataset.imagem || '' };

            const r = await form('evoluir', {id_capturado:capturado, id_destino:destino});
            preferencias.item = itemSelecionado.id;
            preferencias.evoluir = capturado; // id do exemplar continua o mesmo depois da evolucao
            salvarPreferencias();
            await animarEvolucao(de, para);   // rapida: no maximo ~4,6 s
            toast(r.mensagem);
            setTimeout(recarregarPreservandoScroll, 400);
        }
        catch (e) { toast(e.message, true); botaoEvoluir.disabled = false; }
    });
})();
</script>
</body>
</html>
