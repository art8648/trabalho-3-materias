<?php

declare(strict_types=1);

namespace Peakmon\Controllers;

use DomainException;
use PDO;
use Peakmon\Models\Captura;
use Peakmon\Models\Inicial;
use Peakmon\Models\Mapa;
use Peakmon\Models\Perfil;
use Peakmon\Models\SinalEstranho;
use Peakmon\Models\Time;

/**
 * Responsavel pelas acoes HTTP e pela preparacao dos dados da view mapa.php.
 * As regras de negocio/consultas continuam nos Models; a interface permanece
 * no mapa.php, preservando o JavaScript, o CSS e as animacoes existentes.
 */
final class MapaController
{
    private Mapa $mapa;
    private Inicial $inicial;
    private Time $time;
    private Captura $captura;
    private SinalEstranho $sinal;
    private Perfil $perfil;

    public function __construct(private readonly PDO $pdo)
    {
        // COMPOSICAO: controlador constitui seus colaboradores e os gerencia.
        $this->mapa = new Mapa($pdo);
        $this->inicial = new Inicial($pdo);
        $this->time = new Time($pdo);
        $this->captura = new Captura($pdo);
        $this->sinal = new SinalEstranho($pdo);
        $this->perfil = new Perfil($pdo);
    }

    /** Acao POST: devolve dados para o mapa.php serializar como JSON. */
    public function processarAcao(int $idPerfil, int $idUsuario, array $post): array
    {
        $acao = (string) ($post['acao'] ?? '');

        if ($acao === 'escolher_inicial') {
            $idPokemon = filter_var($post['id_pokemon'] ?? null, FILTER_VALIDATE_INT);
            if ($idPokemon === false || $idPokemon === null) {
                throw new DomainException('Pokemon invalido.');
            }
            return $this->inicial->escolher($idPerfil, $idUsuario, (int) $idPokemon);
        }

        if ($acao === 'mover_time') {
            if (!empty($_SESSION['peakmon_batalha_interativa'][$idPerfil])) {
                throw new DomainException('Termine a batalha atual antes de mudar a equipe.');
            }
            $de = filter_var($post['de'] ?? null, FILTER_VALIDATE_INT);
            $para = filter_var($post['para'] ?? null, FILTER_VALIDATE_INT);
            if ($de === false || $de === null || $para === false || $para === null) {
                throw new DomainException('Posicao invalida.');
            }
            $this->time->mover($idPerfil, $idUsuario, (int) $de, (int) $para);
            return ['sucesso' => true, 'mensagem' => 'Time atualizado.'];
        }

        if ($acao === 'reiniciar_perfil') {
            $this->perfil->reiniciarEscolhas($idPerfil, $idUsuario);
            unset(
                $_SESSION['peakmon_batalha_interativa'][$idPerfil],
                $_SESSION['peakmon_sinal'][$idPerfil],
                $_SESSION['peakmon_sinal_resolvido'][$idPerfil]
            );
            foreach (array_keys($_SESSION['peakmon_encontro'] ?? []) as $chave) {
                if (str_starts_with((string) $chave, $idPerfil . ':')) {
                    unset($_SESSION['peakmon_encontro'][$chave]);
                }
            }
            // Perfil::reiniciarEscolhas ja zera o dinheiro dentro da transacao.
            // Uma segunda atualizacao aqui poderia recriar um evento no Diario.
            return [
                'sucesso' => true,
                'mensagem' => 'Perfil reiniciado.',
                'redirecionar' => 'menu%20inicial/selecao.html',
            ];
        }

        if (in_array($acao, ['sinal_dados', 'sinal_trocar', 'sinal_ignorar'], true)) {
            if ((int) ($_SESSION['peakmon_sinal'][$idPerfil] ?? 0) <= 0) {
                throw new DomainException('Nenhum sinal estranho esta ativo agora.');
            }
            if ($acao === 'sinal_dados') {
                return [
                    'sucesso' => true,
                    'meus' => $this->sinal->listarDoJogador($idPerfil, $idUsuario),
                    'especies' => $this->sinal->listarEspecies(),
                ];
            }
            if ($acao === 'sinal_ignorar') {
                $noSinal = (int) $_SESSION['peakmon_sinal'][$idPerfil];
                $_SESSION['peakmon_sinal_resolvido'][$idPerfil][$noSinal] = true;
                unset($_SESSION['peakmon_sinal'][$idPerfil]);
                return ['sucesso' => true, 'mensagem' => 'Voce ignorou o sinal.'];
            }
            $idCapturado = filter_var($post['id_capturado'] ?? null, FILTER_VALIDATE_INT);
            $idNovaEspecie = filter_var($post['id_especie'] ?? null, FILTER_VALIDATE_INT);
            if ($idCapturado === false || $idCapturado === null || $idNovaEspecie === false || $idNovaEspecie === null) {
                throw new DomainException('Escolha o seu Pokemon e a especie nova.');
            }
            $resultadoTroca = $this->sinal->trocar(
                $idPerfil, $idUsuario, (int) $idCapturado, (int) $idNovaEspecie
            );
            $noSinal = (int) $_SESSION['peakmon_sinal'][$idPerfil];
            $_SESSION['peakmon_sinal_resolvido'][$idPerfil][$noSinal] = true;
            unset($_SESSION['peakmon_sinal'][$idPerfil]);
            return ['sucesso' => true] + $resultadoTroca;
        }

        if (!$this->inicial->perfilTemInicial($idPerfil)) {
            throw new DomainException('Escolha seu Pokemon inicial antes de avancar no mapa.');
        }
        $idNoDestino = filter_var($post['id_no_destino'] ?? null, FILTER_VALIDATE_INT);
        if ($idNoDestino === false || $idNoDestino === null) {
            throw new DomainException('Ponto do mapa invalido.');
        }
        unset($_SESSION['peakmon_sinal'][$idPerfil]);
        $resultado = $this->mapa->avancar(
            $idPerfil, $idUsuario, (int) $idNoDestino,
            [$this->captura, 'capturarAleatorio']
        );
        if (($resultado['no']['tipo_no'] ?? '') === 'EVENTO') {
            $noSinal = (int) $resultado['no']['id'];
            unset($_SESSION['peakmon_sinal_resolvido'][$idPerfil][$noSinal]);
            $_SESSION['peakmon_sinal'][$idPerfil] = $noSinal;
            $resultado['evento'] = ['tipo' => 'SINAL_ESTRANHO'];
        }
        return $resultado;
    }

