# trabalho-3-materias
Um breve e pequeno começo

## Sistema de batalha (estilo Pokelike)

- `batalha.php` — tela de batalha: ordem do time -> **Lutar!** -> arena animada (1x/2x/4x e Pular) -> resultado.
- `app/Models/Batalha.php` — motor. A luta inteira é simulada **no servidor** (o navegador só anima) e gravada em `batalha` / `log_batalha`.
- Integração: ao chegar num nó `BATALHA`/`CHEFE` o mapa leva para `batalha.php`. A vitória registrada libera os próximos caminhos; ouro e Pedras do chefe vêm dos triggers do banco.
- Derrota: o time é curado (Centro Pokémon) e o jogador pode tentar de novo contra outro adversário (`Batalha::CURAR_TIME_AO_PERDER`).
- Dificuldade: escala pela profundidade do nó e pela força do time. Ajuste os multiplicadores em `Batalha::gerarEncontro()`.
- "Reiniciar perfil" agora também apaga o histórico de batalhas do perfil.
