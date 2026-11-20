<?php

declare(strict_types=1);

namespace Peakmon\Controllers;

use DomainException;
use PDO;
use Peakmon\Models\Batalha;
use Peakmon\Models\Mochila;

/** Controla as acoes HTTP da batalha; deixa as regras no Model e a view no PHP. */
final class BatalhaController
{
    private Batalha $batalha;
    private Mochila $mochila;

    public function __construct(private readonly PDO $pdo, ?Batalha $batalha = null, ?Mochila $mochila = null)
    {
        // AGREGACAO: modelos podem ser fornecidos de fora (injeção) e vivem independentemente deste Controller.
        $this->batalha = $batalha ?? new Batalha($pdo);
        $this->mochila = $mochila ?? new Mochila($pdo);
    }

    public function executarAcao(int $idPerfil, int $idUsuario, string $csrf, array $post): array
    {
        if (!hash_equals($csrf, (string) ($post['csrf'] ?? ''))) {
            throw new DomainException('A solicitacao expirou. Atualize a pagina.', 403);
        }

        $acao = (string) ($post['acao'] ?? '');
        if ($acao === 'iniciar') {
            $resultado = $this->batalha->iniciarTurnos($idPerfil, $idUsuario);
        } elseif ($acao === 'rodada') {
            $resultado = $this->batalha->executarTurno($idPerfil, $idUsuario);
        } elseif ($acao === 'item') {
            $idItem = filter_var($post['id_item'] ?? null, FILTER_VALIDATE_INT);
            $idAlvo = filter_var($post['id_capturado'] ?? null, FILTER_VALIDATE_INT);
            if (!$idItem || !$idAlvo || $idItem < 1 || $idAlvo < 1) {
                throw new DomainException('Selecione um item e um Pokemon do time.');
            }
            $resultado = $this->batalha->executarTurno($idPerfil, $idUsuario, (int) $idItem, (int) $idAlvo);
        } else {
            throw new DomainException('Acao invalida.');
        }

        $resultado['itens'] = $this->mochila->listarItens($idPerfil, $idUsuario);
        return $resultado;
    }

    public function prepararPagina(int $idPerfil, int $idUsuario): array
    {
        $previa = $this->batalha->prepararEncontro($idPerfil, $idUsuario);
        $consulta = $this->pdo->prepare('SELECT nome_perfil, dinheiro FROM perfil WHERE id = :id AND id_usuario = :usuario LIMIT 1');
        $consulta->execute(['id' => $idPerfil, 'usuario' => $idUsuario]);
        $perfil = $consulta->fetch() ?: null;
        return ['previa' => $previa, 'perfil' => $perfil];
    }
}
