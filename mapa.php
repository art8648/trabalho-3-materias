<?php
declare(strict_types=1);

use Peakmon\Models\Inicial;
use Peakmon\Models\Mapa;
use Peakmon\Models\Time;
use Peakmon\Models\Captura;
use Peakmon\Models\Perfil;

require_once __DIR__ . '/app/sessao.php';
require_once __DIR__ . '/app/autoload.php';

$ehPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// O usuario e o perfil ativo sao obtidos da sessao, nunca do navegador.
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

$mostrarInicial = false;
$iniciais = [];
$slotsTime = [];
$roleta = [];

try {
    $pdo = require __DIR__ . '/config/database.php';
    $mapa = new Mapa($pdo);
    $inicial = new Inicial($pdo);
    $time = new Time($pdo);
    $captura = new Captura($pdo);

    // O mesmo arquivo recebe o clique do mapa e salva o novo ponto.
    if ($ehPost) {
        header('Content-Type: application/json; charset=utf-8');

        // Clique em CONFIRMAR no pop-up: salva o Pokemon inicial do perfil.
        if (($_POST['acao'] ?? '') === 'escolher_inicial') {
            $idPokemon = filter_var(
                $_POST['id_pokemon'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($idPokemon === false || $idPokemon === null) {
                http_response_code(400);
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Pokemon invalido.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode(
                $inicial->escolher($idPerfil, $idUsuario, (int) $idPokemon),
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        // Painel TIME: troca dois Pokemon de posicao (ou move para uma posicao vazia).
        if (($_POST['acao'] ?? '') === 'mover_time') {
            $de = filter_var($_POST['de'] ?? null, FILTER_VALIDATE_INT);
            $para = filter_var($_POST['para'] ?? null, FILTER_VALIDATE_INT);

            if ($de === false || $de === null || $para === false || $para === null) {
                http_response_code(400);
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Posicao invalida.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $time->mover($idPerfil, $idUsuario, (int) $de, (int) $para);

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Time atualizado.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Botao REINICIAR: apaga o inicial e o personagem SO do perfil ativo.
        if (($_POST['acao'] ?? '') === 'reiniciar_perfil') {
            (new Perfil($pdo))->reiniciarEscolhas($idPerfil, $idUsuario);

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Perfil reiniciado.',
                'redirecionar' => 'menu%20inicial/selecao.html'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Nao deixa andar pelo mapa sem ter escolhido o inicial.
        if (!$inicial->perfilTemInicial($idPerfil)) {
            throw new DomainException('Escolha seu Pokemon inicial antes de avancar no mapa.');
        }

        $idNoDestino = filter_var(
            $_POST['id_no_destino'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($idNoDestino === false || $idNoDestino === null) {
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Ponto do mapa invalido.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Se o destino for uma Pokebola, o Captura sorteia o Pokemon na mesma transacao.
        $resultado = $mapa->avancar(
            $idPerfil,
            $idUsuario,
            (int) $idNoDestino,
            [$captura, 'capturarAleatorio']
        );

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // O time e carregado primeiro: o painel aparece mesmo que o mapa falhe.
    $slotsTime = $time->listar($idPerfil);
    $dados = $mapa->carregarMapa($idPerfil, $idUsuario);
    // Pokemon que aparecem na roleta (so desenha; o sorteio e feito no servidor).
    $roleta = $captura->listarRoleta();

    // Perfil sem Pokemon ainda: o pop-up de escolha aparece em cima do mapa.
    if (!$inicial->perfilTemInicial($idPerfil)) {
        $iniciais = $inicial->listar();
        $mostrarInicial = count($iniciais) > 0;
    }

} catch (DomainException $erro) {
    if ($ehPost) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => $erro->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mensagemErro = $erro->getMessage();
    $dados = null;
} catch (Throwable $erro) {
    error_log((string) $erro);

    if ($ehPost) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Ocorreu um erro ao salvar o caminho.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mensagemErro = 'Nao foi possivel carregar o mapa. Verifique a conexao com o banco.';
    $dados = null;
}

// Se o time nao pode ser lido, mostra as posicoes vazias mesmo assim.
if ($slotsTime === []) {
    $slotsTime = array_fill(1, Time::POSICOES, null);
}

// O mapa so e desenhado quando a area existe e tem pontos cadastrados.
$mapaPronto = !isset($mensagemErro)
    && $dados !== null
    && $dados['area'] !== null
    && count($dados['nos']) > 0;

// BATALHA com codigo TREINADOR_* = treinador; os demais BATALHA = mato (Pokemon selvagem).
$iconePorTipo = static function (string $tipo, string $codigo = ''): string {
    if ($tipo === 'BATALHA' && str_starts_with($codigo, 'TREINADOR')) {
        return '🥋';
    }

    return match ($tipo) {
        'INICIO' => '🧑🏻',
        'BATALHA' => '🌿',
        'CAPTURA' => '🔴',
        'ITEM' => '🎁',
        'LOJA' => '🛒',
        'CURA' => '🏥',
        'EVENTO' => '📡',
        'CHEFE' => '⭐',
        'FIM' => '🏁',
        default => '❔'
    };
};

$idsProximos = [];
$idNoAtual = null;
$tipoNoAtual = null;

if ($dados !== null && $dados['progresso'] !== null) {
    $idNoAtual = $dados['progresso']['id_no_atual'] === null
        ? null
        : (int) $dados['progresso']['id_no_atual'];

    $tipoNoAtual = $dados['progresso']['no_atual_tipo'];

    // O jogador nao pode pular uma batalha: as proximas rotas ficam bloqueadas
    // ate que o sistema de batalha registre uma vitoria para aquele no.
    if (!in_array($tipoNoAtual, ['BATALHA', 'CHEFE'], true) || $dados['batalha_vencida']) {
        foreach ($dados['conexoes'] as $conexao) {
            if ((int) $conexao['id_no_origem'] === $idNoAtual) {
                $idsProximos[] = (int) $conexao['id_no_destino'];
            }
        }
    }
}

$escapar = static function ($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
};

// Cor do brilho de cada card, conforme o tipo (R, G, B).
$corPorTipo = static function (string $tipo): string {
    return match ($tipo) {
        'GRAMA' => '130, 200, 120',
        'FOGO' => '255, 100, 40',
        'AGUA' => '100, 170, 255',
        default => '255, 255, 255'
    };
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peakmon - Mapa</title>

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
        }

        .topo {
            width: min(calc(100% - 40px), 1280px);
            margin: 18px auto 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .informacao, .botao-menu {
            background: #eee9dc;
            border: 3px solid #292e31;
            box-shadow: 3px 3px #42484b;
            padding: 10px 14px;
        }

        .informacao strong { color: #385b43; }
        .botao-menu { color: #20252a; text-decoration: none; font-weight: bold; }
        .botao-menu:hover { background: #d9efd5; }

        h1 {
            text-align: center;
            margin: 12px 8px 18px;
            font-size: clamp(18px, 3vw, 25px);
            text-shadow: 1px 2px #fff;
        }

        .mapa {
            position: relative;
            width: min(94vw, 620px);
            height: min(150vw, 900px);
            min-height: 620px;
            margin: 0 auto 35px;
            overflow: hidden;
            border: 4px solid #24292a;
            box-shadow: 5px 5px #40494b;
            background-color: #72cda8;
            background-image:
                radial-gradient(circle at 20% 25%, #9adcb6 0 1px, transparent 2px),
                radial-gradient(circle at 72% 63%, #4dbd91 0 1px, transparent 2px),
                radial-gradient(circle at 50% 40%, #a1e0b9 0 1px, transparent 2px);
            background-size: 19px 21px, 23px 17px, 29px 25px;
        }

        .mapa::before, .mapa::after {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            width: 18px;
            z-index: 1;
            background: repeating-linear-gradient(
                135deg,
                #3c8d4e 0 7px,
                #65bd68 7px 14px,
                #b0e77a 14px 20px
            );
        }
        .mapa::before { left: 0; }
        .mapa::after { right: 0; }

        .caminhos {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
        }

        .caminhos line {
            stroke: #d6c9a1;
            stroke-width: 0.8;
            stroke-dasharray: 1.5 1.2;
        }

        .no {
            position: absolute;
            z-index: 3;
            width: 46px;
            height: 46px;
            padding: 0;
            transform: translate(-50%, -50%);
            display: flex;
            justify-content: center;
            align-items: center;
            border: 0;
            background: transparent;
            color: #20252a;
            font: inherit;
            font-size: 27px;
            cursor: default;
            filter: drop-shadow(1px 2px 0 rgba(0,0,0,.25));
        }

        button.no { cursor: pointer; }
        button.no:hover { transform: translate(-50%, -50%) scale(1.2); }
        button.no:focus-visible { outline: 3px solid white; border-radius: 50%; }

        .no.atual {
            width: 48px;
            height: 48px;
            border: 3px solid #252a2d;
            border-radius: 50%;
            background: #f3d86c;
        }

        .no.bloqueado {
            opacity: .38;
            filter: grayscale(1);
        }

        .no .dica {
            position: absolute;
            left: 50%;
            bottom: calc(100% + 7px);
            transform: translateX(-50%);
            width: max-content;
            max-width: 190px;
            padding: 7px 9px;
            border: 2px solid #252a2d;
            background: #f2eddf;
            color: #34332e;
            box-shadow: 3px 3px #42484b;
            font-size: 11px;
            font-weight: bold;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .no:hover .dica, .no:focus-visible .dica {
            opacity: 1;
            visibility: visible;
        }

        .painel-inferior {
            width: min(94vw, 620px);
            margin: -15px auto 30px;
            padding: 12px 15px;
            border: 3px solid #292e31;
            background: #eee9dc;
            box-shadow: 4px 4px #42484b;
            line-height: 1.6;
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

        .sem-area {
            width: min(94vw, 620px);
            margin: 30px auto;
            padding: 22px;
            border: 3px solid #292e31;
            background: #eee9dc;
            box-shadow: 4px 4px #42484b;
            text-align: center;
            line-height: 1.6;
        }

        .legenda {
            width: min(94vw, 620px);
            margin: 0 auto 25px;
            padding: 10px 14px;
            background: #eee9dc;
            border: 2px solid #34383a;
            font-size: 12px;
            line-height: 1.8;
        }

        @media (max-width: 520px) {
            .topo { align-items: stretch; }
            .informacao { flex: 1; }
            .mapa { min-height: 620px; }
        }

        /* ===== PAINEL: TIME (ao lado do mapa) ===== */

        /* ===== LAYOUT PRINCIPAL: TIME | MAPA | ACESSOS ===== */

        .area-jogo {
            width: min(calc(100% - 40px), 1280px);
            margin: 0 auto 30px;
            display: grid;
            grid-template-columns: 210px minmax(0, 620px) 180px;
            justify-content: center;
            align-items: start;
            gap: 18px;
        }

        .area-jogo .mapa {
            width: 100%;
            height: min(90vh, 900px);
            min-height: 620px;
            margin: 0;
        }

        .time {
            width: 100%;
            max-width: none;
            padding: 12px;
            background: #eee9dc;
            border: 3px solid #292e31;
            box-shadow: 4px 4px #42484b;
        }

        /* Espaço reservado para a futura mochila e loja.
           Nesta etapa os botões são apenas visuais, sem funcionalidades. */
        .acessos-laterais {
            width: 100%;
            padding: 12px;
            background: #eee9dc;
            border: 3px solid #292e31;
            box-shadow: 4px 4px #42484b;
        }

        .acessos-titulo {
            margin: 0 0 12px;
            text-align: center;
            font-size: 15px;
            letter-spacing: 2px;
            color: #817866;
        }

        .acesso-placeholder {
            width: 100%;
            min-height: 92px;
            margin: 0 0 10px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 7px;
            border: 2px dashed #aaa28f;
            background: #f8f4e8;
            color: #77705f;
            font: inherit;
            font-weight: bold;
            letter-spacing: 1px;
            cursor: default;
        }

        .acesso-placeholder:last-child { margin-bottom: 0; }

        .acesso-icone {
            font-size: 30px;
            line-height: 1;
        }

        .acesso-legenda {
            margin: 10px 0 0;
            color: #8a8372;
            font-size: 10px;
            line-height: 1.4;
            text-align: center;
        }

        @media (max-width: 900px) {
            .area-jogo {
                width: min(calc(100% - 24px), 620px);
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }

            .area-jogo .mapa {
                grid-row: 1;
                height: min(110vw, 900px);
                min-height: 620px;
            }

            .time { grid-row: 2; }
            .acessos-laterais {
                grid-row: 3;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .acessos-titulo,
            .acesso-legenda { grid-column: 1 / -1; }

            .acesso-placeholder { margin: 0; }
        }

        @media (max-width: 520px) {
            .area-jogo .mapa {
                min-height: 620px;
                height: 150vw;
            }
        }

        .time-titulo {
            margin: 0 0 10px;
            text-align: center;
            font-size: 18px;
            letter-spacing: 4px;
        }

        .time-lista {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .time-slot {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .time-numero {
            width: 18px;
            flex: none;
            text-align: center;
            font-weight: bold;
            color: #385b43;
        }

        .time-card {
            flex: 1;
            min-width: 0;
            min-height: 54px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 8px;
            background: #f8f4e8;
            border: 2px solid #34383a;
            cursor: pointer;
        }

        .time-card:hover,
        .time-card:focus-visible {
            background: #e1f0dc;
            outline: none;
        }

        .time-slot.vazio .time-card {
            justify-content: center;
            opacity: .6;
        }

        .time-slot.selecionado .time-card {
            background: #f3d86c;
            border-color: #20252a;
            box-shadow: 0 0 0 3px #fff;
            opacity: 1;
        }

        .time-foto {
            width: 38px;
            height: 38px;
            flex: none;
            border: 2px solid #34383a;
            border-radius: 50%;
            background-color: rgba(var(--cor), .45);
            background-image: var(--img);
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
        }

        .time-dados {
            display: flex;
            flex-direction: column;
            min-width: 0;
            font-size: 12px;
            line-height: 1.35;
        }

        .time-dados strong { font-size: 14px; }
        .time-dados em { color: #a84d38; font-style: normal; font-weight: bold; }
        .time-vazio { font-size: 12px; }

        .time-trocar {
            width: 32px;
            height: 32px;
            flex: none;
            padding: 0;
            border: 2px solid #292e31;
            background: #d9efd5;
            color: #20252a;
            font: inherit;
            font-size: 16px;
            cursor: pointer;
        }

        .time-trocar:hover { background: #b9e2b3; }

        .time-aviso {
            margin: 10px 0 0;
            min-height: 2.8em;
            font-size: 12px;
            line-height: 1.4;
            text-align: center;
        }

        .time-aviso.com-erro { color: #a8281b; }

        .mapa-aviso .erro,
        .mapa-aviso .sem-area {
            width: 100%;
            margin: 0;
        }

        @media (max-width: 900px) {
            .mapa-aviso { grid-row: 1; }
        }

        /* Link da mochila (alteracao do grupo) */
        .acesso-placeholder[href] {
            cursor: pointer;
            text-decoration: none;
            transition: transform .12s ease, background .12s ease;
        }
        .acesso-placeholder[href]:hover {
            background: #e5efd9;
            border-style: solid;
            color: #385b43;
            transform: translateY(-2px);
        }

        /* ===== ROLETA DE CAPTURA (aparece em cima do mapa) ===== */

        body.roleta-aberta { overflow: hidden; }

        .roleta-overlay {
            position: fixed;
            inset: 0;
            z-index: 120;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 110px 16px 24px;
            overflow-y: auto;
            text-align: center;
            color: #fff;
            font-family: monospace;
            background: rgba(5, 5, 5, 0.84);
            backdrop-filter: blur(3px);
            animation: roleta-aparecer 0.25s ease;
        }

        .roleta-overlay[hidden] { display: none; }

        @keyframes roleta-aparecer {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .roleta-titulo {
            margin: 0 0 6px;
            font-size: 26px;
            letter-spacing: 4px;
            text-transform: uppercase;
        }

        .roleta-sub {
            min-height: 1.4em;
            margin: 0 0 26px;
            font-size: 13px;
            letter-spacing: 2px;
            color: #b8b8b8;
        }

        .roleta-palco {
            position: relative;
            width: min(86vw, 420px);
            flex: none;
        }

        .roleta-roda {
            display: block;
            width: 100%;
            height: auto;
            transform-origin: 50% 50%;
            filter: drop-shadow(0 8px 18px rgba(0, 0, 0, 0.6));
        }

        /* Setinha fixa no topo: a fatia que parar embaixo dela e a sorteada. */
        .roleta-seta {
            position: absolute;
            z-index: 2;
            left: 50%;
            top: -16px;
            width: 0;
            height: 0;
            transform: translateX(-50%);
            border-left: 15px solid transparent;
            border-right: 15px solid transparent;
            border-top: 30px solid #f3d86c;
            filter: drop-shadow(0 2px 0 #20252a);
        }

        /* Aviso "Voce capturou ..." no topo da tela. */
        .roleta-resultado {
            position: absolute;
            top: 22px;
            left: 50%;
            z-index: 3;
            display: flex;
            align-items: center;
            gap: 14px;
            max-width: min(94vw, 560px);
            padding: 12px 18px;
            border: 3px solid #292e31;
            background: #eee9dc;
            box-shadow: 4px 4px #42484b;
            color: #20252a;
            text-align: left;
            line-height: 1.45;
            opacity: 0;
            visibility: hidden;
            transform: translate(-50%, -18px);
            transition: opacity 0.4s ease, transform 0.4s ease;
        }

        .roleta-resultado.visivel {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, 0);
        }

        .roleta-resultado img {
            width: 64px;
            height: 64px;
            flex: none;
            object-fit: contain;
            border: 2px solid #34383a;
            border-radius: 50%;
            background: #f8f4e8;
        }

        .roleta-resultado strong { display: block; font-size: 17px; }
        .roleta-resultado span { font-size: 13px; }

        @media (prefers-reduced-motion: reduce) {
            .roleta-overlay { animation: none; }
            .roleta-resultado { transition: none; }
        }

        /* ===== POP-UP: ESCOLHA DO POKEMON INICIAL ===== */

        body.inicial-aberto { overflow: hidden; }

        .inicial-overlay {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: flex;
            padding: 20px;
            overflow-y: auto;
            background: rgba(5, 5, 5, 0.82);
            backdrop-filter: blur(3px);
            animation: inicial-aparecer 0.35s ease;
        }

        @keyframes inicial-aparecer {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .inicial-modal {
            width: 100%;
            max-width: 900px;
            margin: auto;
            text-align: center;
            color: white;
            font-family: Arial, sans-serif;
        }

        .inicial-modal h2 {
            margin: 0 0 12px;
            font-size: 28px;
            letter-spacing: 5px;
            text-transform: uppercase;
        }

        .inicial-subtitulo {
            margin: 0 0 40px;
            font-size: 12px;
            letter-spacing: 3px;
            color: #888;
        }

        .inicial-lista {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 35px;
            padding: 15px 0;
        }

        /* CARD */

        .inicial-card {
            --cor: 255, 255, 255;

            width: 230px;
            height: 340px;
            padding: 0;

            position: relative;
            overflow: hidden;

            display: flex;
            align-items: flex-end;
            justify-content: center;

            background: radial-gradient(circle at 50% 35%, rgba(var(--cor), 0.35), #111 70%);
            border: 1px solid #333;
            color: white;
            font-family: Arial, sans-serif;
            cursor: pointer;

            transition:
                transform 0.35s ease,
                box-shadow 0.35s ease,
                border-color 0.35s ease;
        }

        .inicial-card::before {
            content: "";
            position: absolute;
            inset: 0;

            background-image: var(--img);
            background-size: contain;
            background-position: center 40%;
            background-repeat: no-repeat;

            opacity: 0.25;
            filter: grayscale(100%);

            transition:
                opacity 0.45s ease,
                filter 0.45s ease,
                transform 0.6s ease;
        }

        .inicial-card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(
                to top,
                rgba(0, 0, 0, 0.95),
                rgba(0, 0, 0, 0.15) 65%,
                rgba(0, 0, 0, 0.1)
            );
            pointer-events: none;
        }

        .inicial-card:hover,
        .inicial-card:focus-visible {
            transform: scale(1.08);
            border-color: rgba(255, 255, 255, 0.85);
            box-shadow:
                0 0 10px rgba(var(--cor), 0.45),
                0 0 25px rgba(var(--cor), 0.2);
            outline: none;
        }

        .inicial-card:hover::before,
        .inicial-card:focus-visible::before {
            opacity: 1;
            filter: grayscale(0%);
            transform: scale(1.08);
        }

        .inicial-card.selecionado {
            transform: scale(1.05);
            border-color: white;
            box-shadow:
                0 0 12px rgba(var(--cor), 0.7),
                0 0 30px rgba(var(--cor), 0.35);
        }

        .inicial-card.selecionado::before {
            opacity: 1;
            filter: grayscale(0%);
        }

        .inicial-info {
            position: relative;
            z-index: 2;
            width: 100%;
            padding: 25px 10px;
        }

        .inicial-info h3 {
            margin: 0;
            font-size: 19px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .inicial-info p {
            margin: 8px 0 0;
            font-size: 10px;
            letter-spacing: 2px;
            color: #aaa;
            text-transform: uppercase;
        }

        .inicial-info .inicial-status { color: #ddd; }

        /* RODAPE: TEXTO + CONFIRMAR */

        .inicial-selecao {
            margin-top: 40px;
            font-size: 13px;
            letter-spacing: 3px;
            color: #888;
        }

        .inicial-descricao {
            max-width: 520px;
            min-height: 3.2em;
            margin: 12px auto 0;
            font-size: 13px;
            line-height: 1.6;
            color: #aaa;
        }

        .inicial-erro {
            min-height: 1.4em;
            margin: 8px 0 0;
            font-size: 13px;
            color: #ff8585;
        }

        .inicial-confirmar {
            margin-top: 14px;
            padding: 14px 44px;
            background: transparent;
            color: white;
            border: 1px solid white;
            font-family: Arial, sans-serif;
            font-size: 13px;
            letter-spacing: 4px;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.3s ease, color 0.3s ease, opacity 0.3s ease;
        }

        .inicial-confirmar:hover:not(:disabled) {
            background: white;
            color: #050505;
        }

        .inicial-confirmar:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        @media (max-width: 650px) {
            .inicial-lista { gap: 25px; }
            .inicial-card { width: 200px; height: 290px; }
            .inicial-modal h2 { font-size: 22px; letter-spacing: 3px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .inicial-overlay { animation: none; }
            .inicial-card, .inicial-card::before { transition: none; }
        }
        @media (max-width: 520px) {
            .topo { align-items: stretch; }
            .informacao { flex: 1; }
            .mapa { min-height: 620px; }
        }

        .topo-acoes { display: flex; gap: 10px; flex-wrap: wrap; }
        button.botao-menu { font: inherit; font-weight: bold; cursor: pointer; }
        .botao-reiniciar:hover { background: #f6d3cb; }
        .botao-reiniciar:disabled { opacity: .5; cursor: wait; }
    </style>
</head>
<body>

<header class="topo">
    <div class="informacao">
        Perfil: <strong><?= $dados !== null ? $escapar($dados['perfil']['nome_perfil']) : $escapar($_SESSION['perfil_nome'] ?? 'Perfil') ?></strong>
        <span> | Pokédollars: <strong><?= $dados !== null ? (int) $dados['perfil']['dinheiro'] : 0 ?></strong></span>
    </div>
    <div class="topo-acoes">
        <a class="botao-menu" href="perfis.php">⇦ Trocar perfil</a>
        <button type="button" class="botao-menu botao-reiniciar" id="botao-reiniciar">↻ Reiniciar perfil</button>
    </div>
</header>

<h1>
    <?= $dados !== null && $dados['area'] !== null
        ? $escapar($dados['area']['nome'])
        : 'PEAKMON: THE BIGOLIN CRISIS' ?>
</h1>

<main>
    <div class="area-jogo">
        <aside class="time" aria-label="Seu time">
            <h2 class="time-titulo">SEU TIME</h2>

            <ol class="time-lista" id="time-lista">
                <?php foreach ($slotsTime as $posicao => $slot): ?>
                    <li class="time-slot<?= $slot === null ? ' vazio' : '' ?>" data-posicao="<?= (int) $posicao ?>">
                        <span class="time-numero"><?= (int) $posicao ?></span>

                        <div class="time-card" role="button" tabindex="0" aria-label="Posição <?= (int) $posicao ?> do time">
                            <?php if ($slot !== null): ?>
                                <div class="time-foto" style="--cor: <?= $corPorTipo((string) $slot['tipo']) ?>; --img: url('<?= $escapar($slot['imagem']) ?>');"></div>
                                <div class="time-dados">
                                    <strong><?= $escapar($slot['nome']) ?></strong>
                                    <span><?= $escapar($slot['tipo']) ?> • HP <?= (int) $slot['hp_atual'] ?>/<?= (int) $slot['hp_max'] ?></span>
                                    <?php if ((int) $slot['desmaiado'] === 1): ?><em>DESMAIADO</em><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="time-vazio">— vazio —</span>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="time-trocar" aria-label="Mover posição <?= (int) $posicao ?>" title="Trocar de posição">⇄</button>
                    </li>
                <?php endforeach; ?>
            </ol>

            <p class="time-aviso" id="time-aviso">Clique em um Pokémon e depois na posição para onde quer levá-lo.</p>
        </aside>

        <?php if (!$mapaPronto): ?>
            <div class="mapa-aviso">
                <?php if (isset($mensagemErro)): ?>
                    <div class="erro"><?= $escapar($mensagemErro) ?></div>
                <?php elseif ($dados === null): ?>
                    <div class="erro">Nao foi possivel carregar os dados do mapa.</div>
                <?php elseif ($dados['area'] === null): ?>
                    <div class="sem-area">
                        <h2>O mapa ainda nao foi cadastrado!</h2>
                        <p>O banco ainda nao tem o mapa. Importe o arquivo <strong>peakmon_base.sql</strong> no MySQL.</p>
                    </div>
                <?php else: ?>
                    <div class="sem-area">
                        <h2>Esta area ainda nao possui pontos.</h2>
                        <p>Cadastre os nos da area na tabela <strong>area_no</strong>.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <section class="mapa" aria-label="Mapa da area">
            <svg class="caminhos" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <?php foreach ($dados['conexoes'] as $conexao): ?>
                    <line
                        x1="<?= max(3, min(97, (int) $conexao['origem_x'])) ?>"
                        y1="<?= max(3, min(97, (int) $conexao['origem_y'])) ?>"
                        x2="<?= max(3, min(97, (int) $conexao['destino_x'])) ?>"
                        y2="<?= max(3, min(97, (int) $conexao['destino_y'])) ?>"
                    />
                <?php endforeach; ?>
            </svg>

            <?php foreach ($dados['nos'] as $no): ?>
                <?php
                    $idNo = (int) $no['id'];
                    $atual = $idNoAtual === $idNo;
                    $proximo = in_array($idNo, $idsProximos, true);
                    $x = max(3, min(97, (int) $no['pos_x']));
                    $y = max(3, min(97, (int) $no['pos_y']));
                    $icone = $iconePorTipo($no['tipo_no'], (string) $no['codigo']);
                    $tituloNo = $no['titulo'];
                    $descricaoNo = $no['descricao'] ?? '';
                ?>

                <?php if ($proximo): ?>
                    <button
                        type="button"
                        class="no"
                        style="left: <?= $x ?>%; top: <?= $y ?>%;"
                        data-id-no-destino="<?= $idNo ?>"
                        data-tipo="<?= $escapar($no['tipo_no']) ?>"
                        aria-label="Avancar para <?= $escapar($tituloNo) ?>"
                    >
                        <?= $icone ?>
                        <span class="dica"><?= $escapar($tituloNo) ?><?= $descricaoNo !== '' ? ' — ' . $escapar($descricaoNo) : '' ?></span>
                    </button>
                <?php else: ?>
                    <div
                        class="no <?= $atual ? 'atual' : 'bloqueado' ?>"
                        style="left: <?= $x ?>%; top: <?= $y ?>%;"
                        aria-label="<?= $escapar($tituloNo) ?>"
                    >
                        <?= $icone ?>
                        <span class="dica"><?= $escapar($tituloNo) ?><?= $atual ? ' — Ponto atual' : '' ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <aside class="acessos-laterais" aria-label="Acessos do jogo">
            <h2 class="acessos-titulo">ACESSOS</h2>

            <a class="acesso-placeholder" href="mochila.php" aria-label="Abrir mochila e gerenciar itens e Pokemon">
                <span class="acesso-icone" aria-hidden="true">🎒</span>
                <span>MOCHILA</span>
            </a>

            <div class="acesso-placeholder" aria-label="Espaco reservado para loja">
                <span class="acesso-icone" aria-hidden="true">🛒</span>
                <span>LOJA</span>
            </div>

            <p class="acesso-legenda">Espaços reservados para as próximas etapas.</p>
        </aside>
        </div>

        <?php if ($mapaPronto): ?>
        <section class="painel-inferior">
            <?php if (($tipoNoAtual === 'BATALHA' || $tipoNoAtual === 'CHEFE') && !$dados['batalha_vencida']): ?>
                <strong>Você chegou a um ponto de batalha!</strong><br>
                O caminho seguinte será liberado depois da vitória. A página de batalha será conectada na próxima etapa.
            <?php elseif (($tipoNoAtual === 'BATALHA' || $tipoNoAtual === 'CHEFE') && $dados['batalha_vencida']): ?>
                <strong>Batalha vencida!</strong><br>
                Agora você pode escolher um dos próximos caminhos.
            <?php elseif ($dados['progresso']['estado'] === 'CONCLUIDA'): ?>
                <strong>Área concluída!</strong><br>
                Você chegou ao final deste mapa.
            <?php else: ?>
                <strong>Ponto atual:</strong>
                <?= $escapar($dados['progresso']['no_atual_titulo'] ?? 'Inicio') ?><br>
                Clique em um dos pontos destacados para escolher seu próximo caminho.
            <?php endif; ?>
        </section>

        <section class="legenda">
            🌿 Mato (Pokémon selvagem) &nbsp; 🥋 Treinador &nbsp; 🔴 Captura &nbsp; 🎁 Item<br>
            🛒 Loja &nbsp; 🏥 Centro Pokémon &nbsp; 📡 Evento &nbsp; ⭐ Chefe
        </section>
        <?php endif; ?>
    </main>

<?php if ($mostrarInicial): ?>
    <div class="inicial-overlay" id="inicial-overlay" role="dialog" aria-modal="true" aria-labelledby="inicial-titulo">
        <div class="inicial-modal">

            <h2 id="inicial-titulo">Escolha seu inicial</h2>
            <p class="inicial-subtitulo">ESTE SERÁ SEU PRIMEIRO PARCEIRO NA JORNADA</p>

            <div class="inicial-lista">
                <?php foreach ($iniciais as $pokemon): ?>
                    <button
                        type="button"
                        class="inicial-card"
                        style="--cor: <?= $corPorTipo((string) $pokemon['tipo']) ?>; --img: url('<?= $escapar($pokemon['imagem']) ?>');"
                        data-id-pokemon="<?= (int) $pokemon['id'] ?>"
                        data-nome="<?= $escapar($pokemon['nome']) ?>"
                        data-descricao="<?= $escapar($pokemon['descricao']) ?>"
                        aria-pressed="false"
                    >
                        <div class="inicial-info">
                            <h3><?= $escapar($pokemon['nome']) ?></h3>
                            <p><?= $escapar($pokemon['tipo']) ?> • <?= $escapar($pokemon['nome_ataque']) ?></p>
                            <p class="inicial-status">
                                HP <?= (int) $pokemon['hp'] ?> &nbsp;
                                ATK <?= (int) $pokemon['atk'] ?> &nbsp;
                                VEL <?= (int) $pokemon['vel'] ?>
                            </p>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="inicial-selecao" id="inicial-selecao">NENHUM POKÉMON SELECIONADO</div>
            <p class="inicial-descricao" id="inicial-descricao"></p>
            <p class="inicial-erro" id="inicial-erro" role="alert"></p>

            <button type="button" class="inicial-confirmar" id="inicial-confirmar" disabled>Confirmar</button>

        </div>
    </div>
<?php endif; ?>

<div class="roleta-overlay" id="roleta-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="roleta-titulo">
    <div class="roleta-resultado" id="roleta-resultado" aria-live="polite"></div>

    <h2 class="roleta-titulo" id="roleta-titulo">Pokébola!</h2>
    <p class="roleta-sub" id="roleta-sub">Girando a roleta...</p>

    <div class="roleta-palco">
        <div class="roleta-seta" aria-hidden="true"></div>
        <svg class="roleta-roda" id="roleta-roda" viewBox="0 0 400 400" aria-hidden="true"></svg>
    </div>
</div>

<script>
    // Pokemon da roleta de captura (o sorteio e feito no servidor; aqui so se desenha).
    const ROLETA = <?= json_encode($roleta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // ===== Roleta de captura =====
    const Roleta = (function () {
        const overlay = document.getElementById('roleta-overlay');
        const roda = document.getElementById('roleta-roda');
        const sub = document.getElementById('roleta-sub');
        const resultado = document.getElementById('roleta-resultado');
        const total = ROLETA.length;
        const fatia = total > 0 ? 360 / total : 360;
        let montada = false;
        let podePular = false;

        const esperar = (ms) => new Promise((resolver) => setTimeout(resolver, ms));

        // Ponto na roda: o angulo conta em graus, no sentido horario, a partir do topo.
        function ponto(raio, graus) {
            const rad = graus * Math.PI / 180;
            return [200 + raio * Math.sin(rad), 200 - raio * Math.cos(rad)];
        }

        function montar() {
            if (montada || total === 0) return;
            montada = true;

            const NS = 'http://www.w3.org/2000/svg';
            const criar = (nome, atributos) => {
                const elemento = document.createElementNS(NS, nome);
                Object.keys(atributos).forEach((chave) => elemento.setAttribute(chave, atributos[chave]));
                return elemento;
            };

            ROLETA.forEach((pokemon, i) => {
                const inicio = i * fatia;
                const fim = (i + 1) * fatia;
                const meio = inicio + fatia / 2;
                const [x0, y0] = ponto(190, inicio);
                const [x1, y1] = ponto(190, fim);

                roda.appendChild(criar('path', {
                    d: 'M200 200 L' + x0 + ' ' + y0 + ' A190 190 0 0 1 ' + x1 + ' ' + y1 + ' Z',
                    fill: 'hsl(' + Math.round(i * 360 / total) + ', 65%, 74%)',
                    stroke: '#292e31',
                    'stroke-width': 2
                }));

                // Imagem e nome ficam num grupo girado para o meio da fatia.
                const grupo = criar('g', { transform: 'rotate(' + meio + ' 200 200)' });

                const imagem = criar('image', {
                    href: pokemon.imagem,
                    x: 200 - 20,
                    y: 200 - 124 - 20,
                    width: 40,
                    height: 40
                });
                grupo.appendChild(imagem);

                const nome = criar('text', {
                    x: 200,
                    y: 200 - 166,
                    'text-anchor': 'middle',
                    'font-size': 10,
                    'font-weight': 'bold',
                    'font-family': 'monospace',
                    fill: '#20252a'
                });
                nome.textContent = pokemon.nome;
                grupo.appendChild(nome);

                roda.appendChild(grupo);
            });

            roda.appendChild(criar('circle', {
                cx: 200, cy: 200, r: 190, fill: 'none', stroke: '#292e31', 'stroke-width': 6
            }));
            roda.appendChild(criar('circle', {
                cx: 200, cy: 200, r: 24, fill: '#eee9dc', stroke: '#292e31', 'stroke-width': 4
            }));
        }

        function abrir() {
            montar();

            roda.style.transition = 'none';
            roda.style.transform = 'rotate(0deg)';
            roda.getBoundingClientRect(); // forca o navegador a aplicar o reset

            resultado.classList.remove('visivel');
            resultado.textContent = '';
            sub.textContent = 'Girando a roleta...';
            podePular = false;

            overlay.hidden = false;
            document.body.classList.add('roleta-aberta');
        }

        function fechar() {
            overlay.hidden = true;
            document.body.classList.remove('roleta-aberta');
        }

        // Gira ate parar no Pokemon que o servidor sorteou, mostra o aviso e recarrega o mapa.
        async function girarAte(captura) {
            let indice = ROLETA.findIndex((pokemon) => pokemon.id === captura.id_pokemon);
            if (indice < 0) {
                indice = ROLETA.findIndex((pokemon) => pokemon.nome === captura.nome);
            }
            if (indice < 0) {
                window.location.reload();
                return;
            }

            const reduzir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const duracao = reduzir ? 700 : 5200;
            const voltas = reduzir ? 1 : 6;

            // Para o ponteiro (no topo) cair na fatia certa, em qualquer ponto dela.
            const meio = indice * fatia + fatia / 2;
            const folga = (Math.random() - 0.5) * fatia * 0.7;
            const alvo = voltas * 360 - meio + folga;

            roda.style.transition = 'transform ' + duracao + 'ms cubic-bezier(0.12, 0.62, 0.08, 1)';
            roda.getBoundingClientRect();
            roda.style.transform = 'rotate(' + alvo + 'deg)';

            await Promise.race([
                new Promise((resolver) => roda.addEventListener('transitionend', resolver, { once: true })),
                esperar(duracao + 600)
            ]);

            sub.textContent = '';
            mostrarResultado(captura);

            // Fica um instante na tela; clicar acelera.
            podePular = true;
            await Promise.race([
                esperar(4200),
                new Promise((resolver) => overlay.addEventListener('click', resolver, { once: true }))
            ]);

            // A roleta sai e o mapa e atualizado (posicao nova e time).
            window.location.reload();
        }

        function mostrarResultado(captura) {
            resultado.textContent = '';

            const foto = document.createElement('img');
            foto.src = captura.imagem;
            foto.alt = captura.nome;

            const texto = document.createElement('div');
            const titulo = document.createElement('strong');
            titulo.textContent = 'Você capturou ' + captura.nome + '!';
            const detalhe = document.createElement('span');
            detalhe.textContent = captura.destino === 'time'
                ? 'Ele entrou no seu time (posição ' + captura.posicao + ').'
                : 'Seu time está cheio (5/5): ' + captura.nome + ' foi para o PC (Arquivo Bigolin, na Mochila).';

            texto.appendChild(titulo);
            texto.appendChild(detalhe);
            resultado.appendChild(foto);
            resultado.appendChild(texto);
            resultado.classList.add('visivel');
        }

        return { abrir, fechar, girarAte, total };
    })();

    // Cada clique envia apenas o ID do proximo no. O perfil ativo fica na sessao PHP.
    document.querySelectorAll('[data-id-no-destino]').forEach((botao) => {
        botao.addEventListener('click', async () => {
            botao.disabled = true;

            // Pokebola: a roleta abre em cima do mapa, sem sair da pagina.
            const ehCaptura = botao.dataset.tipo === 'CAPTURA' && Roleta.total > 0;
            if (ehCaptura) Roleta.abrir();

            const dados = new URLSearchParams();
            dados.set('id_no_destino', botao.dataset.idNoDestino);

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
                    throw new Error(resultado.mensagem || 'Nao foi possivel salvar o caminho.');
                }

                if (ehCaptura && resultado.captura) {
                    await Roleta.girarAte(resultado.captura);
                    return;
                }

                window.location.reload();

            } catch (erro) {
                if (ehCaptura) Roleta.fechar();
                alert(erro.message);
                botao.disabled = false;
            }
        });
    });

    // ===== Pop-up de escolha do Pokemon inicial =====
    (function () {
        const overlay = document.getElementById('inicial-overlay');
        if (!overlay) return;

        document.body.classList.add('inicial-aberto');

        const cards = overlay.querySelectorAll('.inicial-card');
        const textoSelecao = document.getElementById('inicial-selecao');
        const textoDescricao = document.getElementById('inicial-descricao');
        const textoErro = document.getElementById('inicial-erro');
        const botaoConfirmar = document.getElementById('inicial-confirmar');
        let idEscolhido = null;

        cards.forEach((card) => {
            card.addEventListener('click', () => {
                cards.forEach((outro) => {
                    outro.classList.remove('selecionado');
                    outro.setAttribute('aria-pressed', 'false');
                });

                card.classList.add('selecionado');
                card.setAttribute('aria-pressed', 'true');

                idEscolhido = card.dataset.idPokemon;
                textoSelecao.textContent = 'SELECIONADO: ' + card.dataset.nome.toUpperCase();
                textoDescricao.textContent = card.dataset.descricao;
                textoErro.textContent = '';
                botaoConfirmar.disabled = false;
            });
        });

        botaoConfirmar.addEventListener('click', async () => {
            if (idEscolhido === null) return;

            botaoConfirmar.disabled = true;
            textoErro.textContent = '';

            const dados = new URLSearchParams();
            dados.set('acao', 'escolher_inicial');
            dados.set('id_pokemon', idEscolhido);

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
                    throw new Error(resultado.mensagem || 'Nao foi possivel salvar a escolha.');
                }

                window.location.reload();

            } catch (erro) {
                textoErro.textContent = erro.message;
                botaoConfirmar.disabled = false;
            }
        });

        // Foco no primeiro card para dar para escolher so com o teclado.
        if (cards.length > 0) cards[0].focus();
    })();
    // ===== Painel TIME: trocar Pokemon de posicao =====
    (function () {
        const lista = document.getElementById('time-lista');
        if (!lista) return;

        const aviso = document.getElementById('time-aviso');
        const textoPadrao = aviso.textContent;
        let origem = null;      // posicao (li) escolhida primeiro
        let ocupado = false;    // evita cliques enquanto o servidor responde

        function mostrar(texto, erro) {
            aviso.textContent = texto;
            aviso.classList.toggle('com-erro', Boolean(erro));
        }

        function limpar() {
            if (origem) origem.classList.remove('selecionado');
            origem = null;
        }

        async function clicou(slot) {
            if (ocupado) return;

            // 1o clique: escolhe o Pokemon que vai mudar de lugar.
            if (origem === null) {
                if (slot.classList.contains('vazio')) {
                    mostrar('Escolha primeiro um Pokémon.', true);
                    return;
                }
                origem = slot;
                slot.classList.add('selecionado');
                mostrar('Agora clique na posição de destino.', false);
                return;
            }

            // Clicou na mesma posicao de novo: cancela.
            if (origem === slot) {
                limpar();
                mostrar(textoPadrao, false);
                return;
            }

            // 2o clique: pede ao servidor para trocar (ou mover para posicao vazia).
            const de = origem;
            ocupado = true;

            const dados = new URLSearchParams();
            dados.set('acao', 'mover_time');
            dados.set('de', de.dataset.posicao);
            dados.set('para', slot.dataset.posicao);

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
                    throw new Error(resultado.mensagem || 'Nao foi possivel mover o Pokemon.');
                }

                // Troca o conteudo das duas posicoes na tela.
                const cartaoDe = de.querySelector('.time-card');
                const cartaoPara = slot.querySelector('.time-card');
                const htmlDe = cartaoDe.innerHTML;
                cartaoDe.innerHTML = cartaoPara.innerHTML;
                cartaoPara.innerHTML = htmlDe;

                const deVazio = de.classList.contains('vazio');
                const paraVazio = slot.classList.contains('vazio');
                de.classList.toggle('vazio', paraVazio);
                slot.classList.toggle('vazio', deVazio);

                limpar();
                mostrar('Time atualizado!', false);

            } catch (erro) {
                limpar();
                mostrar(erro.message, true);
            } finally {
                ocupado = false;
            }
        }

        lista.querySelectorAll('.time-slot').forEach((slot) => {
            const cartao = slot.querySelector('.time-card');
            const botao = slot.querySelector('.time-trocar');

            cartao.addEventListener('click', () => clicou(slot));
            cartao.addEventListener('keydown', (evento) => {
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    clicou(slot);
                }
            });
            botao.addEventListener('click', () => clicou(slot));
        });
    })();
        // ===== Botao REINICIAR PERFIL =====
    (function () {
        const botao = document.getElementById('botao-reiniciar');
        if (!botao) return;

        botao.addEventListener('click', async () => {
            const certeza = confirm(
                'Reiniciar este perfil?\n\n' +
                'Seu personagem e seus Pokémon (inclusive o inicial) serão apagados, ' +
                'e sua posição no mapa volta para o início. ' +
                'Os outros perfis não são afetados.'
            );
            if (!certeza) return;

            botao.disabled = true;

            const dados = new URLSearchParams();
            dados.set('acao', 'reiniciar_perfil');

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
                    throw new Error(resultado.mensagem || 'Nao foi possivel reiniciar o perfil.');
                }

                window.location.href = resultado.redirecionar;

            } catch (erro) {
                alert(erro.message);
                botao.disabled = false;
            }
        });
    })();
</script>

</body>
</html>