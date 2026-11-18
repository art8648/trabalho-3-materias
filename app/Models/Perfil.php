<?php

namespace Peakmon\Models;

use DomainException;
use PDO;

class Perfil
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listarPorUsuario(int $idUsuario): array
    {
        $sql = '
            SELECT
                p.id,
                p.nome_perfil,
                p.id_avatar,
                p.id_personagem,
                p.dinheiro,
                p.jogo_concluido,
                p.data_criacao,
                av.nome AS avatar_nome,
                av.imagem AS avatar_imagem,
                c.nome AS personagem_nome
            FROM perfil p
            LEFT JOIN avatar_opcao av
                ON av.id = p.id_avatar
            LEFT JOIN personagem c
                ON c.id = p.id_personagem
            WHERE p.id_usuario = :id_usuario
            ORDER BY p.numero_perfil ASC
        ';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_usuario' => $idUsuario
        ]);

        return $consulta->fetchAll();
    }

    public function contarPorUsuario(int $idUsuario): int
    {
        $sql = '
            SELECT COUNT(*)
            FROM perfil
            WHERE id_usuario = :id_usuario
        ';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_usuario' => $idUsuario
        ]);

        return (int) $consulta->fetchColumn();
    }

    public function buscarPorIdDoUsuario(
        int $idPerfil,
        int $idUsuario
    ): ?array {
        $sql = '
            SELECT
                p.id,
                p.nome_perfil,
                p.id_avatar,
                p.id_personagem,
                p.dinheiro,
                p.jogo_concluido,
                p.data_criacao,
                av.nome AS avatar_nome,
                av.imagem AS avatar_imagem,
                c.nome AS personagem_nome
            FROM perfil p
            LEFT JOIN avatar_opcao av
                ON av.id = p.id_avatar
            LEFT JOIN personagem c
                ON c.id = p.id_personagem
            WHERE p.id = :id_perfil
              AND p.id_usuario = :id_usuario
            LIMIT 1
        ';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_perfil' => $idPerfil,
            'id_usuario' => $idUsuario
        ]);

        $perfil = $consulta->fetch();

        return $perfil === false ? null : $perfil;
    }

    /**
     * Reinicia UM perfil: apaga os Pokemon capturados (o time esvazia por
     * cascata), limpa o personagem e volta a posicao no mapa para o inicio.
     */
    public function reiniciarEscolhas(int $idPerfil, int $idUsuario): void
    {
        $this->pdo->beginTransaction();

        try {
            // Confere que o perfil e deste usuario.
            $consulta = $this->pdo->prepare('
                SELECT id FROM perfil
                WHERE id = :id_perfil AND id_usuario = :id_usuario
                LIMIT 1
                FOR UPDATE
            ');
            $consulta->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);

            if ($consulta->fetchColumn() === false) {
                throw new DomainException('Perfil nao encontrado.');
            }

            $apagar = $this->pdo->prepare('DELETE FROM pokemon_capturado WHERE id_perfil = :id_perfil');
            $apagar->execute(['id_perfil' => $idPerfil]);

            $limpar = $this->pdo->prepare('
                UPDATE perfil SET id_personagem = NULL
                WHERE id = :id_perfil AND id_usuario = :id_usuario
            ');
            $limpar->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);

            // Volta a posicao no mapa para o ponto INICIO da primeira area
            // (o mapa usa uma unica area). So mexe no progresso deste perfil.
            $voltarAoInicio = $this->pdo->prepare("
                UPDATE progresso_area pa
                INNER JOIN area_no n
                    ON n.id_area = pa.id_area
                   AND n.tipo_no = 'INICIO'
                SET pa.id_no_atual = n.id,
                    pa.estado = 'DISPONIVEL'
                WHERE pa.id_perfil = :id_perfil
                  AND pa.id_area = (
                      SELECT id FROM area_jogo ORDER BY ordem ASC LIMIT 1
                  )
            ");
            $voltarAoInicio->execute(['id_perfil' => $idPerfil]);

            $this->pdo->commit();

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }
}