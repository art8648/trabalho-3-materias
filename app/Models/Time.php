<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Time
{
    // Quantas posicoes o painel TIME mostra.
    public const POSICOES = 5;

    // O banco aceita posicoes 1 a 6. A 6 so e usada por um instante durante uma troca.
    private const POSICAO_TEMPORARIA = 6;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Devolve as posicoes do time do perfil: [1 => dados ou null, 2 => ..., 5 => ...].
     * null significa posicao vazia.
     */
    public function listar(int $idPerfil): array
    {
        $maximo = self::POSICOES;

        $consulta = $this->pdo->prepare("
            SELECT
                tp.posicao,
                pc.id AS id_capturado,
                pc.hp_atual,
                pc.desmaiado,
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
              AND tp.posicao BETWEEN 1 AND {$maximo}
            ORDER BY tp.posicao ASC
        ");
        $consulta->execute(['id_perfil' => $idPerfil]);

        $posicoes = array_fill(1, self::POSICOES, null);

        foreach ($consulta->fetchAll() as $linha) {
            $posicoes[(int) $linha['posicao']] = $linha;
        }

        return $posicoes;
    }

    /**
     * Move o Pokemon da posicao $de para a posicao $para.
     * Se a posicao de destino estiver vazia, o Pokemon so muda de lugar.
     * Se estiver ocupada, os dois Pokemon trocam de posicao.
     */
    public function mover(int $idPerfil, int $idUsuario, int $de, int $para): void
    {
        if ($de < 1 || $de > self::POSICOES || $para < 1 || $para > self::POSICOES) {
            throw new DomainException('Posicao invalida.');
        }

        if ($de === $para) {
            throw new DomainException('Escolha duas posicoes diferentes.');
        }

        $this->pdo->beginTransaction();

        try {
            // So mexe no time do perfil que esta na sessao.
            $consultaTime = $this->pdo->prepare('
                SELECT tm.id
                FROM times tm
                INNER JOIN perfil p ON p.id = tm.id_perfil
                WHERE tm.id_perfil = :id_perfil
                  AND p.id_usuario = :id_usuario
                LIMIT 1
            ');
            $consultaTime->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);
            $idTime = $consultaTime->fetchColumn();

            if ($idTime === false) {
                throw new DomainException('Time nao encontrado para este perfil.');
            }

            // Trava as linhas envolvidas para dois cliques nao se misturarem.
            $consultaPosicoes = $this->pdo->prepare('
                SELECT id, posicao
                FROM time_pokemon
                WHERE id_time = :id_time
                  AND posicao IN (:de, :para, :temporaria)
                FOR UPDATE
            ');
            $consultaPosicoes->execute([
                'id_time' => (int) $idTime,
                'de' => $de,
                'para' => $para,
                'temporaria' => self::POSICAO_TEMPORARIA
            ]);

            $ocupadas = [];
            foreach ($consultaPosicoes->fetchAll() as $linha) {
                $ocupadas[(int) $linha['posicao']] = (int) $linha['id'];
            }

            if (!isset($ocupadas[$de])) {
                throw new DomainException('Nao ha Pokemon na posicao escolhida.');
            }

            $atualizar = $this->pdo->prepare('
                UPDATE time_pokemon
                SET posicao = :posicao
                WHERE id = :id
                  AND id_time = :id_time
            ');

            $definir = function (int $idLinha, int $posicao) use ($atualizar, $idTime): void {
                $atualizar->execute([
                    'posicao' => $posicao,
                    'id' => $idLinha,
                    'id_time' => (int) $idTime
                ]);
            };

            if (!isset($ocupadas[$para])) {
                // Destino vazio: so muda de lugar.
                $definir($ocupadas[$de], $para);
            } else {
                // Troca entre dois Pokemon. O banco nao aceita duas linhas na mesma
                // posicao nem por um instante, entao um deles passa pela posicao 6.
                if (isset($ocupadas[self::POSICAO_TEMPORARIA])) {
                    throw new DomainException('Nao foi possivel trocar: a posicao 6 do time esta ocupada.');
                }

                $definir($ocupadas[$de], self::POSICAO_TEMPORARIA);
                $definir($ocupadas[$para], $de);
                $definir($ocupadas[$de], $para);
            }

            $this->pdo->commit();

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }
}