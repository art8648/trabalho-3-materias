<?php
declare(strict_types=1);

try{
    $pdo = require dirname(__DIR__) . '/config/database.php';
    $consulta = $pdo->query('SELECT DATABASE() AS nome_banco');
    $resultado = $consulta->fetch();

    echo '<h1>Peakmon: The Bigolin Crisis</h1>';
    echo '<p>Conexão funcionando!</p>';
    echo '<p>Banco: ' . htmlspecialchars($resultado['nome_banco']). '</p>';
} 
catch (Throwable $erro){
    error_log((string) $erro);
    http_response_code(500);
    echo '<h1>Erro de conexão</h1>';
    echo '<p>' . htmlspecialchars($erro->getMessage() . ' | ' . ($erro->getPrevious()?->getMessage() ?? '')) . '</p>';
    echo '<p>Confira a configuração do MySQL e o banco selecionado.</p>';
}