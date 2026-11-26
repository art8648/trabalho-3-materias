<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


require PEAKMON_ROOT . '/app/iniciar_sessao.php';

if (!empty($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] > 0) {
    header('Location: index.php?pagina=perfis');
    exit;
}

// Aviso deixado por index.php?pagina=auth (senha errada, nome ja usado...). Aparece uma vez so.
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

require PEAKMON_ROOT . '/menu inicial/login.html';
