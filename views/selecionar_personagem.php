<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


require PEAKMON_ROOT . '/app/sessao.php';

if (!isset($_SESSION['perfil_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?pagina=perfis');
    exit;
}

require PEAKMON_ROOT . '/app/autoload.php';

$nome = trim((string) ($_POST['personagem'] ?? ''));
$pdo = require PEAKMON_ROOT . '/config/database.php';
$controller = new \Peakmon\Controllers\PersonagemController($pdo);
$ok = $controller->selecionar(
    (int) $_SESSION['perfil_id'],
    (int) $_SESSION['usuario_id'],
    $nome
);

header('Location: ' . ($ok ? 'index.php?pagina=mapa' : 'menu%20inicial/selecao.html'));
exit;
