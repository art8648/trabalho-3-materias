<?php

declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

class Loja
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function listarItens(int $idPerfil): array
    {
        $consulta = $this->pdo->prepare("
            SELECT i.id, i.nome, i.descricao, i.preco, i.tipo_efeito, i.valor_efeito,
                   i.status_curado, COALESCE(ii.quantidade, 0) AS quantidade
            FROM item i
            LEFT JOIN inventario inv ON inv.id_perfil = :id_perfil
            LEFT JOIN inventario_item ii ON ii.id_inventario = inv.id AND ii.id_item = i.id
            WHERE i.ativo = TRUE
            ORDER BY i.preco ASC, i.nome ASC
        ");
        $consulta->execute(['id_perfil' => $idPerfil]);
        return $consulta->fetchAll();
    }

    public function listarInventario(int $idPerfil): array
    {
        $consulta = $this->pdo->prepare("
            SELECT i.id, i.nome, i.descricao, i.tipo_efeito, i.valor_efeito,
                   i.status_curado, ii.quantidade
            FROM inventario inv
            INNER JOIN inventario_item ii ON ii.id_inventario = inv.id
            INNER JOIN item i ON i.id = ii.id_item
            WHERE inv.id_perfil = :id_perfil AND ii.quantidade > 0
            ORDER BY i.nome ASC
        ");
        $consulta->execute(['id_perfil' => $idPerfil]);
        return $consulta->fetchAll();
    }

    public function comprar(int $idPerfil, int $idUsuario, int $idItem, int $quantidade): array
    {
        if ($idPerfil <= 0 || $idUsuario <= 0 || $idItem <= 0 || $quantidade < 1 || $quantidade > 99) {
            throw new DomainException('Dados da compra invalidos.');
        }
        $this->pdo->beginTransaction();
        try {
            $perfil = $this->pdo->prepare('SELECT id FROM perfil WHERE id = :id_perfil AND id_usuario = :id_usuario FOR UPDATE');
            $perfil->execute(['id_perfil' => $idPerfil, 'id_usuario' => $idUsuario]);
            if ($perfil->fetchColumn() === false) throw new DomainException('Perfil nao encontrado para esta conta.');

            $item = $this->pdo->prepare('SELECT nome, ativo FROM item WHERE id = :id_item LIMIT 1');
            $item->execute(['id_item' => $idItem]);
            $dadosItem = $item->fetch();
            if ($dadosItem === false || !(bool)$dadosItem['ativo']) throw new DomainException('Este item nao esta disponivel na loja.');

            // Os triggers trg_compra_loja_validar/aplicar/criar_log fazem as validacoes e atualizacoes.
            $compra = $this->pdo->prepare('INSERT INTO compra_loja (id_perfil, id_item, quantidade) VALUES (:id_perfil, :id_item, :quantidade)');
            $compra->execute(['id_perfil' => $idPerfil, 'id_item' => $idItem, 'quantidade' => $quantidade]);

            $saldo = $this->pdo->prepare('SELECT dinheiro FROM perfil WHERE id = :id_perfil');
            $saldo->execute(['id_perfil' => $idPerfil]);
            $dinheiro = (int)$saldo->fetchColumn();
            $this->pdo->commit();
            return ['sucesso' => true, 'mensagem' => $dadosItem['nome'].' comprado com sucesso!', 'dinheiro' => $dinheiro];
        } catch (\Throwable $erro) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($erro instanceof \PDOException && (($erro->errorInfo[0] ?? '') === '45000' || (string)$erro->getCode() === '45000')) {
                throw new DomainException($erro->errorInfo[2] ?? 'Nao foi possivel concluir a compra.', 0, $erro);
            }
            throw $erro;
        }
    }
}
