<?php
declare(strict_types=1);

require_once __DIR__ . '/app/iniciar_sessao.php';

if (!empty($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] > 0) {
    header('Location: perfis.php');
    exit;
}

// Aviso deixado por auth.php (senha errada, nome ja usado...). Aparece uma vez so.
$aviso = $_SESSION['aviso_login'] ?? null;
unset($_SESSION['aviso_login']);

$avisoLogin = null;
$avisoCadastro = null;
$nomeDigitado = '';
if (is_array($aviso)) {
    $nomeDigitado = (string) ($aviso['nome'] ?? '');
    if (($aviso['acao'] ?? '') === 'registrar') {
        $avisoCadastro = (string) ($aviso['mensagem'] ?? '');
    } else {
        $avisoLogin = (string) ($aviso['mensagem'] ?? '');
    }
}

require __DIR__ . '/menu inicial/login.html';
