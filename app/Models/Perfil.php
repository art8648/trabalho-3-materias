<?php

namespace Peakmon\Models;

use DomainException;
use PDO;

class Perfil
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listarPorUsuario(int $idUsuario): array
    {
        $sql = '
            SELECT
                p.id,
                p.nome_perfil,
                p.id_avatar,
                p.id_personagem,
                p.dinheiro,
                p.jogo_concluido,
                p.data_criacao,
                av.nome AS avatar_nome,
                av.imagem AS avatar_imagem,
                c.nome AS personagem_nome
            FROM perfil p
            LEFT JOIN avatar_opcao av
                ON av.id = p.id_avatar
            LEFT JOIN personagem c
                ON c.id = p.id_personagem
            WHERE p.id_usuario = :id_usuario
            ORDER BY p.numero_perfil ASC
        ';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_usuario' => $idUsuario
        ]);

        return $consulta->fetchAll();
    }

    public function contarPorUsuario(int $idUsuario): int
    {
        $sql = '
            SELECT COUNT(*)
            FROM perfil
            WHERE id_usuario = :id_usuario
        ';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_usuario' => $idUsuario
        ]);

        return (int) $consulta->fetchColumn();
    }

    public function buscarPorIdDoUsuario(
        int $idPerfil,
        int $idUsuario
    ): ?array {
        $sql = '
            SELECT
                p.id,
                p.nome_perfil,
                p.id_avatar,
                p.id_personagem,
                p.dinheiro,
                p.jogo_concluido,
                p.data_criacao,
                av.nome AS avatar_nome,
                av.imagem AS avatar_imagem,
                c.nome AS personagem_nome
            FROM perfil p
            LEFT JOIN avatar_opcao av
                ON av.id = p.id_avatar
            LEFT JOIN personagem c
                ON c.id = p.id_personagem
            WHERE p.id = :id_perfil
              AND p.id_usuario = :id_usuario
            LIMIT 1
        ';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([
            'id_perfil' => $idPerfil,
            'id_usuario' => $idUsuario
        ]);

        $perfil = $consulta->fetch();

        return $perfil === false ? null : $perfil;
    }

    /**
     * Reinicia UM perfil: apaga os Pokemon capturados (o time esvazia por
     * cascata), limpa o personagem e volta a posicao no mapa para o inicio.
     */
    public function reiniciarEscolhas(int $idPerfil, int $idUsuario): void
    {
        $this->pdo->beginTransaction();

        try {
            // Confere que o perfil e deste usuario.
            $consulta = $this->pdo->prepare('
                SELECT id FROM perfil
                WHERE id = :id_perfil AND id_usuario = :id_usuario
                LIMIT 1
                FOR UPDATE
            ');
            $consulta->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);

            if ($consulta->fetchColumn() === false) {
                throw new DomainException('Perfil nao encontrado.');
            }

            // Limpa os itens da mochila deste perfil, sem apagar o inventario.
            $limparMochila = $this->pdo->prepare('
                DELETE ii
                FROM inventario_item ii
                INNER JOIN inventario i
                    ON i.id = ii.id_inventario
                WHERE i.id_perfil = :id_perfil
            ');
            $limparMochila->execute(['id_perfil' => $idPerfil]);

            // Preservar o passado sem manter batalhas "vencidas" no mapa atual:
            // as batalhas antigas sao arquivadas ANTES de apagar a progressao.
            // A tabela e opcional para manter compatibilidade com bancos antigos.
            $temArquivo = $this->pdo->query("SHOW TABLES LIKE 'historico_batalha_arquivo'")->fetchColumn() !== false;
            $historico = new Historico($this->pdo);
            $consultarBatalhas = $this->pdo->prepare('
                SELECT b.id, b.tipo_batalha, b.resultado, b.dinheiro_ganho,
                       b.data_inicio, b.data_fim,
                       adversario.nome AS adversario, a.nome AS area_nome
                FROM batalha b
                JOIN personagem adversario ON adversario.id = b.id_personagem_adversario
                LEFT JOIN area_jogo a ON a.id = b.id_area
                WHERE b.id_perfil = :perfil ORDER BY b.id
            ');
            $consultarBatalhas->execute(['perfil' => $idPerfil]);
            $batalhasAntigas = $consultarBatalhas->fetchAll(PDO::FETCH_ASSOC);
            if ($temArquivo) {
                $lerTurnos = $this->pdo->prepare('
                    SELECT turno, tipo_evento, mensagem, dano, data_evento
                    FROM log_batalha WHERE id_batalha = :batalha ORDER BY turno, id
                ');
                $gravarArquivo = $this->pdo->prepare('
                    INSERT INTO historico_batalha_arquivo
                    (id_perfil, id_batalha_original, tipo_batalha, resultado, dinheiro_ganho,
                     data_inicio, data_fim, adversario, area_nome, turnos_json)
                    VALUES (:perfil, :batalha, :tipo, :resultado, :dinheiro,
                            :inicio, :fim, :adversario, :area, :turnos)
                ');
            }
            foreach ($batalhasAntigas as $batalhaAntiga) {
                if ($temArquivo) {
                    $lerTurnos->execute(['batalha' => (int) $batalhaAntiga['id']]);
                    $turnos = $lerTurnos->fetchAll(PDO::FETCH_ASSOC);
                    $gravarArquivo->execute([
                        'perfil' => $idPerfil, 'batalha' => (int) $batalhaAntiga['id'],
                        'tipo' => $batalhaAntiga['tipo_batalha'],
                        'resultado' => $batalhaAntiga['resultado'],
                        'dinheiro' => (int) $batalhaAntiga['dinheiro_ganho'],
                        'inicio' => $batalhaAntiga['data_inicio'], 'fim' => $batalhaAntiga['data_fim'],
                        'adversario' => $batalhaAntiga['adversario'],
                        'area' => $batalhaAntiga['area_nome'],
                        'turnos' => json_encode($turnos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
                // Mesmo sem executar a migracao, ao menos a memoria da batalha
                // permanece nos eventos do perfil (maximo 255 caracteres).
                $historico->registrar($idPerfil, 'BATALHA_ARQUIVADA',
                    'Batalha #' . $batalhaAntiga['id'] . ' contra ' . $batalhaAntiga['adversario']
                    . ' (' . $batalhaAntiga['resultado'] . ') antes de reiniciar a jornada.');
            }

            // Apaga os Pokemon capturados; os vinculos do time sao removidos por cascata.
            // Historico de batalhas: sem apagar, os pontos do mapa continuariam
            // "vencidos" depois de reiniciar (o Mapa libera o caminho pela vitoria
            // registrada no no). A ordem respeita as chaves estrangeiras; o
            // log_batalha e apagado em cascata junto com a batalha.
            foreach (['capture_attempts', 'area_concluida', 'batalha'] as $tabela) {
                // Nomes de tabela fixos acima (nunca vindos do usuario).
                $limparHistorico = $this->pdo->prepare("DELETE FROM $tabela WHERE id_perfil = :id_perfil");
                $limparHistorico->execute(['id_perfil' => $idPerfil]);
            }

            $apagar = $this->pdo->prepare('DELETE FROM pokemon_capturado WHERE id_perfil = :id_perfil');
            $apagar->execute(['id_perfil' => $idPerfil]);

            $limpar = $this->pdo->prepare('
                UPDATE perfil
                SET id_personagem = NULL,
                    dinheiro = 0,
                    jogo_concluido = 0
                WHERE id = :id_perfil AND id_usuario = :id_usuario
            ');
            $limpar->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario
            ]);

            // Um reinicio verdadeiro deve bloquear novamente as areas seguintes.
            // Remove o progresso das duas areas (somente do perfil ativo) e
            // cria novamente o inicio da primeira area.
            $apagarProgresso = $this->pdo->prepare('
                DELETE FROM progresso_area WHERE id_perfil = :id_perfil
            ');
            $apagarProgresso->execute(['id_perfil' => $idPerfil]);

            $voltarAoInicio = $this->pdo->prepare("
                INSERT INTO progresso_area
                    (id_perfil, id_area, id_no_atual, estado, data_inicio)
                SELECT :id_perfil, a.id, n.id, 'DISPONIVEL', CURRENT_TIMESTAMP
                FROM area_jogo a
                INNER JOIN area_no n ON n.id_area = a.id AND n.tipo_no = 'INICIO'
                ORDER BY a.ordem ASC
                LIMIT 1
            ");
            $voltarAoInicio->execute(['id_perfil' => $idPerfil]);
            (new Historico($this->pdo))->registrar($idPerfil, 'PERFIL_REINICIADO',
                'O perfil voltou ao inicio: time, mochila, dinheiro e progresso foram reiniciados.');

            $this->pdo->commit();

        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }
}