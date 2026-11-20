<?php

declare(strict_types=1);

namespace Peakmon\Controllers;

use DomainException;
use PDO;
use Peakmon\Models\Mochila;
use Peakmon\Models\Time;

/** Orquestra a Mochila; a classe Model continua sendo dona das regras e transacoes. */
final class MochilaController
{
    private Mochila $mochila;
    private Time $time;

    public function __construct(PDO $pdo, ?Mochila $mochila = null, ?Time $time = null)
    {
        $this->mochila = $mochila ?? new Mochila($pdo);
        $this->time = $time ?? new Time($pdo);
    }

    public function processarAcao(int $idPerfil, int $idUsuario, string $csrf, array $post): array
    {
        if (!hash_equals($csrf, (string) ($post['csrf'] ?? ''))) {
            throw new DomainException('A solicitacao expirou. Atualize a pagina.', 403);
        }
        // Bloqueia mudancas simultaneas em outra aba durante batalha interativa.
        if (!empty($_SESSION['peakmon_batalha_interativa'][$idPerfil])) {
            throw new DomainException('Ha uma batalha em andamento. Use os itens na tela de batalha ou termine a luta.');
        }
        $id = static function (string $campo) use ($post): int {
            $valor = filter_var($post[$campo] ?? null, FILTER_VALIDATE_INT);
            if ($valor === false || $valor === null || $valor <= 0) {
                throw new DomainException('Dados invalidos. Atualize a pagina e tente novamente.');
            }
            return (int) $valor;
        };

        switch ((string) ($post['acao'] ?? '')) {
            case 'usar_item':
                $mensagem = $this->mochila->usarItem($idPerfil, $idUsuario, $id('id_item'), $id('id_capturado'));
                return ['sucesso' => true, 'mensagem' => $mensagem];
            case 'trocar_reserva':
                $this->mochila->trocarComReserva($idPerfil, $idUsuario, $id('id_capturado'), $id('posicao'));
                return ['sucesso' => true, 'mensagem' => 'Equipe atualizada! Os integrantes foram deslocados para abrir espaço.'];
            case 'enviar_reserva':
                $this->mochila->enviarParaReserva($idPerfil, $idUsuario, $id('posicao'));
                return ['sucesso' => true, 'mensagem' => 'Pokemon enviado para o Arquivo Bigolin.'];
            case 'mover_time':
                $this->time->mover($idPerfil, $idUsuario, $id('de'), $id('para'));
                return ['sucesso' => true, 'mensagem' => 'Ordem da equipe atualizada!'];
            case 'listar_evolucoes':
                return ['sucesso' => true, 'evolucoes' => $this->mochila->listarEvolucoes($idPerfil, $idUsuario, $id('id_capturado'))];
            case 'evoluir':
                $mensagem = $this->mochila->evoluirComPedra($idPerfil, $idUsuario, $id('id_capturado'), $id('id_destino'));
                return ['sucesso' => true, 'mensagem' => $mensagem];
            default:
                throw new DomainException('Acao desconhecida.');
        }
    }

    public function prepararPagina(int $idPerfil, int $idUsuario): array
    {
        $itens = $this->mochila->listarItens($idPerfil, $idUsuario);
        $equipe = $this->mochila->listarEquipe($idPerfil, $idUsuario);
        $reserva = $this->mochila->listarReserva($idPerfil, $idUsuario);
        $todosPokemon = array_values(array_filter($equipe, static fn ($pk) => $pk !== null));
        foreach ($reserva as $pokemon) {
            $todosPokemon[] = $pokemon;
        }
        usort($todosPokemon, static fn (array $a, array $b): int => strcasecmp((string) $a['nome'], (string) $b['nome']));
        return compact('itens', 'equipe', 'reserva', 'todosPokemon');
    }
}
