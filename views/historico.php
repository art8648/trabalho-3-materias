<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


require_once PEAKMON_ROOT . '/app/sessao.php';
require_once PEAKMON_ROOT . '/app/autoload.php';

if (!isset($_SESSION['usuario_id'], $_SESSION['perfil_id'])) {
    header('Location: index.php?pagina=perfis');
    exit;
}

$idUsuario = (int) $_SESSION['usuario_id'];
$idPerfil = (int) $_SESSION['perfil_id'];
$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$perfil = (string) ($_SESSION['perfil_nome'] ?? 'Perfil');
$batalhas = $eventos = $compras = $logsPorBatalha = [];
$erro = null;

try {
    $pdo = require PEAKMON_ROOT . '/config/database.php';
    $controller = new \Peakmon\Controllers\HistoricoController($pdo);
    $pagina = $controller->prepararPagina($idPerfil, $idUsuario);
    $perfil = $pagina['perfil'];
    $batalhas = $pagina['batalhas'];
    $eventos = $pagina['eventos'];
    $compras = $pagina['compras'];
    $logsPorBatalha = $pagina['logsPorBatalha'];
} catch (Throwable $ex) {
    error_log((string) $ex);
    $erro = $ex instanceof DomainException ? $ex->getMessage() : 'Não foi possível consultar o histórico. Verifique a conexão com o banco.';
}

$rotulos = [
    'POKEMON_CAPTURADO' => 'Captura',
    'POKEMON_EVOLUIDO' => 'Evolução',
    'POKEMON_GUARDADO' => 'Arquivo Bigolin',
    'TIME_ATUALIZADO' => 'Equipe',
    'BATALHA_VENCIDA' => 'Vitória',
    'BATALHA_PERDIDA' => 'Derrota',
    'CHEFE_DERROTADO' => 'Chefe derrotado',
    'ITEM_ENCONTRADO' => 'Item surpresa',
    'ITEM_UTILIZADO' => 'Item utilizado',
    'CENTRO_POKEMON' => 'Centro Pokémon',
    'AREA_CONCLUIDA' => 'Área concluída',
    'PERFIL_REINICIADO' => 'Perfil reiniciado',
    'JOGO_CONCLUIDO' => 'Fim de jogo',
    'BATALHA_ARQUIVADA' => 'Batalha da jornada anterior',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Histórico | Peakmon</title>
<link rel="stylesheet" href="assets/css/historico.css">
</head>
<body>
<div class="wrap">
  <header class="header"><div><p>PEAKMON: THE BIGOLIN CRISIS</p><h1>📜 Diário de Jornada</h1><p>Histórico do <?= $esc($perfil) ?></p></div><a class="button" href="index.php?pagina=mapa">← Voltar ao mapa</a></header>
  <?php if ($erro !== null): ?><div class="panel" style="margin-top:20px;color:#a42d29"><?= $esc($erro) ?></div><?php else: ?>
  <div class="stats"><div class="stat"><strong><?= count($batalhas) ?></strong>Batalhas recentes</div><div class="stat"><strong><?= count($eventos) ?></strong>Eventos recentes</div><div class="stat"><strong><?= count($compras) ?></strong>Compras recentes</div></div>
  <div class="grid">
    <section class="panel history-panel"><h2>⚔ Batalhas e turnos</h2><p class="muted">Últimas 15 batalhas. Clique para abrir o registro de cada turno, incluindo danos, efeitos, itens e desmaios.</p>
      <div class="history-scroll">
      <?php if (!$batalhas): ?><p class="muted">Você ainda não enfrentou nenhuma batalha neste perfil.</p><?php endif; ?>
      <?php foreach ($batalhas as $b): ?>
      <details class="battle">
        <summary><span>#<?= (int) $b['id'] ?> · <?= $esc($b['adversario']) ?><span class="sub"> · <?= $esc($b['tipo_batalha']) ?> · <?= $esc($b['area_nome'] ?? 'Área não informada') ?></span></span>
          <?php if (!empty($b['arquivada'])): ?><span class="status antigo">Jornada anterior</span><?php endif; ?>
          <span class="status <?= $b['resultado'] === 'DERROTA' ? 'derrota' : ($b['resultado'] === 'EM_ANDAMENTO' ? 'andamento' : '') ?>"><?= $esc($b['resultado']) ?></span>
        </summary>
        <ul class="turns">
          <?php foreach ($logsPorBatalha[(int) $b['id']] ?? [] as $ev): ?>
            <li><span class="turn">T<?= (int) $ev['turno'] ?></span><strong><?= $esc(str_replace('_',' ',(string)$ev['tipo_evento'])) ?>:</strong> <?= $esc($ev['mensagem']) ?></li>
          <?php endforeach; ?>
          <?php if (!isset($logsPorBatalha[(int) $b['id']])): ?><li class="muted">Não há turnos registrados nesta batalha.</li><?php endif; ?>
          <li class="muted">Início: <?= $esc($b['data_inicio']) ?><?php if ($b['data_fim']): ?> · Fim: <?= $esc($b['data_fim']) ?><?php endif; ?> · Recompensa: <?= (int) $b['dinheiro_ganho'] ?> Pokédollars</li>
        </ul>
      </details>
      <?php endforeach; ?>
      </div>
    </section>
    <div class="history-side">
      <section class="panel history-panel"><h2>✨ Eventos do perfil</h2><p class="muted">Capturas, evoluções, vitórias, chefes, itens e outras conquistas.</p>
        <?php if (!$eventos): ?><p class="muted">Os novos acontecimentos da jornada aparecerão aqui.</p><?php endif; ?>
        <ol class="timeline history-scroll">
        <?php foreach ($eventos as $ev): ?><li><span class="type"><?= $esc($rotulos[$ev['tipo_evento']] ?? str_replace('_',' ', $ev['tipo_evento'])) ?></span><?= $esc($ev['descricao']) ?><small><?= $esc($ev['data_evento']) ?></small></li><?php endforeach; ?>
        </ol>
      </section>
      <section class="panel shop history-panel"><h2>🛒 Compras na loja</h2>
        <?php if (!$compras): ?><p class="muted">Nenhuma compra registrada neste perfil.</p><?php endif; ?>
        <ol class="timeline history-scroll"><?php foreach ($compras as $c): ?><li><span class="type"><?= $esc($c['item_nome']) ?> × <?= (int)$c['quantidade'] ?></span><?= (int)$c['valor_total'] ?> Pokédollars<small><?= $esc($c['data_evento']) ?></small></li><?php endforeach; ?></ol>
      </section>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
