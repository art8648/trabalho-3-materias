<?php

declare(strict_types=1);

namespace Peakmon\Models\Condicoes;

use BadMethodCallException;
use InvalidArgumentException;

/**
 * Condicao de status de um Pokemon em batalha (queimadura, veneno, congelamento...).
 *
 * POLIMORFISMO: cada condicao e uma subclasse que redefine o proprio comportamento
 * (nome, golpe que a causa, chance de perder a acao, dano residual). O motor da batalha
 * trabalha so com CondicaoStatus e chama os mesmos metodos em qualquer subclasse,
 * sem precisar de match/if por nome de condicao.
 *
 * SOBRECARGA (PHP nao tem sobrecarga nativa; usa-se __call para despachar pela
 * quantidade e pelo tipo dos argumentos):
 *   danoResidual(int $hpMax)                 -> usa o divisor da propria condicao
 *   danoResidual(array $pokemon)             -> le 'hp_max' do array do lutador
 *   danoResidual(int $hpMax, int $divisor)   -> divisor informado na chamada
 *   perdeAcao()                              -> sorteia 1..100 internamente
 *   perdeAcao(int $sorteio)                  -> usa o sorteio recebido (util em testes)
 *
 * @method int  danoResidual(int|array $alvo, ?int $divisor = null)
 * @method bool perdeAcao(?int $sorteio = null)
 */
abstract class CondicaoStatus
{
    /** Condicoes existentes (a ordem nao importa). */
    private const CLASSES = [
        Queimadura::class,
        Veneno::class,
        Congelamento::class,
        Paralisia::class,
        Sono::class,
    ];

    /** Nome gravado no banco e mostrado na tela (ex.: QUEIMADURA). */
    abstract public function nome(): string;

    /** Tipo do golpe que pode causar esta condicao (ex.: FOGO). */
    abstract public function tipoDoGolpe(): string;

    /** Chance (%) de o Pokemon perder a acao no turno. Padrao: nunca perde. */
    public function chancePerderAcao(): int
    {
        return 0;
    }

    /** Divisor do HP maximo para o dano ao fim da rodada. 0 = sem dano residual. */
    protected function divisorResidual(): int
    {
        return 0;
    }

    public function temDanoResidual(): bool
    {
        return $this->divisorResidual() > 0;
    }

    /** Devolve a condicao pelo nome do banco, ou null (inclusive para NULL ou nome desconhecido). */
    public static function porNome(?string $nome): ?self
    {
        if ($nome === null) {
            return null;
        }
        foreach (self::CLASSES as $classe) {
            $condicao = new $classe();
            if ($condicao->nome() === $nome) {
                return $condicao;
            }
        }
        return null;
    }

    /** Devolve a condicao que um golpe daquele tipo pode causar, ou null. */
    public static function porTipoDoGolpe(string $tipo): ?self
    {
        foreach (self::CLASSES as $classe) {
            $condicao = new $classe();
            if ($condicao->tipoDoGolpe() === $tipo) {
                return $condicao;
            }
        }
        return null;
    }

    /** Sobrecarga de danoResidual() e perdeAcao() (ver o comentario da classe). */
    public function __call(string $metodo, array $argumentos): mixed
    {
        return match ($metodo) {
            'danoResidual' => $this->sobrecargaDanoResidual($argumentos),
            'perdeAcao' => $this->sobrecargaPerdeAcao($argumentos),
            default => throw new BadMethodCallException(
                'Metodo inexistente: ' . static::class . '::' . $metodo . '()'
            ),
        };
    }

    private function sobrecargaDanoResidual(array $argumentos): int
    {
        $divisor = $this->divisorResidual();

        if (count($argumentos) === 2 && is_int($argumentos[0]) && is_int($argumentos[1])) {
            [$hpMax, $divisor] = $argumentos;
        } elseif (count($argumentos) === 1 && is_int($argumentos[0])) {
            $hpMax = $argumentos[0];
        } elseif (count($argumentos) === 1 && is_array($argumentos[0]) && isset($argumentos[0]['hp_max'])) {
            $hpMax = (int) $argumentos[0]['hp_max'];
        } else {
            throw new InvalidArgumentException(
                'danoResidual() aceita (int $hpMax), (array $pokemon) ou (int $hpMax, int $divisor).'
            );
        }

        if ($divisor <= 0) {
            return 0;
        }
        return max(1, (int) ceil($hpMax / $divisor));
    }

    private function sobrecargaPerdeAcao(array $argumentos): bool
    {
        if (count($argumentos) === 1 && is_int($argumentos[0])) {
            $sorteio = $argumentos[0];
        } elseif (count($argumentos) === 0) {
            $sorteio = null;
        } else {
            throw new InvalidArgumentException('perdeAcao() aceita () ou (int $sorteio).');
        }

        $chance = $this->chancePerderAcao();
        if ($chance <= 0) {
            return false;
        }
        return ($sorteio ?? random_int(1, 100)) <= $chance;
    }
}
