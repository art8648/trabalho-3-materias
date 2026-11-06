<?php
declare(strict_types=1);

$host = '127.0.0.1';
$port = 3306;
$banco = 'peakmon_bigolin_crisis';
$usuario = 'root';
$senha = '';
$dsn = "mysql:host=$host;port=$port;dbname=$banco;charset=utf8mb4";

try{
    $pdo = new PDO($dsn, $usuario, $senha,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    return $pdo;
}
 catch (PDOException $erro){
    error_log($erro->getMessage());

    throw new RuntimeException('Não foi possível conectar ao banco de dados.', 0, $erro);
}