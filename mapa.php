<?php
declare(strict_types=1);
require_once __DIR__ . '/app/autoload.php';
require_once __DIR__ . '/app/sessao.php';

use Peakmon\Models\Mapa;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = require __DIR__ . '/config/database.php';

// Recupera os dados da sessão
$idUsuario = (int) ($_SESSION['id_usuario'] ?? 0);
$idPerfil = (int) ($_SESSION['id_perfil'] ?? 0);

if ($idUsuario <= 0 || $idPerfil <= 0) {
    header('Location: login.php');
    exit;
}

$erro = null;
$dadosMapa = null;

try {
    $mapa = new Mapa($pdo);
    $dadosMapa = $mapa->carregarMapa($idPerfil, $idUsuario);
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peakmon - Mapa</title>
</head>
<body>

    <h1>Peakmon: The Bigolin Crisis</h1>

    <?php if ($erro !== null): ?>
        <p style="color: red;">
            <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
        </p>

    <?php elseif ($dadosMapa['area'] === null): ?>
        <p>Nenhuma área foi cadastrada ainda.</p>

    <?php else: ?>
        <h2>
            <?= htmlspecialchars($dadosMapa['area']['nome'], ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <p>
            Perfil:
            <?= htmlspecialchars($dadosMapa['perfil']['nome_perfil'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        <p>
            Dinheiro:
            <?= htmlspecialchars((string) $dadosMapa['perfil']['dinheiro'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        <h3>Mapa</h3>

        <?php if (empty($dadosMapa['nos'])): ?>
            <p>Nenhum ponto foi cadastrado nesta área.</p>
        <?php else: ?>
            <?php foreach ($dadosMapa['nos'] as $no): ?>
                <div>
                    <strong>
                        <?= htmlspecialchars($no['titulo'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <p>
                        <?= htmlspecialchars($no['descricao'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <small>
                        Tipo: <?= htmlspecialchars($no['tipo_no'], ENT_QUOTES, 'UTF-8') ?>
                    </small>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

</body>
</html>
