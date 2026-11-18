<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;
use PDOException;

class Loja
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Retorna os itens ativos e o saldo do perfil. */
    public function carregarLoja(int $idPerfil, int $idUsuario): array
    {
        $this->validarPerfil($idPerfil, $idUsuario);

        $saldo = $this->pdo->prepare('SELECT dinheiro FROM perfil WHERE id = :perfil AND id_usuario = :usuario');
        $saldo->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        $dinheiro = $saldo->fetchColumn();
        if ($dinheiro === false) {
            throw new DomainException('Perfil nao encontrado.');
        }

        $consulta = $this->pdo->query('
            SELECT id, nome, descricao, preco, tipo_efeito, valor_efeito, status_curado
            FROM item
            WHERE ativo = TRUE
            ORDER BY tipo_efeito, preco, nome
        ');

        return [
            'dinheiro' => (int) $dinheiro,
            'itens' => $consulta->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Registra a compra. Os triggers do banco validam o saldo, descontam o valor,
     * adicionam os itens ao inventario e registram o log da loja.
     */
    public function comprar(int $idPerfil, int $idUsuario, int $idItem, int $quantidade): array
    {
        if ($idItem <= 0) {
            throw new DomainException('Item invalido.');
        }
        if ($quantidade < 1 || $quantidade > 99) {
            throw new DomainException('Escolha uma quantidade entre 1 e 99.');
        }

        $this->pdo->beginTransaction();
        try {
            $perfil = $this->pdo->prepare('
                SELECT id FROM perfil
                WHERE id = :perfil AND id_usuario = :usuario
                FOR UPDATE
            ');
            $perfil->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
            if ($perfil->fetchColumn() === false) {
                throw new DomainException('Perfil nao encontrado para este usuario.');
            }

            $item = $this->pdo->prepare('
                SELECT nome, preco FROM item
                WHERE id = :item AND ativo = TRUE
            ');
            $item->execute(['item' => $idItem]);
            $dadosItem = $item->fetch(PDO::FETCH_ASSOC);
            if (!$dadosItem) {
                throw new DomainException('Este item nao esta disponivel na loja.');
            }

            $total = (int) $dadosItem['preco'] * $quantidade;

            // A insercao dispara os tres triggers de compra ja existentes no banco.
            $compra = $this->pdo->prepare('
                INSERT INTO compra_loja (id_perfil, id_item, quantidade)
                VALUES (:perfil, :item, :quantidade)
            ');
            $compra->execute([
                'perfil' => $idPerfil,
                'item' => $idItem,
                'quantidade' => $quantidade,
            ]);

            $saldoConsulta = $this->pdo->prepare('SELECT dinheiro FROM perfil WHERE id = :perfil');
            $saldoConsulta->execute(['perfil' => $idPerfil]);
            $saldoAtual = (int) $saldoConsulta->fetchColumn();

            $this->pdo->commit();

            return [
                'mensagem' => $quantidade . 'x ' . $dadosItem['nome'] . ' comprado(s) com sucesso!',
                'total' => $total,
                'dinheiro' => $saldoAtual,
            ];
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($erro instanceof PDOException) {
                $mensagem = $erro->getMessage();
                if (stripos($mensagem, 'Saldo insuficiente') !== false) {
                    throw new DomainException('Voce nao tem Pokédollars suficientes para essa compra.');
                }
                if (stripos($mensagem, 'nao esta disponivel') !== false || stripos($mensagem, 'não está disponível') !== false) {
                    throw new DomainException('Este item nao esta disponivel na loja.');
                }
            }
            throw $erro;
        }
    }

    private function validarPerfil(int $idPerfil, int $idUsuario): void
    {
        $consulta = $this->pdo->prepare('SELECT id FROM perfil WHERE id = :perfil AND id_usuario = :usuario LIMIT 1');
        $consulta->execute(['perfil' => $idPerfil, 'usuario' => $idUsuario]);
        if ($consulta->fetchColumn() === false) {
            throw new DomainException('Perfil nao encontrado para este usuario.');
        }
    }
}
