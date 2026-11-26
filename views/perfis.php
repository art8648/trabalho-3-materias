<?php
declare(strict_types=1);

if (!defined('PEAKMON_ROOT')) {
    http_response_code(404);
    exit;
}


use Peakmon\Controllers\PerfilController;

require PEAKMON_ROOT . '/app/autoload.php';
require PEAKMON_ROOT . '/app/sessao.php';

$erro = '';
$perfis = [];

try {
    $pdo = require PEAKMON_ROOT . '/config/database.php';

    $controller = new PerfilController($pdo);

    $idUsuario = (int) $_SESSION['usuario_id'];

    // Recebe a seleção de perfil nesta mesma página
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['acao'] ?? '') === 'selecionar') {

        $idPerfil = filter_var(
            $_POST['id_perfil'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($idPerfil === false || $idPerfil === null) {
            $erro = 'Perfil inválido.';
        } else {
            $resultado = $controller->selecionar(
                $idPerfil,
                $idUsuario
            );

            if ($resultado['sucesso']) {
                // Save novo (sem personagem) vai escolher; save que ja escolheu vai direto ao mapa
                $destino = empty($resultado['perfil']['id_personagem'])
                     ? 'menu%20inicial/selecao.html'
                    : 'index.php?pagina=mapa';
                header('Location: ' . $destino);
            exit;
            }

            $erro = $resultado['mensagem'];
        }
    }

    // Busca os perfis pertencentes ao usuário
    $resultadoLista = $controller->listar($idUsuario);

    if (!$resultadoLista['sucesso']) {
        $erro = $resultadoLista['mensagem'];
    } else {
        $perfis = $resultadoLista['perfis'];
    }

} catch (Throwable $e) {
    error_log($e->getMessage());
    $erro = 'Não foi possível carregar os perfis.';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Escolher Perfil</title>

<link rel="stylesheet" href="assets/css/perfis.css">
</head>

<body>

    <h1>ESCOLHA SEU PERFIL</h1>
    <p class="jogador">
        Jogador: <strong><?= htmlspecialchars((string) ($_SESSION['usuario_nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
    </p>

    <?php if ($erro !== ''): ?>
        <p class="erro">
            <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <div class="perfis">

        <?php foreach ($perfis as $indice => $perfil): ?>

            <form class="perfil-form" method="POST" action="index.php?pagina=perfis">

                <input type="hidden" name="acao" value="selecionar">

                <input
                    type="hidden"
                    name="id_perfil"
                    value="<?= (int) $perfil['id'] ?>"
                >

                <button class="perfil" type="submit">
                    <div class="avatar">
                        <?= (int)($perfil['numero_perfil'] ?? ($indice + 1)) === 1 ? '<img src="menu inicial/images/bananinha.png" alt="Bananinha">' : '<img src="menu inicial/images/3xT.png" alt="3xT">' ?>
                    </div>
                    <h2><?= htmlspecialchars($perfil['nome_perfil'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p>Save independente</p>
                    <span class="entrar">JOGAR</span>
                    </button>

            </form>

        <?php endforeach; ?>

    </div>
    <div class="sair-conta">
    <a href="index.php?pagina=logout">Sair da conta</a>
</div>
</body>
</html>
