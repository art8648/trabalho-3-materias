<?php
declare(strict_types=1);

use Peakmon\Models\Mapa;

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

try {
    $pdo = require __DIR__ . '/config/database.php';
    $mapa = new Mapa($pdo);

    // O mesmo arquivo recebe o clique do mapa e salva o novo ponto.
    if ($ehPost) {
        header('Content-Type: application/json; charset=utf-8');

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

        $resultado = $mapa->avancar(
            $idPerfil,
            $idUsuario,
            (int) $idNoDestino
        );

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dados = $mapa->carregarMapa($idPerfil, $idUsuario);

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

$iconePorTipo = static function (string $tipo): string {
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
            width: min(100% - 28px, 900px);
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
    </style>
</head>
<body>

<header class="topo">
    <div class="informacao">
        Perfil: <strong><?= $dados !== null ? $escapar($dados['perfil']['nome_perfil']) : $escapar($_SESSION['perfil_nome'] ?? 'Perfil') ?></strong>
        <span> | Pokédollars: <strong><?= $dados !== null ? (int) $dados['perfil']['dinheiro'] : 0 ?></strong></span>
    </div>
    <a class="botao-menu" href="perfis.php">⇦ Trocar perfil</a>
</header>

<h1>
    <?= $dados !== null && $dados['area'] !== null
        ? $escapar($dados['area']['nome'])
        : 'PEAKMON: THE BIGOLIN CRISIS' ?>
</h1>

<?php if (isset($mensagemErro)): ?>
    <div class="erro"><?= $escapar($mensagemErro) ?></div>
<?php elseif ($dados === null): ?>
    <div class="erro">Nao foi possivel carregar os dados do mapa.</div>
<?php elseif ($dados['area'] === null): ?>
    <div class="sem-area">
        <h2>O mapa ainda nao foi cadastrado!</h2>
        <p>O perfil e o save estao funcionando, mas ainda precisamos cadastrar a area, os pontos e os caminhos no banco de dados.</p>
    </div>
<?php elseif (count($dados['nos']) === 0): ?>
    <div class="sem-area">
        <h2>Esta area ainda nao possui pontos.</h2>
        <p>Cadastre os nos da area na tabela <strong>area_no</strong>.</p>
    </div>
<?php else: ?>

    <main>
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
                    $icone = $iconePorTipo($no['tipo_no']);
                    $tituloNo = $no['titulo'];
                    $descricaoNo = $no['descricao'] ?? '';
                ?>

                <?php if ($proximo): ?>
                    <button
                        type="button"
                        class="no"
                        style="left: <?= $x ?>%; top: <?= $y ?>%;"
                        data-id-no-destino="<?= $idNo ?>"
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
            🌿 Batalha &nbsp; 🔴 Captura &nbsp; 🎁 Item &nbsp; 🛒 Loja<br>
            🏥 Cura &nbsp; 📡 Evento &nbsp; ⭐ Chefe &nbsp; 🏁 Final
        </section>
    </main>

<?php endif; ?>

<script>
    // Cada clique envia apenas o ID do proximo no. O perfil ativo fica na sessao PHP.
    document.querySelectorAll('[data-id-no-destino]').forEach((botao) => {
        botao.addEventListener('click', async () => {
            botao.disabled = true;

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

                window.location.reload();

            } catch (erro) {
                alert(erro.message);
                botao.disabled = false;
            }
        });
    });
</script>

</body>
</html>
