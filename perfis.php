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
    
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    padding: 32px 16px;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    color: #fff;
    font-family: Arial, sans-serif;

    background:
        radial-gradient(circle at 50% 0%, rgba(141, 224, 165, .18), transparent 55%),
        linear-gradient(135deg, #10202a 0%, #17252d 50%, #1b3a33 100%);
}

h1 {
    margin: -25px 0 22px;

    font-size: clamp(28px, 5vw, 44px);
    letter-spacing: 5px;
    text-align: center;

    text-shadow: 0 4px 0 rgba(0, 0, 0, .45);
}

.jogador {
    margin: 0 0 42px;
    color: #a9bcc5;
    font-size: 17px;
    text-align: center;
}

.jogador strong {
    color: #fff;
}

.sair-conta {
    margin-top: 30px;
    text-align: center;
}

.sair-conta a {
    color: #8de0a5;
    font-size: 14px;
    text-decoration: none;
    border-bottom: 1px dashed currentColor;
    transition: .3s;
}

.sair-conta a:hover {
    color: #fff;
}

.erro {
    margin: 0 0 24px;
    padding: 10px 18px;
    color: #ffb3b3;
    background: rgba(255, 90, 90, .12);
    border: 2px solid #ff8585;
    border-radius: 8px;
}

.perfis {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 32px;
}

.perfil-form {
    margin: 0;
}

.perfil {
    width: 400px;
    padding: 28px 20px 24px;

    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;

    color: #fff;
    font-family: Arial, sans-serif;
    cursor: pointer;

    background: linear-gradient(180deg, #2d4856, #223742);
    border: 3px solid #52616a;
    border-radius: 10px;

    box-shadow: 0 6px 0 #0c161b, 0 14px 28px rgba(0, 0, 0, .35);

    transition: transform .2s, border-color .2s, box-shadow .2s;
}

.perfil:hover,
.perfil:focus-visible {
    transform: translateY(-6px);
    border-color: #8de0a5;
    box-shadow: 0 10px 0 #0c161b, 0 0 24px rgba(141, 224, 165, .35);
    outline: none;
}

.perfil:active {
    transform: translateY(-2px);
}

.avatar {
    width: 130px;
    height: 130px;

    display: grid;
    place-items: center;

    overflow: hidden;

    background: rgba(0, 0, 0, .28);
    border: 3px solid #8de0a5;
    border-radius: 50%;
}
.avatar img {
    width: 100%;
    height: 100%;

    object-fit: cover;
    border-radius: 50%;

    display: block;
}

.perfil h2 {
    margin: 6px 0 0;
    font-size: 22px;
}

.perfil p {
    margin: 0;
    font-size: 14px;
    color: #a9bcc5;
}

.entrar {
    margin-top: 8px;
    padding: 6px 16px;

    font-size: 14px;
    font-weight: 700;
    letter-spacing: 2px;

    color: #17252d;
    background: #8de0a5;
    border-radius: 6px;
}

@media (max-width: 550px) {
    .perfis {
        flex-direction: column;
        align-items: center;
    }

    h1 {
        font-size: 27px;
    }
}

    </style>
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

            <form class="perfil-form" method="POST" action="perfis.php">

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
    <a href="logout.php">Sair da conta</a>
</div>
</body>
</html>
