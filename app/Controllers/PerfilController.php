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
