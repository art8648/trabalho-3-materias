<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Time
{
    // A equipe normal possui cinco espacos. A posicao 6 fica reservada ao Rayquaza.
    public const POSICOES = 5;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Retorna as cinco posicoes da equipe; posicoes vazias recebem null. */
    public function listar(int $idPerfil): array
    {
        $consulta = $this->pdo->prepare('
            SELECT
                tp.posicao,
                pc.id AS id_capturado,
                pc.hp_atual,
                pc.desmaiado,
                pc.status_condicao,
                p.nome,
                p.imagem,
                p.hp AS hp_max,
                t.nome AS tipo
            FROM times tm
            INNER JOIN time_pokemon tp ON tp.id_time = tm.id
            INNER JOIN pokemon_capturado pc ON pc.id = tp.id_pokemon_capturado
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE tm.id_perfil = :id_perfil
              AND tp.posicao BETWEEN 1 AND 5
            ORDER BY tp.posicao ASC
        ');
        $consulta->execute(['id_perfil' => $idPerfil]);

        $posicoes = array_fill(1, self::POSICOES, null);
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $posicoes[(int) $linha['posicao']] = $linha;
        }
        return $posicoes;
    }

    /** Move para uma vaga vazia ou troca dois integrantes sem usar a posicao 6. */
    public function mover(int $idPerfil, int $idUsuario, int $de, int $para): void
    {
        if ($de < 1 || $de > self::POSICOES || $para < 1 || $para > self::POSICOES) {
            throw new DomainException('A equipe normal possui apenas cinco posicoes.');
        }
        if ($de === $para) {
            throw new DomainException('Escolha duas posicoes diferentes.');
        }

        $this->pdo->beginTransaction();
        try {
            $idTime = $this->buscarTimeDoPerfil($idPerfil, $idUsuario, true);

            $consulta = $this->pdo->prepare('
                SELECT id, id_pokemon, id_pokemon_capturado, posicao
                FROM time_pokemon
                WHERE id_time = :id_time AND posicao IN (:de, :para)
                FOR UPDATE
            ');
            $consulta->execute(['id_time' => $idTime, 'de' => $de, 'para' => $para]);
            $linhas = [];
            foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                $linhas[(int) $linha['posicao']] = $linha;
            }

            if (!isset($linhas[$de])) {
                throw new DomainException('Nao existe Pokemon na posicao de origem.');
            }

            if (!isset($linhas[$para])) {
                $mover = $this->pdo->prepare('UPDATE time_pokemon SET posicao = :destino WHERE id = :id AND id_time = :time');
                $mover->execute(['destino' => $para, 'id' => $linhas[$de]['id'], 'time' => $idTime]);
            } else {
                // Apaga temporariamente a linha de origem dentro da transacao.
                // Assim nao precisamos usar a posicao 6, reservada ao Rayquaza.
                $origem = $linhas[$de];
                $destino = $linhas[$para];

                $apagar = $this->pdo->prepare('DELETE FROM time_pokemon WHERE id = :id AND id_time = :time');
                $apagar->execute(['id' => $origem['id'], 'time' => $idTime]);

                $atualizarDestino = $this->pdo->prepare('UPDATE time_pokemon SET posicao = :posicao WHERE id = :id AND id_time = :time');
                $atualizarDestino->execute(['posicao' => $de, 'id' => $destino['id'], 'time' => $idTime]);

                $inserirOrigem = $this->pdo->prepare('
                    INSERT INTO time_pokemon (id_time, id_pokemon, id_pokemon_capturado, posicao)
                    VALUES (:time, :pokemon, :capturado, :posicao)
                ');
                $inserirOrigem->execute([
                    'time' => $idTime,
                    'pokemon' => $origem['id_pokemon'],
                    'capturado' => $origem['id_pokemon_capturado'],
                    'posicao' => $para,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }

    /** Primeira vaga livre entre 1 e 5. A posicao 6 nunca e usada por capturas. */
    public function proximaPosicaoLivre(int $idPerfil): ?int
    {
        $consultaTime = $this->pdo->prepare('SELECT id FROM times WHERE id_perfil = :perfil FOR UPDATE');
        $consultaTime->execute(['perfil' => $idPerfil]);
        $idTime = $consultaTime->fetchColumn();
        if ($idTime === false) {
            throw new DomainException('Time nao encontrado.');
        }

        $consulta = $this->pdo->prepare('SELECT posicao FROM time_pokemon WHERE id_time = :time AND posicao BETWEEN 1 AND 5');
        $consulta->execute(['time' => (int) $idTime]);
        $ocupadas = array_map('intval', $consulta->fetchAll(PDO::FETCH_COLUMN));

        for ($posicao = 1; $posicao <= self::POSICOES; $posicao++) {
            if (!in_array($posicao, $ocupadas, true)) {
                return $posicao;
            }
        }
        return null;
    }

    private function buscarTimeDoPerfil(int $idPerfil, int $idUsuario, bool $bloquear = false): int
    {
        $sql = '
            SELECT tm.id
            FROM times tm
            INNER JOIN perfil p ON p.id = tm.id_perfil
            WHERE tm.id_perfil = :perfil AND p.id_usuario = :usuario
            LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '');
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        $id = $consulta->fetchColumn();
        if ($id === false) {
            throw new DomainException('Time nao encontrado para este perfil.');
        }
        return (int) $id;
    }
}
