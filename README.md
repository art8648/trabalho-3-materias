# PEAKMON: THE BIGOLIN CRISIS

## Sobre o projeto

**Peakmon: The Bigolin Crisis** é um jogo web inspirado em Pokémon, desenvolvido como trabalho interdisciplinar das disciplinas de **Programação Orientada a Objetos I, Linguagens para a Web II e Banco de Dados II**, do Instituto Federal do Rio Grande do Sul (IFRS) — Campus Canoas.

O jogador embarca em uma aventura por diferentes áreas, enfrentando Pokémon selvagens, treinadores e chefes. Durante a jornada, é possível capturar novos Pokémon, organizar a equipe, evoluir espécies, comprar itens e participar de batalhas por turnos.

O projeto foi desenvolvido utilizando PHP orientado a objetos, MySQL, HTML, CSS e JavaScript, com aplicação da arquitetura MVC e de conceitos de programação orientada a objetos.

## Funcionalidades

### Sistema de usuários e perfis

- Cadastro e autenticação de usuários.
- Criação automática de dois perfis por usuário.
- Seleção e alternância entre perfis.
- Escolha de personagens jogáveis.
- Seleção do Pokémon inicial.
- Salvamento independente do progresso de cada perfil.
- Reinício individual dos perfis.

### Mapa e exploração

- Duas áreas com caminhos e pontos interativos.
- Exploração de diferentes rotas.
- Encontros com Pokémon selvagens e treinadores.
- Batalhas contra chefes, incluindo Hiago e Bigolin.
- Pontos de captura e itens surpresa.
- Centro Pokémon para recuperação da equipe.
- Evento especial chamado **Sinal Estranho**, que permite trocar um Pokémon por outra espécie disponível.
- Liberação de novas áreas conforme o progresso do jogador.

### Sistema de batalha

As batalhas acontecem por turnos e são processadas no servidor PHP.

O jogador pode utilizar seus Pokémon e itens durante o combate, enquanto o sistema calcula os resultados com base nos atributos dos participantes.

Entre as mecânicas implementadas estão:

- Ataques físicos e especiais.
- Cálculo de dano com base nos atributos.
- Efetividade, resistência e imunidade entre tipos.
- Golpes críticos.
- Condições negativas, como queimadura, congelamento e paralisia.
- Utilização de itens de cura e de status durante as batalhas.
- Troca de Pokémon conforme as regras de combate.
- Recompensas em Pokédollars após vitórias.
- Registro dos acontecimentos de cada turno no banco de dados.
- Animações de combate com opções de velocidade e avanço.

O sistema também possui mecanismos para evitar batalhas infinitas causadas por imunidades entre os Pokémon.

### Captura de Pokémon

Após determinados encontros, o jogador pode participar de uma roleta de captura.

O resultado é determinado pelo servidor e apresentado por meio de uma animação interativa.

Os Pokémon capturados passam a integrar a coleção do perfil e podem ser utilizados na equipe ou armazenados no Arquivo Bigolin.

### Mochila e montagem do time

A Mochila funciona também como o **Team Builder** do jogo.

Nela, o jogador pode:

- Visualizar seus Pokémon e itens.
- Organizar a ordem dos integrantes da equipe.
- Trocar Pokémon do time por outros armazenados.
- Guardar e recuperar Pokémon pelo Arquivo Bigolin.
- Utilizar itens de recuperação.
- Evoluir Pokémon com a Pedra Universal.
- Consultar as evoluções disponíveis de acordo com a espécie e o sexo.

A equipe possui cinco posições normais. A sexta posição é reservada para um acontecimento especial da história envolvendo Rayquaza.

### Loja Pokémon

A loja permite utilizar os Pokédollars conquistados durante a aventura para comprar itens.

Os principais itens disponíveis são:

**Recuperação de HP:**
- Potion
- Super Potion
- Hyper Potion
- Max Potion
- Full Restore

**Recuperação de condições negativas:**
- Antidote
- Burn Heal
- Ice Heal
- Awakening
- Paralyze Heal
- Full Heal

**Outros itens:**
- Revive
- Pedra Universal

As compras são registradas no banco de dados, e o inventário é atualizado conforme as transações.

### Evolução dos Pokémon

A evolução é realizada conforme as regras cadastradas no banco de dados.

O jogador pode utilizar uma Pedra Universal para evoluir Pokémon elegíveis, respeitando as condições específicas de cada espécie.

O sistema também oferece oportunidades de evolução gratuita após determinadas vitórias.

