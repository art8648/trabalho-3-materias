<?php

declare(strict_types=1);

namespace Peakmon\Models\Condicoes;

/** Veneno: causado por golpes de VENENO; tira 1/16 do HP maximo por rodada. */
final class Veneno extends CondicaoStatus
{
    public function nome(): string
    {
        return 'VENENO';
    }

    public function tipoDoGolpe(): string
    {
        return 'VENENO';
    }

    protected function divisorResidual(): int
    {
        return 16;
    }
}
