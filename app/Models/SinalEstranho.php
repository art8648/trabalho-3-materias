<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

/**
 * Evento "Sinal estranho" (ponto EVENTO do mapa).
 *
 * O jogador pode trocar UM dos Pokemon dele (time ou reserva) por quase qualquer
 * outra especie. Rayquaza, Deoxys e Arnaldo ficam de fora: nao podem ser
 * recebidos nem entregues na troca.
 *
 * O banco nao deixa uma especie mudar com UPDATE (so por evolucao), entao a
 * troca apaga o exemplar antigo e cria um novo na MESMA posicao do time.
 */
class SinalEstranho
{
    /** Especies que nao participam da troca. */
    public const PROIBIDOS = ['Rayquaza', 'Deoxys', 'Arnaldo'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Pokemon do jogador (time primeiro, depois reserva) que podem ser trocados. */
    public function listarDoJogador(int $idPerfil, int $idUsuario): array
    {
        $this->validarDonoPerfil($idPerfil, $idUsuario);

        $marcadores = implode(',', array_fill(0, count(self::PROIBIDOS), '?'));

        $consulta = $this->pdo->prepare("
            SELECT
                pc.id AS id_capturado,
                p.nome,
                p.imagem,
                t.nome AS tipo,
                tp.posicao
            FROM pokemon_capturado pc
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            LEFT JOIN (
                SELECT tp2.id_pokemon_capturado, tp2.posicao
                FROM time_pokemon tp2
                INNER JOIN times tm ON tm.id = tp2.id_time
                WHERE tm.id_perfil = ?
            ) tp ON tp.id_pokemon_capturado = pc.id
            WHERE pc.id_perfil = ?
              AND p.nome NOT IN ($marcadores)
            ORDER BY (tp.posicao IS NULL), tp.posicao, p.nome
        ");
        $consulta->execute(array_merge([$idPerfil, $idPerfil], self::PROIBIDOS));

        return $consulta->fetchAll();
    }

    /** Especies que o jogador pode receber (todas, menos as proibidas). */
    public function listarEspecies(): array
    {
        $marcadores = implode(',', array_fill(0, count(self::PROIBIDOS), '?'));

        $consulta = $this->pdo->prepare("
            SELECT p.id, p.nome, p.imagem, t.nome AS tipo
            FROM pokemon p
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE p.capturavel = TRUE
              AND p.nome NOT IN ($marcadores)
            ORDER BY p.nome
        ");
        $consulta->execute(self::PROIBIDOS);

        return $consulta->fetchAll();
    }

    /**
     * Troca o exemplar $idCapturado pela especie $idNovaEspecie.
     * O novo Pokemon nasce com HP cheio e ocupa a mesma posicao do antigo.
     */
    public function trocar(int $idPerfil, int $idUsuario, int $idCapturado, int $idNovaEspecie): array
    {
        $this->pdo->beginTransaction();

        try {
            $this->validarDonoPerfil($idPerfil, $idUsuario);

            $consultaAntigo = $this->pdo->prepare('
                SELECT pc.id, pc.id_pokemon, pc.sexo, p.nome
                FROM pokemon_capturado pc
                INNER JOIN pokemon p ON p.id = pc.id_pokemon
                WHERE pc.id = :id AND pc.id_perfil = :perfil
                FOR UPDATE
            ');
            $consultaAntigo->execute(['id' => $idCapturado, 'perfil' => $idPerfil]);
            $antigo = $consultaAntigo->fetch();

            if ($antigo === false) {
                throw new DomainException('Pokemon nao encontrado neste perfil.');
            }

            if (in_array($antigo['nome'], self::PROIBIDOS, true)) {
                throw new DomainException($antigo['nome'] . ' nao pode ser trocado.');
            }

            $consultaNova = $this->pdo->prepare('
                SELECT id, nome, hp, possui_sexo, imagem, capturavel
                FROM pokemon
                WHERE id = :id
            ');
            $consultaNova->execute(['id' => $idNovaEspecie]);
            $nova = $consultaNova->fetch();

            if ($nova === false
                || !(bool) $nova['capturavel']
                || in_array($nova['nome'], self::PROIBIDOS, true)) {
                throw new DomainException('Essa especie nao esta disponivel para troca.');
            }

            if ((int) $nova['id'] === (int) $antigo['id_pokemon']) {
                throw new DomainException('Escolha uma especie diferente da atual.');
            }

            // Posicao que o antigo ocupa no time (nenhuma = esta na reserva).
            $consultaPosicao = $this->pdo->prepare('
                SELECT id_time, posicao
                FROM time_pokemon
                WHERE id_pokemon_capturado = :id
                LIMIT 1
            ');
            $consultaPosicao->execute(['id' => $idCapturado]);
            $lugar = $consultaPosicao->fetch();

            // Apaga o antigo (a linha do time some junto, por cascata).
            $apagar = $this->pdo->prepare('
                DELETE FROM pokemon_capturado
                WHERE id = :id AND id_perfil = :perfil
            ');
            $apagar->execute(['id' => $idCapturado, 'perfil' => $idPerfil]);

            // Mantem o sexo quando a nova especie tambem tem sexo.
            if ((bool) $nova['possui_sexo']) {
                $sexo = in_array($antigo['sexo'], ['M', 'F'], true)
                    ? $antigo['sexo']
                    : (random_int(0, 1) === 1 ? 'M' : 'F');
            } else {
                $sexo = 'SEM_GENERO';
            }

            $criar = $this->pdo->prepare('
                INSERT INTO pokemon_capturado
                    (id_perfil, id_pokemon, hp_atual, desmaiado, sexo)
                VALUES
                    (:perfil, :pokemon, :hp, FALSE, :sexo)
            ');
            $criar->execute([
                'perfil' => $idPerfil,
                'pokemon' => (int) $nova['id'],
                'hp' => (int) $nova['hp'],
                'sexo' => $sexo
            ]);
            $idNovoCapturado = (int) $this->pdo->lastInsertId();

            if ($lugar !== false) {
                $colocar = $this->pdo->prepare('
                    INSERT INTO time_pokemon (id_time, id_pokemon_capturado, posicao)
                    VALUES (:time, :capturado, :posicao)
                ');
                $colocar->execute([
                    'time' => (int) $lugar['id_time'],
                    'capturado' => $idNovoCapturado,
                    'posicao' => (int) $lugar['posicao']
                ]);
            }

            (new Historico($this->pdo))->registrar($idPerfil, 'SINAL_ESTRANHO',
                $antigo['nome'] . ' foi trocado por ' . $nova['nome'] .
                ' no Sinal Estranho.');
            $this->pdo->commit();

            return [
                'antigo' => $antigo['nome'],
                'novo' => $nova['nome'],
                'imagem' => $nova['imagem'],
                'destino' => $lugar !== false ? 'time' : 'pc',
                'posicao' => $lugar !== false ? (int) $lugar['posicao'] : null
            ];

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }

    private function validarDonoPerfil(int $idPerfil, int $idUsuario): void
    {
        $consulta = $this->pdo->prepare('
            SELECT 1 FROM perfil WHERE id = :perfil AND id_usuario = :usuario LIMIT 1
        ');
        $consulta->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);

        if ($consulta->fetchColumn() === false) {
            throw new DomainException('Perfil nao encontrado.');
        }
    }
}