As evoluções possuem animações próprias e respeitam restrições de sexo quando necessário.

### Centro Pokémon

O Centro Pokémon permite recuperar os integrantes ativos da equipe.

A recuperação é apresentada por uma máquina animada, com Pokébolas correspondentes à quantidade de Pokémon no time.

A cura atualiza os dados dos Pokémon no banco, restaurando o HP e removendo condições negativas.

### Sistema de derrota e reinício

O Peakmon utiliza elementos de jogos *roguelike*, nos quais a derrota total possui consequências para a jornada.

Quando todos os Pokémon do jogador são derrotados, o perfil é reiniciado.

Nesse processo:

- Os Pokémon capturados são perdidos.
- O time é esvaziado.
- Os itens e os Pokédollars são perdidos.
- O progresso das áreas é reiniciado.
- O personagem precisa ser escolhido novamente.
- Os registros da jornada daquele perfil são apagados.

O perfil continua existindo, permitindo iniciar uma nova aventura sem precisar criar outra conta.

O reinício de um perfil não altera os dados do outro perfil do mesmo usuário.

### Diário de Jornada

O jogo possui uma página de histórico para consultar os acontecimentos registrados no banco de dados.

Entre os registros estão:

- Batalhas realizadas.
- Ações e acontecimentos dos turnos.
- Ataques e danos.
- Utilização de itens.
- Pokémon derrotados.
- Capturas realizadas.
- Evoluções.
- Vitórias contra chefes.
- Compras na loja.
- Eventos importantes da jornada.

Cada perfil possui seu próprio histórico, que é reiniciado juntamente com seu progresso.

## Tecnologias utilizadas

| Tecnologia | Utilização |
|---|---|
| PHP 8 | Lógica do jogo e programação orientada a objetos |
| MySQL/MariaDB | Armazenamento dos dados e progresso |
| PDO | Comunicação segura entre PHP e banco de dados |
| HTML5 | Estrutura das interfaces |
| CSS3 | Estilização, responsividade e animações |
| JavaScript | Interatividade e atualização das telas |
| Fetch API | Requisições assíncronas entre navegador e servidor |
| JSON | Transporte de dados entre JavaScript e PHP |
| XAMPP | Ambiente local com Apache, PHP e MySQL |
| Git e GitHub | Versionamento e colaboração |

## Arquitetura do sistema

O projeto utiliza uma organização baseada no padrão **MVC (Model-View-Controller)**.

### Models

Responsáveis pelas regras de negócio, cálculos, validações e consultas ao banco.

Estão localizados em `app/Models/`.

Exemplos:

- `Batalha.php`
- `Mapa.php`
- `Mochila.php`
- `Loja.php`
- `Perfil.php`
- `Time.php`
- `Captura.php`
- `Historico.php`
- `SinalEstranho.php`

### Controllers

Responsáveis por receber as ações do usuário, encaminhar as operações aos Models e preparar as respostas do sistema.

Estão localizados em `app/Controllers/`.

O projeto possui controllers para autenticação, perfis, personagens, mapa, batalhas, mochila, loja e histórico.

### Views

As páginas PHP e os arquivos HTML, CSS e JavaScript são responsáveis pela apresentação e interação com o usuário.

Entre as principais páginas estão:

- `login.php`
- `perfis.php`
- `selecionar_personagem.php`
- `mapa.php`
- `batalha.php`
- `mochila.php`
- `loja.php`
- `historico.php`

## Conceitos de Programação Orientada a Objetos

O projeto aplica diferentes conceitos de POO em PHP.

### Herança

Foi implementada uma classe abstrata de Pokémon, com subclasses representando os tipos existentes no jogo.

Também existe uma hierarquia de classes para condições negativas de batalha.

### Polimorfismo

Classes especializadas podem implementar comportamentos próprios a partir dos métodos definidos nas classes-base.

Esse conceito é utilizado nas regras de tipos e nas condições de status.

### Encapsulamento

As classes utilizam propriedades e métodos privados ou protegidos para controlar o acesso aos seus dados e preservar suas regras internas.

### Composição

Algumas classes criam e mantêm objetos responsáveis por partes específicas de seu funcionamento.

### Agregação

Os controllers e outros componentes recebem dependências de objetos que podem existir independentemente deles.

### Associação

Objetos do sistema interagem entre si, como ocorre na relação entre os Pokémon durante o cálculo de efetividade.

### Sobrecarga de métodos

O projeto também utiliza recursos de sobrecarga dinâmica do PHP por meio de métodos mágicos, como `__call()`.

