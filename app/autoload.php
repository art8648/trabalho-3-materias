<?php
spl_autoload_register(function (string $classe): 
void{
    $prefixo = 'Peakmon\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0){
        return;
    }
    $nomeArquivo = substr($classe, strlen($prefixo));
    $caminho = __DIR__ . '/' . str_replace('\\', '/', $nomeArquivo) . '.php';

    if (is_file($caminho)){
        require $caminho;
    }
});