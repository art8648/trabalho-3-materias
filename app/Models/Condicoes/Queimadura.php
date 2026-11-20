<?php

declare(strict_types=1);

namespace Peakmon\Models\Condicoes;

/** Queimadura: causada por golpes de FOGO; tira 1/16 do HP maximo por rodada. */
final class Queimadura extends CondicaoStatus
{
    public function nome(): string
    {
        return 'QUEIMADURA';
    }

    public function tipoDoGolpe(): string
    {
        return 'FOGO';
    }

    protected function divisorResidual(): int
    {
        return 16;
    }
}
