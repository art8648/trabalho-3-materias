<?php
declare(strict_types=1);

require_once __DIR__ . '/iniciar_sessao.php';

// Não existe login automático: páginas protegidas exigem usuário autenticado.
if (empty($_SESSION['usuario_id']) || (int) $_SESSION['usuario_id'] <= 0) {
    unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['perfil_id'], $_SESSION['perfil_nome']);

    $ehRequisicaoAjax = (
        stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    );

    if ($ehRequisicaoAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Sua sessão expirou. Entre novamente para continuar.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: login.php');
    exit;
}
