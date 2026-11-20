<?php
declare(strict_types=1);

require_once __DIR__ . '/app/sessao.php';
require_once __DIR__ . '/app/autoload.php';

if (!isset($_SESSION['usuario_id'], $_SESSION['perfil_id'])) {
    header('Location: perfis.php');
    exit;
}

$idUsuario = (int) $_SESSION['usuario_id'];
$idPerfil = (int) $_SESSION['perfil_id'];
$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$perfil = (string) ($_SESSION['perfil_nome'] ?? 'Perfil');
$batalhas = $eventos = $compras = $logsPorBatalha = [];
$erro = null;

try {
    $pdo = require __DIR__ . '/config/database.php';
    // Nao aceitar perfil por GET: o historico e sempre do usuario autenticado.
    $validar = $pdo->prepare('SELECT nome_perfil FROM perfil WHERE id = :perfil AND id_usuario = :usuario LIMIT 1');
    $validar->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
    $nome = $validar->fetchColumn();
    if ($nome === false) {
        throw new DomainException('Selecione um perfil para consultar o histórico.');
    }
    $perfil = (string) $nome;

    $consulta = $pdo->prepare('SELECT b.id, b.tipo_batalha, b.resultado, b.dinheiro_ganho,
        b.data_inicio, b.data_fim, c.nome AS adversario, a.nome AS area_nome
        FROM batalha b
        INNER JOIN personagem c ON c.id = b.id_personagem_adversario
        LEFT JOIN area_jogo a ON a.id = b.id_area
        WHERE b.id_perfil = :perfil ORDER BY b.id DESC LIMIT 15');
    $consulta->execute(['perfil' => $idPerfil]);
    $batalhas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    if ($batalhas !== []) {
        $ids = array_map(static fn (array $b): int => (int) $b['id'], $batalhas);
        $lugares = implode(',', array_fill(0, count($ids), '?'));
        $consulta = $pdo->prepare("SELECT id_batalha, turno, tipo_evento, mensagem, dano, data_evento
            FROM log_batalha WHERE id_batalha IN ($lugares)
            ORDER BY id_batalha DESC, turno ASC, id ASC LIMIT 2000");
        $consulta->execute($ids);
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $evento) {
            $logsPorBatalha[(int) $evento['id_batalha']][] = $evento;
        }
    }

    // Se o perfil foi reiniciado e a migracao opcional esta instalada,
    // exibir tambem as batalhas anteriores. Nao participam da progressao atual.
    $temArquivo = $pdo->query("SHOW TABLES LIKE 'historico_batalha_arquivo'")->fetchColumn() !== false;
    if ($temArquivo) {
        $antigas = $pdo->prepare('SELECT id_batalha_original AS id, tipo_batalha,
            resultado, dinheiro_ganho, data_inicio, data_fim, adversario, area_nome,
            turnos_json FROM historico_batalha_arquivo
            WHERE id_perfil = :perfil ORDER BY data_inicio DESC, id DESC LIMIT 15');
        $antigas->execute(['perfil' => $idPerfil]);
        foreach ($antigas->fetchAll(PDO::FETCH_ASSOC) as $antiga) {
            $idAntigo = (int) $antiga['id'];
            $turnosAntigos = json_decode((string) $antiga['turnos_json'], true);
            $logsPorBatalha[$idAntigo] = is_array($turnosAntigos) ? $turnosAntigos : [];
            unset($antiga['turnos_json']);
            $antiga['arquivada'] = true;
            $batalhas[] = $antiga;
        }
        usort($batalhas, static function (array $a, array $b): int {
            return strcmp((string) $b['data_inicio'], (string) $a['data_inicio'])
                ?: ((int) $b['id'] <=> (int) $a['id']);
        });
        $batalhas = array_slice($batalhas, 0, 15);
    }

    $consulta = $pdo->prepare('SELECT tipo_evento, descricao, data_evento
        FROM log_perfil WHERE id_perfil = :perfil
        ORDER BY id DESC LIMIT 80');
    $consulta->execute(['perfil' => $idPerfil]);
    $eventos = $consulta->fetchAll(PDO::FETCH_ASSOC);

    $consulta = $pdo->prepare('SELECT ll.descricao, ll.quantidade, ll.valor_total, ll.data_evento,
        i.nome AS item_nome FROM log_loja ll
        INNER JOIN item i ON i.id = ll.id_item
        WHERE ll.id_perfil = :perfil ORDER BY ll.id DESC LIMIT 20');
    $consulta->execute(['perfil' => $idPerfil]);
    $compras = $consulta->fetchAll(PDO::FETCH_ASSOC);
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
    'BATALHA_ARQUIVADA' => 'Batalha da jornada anterior',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Histórico | Peakmon</title>
<style>
:root {color-scheme:light;--ink:#253c43;--paper:#fff7de;--edge:#163a43;--red:#b74838;--teal:#48a594;}
*{box-sizing:border-box}
body {margin:0;min-height:100vh;padding:18px 14px 50px;color:var(--ink);font-family:monospace;font-size:17px;background:repeating-linear-gradient(45deg,#75bdae 0 18px,#71b6a8 18px 36px)}
.wrap{max-width:1120px;margin:auto}
.header{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 22px;background:var(--paper);border:4px solid var(--edge);box-shadow:5px 5px #25535a}
.header h1{margin:0;font-size:clamp(24px,4vw,33px);line-height:1.1}
.header p{margin:3px 0 0;color:#607476}
.button{display:inline-block;padding:10px 15px;background:#eacb78;color:var(--ink);border:3px solid var(--edge);box-shadow:3px 3px var(--edge);text-decoration:none;font-weight:700}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin:20px 0}.stat{flex:1 1 155px;padding:12px 16px;background:var(--paper);border:3px solid var(--edge);font-weight:700}.stat strong{display:block;font-size:26px;color:var(--red)}
.grid{display:grid;grid-template-columns:minmax(0,1.28fr) minmax(290px,.9fr);align-items:start;gap:17px}
.panel{min-width:0;padding:18px;background:var(--paper);border:4px solid var(--edge);box-shadow:4px 5px #25535a}
.panel h2{margin:0 0 14px;font-size:clamp(21px,3vw,27px);border-bottom:3px dashed #cfb989;padding-bottom:7px}
.battle{margin:10px 0;background:#f7e9bd;border:3px solid #547b77}
.battle summary{cursor:pointer;padding:13px;list-style:none;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between;font-weight:700}
.battle summary::-webkit-details-marker{display:none}.battle summary:before{content:'▸';color:var(--red)}.battle[open] summary:before{content:'▾'}
.battle .sub{font-size:13px;color:#52696e}.status{padding:3px 9px;background:#dcebc7;border:2px solid #46765b;font-weight:700}.status.derrota{background:#f8c7b7;border-color:#b94a3a}.status.andamento{background:#fff1b5;border-color:#b99c3c}
.turns{margin:0;max-height:330px;overflow:auto;padding:0 10px 12px;list-style:none}.turns li{padding:7px 4px;border-bottom:1px dotted #cbb784;font-size:15px}.turns .turn{font-weight:bold;color:#925037;margin-right:7px}
.timeline{list-style:none;margin:0;padding:0}.timeline li{margin:0 0 12px;padding:11px 12px;border:2px solid #8aa69c;background:#fffdf2}
.timeline .type{display:block;color:#9e432e;font-weight:bold}.timeline small{display:block;color:#617d7d;margin-top:4px}
.shop{margin-top:17px}.shop li{font-size:15px}.muted{color:#667c81;line-height:1.5}
/* Historico: a pagina fica estavel e cada coluna rola por conta propria. */
@media(min-width:861px) and (min-height:530px){
  body { height:100dvh; overflow:hidden; padding-bottom:14px; }
  .wrap { height:100%; display:flex; flex-direction:column; }
  .grid { flex:1; min-height:0; }
}
.grid{align-items:stretch}
.history-panel{display:flex;flex-direction:column;min-height:0;overflow:hidden}
.history-panel>h2,.history-panel>p{flex:none}
.history-scroll{min-height:0;flex:1;overflow-y:auto;overscroll-behavior:contain;scrollbar-color:#89b8ab #f3e4bf;scrollbar-width:thin;padding-right:5px}
.history-side{display:flex;flex-direction:column;min-height:0;gap:17px}
.history-side>.history-panel:first-child{flex:3}
.history-side>.history-panel:last-child{flex:1.4;margin-top:0}
.status.antigo{background:#d9e6e9;border-color:#5c8794}
@media(max-width:860px){
  .grid{grid-template-columns:minmax(0,1fr)}
  .history-panel{max-height: min(64vh,680px);min-height:240px}
  .history-side>.history-panel:first-child{max-height: min(64vh,680px)}
  .history-side>.history-panel:last-child{max-height: min(43vh,390px)}
  .panel{padding:13px}.header{padding:13px}
}

</style>
</head>
<body>
<div class="wrap">
  <header class="header"><div><p>PEAKMON: THE BIGOLIN CRISIS</p><h1>📜 Diário de Jornada</h1><p>Histórico do <?= $esc($perfil) ?></p></div><a class="button" href="mapa.php">← Voltar ao mapa</a></header>
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
