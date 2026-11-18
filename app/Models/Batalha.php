<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

/**
 * Batalha automatica estilo Pokelike.
 *
 * Fluxo:
 *  1. O jogador chega a um no BATALHA/CHEFE do mapa (Mapa::avancar).
 *  2. batalha.php chama prepararEncontro(): mostra o time e o adversario.
 *  3. Ao clicar em LUTAR, lutar() simula a batalha INTEIRA no servidor,
 *     grava tudo (batalha, log_batalha, HP dos Pokemon) e devolve a lista de
 *     eventos. O navegador so anima; ele nunca decide o resultado.
 *  4. Uma batalha com resultado VITORIA naquele no e o que libera os proximos
 *     caminhos no Mapa. O ouro (e as Pedras do chefe) sao pagos pelos
 *     triggers do banco, ao mudar a batalha de EM_ANDAMENTO para VITORIA.
 */
class Batalha
{
    /** Tipos de no que disparam uma batalha. */
    private const NOS_DE_BATALHA = ['BATALHA', 'CHEFE'];

    /** Codigo da DomainException "nao ha nada para lutar aqui": a pagina so volta ao mapa. */
    public const SEM_BATALHA = 1;

    /** Ao perder, o time e curado (Centro Pokemon) e o jogador pode tentar de novo. */
    public const CURAR_TIME_AO_PERDER = true;

    /** Chance (%) de golpe critico. */
    private const CHANCE_CRITICO = 6;

    /** Trava de seguranca contra batalhas infinitas. */
    private const MAX_RODADAS = 400;

    /** Recompensa em ouro (Pokedollars) por tipo de batalha: [minimo, maximo]. */
    private const OURO = [
        'SELVAGEM' => [150, 250],
        'TREINADOR' => [350, 550],
        'CHEFE' => [1200, 1500],
    ];

    /** Nome exibido para Pokemon selvagens (tambem usado para escolher a frase de entrada). */
    private const NOME_SELVAGEM = 'Pokémon selvagem';

    private const NOMES_TREINADORES = [
        'Tiago', 'Bia', 'Caio', 'Duda', 'Rafa', 'Lia', 'Nico', 'Gabi', 'Davi', 'Mari',
    ];

    private const COLUNAS_ESPECIE = '
        p.id AS id_pokemon, p.nome, p.imagem, p.hp AS hp_max,
        p.atk, p.def, p.sp_atk, p.sp_def, p.vel, p.prioridade,
        p.tipo_id, t.nome AS tipo,
        p.nome_ataque, p.categoria_ataque, p.poder_ataque';

    private PDO $pdo;

    /** @var array<string,float>|null */
    private ?array $efetividade = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ------------------------------------------------------------------
    // LEITURA (usada pela pagina antes da luta)
    // ------------------------------------------------------------------

    /**
     * Dados da tela pre-batalha. Nao grava nada no banco.
     * Lanca DomainException se o jogador nao esta num no de batalha pendente.
     */
    public function prepararEncontro(int $idPerfil, int $idUsuario): array
    {
        $ctx = $this->carregarContexto($idPerfil, $idUsuario, false);
        $this->validarNoDeBatalha($ctx);

        $equipe = $this->carregarEquipe($idPerfil);
        $encontro = $this->obterEncontro($idPerfil, $ctx, $equipe);

        $vivos = count(array_filter($equipe, static fn (array $l): bool => $l['hp'] > 0));

        return [
            'no' => [
                'id' => $ctx['id_no'],
                'codigo' => $ctx['codigo'],
                'titulo' => $ctx['titulo'],
                'tipo_no' => $ctx['tipo_no'],
            ],
            'categoria' => $encontro['categoria'],
            'adversario' => $encontro['adversario'],
            'equipe' => $equipe,
            'inimigos' => $encontro['inimigos'],
            'pode_lutar' => $vivos > 0,
            'aviso' => $vivos > 0
                ? null
                : 'Todos os seus Pokemon estao desmaiados. Ao clicar em LUTAR eles serao levados ao Centro Pokemon.',
        ];
    }

    // ------------------------------------------------------------------
    // LUTA
    // ------------------------------------------------------------------

