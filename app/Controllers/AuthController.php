<?php
namespace Peakmon\Controllers;
use PDO;
use PDOException;
use Peakmon\Models\Usuario;

class AuthController{
    private Usuario $usuario;
    public function __construct(PDO $pdo){
        $this->usuario = new Usuario($pdo);
    }
    public function registrar(
        string $nome,
        string $senha): array{
        $nome = trim($nome);

        if ($nome === '' || $senha === ''){
            return[ 'sucesso' => false, 'mensagem' => 'Preencha todos os campos.'];
        }
        if (mb_strlen($nome) > 60){
            return['sucesso' => false, 'mensagem' => 'O nome deve ter até 60 caracteres.'];
    }
        if (strlen($senha) < 8){
            return['sucesso' => false, 'mensagem' => 'A senha deve ter pelo menos 8 caracteres.'];
    }
        if ($this->usuario->buscarPorNome($nome) !== null){
            return['sucesso' => false, 'mensagem' => 'Esse nome já está cadastrado.'];
    }
        try{
           $id = $this->usuario->cadastrar($nome, $senha);
       
        session_regenerate_id(true);
        // Um perfil selecionado em uma sessão anterior nunca deve ser herdado.
        unset($_SESSION['perfil_id'], $_SESSION['perfil_nome']);
        $_SESSION['usuario_id'] = $id;
        $_SESSION['usuario_nome'] = $nome;
            return['sucesso' => true,'mensagem' => 'Cadastro realizado com sucesso!','id' => $id];
} 
        catch (PDOException $erro){
            if ($erro->getCode() === '23000'){
            return['sucesso' => false, 'mensagem' => 'Esse nome já está cadastrado.'];
}
        throw $erro;
        }
    }
    public function entrar(
        string $nome,
        string $senha): array{
        $nome = trim($nome);

        if ($nome === '' || $senha === ''){
            return['sucesso' => false, 'mensagem' => 'Preencha o nome e a senha.'];
        }
        $usuario = $this->usuario->buscarPorNome($nome);

        if($usuario === null || !password_verify($senha, $usuario['senha_hash'])){
            return['sucesso' => false, 'mensagem' => 'Nome ou senha incorretos.'];
        }
        if (session_status() === PHP_SESSION_NONE){
            session_start();
        }
        session_regenerate_id(true);
        // Ao trocar de conta, obriga a escolher novamente um dos saves do usuário.
        unset($_SESSION['perfil_id'], $_SESSION['perfil_nome']);
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];

        return['sucesso' => true, 'mensagem' => 'Login realizado com sucesso!'];
    }
}
