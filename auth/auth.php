<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


use Peakmon\Controllers\AuthController;

require PEAKMON_ROOT . '/app/iniciar_sessao.php';
require PEAKMON_ROOT . '/app/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$acao = (string) ($_POST['acao'] ?? '');
$nome = trim((string) ($_POST['nome'] ?? ''));
$senhaFormulario = (string) ($_POST['senha'] ?? '');

try {
    $pdo = require PEAKMON_ROOT . '/config/database.php';
    $auth = new AuthController($pdo);

    if ($acao === 'registrar') {
        $resultado = $auth->registrar($nome, $senhaFormulario);
    } elseif ($acao === 'entrar') {
        $resultado = $auth->entrar($nome, $senhaFormulario);
    } else {
        http_response_code(400);
        $resultado = [
            'sucesso' => false,
            'mensagem' => 'Ação inválida.'
        ];
    }

} catch (Throwable $erro) {
    error_log((string) $erro);
    http_response_code(500);

    $resultado = [
        'sucesso' => false,
        'mensagem' => 'Ocorreu um erro interno. Tente novamente.'
    ];
}

if (($resultado['sucesso'] ?? false) === true
    && in_array($acao, ['registrar', 'entrar'], true)) {

    header('Location: index.php?pagina=perfis');
    exit;
}

// Falhou: nao mostra uma pagina separada. Guarda a mensagem na sessao e volta
// para a tela de login, que exibe o aviso em cima do formulario correto.
$_SESSION['aviso_login'] = [
    'acao' => $acao === 'registrar' ? 'registrar' : 'entrar',
    'mensagem' => (string) ($resultado['mensagem'] ?? 'Erro desconhecido.'),
    'nome' => $nome,
];

header('Location: index.php?pagina=login');
exit;