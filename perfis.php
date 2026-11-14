<?php
declare(strict_types=1);

use Peakmon\Controllers\PerfilController;

require_once __DIR__ . '/app/autoload.php';
require_once __DIR__ . '/app/sessao.php';

$erro = '';
$perfis = [];

try {
    $pdo = require __DIR__ . '/config/database.php';

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
                    : 'mapa.php';
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

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #17252d;
            color: white;
            font-family: monospace;

            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        h1 {
            margin-bottom: 40px;
        }

        .perfis {
            display: flex;
            gap: 30px;
        }

        .perfil-form {
            margin: 0;
        }

        .perfil {
            width: 220px;
            height: 230px;
            background: #263d49;
            border: 3px solid #52616a;
            color: white;
            cursor: pointer;
            font-family: monospace;
            transition: 0.2s;
        }

        .perfil:hover {
            border-color: #8de0a5;
            transform: translateY(-5px);
        }

        .avatar {
            font-size: 60px;
        }

        .perfil h2 {
            margin-top: 15px;
        }

        .erro {
            color: #ff8585;
            margin-bottom: 20px;
        }

        @media(max-width: 550px) {
            .perfis {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

    <h1>ESCOLHA SEU PERFIL</h1>

    <?php if ($erro !== ''): ?>
        <p class="erro">
            <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <div class="perfis">

        <?php foreach ($perfis as $indice => $perfil): ?>

            <form class="perfil-form" method="POST" action="perfis.php">

                <input type="hidden" name="acao" value="selecionar">

                <input
                    type="hidden"
                    name="id_perfil"
                    value="<?= (int) $perfil['id'] ?>"
                >

                <button class="perfil" type="submit">

                    <div class="avatar">
                        <?= (int)($perfil['numero_perfil'] ?? ($indice + 1)) === 1 ? '🧢' : '⚔️' ?>
                    </div>

                    <h2>
                        <?= htmlspecialchars(
                            $perfil['nome_perfil'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </h2>

                    <p>Save independente</p>

                </button>

            </form>

        <?php endforeach; ?>

    </div>

</body>
</html>
