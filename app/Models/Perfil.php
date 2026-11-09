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
    public function contarPorUsuario(int $idUsuario): int{
    $sql = '
        SELECT COUNT(*)
        FROM perfil
        WHERE id_usuario = :id_usuario';
    $consulta = $this->pdo->prepare($sql);
    $consulta->execute(['id_usuario' => $idUsuario]);
    return (int) $consulta->fetchColumn();
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

        $this->pdo->beginTransaction();
        try{
            $sqlBloqueio = '
                SELECT id
                FROM usuario
                WHERE id = :id_usuario
                FOR UPDATE';
            $consulta = $this->pdo->prepare($sqlBloqueio);
            $consulta->execute(['id_usuario' => $idUsuario]);
    
            if($consulta->fetchColumn() === false){
                throw new \DomainException('Usuario nao encontrado.');
            }
            $sql = '
                SELECT numero_perfil
                FROM perfil
                WHERE id_usuario = :id_usuario';
    
            $consulta = $this->pdo->prepare($sql);
            $consulta->execute(['id_usuario' => $idUsuario]);
    
            $numerosOcupados = array_map('intval',
            $consulta->fetchAll(PDO::FETCH_COLUMN));
            $numeroPerfil = null;
    
            foreach([1, 2] as $numero){
                if (!in_array($numero, $numerosOcupados, true)){
                    $numeroPerfil = $numero;
                    break;
                }
            }
            if($numeroPerfil === null){
                throw new \DomainException('Voce ja possui o limite de 2 perfis.');
            }
            $sql = '
                INSERT INTO perfil(id_usuario, nome_perfil, id_avatar, numero_perfil)
                VALUES(:id_usuario, :nome_perfil, :id_avatar, :numero_perfil)';
    
            $consulta = $this->pdo->prepare($sql);
            $consulta->execute([
                'id_usuario' => $idUsuario,
                'nome_perfil' => $nomePerfil,
                'id_avatar' => $idAvatar,
                'numero_perfil' => $numeroPerfil]);

            $id = (int) $this->pdo->lastInsertId();
                $this->pdo->commit();
            return $id;
        } 
        catch (\Throwable $erro){
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }
}
