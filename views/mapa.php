<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


use Peakmon\Models\Inicial;
use Peakmon\Models\Mapa;
use Peakmon\Models\Time;
use Peakmon\Models\Captura;
use Peakmon\Models\Perfil;
use Peakmon\Models\SinalEstranho;

require_once PEAKMON_ROOT . '/app/sessao.php';
require_once PEAKMON_ROOT . '/app/autoload.php';

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

    header('Location: index.php?pagina=perfis');
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
    $pdo = require PEAKMON_ROOT . '/config/database.php';
    $controller = new \Peakmon\Controllers\MapaController($pdo);

    if ($ehPost) {
        header('Content-Type: application/json; charset=utf-8');
        $resultado = $controller->processarAcao($idPerfil, $idUsuario, $_POST);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pagina = $controller->carregarPagina($idPerfil, $idUsuario, PEAKMON_ROOT);
    $mostrarInicial = $pagina['mostrarInicial'];
    $personagemNome = $pagina['personagemNome'];
    $iniciais = $pagina['iniciais'];
    $slotsTime = $pagina['slotsTime'];
    $dados = $pagina['dados'];
    $roleta = $pagina['roleta'];
    $roletaItens = $pagina['roletaItens'];
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
            if (is_file(PEAKMON_ROOT . '/images/mapa/' . $base . '.' . $extensao)) {
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
// Imagem do chefe no mapa: Bigolin (area 2) aparece como o Bigolin do mal (sem o Deoxys); os demais usam o Hiago.
$imagemDoChefe = static function (?string $chefeNome): string {
    return $chefeNome === 'Bigolin'
        ? 'menu%20inicial/images/bigolin.jpg'
        : 'images/mapa/hiago.png';
};
$iconeNoHtml = static function (string $tipo, string $codigo = '', ?string $chefeNome = null) use ($iconePorTipo, $miniIcone, $imagemDoChefe): string {
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
    // Chefe final: imagem do chefe da area (Hiago ou Bigolin do mal), maior que os demais pontos.
    if ($tipo === 'CHEFE') {
        $classeChefe = $chefeNome === 'Bigolin' ? 'no-chefe no-chefe-bigolin' : 'no-chefe';
        return '<img class="no-pixel-icon ' . $classeChefe . '" src="' . $imagemDoChefe($chefeNome) . '" alt="" draggable="false">';
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
            if (is_file(PEAKMON_ROOT . '/images/personagens/' . $base . '.' . $extensao)) {
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
    return match (strtoupper(trim($tipo))) {
        'NORMAL' => '168, 167, 122',
        'FOGO' => '238, 112, 63',
        'AGUA' => '83, 141, 224',
        'ELETRICO' => '240, 197, 50',
        'GRAMA' => '104, 183, 91',
        'GELO' => '105, 199, 207',
        'LUTADOR' => '205, 89, 81',
        'VENENO' => '172, 100, 183',
        'TERRA' => '207, 169, 91',
        'VOADOR' => '152, 159, 222',
        'PSIQUICO' => '232, 99, 152',
        'INSETO' => '163, 179, 65',
        'PEDRA' => '183, 159, 87',
        'FANTASMA' => '117, 96, 168',
        'DRAGAO' => '128, 100, 216',
        'SOMBRIO' => '129, 111, 101',
        'ACO' => '155, 173, 190',
        'FADA' => '229, 145, 194',
        'ALIENX' => '78, 194, 171',
        'TUDO' => '223, 168, 65',
        default => '168, 167, 122'
    };
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peakmon - Mapa</title>

    <link rel="stylesheet" href="assets/css/mapa.css">
    <link rel="stylesheet" href="assets/css/peakui.css">
</head>
<body>

<header class="topo">
    <div class="informacao">
        Perfil: <strong><?= $dados !== null ? $escapar($dados['perfil']['nome_perfil']) : $escapar($_SESSION['perfil_nome'] ?? 'Perfil') ?></strong>
        <span> | Pokédollars: <strong><?= $dados !== null ? (int) $dados['perfil']['dinheiro'] : 0 ?></strong></span>
    </div>
    <div class="topo-acoes">
        <a class="botao-menu" href="index.php?pagina=perfis">⇦ Trocar perfil</a>
        <button type="button" class="botao-menu botao-reiniciar" id="botao-reiniciar">↻ Reiniciar perfil</button>
    </div>
</header>


<main>
    <div class="area-jogo">
        <div class="cabecalho-mapa">
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
                <small>Mapa da jornada • escolha seu próximo caminho</small>
            </h1>
        </div>
        <aside class="time" aria-label="Seu time">
            <h2 class="time-titulo">SEU TIME</h2>

            <ol class="time-lista" id="time-lista">
                <?php foreach ($slotsTime as $posicao => $slot): ?>
                    <li class="time-slot<?= $slot === null ? ' vazio' : '' ?>" data-posicao="<?= (int) $posicao ?>">
                        <span class="time-numero"><?= (int) $posicao ?></span>

                        <div class="time-card" role="button" tabindex="0" aria-label="Posição <?= (int) $posicao ?> do time">
                            <?php if ($slot !== null): ?>
                                <?php
                                    $hpMax = max(1, (int) $slot['hp_max']);
                                    $hpAtual = max(0, (int) $slot['hp_atual']);
                                    $hpPercentual = (int) max(0, min(100, round(($hpAtual / $hpMax) * 100)));
                                    $classeHp = $hpPercentual <= 25 ? ' hp-baixa' : ($hpPercentual <= 55 ? ' hp-media' : '');
                                ?>
                                <div class="time-foto" style="--cor: <?= $corPorTipo((string) $slot['tipo']) ?>; --img: url('<?= $escapar($slot['imagem']) ?>');"></div>
                                <div class="time-dados">
                                    <div class="time-topo">
                                        <strong><?= $escapar($slot['nome']) ?></strong>
                                        <span class="time-tipo" style="--cor: <?= $corPorTipo((string) $slot['tipo']) ?>;"><?= $escapar($slot['tipo']) ?></span>
                                    </div>
                                    <span class="time-hp-text">HP <?= $hpAtual ?>/<?= $hpMax ?></span>
                                    <span class="time-hp-barra"><span class="time-hp-preenchimento<?= $classeHp ?>" style="width: <?= $hpPercentual ?>%;"></span></span>
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
                        <p>O banco ainda nao tem o mapa. Importe o arquivo <strong>database/peakmon_base.sql</strong> no MySQL.</p>
                    </div>
                <?php else: ?>
                    <div class="sem-area">
                        <h2>Esta area ainda nao possui pontos.</h2>
                        <p>Cadastre os nos da area na tabela <strong>area_no</strong>.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <section class="mapa-coluna">
        <section class="mapa" aria-label="Mapa da area">
            <svg class="caminhos" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <?php foreach ($dados['conexoes'] as $conexao): ?>
                    <?php
                        $classeCaminho = '';
                        if ((int) $conexao['id_no_origem'] === $idNoAtual && in_array((int) $conexao['id_no_destino'], $idsProximos, true)) {
                            $classeCaminho = ' caminho-disponivel';
                        } elseif ((int) $conexao['id_no_destino'] === $idNoAtual) {
                            $classeCaminho = ' caminho-atual';
                        }
                    ?>
                    <line
                        class="<?= trim($classeCaminho) ?>"
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
                    $icone = $iconeNoHtml($no['tipo_no'], (string) $no['codigo'], $dados['area']['chefe_nome'] ?? null);
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
                        class="no disponivel"
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
        <?php if ($mapaPronto): ?>
            <div class="mapa-rodape">
                <section class="legenda" aria-label="Legenda do mapa">
                    <strong>LEGENDA</strong>
                    <div class="legenda-grade">
                        <span>🌿 Mato</span><span>🥋 Treinador</span><span><img src="images/mapa/captura.svg" alt=""> Captura</span><span><img src="images/mapa/item.svg" alt=""> Item</span>
                        <span>🛒 Loja</span><span>🏥 Centro</span><span><img src="images/mapa/evento.svg" alt=""> Evento</span><span><img src="<?= $imagemDoChefe($dados['area']['chefe_nome'] ?? null) ?>" alt="" style="border-radius:50%;object-fit:cover"> Chefe</span>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        </section>
        <?php endif; ?>

        <aside class="acessos-laterais" aria-label="Acessos do jogo">
            <h2 class="acessos-titulo">ACESSOS</h2>

            <a class="acesso-placeholder" href="index.php?pagina=mochila" aria-label="Abrir mochila e gerenciar itens e Pokemon">
                <span class="acesso-icone" aria-hidden="true">🎒</span>
                <span>MOCHILA</span>
                <span class="acesso-subtitulo">Equipe, arquivo e itens</span>
            </a>

            <a class="acesso-placeholder" href="index.php?pagina=loja" aria-label="Abrir loja e comprar itens">
                <span class="acesso-icone" aria-hidden="true">🛒</span>
                <span>LOJA</span>
                <span class="acesso-subtitulo">Compre suprimentos da jornada</span>
            </a>

            <a class="acesso-placeholder" href="index.php?pagina=historico" aria-label="Abrir histórico de batalhas e eventos">
                <span class="acesso-icone" aria-hidden="true">📜</span>
                <span>HISTÓRICO</span>
                <span class="acesso-subtitulo">Registros da aventura</span>
            </a>
            <p class="acesso-legenda">Gerencie sua jornada pelos acessos rápidos ao lado.</p>
            <?php if ($mapaPronto): ?>
                <section class="painel-inferior">
                    <?php if (($tipoNoAtual === 'BATALHA' || $tipoNoAtual === 'CHEFE') && !$dados['batalha_vencida']): ?>
                        <strong>Você chegou a um ponto de batalha!</strong>
                        <p>O caminho seguinte será liberado depois da vitória.</p>
                        <a class="botao-menu botao-batalha" href="index.php?pagina=batalha">⚔ Ir para a batalha</a>
                    <?php elseif (($tipoNoAtual === 'BATALHA' || $tipoNoAtual === 'CHEFE') && $dados['batalha_vencida']): ?>
                        <strong>Batalha vencida!</strong>
                        <p>Agora você pode escolher um dos próximos caminhos.</p>
                    <?php elseif ($dados['progresso']['estado'] === 'CONCLUIDA'): ?>
                        <strong>Área concluída!</strong>
                        <p>Você chegou ao final deste mapa.</p>
                    <?php else: ?>
                        <strong>Ponto atual: <?= $escapar($dados['progresso']['no_atual_titulo'] ?? 'Inicio') ?></strong>
                        <p>Clique em um dos pontos destacados para escolher seu próximo caminho.</p>
                    <?php endif; ?>
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
<template id="modelos-mapa">
    <div></div><span></span><strong></strong><small></small><button type="button"></button><img alt="">
</template>
<template id="modelos-roleta-svg">
    <svg xmlns="http://www.w3.org/2000/svg">
        <path></path><g></g><image></image><text></text><circle></circle>
    </svg>
</template>
<script src="assets/js/peakui.js"></script>
<script>
    // Modelos sao declarados no HTML para respeitar a restricao do professor.
    function clonarMapa(tag) {
        const modelo = document.getElementById('modelos-mapa').content.querySelector(tag);
        if (!modelo) throw new Error('Modelo visual não cadastrado: ' + tag);
        return modelo.cloneNode(true);
    }
    function clonarSvg(tag) {
        const modelo = document.getElementById('modelos-roleta-svg').content.querySelector(tag);
        if (!modelo) throw new Error('Modelo SVG não cadastrado: ' + tag);
        return modelo.cloneNode(true);
    }
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
            const bola = clonarMapa('span');
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

            const criar = (nome, atributos) => {
                const elemento = clonarSvg(nome);
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
                marca = clonarMapa('img');
                marca.src = info.imagem;
                marca.alt = info.alt || '';
            } else {
                marca = clonarMapa('span');
                marca.className = 'roleta-emoji';
                marca.textContent = info.icone || '🎁';
            }

            const texto = clonarMapa('div');
            const titulo = clonarMapa('strong');
            titulo.textContent = info.titulo;
            const detalhe = clonarMapa('span');
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

            const resposta = await fetch('index.php?pagina=mapa', {
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
            const botao = clonarMapa('button');
            botao.type = 'button';
            botao.className = 'sinal-card';

            const foto = clonarMapa('img');
            foto.src = pokemon.imagem;
            foto.alt = '';
            foto.onerror = () => { foto.style.visibility = 'hidden'; };

            const nome = clonarMapa('span');
            nome.textContent = pokemon.nome;

            botao.appendChild(foto);
            botao.appendChild(nome);

            if (detalhe) {
                const pequeno = clonarMapa('small');
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
            const foto = clonarMapa('img');
            foto.src = r.imagem;
            foto.alt = r.novo;
            const titulo = clonarMapa('strong');
            titulo.textContent = r.antigo + ' virou ' + r.novo + '!';
            const detalhe = clonarMapa('span');
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
                const resposta = await fetch('index.php?pagina=mapa', {
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
                    window.location.href = 'index.php?pagina=batalha';
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
        if (entrar) window.location.href = 'index.php?pagina=batalha';
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
                const resposta = await fetch('index.php?pagina=mapa', {
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
                const resposta = await fetch('index.php?pagina=mapa', {
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
                const resposta = await fetch('index.php?pagina=mapa', {
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