<?php
declare(strict_types=1);

require_once __DIR__ . '/app/sessao.php';

if (!isset($_SESSION['perfil_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: perfis.php');
    exit;
}

$nome = trim((string) ($_POST['personagem'] ?? ''));
$pdo = require __DIR__ . '/config/database.php';

$consulta = $pdo->prepare('SELECT id FROM personagem WHERE nome = :nome AND jogavel = TRUE LIMIT 1');
$consulta->execute(['nome' => $nome]);
$idPersonagem = $consulta->fetchColumn();

if ($idPersonagem === false) {
    header('Location: menu%20inicial/selecao.html');
    exit;
}

$atualiza = $pdo->prepare(
    'UPDATE perfil SET id_personagem = :personagem
     WHERE id = :perfil AND id_usuario = :usuario'
);
$atualiza->execute([
    'personagem' => $idPersonagem,
    'perfil'     => $_SESSION['perfil_id'],
    'usuario'    => $_SESSION['usuario_id'],
]);

header('Location: mapa.php');
exit;