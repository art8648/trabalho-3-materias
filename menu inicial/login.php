<?php
declare(strict_types=1);

require_once __DIR__ . '/app/iniciar_sessao.php';

if (!empty($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] > 0) {
    header('Location: perfis.php');
    exit;
}

require __DIR__ . '/menu inicial/login.html';
