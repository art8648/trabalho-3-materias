<?php
declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

/** Eventos narrativos do save; as acoes de cada turno ficam em log_batalha. */
final class Historico
{
    public function __construct(private PDO $pdo) {}

    /** Pode ser chamado dentro da transacao da acao: falhar faz rollback do conjunto. */
    public function registrar(int $idPerfil, string $tipo, string $descricao): void
    {
        if ($idPerfil <= 0 || !preg_match('/^[A-Z0-9_]{1,40}$/', $tipo)) {
            throw new DomainException('Evento de historico invalido.');
        }
        $consulta = $this->pdo->prepare(''
            . 'INSERT INTO log_perfil (id_usuario, id_perfil, tipo_evento, descricao) '
            . 'SELECT p.id_usuario, p.id, :tipo, :descricao FROM perfil p WHERE p.id = :perfil');
        $consulta->execute([
            'perfil' => $idPerfil,
            'tipo' => $tipo,
            'descricao' => $descricao,
        ]);
        if ($consulta->rowCount() !== 1) {
            throw new DomainException('Perfil nao encontrado para registrar o historico.');
        }
    }

    /** Consultas do Diario: o Model acessa os dados, o Controller decide o fluxo. */
    public function consultar(int $idPerfil, int $idUsuario): array
    {
        $batalhas = $eventos = $compras = $logsPorBatalha = [];
        $perfil = '';
    // Nao aceitar perfil por GET: o historico e sempre do usuario autenticado.
    $validar = $this->pdo->prepare('SELECT nome_perfil FROM perfil WHERE id = :perfil AND id_usuario = :usuario LIMIT 1');
    $validar->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
    $nome = $validar->fetchColumn();
    if ($nome === false) {
        throw new DomainException('Selecione um perfil para consultar o histórico.');
    }
    $perfil = (string) $nome;

    $consulta = $this->pdo->prepare('SELECT b.id, b.tipo_batalha, b.resultado, b.dinheiro_ganho,
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
        $consulta = $this->pdo->prepare("SELECT id_batalha, turno, tipo_evento, mensagem, dano, data_evento
            FROM log_batalha WHERE id_batalha IN ($lugares)
            ORDER BY id_batalha DESC, turno ASC, id ASC LIMIT 2000");
        $consulta->execute($ids);
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $evento) {
            $logsPorBatalha[(int) $evento['id_batalha']][] = $evento;
        }
    }

    // Se o perfil foi reiniciado e a migracao opcional esta instalada,
    // exibir tambem as batalhas anteriores. Nao participam da progressao atual.
    $temArquivo = $this->pdo->query("SHOW TABLES LIKE 'historico_batalha_arquivo'")->fetchColumn() !== false;
    if ($temArquivo) {
        $antigas = $this->pdo->prepare('SELECT id_batalha_original AS id, tipo_batalha,
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

    $consulta = $this->pdo->prepare('SELECT tipo_evento, descricao, data_evento
        FROM log_perfil WHERE id_perfil = :perfil
        ORDER BY id DESC LIMIT 80');
    $consulta->execute(['perfil' => $idPerfil]);
    $eventos = $consulta->fetchAll(PDO::FETCH_ASSOC);

    $consulta = $this->pdo->prepare('SELECT ll.descricao, ll.quantidade, ll.valor_total, ll.data_evento,
        i.nome AS item_nome FROM log_loja ll
        INNER JOIN item i ON i.id = ll.id_item
        WHERE ll.id_perfil = :perfil ORDER BY ll.id DESC LIMIT 20');
    $consulta->execute(['perfil' => $idPerfil]);
    $compras = $consulta->fetchAll(PDO::FETCH_ASSOC);
        return compact('perfil', 'batalhas', 'eventos', 'compras', 'logsPorBatalha');
    }

}