### Exceções

O tratamento de erros utiliza exceções nativas do PHP e exceções personalizadas, como `ItemInvalidoException`.

## Banco de dados

O banco utilizado pelo jogo é:

`peakmon_bigolin_crisis`

O projeto possui tabelas para armazenar usuários, perfis, personagens, Pokémon, equipes, itens, inventários, batalhas, capturas, compras e progresso do mapa.

Também utiliza:

- **Triggers:** validações e execução automática de regras do banco.
- **Views:** consultas organizadas para exibição de informações.
- **Transações:** garantia de consistência em operações que modificam diferentes tabelas.
- **Chaves estrangeiras:** manutenção dos relacionamentos entre dados.
- **Logs:** registro de batalhas, eventos dos perfis e compras.

As cinco views principais são:

- `vw_resumo_perfil`
- `vw_historico_batalhas`
- `vw_historico_loja`
- `vw_catalogo_loja`
- `vw_evolucoes_oficiais`

O projeto trabalha com operações SQL de DDL, DML, DTL e DQL.

## Como executar o projeto

### Pré-requisitos

- XAMPP com Apache, PHP 8.2 e MySQL/MariaDB.
- Navegador atualizado.
- Banco de dados configurado.
- Editor de código, como Visual Studio Code, para desenvolvimento.

### Instalação

**1. Obtenha o projeto**

Clone o repositório ou extraia seus arquivos para a pasta:

`C:\xampp\htdocs\trabalho-3-materias`

**2. Inicie os serviços**

Abra o painel do XAMPP e ative:

- Apache
- MySQL

**3. Configure o banco**

O script de criação e configuração do banco está no arquivo:

`peakmon_base.sql`

Execute esse script em uma instalação de desenvolvimento, utilizando o gerenciador SQL de sua preferência.

**Atenção:** o script contém `DROP DATABASE IF EXISTS`. Executá-lo novamente apaga o banco existente e recria os dados iniciais, causando a perda do progresso salvo.

**4. Confira a conexão**

O arquivo de configuração é:

`config/database.php`

A configuração local padrão utiliza:

- Host: `127.0.0.1`
- Porta: `3306`
- Banco: `peakmon_bigolin_crisis`
- Usuário: `root`
- Senha: vazia

Caso o ambiente utilize outras credenciais, atualize o arquivo de configuração.

**5. Abra o jogo**

Acesse:

`http://localhost/trabalho-3-materias/menu%20inicial/menu.html`

Ou diretamente a tela de login:

`http://localhost/trabalho-3-materias/login.php`

Depois, entre em sua conta, escolha um perfil e inicie a aventura.

**Importante:** o jogo deve ser aberto através do Apache, e não diretamente por um caminho `file:///`.

## Organização dos arquivos

```text
trabalho-3-materias/
│
├── app/
│   ├── Controllers/
│   ├── Models/
│   │   ├── Condicoes/
│   │   └── Pokemons/
│   ├── Exceptions/
│   └── autoload.php
│
├── assets/
├── config/
│   └── database.php
├── images/
├── menu inicial/
│
├── login.php
├── perfis.php
├── selecionar_personagem.php
├── mapa.php
├── batalha.php
├── mochila.php
├── loja.php
├── historico.php
│
├── peakmon_base.sql
└── README.md
```

## Objetivo acadêmico

O Peakmon foi desenvolvido para integrar os conhecimentos das três disciplinas envolvidas no projeto.

**Programação Orientada a Objetos I:** aplicação de classes, objetos, herança, polimorfismo, encapsulamento, exceções e relacionamentos entre objetos.

**Linguagens para a Web II:** desenvolvimento de interfaces e sistemas web com PHP, HTML, CSS, JavaScript, MVC, sessões, requisições assíncronas e JSON.

**Banco de Dados II:** modelagem e utilização de banco relacional, comandos SQL, views, triggers, transações e registros de operações.

## Considerações finais

O Peakmon: The Bigolin Crisis reúne mecânicas de RPG, exploração, coleção de Pokémon e batalhas por turnos em uma aplicação web.

Além da experiência de jogo, o projeto busca demonstrar na prática a integração entre programação orientada a objetos, desenvolvimento web e banco de dados.

Seu desenvolvimento foi realizado de forma colaborativa, utilizando Git e GitHub para organização e versionamento do código.

---

**Projeto acadêmico desenvolvido no IFRS — Campus Canoas.**

*Peakmon: The Bigolin Crisis é um projeto educacional, sem finalidade comercial.*