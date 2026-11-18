<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Mapa
{
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

        // O projeto utiliza uma unica area. Por isso, carregamos a primeira area cadastrada.
        $consultaArea = $this->pdo->query('
            SELECT id, nome
            FROM area_jogo
            ORDER BY ordem ASC
            LIMIT 1
        ');

        $area = $consultaArea->fetch();

        if ($area === false) {
            return [
                'perfil' => $perfil,
                'area' => null,
                'progresso' => null,
                'nos' => [],
                'conexoes' => [],
                'batalha_vencida' => false
            ];
        }

        $idArea = (int) $area['id'];

        // Garante que o perfil tenha um ponto inicial, sem sobrescrever um save existente.
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
            $consultaProgresso = $this->pdo->prepare('
                SELECT pa.id_area, pa.id_no_atual, pa.estado
                FROM progresso_area pa
                INNER JOIN perfil p ON p.id = pa.id_perfil
                WHERE pa.id_perfil = :id_perfil
                  AND p.id_usuario = :id_usuario
                ORDER BY pa.id_area ASC
                LIMIT 1
                FOR UPDATE
            ');

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
            if ($destino['tipo_no'] === 'CAPTURA' && $aoChegarEmCaptura !== null) {
                $captura = $aoChegarEmCaptura($idPerfil);
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

            return $retorno;

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
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
