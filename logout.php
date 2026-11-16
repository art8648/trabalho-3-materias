<?php
declare(strict_types=1);

require_once __DIR__ . '/app/iniciar_sessao.php';

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

header('Location: menu inicial/menu.html');
exit;
