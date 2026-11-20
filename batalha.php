<?php
declare(strict_types=1);

use Peakmon\Models\Batalha;
use Peakmon\Models\Mochila;

require_once __DIR__ . '/app/sessao.php';
require_once __DIR__ . '/app/autoload.php';

$ehPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Usuario e perfil ativo vem da sessao, como no mapa.
if (!isset($_SESSION['usuario_id'], $_SESSION['perfil_id'])) {
    if ($ehPost) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Sua sessao expirou. Selecione um perfil novamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: perfis.php');
    exit;
}

$idUsuario = (int) $_SESSION['usuario_id'];
$idPerfil = (int) $_SESSION['perfil_id'];

if (empty($_SESSION['csrf_batalha'])) {
    $_SESSION['csrf_batalha'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_batalha'];

$previa = null;
$perfil = ['nome_perfil' => (string) ($_SESSION['perfil_nome'] ?? 'Perfil'), 'dinheiro' => 0];
$mensagemErro = null;

try {
    $pdo = require __DIR__ . '/config/database.php';
    $controller = new \Peakmon\Controllers\BatalhaController($pdo, new Batalha($pdo), new Mochila($pdo));
    if ($ehPost) {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'A solicitacao expirou. Atualize a pagina.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $resultado = $controller->executarAcao($idPerfil, $idUsuario, $csrf, $_POST);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pagina = $controller->prepararPagina($idPerfil, $idUsuario);
    $previa = $pagina['previa'];
    if ($pagina['perfil'] !== null) {
        $perfil = $pagina['perfil'];
    }
} catch (DomainException $erro) {
    if ($ehPost) {
        http_response_code($erro->getCode() === 403 ? 403 : 400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => $erro->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Nada para lutar aqui (batalha ja vencida, ponto sem batalha...): volta ao mapa.
    if ($erro->getCode() === Batalha::SEM_BATALHA) {
        header('Location: mapa.php');
        exit;
    }

    $mensagemErro = $erro->getMessage();
} catch (Throwable $erro) {
    error_log((string) $erro);

    if ($ehPost) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Ocorreu um erro durante a batalha.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mensagemErro = 'Nao foi possivel carregar a batalha. Verifique a conexao com o banco.';
}

$escapar = static function ($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
};

$titulos = [
    'SELVAGEM' => 'Um Pokémon selvagem apareceu!',
    'TREINADOR' => 'Um treinador quer batalhar!',
    'CHEFE' => 'Batalha de chefe!',
];

$dadosJs = null;
if ($previa !== null) {
    // Slots 1 a 5; posicao vazia = null.
    $slots = array_fill(1, 5, null);
    foreach ($previa['equipe'] as $lutador) {
        $slots[(int) $lutador['posicao']] = $lutador;
    }

    $dadosJs = [
        'csrf' => $csrf,
        'categoria' => $previa['categoria'],
        'adversario' => $previa['adversario'],
        'slots' => array_values($slots),
        'inimigos' => $previa['inimigos'],
        'podeLutar' => $previa['pode_lutar'],
        'itens' => (new Mochila($pdo))->listarItens($idPerfil, $idUsuario),
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peakmon - Batalha</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            font-family: monospace;
            color: #20252a;
            background: linear-gradient(
                to bottom,
                #75c8f4 0%,
                #8bd7f7 45%,
                #e5f4f5 46%,
                #9acb70 47%,
                #7eaa6b 100%
            );
            background-attachment: fixed;
        }

        .topo {
            width: min(calc(100% - 40px), 1100px);
            margin: 18px auto 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .informacao, .botao {
            background: #eee9dc;
            border: 3px solid #292e31;
            box-shadow: 3px 3px #42484b;
            padding: 10px 14px;
            color: #20252a;
        }

        .informacao strong { color: #385b43; }

        .botao {
            font: inherit;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            display: inline-block;
        }

        .botao:hover:not(:disabled) { background: #d9efd5; }
        .botao:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
        .botao:disabled { opacity: .5; cursor: not-allowed; }

        .botao.principal {
            background: #f3d86c;
            font-size: 18px;
            padding: 14px 34px;
        }
        .botao.principal:hover:not(:disabled) { background: #ffe58a; }

        h1 {
            text-align: center;
            margin: 12px 8px 4px;
            font-size: clamp(18px, 3vw, 25px);
            text-shadow: 1px 2px #fff;
        }

        .subtitulo {
            margin: 0 8px 18px;
            text-align: center;
            font-size: 13px;
        }

        .painel {
            background: #eee9dc;
            border: 3px solid #292e31;
            box-shadow: 4px 4px #42484b;
            padding: 12px;
        }

        .painel h2 {
            margin: 0 0 10px;
            font-size: 16px;
            text-align: center;
        }

        .erro {
            width: min(94vw, 620px);
            margin: 20px auto;
            padding: 14px;
            background: #fff0e8;
            border: 3px solid #a84d38;
            color: #7d281b;
            line-height: 1.5;
        }

        main { width: min(calc(100% - 24px), 1100px); margin: 0 auto 40px; }
        [hidden] { display: none !important; }

        /* ===== ANTES DA LUTA ===== */

        .previa {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }

        .slot { display: flex; align-items: center; gap: 6px; }
        .slot-numero { width: 18px; flex: none; text-align: center; font-weight: bold; color: #385b43; }

        .cartao {
            flex: 1;
            min-width: 0;
            min-height: 58px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 8px;
            background: #f8f4e8;
            border: 2px solid #34383a;
            font: inherit;
            color: inherit;
            text-align: left;
        }

        button.cartao { cursor: pointer; }
        button.cartao:hover:not(:disabled), button.cartao:focus-visible { background: #e1f0dc; outline: none; }
        .cartao.vazio { justify-content: center; opacity: .6; }
        .cartao.desmaiado { opacity: .55; filter: grayscale(.8); }
        .slot.selecionado .cartao { background: #f3d86c; border-color: #20252a; box-shadow: 0 0 0 3px #fff; opacity: 1; }

        .foto {
            width: 42px;
            height: 42px;
            flex: none;
            border: 2px solid #34383a;
            border-radius: 50%;
            background: #fff;
            background-image: var(--img);
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
        }

        .dados { display: flex; flex-direction: column; min-width: 0; font-size: 12px; line-height: 1.35; flex: 1; }
        .dados strong { font-size: 14px; }
        .dados em { color: #a84d38; font-style: normal; font-weight: bold; }

        .mini-barra { height: 6px; margin-top: 3px; background: #c9c2b0; border: 1px solid #34383a; }
        .mini-barra > span { display: block; height: 100%; background: #5fae5f; }
        .mini-barra > span.medio { background: #e0b43a; }
        .mini-barra > span.baixo { background: #c84a38; }

        .adversario-cabeca {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 8px 10px;
            border: 2px solid #34383a;
            background: #f8f4e8;
            font-weight: bold;
        }
        .adversario-cabeca .emoji { font-size: 26px; }

        .acoes { margin: 20px 0 0; text-align: center; }
        .dica { margin: 10px 0 0; min-height: 2.6em; font-size: 12px; line-height: 1.4; text-align: center; }
        .dica.com-erro { color: #a8281b; }

        /* ===== ARENA ===== */

        .arena-bloco { display: flex; flex-direction: column; gap: 12px; }

        .fila { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; min-height: 34px; }
        .fila .chip {
            width: 34px;
            height: 34px;
            border: 2px solid #34383a;
            border-radius: 50%;
            background: #f8f4e8;
            background-image: var(--img);
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            transition: opacity .3s, filter .3s;
        }
        .fila .chip.ativo { box-shadow: 0 0 0 3px #f3d86c; }
        .fila .chip.fora { opacity: .35; filter: grayscale(1); }
        .fila .chip.espera { opacity: .8; }

        .arena {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 10;
            min-height: 330px;
            overflow: hidden;
            border: 4px solid #24292a;
            box-shadow: 5px 5px #40494b;
            background:
                radial-gradient(ellipse 28% 9% at 76% 47%, rgba(0,0,0,.22), transparent 70%),
                radial-gradient(ellipse 30% 10% at 24% 90%, rgba(0,0,0,.22), transparent 70%),
                linear-gradient(to bottom, #8bd7f7 0%, #bfe8f6 45%, #a8d27c 46%, #7eaa6b 100%);
        }

        .sprite {
            position: absolute;
            width: clamp(110px, 24%, 190px);
            aspect-ratio: 1;
            background-image: var(--img);
            background-size: contain;
            background-position: center bottom;
            background-repeat: no-repeat;
            filter: drop-shadow(0 4px 0 rgba(0,0,0,.2));
        }
        .sprite.inimigo { right: 12%; top: 8%; }
        .sprite.jogador { left: 12%; bottom: 4%; }
        .sprite.fora { opacity: 0; transform: translateY(30px); transition: opacity .5s, transform .5s; }

        .sprite.entra-inimigo { animation: entra-direita .55s ease-out; }
        .sprite.entra-jogador { animation: entra-esquerda .55s ease-out; }
        .sprite.golpe-jogador { animation: golpe-jogador .45s ease-in-out; }
        .sprite.golpe-inimigo { animation: golpe-inimigo .45s ease-in-out; }
        .sprite.levou { animation: levou .45s linear; }

        @keyframes entra-direita { from { transform: translateX(120%); opacity: 0; } to { transform: none; opacity: 1; } }
        @keyframes entra-esquerda { from { transform: translateX(-120%); opacity: 0; } to { transform: none; opacity: 1; } }
        @keyframes golpe-jogador { 40% { transform: translate(55%, -35%) scale(1.08); } 100% { transform: none; } }
        @keyframes golpe-inimigo { 40% { transform: translate(-55%, 35%) scale(1.08); } 100% { transform: none; } }
        @keyframes levou {
            0%, 100% { transform: none; filter: drop-shadow(0 4px 0 rgba(0,0,0,.2)); }
            20% { transform: translateX(-8px); filter: brightness(2.2) drop-shadow(0 4px 0 rgba(0,0,0,.2)); }
            40% { transform: translateX(8px); }
            60% { transform: translateX(-6px); filter: brightness(1.6) drop-shadow(0 4px 0 rgba(0,0,0,.2)); }
            80% { transform: translateX(4px); }
        }

        .placa {
            position: absolute;
            width: min(46%, 270px);
            padding: 7px 10px;
            border: 3px solid #292e31;
            background: #eee9dc;
            box-shadow: 3px 3px #42484b;
            font-size: 12px;
            line-height: 1.4;
        }
        .placa.inimigo { left: 3%; top: 6%; }
        .placa.jogador { right: 3%; bottom: 8%; }
        .placa strong { font-size: 14px; }
        .placa .tipo { color: #5d5849; }
        /* Condicao (queimadura, congelamento...) ao lado do nome do Pokemon. */
        .condicao {
            display: inline-block;
            margin: 0 3px 0 4px;
            padding: 0 6px;
            border: 2px solid var(--cond-borda);
            background: var(--cond-fundo);
            color: var(--cond-texto);
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .5px;
            line-height: 1.5;
            vertical-align: 1px;
            white-space: nowrap;
        }
        .condicao.novo { animation: condicao-pop .6s ease-out; }
        .condicao-queimadura { --cond-fundo: #ff8a3d; --cond-borda: #b3220f; --cond-texto: #4a0d00; }
        .condicao-congelamento { --cond-fundo: #8be9ff; --cond-borda: #0a6fb8; --cond-texto: #05324f; }
        .condicao-paralisia { --cond-fundo: #ffe14d; --cond-borda: #a87b00; --cond-texto: #4a3600; }
        .condicao-veneno { --cond-fundo: #c58cff; --cond-borda: #6b2aa8; --cond-texto: #2e0c52; }
        .condicao-sono { --cond-fundo: #b9c4e8; --cond-borda: #4a5a99; --cond-texto: #1c2650; }
        @keyframes condicao-pop { 0% { transform: scale(2.2); opacity: 0; } 60% { transform: scale(.9); opacity: 1; } 100% { transform: none; } }
        .barra { height: 10px; margin-top: 5px; background: #c9c2b0; border: 2px solid #34383a; }
        .barra > span { display: block; height: 100%; width: 100%; background: #5fae5f; transition: width .5s ease-out, background .3s; }
        .barra > span.medio { background: #e0b43a; }
        .barra > span.baixo { background: #c84a38; }

        .flutuante {
            position: absolute;
            z-index: 5;
            font-size: 26px;
            font-weight: bold;
            color: #fff;
            text-shadow: 2px 2px 0 #292e31, -1px -1px 0 #292e31, 1px -1px 0 #292e31, -1px 1px 0 #292e31;
            pointer-events: none;
            animation: subir 1s ease-out forwards;
        }
        .flutuante.super { color: #ffd34d; font-size: 32px; }
        .flutuante.fraco { color: #b9c4cc; font-size: 22px; }
        .flutuante.nada { color: #b9c4cc; font-size: 18px; }
        @keyframes subir { from { transform: translateY(0); opacity: 1; } to { transform: translateY(-48px); opacity: 0; } }

        .rodape-arena { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: start; }

        .registro {
            height: 132px;
            margin: 0;
            padding: 8px 10px;
            overflow-y: auto;
            list-style: none;
            font-size: 12px;
            line-height: 1.55;
            background: #f8f4e8;
            border: 2px solid #34383a;
        }
        .registro li { padding: 1px 0; }
        .registro li.entra { color: #385b43; font-weight: bold; }
        .registro li.desmaia { color: #a84d38; font-weight: bold; }
        .registro li.fim { font-weight: bold; }

        .controles { display: flex; flex-direction: column; gap: 6px; }
        .controles .botao { padding: 7px 12px; font-size: 13px; text-align: center; }
        .controles .botao[aria-pressed="true"] { background: #f3d86c; }

        /* ===== RESULTADO ===== */

        .resultado {
            margin: 4px auto 0;
            max-width: 520px;
            text-align: center;
            line-height: 1.6;
        }
        .resultado h2 { font-size: 22px; margin: 0 0 6px; }
        .resultado .ouro { font-size: 20px; font-weight: bold; color: #385b43; }
        .resultado.derrota h2 { color: #a84d38; }
        .resultado .botoes { margin-top: 14px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

        .painel-combate {
            margin: 12px 0 0; padding: 12px;
            border: 3px solid #30383f; background: #f1ead7;
        }
        .painel-combate .cabecalho { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
        .painel-combate h2 { font-size: 16px; margin: 0; }
        .painel-combate .botoes { display: flex; flex-wrap: wrap; gap: 7px; }
        .painel-combate .botoes .botao { font-size: 12px; padding: 9px 12px; }
        .mochila-combate {
            padding: 12px; margin-top: 10px; border: 2px solid #6f8b80;
            background: #fcfef7;
        }
        .mochila-combate[hidden] { display: none; }
        .mochila-combate .campos { display: flex; flex-wrap: wrap; gap: 12px; margin: 12px 0; }
        .mochila-combate label { display: flex; flex: 1 1 160px; flex-direction: column; gap: 5px; font-weight: bold; }
        .mochila-combate select { font: 14px monospace; padding: 9px; border: 2px solid #34483d; max-width: 100%; }
        .dica-combate { font-size: 12px; margin: 9px 0 0; min-height: 18px; }
        .dica-combate.com-erro { color: #9f362c; }

        /* Desktop: comandos persistentes na lateral; tela menor: empilhar. */
        .arena-bloco { display:grid; grid-template-columns:minmax(0,1fr) minmax(236px,290px);
            grid-template-areas:"inimigo painel" "arena painel" "jogador painel" "registro painel";
            gap:12px 17px; align-items:start; }
        .arena-bloco[hidden], [hidden] { display:none !important; }
        #fila-inimigo { grid-area:inimigo; }
        #arena { grid-area:arena; }
        #fila-jogador { grid-area:jogador; }
        .rodape-arena { grid-area:registro; }
        #painel-combate { grid-area:painel; margin:0; position:sticky; top:12px;
            max-height:calc(100vh - 24px); overflow-y:auto; }
        #painel-combate .cabecalho { display:block; }
        #painel-combate .botoes { display:grid; grid-template-columns:1fr; margin-top:12px; }
        #painel-combate .botoes .botao { width:100%; }
        .mochila-combate .campos { display:grid; grid-template-columns:minmax(0,1fr); }
        .mochila-combate label { min-width:0; }
        @media (max-width: 1040px) {
            .arena-bloco { grid-template-columns:minmax(0,1fr); grid-template-areas:"inimigo" "arena" "jogador" "painel" "registro"; }
            #painel-combate { position:static; max-height:none; overflow:visible; }
            #painel-combate .botoes { display:flex; flex-wrap:wrap; }
            #painel-combate .botoes .botao { width:auto; }
        }
        @media (max-width: 800px) {
            .previa { grid-template-columns: minmax(0, 1fr); }
            .rodape-arena { grid-template-columns: minmax(0, 1fr); }
            .controles { flex-direction: row; }
            .controles .botao { flex: 1; }
            .arena { min-height: 300px; aspect-ratio: 4 / 3.4; }
            .placa { font-size: 11px; width: min(52%, 220px); }
        }

        @media (prefers-reduced-motion: reduce) {
            .sprite, .flutuante { animation: none !important; }
            .barra > span, .fila .chip, .sprite.fora { transition: none; }
        }
    </style>
    <link rel="stylesheet" href="assets/peakui.css">
</head>
<body>

<header class="topo">
    <div class="informacao">
        Perfil: <strong><?= $escapar($perfil['nome_perfil']) ?></strong>
        <span> | Pokédollars: <strong id="ouro"><?= (int) $perfil['dinheiro'] ?></strong></span>
    </div>
    <div>
        <a class="botao" href="historico.php">📜 Histórico</a>
        <a class="botao" id="voltar-mapa" href="mapa.php">⇦ Voltar ao mapa</a>
    </div>
</header>

<?php if ($mensagemErro !== null): ?>
    <main>
        <div class="erro"><?= $escapar($mensagemErro) ?></div>
    </main>
<?php else: ?>
    <h1 id="titulo"><?= $escapar($titulos[$previa['categoria']] ?? 'Batalha!') ?></h1>
    <p class="subtitulo" id="subtitulo">
        <?= $escapar($previa['adversario']['emoji']) ?> <?= $escapar($previa['adversario']['nome']) ?>
    </p>

    <main>
        <!-- 1) Antes da luta: ordem do time e adversario -->
        <section id="fase-previa">
            <div class="previa">
                <div class="painel">
                    <h2>Seu time (ordem de batalha)</h2>
                    <ol class="lista" id="lista-time"></ol>
                    <p class="dica" id="dica-time">O primeiro da lista entra primeiro. Clique em um Pokémon e depois na posição de destino para trocar a ordem.</p>
                </div>

                <div class="painel">
                    <h2>Adversário</h2>
                    <div class="adversario-cabeca">
                        <span class="emoji" aria-hidden="true"><?= $escapar($previa['adversario']['emoji']) ?></span>
                        <span><?= $escapar($previa['adversario']['nome']) ?></span>
                    </div>
                    <ol class="lista" id="lista-inimigos"></ol>
                </div>
            </div>

            <p class="acoes">
                <button type="button" class="botao principal" id="botao-lutar">⚔ Lutar!</button>
            </p>
            <p class="dica<?= $previa['aviso'] !== null ? ' com-erro' : '' ?>" id="dica-lutar" role="status"><?= $escapar($previa['aviso'] ?? '') ?></p>
        </section>

        <!-- 2) Arena: a batalha automatica -->
        <section id="fase-arena" class="arena-bloco" hidden>
            <div class="fila" id="fila-inimigo" aria-label="Time adversário"></div>

            <div class="arena" id="arena">
                <div class="placa inimigo" id="placa-inimigo"></div>
                <div class="sprite inimigo" id="sprite-inimigo"></div>

                <div class="sprite jogador" id="sprite-jogador"></div>
                <div class="placa jogador" id="placa-jogador"></div>
            </div>

            <div class="fila" id="fila-jogador" aria-label="Seu time"></div>

            <div class="rodape-arena">
                <ul class="registro" id="registro" aria-live="polite" aria-label="Registro da batalha"></ul>
                <div class="controles">
                    <button type="button" class="botao" data-velocidade="1" aria-pressed="true">1x</button>
                    <button type="button" class="botao" data-velocidade="2" aria-pressed="false">2x</button>
                    <button type="button" class="botao" data-velocidade="4" aria-pressed="false">4x</button>
                    <button type="button" class="botao" id="botao-pular">Pular</button>
                </div>
            </div>
            <div class="painel-combate" id="painel-combate">
                <div class="cabecalho">
                    <h2>⚔ Batalha em andamento</h2>
                    <div class="botoes">
                        <button class="botao" type="button" id="botao-pausar">⏸ Pausar</button>
                        <button class="botao" type="button" id="botao-itens">🎒 Usar item</button>
                        <button class="botao" type="button" id="botao-proximo" hidden>▶ Próximo turno</button>
                    </div>
                </div>
                <div class="mochila-combate" id="mochila-combate" hidden>
                    <strong>Use uma Potion, cura de status ou Revive.</strong>
                    <p>Usar um item consome sua ação na rodada; o inimigo ainda ataca.</p>
                    <div class="campos">
                        <label>Item disponível<select id="escolher-item"></select></label>
                        <label>Pokémon do time<select id="escolher-alvo"></select></label>
                    </div>
                    <button class="botao principal" id="botao-usar" type="button">Aplicar item</button>
                    <button class="botao" id="botao-fechar-itens" type="button">Fechar</button>
                </div>
                <p id="dica-combate" class="dica-combate" role="status">A batalha avança automaticamente. Pause para escolher um item.</p>
            </div>
        </section>

        <!-- 3) Resultado -->
        <section id="fase-resultado" class="painel resultado" hidden></section>
    </main>

    <script>
        const DADOS = <?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // ---------- utilidades ----------
        const $ = (id) => document.getElementById(id);
        const reduzirMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Condicoes de status: simbolo + nome no gerundio + classe de cor (ver .condicao-*).
        const CONDICOES = {
            QUEIMADURA: { simbolo: '🔥', rotulo: 'QUEIMANDO', classe: 'condicao-queimadura' },
            CONGELAMENTO: { simbolo: '❄️', rotulo: 'CONGELADO', classe: 'condicao-congelamento' },
            PARALISIA: { simbolo: '⚡', rotulo: 'PARALISADO', classe: 'condicao-paralisia' },
            VENENO: { simbolo: '☠️', rotulo: 'ENVENENADO', classe: 'condicao-veneno' },
            SONO: { simbolo: '💤', rotulo: 'DORMINDO', classe: 'condicao-sono' },
        };

        function criarCondicao(status, novo) {
            const info = CONDICOES[status] || { simbolo: '❗', rotulo: String(status), classe: '' };
            const el = criar('span', 'condicao ' + info.classe + (novo ? ' novo' : ''), info.simbolo + ' ' + info.rotulo);
            el.title = info.rotulo;
            return el;
        }

        function criar(tag, classe, texto) {
            const el = document.createElement(tag);
            if (classe) el.className = classe;
            if (texto !== undefined) el.textContent = texto;
            return el;
        }

        function classeHp(hp, max) {
            const pct = max > 0 ? hp / max : 0;
            return pct > 0.5 ? '' : (pct > 0.2 ? 'medio' : 'baixo');
        }

        function urlImagem(caminho) {
            return "url('" + String(caminho).replace(/'/g, "\\'") + "')";
        }

        // Cartao de Pokemon (usado no time e no adversario antes da luta).
        function montarCartao(lutador, comoBotao) {
            const cartao = criar(comoBotao ? 'button' : 'div', 'cartao');
            if (comoBotao) cartao.type = 'button';

            if (lutador === null) {
                cartao.classList.add('vazio');
                cartao.appendChild(criar('span', '', '— vazio —'));
                return cartao;
            }

            if (lutador.desmaiado || lutador.hp <= 0) cartao.classList.add('desmaiado');

            const foto = criar('div', 'foto');
            foto.style.setProperty('--img', urlImagem(lutador.imagem));

            const dados = criar('div', 'dados');
            dados.appendChild(criar('strong', '', lutador.nome));
            dados.appendChild(criar('span', '', lutador.tipo + ' • ' + lutador.golpe + ' (' + lutador.poder + ')'));
            dados.appendChild(criar('span', '', 'HP ' + lutador.hp + '/' + lutador.hp_max));

            const barra = criar('div', 'mini-barra');
            const preenchimento = criar('span', classeHp(lutador.hp, lutador.hp_max));
            preenchimento.style.width = (lutador.hp_max > 0 ? Math.round(lutador.hp / lutador.hp_max * 100) : 0) + '%';
            barra.appendChild(preenchimento);
            dados.appendChild(barra);

            if (lutador.desmaiado || lutador.hp <= 0) dados.appendChild(criar('em', '', 'DESMAIADO'));

            cartao.append(foto, dados);
            return cartao;
        }

        // ---------- fase 1: ordem do time ----------
        const Previa = (function () {
            const lista = $('lista-time');
            const dica = $('dica-time');
            const textoPadrao = dica.textContent;
            let slots = DADOS.slots.slice();
            let origem = null;
            let ocupado = false;

            function mostrar(texto, erro) {
                dica.textContent = texto;
                dica.classList.toggle('com-erro', Boolean(erro));
            }

            function desenhar() {
                lista.textContent = '';
                slots.forEach((lutador, indice) => {
                    const item = criar('li', 'slot');
                    item.dataset.indice = String(indice);
                    if (origem === indice) item.classList.add('selecionado');

                    item.appendChild(criar('span', 'slot-numero', String(indice + 1)));
                    const cartao = montarCartao(lutador, true);
                    cartao.setAttribute('aria-label', 'Posição ' + (indice + 1) + ' do time');
                    cartao.addEventListener('click', () => clicou(indice));
                    item.appendChild(cartao);
                    lista.appendChild(item);
                });
            }

            async function clicou(indice) {
                if (ocupado) return;

                if (origem === null) {
                    if (slots[indice] === null) {
                        mostrar('Escolha primeiro um Pokémon.', true);
                        return;
                    }
                    origem = indice;
                    mostrar('Agora clique na posição de destino.', false);
                    desenhar();
                    return;
                }

                if (origem === indice) {
                    origem = null;
                    mostrar(textoPadrao, false);
                    desenhar();
                    return;
                }

                const de = origem;
                ocupado = true;

                // Reaproveita a acao que o painel TIME do mapa ja usa.
                const dados = new URLSearchParams();
                dados.set('acao', 'mover_time');
                dados.set('de', String(de + 1));
                dados.set('para', String(indice + 1));

                try {
                    const resposta = await fetch('mapa.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: dados.toString()
                    });
                    const resultado = await resposta.json();
                    if (!resposta.ok || !resultado.sucesso) {
                        throw new Error(resultado.mensagem || 'Não foi possível mover o Pokémon.');
                    }

                    const temporario = slots[de];
                    slots[de] = slots[indice];
                    slots[indice] = temporario;
                    origem = null;
                    mostrar('Ordem atualizada.', false);
                } catch (erro) {
                    origem = null;
                    mostrar(erro.message, true);
                } finally {
                    ocupado = false;
                    desenhar();
                }
            }

            function bloquear(valor) { ocupado = valor; }

            // Lista de adversarios
            const listaInimigos = $('lista-inimigos');
            DADOS.inimigos.forEach((inimigo, indice) => {
                const item = criar('li', 'slot');
                item.appendChild(criar('span', 'slot-numero', String(indice + 1)));
                item.appendChild(montarCartao(inimigo, false));
                listaInimigos.appendChild(item);
            });

            desenhar();
            return { bloquear };
        })();

        // ---------- fase 2: arena ----------
        const Arena = (function () {
            const sprite = { jogador: $('sprite-jogador'), inimigo: $('sprite-inimigo') };
            const placa = { jogador: $('placa-jogador'), inimigo: $('placa-inimigo') };
            const fila = { jogador: $('fila-jogador'), inimigo: $('fila-inimigo') };
            const registro = $('registro');
            const arena = $('arena');

            let times = { jogador: [], inimigo: [] };
            let hp = { jogador: [], inimigo: [] };
            let velocidade = 1;
            let pular = false;

            function dormir(ms) {
                if (pular) return Promise.resolve();
                return new Promise((resolver) => setTimeout(resolver, ms / velocidade));
            }

            function reiniciarClasse(el, classe) {
                el.classList.remove(classe);
                void el.offsetWidth; // reinicia a animacao
                el.classList.add(classe);
            }

            function desenharFila(lado, ativo) {
                fila[lado].textContent = '';
                times[lado].forEach((lutador, indice) => {
                    const chip = criar('div', 'chip');
                    chip.style.setProperty('--img', urlImagem(lutador.imagem));
                    chip.title = lutador.nome;
                    if (hp[lado][indice] <= 0) chip.classList.add('fora');
                    else if (indice === ativo) chip.classList.add('ativo');
                    else if (indice > ativo) chip.classList.add('espera');
                    fila[lado].appendChild(chip);
                });
            }

            function desenharPlaca(lado, indice, condicaoNova) {
                const lutador = times[lado][indice];
                const atual = hp[lado][indice];
                placa[lado].textContent = '';
                placa[lado].appendChild(criar('strong', '', lutador.nome));
                if (lutador.status) placa[lado].appendChild(criarCondicao(lutador.status, condicaoNova === true));
                placa[lado].appendChild(criar('span', 'tipo', ' ' + lutador.tipo));

                const barra = criar('div', 'barra');
                const preenchimento = criar('span', classeHp(atual, lutador.hp_max));
                preenchimento.style.width = Math.max(0, Math.round(atual / lutador.hp_max * 100)) + '%';
                barra.appendChild(preenchimento);
                placa[lado].appendChild(barra);
                placa[lado].appendChild(criar('div', '', 'HP ' + Math.max(0, atual) + '/' + lutador.hp_max));
                placa[lado]._barra = preenchimento;
                placa[lado]._texto = placa[lado].lastChild;
            }

            function atualizarHp(lado, indice, novoHp) {
                hp[lado][indice] = novoHp;
                const lutador = times[lado][indice];
                const barra = placa[lado]._barra;
                if (barra) {
                    barra.style.width = Math.max(0, Math.round(novoHp / lutador.hp_max * 100)) + '%';
                    barra.className = classeHp(novoHp, lutador.hp_max);
                    placa[lado]._texto.textContent = 'HP ' + Math.max(0, novoHp) + '/' + lutador.hp_max;
                }
                desenharFila(lado, indice);
            }

            function numeroFlutuante(lado, ev) {
                const alvo = sprite[lado];
                const caixaArena = arena.getBoundingClientRect();
                const caixa = alvo.getBoundingClientRect();

                let texto = '-' + ev.dano;
                let classe = '';
                if (ev.efetividade === 0) { texto = 'Sem efeito'; classe = 'nada'; }
                else if (ev.efetividade > 1) classe = 'super';
                else if (ev.efetividade < 1) classe = 'fraco';
                if (ev.critico) texto += '!';

                const el = criar('div', 'flutuante ' + classe, texto);
                el.style.left = (caixa.left - caixaArena.left + caixa.width / 2 - 20) + 'px';
                el.style.top = (caixa.top - caixaArena.top + caixa.height * 0.25) + 'px';
                arena.appendChild(el);
                setTimeout(() => el.remove(), 1000);
            }

            function registrar(ev) {
                const li = criar('li', ev.tipo, ev.msg);
                registro.appendChild(li);
                registro.scrollTop = registro.scrollHeight;
            }

            async function entra(ev) {
                const lado = ev.lado;
                const lutador = times[lado][ev.i];
                sprite[lado].classList.remove('fora');
                sprite[lado].style.setProperty('--img', urlImagem(lutador.imagem));
                desenharPlaca(lado, ev.i);
                desenharFila(lado, ev.i);
                if (!pular && !reduzirMovimento) reiniciarClasse(sprite[lado], 'entra-' + lado);
                await dormir(750);
            }

            async function ataque(ev) {
                const atacante = ev.lado;
                const alvo = atacante === 'jogador' ? 'inimigo' : 'jogador';

                if (!pular && !reduzirMovimento) {
                    reiniciarClasse(sprite[atacante], 'golpe-' + atacante);
                    await dormir(260);
                    if (ev.dano > 0) reiniciarClasse(sprite[alvo], 'levou');
                }
                if (!pular) numeroFlutuante(alvo, ev);
                atualizarHp(alvo, ev.alvo, ev.hp_alvo);
                await dormir(850);
            }

            async function desmaia(ev) {
                sprite[ev.lado].classList.add('fora');
                hp[ev.lado][ev.i] = 0;
                desenharFila(ev.lado, -1);
                await dormir(700);
            }

            function abrir(res) {
                times = { jogador: res.jogador, inimigo: res.inimigo };
                hp = {
                    jogador: res.jogador.map((l) => l.hp),
                    inimigo: res.inimigo.map((l) => l.hp)
                };
                registro.textContent = '';
                $('fase-previa').hidden = true;
                $('fase-arena').hidden = false;
                sprite.jogador.classList.add('fora');
                sprite.inimigo.classList.add('fora');
                desenharFila('jogador', res.iJ ?? 0);
                desenharFila('inimigo', res.iI ?? 0);
                $('fase-arena').scrollIntoView({ behavior: reduzirMovimento ? 'auto' : 'smooth', block: 'start' });
            }

            async function reproduzir(res, primeira = false) {
                if (primeira) {
                    abrir(res);
                    await dormir(400);
                }
                for (const ev of res.eventos) {
                    registrar(ev);
                    if (ev.tipo === 'entra') await entra(ev);
                    else if (ev.tipo === 'ataque') await ataque(ev);
                    else if (ev.tipo === 'desmaia') await desmaia(ev);
                    else if (ev.tipo === 'item') {
                        times.jogador[ev.i].status = ev.status;
                        if (ev.i === (res.iJ ?? ev.i)) {
                            atualizarHp('jogador', ev.i, ev.hp_alvo);
                            desenharPlaca('jogador', ev.i);
                        } else { hp.jogador[ev.i] = ev.hp_alvo; desenharFila('jogador', res.iJ); }
                        await dormir(650);
                    } else if (ev.tipo === 'status') {
                        const statusAntes = times[ev.lado][ev.i].status;
                        times[ev.lado][ev.i].status = ev.status;
                        if (ev.i === (ev.lado === 'jogador' ? res.iJ : res.iI)) desenharPlaca(ev.lado, ev.i, statusAntes !== ev.status);
                        await dormir(350);
                    } else if (ev.tipo === 'efeito_status') {
                        atualizarHp(ev.lado, ev.i, ev.hp_alvo);
                        await dormir(500);
                    }
                }
                // Mantem HP dos membros do time em sincronia com a rodada do servidor.
                times.jogador = res.jogador;
                times.inimigo = res.inimigo;
                hp.jogador = res.jogador.map(l => l.hp);
                hp.inimigo = res.inimigo.map(l => l.hp);
                desenharFila('jogador', res.iJ);
                desenharFila('inimigo', res.iI);
            }

            // Controles de velocidade e pular
            document.querySelectorAll('[data-velocidade]').forEach((botao) => {
                botao.addEventListener('click', () => {
                    velocidade = Number(botao.dataset.velocidade);
                    document.querySelectorAll('[data-velocidade]').forEach((outro) => {
                        outro.setAttribute('aria-pressed', outro === botao ? 'true' : 'false');
                    });
                });
            });
            $('botao-pular').addEventListener('click', () => { pular = true; });

            return { reproduzir };
        })();

        // ---------- fase 3: resultado ----------
        function mostrarResultado(res) {
            const caixa = $('fase-resultado');
            caixa.textContent = '';
            caixa.classList.toggle('derrota', res.resultado !== 'VITORIA');

            if (res.resultado === 'VITORIA') {
                caixa.appendChild(criar('h2', '', '🏆 Vitória!'));
                caixa.appendChild(criar('div', 'ouro', '+' + res.dinheiro_ganho + ' Pokédollars'));
                if (res.area_concluida) {
                    caixa.appendChild(criar('p', '', 'Área concluída! Você recebeu ' + res.pedras + ' Pedras Universais.'));
                    if (DADOS.adversario && DADOS.adversario.nome === 'Hiago') {
                        caixa.appendChild(criar('p', 'area-desbloqueada',
                            '🔓 A Área 2 foi desbloqueada! Continue para enfrentar Bigolin.'));
                    }
                } else {
                    caixa.appendChild(criar('p', '', 'O caminho à frente foi liberado no mapa.'));
                }
            } else if (res.perfil_reiniciado) {
                caixa.appendChild(criar('h2', '', '💀 Você perdeu!'));
                caixa.appendChild(criar('p', '', 'Todos os seus Pokémon desmaiaram. Seu progresso neste perfil foi apagado e você vai voltar para a seleção de personagem.'));
            } else {
                caixa.appendChild(criar('h2', '', 'Derrota'));
                caixa.appendChild(criar('p', '', res.time_curado
                    ? 'Seu time foi levado ao Centro Pokémon e está com a saúde cheia. Reorganize a ordem ou use a Mochila e tente de novo contra outro adversário.'
                    : 'Todos os seus Pokémon desmaiaram. Use itens na Mochila antes de tentar de novo.'));
            }

            const botoes = criar('div', 'botoes');
            if (res.resultado === 'VITORIA') {
                const continuar = criar('a', 'botao principal', 'Continuar');
                continuar.href = 'mapa.php';
                botoes.appendChild(continuar);
            } else if (res.perfil_reiniciado) {
                // Sem "tentar de novo": o perfil foi reiniciado, so resta recomecar.
                const recomecar = criar('a', 'botao principal', 'Voltar à seleção de personagem');
                recomecar.href = res.redirecionar || 'menu%20inicial/selecao.html';
                botoes.appendChild(recomecar);
                const voltarMapa = $('voltar-mapa');
                if (voltarMapa) voltarMapa.hidden = true;
            } else {
                const repetir = criar('a', 'botao principal', 'Tentar de novo');
                repetir.href = 'batalha.php';
                const voltar = criar('a', 'botao', 'Voltar ao mapa');
                voltar.href = 'mapa.php';
                botoes.append(repetir, voltar);
            }
            caixa.appendChild(botoes);
            caixa.hidden = false;
            $('ouro').textContent = String(res.dinheiro_total);
            caixa.scrollIntoView({ behavior: reduzirMovimento ? 'auto' : 'smooth', block: 'nearest' });
            botoes.querySelector('a').focus();
        }

        // ---------- batalha automatica com pausa para itens entre rodadas ----------
        (function () {
            const botao = $('botao-lutar');
            const dica = $('dica-lutar');
            const texto = $('dica-combate');
            const pausar = $('botao-pausar');
            const proximo = $('botao-proximo');
            const itens = $('botao-itens');
            const painel = $('mochila-combate');
            const seletorItem = $('escolher-item');
            const seletorAlvo = $('escolher-alvo');
            const aplicar = $('botao-usar');
            let pausado = false;
            let processando = false;
            let terminou = false;
            let time = [];
            let inventario = DADOS.itens || [];
            let comecou = false;

            async function pedir(acao, outros = {}) {
                const dados = new URLSearchParams({ acao, csrf: DADOS.csrf, ...outros });
                const resposta = await fetch('batalha.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: dados.toString()
                });
                const resultado = await resposta.json();
                if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Falha na rodada.');
                if (resultado.itens) inventario = resultado.itens;
                time = resultado.jogador;
                return resultado;
            }

            function montarSeletores() {
                seletorItem.replaceChildren();
                inventario.filter(i => i.tipo_efeito !== 'PEDRA_EVOLUCAO').forEach((item) => {
                    const o = document.createElement('option');
                    o.value = item.id;
                    o.textContent = item.nome + ' (' + item.quantidade + ')';
                    seletorItem.appendChild(o);
                });
                seletorAlvo.replaceChildren();
                time.forEach((pokemon) => {
                    const o = document.createElement('option');
                    o.value = pokemon.id_capturado;
                    o.textContent = pokemon.nome + ' — ' + pokemon.hp + '/' + pokemon.hp_max + ' HP' +
                        (pokemon.status ? ' • ' + ((CONDICOES[pokemon.status] || {}).rotulo || pokemon.status) : '') + (pokemon.hp <= 0 ? ' • desmaiado' : '');
                    seletorAlvo.appendChild(o);
                });
                aplicar.disabled = !seletorItem.options.length || processando;
            }

            function atualizarControles() {
                pausar.textContent = pausado ? '▶ Continuar' : '⏸ Pausar';
                proximo.hidden = !pausado || terminou;
                pausar.disabled = terminou;
                itens.disabled = terminou;
                aplicar.disabled = terminou || processando || !seletorItem.options.length;
            }

            async function executar(acao, campos = {}) {
                if (processando || terminou) return;
                processando = true;
                atualizarControles();
                try {
                    const res = await pedir(acao, campos);
                    texto.classList.remove('com-erro');
                    await Arena.reproduzir(res, !comecou);
                    comecou = true;
                    montarSeletores();
                    if (res.finalizada) {
                        terminou = true;
                        pausado = true;
                        painel.hidden = true;
                        $('voltar-mapa').hidden = false;
                        mostrarResultado(res);
                        texto.textContent = 'Batalha concluída.';
                    } else {
                        texto.textContent = pausado ? 'Pausado: use um item ou avance uma rodada.' : 'Batalha automática em andamento...';
                    }
                } catch (erro) {
                    pausado = true;
                    texto.textContent = erro.message;
                    texto.classList.add('com-erro');
                } finally {
                    processando = false;
                    montarSeletores();
                    atualizarControles();
                }
                if (!pausado && !terminou) void executar('rodada');
            }

            botao.addEventListener('click', async () => {
                if (processando || comecou) return;
                botao.disabled = true;
                Previa.bloquear(true);
                dica.textContent = 'Preparando os lutadores...';
                $('voltar-mapa').hidden = true;
                await executar('iniciar');
                if (!comecou) {
                    // Falha ao iniciar (ex.: sem Pokemon com HP): permite nova tentativa.
                    botao.disabled = false;
                    Previa.bloquear(false);
                    $('voltar-mapa').hidden = false;
                    dica.classList.add('com-erro');
                    dica.textContent = texto.textContent;
                }
            });
            pausar.addEventListener('click', () => {
                pausado = !pausado;
                if (!pausado) painel.hidden = true;
                atualizarControles();
                if (!pausado && !processando && !terminou) void executar('rodada');
            });
            proximo.addEventListener('click', () => { if (!processando) void executar('rodada'); });
            itens.addEventListener('click', () => {
                pausado = true;
                painel.hidden = false;
                montarSeletores();
                atualizarControles();
                texto.textContent = 'Escolha o item e o Pokémon que deve recebê-lo.';
            });
            $('botao-fechar-itens').addEventListener('click', () => { painel.hidden = true; });
            aplicar.addEventListener('click', async () => {
                if (processando || !seletorItem.value || !seletorAlvo.value) return;
                pausado = true;
                texto.classList.remove('com-erro');
                await executar('item', { id_item: seletorItem.value, id_capturado: seletorAlvo.value });
                if (!texto.classList.contains('com-erro')) painel.hidden = true;
            });
        })();
    </script>
<?php endif; ?>

</body>
</html>
