<?php

declare(strict_types=1);

namespace Peakmon\Models\Condicoes;

/** Paralisia: causada por golpes ELETRICOS; 25% de chance de perder a acao. */
final class Paralisia extends CondicaoStatus
{
    public function nome(): string
    {
        return 'PARALISIA';
    }

    public function tipoDoGolpe(): string
    {
        return 'ELETRICO';
    }

    public function chancePerderAcao(): int
    {
        return 25;
    }
}
