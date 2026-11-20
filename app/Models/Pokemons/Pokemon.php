<?php

declare(strict_types=1);

namespace Peakmon\Models\Pokemons;

/**
 * Objeto de dominio para o Pokemon que entra no combate.
 * As especies continuam armazenadas no MySQL; a subclasse representa o tipo
 * de cada exemplar. Assim, alterar a hierarquia nao altera HP, evolucoes,
 * capturas nem o balanceamento ja cadastrado no banco.
 */
abstract class Pokemon
{
    protected const TIPO = 'NORMAL';

    public function __construct(private readonly int $tipoId)
    {
    }

    public function tipo(): string
    {
        return static::TIPO;
    }

    public function tipoId(): int
    {
        return $this->tipoId;
    }

    /** Associacao entre dois objetos Pokemon; multiplicadores vêm do banco. */
    public function multiplicadorContra(Pokemon $defensor, array $matriz): float
    {
        if ($defensor instanceof PokemonTudo) {
            return 0.5;
        }

        return $matriz[$this->tipoId() . '-' . $defensor->tipoId()] ?? 1.0;
    }
}
