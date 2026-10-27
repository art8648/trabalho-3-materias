<?php

namespace Peakmon\Models;
use PDO;
class Usuario{
    private PDO $pdo;
    public function __construct(PDO $pdo){
        $this->pdo = $pdo;
    }
    public function buscarPorNome(string $nome): ?array{
        $sql = '
            SELECT id, nome, senha_hash
            FROM usuario
            WHERE nome = :nome
            LIMIT 1 ';
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['nome' => $nome]);
        $usuario = $consulta->fetch();
        return $usuario === false ? null : $usuario;
    }
    public function cadastrar(
        string $nome,
        string $senha): int {
        $senhaHash = password_hash(
            $senha,
            PASSWORD_DEFAULT
        );
        $sql = '
            INSERT INTO usuario (nome, senha_hash)
            VALUES (:nome, :senha_hash)';
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'nome' => $nome,
            'senha_hash' => $senhaHash]);
        return (int) $this->pdo->lastInsertId();
    }
}
