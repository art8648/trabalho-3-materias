<?php

declare(strict_types=1);

namespace Peakmon\Controllers;

use PDO;
use Peakmon\Models\Personagem;

/** Recebe os dados da escolha e delega a validacao ao Model. */
final class PersonagemController
{
    private Personagem $personagem;

    public function __construct(PDO $pdo, ?Personagem $personagem = null)
    {
        $this->personagem = $personagem ?? new Personagem($pdo);
    }

    public function selecionar(int $idPerfil, int $idUsuario, string $nome): bool
    {
        return $this->personagem->selecionarJogavel($idPerfil, $idUsuario, $nome);
    }
}
