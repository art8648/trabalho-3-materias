<?php

namespace Peakmon\Controllers;
use PDO;
use PDOException;
use DomainException;
use Peakmon\Models\Perfil;
class PerfilController{
    private Perfil $perfil;
    public function __construct(PDO $pdo)
{
        $this->perfil = new Perfil($pdo);
    }
    public function listar(int $idUsuario): array{
        if($idUsuario <= 0){
            return ['sucesso' => false,'mensagem' => 'Usuario invalido.'];
        }
        $perfis = $this->perfil->listarPorUsuario($idUsuario);
        return['sucesso' => true,'perfis' => $perfis];
    }
    public function criar(
        int $idUsuario,
        string $nomePerfil,
        ?int $idAvatar = null): array{

        $nomePerfil = trim($nomePerfil);
        if($idUsuario <= 0 || $nomePerfil === ''){
            return['sucesso' => false,'mensagem' => 'Preencha o nome do perfil.'];
        }
        if(mb_strlen($nomePerfil) > 100){
            return['sucesso' => false,'mensagem' => 'O nome do perfil deve ter ate 100 caracteres.'];
        }
        if($idAvatar !== null && $idAvatar <= 0){
            return['sucesso' => false,'mensagem' => 'Avatar invalido.'];
        }
        if ($this->perfil->contarPorUsuario($idUsuario) >= 2) {
            return ['sucesso' => false,'mensagem' => 'Voce ja possui o limite de 2 perfis.'];
        }
        try{
            $idPerfil = $this->perfil->criar(
                $idUsuario,
                $nomePerfil,
                $idAvatar
            );
            return ['sucesso' => true,'mensagem' => 'Perfil criado com sucesso!','id' => $idPerfil];
} 
    catch(DomainException $erro) {
            return ['sucesso' => false,'mensagem' => $erro->getMessage()];
} 
    catch(PDOException $erro){
            if(($erro->errorInfo[1] ?? null) === 1062){
                if($this->perfil->contarPorUsuario($idUsuario) >= 2){
                    return ['sucesso' => false,'mensagem' => 'Voce ja possui o limite de 2 perfis.'
                    ];
                }
                return ['sucesso' => false,'mensagem' => 'Voce ja possui um perfil com esse nome.'];
            }
            throw $erro;
        }
    }
    public function selecionar(
        int $idPerfil,
        int $idUsuario): array{
        if ($idPerfil <= 0 || $idUsuario <= 0) {
            return ['sucesso' => false,'mensagem' => 'Perfil ou usuario invalido.'];
        }
        $perfil = $this->perfil->buscarPorIdDoUsuario(
            $idPerfil,
            $idUsuario
        );
        if($perfil === null){
            return ['sucesso' => false,'mensagem' => 'Perfil nao encontrado.'];
        }
        if(session_status() === PHP_SESSION_NONE){
            session_start();
        }
        $_SESSION['perfil_id'] = (int) $perfil['id'];
        $_SESSION['perfil_nome'] = $perfil['nome_perfil'];

        return ['sucesso' => true,'mensagem' => 'Perfil selecionado com sucesso!','perfil' => $perfil];
    }
}
