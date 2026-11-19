# Peakmon — revisão de interface e jogabilidade

Base examinada: `trabalho-3-materias(20261119-012350).zip`.

## O que foi alterado

1. **Centro Pokémon (mapa.php):** visual refeito em estilo pixel-art, máquina com letreiro, scanner, luzes, barra de progresso e brilho. A quantidade de Pokébolas na máquina continua sendo exatamente a quantidade enviada pelo servidor, no máximo cinco (somente equipe ativa). A lógica de cura do banco NÃO foi substituída.
2. **Inicial (mapa.php):** nenhum card inicia selecionado. O hover agora é discreto e não parece uma confirmação; apenas a classe `.selecionado` dá o destaque colorido. O servidor continua salvando o Pokémon confirmado.
3. **Itens em batalha:** a funcionalidade já existia no ZIP (batalha.php e app/Models/Batalha.php). Foi preservada; nenhuma mudança de regra de item foi necessária.
4. **Roleta (app/Models/Captura.php):** evita as cinco espécies capturadas mais recentemente (em vez de quatro), quando há alternativas. A preferência por inéditos passou de 70% para 85%.
5. **Status:** a mecânica já estava implementada (15% em golpes de Fogo, Gelo, Elétrico, Veneno e Psíquico; dano residual, perda de turno, cura por item). Foi mantida.
6. **Imunidade mútua (app/Models/Batalha.php):** quando ambos os ataques são imunes, após três rodadas contra o mesmo par, os dois passam a usar a **Investida de Emergência** com dano neutro. A mudança vale para o combate por turnos usado na tela e para a simulação antiga. O desbloqueio fica registrado em `log_batalha` como `IMPASSE`.
7. **Área 2 após Hiago:** Mapa e Batalha agora escolhem a área desbloqueada mais avançada do perfil, em vez de sempre escolher a primeira. O mapa reconstrói desbloqueios quando a Área 2 foi cadastrada depois da vitória já registrada em `area_concluida`, sem liberar a área para quem não venceu Hiago. O reinício do perfil passa a apagar o progresso de todas as áreas e restaurar somente a primeira. A tela de vitória anuncia a Área 2 após Hiago.

Arquivos alterados:
- `mapa.php`
- `batalha.php`
- `app/Models/Mapa.php`
- `app/Models/Batalha.php`
- `app/Models/Captura.php`
- `app/Models/Perfil.php`

## O que não fiz

- **Não executei os arquivos PHP, JS ou SQL do projeto.** A revisão e edição foram feitas com leitura estática dos arquivos.
- **Não alterei o banco de dados existente, nenhum trigger ou view.**
- **Não fiz commit ou push no GitHub.**
- A integração com o MySQL do seu computador ainda precisa ser verificada por vocês.

## Como aplicar sem quebrar o Git

Faça backup da pasta local e do banco antes de copiar os arquivos. Use o ZIP de correções para substituir somente os seis arquivos acima, conservando a estrutura de diretórios, ou use o ZIP completo numa pasta separada. **Os ZIPs entregues não contêm a pasta `.git`**, justamente para não sobrescrever seu histórico e configuração de Git.

**Não reimporte `peakmon_base.sql` por cima de um banco com progresso:** a primeira linha desse arquivo é `DROP DATABASE IF EXISTS peakmon_bigolin_crisis`.

## Checklist de teste no XAMPP/MySQL

1. Abra o mapa de um perfil novo e confirme que Treecko, Torchic e Mudkip aparecem acinzentados; ao clicar em um, só ele deve acender.
2. Faça capturas sucessivas e verifique se as espécies recentes raramente voltam a aparecer; o sorteio é probabilístico.
3. Visite o Centro com 1, 3 e 5 Pokémon no time. Veja se aparecem exatamente 1, 3 e 5 Pokébolas; confira no banco ou Mochila o HP e os status recuperados.
4. Compre Potion, Burn Heal, Ice Heal, Paralyze Heal e Revive. Durante uma batalha, pause, use cada item em situação compatível e confira a quantidade no inventário. Teste item inválido para confirmar que não seja consumido.
5. Teste duas espécies cujos golpes são imunes mutuamente. Após três turnos, a Investida de Emergência deve causar dano, aparecer no log e permitir que a batalha termine.
6. Derrote Hiago, confirme a recompensa de duas Pedras Universais e clique em Continuar. O mapa deverá mostrar a Área 2, com Bigolin no ponto final.
7. Reinicie somente um perfil. O outro deve conservar o progresso; o reiniciado deve voltar à primeira área e não manter a segunda desbloqueada.

## Requisitos técnicos ainda a verificar na apresentação

Pelo material dos professores, o projeto ainda precisa **demonstrar claramente** herança de tipos em PHP, polimorfismo, sobrecarga de métodos e organização MVC. A estrutura atual de Model/Controller e páginas misturadas não comprova tudo automaticamente. A montagem do time existe, mas está integrada ao mapa/Mochila, não em uma página `team-builder.php` separada.

O prazo do documento dos professores é 22/11/2026 para o último commit. Confiram as exigências com eles e evitem commits após esse prazo.
