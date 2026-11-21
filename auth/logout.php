<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


require_once PEAKMON_ROOT . '/app/iniciar_sessao.php';

// Apaga os dados da sessão no servidor.
$_SESSION = [];

// Invalida também o cookie de sessão no navegador.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'] ?? '',
        (bool) $params['secure'],
        (bool) $params['httponly']
    );
}

session_destroy();

header('Location: menu%20inicial/menu.html');
exit;
