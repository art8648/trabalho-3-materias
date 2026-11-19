<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Mapa
{
    /** Itens que podem ser achados nos pontos "Item escondido" (so consumiveis da loja). */
    public const ITENS_ESCONDIDOS = [
        'Potion', 'Super Potion', 'Antidote', 'Burn Heal',
        'Ice Heal', 'Awakening', 'Paralyze Heal', 'Full Heal', 'Revive'
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Carrega o perfil, a area, o progresso, os nos e as conexoes.
     * O id do perfil e validado junto ao id do usuario logado.
     */
    public function carregarMapa(int $idPerfil, int $idUsuario): array
    {
        $consultaPerfil = $this->pdo->prepare('
            SELECT id, nome_perfil, dinheiro, jogo_concluido
            FROM perfil
            WHERE id = :id_perfil
              AND id_usuario = :id_usuario
            LIMIT 1
        ');

        $consultaPerfil->execute([
            'id_perfil' => $idPerfil,
            'id_usuario' => $idUsuario
        ]);

        $perfil = $consultaPerfil->fetch();

        if ($perfil === false) {
            throw new DomainException('O perfil selecionado nao pertence a este usuario.');
        }

        // Prepara o progresso da area 1, como na versao que ja funcionava.
        $primeiraArea = $this->pdo->query('
            SELECT id FROM area_jogo ORDER BY ordem ASC LIMIT 1
        ')->fetchColumn();

        if ($primeiraArea === false) {
            return [
                'perfil' => $perfil,
                'area' => null,
                'progresso' => null,
                'nos' => [],
                'conexoes' => [],
                'batalha_vencida' => false
            ];
        }

        $this->garantirProgressoInicial($idPerfil, (int) $primeiraArea);

        // A trigger do banco libera a proxima area ao vencer Hiago.
        // Aqui apenas LEMOS o progresso ja existente: nao tentamos criar
        // desbloqueios extras durante a abertura do mapa.
        $consultaArea = $this->pdo->prepare("
            SELECT a.id, a.nome, a.ordem, chefe.nome AS chefe_nome
            FROM area_jogo a
            LEFT JOIN personagem chefe ON chefe.id = a.id_personagem_chefe
            WHERE a.id = :primeira_area
               OR EXISTS (
                   SELECT 1
                   FROM progresso_area pa
                   WHERE pa.id_perfil = :id_perfil
                     AND pa.id_area = a.id
                     AND pa.estado IN ('DISPONIVEL', 'EM_ANDAMENTO', 'CONCLUIDA')
               )
            ORDER BY a.ordem DESC
            LIMIT 1
        ");
        $consultaArea->execute([
            'primeira_area' => (int) $primeiraArea,
            'id_perfil' => $idPerfil
        ]);
        $area = $consultaArea->fetch();
        if ($area === false) {
            throw new DomainException('Nao foi possivel carregar a area deste perfil.');
        }

        $idArea = (int) $area['id'];
        // A area desbloqueada pode estar com o ponto inicial NULL (trigger da vitoria).
        $this->garantirProgressoInicial($idPerfil, $idArea);

        $consultaProgresso = $this->pdo->prepare('
            SELECT
                pa.id_no_atual,
                pa.estado,
                n.titulo AS no_atual_titulo,
                n.tipo_no AS no_atual_tipo
            FROM progresso_area pa
            LEFT JOIN area_no n
                ON n.id = pa.id_no_atual
               AND n.id_area = pa.id_area
            WHERE pa.id_perfil = :id_perfil
              AND pa.id_area = :id_area
            LIMIT 1
        ');
        $consultaProgresso->execute([
            'id_perfil' => $idPerfil,
            'id_area' => $idArea
        ]);

        $progresso = $consultaProgresso->fetch();

        if ($progresso === false) {
            throw new DomainException('Nao foi possivel preparar o progresso deste perfil.');
        }

        $batalhaVencida = false;
        if (in_array($progresso['no_atual_tipo'], ['BATALHA', 'CHEFE'], true)) {
            $consultaVitoria = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM batalha
                WHERE id_perfil = :id_perfil
                  AND id_area = :id_area
                  AND id_no = :id_no
                  AND resultado = :resultado
            " );
            $consultaVitoria->execute([
                'id_perfil' => $idPerfil,
                'id_area' => $idArea,
                'id_no' => (int) $progresso['id_no_atual'],
                'resultado' => 'VITORIA'
            ]);
            $batalhaVencida = (int) $consultaVitoria->fetchColumn() > 0;
        }

        $consultaNos = $this->pdo->prepare('
            SELECT id, codigo, tipo_no, titulo, descricao, pos_x, pos_y
            FROM area_no
            WHERE id_area = :id_area
            ORDER BY pos_y DESC, id ASC
        ');

        $consultaNos->execute(['id_area' => $idArea]);
        $nos = $consultaNos->fetchAll();

        $consultaConexoes = $this->pdo->prepare('
            SELECT
                ac.id_no_origem,
                ac.id_no_destino,
                ac.texto_opcao,
                origem.pos_x AS origem_x,
                origem.pos_y AS origem_y,
                destino.pos_x AS destino_x,
                destino.pos_y AS destino_y
            FROM area_conexao ac
            INNER JOIN area_no origem
                ON origem.id = ac.id_no_origem
               AND origem.id_area = ac.id_area
            INNER JOIN area_no destino
                ON destino.id = ac.id_no_destino
               AND destino.id_area = ac.id_area
            WHERE ac.id_area = :id_area
        ');

        $consultaConexoes->execute(['id_area' => $idArea]);
        $conexoes = $consultaConexoes->fetchAll();

        return [
            'perfil' => $perfil,
            'area' => $area,
            'progresso' => $progresso,
            'nos' => $nos,
            'conexoes' => $conexoes,
            'batalha_vencida' => $batalhaVencida
        ];
    }

    /**
     * Avanca somente para um no ligado diretamente ao no atual.
     * O perfil vem da sessao e sua propriedade e validada no banco.
     *
     * Se o destino for um ponto de CAPTURA e $aoChegarEmCaptura for informado,
     * ele roda dentro da mesma transacao do movimento (ex.: sortear o Pokemon).
     * O que ele devolver volta na chave 'captura' do resultado.
     */
    public function avancar(
        int $idPerfil,
        int $idUsuario,
        int $idNoDestino,
        ?callable $aoChegarEmCaptura = null
    ): array {
        if ($idPerfil <= 0 || $idUsuario <= 0 || $idNoDestino <= 0) {
            throw new DomainException('Dados invalidos para avancar no mapa.');
        }

        $this->pdo->beginTransaction();

        try {
            $consultaProgresso = $this->pdo->prepare("
                SELECT pa.id_area, pa.id_no_atual, pa.estado
                FROM progresso_area pa
                INNER JOIN perfil p ON p.id = pa.id_perfil
                INNER JOIN area_jogo a ON a.id = pa.id_area
                WHERE pa.id_perfil = :id_perfil
                  AND p.id_usuario = :id_usuario
                  AND pa.estado IN ('DISPONIVEL', 'EM_ANDAMENTO')
                ORDER BY a.ordem DESC
                LIMIT 1
                FOR UPDATE
            ");

            $consultaProgresso->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);

            $progresso = $consultaProgresso->fetch();

            if ($progresso === false || $progresso['id_no_atual'] === null) {
                throw new DomainException('Este perfil ainda nao possui um ponto atual no mapa.');
            }

            if ($progresso['estado'] === 'CONCLUIDA') {
                throw new DomainException('Este mapa ja foi concluido.');
            }

            $idArea = (int) $progresso['id_area'];
            $idNoAtual = (int) $progresso['id_no_atual'];

            // Se o ponto atual for uma batalha, exige uma vitoria registrada antes de seguir.
            $consultaTipoAtual = $this->pdo->prepare("
                SELECT tipo_no
                FROM area_no
                WHERE id = :id_no_atual AND id_area = :id_area
                LIMIT 1
            " );
            $consultaTipoAtual->execute([
                'id_no_atual' => $idNoAtual,
                'id_area' => $idArea
            ]);
            $tipoNoAtual = $consultaTipoAtual->fetchColumn();

            if (in_array($tipoNoAtual, ['BATALHA', 'CHEFE'], true)) {
                $consultaVitoria = $this->pdo->prepare("
                    SELECT COUNT(*)
                    FROM batalha
                    WHERE id_perfil = :id_perfil
                      AND id_area = :id_area
                      AND id_no = :id_no
                      AND resultado = :resultado
                " );
                $consultaVitoria->execute([
                    'id_perfil' => $idPerfil,
                    'id_area' => $idArea,
                    'id_no' => $idNoAtual,
                    'resultado' => 'VITORIA'
                ]);

                if ((int) $consultaVitoria->fetchColumn() === 0) {
                    throw new DomainException('Voce precisa vencer esta batalha antes de seguir.');
                }
            }

            // Confere se o destino e uma conexao permitida a partir do no atual.
            $consultaDestino = $this->pdo->prepare('
                SELECT n.id, n.tipo_no, n.titulo
                FROM area_conexao ac
                INNER JOIN area_no n
                    ON n.id = ac.id_no_destino
                   AND n.id_area = ac.id_area
                WHERE ac.id_area = :id_area
                  AND ac.id_no_origem = :id_no_atual
                  AND ac.id_no_destino = :id_no_destino
                LIMIT 1
            ');

            $consultaDestino->execute([
                'id_area' => $idArea,
                'id_no_atual' => $idNoAtual,
                'id_no_destino' => $idNoDestino
            ]);

            $destino = $consultaDestino->fetch();

            if ($destino === false) {
                throw new DomainException('Esse caminho nao esta conectado ao ponto atual.');
            }

            $novoEstado = $destino['tipo_no'] === 'FIM'
                ? 'CONCLUIDA'
                : 'EM_ANDAMENTO';

            $atualizar = $this->pdo->prepare('
                UPDATE progresso_area
                SET id_no_atual = :id_no_destino,
                    estado = :estado
                WHERE id_perfil = :id_perfil
                  AND id_area = :id_area
                  AND id_no_atual = :id_no_atual
            ');

            $atualizar->execute([
                'id_no_destino' => $idNoDestino,
                'estado' => $novoEstado,
                'id_perfil' => $idPerfil,
                'id_area' => $idArea,
                'id_no_atual' => $idNoAtual
            ]);

            if ($atualizar->rowCount() !== 1) {
                throw new DomainException('O progresso mudou. Atualize o mapa e tente novamente.');
            }

            $captura = null;
            $itemEncontrado = null;
            $centroPokemon = null;
            if ($destino['tipo_no'] === 'CAPTURA' && $aoChegarEmCaptura !== null) {
                $captura = $aoChegarEmCaptura($idPerfil);
            } elseif ($destino['tipo_no'] === 'ITEM') {
                $itemEncontrado = $this->entregarItemEscondido($idPerfil);
            } elseif ($destino['tipo_no'] === 'CURA') {
                $centroPokemon = $this->curarNoCentro($idPerfil);
            }

            $this->pdo->commit();

            $retorno = [
                'sucesso' => true,
                'mensagem' => 'Progresso salvo!',
                'no' => [
                    'id' => (int) $destino['id'],
                    'titulo' => $destino['titulo'],
                    'tipo_no' => $destino['tipo_no']
                ]
            ];

            if ($captura !== null) {
                $retorno['captura'] = $captura;
            }
            if ($itemEncontrado !== null) {
                $retorno['item_encontrado'] = $itemEncontrado;
            }
            if ($centroPokemon !== null) {
                $retorno['centro_pokemon'] = $centroPokemon;
            }

            return $retorno;

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }

    /** Itens da roleta do "Item escondido", na mesma ordem do sorteio (o navegador so desenha). */
    public function listarRoletaItens(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ITENS_ESCONDIDOS), '?'));
        $consulta = $this->pdo->prepare("SELECT id, nome, tipo_efeito FROM item WHERE ativo = TRUE AND nome IN ($placeholders)");
        $consulta->execute(self::ITENS_ESCONDIDOS);

        $porNome = [];
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $porNome[$linha['nome']] = $linha;
        }

        $lista = [];
        foreach (self::ITENS_ESCONDIDOS as $nome) {
            if (isset($porNome[$nome])) {
                $lista[] = [
                    'id' => (int) $porNome[$nome]['id'],
                    'nome' => $porNome[$nome]['nome'],
                    'tipo' => $porNome[$nome]['tipo_efeito']
                ];
            }
        }

        return $lista;
    }

    /** Premia o jogador no proprio movimento, sem depender de um segundo POST. */
    private function entregarItemEscondido(int $idPerfil): array
    {
        // Somente consumiveis da loja; a Pedra Universal continua na loja / chefes.
        $nomes = self::ITENS_ESCONDIDOS;
        $placeholders = implode(',', array_fill(0, count($nomes), '?'));
        $consulta = $this->pdo->prepare("SELECT id, nome, tipo_efeito FROM item WHERE ativo = TRUE AND nome IN ($placeholders)");
        $consulta->execute($nomes);
        $itens = $consulta->fetchAll(PDO::FETCH_ASSOC);
        if ($itens === []) {
            throw new DomainException('Nao existem itens ativos para encontrar neste ponto.');
        }
        $item = $itens[random_int(0, count($itens) - 1)];

        $inventario = $this->pdo->prepare('SELECT id FROM inventario WHERE id_perfil = :perfil LIMIT 1');
        $inventario->execute(['perfil' => $idPerfil]);
        $idInventario = $inventario->fetchColumn();
        if ($idInventario === false) {
            throw new DomainException('Inventario do perfil nao encontrado.');
        }
        $adicionar = $this->pdo->prepare('INSERT INTO inventario_item (id_inventario, id_item, quantidade)
            VALUES (:inventario, :item, 1)
            ON DUPLICATE KEY UPDATE quantidade = quantidade + 1');
        $adicionar->execute(['inventario' => (int) $idInventario, 'item' => (int) $item['id']]);
        return [
            'id' => (int) $item['id'],
            'nome' => $item['nome'],
            'tipo' => $item['tipo_efeito'],
            'quantidade' => 1
        ];
    }

    /** Cura SOMENTE os integrantes do time ativo (slots de 1 a 5). */
    private function curarNoCentro(int $idPerfil): array
    {
        $consulta = $this->pdo->prepare('SELECT COUNT(*)
            FROM times tm INNER JOIN time_pokemon tp ON tp.id_time = tm.id
            INNER JOIN pokemon_capturado pc ON pc.id = tp.id_pokemon_capturado
            WHERE tm.id_perfil = :perfil AND tp.posicao BETWEEN 1 AND 5');
        $consulta->execute(['perfil' => $idPerfil]);
        $quantidade = (int) $consulta->fetchColumn();
        $cura = $this->pdo->prepare('UPDATE pokemon_capturado pc
            INNER JOIN pokemon p ON p.id = pc.id_pokemon
            INNER JOIN time_pokemon tp ON tp.id_pokemon_capturado = pc.id
            INNER JOIN times tm ON tm.id = tp.id_time
            SET pc.hp_atual = p.hp, pc.desmaiado = FALSE, pc.status_condicao = NULL
            WHERE tm.id_perfil = :perfil AND tp.posicao BETWEEN 1 AND 5');
        $cura->execute(['perfil' => $idPerfil]);
        return ['quantidade' => $quantidade];
    }

    private function garantirProgressoInicial(int $idPerfil, int $idArea): void
    {
        $consultaInicio = $this->pdo->prepare('
            SELECT id
            FROM area_no
            WHERE id_area = :id_area
              AND tipo_no = \'INICIO\'
            ORDER BY id ASC
            LIMIT 1
        ');

        $consultaInicio->execute(['id_area' => $idArea]);
        $idNoInicio = $consultaInicio->fetchColumn();

        if ($idNoInicio === false) {
            throw new DomainException('A area cadastrada ainda nao possui um no INICIO.');
        }

        $inserir = $this->pdo->prepare('
            INSERT INTO progresso_area
                (id_perfil, id_area, id_no_atual, estado, data_inicio)
            VALUES
                (:id_perfil, :id_area, :id_no_inicio, \'DISPONIVEL\', CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                id_no_atual = IF(
                    estado IN (\'BLOQUEADA\', \'DISPONIVEL\') AND id_no_atual IS NULL,
                    VALUES(id_no_atual),
                    id_no_atual
                ),
                estado = IF(estado = \'BLOQUEADA\', \'DISPONIVEL\', estado)
        ');

        $inserir->execute([
            'id_perfil' => $idPerfil,
            'id_area' => $idArea,
            'id_no_inicio' => (int) $idNoInicio
        ]);
    }
}