    /** Dados para a view HTML: as consultas ficam separadas da apresentacao. */
    public function carregarPagina(int $idPerfil, int $idUsuario, string $raizProjeto): array
    {
        $iniciais = [];
        $mostrarInicial = false;
        if (!$this->inicial->perfilTemInicial($idPerfil)) {
            $iniciais = $this->inicial->listar();
            $mostrarInicial = count($iniciais) > 0;
        }
        $slotsTime = $this->time->listar($idPerfil);
        $dados = $this->mapa->carregarMapa($idPerfil, $idUsuario);
        $perfilAtual = $this->perfil->buscarPorIdDoUsuario($idPerfil, $idUsuario);
        $personagemNome = $perfilAtual['personagem_nome'] ?? null;

        $roleta = $this->captura->listarRoleta();
        $roletaItens = $this->mapa->listarRoletaItens();
        $arquivosItens = [
            'Potion' => 'potion.jpg', 'Super Potion' => 'super_potion.webp',
            'Hyper Potion' => 'hyper_potion.webp', 'Max Potion' => 'max_potion.webp',
            'Full Restore' => 'full_restore.jpg', 'Antidote' => 'antidote.webp',
            'Burn Heal' => 'burn_heal.webp', 'Ice Heal' => 'ice_heal.webp',
            'Awakening' => 'awakening.webp', 'Paralyze Heal' => 'paralyze_heal.webp',
            'Full Heal' => 'full_heal.webp', 'Revive' => 'revive.webp',
            'Pedra Universal' => 'pedra_universal.png',
        ];
        foreach ($roletaItens as &$itemRoleta) {
            $arquivo = $arquivosItens[$itemRoleta['nome']] ?? '';
            $itemRoleta['imagem'] = ($arquivo !== '' && is_file($raizProjeto . '/images/itens/' . $arquivo))
                ? 'images/itens/' . $arquivo : '';
        }
        unset($itemRoleta);

        return compact('mostrarInicial', 'personagemNome', 'iniciais', 'slotsTime', 'dados', 'roleta', 'roletaItens');
    }
}
