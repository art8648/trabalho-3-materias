<?php
declare(strict_types=1);

require_once __DIR__ . '/app/sessao.php';

if (!isset($_SESSION['perfil_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: perfis.php');
    exit;
}

require_once __DIR__ . '/app/autoload.php';

$nome = trim((string) ($_POST['personagem'] ?? ''));
$pdo = require __DIR__ . '/config/database.php';
$controller = new \Peakmon\Controllers\PersonagemController($pdo);
$ok = $controller->selecionar(
    (int) $_SESSION['perfil_id'],
    (int) $_SESSION['usuario_id'],
    $nome
);

header('Location: ' . ($ok ? 'mapa.php' : 'menu%20inicial/selecao.html'));
exit;
