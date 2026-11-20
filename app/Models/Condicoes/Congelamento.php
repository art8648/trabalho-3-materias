<?php

declare(strict_types=1);

namespace Peakmon\Models\Condicoes;

/** Congelamento: causado por golpes de GELO; 60% de chance de perder a acao. */
final class Congelamento extends CondicaoStatus
{
    public function nome(): string
    {
        return 'CONGELAMENTO';
    }

    public function tipoDoGolpe(): string
    {
        return 'GELO';
    }

    public function chancePerderAcao(): int
    {
        return 60;
    }
}
