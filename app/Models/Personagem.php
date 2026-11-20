<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

/** Regra de escolha de personagem; nao confundir personagem com Pokemon. */
final class Personagem
{
    public function __construct(private readonly PDO $pdo) {}

    public function selecionarJogavel(int $idPerfil, int $idUsuario, string $nome): bool
    {
        $nome = trim($nome);
        if ($idPerfil <= 0 || $idUsuario <= 0 || $nome === '') {
            throw new DomainException('Selecao de personagem invalida.');
        }
        $consulta = $this->pdo->prepare('SELECT id FROM personagem WHERE nome = :nome AND jogavel = TRUE LIMIT 1');
        $consulta->execute(['nome' => $nome]);
        $idPersonagem = $consulta->fetchColumn();
        if ($idPersonagem === false) {
            return false;
        }
        $atualiza = $this->pdo->prepare('UPDATE perfil SET id_personagem = :personagem
            WHERE id = :perfil AND id_usuario = :usuario');
        $atualiza->execute(['personagem' => (int) $idPersonagem, 'perfil' => $idPerfil, 'usuario' => $idUsuario]);
        // Um UPDATE com o mesmo personagem pode afetar zero linhas: verifica o dono em vez de usar rowCount.
        $dono = $this->pdo->prepare('SELECT id FROM perfil WHERE id = :perfil AND id_usuario = :usuario LIMIT 1');
        $dono->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        if ($dono->fetchColumn() === false) {
            throw new DomainException('Perfil nao encontrado para este usuario.');
        }
        return true;
    }
}
