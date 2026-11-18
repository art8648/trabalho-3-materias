<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Mochila
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Lista itens que o perfil realmente possui. */
    public function listarItens(int $idPerfil, int $idUsuario): array
    {
        $this->validarDonoPerfil($idPerfil, $idUsuario);
        $consulta = $this->pdo->prepare('
            SELECT i.id, i.nome, i.descricao, i.tipo_efeito, i.valor_efeito,
                   i.status_curado, ii.quantidade
            FROM inventario inv
            INNER JOIN inventario_item ii ON ii.id_inventario = inv.id
            INNER JOIN item i ON i.id = ii.id_item
            WHERE inv.id_perfil = :perfil AND ii.quantidade > 0 AND i.ativo = TRUE
            ORDER BY i.tipo_efeito, i.nome
        ');
        $consulta->execute(['perfil' => $idPerfil]);
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Cinco espacos jogaveis. O espaco 6 nao aparece aqui: e reservado ao Rayquaza. */
    public function listarEquipe(int $idPerfil, int $idUsuario): array
    {
        $this->validarDonoPerfil($idPerfil, $idUsuario);
        $consulta = $this->pdo->prepare('
            SELECT tp.posicao, pc.id AS id_capturado, pc.hp_atual, pc.desmaiado,
                   pc.status_condicao, p.nome, p.imagem, p.hp AS hp_max,
                   t.nome AS tipo
            FROM times tm
            INNER JOIN time_pokemon tp ON tp.id_time = tm.id
            INNER JOIN pokemon_capturado pc ON pc.id = tp.id_pokemon_capturado
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE tm.id_perfil = :perfil AND tp.posicao BETWEEN 1 AND 5
            ORDER BY tp.posicao
        ');
        $consulta->execute(['perfil' => $idPerfil]);
        $slots = array_fill(1, 5, null);
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $pokemon) {
            $slots[(int) $pokemon['posicao']] = $pokemon;
        }
        return $slots;
    }

    /** Reserva = todos os capturados do perfil que nao estao nos cinco espacos ativos. */
    public function listarReserva(int $idPerfil, int $idUsuario): array
    {
        $this->validarDonoPerfil($idPerfil, $idUsuario);
        $consulta = $this->pdo->prepare('
            SELECT pc.id AS id_capturado, pc.hp_atual, pc.desmaiado,
                   pc.status_condicao, pc.sexo, p.nome, p.imagem,
                   p.hp AS hp_max, t.nome AS tipo
            FROM pokemon_capturado pc
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE pc.id_perfil = :perfil
              AND NOT EXISTS (
                  SELECT 1
                  FROM times tm
                  INNER JOIN time_pokemon tp ON tp.id_time = tm.id
                  WHERE tm.id_perfil = pc.id_perfil
                    AND tp.id_pokemon_capturado = pc.id
              )
            ORDER BY pc.data_captura DESC, pc.id DESC
        ');
        $consulta->execute(['perfil' => $idPerfil]);
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Coloca um Pokemon da reserva em uma posicao; o antigo integrante volta automaticamente a reserva. */
    public function trocarComReserva(int $idPerfil, int $idUsuario, int $idCapturado, int $posicao): void
    {
        if ($posicao < 1 || $posicao > 5) {
            throw new DomainException('Escolha uma das cinco posicoes da equipe.');
        }
        $this->pdo->beginTransaction();
        try {
            $idTime = $this->buscarTime($idPerfil, $idUsuario, true);
            $capturado = $this->pdo->prepare('SELECT id FROM pokemon_capturado WHERE id = :id AND id_perfil = :perfil FOR UPDATE');
            $capturado->execute(['id' => $idCapturado, 'perfil' => $idPerfil]);
            if ($capturado->fetchColumn() === false) {
                throw new DomainException('Esse Pokemon nao pertence a este perfil.');
            }

            $jaNaEquipe = $this->pdo->prepare('SELECT posicao FROM time_pokemon WHERE id_time = :time AND id_pokemon_capturado = :capturado FOR UPDATE');
            $jaNaEquipe->execute(['time' => $idTime, 'capturado' => $idCapturado]);
            if ($jaNaEquipe->fetchColumn() !== false) {
                throw new DomainException('Esse Pokemon ja esta na equipe.');
            }

            $vaga = $this->pdo->prepare('SELECT id FROM time_pokemon WHERE id_time = :time AND posicao = :posicao FOR UPDATE');
            $vaga->execute(['time' => $idTime, 'posicao' => $posicao]);
            $idLinhaAntiga = $vaga->fetchColumn();
            if ($idLinhaAntiga !== false) {
                $remover = $this->pdo->prepare('DELETE FROM time_pokemon WHERE id = :id AND id_time = :time');
                $remover->execute(['id' => $idLinhaAntiga, 'time' => $idTime]);
            }

            $inserir = $this->pdo->prepare('
                INSERT INTO time_pokemon (id_time, id_pokemon, id_pokemon_capturado, posicao)
                VALUES (:time, NULL, :capturado, :posicao)
            ');
            $inserir->execute(['time' => $idTime, 'capturado' => $idCapturado, 'posicao' => $posicao]);
            $this->pdo->commit();
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }

    /** Retira um integrante da equipe e o devolve a reserva. */
    public function enviarParaReserva(int $idPerfil, int $idUsuario, int $posicao): void
    {
        if ($posicao < 1 || $posicao > 5) {
            throw new DomainException('Posicao invalida.');
        }
        $this->pdo->beginTransaction();
        try {
            $idTime = $this->buscarTime($idPerfil, $idUsuario, true);
            $consulta = $this->pdo->prepare('SELECT id FROM time_pokemon WHERE id_time = :time AND posicao = :posicao FOR UPDATE');
            $consulta->execute(['time' => $idTime, 'posicao' => $posicao]);
            $idLinha = $consulta->fetchColumn();
            if ($idLinha === false) {
                throw new DomainException('Essa posicao ja esta vazia.');
            }
            $remover = $this->pdo->prepare('DELETE FROM time_pokemon WHERE id = :id AND id_time = :time');
            $remover->execute(['id' => $idLinha, 'time' => $idTime]);
            $this->pdo->commit();
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }

    /** Aplica itens de cura/status/revive. A quantidade so e debitada se houver efeito valido. */
    public function usarItem(int $idPerfil, int $idUsuario, int $idItem, int $idCapturado): string
    {
        $this->pdo->beginTransaction();
        try {
            $idInventario = $this->buscarInventario($idPerfil, $idUsuario, true);
            $itemConsulta = $this->pdo->prepare('
                SELECT i.*, ii.quantidade
                FROM inventario_item ii
                INNER JOIN item i ON i.id = ii.id_item
                WHERE ii.id_inventario = :inventario AND i.id = :item AND i.ativo = TRUE
                FOR UPDATE
            ');
            $itemConsulta->execute(['inventario' => $idInventario, 'item' => $idItem]);
            $item = $itemConsulta->fetch(PDO::FETCH_ASSOC);
            if (!$item || (int) $item['quantidade'] < 1) {
                throw new DomainException('Voce nao possui esse item.');
            }

            if ($item['tipo_efeito'] === 'PEDRA_EVOLUCAO') {
                throw new DomainException('Selecione o Pokemon e a evolucao desejada na opcao de evolucao.');
            }

            $pokemonConsulta = $this->pdo->prepare('
                SELECT pc.id, pc.id_perfil, pc.hp_atual, pc.desmaiado, pc.status_condicao,
                       p.hp AS hp_max, p.nome
                FROM pokemon_capturado pc
                INNER JOIN pokemon p ON p.id = pc.id_pokemon
                WHERE pc.id = :capturado AND pc.id_perfil = :perfil
                FOR UPDATE
            ');
            $pokemonConsulta->execute(['capturado' => $idCapturado, 'perfil' => $idPerfil]);
            $pokemon = $pokemonConsulta->fetch(PDO::FETCH_ASSOC);
            if (!$pokemon) {
                throw new DomainException('Pokemon nao encontrado neste perfil.');
            }

            $hpAtual = (int) $pokemon['hp_atual'];
            $hpMax = (int) $pokemon['hp_max'];
            $desmaiado = ($hpAtual <= 0); // HP e a fonte de verdade; corrige flag desmaiado inconsistente
            $status = $pokemon['status_condicao'];
            $novoHp = $hpAtual;
            $novoDesmaiado = $desmaiado ? 1 : 0;
            $novoStatus = $status;
            $mensagem = '';

            switch ($item['tipo_efeito']) {
                case 'CURA_HP':
                    if ($desmaiado || $hpAtual <= 0) {
                        throw new DomainException('Esse item nao funciona em um Pokemon desmaiado. Use Revive.');
                    }
                    if ($hpAtual >= $hpMax) {
                        throw new DomainException('Esse Pokemon ja esta com o HP cheio.');
                    }
                    $novoHp = min($hpMax, $hpAtual + (int) $item['valor_efeito']);
                    $mensagem = $pokemon['nome'] . ' recuperou ' . ($novoHp - $hpAtual) . ' HP.';
                    break;

                case 'CURA_TOTAL':
                    if ($desmaiado || $hpAtual <= 0) {
                        throw new DomainException('Esse Pokemon esta desmaiado. Use Revive primeiro.');
                    }
                    $novoHp = $hpMax;
                    if ($item['status_curado'] === 'TODOS') {
                        $novoStatus = null;
                    }
                    if ($novoHp === $hpAtual && $novoStatus === $status) {
                        throw new DomainException('Esse Pokemon ja esta totalmente recuperado.');
                    }
                    $mensagem = $pokemon['nome'] . ' teve o HP restaurado' . ($item['status_curado'] === 'TODOS' ? ' e as condicoes curadas.' : '.');
                    break;

                case 'CURA_STATUS':
                    if ($status === null) {
                        throw new DomainException('Esse Pokemon nao possui uma condicao de status para curar.');
                    }
                    if ($item['status_curado'] !== 'TODOS' && $item['status_curado'] !== $status) {
                        throw new DomainException('Esse item nao cura a condicao atual desse Pokemon.');
                    }
                    $novoStatus = null;
                    $mensagem = 'A condicao de status de ' . $pokemon['nome'] . ' foi curada.';
                    break;

                case 'REVIVER':
                    if (!$desmaiado && $hpAtual > 0) {
                        throw new DomainException('Revive so pode ser usado em um Pokemon desmaiado.');
                    }
                    $novoHp = max(1, (int) ceil($hpMax * ((int) $item['valor_efeito'] / 100)));
                    $novoDesmaiado = 0;
                    $mensagem = $pokemon['nome'] . ' voltou a batalha com ' . $novoHp . ' HP.';
                    break;

                default:
                    throw new DomainException('Tipo de item ainda nao implementado.');
            }

            $atualizar = $this->pdo->prepare('
                UPDATE pokemon_capturado
                SET hp_atual = :hp, desmaiado = :desmaiado, status_condicao = :status
                WHERE id = :id AND id_perfil = :perfil
            ');
            $atualizar->execute([
                'hp' => $novoHp,
                'desmaiado' => $novoDesmaiado,
                'status' => $novoStatus,
                'id' => $idCapturado,
                'perfil' => $idPerfil,
            ]);

            $this->consumirItem($idInventario, $idItem, (int) $item['quantidade']);
            $this->pdo->commit();
            return $mensagem;
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }

    /** Retorna evolucoes validas para o exemplar, respeitando a regra e o sexo. */
    public function listarEvolucoes(int $idPerfil, int $idUsuario, int $idCapturado): array
    {
        $this->validarDonoPerfil($idPerfil, $idUsuario);
        $consulta = $this->pdo->prepare('
            SELECT pc.id AS id_capturado, pc.sexo, po.nome AS pokemon_atual,
                   pd.id AS id_destino, pd.nome AS pokemon_destino, pd.imagem AS imagem_destino,
                   er.sexo_requerido
            FROM pokemon_capturado pc
            INNER JOIN evolution_rules er ON er.id_pokemon_origem = pc.id_pokemon
            INNER JOIN pokemon po ON po.id = er.id_pokemon_origem
            INNER JOIN pokemon pd ON pd.id = er.id_pokemon_destino
            INNER JOIN item i ON i.id = er.id_item
            WHERE pc.id = :capturado AND pc.id_perfil = :perfil
              AND i.nome = :pedra AND i.tipo_efeito = :efeito
              AND (er.sexo_requerido IS NULL OR er.sexo_requerido = pc.sexo)
              AND NOT EXISTS (
                  SELECT 1
                  FROM evolution_rules etapa1
                  INNER JOIN evolution_rules etapa2
                    ON etapa2.id_pokemon_origem = etapa1.id_pokemon_destino
                  WHERE etapa1.id_pokemon_origem = er.id_pokemon_origem
                    AND etapa2.id_pokemon_destino = er.id_pokemon_destino
              )
            ORDER BY pd.nome
        ');
        $consulta->execute([
            'capturado' => $idCapturado,
            'perfil' => $idPerfil,
            'pedra' => 'Pedra Universal',
            'efeito' => 'PEDRA_EVOLUCAO',
        ]);
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Evolui usando Pedra Universal, validando regra, sexo, dono e quantidade na mesma transacao. */
    public function evoluirComPedra(int $idPerfil, int $idUsuario, int $idCapturado, int $idDestino): string
    {
        $this->pdo->beginTransaction();
        try {
            $idInventario = $this->buscarInventario($idPerfil, $idUsuario, true);
            $pc = $this->pdo->prepare('SELECT pc.id, pc.sexo, pc.id_pokemon, pc.hp_atual, pc.desmaiado, p.nome FROM pokemon_capturado pc INNER JOIN pokemon p ON p.id = pc.id_pokemon WHERE pc.id = :id AND pc.id_perfil = :perfil FOR UPDATE');
            $pc->execute(['id' => $idCapturado, 'perfil' => $idPerfil]);
            $pokemon = $pc->fetch(PDO::FETCH_ASSOC);
            if (!$pokemon) {
                throw new DomainException('Pokemon nao encontrado neste perfil.');
            }

            $regra = $this->pdo->prepare('
                SELECT pd.nome, pd.hp AS hp_destino
                FROM evolution_rules er
                INNER JOIN pokemon pd ON pd.id = er.id_pokemon_destino
                INNER JOIN item i ON i.id = er.id_item
                WHERE er.id_pokemon_origem = :origem
                  AND er.id_pokemon_destino = :destino
                  AND i.nome = :pedra AND i.tipo_efeito = :efeito
                  AND (er.sexo_requerido IS NULL OR er.sexo_requerido = :sexo)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM evolution_rules etapa1
                      INNER JOIN evolution_rules etapa2
                        ON etapa2.id_pokemon_origem = etapa1.id_pokemon_destino
                      WHERE etapa1.id_pokemon_origem = er.id_pokemon_origem
                        AND etapa2.id_pokemon_destino = er.id_pokemon_destino
                  )
                LIMIT 1
                FOR UPDATE
            ');
            $regra->execute([
                'origem' => $pokemon['id_pokemon'],
                'destino' => $idDestino,
                'pedra' => 'Pedra Universal',
                'efeito' => 'PEDRA_EVOLUCAO',
                'sexo' => $pokemon['sexo'],
            ]);
            $dadosDestino = $regra->fetch(PDO::FETCH_ASSOC);
            if (!$dadosDestino) {
                throw new DomainException('Essa evolucao nao existe ou nao e compativel com o sexo do Pokemon.');
            }

            $pedra = $this->pdo->prepare('
                SELECT ii.quantidade, i.id
                FROM inventario_item ii
                INNER JOIN item i ON i.id = ii.id_item
                WHERE ii.id_inventario = :inventario
                  AND i.nome = :nome AND i.tipo_efeito = :efeito
                FOR UPDATE
            ');
            $pedra->execute(['inventario' => $idInventario, 'nome' => 'Pedra Universal', 'efeito' => 'PEDRA_EVOLUCAO']);
            $dadosPedra = $pedra->fetch(PDO::FETCH_ASSOC);
            if (!$dadosPedra || (int) $dadosPedra['quantidade'] < 1) {
                throw new DomainException('Voce nao possui uma Pedra Universal.');
            }

            $nomeDestino = (string) $dadosDestino['nome'];
            $hpDestino = (int) $dadosDestino['hp_destino'];
            // Evoluir recupera totalmente o Pokemon, usando o HP maximo da nova especie.
            $hpNovo = $hpDestino;
            $desmaiadoNovo = 0;
            $evoluir = $this->pdo->prepare('UPDATE pokemon_capturado SET id_pokemon = :destino, hp_atual = :hp, desmaiado = :desmaiado WHERE id = :id AND id_perfil = :perfil');
            $evoluir->execute(['destino' => $idDestino, 'hp' => $hpNovo, 'desmaiado' => $desmaiadoNovo, 'id' => $idCapturado, 'perfil' => $idPerfil]);
            $this->consumirItem($idInventario, (int) $dadosPedra['id'], (int) $dadosPedra['quantidade']);
            $this->pdo->commit();
            return $pokemon['nome'] . ' evoluiu para ' . $nomeDestino . '!';
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }

    private function validarDonoPerfil(int $idPerfil, int $idUsuario): void
    {
        $consulta = $this->pdo->prepare('SELECT id FROM perfil WHERE id = :perfil AND id_usuario = :usuario LIMIT 1');
        $consulta->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        if ($consulta->fetchColumn() === false) {
            throw new DomainException('Perfil nao encontrado para este usuario.');
        }
    }

    private function buscarTime(int $idPerfil, int $idUsuario, bool $bloquear = false): int
    {
        $sql = 'SELECT tm.id FROM times tm INNER JOIN perfil p ON p.id = tm.id_perfil WHERE tm.id_perfil = :perfil AND p.id_usuario = :usuario LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '');
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        $id = $consulta->fetchColumn();
        if ($id === false) {
            throw new DomainException('Time nao encontrado para este perfil.');
        }
        return (int) $id;
    }

    private function buscarInventario(int $idPerfil, int $idUsuario, bool $bloquear = false): int
    {
        $this->validarDonoPerfil($idPerfil, $idUsuario);
        $sql = 'SELECT id FROM inventario WHERE id_perfil = :perfil LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '');
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['perfil' => $idPerfil]);
        $id = $consulta->fetchColumn();
        if ($id === false) {
            throw new DomainException('Inventario nao encontrado para este perfil.');
        }
        return (int) $id;
    }

    private function consumirItem(int $idInventario, int $idItem, int $quantidadeAtual): void
    {
        if ($quantidadeAtual <= 1) {
            $consulta = $this->pdo->prepare('DELETE FROM inventario_item WHERE id_inventario = :inventario AND id_item = :item');
            $consulta->execute(['inventario' => $idInventario, 'item' => $idItem]);
        } else {
            $consulta = $this->pdo->prepare('UPDATE inventario_item SET quantidade = quantidade - 1 WHERE id_inventario = :inventario AND id_item = :item AND quantidade > 0');
            $consulta->execute(['inventario' => $idInventario, 'item' => $idItem]);
        }
    }
}
