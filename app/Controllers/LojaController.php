<?php

declare(strict_types=1);

namespace Peakmon\Controllers;

use DomainException;
use PDO;
use Peakmon\Models\Loja;

/** Valida requisicoes HTTP da loja; regras de saldo/estoque continuam no Model e triggers. */
final class LojaController
{
    private Loja $loja;

    public function __construct(PDO $pdo, ?Loja $loja = null)
    {
        $this->loja = $loja ?? new Loja($pdo);
    }

    public function processarAcao(int $idPerfil, int $idUsuario, string $csrf, array $post): array
    {
        if (!hash_equals($csrf, (string) ($post['csrf'] ?? ''))) {
            throw new DomainException('A solicitacao expirou. Atualize a pagina.', 403);
        }
        if (($post['acao'] ?? '') !== 'comprar') {
            throw new DomainException('Acao desconhecida.');
        }
        $idItem = filter_var($post['id_item'] ?? null, FILTER_VALIDATE_INT);
        $quantidade = filter_var($post['quantidade'] ?? null, FILTER_VALIDATE_INT);
        if ($idItem === false || $idItem === null || $quantidade === false || $quantidade === null) {
            throw new DomainException('Dados da compra invalidos.');
        }
        return ['sucesso' => true] + $this->loja->comprar($idPerfil, $idUsuario, (int) $idItem, (int) $quantidade);
    }

    public function prepararPagina(int $idPerfil, int $idUsuario): array
    {
        return $this->loja->carregarLoja($idPerfil, $idUsuario);
    }
}
