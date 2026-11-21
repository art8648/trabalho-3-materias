<?php
declare(strict_types=1);

// Ponto de entrada unico: mantem as URLs relativas na raiz do projeto.
define('PEAKMON_ROOT', __DIR__);
$rota = $_GET['pagina'] ?? 'menu';
$paginas = [
    'mapa' => 'views/mapa.php',
    'batalha' => 'views/batalha.php',
    'mochila' => 'views/mochila.php',
    'loja' => 'views/loja.php',
    'historico' => 'views/historico.php',
    'perfis' => 'views/perfis.php',
    'selecionar_personagem' => 'views/selecionar_personagem.php',
    'auth' => 'auth/auth.php',
    'login' => 'auth/login.php',
    'logout' => 'auth/logout.php',
];

if ($rota === 'menu') {
    header('Location: menu%20inicial/menu.html');
    exit;
}
if (!is_string($rota) || !isset($paginas[$rota])) {
    http_response_code(404);
    exit('Pagina nao encontrada.');
}
require PEAKMON_ROOT . '/' . $paginas[$rota];
