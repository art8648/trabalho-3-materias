<?php
declare(strict_types=1);

namespace Peakmon\Models;

use DomainException;
use PDO;

/** Eventos narrativos do save; as acoes de cada turno ficam em log_batalha. */
final class Historico
{
    public function __construct(private PDO $pdo) {}

    /** Pode ser chamado dentro da transacao da acao: falhar faz rollback do conjunto. */
    public function registrar(int $idPerfil, string $tipo, string $descricao): void
    {
        if ($idPerfil <= 0 || !preg_match('/^[A-Z0-9_]{1,40}$/', $tipo)) {
            throw new DomainException('Evento de historico invalido.');
        }
        $consulta = $this->pdo->prepare(''
            . 'INSERT INTO log_perfil (id_usuario, id_perfil, tipo_evento, descricao) '
            . 'SELECT p.id_usuario, p.id, :tipo, :descricao FROM perfil p WHERE p.id = :perfil');
        $consulta->execute([
            'perfil' => $idPerfil,
            'tipo' => $tipo,
            'descricao' => $descricao,
        ]);
        if ($consulta->rowCount() !== 1) {
            throw new DomainException('Perfil nao encontrado para registrar o historico.');
        }
    }
}
