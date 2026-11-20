<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use LogicException;
use PDO;

/**
 * Captura por roleta: o servidor sorteia um Pokemon da lista abaixo, grava o
 * exemplar no perfil e o coloca no time (posicoes 1 a 5) ou, se o time estiver
 * cheio, deixa na reserva (o "PC" / Arquivo Bigolin da Mochila).
 */
class Captura
{
    /** Evita as ultimas cinco capturas quando ainda ha outras especies. */
    private const RECENTES_A_EVITAR = 5;
    /** Chance de priorizar especies ainda nao capturadas pelo perfil. */
    private const CHANCE_INEDITO = 85;

    /** Pokemon que aparecem na roleta (nomes como estao na tabela pokemon). */
    public const NOMES = [
        'Taillow',
        'Wingull',
        'Ralts',
        'Abra',
        'Magikarp',
        'Geodude',
        'Beldum',
        'Machop',
        'Electrike',
        'Bagon',
        'Absol',
        'Heracross',
        'Snorunt',
        'Gulpin',
        'Rhyhorn',
        'Jigglypuff',
        'Shuppet',
    ];

    private PDO $pdo;
    private Time $time;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->time = new Time($pdo);
    }

    /** Dados que o navegador precisa para desenhar a roleta (mesma ordem do sorteio). */
    public function listarRoleta(): array
    {
        $lista = [];

        foreach ($this->carregarEspecies() as $especie) {
            $lista[] = [
                'id' => (int) $especie['id'],
                'nome' => $especie['nome'],
                'imagem' => $especie['imagem']
            ];
        }

        return $lista;
    }

    /**
     * Sorteia e grava o Pokemon capturado.
     * Precisa rodar DENTRO de uma transacao aberta (o Mapa::avancar chama este
     * metodo antes do commit), assim mover no mapa e capturar acontecem juntos.
     */
    public function capturarAleatorio(int $idPerfil): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('A captura deve acontecer dentro de uma transacao.');
        }

        $especies = $this->carregarEspecies();

        if ($especies === []) {
            throw new DomainException('Nenhum Pokemon da roleta foi encontrado no banco de dados.');
        }

        // O sorteio e feito no servidor: evita as cinco especies mais recentes,
        // e normalmente prioriza Pokemon ainda nao descobertos neste perfil.
        $consulta = $this->pdo->prepare('SELECT id_pokemon FROM pokemon_capturado
            WHERE id_perfil = :perfil ORDER BY id DESC');
        $consulta->execute(['perfil' => $idPerfil]);
        $historico = array_map('intval', $consulta->fetchAll(PDO::FETCH_COLUMN));
        $recentes = array_slice($historico, 0, self::RECENTES_A_EVITAR);
        $possiveis = array_values(array_filter($especies, static function (array $p) use ($recentes): bool {
            return !in_array((int) $p['id'], $recentes, true);
        }));
        if ($possiveis === []) {
            $possiveis = $especies;
        }
        $ineditos = array_values(array_filter($possiveis, static function (array $p) use ($historico): bool {
            return !in_array((int) $p['id'], $historico, true);
        }));
        if ($ineditos !== [] && random_int(1, 100) <= self::CHANCE_INEDITO) {
            $possiveis = $ineditos;
        }
        $especie = $possiveis[random_int(0, count($possiveis) - 1)];

        $sexo = ((bool) $especie['possui_sexo'])
            ? (random_int(0, 1) === 1 ? 'M' : 'F')
            : 'SEM_GENERO';

        $inserirCapturado = $this->pdo->prepare('
            INSERT INTO pokemon_capturado
                (id_perfil, id_pokemon, hp_atual, desmaiado, sexo)
            VALUES
                (:id_perfil, :id_pokemon, :hp_atual, FALSE, :sexo)
        ');
        $inserirCapturado->execute([
            'id_perfil' => $idPerfil,
            'id_pokemon' => (int) $especie['id'],
            'hp_atual' => (int) $especie['hp'],
            'sexo' => $sexo
        ]);
        $idCapturado = (int) $this->pdo->lastInsertId();

        // Primeira vaga livre entre 1 e 5; null = time cheio, vai para o PC.
        $posicao = $this->time->proximaPosicaoLivre($idPerfil);

        if ($posicao !== null) {
            $consultaTime = $this->pdo->prepare('
                SELECT id FROM times WHERE id_perfil = :id_perfil LIMIT 1
            ');
            $consultaTime->execute(['id_perfil' => $idPerfil]);
            $idTime = $consultaTime->fetchColumn();

            if ($idTime === false) {
                throw new DomainException('Nao foi encontrado o time deste perfil.');
            }

            $inserirNoTime = $this->pdo->prepare('
                INSERT INTO time_pokemon
                    (id_time, id_pokemon_capturado, posicao)
                VALUES
                    (:id_time, :id_capturado, :posicao)
            ');
            $inserirNoTime->execute([
                'id_time' => (int) $idTime,
                'id_capturado' => $idCapturado,
                'posicao' => $posicao
            ]);
        }

        return [
            'id_pokemon' => (int) $especie['id'],
            'id_capturado' => $idCapturado,
            'nome' => $especie['nome'],
            'imagem' => $especie['imagem'],
            'sexo' => $sexo,
            'destino' => $posicao !== null ? 'time' : 'pc',
            'posicao' => $posicao
        ];
    }

    /** Especies da roleta que existem no banco e podem ser capturadas, na ordem de NOMES. */
    private function carregarEspecies(): array
    {
        $marcadores = implode(',', array_fill(0, count(self::NOMES), '?'));

        $consulta = $this->pdo->prepare("
            SELECT id, nome, hp, possui_sexo, imagem
            FROM pokemon
            WHERE capturavel = TRUE
              AND nome IN ($marcadores)
        ");
        $consulta->execute(self::NOMES);

        $porNome = [];
        foreach ($consulta->fetchAll() as $linha) {
            $porNome[$linha['nome']] = $linha;
        }

        $lista = [];
        foreach (self::NOMES as $nome) {
            if (isset($porNome[$nome])) {
                $lista[] = $porNome[$nome];
            }
        }

        return $lista;
    }
}
