<?php

declare(strict_types=1);

namespace Peakmon\Controllers;

use PDO;
use Peakmon\Models\Historico;

/** Consulta do Diario: apenas o perfil da sessao e enviado ao Model. */
final class HistoricoController
{
    private Historico $historico;

    public function __construct(PDO $pdo, ?Historico $historico = null)
    {
        $this->historico = $historico ?? new Historico($pdo);
    }

    public function prepararPagina(int $idPerfil, int $idUsuario): array
    {
        return $this->historico->consultar($idPerfil, $idUsuario);
    }
}
