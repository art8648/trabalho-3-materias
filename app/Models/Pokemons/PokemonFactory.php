<?php

declare(strict_types=1);

namespace Peakmon\Models\Pokemons;

use InvalidArgumentException;

/** Converte os dados reais do lutador (vindos do PDO) na subclasse do tipo. */
final class PokemonFactory
{
    public static function criar(array $lutador): Pokemon
    {
        if (!isset($lutador['tipo'], $lutador['tipo_id'])) {
            throw new InvalidArgumentException('Pokemon sem dados do tipo.');
        }
        $tipo = strtoupper((string) $lutador['tipo']);
        $id = (int) $lutador['tipo_id'];
        return match ($tipo) {
            'NORMAL' => new PokemonNormal($id),
            'FOGO' => new PokemonFogo($id),
            'AGUA' => new PokemonAgua($id),
            'ELETRICO' => new PokemonEletrico($id),
            'GRAMA' => new PokemonGrama($id),
            'GELO' => new PokemonGelo($id),
            'LUTADOR' => new PokemonLutador($id),
            'VENENO' => new PokemonVeneno($id),
            'TERRA' => new PokemonTerra($id),
            'VOADOR' => new PokemonVoador($id),
            'PSIQUICO' => new PokemonPsiquico($id),
            'INSETO' => new PokemonInseto($id),
            'PEDRA' => new PokemonPedra($id),
            'FANTASMA' => new PokemonFantasma($id),
            'DRAGAO' => new PokemonDragao($id),
            'SOMBRIO' => new PokemonSombrio($id),
            'ACO' => new PokemonAco($id),
            'FADA' => new PokemonFada($id),
            'ALIENX' => new PokemonAlienx($id),
            'TUDO' => new PokemonTudo($id),
            default => throw new InvalidArgumentException('Tipo de Pokemon nao reconhecido: ' . $tipo),
        };
    }
}
