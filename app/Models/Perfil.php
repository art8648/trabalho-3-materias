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
     * Reinicia somente o perfil escolhido, inclusive todo o seu historico.
     * Os registros do outro perfil e a conta do usuario permanecem intactos.
     * Tudo acontece em uma unica transacao, para nao deixar o save pela metade.
     */
    public function reiniciarEscolhas(int $idPerfil, int $idUsuario): void
    {
        $this->pdo->beginTransaction();

        try {
            // O perfil precisa pertencer ao usuario autenticado.
            $consulta = $this->pdo->prepare('
                SELECT id FROM perfil
                WHERE id = :id_perfil AND id_usuario = :id_usuario
                LIMIT 1
                FOR UPDATE
            ');
            $consulta->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario,
            ]);

            if ($consulta->fetchColumn() === false) {
                throw new DomainException('Perfil nao encontrado.');
            }

            $parametros = ['id_perfil' => $idPerfil];

            // Apaga os itens, mas mantem o inventario do perfil para a nova jornada.
            $limparItens = $this->pdo->prepare('
                DELETE ii
                FROM inventario_item ii
                INNER JOIN inventario i ON i.id = ii.id_inventario
                WHERE i.id_perfil = :id_perfil
            ');
            $limparItens->execute($parametros);

            // Respeita as chaves estrangeiras antes de remover as batalhas.
            // Os turnos em log_batalha sao apagados automaticamente por CASCADE.
            foreach (['capture_attempts', 'area_concluida', 'batalha'] as $tabela) {
                // Nomes fixos do codigo, nunca recebidos do formulario.
                $limpar = $this->pdo->prepare("DELETE FROM $tabela WHERE id_perfil = :id_perfil");
                $limpar->execute($parametros);
            }

            // Limpa as compras e seus logs, sem tocar nas compras do outro perfil.
            // log_loja tambem possui ON DELETE CASCADE, mas apagamos explicitamente.
            $limparLogLoja = $this->pdo->prepare('
                DELETE FROM log_loja WHERE id_perfil = :id_perfil
            ');
            $limparLogLoja->execute($parametros);

            $limparCompras = $this->pdo->prepare('
                DELETE FROM compra_loja WHERE id_perfil = :id_perfil
            ');
            $limparCompras->execute($parametros);

            // A tabela de arquivo de jornadas e opcional, de uma migracao antiga.
            // Caso exista, elimina tambem todos os turnos antigos desse perfil.
            $temArquivo = $this->pdo->query(
                "SHOW TABLES LIKE 'historico_batalha_arquivo'"
            )->fetchColumn() !== false;
            if ($temArquivo) {
                $limparArquivo = $this->pdo->prepare('
                    DELETE FROM historico_batalha_arquivo WHERE id_perfil = :id_perfil
                ');
                $limparArquivo->execute($parametros);
            }

            // Apaga os Pokemon; a ocupacao do time e limpa por cascata.
            $limparPokemon = $this->pdo->prepare('
                DELETE FROM pokemon_capturado WHERE id_perfil = :id_perfil
            ');
            $limparPokemon->execute($parametros);

            $limparPerfil = $this->pdo->prepare('
                UPDATE perfil
                SET id_personagem = NULL,
                    dinheiro = 0,
                    jogo_concluido = 0,
                    data_conclusao = NULL
                WHERE id = :id_perfil AND id_usuario = :id_usuario
            ');
            $limparPerfil->execute([
                'id_perfil' => $idPerfil,
                'id_usuario' => $idUsuario,
            ]);

            // Reseta as areas, bloqueando novamente os capitulos seguintes.
            $limparProgresso = $this->pdo->prepare('
                DELETE FROM progresso_area WHERE id_perfil = :id_perfil
            ');
            $limparProgresso->execute($parametros);

            $voltarAoInicio = $this->pdo->prepare("
                INSERT INTO progresso_area
                    (id_perfil, id_area, id_no_atual, estado, data_inicio)
                SELECT :id_perfil, a.id, n.id, 'DISPONIVEL', CURRENT_TIMESTAMP
                FROM area_jogo a
                INNER JOIN area_no n ON n.id_area = a.id AND n.tipo_no = 'INICIO'
                ORDER BY a.ordem ASC
                LIMIT 1
            ");
            $voltarAoInicio->execute($parametros);

            // POR ULTIMO: o UPDATE de perfil pode disparar um trigger que insere
            // PERFIL_ATUALIZADO. Limpar aqui garante Diario vazio ao reiniciar.
            // Nao registrar PERFIL_REINICIADO: usuario quer apagar TODOS os logs.
            $limparEventos = $this->pdo->prepare('
                DELETE FROM log_perfil WHERE id_perfil = :id_perfil
            ');
            $limparEventos->execute($parametros);

            $this->pdo->commit();
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $erro;
        }
    }
}
