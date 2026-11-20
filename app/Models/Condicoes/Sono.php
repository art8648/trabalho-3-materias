<?php

declare(strict_types=1);

namespace Peakmon\Models\Condicoes;

/** Sono: causado por golpes PSIQUICOS; 70% de chance de perder a acao. */
final class Sono extends CondicaoStatus
{
    public function nome(): string
    {
        return 'SONO';
    }

    public function tipoDoGolpe(): string
    {
        return 'PSIQUICO';
    }

    public function chancePerderAcao(): int
    {
        return 70;
    }
}
