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
<style>
:root{--ink:#24332c;--deep:#203d31;--green:#477b55;--mint:#dcebd2;--paper:#f5edda;--paper2:#e8ddc3;--gold:#d8b96f;--line:#35483c;--muted:#748174;--red:#a94e43;--shadow:#1c2b24}
*{box-sizing:border-box}body{margin:0;min-height:100vh;color:var(--ink);font-family:ui-monospace,Consolas,monospace;background:radial-gradient(ellipse at 50% 0,#a9d6a0 0,#6d9c70 48%,#365b48 100%);padding:24px 16px 48px}
button,select,input{font:inherit}button{cursor:pointer}.shell{width:min(1180px,100%);margin:auto}.topbar{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:12px;color:#f8f2df;text-shadow:2px 2px #294433}.brand-mark{width:48px;height:48px;display:grid;place-items:center;background:#f4e9ca;border:3px solid var(--line);box-shadow:4px 4px var(--shadow);font-size:25px}.brand small{display:block;font-size:10px;letter-spacing:2px;color:#e4edcf}.brand h1{font-size:clamp(18px,3vw,27px);margin:2px 0}.back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;background:var(--paper);border:3px solid var(--line);box-shadow:3px 3px var(--shadow);color:var(--ink);text-decoration:none;font-weight:900}.back:hover{background:#fff6df;transform:translateY(-1px)}
.frame{background:var(--paper);border:4px solid var(--line);box-shadow:7px 7px var(--shadow);padding:clamp(12px,2.5vw,24px)}.frame-head{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;border-bottom:3px solid var(--line);padding-bottom:12px;margin-bottom:16px}.eyebrow{font-size:10px;letter-spacing:2px;color:var(--green);font-weight:900}.frame-head h2{margin:4px 0 0;font-size:clamp(20px,3vw,29px)}.save-tag{background:var(--mint);border:2px solid var(--line);padding:7px 10px;font-size:10px;font-weight:900;white-space:nowrap}
.tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}.tab{border:2px solid var(--line);background:var(--paper2);padding:9px 13px;font-size:11px;font-weight:900;color:var(--ink)}.tab.active,.tab:hover{background:var(--green);color:#fff}.inventory-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr);gap:14px}.panel{border:3px solid var(--line);background:#fff9e9;min-width:0}.panel-title{margin:0;padding:10px 12px;background:var(--deep);color:#fff4d8;font-size:12px;letter-spacing:1px;border-bottom:3px solid var(--line);display:flex;justify-content:space-between;align-items:center}.panel-body{padding:12px}.search{width:100%;padding:10px;border:2px solid #899783;background:#fffdf5;margin-bottom:10px;color:var(--ink);outline:none}.item-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:8px;max-height:440px;overflow:auto;padding:2px}.item-card{position:relative;text-align:left;border:2px solid #9a9b7e;background:#f5edda;padding:10px;min-height:104px;color:var(--ink);display:flex;flex-direction:column;gap:5px}.item-card:hover,.item-card.selected{border:3px solid var(--green);background:#e7f0d9;padding:9px}.item-icon{font-size:25px}.item-name{font-weight:900;font-size:12px;line-height:1.3}.item-desc{font-size:10px;color:#657266;line-height:1.4}.qty{position:absolute;right:7px;top:7px;background:var(--deep);color:white;padding:4px 6px;font-size:10px;font-weight:900}.kind{font-size:9px;color:#53745a;text-transform:uppercase;font-weight:900;letter-spacing:1px}.empty{padding:24px 12px;text-align:center;color:var(--muted);font-size:12px;line-height:1.7}
.detail{min-height:300px;display:flex;flex-direction:column}.detail-inner{padding:16px;display:flex;flex-direction:column;gap:12px;flex:1}.detail-icon{width:72px;height:72px;display:grid;place-items:center;font-size:38px;background:#e5edda;border:3px solid #91a184}.detail h3{margin:0;font-size:20px}.detail-desc{font-size:12px;line-height:1.65;color:#5e6b60;margin:0}.detail-meta{display:flex;gap:8px;flex-wrap:wrap}.meta-chip{background:#e6e2cc;border:1px solid #a5a58a;padding:5px 8px;font-size:10px;font-weight:900}.field-label{font-size:10px;font-weight:900;letter-spacing:1px;color:#526c56;margin-bottom:5px;display:block}.field{width:100%;padding:10px;border:2px solid #8d9b83;background:#fffdf5;color:var(--ink)}.primary{border:3px solid var(--line);background:var(--green);color:white;padding:11px 14px;font-weight:900;box-shadow:3px 3px #b7b08f}.primary:hover{background:#315f42}.primary:disabled{opacity:.45;cursor:not-allowed}.secondary{border:2px solid var(--line);background:#e8ddc3;color:var(--ink);padding:8px 10px;font-size:10px;font-weight:900}.secondary:hover{background:#d8e8cc}.danger{border:2px solid #743b35;background:#f0d8cb;color:#743b35;padding:8px 10px;font-size:10px;font-weight:900}.hidden{display:none!important}
.lower{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.team-list{display:grid;gap:7px}.pokemon-row{display:flex;align-items:center;gap:10px;padding:8px;background:#f7f0df;border:2px solid #c2b99e;min-width:0}.pokemon-sprite{width:58px;height:58px;object-fit:contain;image-rendering:pixelated;flex:none}.pokemon-info{min-width:0;flex:1}.pokemon-info strong{display:block;font-size:12px}.pokemon-info small{display:block;font-size:10px;color:#657266;margin-top:4px;line-height:1.5}.slot-number{width:25px;height:25px;display:grid;place-items:center;background:var(--deep);color:white;font-size:11px;font-weight:900;flex:none}.slot-empty{padding:13px;border:2px dashed #a9ad91;color:#89917f;font-size:11px;text-align:center}.hp-track{height:6px;background:#d8d7c3;margin-top:6px;overflow:hidden}.hp-fill{height:100%;background:#6caa62}.reserve-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:7px}.reserve-card{border:2px solid #c2b99e;background:#f7f0df;padding:8px;display:flex;gap:8px;align-items:center;min-width:0}.reserve-card .pokemon-sprite{width:48px;height:48px}.reserve-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.reserve-actions select{max-width:66px;padding:5px;border:1px solid #899783;background:#fffdf5;font-size:10px}.reserve-card .secondary{padding:6px;font-size:9px}.status{font-size:9px;font-weight:900;color:var(--red)}.toast{position:fixed;right:18px;bottom:18px;max-width:min(420px,calc(100% - 36px));padding:13px 16px;background:#203d31;color:white;border:3px solid #f4e9ca;box-shadow:4px 4px #1c2b24;font-size:12px;z-index:10}.toast.error{background:#8e3d35}.hint{font-size:10px;color:#6b776b;line-height:1.6;margin:10px 0 0}.error-box{padding:14px;background:#f2d8cc;border:2px solid #9b5045;color:#783b33;font-size:12px;margin-bottom:12px}
@media(max-width:800px){.inventory-layout,.lower{grid-template-columns:1fr}.detail{min-height:unset}.item-grid{max-height:320px}}@media(max-width:480px){body{padding:12px 8px 32px}.frame{padding:10px}.frame-head{align-items:flex-start;flex-direction:column}.save-tag{white-space:normal}.item-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pokemon-row{gap:6px}.pokemon-sprite{width:46px;height:46px}.pokemon-row .secondary{padding:6px;font-size:9px}}
</style>
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