    /**
     * Simula e grava a batalha do no atual. Tudo numa transacao:
     * ou a batalha inteira e salva, ou nada muda.
     */
    public function lutar(int $idPerfil, int $idUsuario): array
    {
        $this->pdo->beginTransaction();

        try {
            // Trava o progresso do perfil: impede dois cliques/abas lutando ao mesmo tempo.
            $ctx = $this->carregarContexto($idPerfil, $idUsuario, true);
            $this->validarNoDeBatalha($ctx);

            $equipe = $this->carregarEquipe($idPerfil);
            $lutadores = array_values(array_filter(
                $equipe,
                static fn (array $l): bool => $l['hp'] > 0 && !$l['desmaiado']
            ));

            if ($lutadores === []) {
                // Time inteiro desmaiado: leva ao Centro Pokemon e pede para tentar de novo.
                $this->curarTime($idPerfil);
                $this->pdo->commit();
                throw new DomainException(
                    'Seu time estava desmaiado e foi curado no Centro Pokemon. Tente novamente.'
                );
            }

            $encontro = $this->obterEncontro($idPerfil, $ctx, $equipe);
            [$idAdversario, $idTimeAdversario] = $this->garantirAdversario($encontro['adversario']);
            $this->sincronizarTimeNpc($idTimeAdversario, $encontro['inimigos']);

            $idTimeJogador = $this->buscarTimeDoJogador($idPerfil);

            $inserirBatalha = $this->pdo->prepare('
                INSERT INTO batalha
                    (id_perfil, id_time_jogador, id_time_adversario,
                     id_personagem_jogador, id_personagem_adversario,
                     id_area, id_no, tipo_batalha, resultado)
                VALUES
                    (:perfil, :time_jogador, :time_adversario,
                     :personagem_jogador, :personagem_adversario,
                     :area, :no, :tipo, \'EM_ANDAMENTO\')
            ');
            $inserirBatalha->execute([
                'perfil' => $idPerfil,
                'time_jogador' => $idTimeJogador,
                'time_adversario' => $idTimeAdversario,
                'personagem_jogador' => $ctx['id_personagem_perfil'],
                'personagem_adversario' => $idAdversario,
                'area' => $ctx['id_area'],
                'no' => $ctx['id_no'],
                'tipo' => $ctx['tipo_no'] === 'CHEFE' ? 'CHEFE' : 'NORMAL',
            ]);
            $idBatalha = (int) $this->pdo->lastInsertId();

            // ---- A batalha acontece aqui ----
            $sim = $this->simular($lutadores, $encontro['inimigos'], $encontro['fator'], $encontro['adversario']['nome']);

            $this->gravarLog($idBatalha, $sim['eventos'], $lutadores, $encontro['inimigos']);
            $this->gravarHpDoTime($idPerfil, $sim['jogador_final']);

            $venceu = $sim['resultado'] === 'VITORIA';
            $ouro = 0;

            if ($venceu) {
                [$minimo, $maximo] = self::OURO[$encontro['categoria']];
                $ouro = random_int($minimo, $maximo);
            }

            // Os triggers pagam o ouro e, no chefe, as Pedras Universais.
            $encerrar = $this->pdo->prepare('
                UPDATE batalha
                SET resultado = :resultado, dinheiro_ganho = :ouro
                WHERE id = :id AND resultado = \'EM_ANDAMENTO\'
            ');
            $encerrar->execute([
                'resultado' => $sim['resultado'],
                'ouro' => $ouro,
                'id' => $idBatalha,
            ]);

            $timeCurado = false;
            if (!$venceu && self::CURAR_TIME_AO_PERDER) {
                $this->curarTime($idPerfil);
                $timeCurado = true;
            }

            $areaConcluida = false;
            $pedras = 0;
            if ($venceu && $ctx['tipo_no'] === 'CHEFE') {
                $consulta = $this->pdo->prepare('
                    SELECT aj.quantidade_recompensa
                    FROM area_concluida ac
                    INNER JOIN area_jogo aj ON aj.id = ac.id_area
                    WHERE ac.id_batalha = :id
                    LIMIT 1
                ');
                $consulta->execute(['id' => $idBatalha]);
                $quantidade = $consulta->fetchColumn();
                if ($quantidade !== false) {
                    $areaConcluida = true;
                    $pedras = (int) $quantidade;
                }
            }

            $saldo = $this->pdo->prepare('SELECT dinheiro FROM perfil WHERE id = :id');
            $saldo->execute(['id' => $idPerfil]);
            $dinheiroTotal = (int) $saldo->fetchColumn();

            $this->pdo->commit();

            // Depois da luta o encontro some: vitoria libera o mapa, derrota sorteia outro.
            $this->descartarEncontro($idPerfil, $ctx['id_no']);

            return [
                'sucesso' => true,
                'resultado' => $sim['resultado'],
                'eventos' => $sim['eventos'],
                'jogador' => $lutadores,
                'inimigo' => $encontro['inimigos'],
                'adversario' => $encontro['adversario'],
                'dinheiro_ganho' => $ouro,
                'dinheiro_total' => $dinheiroTotal,
                'area_concluida' => $areaConcluida,
                'pedras' => $pedras,
                'time_curado' => $timeCurado,
            ];
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Mensagens de validacao dos triggers viram avisos legiveis.
            if ($erro instanceof \PDOException
                && (($erro->errorInfo[0] ?? '') === '45000' || (string) $erro->getCode() === '45000')) {
                throw new DomainException($erro->errorInfo[2] ?? 'Nao foi possivel concluir a batalha.', 0, $erro);
            }

            throw $erro;
        }
    }

    // ------------------------------------------------------------------
    // SIMULACAO (nao toca no banco)
    // ------------------------------------------------------------------

    /**
     * Um Pokemon de cada lado por vez. Quem tem maior prioridade/velocidade
     * ataca primeiro; quem desmaia e substituido pelo proximo da fila.
     * Vence quem ainda tiver Pokemon de pe.
     *
     * @param array<int,array> $jogador lutadores do jogador, na ordem de batalha
     * @param array<int,array> $inimigo lutadores do adversario
     */
    private function simular(array $jogador, array $inimigo, float $fatorInimigo, string $nomeAdversario): array
    {
        $eventos = [];
        $turno = 0;
        $iJ = 0;
        $iI = 0;
        $totalJ = count($jogador);
        $totalI = count($inimigo);

        $eventos[] = $this->eventoEntrada('jogador', $iJ, $jogador[$iJ], $turno, $nomeAdversario);
        $eventos[] = $this->eventoEntrada('inimigo', $iI, $inimigo[$iI], $turno, $nomeAdversario);

        $rodadas = 0;
        while ($iJ < $totalJ && $iI < $totalI && $rodadas < self::MAX_RODADAS) {
            $rodadas++;
            $turno++;

            $ordem = $this->jogadorAtacaPrimeiro($jogador[$iJ], $inimigo[$iI])
                ? ['jogador', 'inimigo']
                : ['inimigo', 'jogador'];

            foreach ($ordem as $lado) {
                // Fim da fila de um dos lados: a batalha acabou.
                if ($iJ >= $totalJ || $iI >= $totalI) {
                    break;
                }

                if ($lado === 'jogador') {
                    $resultado = $this->calcularDano($jogador[$iJ], $inimigo[$iI], 1.0);
                    $inimigo[$iI]['hp'] = max(0, $inimigo[$iI]['hp'] - $resultado['dano']);
                    $eventos[] = $this->eventoAtaque(
                        'jogador', $iJ, $iI, $jogador[$iJ], $inimigo[$iI], $resultado, $turno
                    );

                    if ($inimigo[$iI]['hp'] <= 0) {
                        $eventos[] = $this->eventoDesmaio('inimigo', $iI, $inimigo[$iI], $turno);
                        $iI++;
                        if ($iI < $totalI) {
                            $eventos[] = $this->eventoEntrada('inimigo', $iI, $inimigo[$iI], $turno, $nomeAdversario);
                        }
                        // Quem desmaiou nao revida e o substituto so entra: a rodada acaba aqui.
                        break;
                    }
                } else {
                    $resultado = $this->calcularDano($inimigo[$iI], $jogador[$iJ], $fatorInimigo);
                    $jogador[$iJ]['hp'] = max(0, $jogador[$iJ]['hp'] - $resultado['dano']);
                    $eventos[] = $this->eventoAtaque(
                        'inimigo', $iI, $iJ, $inimigo[$iI], $jogador[$iJ], $resultado, $turno
                    );

                    if ($jogador[$iJ]['hp'] <= 0) {
                        $eventos[] = $this->eventoDesmaio('jogador', $iJ, $jogador[$iJ], $turno);
                        $iJ++;
                        if ($iJ < $totalJ) {
                            $eventos[] = $this->eventoEntrada('jogador', $iJ, $jogador[$iJ], $turno, $nomeAdversario);
                        }
                        break;
                    }
                }
            }
        }

        $venceu = $iI >= $totalI;

        $eventos[] = [
            'tipo' => 'fim',
            'turno' => $turno,
            'resultado' => $venceu ? 'VITORIA' : 'DERROTA',
            'msg' => $venceu
                ? 'Você venceu a batalha!'
                : 'Todos os seus Pokémon desmaiaram...',
        ];

        return [
            'resultado' => $venceu ? 'VITORIA' : 'DERROTA',
            'eventos' => $eventos,
            'jogador_final' => $jogador,
        ];
    }

    private function jogadorAtacaPrimeiro(array $jogador, array $inimigo): bool
    {
        if ($jogador['prio'] !== $inimigo['prio']) {
            return $jogador['prio'] > $inimigo['prio'];
        }
        if ($jogador['vel'] !== $inimigo['vel']) {
            return $jogador['vel'] > $inimigo['vel'];
        }

        return random_int(0, 1) === 1;
    }

    /**
     * Dano = poder x (ataque / defesa) / 6, vezes efetividade de tipo,
     * variacao de 85-100% e critico (x1.5). Cada Pokemon so tem um tipo e o
     * golpe e sempre desse tipo, entao o bonus STAB ja esta embutido no poder.
     */
    private function calcularDano(array $atacante, array $alvo, float $fator): array
    {
        $especial = $atacante['cat'] === 'ESPECIAL';
        $ataque = $especial ? $atacante['sp_atk'] : $atacante['atk'];
        $defesa = $especial ? $alvo['sp_def'] : $alvo['def'];

        $efetividade = $this->multiplicador($atacante, $alvo);

        if ($efetividade <= 0.0) {
            return ['dano' => 0, 'efetividade' => 0.0, 'critico' => false];
        }

        $critico = random_int(1, 100) <= self::CHANCE_CRITICO;
        $variacao = random_int(85, 100) / 100;
        $base = ($atacante['poder'] * $ataque / max(1, $defesa)) / 6.0;

        $dano = (int) round($base * $efetividade * $variacao * ($critico ? 1.5 : 1.0) * $fator);

        return [
            'dano' => max(1, $dano),
            'efetividade' => $efetividade,
            'critico' => $critico,
        ];
    }

    /**
     * Efetividade de tipos, lida da tabela tipo_efetividade (pares nao
     * listados valem 1.0). Arnaldo (TUDO) acerta tudo em cheio e todo mundo
     * acerta Arnaldo de leve. ALIENX (Deoxys) nao tem relacoes: neutro.
     */
    private function multiplicador(array $atacante, array $alvo): float
    {
        if ($atacante['tipo'] === 'TUDO') {
            return 2.0;
        }
        if ($alvo['tipo'] === 'TUDO') {
            return 0.5;
        }

        if ($this->efetividade === null) {
            $this->efetividade = [];
            $linhas = $this->pdo
                ->query('SELECT tipo_atacante_id AS a, tipo_defensor_id AS d, multiplicador AS m FROM tipo_efetividade')
                ->fetchAll();
            foreach ($linhas as $linha) {
                $this->efetividade[((int) $linha['a']) . '-' . ((int) $linha['d'])] = (float) $linha['m'];
            }
        }

        return $this->efetividade[$atacante['tipo_id'] . '-' . $alvo['tipo_id']] ?? 1.0;
    }

    // ------------------------------------------------------------------
    // EVENTOS (o navegador usa estes campos para animar)
    // ------------------------------------------------------------------

    private function eventoEntrada(string $lado, int $indice, array $lutador, int $turno, string $nomeAdversario): array
    {
        if ($lado === 'jogador') {
            $msg = 'Vai, ' . $lutador['nome'] . '!';
        } else {
            $msg = $nomeAdversario === self::NOME_SELVAGEM
                ? 'Um ' . $lutador['nome'] . ' selvagem apareceu!'
                : $nomeAdversario . ' enviou ' . $lutador['nome'] . '!';
        }

        return [
            'tipo' => 'entra',
            'turno' => $turno,
            'lado' => $lado,
            'i' => $indice,
            'msg' => $msg,
        ];
    }

    private function eventoAtaque(
        string $lado,
        int $indiceAtacante,
        int $indiceAlvo,
        array $atacante,
        array $alvo,
        array $resultado,
        int $turno
    ): array {
        $msg = $atacante['nome'] . ' usou ' . $atacante['golpe'] . '!';

        if ($resultado['efetividade'] <= 0.0) {
            $msg .= ' Não afeta ' . $alvo['nome'] . '...';
        } else {
            $msg .= ' Causou ' . $resultado['dano'] . ' de dano.';
            if ($resultado['efetividade'] > 1.0) {
                $msg .= ' É super efetivo!';
            } elseif ($resultado['efetividade'] < 1.0) {
                $msg .= ' Não é muito efetivo...';
            }
            if ($resultado['critico']) {
                $msg .= ' Golpe crítico!';
            }
        }

        return [
            'tipo' => 'ataque',
            'turno' => $turno,
            'lado' => $lado,
            'i' => $indiceAtacante,
            'alvo' => $indiceAlvo,
            'golpe' => $atacante['golpe'],
            'dano' => $resultado['dano'],
            'efetividade' => $resultado['efetividade'],
            'critico' => $resultado['critico'],
            'hp_alvo' => $alvo['hp'],
            'hp_max_alvo' => $alvo['hp_max'],
            'pk_atacante' => $atacante['id_pokemon'],
            'pk_alvo' => $alvo['id_pokemon'],
            'msg' => $msg,
        ];
    }

    private function eventoDesmaio(string $lado, int $indice, array $lutador, int $turno): array
    {
        return [
            'tipo' => 'desmaia',
            'turno' => $turno,
            'lado' => $lado,
            'i' => $indice,
            'pk' => $lutador['id_pokemon'],
            'msg' => $lutador['nome'] . ' desmaiou!',
        ];
    }

    // ------------------------------------------------------------------
    // GRAVACAO
    // ------------------------------------------------------------------

    private function gravarLog(int $idBatalha, array $eventos, array $jogador, array $inimigo): void
    {
        $inserir = $this->pdo->prepare('
            INSERT INTO log_batalha
                (id_batalha, turno, tipo_evento, mensagem, id_pokemon_atacante, id_pokemon_alvo, dano)
            VALUES
                (:batalha, :turno, :tipo, :msg, :atacante, :alvo, :dano)
        ');

        $nomes = ['entra' => 'ENTRADA', 'ataque' => 'ATAQUE', 'desmaia' => 'DESMAIO', 'fim' => 'FIM'];

        foreach ($eventos as $evento) {
            $atacante = null;
            $alvo = null;
            $dano = null;

            if ($evento['tipo'] === 'ataque') {
                $atacante = $evento['pk_atacante'];
                $alvo = $evento['pk_alvo'];
                $dano = $evento['dano'];
            } elseif ($evento['tipo'] === 'entra') {
                $lista = $evento['lado'] === 'jogador' ? $jogador : $inimigo;
                $atacante = $lista[$evento['i']]['id_pokemon'] ?? null;
            } elseif ($evento['tipo'] === 'desmaia') {
                $alvo = $evento['pk'];
            }

            $inserir->execute([
                'batalha' => $idBatalha,
                'turno' => (int) $evento['turno'],
                'tipo' => $nomes[$evento['tipo']] ?? 'EVENTO',
                'msg' => $evento['msg'],
                'atacante' => $atacante,
                'alvo' => $alvo,
                'dano' => $dano,
            ]);
        }
    }

    /** Salva o HP final de quem lutou (HP 0 = desmaiado). */
    private function gravarHpDoTime(int $idPerfil, array $lutadores): void
    {
        $atualizar = $this->pdo->prepare('
            UPDATE pokemon_capturado
            SET hp_atual = :hp, desmaiado = :desmaiado
            WHERE id = :id AND id_perfil = :perfil
        ');

        foreach ($lutadores as $lutador) {
            $hp = max(0, min((int) $lutador['hp'], (int) $lutador['hp_max']));
            $atualizar->execute([
                'hp' => $hp,
                'desmaiado' => $hp <= 0 ? 1 : 0,
                'id' => $lutador['id_capturado'],
                'perfil' => $idPerfil,
            ]);
        }
    }

    /** "Centro Pokemon": HP cheio, sem desmaio e sem status para todos os Pokemon do perfil. */
    private function curarTime(int $idPerfil): void
    {
        $curar = $this->pdo->prepare('
            UPDATE pokemon_capturado pc
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            SET pc.hp_atual = p.hp, pc.desmaiado = FALSE, pc.status_condicao = NULL
            WHERE pc.id_perfil = :perfil
        ');
        $curar->execute(['perfil' => $idPerfil]);
    }

    // ------------------------------------------------------------------
    // ADVERSARIOS (personagem + time de NPC exigidos pelo banco)
    // ------------------------------------------------------------------

    /**
     * A tabela batalha exige um personagem adversario com um time cadastrado.
     * Selvagens e treinadores comuns usam dois personagens genericos, criados
     * na primeira vez que sao necessarios. Chefes usam o personagem do no.
     *
     * @return array{0:int,1:int} [id_personagem, id_time]
     */
    private function garantirAdversario(array $adversario): array
    {
        if ($adversario['id'] !== null) {
            $idPersonagem = (int) $adversario['id'];
        } else {
            $this->pdo->prepare('
                INSERT IGNORE INTO personagem (nome, jogavel, secreto, imagem)
                VALUES (:nome, FALSE, FALSE, :imagem)
            ')->execute(['nome' => $adversario['nome_db'], 'imagem' => $adversario['imagem']]);

            $consulta = $this->pdo->prepare('SELECT id FROM personagem WHERE nome = :nome AND jogavel = FALSE LIMIT 1');
            $consulta->execute(['nome' => $adversario['nome_db']]);
            $idPersonagem = $consulta->fetchColumn();
            if ($idPersonagem === false) {
                throw new DomainException('Nao foi possivel preparar o adversario desta batalha.');
            }
            $idPersonagem = (int) $idPersonagem;
        }

        $this->pdo->prepare('
            INSERT IGNORE INTO times (id_perfil, id_personagem, nome)
            VALUES (NULL, :personagem, :nome)
        ')->execute(['personagem' => $idPersonagem, 'nome' => 'Time de ' . $adversario['nome_db']]);

        // FOR UPDATE: dois perfis lutando contra o mesmo NPC ao mesmo tempo nao se misturam.
        $consultaTime = $this->pdo->prepare('SELECT id FROM times WHERE id_personagem = :personagem LIMIT 1 FOR UPDATE');
        $consultaTime->execute(['personagem' => $idPersonagem]);
        $idTime = $consultaTime->fetchColumn();
        if ($idTime === false) {
            throw new DomainException('Nao foi possivel preparar o time do adversario.');
        }

        return [$idPersonagem, (int) $idTime];
    }

    /** Registra no banco quais especies o NPC usou nesta batalha (posicoes 1 a 6). */
    private function sincronizarTimeNpc(int $idTime, array $inimigos): void
    {
        $this->pdo->prepare('DELETE FROM time_pokemon WHERE id_time = :time')->execute(['time' => $idTime]);

        $inserir = $this->pdo->prepare('
            INSERT INTO time_pokemon (id_time, id_pokemon, id_pokemon_capturado, posicao)
            VALUES (:time, :pokemon, NULL, :posicao)
        ');

        foreach (array_slice(array_values($inimigos), 0, 6) as $indice => $inimigo) {
            $inserir->execute([
                'time' => $idTime,
                'pokemon' => $inimigo['id_pokemon'],
                'posicao' => $indice + 1,
            ]);
        }
    }

    private function buscarTimeDoJogador(int $idPerfil): int
    {
        $consulta = $this->pdo->prepare('SELECT id FROM times WHERE id_perfil = :perfil LIMIT 1');
        $consulta->execute(['perfil' => $idPerfil]);
        $id = $consulta->fetchColumn();
        if ($id === false) {
            throw new DomainException('Time nao encontrado para este perfil.');
        }

        return (int) $id;
    }

    // ------------------------------------------------------------------
    // CONTEXTO DO MAPA
    // ------------------------------------------------------------------

    private function carregarContexto(int $idPerfil, int $idUsuario, bool $bloquear): array
    {
        if ($idPerfil <= 0 || $idUsuario <= 0) {
            throw new DomainException('Perfil invalido.');
        }

        $sql = '
            SELECT
                pa.id_area, pa.id_no_atual, pa.estado,
                p.id_personagem AS id_personagem_perfil,
                n.tipo_no, n.codigo, n.titulo, n.pos_y,
                n.id_personagem AS id_personagem_no
            FROM progresso_area pa
            INNER JOIN perfil p ON p.id = pa.id_perfil
            LEFT JOIN area_no n
                ON n.id = pa.id_no_atual AND n.id_area = pa.id_area
            WHERE pa.id_perfil = :perfil AND p.id_usuario = :usuario
            ORDER BY pa.id_area ASC
            LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '');

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        $linha = $consulta->fetch();

        if ($linha === false) {
            throw new DomainException('Progresso do perfil nao encontrado.');
        }

        $idNo = $linha['id_no_atual'] === null ? null : (int) $linha['id_no_atual'];
        $venceu = false;

        if ($idNo !== null) {
            $vitoria = $this->pdo->prepare('
                SELECT COUNT(*) FROM batalha
                WHERE id_perfil = :perfil AND id_area = :area AND id_no = :no AND resultado = \'VITORIA\'
            ');
            $vitoria->execute(['perfil' => $idPerfil, 'area' => (int) $linha['id_area'], 'no' => $idNo]);
            $venceu = (int) $vitoria->fetchColumn() > 0;
        }

        return [
            'id_area' => (int) $linha['id_area'],
            'id_no' => $idNo,
            'estado' => (string) $linha['estado'],
            'id_personagem_perfil' => $linha['id_personagem_perfil'] === null ? null : (int) $linha['id_personagem_perfil'],
            'tipo_no' => (string) ($linha['tipo_no'] ?? ''),
            'codigo' => (string) ($linha['codigo'] ?? ''),
            'titulo' => (string) ($linha['titulo'] ?? ''),
            'pos_y' => (int) ($linha['pos_y'] ?? 0),
            'id_personagem_no' => $linha['id_personagem_no'] === null ? null : (int) $linha['id_personagem_no'],
            'venceu' => $venceu,
        ];
    }

    private function validarNoDeBatalha(array $ctx): void
    {
        if ($ctx['id_no'] === null
            || $ctx['estado'] === 'CONCLUIDA'
            || !in_array($ctx['tipo_no'], self::NOS_DE_BATALHA, true)) {
            throw new DomainException('Nao ha batalha pendente neste ponto do mapa.', self::SEM_BATALHA);
        }

        if ($ctx['venceu']) {
            throw new DomainException('Esta batalha ja foi vencida. Escolha o proximo caminho no mapa.', self::SEM_BATALHA);
        }

        if ($ctx['id_personagem_perfil'] === null) {
            throw new DomainException('Escolha um personagem antes de batalhar.');
        }
    }

    // ------------------------------------------------------------------
    // TIMES E ENCONTROS
    // ------------------------------------------------------------------

    /** Posicoes 1 a 5 do time, na ordem de batalha (a 6 e reservada ao Rayquaza). */
    private function carregarEquipe(int $idPerfil): array
    {
        $consulta = $this->pdo->prepare('
            SELECT tp.posicao, pc.id AS id_capturado, pc.hp_atual, pc.desmaiado,' . self::COLUNAS_ESPECIE . '
            FROM times tm
            INNER JOIN time_pokemon tp ON tp.id_time = tm.id
            INNER JOIN pokemon_capturado pc ON pc.id = tp.id_pokemon_capturado
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE tm.id_perfil = :perfil AND tp.posicao BETWEEN 1 AND 5
            ORDER BY tp.posicao ASC
        ');
        $consulta->execute(['perfil' => $idPerfil]);

        $equipe = [];
        foreach ($consulta->fetchAll() as $linha) {
            $equipe[] = $this->criarLutador($linha);
        }

        return $equipe;
    }

    private function criarLutador(array $linha): array
    {
        $hpMax = (int) $linha['hp_max'];

        return [
            'id_pokemon' => (int) $linha['id_pokemon'],
            'id_capturado' => isset($linha['id_capturado']) ? (int) $linha['id_capturado'] : null,
            'posicao' => isset($linha['posicao']) ? (int) $linha['posicao'] : null,
            'nome' => (string) $linha['nome'],
            'imagem' => (string) $linha['imagem'],
            'tipo' => (string) $linha['tipo'],
            'tipo_id' => (int) $linha['tipo_id'],
            'hp' => isset($linha['hp_atual']) ? (int) $linha['hp_atual'] : $hpMax,
            'hp_max' => $hpMax,
            'atk' => (int) $linha['atk'],
            'def' => (int) $linha['def'],
            'sp_atk' => (int) $linha['sp_atk'],
            'sp_def' => (int) $linha['sp_def'],
            'vel' => (int) $linha['vel'],
            'prio' => (int) $linha['prioridade'],
            'golpe' => (string) $linha['nome_ataque'],
            'cat' => (string) $linha['categoria_ataque'],
            'poder' => (int) $linha['poder_ataque'],
            'desmaiado' => isset($linha['desmaiado']) ? (bool) $linha['desmaiado'] : false,
        ];
    }

    /**
     * O encontro (quem sera o adversario) e sorteado uma vez por no e guardado
     * na sessao: assim o jogador pode reordenar o time ou recarregar a pagina
     * sem trocar de adversario. Ao terminar a luta ele e descartado.
     */
    private function obterEncontro(int $idPerfil, array $ctx, array $equipe): array
    {
        $chave = $idPerfil . ':' . $ctx['id_no'];
        $guardado = $_SESSION['peakmon_encontro'][$chave] ?? null;

        if (is_array($guardado) && isset($guardado['inimigos'], $guardado['adversario'], $guardado['categoria'], $guardado['fator'])) {
            return $guardado;
        }

        $encontro = $this->gerarEncontro($ctx, $equipe);
        $_SESSION['peakmon_encontro'][$chave] = $encontro;

        return $encontro;
    }

    private function descartarEncontro(int $idPerfil, ?int $idNo): void
    {
        unset($_SESSION['peakmon_encontro'][$idPerfil . ':' . $idNo]);
    }

    /**
     * Escala a dificuldade pela profundidade do no no mapa (pos_y) e pela
     * forca do time do jogador (soma dos atributos base). Assim o jogo continua
     * jogavel mesmo sem loja/evolucoes, e evoluir um Pokemon nao e "castigado"
     * porque o encontro ja foi sorteado.
     *
     * Os multiplicadores abaixo foram calibrados por simulacao (milhares de
     * runs com os atributos do peakmon_base.sql): ~75% de vitoria em selvagens,
     * ~70% em treinadores e o chefe cai em 2-3 tentativas. Para deixar o jogo
     * mais facil/dificil, mexa so neles (menor = inimigos mais fracos).
     */
    private function gerarEncontro(array $ctx, array $equipe): array
    {
        $profundidade = max(0.0, min(1.0, ($ctx['pos_y'] - 5) / 91));

        if ($ctx['tipo_no'] === 'CHEFE') {
            $categoria = 'CHEFE';
            $quantidade = 5;
            $multiplicador = 0.8;
            $fator = 1.0;
        } elseif (str_starts_with($ctx['codigo'], 'TREINADOR')) {
            $categoria = 'TREINADOR';
            $quantidade = $profundidade > 0.55 ? 3 : 2;
            $multiplicador = 0.75 + 0.25 * $profundidade;
            $fator = 0.8 + 0.3 * $profundidade;
        } else {
            $categoria = 'SELVAGEM';
            $quantidade = $profundidade > 0.6 ? 2 : 1;
            $multiplicador = 0.7 + 0.25 * $profundidade;
            $fator = 0.8 + 0.3 * $profundidade;
        }

        // Forca media do time do jogador (todos os integrantes, desmaiados ou nao).
        $somaForca = 0;
        foreach ($equipe as $lutador) {
            $somaForca += $this->forcaBase($lutador);
        }
        $forcaMedia = $equipe === [] ? 300.0 : $somaForca / count($equipe);
        $alvo = $forcaMedia * $multiplicador;

        $especies = $this->carregarEspeciesSelvagens();
        if ($especies === []) {
            throw new DomainException('Nenhuma especie disponivel para montar o adversario.');
        }

        usort(
            $especies,
            static fn (array $a, array $b): int => abs($a['forca'] - $alvo) <=> abs($b['forca'] - $alvo)
        );
        $reserva = array_slice($especies, 0, max(6, $quantidade + 3));
        shuffle($reserva);
        $escolhidas = array_slice($reserva, 0, $quantidade);

        $inimigos = [];
        foreach ($escolhidas as $especie) {
            $inimigos[] = $this->criarLutador($especie['linha']);
        }

        return [
            'categoria' => $categoria,
            'fator' => $fator,
            'adversario' => $this->descreverAdversario($ctx, $categoria),
            'inimigos' => $inimigos,
        ];
    }

    private function forcaBase(array $lutador): int
    {
        return $lutador['hp_max'] + $lutador['atk'] + $lutador['def']
            + $lutador['sp_atk'] + $lutador['sp_def'] + $lutador['vel'];
    }

    /**
     * Especies que podem aparecer como inimigas: so as capturaveis do catalogo
     * oficial (api_id preenchido). Fora ficam Rayquaza/Deoxys (historia) e o
     * Arnaldo (personalizado).
     */
    private function carregarEspeciesSelvagens(): array
    {
        $consulta = $this->pdo->query('
            SELECT' . self::COLUNAS_ESPECIE . '
            FROM pokemon p
            INNER JOIN tipo_pokemon t ON t.id = p.tipo_id
            WHERE p.capturavel = TRUE AND p.api_id IS NOT NULL
        ');

        $lista = [];
        foreach ($consulta->fetchAll() as $linha) {
            $lutador = $this->criarLutador($linha);
            $lista[] = ['forca' => $this->forcaBase($lutador), 'linha' => $linha];
        }

        return $lista;
    }

    /** Quem aparece como adversario: o personagem do no (se houver) ou um generico. */
    private function descreverAdversario(array $ctx, string $categoria): array
    {
        if ($ctx['id_personagem_no'] !== null) {
            $consulta = $this->pdo->prepare('SELECT nome, imagem FROM personagem WHERE id = :id AND jogavel = FALSE LIMIT 1');
            $consulta->execute(['id' => $ctx['id_personagem_no']]);
            $personagem = $consulta->fetch();

            if ($personagem === false) {
                throw new DomainException('O personagem deste ponto do mapa nao e um adversario valido.');
            }

            return [
                'id' => $ctx['id_personagem_no'],
                'nome_db' => (string) $personagem['nome'],
                'nome' => (string) $personagem['nome'],
                'imagem' => (string) $personagem['imagem'],
                'emoji' => $categoria === 'CHEFE' ? '⭐' : '🥋',
            ];
        }

        if ($categoria === 'TREINADOR') {
            $nome = 'Treinador ' . self::NOMES_TREINADORES[array_rand(self::NOMES_TREINADORES)];

            return [
                'id' => null,
                'nome_db' => 'Treinador',
                'nome' => $nome,
                'imagem' => 'menu inicial/images/sla.jpg',
                'emoji' => '🥋',
            ];
        }

        return [
            'id' => null,
            'nome_db' => 'Pokemon Selvagem',
            'nome' => self::NOME_SELVAGEM,
            'imagem' => 'images/pokemon/Absol.png',
            'emoji' => '🌿',
        ];
    }
}
