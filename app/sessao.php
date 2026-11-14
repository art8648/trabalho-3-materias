<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    $pdo = require __DIR__ . '/../config/database.php';
    $consulta = $pdo->prepare('SELECT id, nome FROM usuario WHERE nome = :nome LIMIT 1');
    $consulta->execute(['nome' => 'jogador_local']);
    $usuario = $consulta->fetch();

    if ($usuario === false) {
        http_response_code(500);
        exit('Usuario local nao encontrado. Rode o INSERT do jogador_local no banco.');
    }

    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
}