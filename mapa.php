<?php
declare(strict_types=1);

use Peakmon\Models\Inicial;
use Peakmon\Models\Mapa;
use Peakmon\Models\Time;
use Peakmon\Models\Captura;
use Peakmon\Models\Perfil;
use Peakmon\Models\SinalEstranho;

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
$personagemNome = null;
$iniciais = [];
$slotsTime = [];
$roleta = [];
$roletaItens = [];

try {
    $pdo = require __DIR__ . '/config/database.php';
    $mapa = new Mapa($pdo);
    $inicial = new Inicial($pdo);
    $time = new Time($pdo);
    $captura = new Captura($pdo);
    $sinal = new SinalEstranho($pdo);

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

        // Nao troca integrantes de uma equipe enquanto a luta guarda seu estado.
        if (($_POST['acao'] ?? '') === 'mover_time' && !empty($_SESSION['peakmon_batalha_interativa'][$idPerfil])) {
            throw new DomainException('Termine a batalha atual antes de mudar a equipe.');
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
            // Uma batalha em andamento pode estar na sessao: descartar ao zerar o save.
            unset($_SESSION['peakmon_batalha_interativa'][$idPerfil],
                $_SESSION['peakmon_sinal'][$idPerfil], $_SESSION['peakmon_sinal_resolvido'][$idPerfil]);
            foreach (array_keys($_SESSION['peakmon_encontro'] ?? []) as $chave) {
                if (str_starts_with((string) $chave, $idPerfil . ':')) {
                    unset($_SESSION['peakmon_encontro'][$chave]);
                }
            }

            // O reinicio tambem deve devolver o saldo do perfil a zero.
            $zerarSaldo = $pdo->prepare('UPDATE perfil SET dinheiro = 0 WHERE id = :perfil');
            $zerarSaldo->execute(['perfil' => $idPerfil]);

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Perfil reiniciado.',
                'redirecionar' => 'menu%20inicial/selecao.html'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // ===== Evento "Sinal estranho": trocar um Pokemon por outro =====
        // So funciona logo depois de chegar num ponto EVENTO (a sessao guarda esse aviso).
        $acaoSinal = (string) ($_POST['acao'] ?? '');
        if (in_array($acaoSinal, ['sinal_dados', 'sinal_trocar', 'sinal_ignorar'], true)) {
            if ((int) ($_SESSION['peakmon_sinal'][$idPerfil] ?? 0) <= 0) {
                throw new DomainException('Nenhum sinal estranho esta ativo agora.');
            }

            if ($acaoSinal === 'sinal_dados') {
                echo json_encode([
                    'sucesso' => true,
                    'meus' => $sinal->listarDoJogador($idPerfil, $idUsuario),
                    'especies' => $sinal->listarEspecies()
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($acaoSinal === 'sinal_ignorar') {
                $noSinal = (int) $_SESSION['peakmon_sinal'][$idPerfil];
                $_SESSION['peakmon_sinal_resolvido'][$idPerfil][$noSinal] = true;
                unset($_SESSION['peakmon_sinal'][$idPerfil]);
                echo json_encode(['sucesso' => true, 'mensagem' => 'Voce ignorou o sinal.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $idCapturado = filter_var($_POST['id_capturado'] ?? null, FILTER_VALIDATE_INT);
            $idNovaEspecie = filter_var($_POST['id_especie'] ?? null, FILTER_VALIDATE_INT);

            if ($idCapturado === false || $idCapturado === null || $idNovaEspecie === false || $idNovaEspecie === null) {
                throw new DomainException('Escolha o seu Pokemon e a especie nova.');
            }

            $resultadoTroca = $sinal->trocar($idPerfil, $idUsuario, (int) $idCapturado, (int) $idNovaEspecie);
            $noSinal = (int) $_SESSION['peakmon_sinal'][$idPerfil];
            $_SESSION['peakmon_sinal_resolvido'][$idPerfil][$noSinal] = true;
            unset($_SESSION['peakmon_sinal'][$idPerfil]);

            echo json_encode(['sucesso' => true] + $resultadoTroca, JSON_UNESCAPED_UNICODE);
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

        // Andar para outro ponto encerra qualquer sinal que ficou pendente.
        unset($_SESSION['peakmon_sinal'][$idPerfil]);

        // Se o destino for uma Pokebola, o Captura sorteia o Pokemon na mesma transacao.
        $resultado = $mapa->avancar(
            $idPerfil,
            $idUsuario,
            (int) $idNoDestino,
            [$captura, 'capturarAleatorio']
        );

        // Ponto EVENTO = "Sinal estranho": o navegador abre a escolha de troca.
        if (($resultado['no']['tipo_no'] ?? '') === 'EVENTO') {
            $noSinal = (int) $resultado['no']['id'];
            unset($_SESSION['peakmon_sinal_resolvido'][$idPerfil][$noSinal]);
            $_SESSION['peakmon_sinal'][$idPerfil] = $noSinal;
            $resultado['evento'] = ['tipo' => 'SINAL_ESTRANHO'];
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // O modal do inicial precisa ser preparado ANTES do mapa.
    // Assim, uma falha no carregamento dos nos nao esconde a escolha.
    if (!$inicial->perfilTemInicial($idPerfil)) {
        $iniciais = $inicial->listar();
        $mostrarInicial = count($iniciais) > 0;
    }

    $slotsTime = $time->listar($idPerfil);
    $dados = $mapa->carregarMapa($idPerfil, $idUsuario);
    // Nome do personagem escolhido no perfil (Link, The Knight, Mereoleona...).
    $consultaPersonagem = $pdo->prepare('
        SELECT c.nome
        FROM perfil p
        JOIN personagem c ON c.id = p.id_personagem
        WHERE p.id = :id_perfil AND p.id_usuario = :id_usuario
        LIMIT 1
    ');
    $consultaPersonagem->execute(['id_perfil' => $idPerfil, 'id_usuario' => $idUsuario]);
    $personagemNome = $consultaPersonagem->fetchColumn() ?: null;
    // Pokemon que aparecem na roleta (so desenha; o sorteio e feito no servidor).
    $roleta = $captura->listarRoleta();
    // Itens que aparecem na roleta do "Item escondido" (so desenha; o sorteio e no servidor).
    $roletaItens = $mapa->listarRoletaItens();
    // Sprite de cada item (as mesmas imagens da Mochila e da Loja, em images/itens/).
    // Se o arquivo nao existir, a roleta cai de volta no emoji.
    $arquivosItens = [
        'Potion' => 'potion.jpg', 'Super Potion' => 'super_potion.webp',
        'Hyper Potion' => 'hyper_potion.webp', 'Max Potion' => 'max_potion.webp',
        'Full Restore' => 'full_restore.jpg', 'Antidote' => 'antidote.webp',
        'Burn Heal' => 'burn_heal.webp', 'Ice Heal' => 'ice_heal.webp',
        'Awakening' => 'awakening.webp', 'Paralyze Heal' => 'paralyze_heal.webp',
        'Full Heal' => 'full_heal.webp', 'Revive' => 'revive.webp',
        'Pedra Universal' => 'pedra_universal.png',
    ];
    foreach ($roletaItens as &$itemRoleta) {
        $arquivo = $arquivosItens[$itemRoleta['nome']] ?? '';
        $itemRoleta['imagem'] = ($arquivo !== '' && is_file(__DIR__ . '/images/itens/' . $arquivo))
            ? 'images/itens/' . $arquivo
            : '';
    }
    unset($itemRoleta);

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

// Imagens dos pontos do mapa, em images/mapa/. A extensao (png, webp, jpg...) e descoberta no disco;
// se a imagem nao existir, o emoji original continua aparecendo.
$imagemMapa = static function (string $nome): string {
    foreach ([$nome, str_replace(' ', '_', $nome)] as $base) {
        foreach (['png', 'webp', 'jpg', 'jpeg', 'gif'] as $extensao) {
            if (is_file(__DIR__ . '/images/mapa/' . $base . '.' . $extensao)) {
                return 'images/mapa/' . rawurlencode($base) . '.' . $extensao;
            }
        }
    }
    return '';
};
$miniIcone = static function (string $arquivo, string $emoji, string $classe = 'legenda-img') use ($imagemMapa): string {
    $src = $imagemMapa($arquivo);
    return $src !== ''
        ? '<img class="' . $classe . '" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" draggable="false">'
        : $emoji;
};
$iconeNoHtml = static function (string $tipo, string $codigo = '') use ($iconePorTipo, $miniIcone): string {
    // Os pontos ITEM, EVENTO, CAPTURA e INICIO usam SVG transparente; a logica de troca continua intacta.
    if ($tipo === 'ITEM' || $tipo === 'EVENTO' || $tipo === 'CAPTURA' || $tipo === 'INICIO') {
        $nomeArquivo = match ($tipo) {
            'ITEM' => 'item',
            'EVENTO' => 'evento',
            'INICIO' => 'inicio',
            default => 'captura',
        };
        return '<img class="no-pixel-icon" src="images/mapa/' . $nomeArquivo . '.svg" alt="" draggable="false">';
    }
    // Chefe final: imagem do Hiago (images/mapa/hiago.png), maior que os demais pontos.
    if ($tipo === 'CHEFE') {
        return '<img class="no-pixel-icon no-chefe" src="images/mapa/hiago.png" alt="" draggable="false">';
    }
    $arquivo = match ($tipo) {
        'BATALHA' => str_starts_with($codigo, 'TREINADOR') ? 'treinador' : 'mato',
        'CURA' => 'centro pokemon',
        'EVENTO' => 'sinal_estranho',
        default => '',
    };
    $emoji = $iconePorTipo($tipo, $codigo);
    return $arquivo !== '' ? $miniIcone($arquivo, $emoji, 'no-img') : $emoji;
};

// Imagem do personagem em images/personagens/<Nome>.<ext> (ex.: Link.png, The Knight.png).
// Se o arquivo ainda nao existir, o ponto atual continua com o icone normal.
$imagemPersonagem = static function (?string $nome): string {
    if ($nome === null || $nome === '' || str_contains($nome, '/') || str_contains($nome, '\\') || str_contains($nome, '..')) {
        return '';
    }
    foreach ([$nome, str_replace(' ', '_', $nome)] as $base) {
        foreach (['png', 'webp', 'jpg', 'jpeg', 'gif', 'svg'] as $extensao) {
            if (is_file(__DIR__ . '/images/personagens/' . $base . '.' . $extensao)) {
                return 'images/personagens/' . rawurlencode($base) . '.' . $extensao;
            }
        }
    }
    return '';
};
$srcPersonagem = $imagemPersonagem($personagemNome);

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

// Recupera inclusive um ponto EVENTO visitado quando a versao antiga nao abria o modal.
// Depois que o jogador troca ou ignora, a sessao marca o no como resolvido.
if ($idNoAtual !== null && $tipoNoAtual === 'EVENTO'
    && empty($_SESSION['peakmon_sinal_resolvido'][$idPerfil][$idNoAtual])
    && (int) ($_SESSION['peakmon_sinal'][$idPerfil] ?? 0) !== $idNoAtual) {
    $_SESSION['peakmon_sinal'][$idPerfil] = $idNoAtual;
}
// Sinal estranho ainda nao resolvido neste ponto (ex.: a pagina foi recarregada).
$sinalPendente = $idNoAtual !== null
    && $tipoNoAtual === 'EVENTO'
    && (int) ($_SESSION['peakmon_sinal'][$idPerfil] ?? 0) === $idNoAtual;

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

        .indice-area {
            width: fit-content;
            max-width: calc(100% - 24px);
            margin: 13px auto 0;
            padding: 6px 14px;
            background: #132d4a;
            border: 2px solid #081629;
            box-shadow: 3px 3px 0 #6a90a2;
            color: #b8ffda;
            text-align: center;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 1.4px;
        }

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

        .no-img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            image-rendering: pixelated;
            pointer-events: none;
            user-select: none;
        }
        .legenda-img {
            width: 20px;
            height: 20px;
            object-fit: contain;
            image-rendering: pixelated;
            vertical-align: middle;
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

        .no .dica.dica-baixo {
            bottom: auto;
            top: calc(100% + 7px);
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
            grid-template-columns: 252px minmax(0, 620px) 180px;
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
            overflow: hidden;
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
            flex: 1 1 0;
            min-width: 0;
            font-size: 12px;
            line-height: 1.35;
        }

        .time-dados strong { font-size: 14px; white-space: nowrap; }
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
        .roleta-dica-pular { color:#fff3c6; font-size:12px; margin:8px auto 12px; cursor:pointer; }
        .roleta-overlay:not([hidden]) { cursor:pointer; }


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

        /* Emoji do item no aviso da roleta (no lugar da foto do Pokemon) */
        .roleta-resultado .roleta-emoji {
            width: 64px;
            height: 64px;
            flex: none;
            display: grid;
            place-items: center;
            font-size: 34px;
            border: 2px solid #34383a;
            border-radius: 50%;
            background: #f8f4e8;
        }

        /* ===== EVENTO: SINAL ESTRANHO (trocar um Pokemon por outro) ===== */

        .sinal-overlay {
            position: fixed;
            inset: 0;
            z-index: 125;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            overflow-y: auto;
            background: rgba(3, 8, 10, 0.88);
            backdrop-filter: blur(3px);
            animation: roleta-aparecer 0.25s ease;
        }

        .sinal-overlay[hidden] { display: none; }

        .sinal-dialogo {
            width: min(94vw, 720px);
            max-height: 94vh;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px 18px 18px;
            color: #d6ffe9;
            font-family: monospace;
            text-align: center;
            background: #0b1d1b;
            border: 3px solid #3fe0a0;
            box-shadow: 0 0 22px rgba(63, 224, 160, 0.35), 6px 6px 0 #000;
        }

        .sinal-topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            letter-spacing: 2px;
            color: #7be8bd;
        }

        .sinal-chiado { animation: sinal-piscar 1.1s steps(2, end) infinite; color: #ffe27a; }

        @keyframes sinal-piscar { 50% { opacity: 0.25; } }

        .sinal-dialogo h2 { margin: 0; font-size: 22px; letter-spacing: 3px; text-transform: uppercase; }
        .sinal-texto { margin: 0; min-height: 2.6em; font-size: 13px; line-height: 1.5; color: #bfeede; }
        .sinal-erro { margin: 0; min-height: 1.3em; font-size: 13px; color: #ff9a9a; }

        .sinal-busca {
            width: 100%;
            padding: 9px 10px;
            color: #d6ffe9;
            font: inherit;
            background: #06100f;
            border: 2px solid #3fe0a0;
            outline: none;
        }

        .sinal-lista {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
            gap: 8px;
            max-height: 44vh;
            padding: 4px;
            overflow-y: auto;
        }

        .sinal-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 4px;
            color: #d6ffe9;
            font: inherit;
            font-size: 12px;
            background: #0f2a26;
            border: 2px solid #2b6f5c;
            cursor: pointer;
        }

        .sinal-card img { width: 56px; height: 56px; object-fit: contain; image-rendering: pixelated; }
        .sinal-card small { color: #7fb8a5; font-size: 10px; }
        .sinal-card:hover, .sinal-card:focus-visible { border-color: #3fe0a0; background: #14362f; outline: none; }
        .sinal-card.escolhido { border-color: #ffe27a; background: #3a3414; box-shadow: 0 0 0 2px #ffe27a; }

        .sinal-botoes { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }

        .sinal-botao {
            padding: 10px 18px;
            color: #d6ffe9;
            font: inherit;
            font-size: 13px;
            letter-spacing: 1px;
            background: transparent;
            border: 2px solid #3fe0a0;
            cursor: pointer;
        }

        .sinal-botao:hover:not(:disabled) { background: #3fe0a0; color: #06100f; }
        .sinal-botao:disabled { opacity: 0.35; cursor: not-allowed; }
        .sinal-botao.discreto { border-color: #4c6b64; color: #9fc4b8; }

        .sinal-resultado { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 10px 0; }
        .sinal-resultado img { width: 120px; height: 120px; object-fit: contain; image-rendering: pixelated; }

        .sinal-resultado[hidden], .sinal-lista[hidden], .sinal-busca[hidden], .sinal-botao[hidden] { display: none; }

        @media (prefers-reduced-motion: reduce) {
            .sinal-overlay { animation: none; }
            .sinal-chiado { animation: none; }
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

        /* Centro Pokémon — painel com máquina de cura em estilo pixel-art. */
        .cura-overlay {
            position: fixed;
            inset: 0;
            z-index: 2500;
            display: grid;
            place-items: center;
            padding: 18px;
            overflow-y: auto;
            background:
                radial-gradient(ellipse at 50% 36%, rgba(48, 157, 181, .22), transparent 55%),
                rgba(5, 13, 29, .90);
            backdrop-filter: blur(9px);
        }
        .cura-overlay[hidden] { display: none; }
        .cura-dialogo {
            width: min(95vw, 540px);
            padding: 22px 24px;
            background: #f4f8f9;
            border: 4px solid #233d56;
            box-shadow: 8px 8px 0 #071522;
            text-align: center;
            color: #193650;
        }
        .cura-dialogo h2 { margin: 4px 0 8px; }
        .cura-dialogo p { margin: 8px 0 15px; line-height: 1.5; }
        .cura-fechar {
            padding: 11px 22px;
            font: bold 15px monospace;
            color: #12304a;
            background: #b9f6d8;
            border: 3px solid #294e69;
            cursor: pointer;
        }
        .cura-fechar:disabled { opacity: .55; cursor: not-allowed; }

        #cura-overlay .cura-dialogo {
            position: relative;
            width: min(95vw, 570px);
            padding: 0 22px 24px;
            overflow: hidden;
            color: #eaf9ff;
            background:
                linear-gradient(135deg, transparent 0 49%, rgba(255,255,255,.025) 50% 51%, transparent 52%) 0 0 / 27px 27px,
                linear-gradient(180deg, #172c4b 0%, #101f36 100%);
            border: 4px solid #09182a;
            outline: 2px solid #517c97;
            outline-offset: -9px;
            box-shadow: 0 20px 70px rgba(0,0,0,.55), 9px 10px 0 #050e1c;
        }
        .cura-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 0 -22px 20px;
            padding: 10px 20px;
            border-bottom: 3px solid #09182a;
            background: #e6516b;
            box-shadow: inset 0 -4px #a72c4d;
            color: #fff9f2;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 1.4px;
        }
        .cura-topbar .cura-simbolo {
            display: inline-grid;
            place-items: center;
            width: 27px;
            height: 27px;
            background: #fff;
            border: 3px solid #9a2944;
            color: #ce3954;
            font-size: 21px;
            line-height: 1;
            box-shadow: 2px 2px 0 #9a2944;
        }
        .cura-topbar .cura-online {
            color: #d7fff5;
            font-size: 10px;
            letter-spacing: .5px;
            white-space: nowrap;
        }
        .cura-topbar .cura-online::before {
            content: "";
            display: inline-block;
            width: 7px;
            height: 7px;
            margin-right: 6px;
            background: #89ffbf;
            box-shadow: 0 0 8px #7af5ad;
        }
        #cura-overlay .cura-etiqueta {
            margin: 0;
            color: #75dfe5;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 3px;
        }
        #cura-overlay h2 {
            margin: 7px 0 7px;
            color: #fff;
            font-size: clamp(23px, 4vw, 30px);
            letter-spacing: .8px;
            text-shadow: 3px 3px 0 #071323;
        }
        #cura-overlay .cura-intro {
            margin: 0;
            font-size: 12px;
            color: #b9cedd;
        }
        .cura-cenario {
            position: relative;
            margin: 20px auto 12px;
            padding: 6px 12px 15px;
            max-width: 480px;
            background:
                radial-gradient(ellipse at 50% 90%, rgba(77,231,202,.15), transparent 63%),
                linear-gradient(180deg, #132e48, #183b56 67%, #203950 67%);
            border: 3px solid #09182a;
            box-shadow: inset 0 0 0 3px #294860, 0 5px 0 #060e19;
        }
        .cura-cenario::before, .cura-cenario::after {
            content: "✦";
            position: absolute;
            color: #63efd7;
            font-size: 17px;
            opacity: .7;
            text-shadow: 0 0 15px #74ffdf;
        }
        .cura-cenario::before { top: 24%; left: 7%; }
        .cura-cenario::after { right: 7%; bottom: 27%; font-size: 13px; }
        .cura-maquina {
            position: relative;
            z-index: 1;
            width: min(100%, 392px);
            margin: 18px auto 0;
            background: #f4ecdf;
            border: 5px solid #14253e;
            border-radius: 18px 18px 7px 7px;
            box-shadow: inset 5px 0 #fff8ee, inset -7px 0 #bfc7d0, 6px 7px 0 #09172a;
        }
        .cura-coroa {
            position: relative;
            min-height: 56px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin: -5px -5px 0;
            border: 5px solid #14253e;
            border-radius: 17px 17px 5px 5px;
            background: linear-gradient(180deg, #ff8a92 0 12%, #f05870 12% 70%, #bd2d54 70%);
            box-shadow: inset 5px 0 #ffb4b6, inset -6px 0 #a9244d;
        }
        .cura-cruz {
            display: grid;
            place-items: center;
            width: 31px;
            height: 31px;
            border-radius: 5px;
            background: #fff9f2;
            border: 3px solid #ad304e;
            color: #ea4c6b;
            font-size: 26px;
            line-height: 1;
            font-weight: 900;
        }
        .cura-marca {
            color: #fff2ed;
            font-size: 11px;
            letter-spacing: 2px;
            font-weight: bold;
            text-shadow: 2px 2px #9a2548;
        }
        .cura-monitor {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 8px;
            margin: 12px 19px 8px;
            padding: 7px 9px;
            min-height: 35px;
            background: #102943;
            border: 3px solid #576d86;
            box-shadow: inset 0 0 12px #031327, 2px 2px 0 #a7b3c4;
        }
        .cura-monitor-texto {
            min-width: 0;
            color: #74ffe2;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
        }
        .cura-visores { display: flex; gap: 5px; justify-content: flex-end; }
        .cura-led {
            width: 10px;
            height: 10px;
            border: 2px solid #36576f;
            background: #406179;
        }
        .cura-led.aceso {
            background: #9effdc;
            box-shadow: 0 0 8px 2px #59e4d0;
        }
        .cura-bandeja {
            min-height: 94px;
            margin: 12px 19px;
            padding: 16px 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: clamp(5px, 2vw, 11px);
            background:
                repeating-linear-gradient(90deg, transparent 0 26px, rgba(141,205,223,.045) 27px 29px),
                linear-gradient(#102c44, #1b4561);
            border: 4px solid #758ca1;
            border-radius: 7px;
            box-shadow: inset 0 6px 10px #071a30, 0 3px #c0c9d2;
        }
        .cura-bola {
            position: relative;
            display: block;
            flex: 0 0 auto;
            width: clamp(36px, 8.2vw, 53px);
            height: clamp(36px, 8.2vw, 53px);
            border: 4px solid #132338;
            border-radius: 50%;
            background: linear-gradient(#fa4b64 0 44%, #162439 45% 55%, #f8faf6 56% 100%);
            box-shadow: inset -4px -5px #0002, inset 3px 2px #fff7, 0 4px 0 #071727;
        }
        .cura-bola::before {
            content: "";
            position: absolute;
            left: 50%;
            top: 50%;
            width: 26%;
            aspect-ratio: 1;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            border: 3px solid #132338;
            background: #fbffff;
            box-shadow: 0 0 0 2px #d2e2e5;
        }
        .cura-bola::after {
            content: "✦";
            position: absolute;
            top: -13px;
            right: -9px;
            color: #9fffe3;
            font-size: 17px;
            opacity: 0;
            text-shadow: 0 0 12px #74ffdf;
        }
        .cura-base {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0 19px 14px;
            color: #395675;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 1px;
        }
        .cura-base .cura-detalhes {
            width: 66px;
            height: 10px;
            background: repeating-linear-gradient(90deg, #526d84 0 8px, transparent 8px 13px);
        }
        .cura-informacoes {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 5px 12px;
            color: #abd6e5;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .cura-informacoes strong { color: #9bffe4; }
        .cura-barra {
            margin: 9px 0 12px;
            height: 12px;
            background: #06172b;
            border: 2px solid #42617d;
            overflow: hidden;
        }
        .cura-barra span {
            display: block;
            width: 0;
            height: 100%;
            background: repeating-linear-gradient(90deg, #53d3b5 0 16px, #85f3d7 16px 20px);
            box-shadow: 0 0 15px #84f8d8;
        }
        .cura-overlay.curando .cura-bola {
            animation: cura-rodar 1.05s linear infinite, cura-brilhar 1.05s ease-in-out infinite;
            animation-delay: calc(var(--i) * 110ms);
        }
        .cura-overlay.curando .cura-bola::after {
            animation: cura-faisca .7s ease-in-out infinite alternate;
            animation-delay: calc(var(--i) * 100ms);
        }
        .cura-overlay.curando .cura-barra span { animation: cura-progresso 3.1s ease-in-out forwards; }
        .cura-overlay.curando .cura-led { animation: cura-led-pulso .8s ease-in-out infinite alternate; }
        .cura-overlay.concluido .cura-bola {
            box-shadow: 0 0 16px 5px #79f6d0, inset -4px -5px #0002;
        }
        .cura-overlay.concluido .cura-barra span { width: 100%; }
        .cura-overlay.concluido .cura-monitor-texto { color: #baffdf; }
        #cura-overlay .cura-status {
            min-height: 30px;
            margin: 0 0 17px;
            color: #dbf7f6;
            font-size: 12px;
            font-weight: bold;
        }
        #cura-overlay .cura-fechar {
            width: min(100%, 310px);
            min-height: 45px;
            background: #9af5d7;
            color: #112d3e;
            border: 3px solid #061d2b;
            box-shadow: 4px 4px 0 #060f1e;
            font: 900 12px monospace;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: transform .12s, box-shadow .12s, background .12s;
        }
        #cura-overlay .cura-fechar:hover:not(:disabled) {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0 #060f1e;
            background: #c0ffe8;
        }
        #cura-overlay .cura-fechar:focus-visible {
            outline: 3px solid #fff;
            outline-offset: 5px;
        }
        @keyframes cura-rodar {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes cura-brilhar {
            50% { filter: brightness(1.4); box-shadow: 0 0 17px 6px #79f5d8; }
        }
        @keyframes cura-faisca {
            to { opacity: 1; transform: translate(2px, -8px); }
        }
        @keyframes cura-led-pulso {
            50% { filter: brightness(1.8); box-shadow: 0 0 11px #81ffda; }
        }
        @keyframes cura-progresso {
            0% { width: 0; }
            40% { width: 45%; }
            75% { width: 77%; }
            100% { width: 100%; }
        }
        @media (max-width: 470px) {
            #cura-overlay .cura-dialogo { padding: 0 12px 17px; }
            .cura-topbar { margin: 0 -12px 17px; padding: 9px 12px; }
            .cura-cenario { padding: 4px 6px 12px; }
            .cura-maquina { width: 100%; }
            .cura-monitor, .cura-bandeja, .cura-base { margin-left: 9px; margin-right: 9px; }
            .cura-bola { border-width: 3px; }
            .cura-bola::before { border-width: 2px; }
            .cura-topbar .cura-online { font-size: 8px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .cura-overlay.curando .cura-bola,
            .cura-overlay.curando .cura-bola::after,
            .cura-overlay.curando .cura-led,
            .cura-overlay.curando .cura-barra span { animation: none; }
            .cura-overlay.curando .cura-barra span { width: 100%; }
        }

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

        /* Hover indica que o card pode ser clicado, SEM parecer selecionado.
           O brilho e a cor fortes ficam exclusivos da classe .selecionado. */
        .inicial-card:hover:not(.selecionado),
        .inicial-card:focus-visible:not(.selecionado) {
            transform: translateY(-4px);
            border-color: #6f7a85;
            box-shadow: 0 8px 16px rgba(0, 0, 0, .35);
        }
        .inicial-card:focus-visible {
            outline: 2px solid #fff;
            outline-offset: 4px;
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
        .botao-batalha { display: inline-block; margin-top: 8px; background: #f3d86c; }
        .botao-batalha:hover { background: #ffe58a; }
        .botao-reiniciar:hover { background: #f6d3cb; }
        .botao-reiniciar:disabled { opacity: .5; cursor: wait; }
        /* Icones vetoriais transparentes no mapa. */
        .no-pixel-icon { display:block; width:42px; height:42px; image-rendering:pixelated; filter:drop-shadow(1px 2px 0 #21343e); }
        .no { font-size:33px; }
        .no-chefe { width:60px; height:60px; filter:drop-shadow(1px 2px 0 #21343e); }
        .no-personagem { display:block; width:100%; height:100%; border-radius:50%; object-fit:cover; pointer-events:none; user-select:none; }
        .acessos-laterais .painel-inferior, .acessos-laterais .legenda {
            width:100%; margin:12px 0 0; padding:12px 10px;
            box-shadow:2px 3px #42484b; font-size:11px; line-height:1.65;
            overflow-wrap:anywhere;
        }
        .legenda-grade { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:7px 4px; }
        .legenda-grade span { display:flex; align-items:center; gap:4px; min-width:0; }
        .legenda-grade img { width:22px; height:22px; object-fit:contain; }
        .area-jogo { width:min(calc(100% - 26px),1200px); grid-template-columns:minmax(210px,252px) minmax(0,620px) minmax(200px,258px); gap:14px; }
        .area-jogo .mapa { min-height:570px; height:min(82vh,850px); }
        .indice-area { margin-top:8px; }
        h1 { margin:9px 8px 13px; }
        @media (max-width:900px) {
            .area-jogo { grid-template-columns:minmax(0,1fr); width:min(calc(100% - 22px),620px); }
            .area-jogo .mapa { min-height:560px; height:min(118vw,850px); }
            .acessos-laterais { display:block; }
            .acessos-laterais .painel-inferior, .acessos-laterais .legenda { grid-column:auto; }
        }
    </style>
    <link rel="stylesheet" href="assets/peakui.css">
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

<?php if ($mapaPronto): ?>
    <p class="indice-area">
        ÁREA <?= str_pad((string) (int) $dados['area']['ordem'], 2, '0', STR_PAD_LEFT) ?>
        • DESAFIO FINAL: <?= $escapar($dados['area']['chefe_nome']) ?>
    </p>
<?php endif; ?>

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
                    $icone = $iconeNoHtml($no['tipo_no'], (string) $no['codigo']);
                    if ($atual && $srcPersonagem !== '') {
                        $icone = '<img class="no-personagem" src="' . $escapar($srcPersonagem) . '" alt="" draggable="false">';
                    }
                    // Pontos no topo do mapa mostram a dica para baixo (para cima nao ha espaco).
                    $classeDica = $y <= 16 ? ' dica-baixo' : '';
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
                        <span class="dica<?= $classeDica ?>"><?= $escapar($tituloNo) ?><?= $descricaoNo !== '' ? ' — ' . $escapar($descricaoNo) : '' ?></span>
                    </button>
                <?php else: ?>
                    <div
                        class="no <?= $atual ? 'atual' : 'bloqueado' ?>"
                        style="left: <?= $x ?>%; top: <?= $y ?>%;"
                        aria-label="<?= $escapar($tituloNo) ?>"
                    >
                        <?= $icone ?>
                        <span class="dica<?= $classeDica ?>"><?= $escapar($tituloNo) ?><?= $atual ? ' — Ponto atual' : '' ?></span>
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

            <a class="acesso-placeholder" href="loja.php" aria-label="Abrir loja e comprar itens">
                <span class="acesso-icone" aria-hidden="true">🛒</span>
                <span>LOJA</span>
            </a>

            <a class="acesso-placeholder" href="historico.php" aria-label="Abrir histórico de batalhas e eventos">
                <span class="acesso-icone" aria-hidden="true">📜</span>
                <span>HISTÓRICO</span>
            </a>
            <p class="acesso-legenda">Seu progresso e os registros da jornada.</p>
            <?php if ($mapaPronto): ?>
            <section class="painel-inferior">
                <?php if (($tipoNoAtual === 'BATALHA' || $tipoNoAtual === 'CHEFE') && !$dados['batalha_vencida']): ?>
                    <strong>Você chegou a um ponto de batalha!</strong>
                    <p>O caminho seguinte será liberado depois da vitória.</p>
                    <a class="botao-menu botao-batalha" href="batalha.php">⚔ Ir para a batalha</a>
                <?php elseif (($tipoNoAtual === 'BATALHA' || $tipoNoAtual === 'CHEFE') && $dados['batalha_vencida']): ?>
                    <strong>Batalha vencida!</strong>
                    <p>Agora você pode escolher um dos próximos caminhos.</p>
                <?php elseif ($dados['progresso']['estado'] === 'CONCLUIDA'): ?>
                    <strong>Área concluída!</strong>
                    <p>Você chegou ao final deste mapa.</p>
                <?php else: ?>
                    <strong>Ponto atual:</strong> <?= $escapar($dados['progresso']['no_atual_titulo'] ?? 'Inicio') ?>
                    <p>Clique em um dos pontos destacados para escolher seu próximo caminho.</p>
                <?php endif; ?>
            </section>
            <section class="legenda" aria-label="Legenda do mapa">
                <strong>LEGENDA</strong>
                <div class="legenda-grade">
                    <span>🌿 Mato</span><span>🥋 Treinador</span>
                    <span><img src="images/mapa/captura.svg" alt=""> Captura</span><span><img src="images/mapa/item.svg" alt=""> Item</span>
                    <span>🛒 Loja</span><span>🏥 Centro</span>
                    <span><img src="images/mapa/evento.svg" alt=""> Evento</span><span><img src="images/mapa/hiago.png" alt=""> Chefe</span>
                </div>
            </section>
            <?php endif; ?>
        </aside>
        </div>
    </main>

<?php if ($mostrarInicial): ?>
    <div class="inicial-overlay" id="inicial-overlay" role="dialog" aria-modal="true" aria-labelledby="inicial-titulo">
        <div class="inicial-modal">

            <h2 id="inicial-titulo" tabindex="-1">Escolha seu inicial</h2>
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
    <p class="roleta-dica-pular" aria-live="polite">Clique em qualquer lugar para pular a animação</p>

    <div class="roleta-palco">
        <div class="roleta-seta" aria-hidden="true"></div>
        <svg class="roleta-roda" id="roleta-roda" viewBox="0 0 400 400" aria-hidden="true"></svg>
    </div>
</div>

<div class="sinal-overlay" id="sinal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="sinal-titulo">
    <div class="sinal-dialogo">
        <div class="sinal-topo">
            <span>📡 PEAKMON • RECEPTOR</span>
            <span class="sinal-chiado" id="sinal-chiado">SINAL INSTÁVEL</span>
        </div>
        <h2 id="sinal-titulo">Sinal estranho</h2>
        <p class="sinal-texto" id="sinal-texto">Uma transmissão misteriosa oferece uma troca. Escolha qual dos seus Pokémon será trocado.</p>

        <input class="sinal-busca" id="sinal-busca" type="search" placeholder="Procurar Pokémon..." autocomplete="off" hidden>
        <div class="sinal-lista" id="sinal-lista"></div>
        <div class="sinal-resultado" id="sinal-resultado" hidden></div>
        <p class="sinal-erro" id="sinal-erro" role="alert"></p>

        <div class="sinal-botoes">
            <button type="button" class="sinal-botao discreto" id="sinal-voltar" hidden>Voltar</button>
            <button type="button" class="sinal-botao" id="sinal-confirmar" hidden disabled>Trocar</button>
            <button type="button" class="sinal-botao discreto" id="sinal-ignorar">Ignorar o sinal</button>
        </div>
    </div>
</div>

<div class="cura-overlay" id="cura-overlay" hidden role="dialog" aria-modal="true"
     aria-labelledby="cura-titulo" aria-describedby="cura-status">
    <div class="cura-dialogo">
        <div class="cura-topbar">
            <span class="cura-simbolo" aria-hidden="true">+</span>
            <span>PEAKMON • HEALTH SYSTEM</span>
            <span class="cura-online">ONLINE</span>
        </div>
        <p class="cura-etiqueta">ESTAÇÃO DE RECUPERAÇÃO</p>
        <h2 id="cura-titulo">Centro Pokémon</h2>
        <p class="cura-intro">Sua equipe está em boas mãos. Preparando a máquina...</p>
        <div class="cura-cenario">
            <div class="cura-maquina" aria-hidden="true">
                <div class="cura-coroa">
                    <span class="cura-cruz">+</span>
                    <span class="cura-marca">HEALING UNIT / 01</span>
                </div>
                <div class="cura-monitor">
                    <span class="cura-monitor-texto" id="cura-monitor-texto">RECUPERAÇÃO EM ESPERA</span>
                    <div class="cura-visores">
                        <span class="cura-led"></span><span class="cura-led"></span><span class="cura-led"></span>
                    </div>
                </div>
                <div class="cura-bandeja" id="cura-bandeja"></div>
                <div class="cura-base">
                    <span>POKÉBALL SCANNER</span>
                    <span class="cura-detalhes"></span>
                </div>
            </div>
        </div>
        <div class="cura-informacoes">
            <span>EQUIPE ATIVA: <strong id="cura-contador">0 POKÉBOLAS</strong></span>
            <span id="cura-etapa">PREPARANDO</span>
        </div>
        <div class="cura-barra" aria-hidden="true"><span></span></div>
        <p class="cura-status" id="cura-status" aria-live="polite">Preparando a recuperação...</p>
        <button class="cura-fechar" type="button" id="cura-fechar" disabled>Continuar jornada</button>
    </div>
</div>
<div class="cura-overlay" id="item-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="item-titulo">
    <div class="cura-dialogo">
        <h2 id="item-titulo">🎁 Item escondido!</h2>
        <p id="item-mensagem" aria-live="polite"></p>
        <button class="cura-fechar" type="button" id="item-fechar">Guardar na Mochila</button>
    </div>
</div>

<script src="assets/peakui.js"></script>
<script>
    async function animarCentroPokemon(quantidade) {
        const overlay = document.getElementById('cura-overlay');
        const bandeja = document.getElementById('cura-bandeja');
        const status = document.getElementById('cura-status');
        const botao = document.getElementById('cura-fechar');
        const etapa = document.getElementById('cura-etapa');
        const monitor = document.getElementById('cura-monitor-texto');
        const total = Math.min(5, Math.max(0, Number.parseInt(quantidade, 10) || 0));

        // Cria apenas as Pokebolas dos Pokemon presentes no time ativo.
        // Nenhuma Pokebola ficticia para posicoes vazias ou Pokemon na reserva.
        bandeja.replaceChildren();
        for (let i = 0; i < total; i++) {
            const bola = document.createElement('span');
            bola.className = 'cura-bola';
            bola.style.setProperty('--i', i);
            bandeja.appendChild(bola);
        }
        document.getElementById('cura-contador').textContent =
            total + (total === 1 ? ' POKÉBOLA' : ' POKÉBOLAS');

        overlay.classList.remove('curando', 'concluido');
        overlay.hidden = false;
        botao.disabled = true;
        etapa.textContent = total ? 'RECUPERANDO' : 'SEM EQUIPE';
        monitor.textContent = total ? 'RESTAURANDO HP...' : 'SEM POKÉMON';
        overlay.querySelectorAll('.cura-led').forEach((el) => {
            el.classList.toggle('aceso', total > 0);
        });
        status.textContent = total
            ? 'Recuperando vida e removendo condições negativas...'
            : 'Seu time está vazio. Nenhum Pokémon foi recuperado.';

        if (total > 0) overlay.classList.add('curando');
        await new Promise((resolver) => setTimeout(
            resolver, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 450 : 3200
        ));

        overlay.classList.remove('curando');
        if (total > 0) overlay.classList.add('concluido');
        etapa.textContent = total ? 'CONCLUÍDO' : 'SEM ALTERAÇÕES';
        monitor.textContent = total ? 'EQUIPE RECUPERADA!' : 'AGUARDANDO EQUIPE';
        status.textContent = total === 0
            ? 'Volte com Pokémon em seu time para utilizar a máquina.'
            : `Tudo pronto! ${total} ${total === 1 ? 'Pokémon recuperado' : 'Pokémon recuperados'}. ✨`;
        botao.disabled = false;
        botao.focus();
        await new Promise((resolver) => botao.addEventListener('click', resolver, { once: true }));
    }

    async function mostrarItemEncontrado(item) {
        const overlay = document.getElementById('item-overlay');
        document.getElementById('item-mensagem').textContent = '+' + item.quantidade + ' ' + item.nome + ' foi guardado na sua Mochila!';
        overlay.hidden = false;
        const botao = document.getElementById('item-fechar');
        botao.focus();
        await new Promise((resolver) => botao.addEventListener('click', resolver, { once: true }));
    }

    // Pokemon da roleta de captura (o sorteio e feito no servidor; aqui so se desenha).
    const ROLETA = <?= json_encode($roleta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Itens da roleta do "Item escondido" e emoji de cada tipo de item.
    const ROLETA_ITENS = <?= json_encode($roletaItens, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ICONES_ITEM = {CURA_HP: '🧪', CURA_TOTAL: '🧪', CURA_STATUS: '💠', REVIVER: '✨', PEDRA_EVOLUCAO: '🔮'};

    // true quando a pagina abriu num ponto "Sinal estranho" que ainda nao foi resolvido.
    const SINAL_PENDENTE = <?= $sinalPendente ? 'true' : 'false' ?>;

    // ===== Roleta de captura =====
    const Roleta = (function () {
        const overlay = document.getElementById('roleta-overlay');
        const roda = document.getElementById('roleta-roda');
        const sub = document.getElementById('roleta-sub');
        const resultado = document.getElementById('roleta-resultado');
        const tituloRoleta = document.getElementById('roleta-titulo');
        const dicaPular = overlay.querySelector('.roleta-dica-pular');
        // A mesma roda serve para Pokemon (captura) e para itens (item escondido).
        let lista = ROLETA;
        let total = lista.length;
        let fatia = total > 0 ? 360 / total : 360;
        // Pular durante o giro preserva o resultado sorteado pelo servidor.
        let puloSolicitado = false;
        let liberarGiro = null;
        let liberarResultado = null;
        let fase = 'fechada'; // aguardando, girando, resultado

        const esperar = (ms) => new Promise((resolver) => setTimeout(resolver, ms));

        // Ponto na roda: o angulo conta em graus, no sentido horario, a partir do topo.
        function ponto(raio, graus) {
            const rad = graus * Math.PI / 180;
            return [200 + raio * Math.sin(rad), 200 - raio * Math.cos(rad)];
        }

        function montar(novaLista) {
            lista = novaLista;
            total = lista.length;
            fatia = total > 0 ? 360 / total : 360;
            roda.replaceChildren();
            if (total === 0) return;

            const NS = 'http://www.w3.org/2000/svg';
            const criar = (nome, atributos) => {
                const elemento = document.createElementNS(NS, nome);
                Object.keys(atributos).forEach((chave) => elemento.setAttribute(chave, atributos[chave]));
                return elemento;
            };

            lista.forEach((pokemon, i) => {
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

                if (pokemon.imagem) {
                    const imagem = criar('image', {
                        href: pokemon.imagem,
                        x: 200 - 20,
                        y: 200 - 124 - 20,
                        width: 40,
                        height: 40
                    });
                    grupo.appendChild(imagem);
                } else {
                    // Item: sem foto, entra um emoji do tipo do item.
                    const icone = criar('text', {
                        x: 200,
                        y: 200 - 116,
                        'text-anchor': 'middle',
                        'font-size': 32
                    });
                    icone.textContent = ICONES_ITEM[pokemon.tipo] || '🎁';
                    grupo.appendChild(icone);
                }

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

        function abrir(novaLista, textoTitulo) {
            montar(novaLista || ROLETA);
            tituloRoleta.textContent = textoTitulo || 'Pokébola!';

            roda.style.transition = 'none';
            roda.style.transform = 'rotate(0deg)';
            roda.getBoundingClientRect();

            resultado.classList.remove('visivel');
            resultado.replaceChildren();
            sub.textContent = 'Sorteando...';
            fase = 'aguardando';
            puloSolicitado = false;
            liberarGiro = null;
            liberarResultado = null;
            dicaPular.textContent = 'Clique em qualquer lugar para pular a animação';

            overlay.hidden = false;
            document.body.classList.add('roleta-aberta');
        }

        function fechar() {
            fase = 'fechada';
            overlay.hidden = true;
            document.body.classList.remove('roleta-aberta');
        }

        // O servidor ja escolheu o premio: a roleta nunca sorteia no navegador.
        async function girarAte(premio, info) {
            let indice = lista.findIndex((entrada) => Number(entrada.id) === Number(premio.id));
            if (indice < 0) {
                indice = lista.findIndex((entrada) => entrada.nome === premio.nome);
            }
            if (indice < 0) {
                fechar();
                window.location.reload();
                return;
            }

            const reduzir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const duracao = reduzir ? 600 : 5200;
            const voltas = reduzir ? 1 : 6;
            const meio = indice * fatia + fatia / 2;
            const folga = (Math.random() - 0.5) * fatia * 0.7;
            const alvo = voltas * 360 - meio + folga;

            fase = 'girando';
            if (!puloSolicitado) {
                sub.textContent = 'Girando a roleta...';
                roda.style.transition = 'transform ' + duracao + 'ms cubic-bezier(0.12, 0.62, 0.08, 1)';
                roda.getBoundingClientRect();
                roda.style.transform = 'rotate(' + alvo + 'deg)';
                await new Promise((resolver) => {
                    let terminou = false;
                    const concluir = () => {
                        if (terminou) return;
                        terminou = true;
                        clearTimeout(timer);
                        roda.removeEventListener('transitionend', concluir);
                        liberarGiro = null;
                        resolver();
                    };
                    const timer = setTimeout(concluir, duracao + 650);
                    roda.addEventListener('transitionend', concluir);
                    liberarGiro = concluir;
                    if (puloSolicitado) concluir();
                });
            }

            // Fixar a roda exatamente sobre o premio antes de revelar a imagem.
            roda.style.transition = 'none';
            roda.style.transform = 'rotate(' + alvo + 'deg)';
            sub.textContent = '';
            mostrarResultado(info);
            fase = 'resultado';
            dicaPular.textContent = 'Clique em qualquer lugar para continuar';
            await new Promise((resolver) => {
                let terminou = false;
                const concluir = () => {
                    if (terminou) return;
                    terminou = true;
                    clearTimeout(timer);
                    liberarResultado = null;
                    resolver();
                };
                const timer = setTimeout(concluir, puloSolicitado ? 1000 : 4300);
                liberarResultado = concluir;
            });
            fechar();
            window.location.reload();
        }

        // info = { imagem | icone, alt, titulo, detalhe }
        function mostrarResultado(info) {
            resultado.textContent = '';

            let marca;
            if (info.imagem) {
                marca = document.createElement('img');
                marca.src = info.imagem;
                marca.alt = info.alt || '';
            } else {
                marca = document.createElement('span');
                marca.className = 'roleta-emoji';
                marca.textContent = info.icone || '🎁';
            }

            const texto = document.createElement('div');
            const titulo = document.createElement('strong');
            titulo.textContent = info.titulo;
            const detalhe = document.createElement('span');
            detalhe.textContent = info.detalhe;

            texto.appendChild(titulo);
            texto.appendChild(detalhe);
            resultado.appendChild(marca);
            resultado.appendChild(texto);
            resultado.classList.add('visivel');
        }

        // Mesma experiencia da evolucao: um clique em qualquer parte da janela
        // acelera a animacao, sem decidir o premio (ele vem do servidor).
        overlay.addEventListener('click', () => {
            if (fase === 'fechada') return;
            if (fase === 'resultado') {
                if (liberarResultado) liberarResultado();
                return;
            }
            if (fase === 'aguardando' || fase === 'girando') {
                puloSolicitado = true;
                dicaPular.textContent = 'Revelando prêmio...';
                if (liberarGiro) liberarGiro();
            }
        });

        return { abrir, fechar, girarAte };
    })();

    // ===== Evento "Sinal estranho": trocar um Pokemon por outro =====
    const Sinal = (function () {
        const overlay = document.getElementById('sinal-overlay');
        const chiado = document.getElementById('sinal-chiado');
        const texto = document.getElementById('sinal-texto');
        const busca = document.getElementById('sinal-busca');
        const listaEl = document.getElementById('sinal-lista');
        const resultadoEl = document.getElementById('sinal-resultado');
        const erroEl = document.getElementById('sinal-erro');
        const botaoVoltar = document.getElementById('sinal-voltar');
        const botaoConfirmar = document.getElementById('sinal-confirmar');
        const botaoIgnorar = document.getElementById('sinal-ignorar');

        let dados = null;          // { meus, especies } vindos do servidor
        let meuEscolhido = null;   // Pokemon do jogador que sera trocado
        let novoEscolhido = null;  // especie que ele vai receber

        async function pedir(acao, extra) {
            const corpo = new URLSearchParams();
            corpo.set('acao', acao);
            Object.keys(extra || {}).forEach((chave) => corpo.set(chave, extra[chave]));

            const resposta = await fetch('mapa.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: corpo.toString()
            });
            const json = await resposta.json();

            if (!resposta.ok || !json.sucesso) {
                throw new Error(json.mensagem || 'Nao foi possivel concluir o sinal.');
            }
            return json;
        }

        function criarCard(pokemon, detalhe, aoClicar) {
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'sinal-card';

            const foto = document.createElement('img');
            foto.src = pokemon.imagem;
            foto.alt = '';
            foto.onerror = () => { foto.style.visibility = 'hidden'; };

            const nome = document.createElement('span');
            nome.textContent = pokemon.nome;

            botao.appendChild(foto);
            botao.appendChild(nome);

            if (detalhe) {
                const pequeno = document.createElement('small');
                pequeno.textContent = detalhe;
                botao.appendChild(pequeno);
            }

            botao.addEventListener('click', () => aoClicar(botao));
            return botao;
        }

        // Passo 1: escolher qual Pokemon seu sera trocado.
        function mostrarMeus() {
            meuEscolhido = null;
            novoEscolhido = null;
            texto.textContent = 'Uma transmissão misteriosa oferece uma troca. Escolha qual dos seus Pokémon será trocado.';
            busca.hidden = true;
            busca.value = '';
            botaoVoltar.hidden = true;
            botaoConfirmar.hidden = true;
            listaEl.hidden = false;
            resultadoEl.hidden = true;
            listaEl.textContent = '';

            if (dados.meus.length === 0) {
                texto.textContent = 'Você não tem nenhum Pokémon que possa participar da troca.';
                return;
            }

            dados.meus.forEach((pokemon) => {
                const lugar = pokemon.posicao ? 'Time ' + pokemon.posicao : 'Reserva';
                listaEl.appendChild(criarCard(pokemon, lugar, () => {
                    meuEscolhido = pokemon;
                    mostrarNovos();
                }));
            });
        }

        // Passo 2: escolher a especie que ele vai receber.
        function desenharNovos() {
            const termo = busca.value.trim().toLowerCase();
            listaEl.textContent = '';

            dados.especies
                .filter((especie) => especie.nome !== meuEscolhido.nome)
                .filter((especie) => termo === '' || especie.nome.toLowerCase().includes(termo))
                .forEach((especie) => {
                    const card = criarCard(especie, especie.tipo, (elemento) => {
                        novoEscolhido = especie;
                        listaEl.querySelectorAll('.sinal-card').forEach((outro) => outro.classList.remove('escolhido'));
                        elemento.classList.add('escolhido');
                        texto.textContent = meuEscolhido.nome + ' será trocado por ' + especie.nome + '. Confirmar?';
                        botaoConfirmar.disabled = false;
                    });

                    if (novoEscolhido && novoEscolhido.id === especie.id) card.classList.add('escolhido');
                    listaEl.appendChild(card);
                });
        }

        function mostrarNovos() {
            novoEscolhido = null;
            texto.textContent = 'Trocar ' + meuEscolhido.nome + ' por qual Pokémon? (Rayquaza, Deoxys e Arnaldo não entram na troca.)';
            busca.hidden = false;
            busca.value = '';
            botaoVoltar.hidden = false;
            botaoConfirmar.hidden = false;
            botaoConfirmar.disabled = true;
            desenharNovos();
        }

        function mostrarConclusao(r) {
            listaEl.hidden = true;
            busca.hidden = true;
            botaoVoltar.hidden = true;
            botaoConfirmar.hidden = true;
            botaoIgnorar.hidden = true;
            chiado.textContent = 'TROCA CONCLUÍDA';
            texto.textContent = '';

            resultadoEl.textContent = '';
            const foto = document.createElement('img');
            foto.src = r.imagem;
            foto.alt = r.novo;
            const titulo = document.createElement('strong');
            titulo.textContent = r.antigo + ' virou ' + r.novo + '!';
            const detalhe = document.createElement('span');
            detalhe.textContent = r.destino === 'time'
                ? r.novo + ' ocupou a posição ' + r.posicao + ' do seu time.'
                : r.novo + ' ficou na reserva (Arquivo Bigolin, na Mochila).';

            resultadoEl.appendChild(foto);
            resultadoEl.appendChild(titulo);
            resultadoEl.appendChild(detalhe);
            resultadoEl.hidden = false;

            // Clicar acelera; senao o mapa recarrega sozinho.
            const recarregar = () => window.location.reload();
            overlay.addEventListener('click', recarregar, { once: true });
            setTimeout(recarregar, 3500);
        }

        busca.addEventListener('input', desenharNovos);
        botaoVoltar.addEventListener('click', mostrarMeus);

        botaoConfirmar.addEventListener('click', async () => {
            if (!meuEscolhido || !novoEscolhido) return;

            botaoConfirmar.disabled = true;
            botaoIgnorar.disabled = true;
            botaoVoltar.disabled = true;
            erroEl.textContent = '';

            try {
                const r = await pedir('sinal_trocar', {
                    id_capturado: meuEscolhido.id_capturado,
                    id_especie: novoEscolhido.id
                });
                mostrarConclusao(r);
            } catch (erro) {
                erroEl.textContent = erro.message;
                botaoConfirmar.disabled = false;
                botaoIgnorar.disabled = false;
                botaoVoltar.disabled = false;
            }
        });

        botaoIgnorar.addEventListener('click', async () => {
            botaoIgnorar.disabled = true;
            try { await pedir('sinal_ignorar'); } catch (erro) { /* segue mesmo assim */ }
            window.location.reload();
        });

        async function abrir() {
            overlay.hidden = false;
            document.body.classList.add('roleta-aberta'); // trava a rolagem do mapa
            erroEl.textContent = '';
            texto.textContent = 'Captando o sinal...';

            try {
                dados = await pedir('sinal_dados');
                mostrarMeus();
            } catch (erro) {
                erroEl.textContent = erro.message;
            }
        }

        return { abrir };
    })();

    // Pagina recarregada num ponto "Sinal estranho" ainda nao resolvido: reabre a escolha.
    if (SINAL_PENDENTE) Sinal.abrir();

    // Cada clique envia apenas o ID do proximo no. O perfil ativo fica na sessao PHP.
    document.querySelectorAll('[data-id-no-destino]').forEach((botao) => {
        botao.addEventListener('click', async () => {
            if (botao.dataset.tipo === 'BATALHA' || botao.dataset.tipo === 'CHEFE') {
                const certeza = await PeakUI.confirm(
                    botao.dataset.tipo === 'CHEFE'
                        ? 'Você vai enfrentar o chefe desta área. Seu time está preparado?'
                        : 'Um adversário espera por você neste ponto. Deseja iniciar o combate?',
                    {title: botao.dataset.tipo === 'CHEFE' ? '⚔ Desafio do chefe' : '⚔ Batalha',
                     okText: 'Ir para batalha'});
                if (!certeza) return;
            }
            botao.disabled = true;

            // Pokebola e item escondido: a roleta abre em cima do mapa, sem sair da pagina.
            const ehCaptura = botao.dataset.tipo === 'CAPTURA' && ROLETA.length > 0;
            const ehItem = botao.dataset.tipo === 'ITEM' && ROLETA_ITENS.length > 0;
            if (ehCaptura) Roleta.abrir(ROLETA, 'Pokébola!');
            if (ehItem) Roleta.abrir(ROLETA_ITENS, 'Item escondido!');

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
                    const c = resultado.captura;
                    await Roleta.girarAte({ id: c.id_pokemon, nome: c.nome }, {
                        imagem: c.imagem,
                        alt: c.nome,
                        titulo: 'Você capturou ' + c.nome + '!',
                        detalhe: c.destino === 'time'
                            ? 'Ele entrou no seu time (posição ' + c.posicao + ').'
                            : 'Seu time está cheio (5/5): ' + c.nome + ' foi para o PC (Arquivo Bigolin, na Mochila).'
                    });
                    return;
                }
                if (ehItem && resultado.item_encontrado) {
                    const it = resultado.item_encontrado;
                    const refItem = ROLETA_ITENS.find((x) => x.id === it.id) || {};
                    await Roleta.girarAte({ id: it.id, nome: it.nome }, {
                        imagem: refItem.imagem || '',
                        alt: it.nome,
                        icone: ICONES_ITEM[it.tipo] || '🎁',
                        titulo: 'Você achou ' + it.nome + '!',
                        detalhe: '+' + it.quantidade + ' guardado na sua Mochila.'
                    });
                    return;
                }
                if (resultado.evento && resultado.evento.tipo === 'SINAL_ESTRANHO') {
                    await Sinal.abrir();
                    return;
                }
                if (resultado.item_encontrado) {
                    await mostrarItemEncontrado(resultado.item_encontrado);
                    window.location.reload();
                    return;
                }
                if (resultado.centro_pokemon) {
                    await animarCentroPokemon(resultado.centro_pokemon.quantidade);
                    window.location.reload();
                    return;
                }

                // Mato, treinador ou chefe: segue direto para a tela de batalha.
                if (resultado.no && (resultado.no.tipo_no === 'BATALHA' || resultado.no.tipo_no === 'CHEFE')) {
                    window.location.href = 'batalha.php';
                    return;
                }

                window.location.reload();

            } catch (erro) {
                if (ehCaptura || ehItem) Roleta.fechar();
                await PeakUI.alert(erro.message, {title: 'Não foi possível continuar'});
                botao.disabled = false;
            }
        });
    });

    <?php if ($mapaPronto && !$mostrarInicial && in_array($tipoNoAtual, ['BATALHA', 'CHEFE'], true) && !$dados['batalha_vencida']): ?>
    // Recarregar a pagina num ponto de batalha abre a confirmacao uma vez nesta aba.
    window.addEventListener('load', async () => {
        const chave = 'peakmon_batalha_pendente_' + <?= (int) $idPerfil ?> + '_' + <?= (int) $idNoAtual ?>;
        if (sessionStorage.getItem(chave)) return;
        sessionStorage.setItem(chave, '1');
        const entrar = await PeakUI.confirm('Você está em um ponto de combate. Deseja batalhar agora?',
            {title:'⚔ Batalha pendente', okText:'Iniciar batalha'});
        if (entrar) window.location.href = 'batalha.php';
    });
    <?php endif; ?>

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

        // O foco fica no titulo: nenhum dos iniciais comeca selecionado.
        document.getElementById('inicial-titulo').focus();
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
            const certeza = await PeakUI.confirm(
                'Essa ação apagará seus Pokémon, itens, dinheiro e progresso deste perfil. Os outros perfis não serão afetados.',
                {title:'Reiniciar este perfil?', okText:'Sim, reiniciar', danger:true}
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
                await PeakUI.alert(erro.message, {title: 'Não foi possível continuar'});
                botao.disabled = false;
            }
        });
    })();
</script>

</body>
</html>