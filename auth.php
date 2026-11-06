<?php
declare(strict_types=1);
use Peakmon\Controllers\AuthController;
require_once __DIR__ . '/app/autoload.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    exit('Método não permitido.');
}
$acao = $_POST['acao'] ?? '';
$nome = trim((string) ($_POST['nome'] ?? ''));
$senha = (string) ($_POST['senha'] ?? '');

try{
    $pdo = require __DIR__ . '/config/database.php';
    $auth = new AuthController($pdo);

    if($acao === 'registrar'){
        $resultado = $auth->registrar($nome, $senha);
    } 
    elseif($acao === 'entrar'){
        $resultado = $auth->entrar($nome, $senha);
    } 
    else{
        http_response_code(400);
        $resultado = ['sucesso' => false,'mensagem' => 'Ação inválida.'];
    }
} 
    catch(Throwable $erro){
    error_log((string) $erro);
    http_response_code(500);
    $resultado = ['sucesso' => false,'mensagem' => 'Ocorreu um erro interno. Tente novamente.'];
}
    if(($resultado['sucesso'] ?? false) && $acao === 'entrar'){
    header('Location: menu%20inicial/menu.html');
    exit;
}
$titulo = ($resultado['sucesso'] ?? false)
    ? 'Operação concluída'
    : 'Não foi possível continuar';

$mensagem = htmlspecialchars(
    $resultado['mensagem'], ENT_QUOTES,'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titulo ?></title>
</head>
<body>
    <h1><?= $titulo ?></h1>
    <p><?= $mensagem ?></p>
    <a href="menu%20inicial/login.html">Voltar ao cadastro ou login</a>
    </body>
</html>
