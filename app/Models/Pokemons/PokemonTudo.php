<?php

declare(strict_types=1);

namespace Peakmon\Models\Pokemons;

/** Regra especial do tipo TUDO, mantida da batalha original. */
final class PokemonTudo extends Pokemon
{
    protected const TIPO = 'TUDO';

    public function multiplicadorContra(Pokemon $defensor, array $matriz): float
    {
        return 2.0;
    }
}
