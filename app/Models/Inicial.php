<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Inicial
{
    // Os tres Pokemon que o jogador pode escolher no comeco do jogo.
    public const NOMES = ['Treecko', 'Torchic', 'Mudkip'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * O perfil ja tem algum Pokemon? Se tiver, o pop-up nao aparece.
     */
    public function perfilTemInicial(int $idPerfil): bool
    {
        $consulta = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM pokemon_capturado
            WHERE id_perfil = :id_perfil
        ');
        $consulta->execute(['id_perfil' => $idPerfil]);

        return (int) $consulta->fetchColumn() > 0;
    }

    /**
     * Busca os 3 iniciais (com tipo, status e ataque) para montar os cards.
     */
    public function listar(): array
    {
        $marcadores = implode(',', array_fill(0, count(self::NOMES), '?'));

        $consulta = $this->pdo->prepare("
            SELECT
                p.id,
                p.nome,
                p.descricao,
                p.imagem,
                p.hp,
                p.atk,
                p.def,
                p.vel,
                p.nome_ataque,
                t.nome AS tipo
            FROM pokemon p
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE p.nome IN ($marcadores)
            ORDER BY p.api_id ASC
        ");
        $consulta->execute(self::NOMES);

        return $consulta->fetchAll();
    }

    /**
     * Salva o inicial escolhido: cria o Pokemon capturado do perfil
     * e coloca ele na posicao 1 do time.
     */
    public function escolher(int $idPerfil, int $idUsuario, int $idPokemon): array
    {
        if ($idPerfil <= 0 || $idUsuario <= 0 || $idPokemon <= 0) {
            throw new DomainException('Dados invalidos para escolher o inicial.');
        }

        $this->pdo->beginTransaction();

        try {
            // Confere que o perfil e do usuario logado e trava a linha
            // para evitar dois cliques/abas escolhendo ao mesmo tempo.
            $consultaPerfil = $this->pdo->prepare('
                SELECT id
                FROM perfil
                WHERE id = :id_perfil
                  AND id_usuario = :id_usuario
                LIMIT 1
                FOR UPDATE
            ');
            $consultaPerfil->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);

            if ($consultaPerfil->fetchColumn() === false) {
                throw new DomainException('O perfil selecionado nao pertence a este usuario.');
            }

            if ($this->perfilTemInicial($idPerfil)) {
                throw new DomainException('Este perfil ja escolheu o Pokemon inicial.');
            }

            // So aceita um dos 3 iniciais, mesmo que alguem edite o HTML no navegador.
            $marcadores = implode(',', array_fill(0, count(self::NOMES), '?'));
            $consultaEspecie = $this->pdo->prepare("
                SELECT id, nome, hp, possui_sexo
                FROM pokemon
                WHERE id = ?
                  AND nome IN ($marcadores)
                LIMIT 1
            ");
            $consultaEspecie->execute(array_merge([$idPokemon], self::NOMES));
            $especie = $consultaEspecie->fetch();

            if ($especie === false) {
                throw new DomainException('Esse Pokemon nao e um inicial valido.');
            }

            $sexo = ((bool) $especie['possui_sexo'])
                ? (random_int(0, 1) === 1 ? 'M' : 'F')
                : 'SEM_GENERO';

            $inserirCapturado = $this->pdo->prepare('
                INSERT INTO pokemon_capturado
                    (id_perfil, id_pokemon, hp_atual, desmaiado, sexo)
                VALUES
                    (:id_perfil, :id_pokemon, :hp_atual, FALSE, :sexo)
            ');
            $inserirCapturado->execute([
                'id_perfil' => $idPerfil,
                'id_pokemon' => (int) $especie['id'],
                'hp_atual' => (int) $especie['hp'],
                'sexo' => $sexo
            ]);
            $idCapturado = (int) $this->pdo->lastInsertId();

            // O time do perfil e criado automaticamente pelo trigger do banco.
            $consultaTime = $this->pdo->prepare('
                SELECT id
                FROM times
                WHERE id_perfil = :id_perfil
                LIMIT 1
            ');
            $consultaTime->execute(['id_perfil' => $idPerfil]);
            $idTime = $consultaTime->fetchColumn();

            if ($idTime === false) {
                throw new DomainException('Nao foi encontrado o time deste perfil.');
            }

            $inserirNoTime = $this->pdo->prepare('
                INSERT INTO time_pokemon
                    (id_time, id_pokemon_capturado, posicao)
                VALUES
                    (:id_time, :id_capturado, 1)
            ');
            $inserirNoTime->execute([
                'id_time' => (int) $idTime,
                'id_capturado' => $idCapturado
            ]);

            $this->pdo->commit();

            return [
                'sucesso' => true,
                'mensagem' => $especie['nome'] . ' agora faz parte do seu time!',
                'pokemon' => $especie['nome']
            ];

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }
}