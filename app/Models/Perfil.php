<?php

namespace Peakmon\Models;
use PDO;
class Perfil{
    private PDO $pdo;
    public function __construct(PDO $pdo){
        $this->pdo = $pdo;
    }
    public function listarPorUsuario(int $idUsuario): array{
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
            ORDER BY p.data_criacao ASC';
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['id_usuario' => $idUsuario]);
        return $consulta->fetchAll();
    }
    public function buscarPorIdDoUsuario(
        int $idPerfil,
        int $idUsuario): ?array{

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
            LIMIT 1';
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['id_perfil' => $idPerfil,'id_usuario' => $idUsuario]);
        $perfil = $consulta->fetch();
        return $perfil === false ? null : $perfil;
    }
    public function criar(
        int $idUsuario,
        string $nomePerfil,
        ?int $idAvatar = null): int{
        $sql = '
            INSERT INTO perfil(id_usuario, nome_perfil, id_avatar)
            VALUES(:id_usuario, :nome_perfil, :id_avatar)';
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_usuario' => $idUsuario,
            'nome_perfil' => $nomePerfil,
            'id_avatar' => $idAvatar]);
        return (int) $this->pdo->lastInsertId();
    }
}
