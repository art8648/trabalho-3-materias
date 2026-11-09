-- ================================================================
-- PEAKMON: THE BIGOLIN CRISIS - schema final de desenvolvimento
-- MySQL 8.0.16+ (usa CHECK, SIGNAL e triggers)
-- Um tipo e um ataque fixo por especie; sem niveis/experiencia; evolucoes oficiais apenas.
-- Depois de validar, voces podem migrar as mudancas para o banco do projeto.
-- ================================================================

CREATE DATABASE IF NOT EXISTS peakmon_bigolin_crisis
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE peakmon_bigolin_crisis;
SET NAMES utf8mb4;

-- 1. TIPOS E EFETIVIDADE
CREATE TABLE tipo_pokemon (
    id TINYINT UNSIGNED PRIMARY KEY,
    nome VARCHAR(20) NOT NULL UNIQUE
);

-- A tabela guarda somente as combinacoes nao neutras.
-- Se nao existir registro para um par de tipos, o multiplicador e 1.0.
CREATE TABLE tipo_efetividade (
    tipo_atacante_id TINYINT UNSIGNED NOT NULL,
    tipo_defensor_id TINYINT UNSIGNED NOT NULL,
    multiplicador DECIMAL(3,2) NOT NULL,
    PRIMARY KEY (tipo_atacante_id, tipo_defensor_id),
    CONSTRAINT chk_efetividade_multiplicador
        CHECK (multiplicador IN (0.00, 0.50, 2.00)),
    FOREIGN KEY (tipo_atacante_id) REFERENCES tipo_pokemon(id),
    FOREIGN KEY (tipo_defensor_id) REFERENCES tipo_pokemon(id)
);

-- 2. USUARIOS E PERSONAGENS
CREATE TABLE usuario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- jogavel = TRUE: selecionavel pelo jogador.
-- jogavel = FALSE: adversario/NPC. Um personagem nao pode ser ambos.
CREATE TABLE personagem (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    -- TRUE = exclusivamente jogavel; FALSE = exclusivamente adversario/NPC.
    jogavel BOOLEAN NOT NULL,
    secreto BOOLEAN NOT NULL DEFAULT FALSE,
    condicao_desbloqueio VARCHAR(255) NULL,
    imagem VARCHAR(255) NOT NULL
);

CREATE TABLE avatar_opcao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL UNIQUE,
    imagem VARCHAR(255) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE perfil (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_avatar INT NULL,
    nome_perfil VARCHAR(100) NOT NULL,
    id_personagem INT NULL,
    numero_perfil TINYINT UNSIGNED NOT NULL,
    dinheiro INT NOT NULL DEFAULT 0,
    jogo_concluido BOOLEAN NOT NULL DEFAULT FALSE,
    data_conclusao DATETIME NULL,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_perfil_dinheiro
        CHECK (dinheiro >= 0),

    CONSTRAINT chk_numero_perfil
        CHECK (numero_perfil IN (1, 2)),

    CONSTRAINT uq_perfil_usuario_numero
        UNIQUE (id_usuario, numero_perfil),

    UNIQUE (id_usuario, nome_perfil),

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id) ON DELETE CASCADE,

    FOREIGN KEY (id_avatar)
        REFERENCES avatar_opcao(id) ON DELETE SET NULL,

    FOREIGN KEY (id_personagem)
        REFERENCES personagem(id) ON DELETE SET NULL

) ENGINE=InnoDB;

-- 3. CATALOGO DE ESPECIES
-- Cada especie tem UM tipo e UM ataque fixo. O dano real e calculado
-- pela aplicacao a partir de poder_ataque, atributos e efetividade.
CREATE TABLE pokemon (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_id INT NULL UNIQUE, -- ID da PokeAPI; NULL para especies personalizadas, como Arnaldo TUDO.
    nome VARCHAR(40) NOT NULL UNIQUE,
    regiao VARCHAR(30) NOT NULL DEFAULT 'HOENN',
    hp INT NOT NULL CHECK (hp > 0),
    atk INT NOT NULL CHECK (atk > 0),
    def INT NOT NULL CHECK (def > 0),
    sp_atk INT NOT NULL CHECK (sp_atk > 0),
    sp_def INT NOT NULL CHECK (sp_def > 0),
    vel INT NOT NULL CHECK (vel > 0),
    prioridade INT NOT NULL DEFAULT 0,
    tipo_id TINYINT UNSIGNED NOT NULL,
    nome_ataque VARCHAR(100) NOT NULL,
    categoria_ataque ENUM('FISICO','ESPECIAL') NOT NULL DEFAULT 'FISICO',
    poder_ataque INT NOT NULL DEFAULT 60 CHECK (poder_ataque > 0),
    imagem VARCHAR(255) NOT NULL,
    cavalo BOOLEAN NOT NULL DEFAULT FALSE,
    possui_sexo BOOLEAN NOT NULL DEFAULT TRUE,
    capturavel BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (tipo_id) REFERENCES tipo_pokemon(id)
);

-- Rayquaza e Deoxys devem existir no catalogo, com capturavel = FALSE.
-- Somente Rayquaza pode ser shiny; o campo atacante_shiny no log representa a forma
-- especial da cena final, sem tornar Rayquaza capturavel pelo jogador.

-- 4. EXEMPLARES CAPTURADOS PELOS PERFIS (sem nivel/experiencia)
CREATE TABLE pokemon_capturado (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_perfil INT NOT NULL,
    id_pokemon INT NOT NULL,
    hp_atual INT NOT NULL CHECK (hp_atual >= 0),
    desmaiado BOOLEAN NOT NULL DEFAULT FALSE,
    -- O PHP deve sortear M/F para especies com sexo; SEM_GENERO para especies sem sexo.
    sexo ENUM('M','F','SEM_GENERO') NOT NULL,
    status_condicao ENUM('VENENO','QUEIMADURA','CONGELAMENTO','SONO','PARALISIA') NULL,
    data_captura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (id_pokemon) REFERENCES pokemon(id)
);

-- 5. TIMES
-- Um time pertence a um perfil (time do jogador) OU a um personagem (time NPC).
CREATE TABLE times (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_perfil INT NULL,
    id_personagem INT NULL,
    nome VARCHAR(60) NOT NULL,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id_perfil), -- um time editavel por perfil
    UNIQUE (id_personagem), -- um time cadastrado por personagem NPC
    CONSTRAINT chk_time_dono CHECK (
        (id_perfil IS NOT NULL AND id_personagem IS NULL) OR
        (id_perfil IS NULL AND id_personagem IS NOT NULL)
    ),
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (id_personagem) REFERENCES personagem(id) ON DELETE CASCADE
);

-- Em times do jogador, use id_pokemon_capturado.
-- Em times de NPCs, use id_pokemon (especie do catalogo).
CREATE TABLE time_pokemon (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_time INT NOT NULL,
    id_pokemon INT NULL,
    id_pokemon_capturado INT NULL,
    posicao TINYINT NOT NULL CHECK (posicao BETWEEN 1 AND 6),
    CONSTRAINT chk_time_pokemon_origem CHECK (
        (id_pokemon IS NOT NULL AND id_pokemon_capturado IS NULL) OR
        (id_pokemon IS NULL AND id_pokemon_capturado IS NOT NULL)
    ),
    UNIQUE (id_time, posicao),
    UNIQUE (id_time, id_pokemon_capturado),
    FOREIGN KEY (id_time) REFERENCES times(id) ON DELETE CASCADE,
    FOREIGN KEY (id_pokemon) REFERENCES pokemon(id),
    FOREIGN KEY (id_pokemon_capturado) REFERENCES pokemon_capturado(id) ON DELETE CASCADE
);

-- 6. LOJA E INVENTARIO
CREATE TABLE item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    descricao VARCHAR(255) NOT NULL,
    preco INT NOT NULL CHECK (preco > 0),
    tipo_efeito ENUM('CURA_HP','CURA_TOTAL','CURA_STATUS','REVIVER','PEDRA_EVOLUCAO') NOT NULL,
    valor_efeito INT NULL,
    -- Para itens de cura de status: ENVENENAMENTO, QUEIMADURA, SONO, etc.; NULL para os demais.
    status_curado VARCHAR(30) NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE
);

-- Areas/capitulos da campanha. A coluna ordem organiza os capitulos; os caminhos
-- ramificados dentro de cada area sao representados por area_no e area_conexao.
-- O chefe final de cada area concede 2 Pedras Universais na primeira vitoria.
CREATE TABLE area_jogo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL UNIQUE,
    ordem TINYINT UNSIGNED NOT NULL UNIQUE,
    id_personagem_chefe INT NOT NULL,
    id_item_recompensa INT NOT NULL,
    quantidade_recompensa INT NOT NULL DEFAULT 2 CHECK (quantidade_recompensa = 2),
    FOREIGN KEY (id_personagem_chefe) REFERENCES personagem(id),
    FOREIGN KEY (id_item_recompensa) REFERENCES item(id)
);

-- Cada no representa um ponto visitavel no mapa de uma area.
-- Exemplo: INICIO -> (caminho A ou caminho B) -> ambos chegam ao mesmo CHEFE.
-- pos_x/pos_y sao coordenadas visuais para desenhar o mapa no frontend.
CREATE TABLE area_no (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_area INT NOT NULL,
    codigo VARCHAR(40) NOT NULL,
    tipo_no ENUM('INICIO','BATALHA','CAPTURA','ITEM','LOJA','CURA','EVENTO','CHEFE','FIM') NOT NULL,
    titulo VARCHAR(80) NOT NULL,
    descricao VARCHAR(255) NULL,
    pos_x INT NOT NULL DEFAULT 0,
    pos_y INT NOT NULL DEFAULT 0,
    id_personagem INT NULL,
    UNIQUE (id_area, codigo),
    UNIQUE (id, id_area),
    FOREIGN KEY (id_area) REFERENCES area_jogo(id) ON DELETE CASCADE,
    FOREIGN KEY (id_personagem) REFERENCES personagem(id)
);

-- Arestas direcionadas entre nos. O mesmo id_area nas duas FKs impede ligar
-- um no de uma area a um no de outra area. As ramificacoes convergem para
-- o mesmo no CHEFE, cadastrado como destino de diferentes caminhos.
CREATE TABLE area_conexao (
    id_area INT NOT NULL,
    id_no_origem INT NOT NULL,
    id_no_destino INT NOT NULL,
    texto_opcao VARCHAR(60) NULL,
    PRIMARY KEY (id_no_origem, id_no_destino),
    CONSTRAINT chk_area_conexao_nos_diferentes CHECK (id_no_origem <> id_no_destino),
    FOREIGN KEY (id_no_origem, id_area) REFERENCES area_no(id, id_area) ON DELETE CASCADE,
    FOREIGN KEY (id_no_destino, id_area) REFERENCES area_no(id, id_area) ON DELETE CASCADE
);

CREATE TABLE inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_perfil INT NOT NULL UNIQUE,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE
);

CREATE TABLE inventario_item (
    id_inventario INT NOT NULL,
    id_item INT NOT NULL,
    quantidade INT NOT NULL DEFAULT 1 CHECK (quantidade > 0),
    PRIMARY KEY (id_inventario, id_item),
    FOREIGN KEY (id_inventario) REFERENCES inventario(id) ON DELETE CASCADE,
    FOREIGN KEY (id_item) REFERENCES item(id)
);

-- Historico de compras. O trigger preenche o preco vigente, desconta o dinheiro
-- e acrescenta os itens ao inventario apos a insercao da compra.
CREATE TABLE compra_loja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_perfil INT NOT NULL,
    id_item INT NOT NULL,
    quantidade INT NOT NULL CHECK (quantidade > 0),
    preco_unitario INT NOT NULL DEFAULT 0 CHECK (preco_unitario >= 0),
    data_compra DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_perfil) REFERENCES perfil(id),
    FOREIGN KEY (id_item) REFERENCES item(id)
);

-- Log explicito da loja, separado do registro da compra para facilitar demonstracao e consultas.
CREATE TABLE log_loja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_compra INT NOT NULL UNIQUE,
    id_perfil INT NOT NULL,
    id_item INT NOT NULL,
    acao ENUM('COMPRA') NOT NULL DEFAULT 'COMPRA',
    quantidade INT NOT NULL,
    valor_total INT NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    data_evento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_compra) REFERENCES compra_loja(id) ON DELETE CASCADE,
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (id_item) REFERENCES item(id)
);

-- 7. EVOLUCAO: pedra comprada na loja; nao existe nivel de Pokemon.
CREATE TABLE evolution_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pokemon_origem INT NOT NULL,
    id_pokemon_destino INT NOT NULL,
    id_item INT NOT NULL,
    -- NULL para evolucoes sem restricao de sexo; M/F para linhas que exigem sexo especifico.
    sexo_requerido ENUM('M','F') NULL,
    UNIQUE (id_pokemon_origem, id_pokemon_destino),
    CONSTRAINT chk_evolucao_especies_diferentes CHECK (id_pokemon_origem <> id_pokemon_destino),
    FOREIGN KEY (id_pokemon_origem) REFERENCES pokemon(id),
    FOREIGN KEY (id_pokemon_destino) REFERENCES pokemon(id),
    FOREIGN KEY (id_item) REFERENCES item(id)
);

-- 8. BATALHAS E LOGS
CREATE TABLE batalha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_perfil INT NOT NULL,
    id_time_jogador INT NOT NULL,
    id_time_adversario INT NOT NULL,
    id_personagem_jogador INT NOT NULL,
    id_personagem_adversario INT NOT NULL,
    id_area INT NULL, -- area em que o combate ocorre
    id_no INT NULL, -- no do mapa associado a este encontro, quando houver
    tipo_batalha ENUM('NORMAL','CHEFE','TREINO','FINAL') NOT NULL DEFAULT 'NORMAL',
    resultado ENUM('EM_ANDAMENTO','VITORIA','DERROTA','FUGA') NOT NULL DEFAULT 'EM_ANDAMENTO',
    dinheiro_ganho INT NOT NULL DEFAULT 0 CHECK (dinheiro_ganho >= 0),
    data_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_fim DATETIME NULL,
    FOREIGN KEY (id_perfil) REFERENCES perfil(id),
    FOREIGN KEY (id_time_jogador) REFERENCES times(id),
    FOREIGN KEY (id_time_adversario) REFERENCES times(id),
    FOREIGN KEY (id_personagem_jogador) REFERENCES personagem(id),
    FOREIGN KEY (id_personagem_adversario) REFERENCES personagem(id),
    FOREIGN KEY (id_area) REFERENCES area_jogo(id),
    FOREIGN KEY (id_no, id_area) REFERENCES area_no(id, id_area)
);

-- Evita premiar a mesma area mais de uma vez para o mesmo perfil.
CREATE TABLE area_concluida (
    id_perfil INT NOT NULL,
    id_area INT NOT NULL,
    id_batalha INT NOT NULL,
    data_conclusao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_perfil, id_area),
    UNIQUE (id_batalha),
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (id_area) REFERENCES area_jogo(id),
    FOREIGN KEY (id_batalha) REFERENCES batalha(id)
);

-- Estado do jogador no mapa: qual no esta acessivel/atual em cada area.
-- O frontend atualiza id_no_atual quando o jogador escolhe um caminho.
CREATE TABLE progresso_area (
    id_perfil INT NOT NULL,
    id_area INT NOT NULL,
    id_no_atual INT NULL,
    estado ENUM('BLOQUEADA','DISPONIVEL','EM_ANDAMENTO','CONCLUIDA') NOT NULL DEFAULT 'BLOQUEADA',
    data_inicio DATETIME NULL,
    data_atualizacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_perfil, id_area),
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (id_area) REFERENCES area_jogo(id) ON DELETE CASCADE,
    FOREIGN KEY (id_no_atual, id_area) REFERENCES area_no(id, id_area)
);

-- Acoes, danos e eventos narrativos ficam aqui.
-- O hitkill do Rayquaza deve ser registrado pela aplicacao como evento de historia.
-- Ao encerrar uma batalha tipo FINAL como VITORIA, o perfil fica concluido para os creditos.
CREATE TABLE log_batalha (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_batalha INT NOT NULL,
    turno INT NOT NULL DEFAULT 0,
    tipo_evento VARCHAR(30) NOT NULL,
    mensagem TEXT NOT NULL,
    id_pokemon_atacante INT NULL,
    id_pokemon_alvo INT NULL,
    dano INT NULL,
    atacante_shiny BOOLEAN NOT NULL DEFAULT FALSE,
    data_evento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_batalha) REFERENCES batalha(id) ON DELETE CASCADE,
    FOREIGN KEY (id_pokemon_atacante) REFERENCES pokemon(id),
    FOREIGN KEY (id_pokemon_alvo) REFERENCES pokemon(id)
);

-- 9. LOG DA ROLETA DE CAPTURA
CREATE TABLE capture_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_perfil INT NOT NULL,
    id_batalha INT NOT NULL,
    id_pokemon INT NOT NULL,
    valor_roleta TINYINT UNSIGNED NOT NULL CHECK (valor_roleta BETWEEN 1 AND 100),
    chance_captura DECIMAL(5,2) NOT NULL CHECK (chance_captura BETWEEN 0 AND 100),
    sucesso BOOLEAN NOT NULL DEFAULT FALSE,
    id_pokemon_capturado INT NULL,
    data_tentativa DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_captura_sucesso_tem_pokemon CHECK ((sucesso = FALSE AND id_pokemon_capturado IS NULL) OR (sucesso = TRUE AND id_pokemon_capturado IS NOT NULL)),
    FOREIGN KEY (id_perfil) REFERENCES perfil(id),
    FOREIGN KEY (id_batalha) REFERENCES batalha(id),
    FOREIGN KEY (id_pokemon) REFERENCES pokemon(id),
    FOREIGN KEY (id_pokemon_capturado) REFERENCES pokemon_capturado(id) ON DELETE SET NULL
);

-- Registro de personagens secretos ja desbloqueados por perfil.
CREATE TABLE personagem_desbloqueado (
    id_perfil INT NOT NULL,
    id_personagem INT NOT NULL,
    data_desbloqueio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_perfil, id_personagem),
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (id_personagem) REFERENCES personagem(id) ON DELETE CASCADE
);

-- Logs de conta/perfil: criacao, troca de personagem/avatar e outros eventos de perfil.
CREATE TABLE log_perfil (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NULL,
    id_perfil INT NULL,
    tipo_evento VARCHAR(40) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    data_evento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id) ON DELETE SET NULL,
    FOREIGN KEY (id_perfil) REFERENCES perfil(id) ON DELETE SET NULL
);

-- 10. VIEWS (o trabalho exige pelo menos tres)
CREATE VIEW vw_resumo_perfil AS
SELECT
    p.id AS id_perfil,
    p.nome_perfil,
    u.nome AS nome_usuario,
    p.dinheiro,
    p.jogo_concluido,
    p.data_conclusao,
    c.nome AS personagem_escolhido,
    av.nome AS avatar_nome,
    av.imagem AS avatar_imagem,
    p.data_criacao
FROM perfil p
JOIN usuario u ON u.id = p.id_usuario
LEFT JOIN personagem c ON c.id = p.id_personagem
LEFT JOIN avatar_opcao av ON av.id = p.id_avatar;

CREATE VIEW vw_historico_batalhas AS
SELECT
    b.id AS id_batalha,
    p.nome_perfil,
    cj.nome AS personagem_jogador,
    ca.nome AS adversario,
    b.tipo_batalha,
    b.resultado,
    b.dinheiro_ganho,
    b.data_inicio,
    b.data_fim
FROM batalha b
JOIN perfil p ON p.id = b.id_perfil
JOIN personagem cj ON cj.id = b.id_personagem_jogador
JOIN personagem ca ON ca.id = b.id_personagem_adversario;

CREATE VIEW vw_catalogo_loja AS
SELECT id, nome, descricao, preco, tipo_efeito, valor_efeito, status_curado
FROM item
WHERE ativo = TRUE;

CREATE VIEW vw_evolucoes_oficiais AS
SELECT po.nome AS pokemon_origem, pd.nome AS pokemon_destino, i.nome AS item_necessario, er.sexo_requerido
FROM evolution_rules er
JOIN pokemon po ON po.id = er.id_pokemon_origem
JOIN pokemon pd ON pd.id = er.id_pokemon_destino
JOIN item i ON i.id = er.id_item;


CREATE VIEW vw_historico_loja AS
SELECT ll.id AS id_log, p.nome_perfil, i.nome AS item, ll.quantidade,
       ll.valor_total, ll.acao, ll.data_evento
FROM log_loja ll
JOIN perfil p ON p.id = ll.id_perfil
JOIN item i ON i.id = ll.id_item;

-- 11. TRIGGERS
DELIMITER //

-- Rayquaza e Deoxys sao exclusivos da historia e nunca capturaveis.
CREATE TRIGGER trg_pokemon_historia_nao_capturavel_insert
BEFORE INSERT ON pokemon
FOR EACH ROW
BEGIN
    IF LOWER(NEW.nome) IN ('rayquaza','deoxys') THEN
        SET NEW.capturavel = FALSE;
    END IF;
END//

CREATE TRIGGER trg_pokemon_historia_nao_capturavel_update
BEFORE UPDATE ON pokemon
FOR EACH ROW
BEGIN
    IF LOWER(NEW.nome) IN ('rayquaza','deoxys') THEN
        SET NEW.capturavel = FALSE;
    END IF;
END//


-- Ao criar perfil, cria automaticamente um inventario vazio.
CREATE TRIGGER trg_perfil_cria_inventario_time
AFTER INSERT ON perfil
FOR EACH ROW
BEGIN
    INSERT INTO inventario (id_perfil) VALUES (NEW.id);
    INSERT INTO times (id_perfil, id_personagem, nome)
    VALUES (NEW.id, NULL, 'Meu time');
    INSERT INTO log_perfil (id_usuario, id_perfil, tipo_evento, descricao)
    VALUES (NEW.id_usuario, NEW.id, 'PERFIL_CRIADO', 'Perfil criado.');

    -- Se a primeira area e seu no inicial ja estiverem cadastrados, libera-os.
    INSERT INTO progresso_area (id_perfil, id_area, id_no_atual, estado, data_inicio)
    SELECT NEW.id, a.id, n.id, 'DISPONIVEL', CURRENT_TIMESTAMP
    FROM area_jogo a
    JOIN area_no n ON n.id_area = a.id AND n.tipo_no = 'INICIO'
    WHERE a.ordem = (SELECT MIN(ordem) FROM area_jogo)
    LIMIT 1
    ON DUPLICATE KEY UPDATE estado = IF(estado = 'BLOQUEADA', 'DISPONIVEL', estado);
END//

-- Ao cadastrar um usuario, cria automaticamente seus dois saves.
-- Cada INSERT em perfil dispara trg_perfil_cria_inventario_time,
-- que prepara o inventario, o time e os registros iniciais de cada save.
CREATE TRIGGER trg_usuario_cria_dois_perfis
AFTER INSERT ON usuario
FOR EACH ROW
BEGIN
    INSERT INTO perfil (id_usuario, nome_perfil, numero_perfil)
    VALUES
        (NEW.id, 'Perfil 1', 1),
        (NEW.id, 'Perfil 2', 2);
END//

-- O personagem escolhido no perfil precisa ser jogavel.
CREATE TRIGGER trg_perfil_valida_personagem_insert
BEFORE INSERT ON perfil
FOR EACH ROW
BEGIN
    DECLARE v_jogavel BOOLEAN;
    IF NEW.id_personagem IS NOT NULL THEN
        SELECT jogavel INTO v_jogavel
        FROM personagem WHERE id = NEW.id_personagem;
        IF v_jogavel = FALSE THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O perfil so pode selecionar um personagem jogavel.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_perfil_valida_personagem_update
BEFORE UPDATE ON perfil
FOR EACH ROW
BEGIN
    DECLARE v_jogavel BOOLEAN;
    IF NEW.id_personagem IS NOT NULL THEN
        SELECT jogavel INTO v_jogavel
        FROM personagem WHERE id = NEW.id_personagem;
        IF v_jogavel = FALSE THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O perfil so pode selecionar um personagem jogavel.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_perfil_registrar_alteracoes
AFTER UPDATE ON perfil
FOR EACH ROW
BEGIN
    IF NOT (OLD.id_avatar <=> NEW.id_avatar) OR
       NOT (OLD.id_personagem <=> NEW.id_personagem) OR
       NOT (OLD.nome_perfil <=> NEW.nome_perfil) THEN
        INSERT INTO log_perfil (id_usuario, id_perfil, tipo_evento, descricao)
        VALUES (NEW.id_usuario, NEW.id, 'PERFIL_ATUALIZADO',
                'Nome, avatar ou personagem do perfil foi alterado.');
    END IF;
END//

-- Um no CHEFE deve apontar para o chefe cadastrado para aquela area.
CREATE TRIGGER trg_area_no_validar_chefe_insert
BEFORE INSERT ON area_no
FOR EACH ROW
BEGIN
    DECLARE v_chefe INT;
    DECLARE v_jogavel BOOLEAN;
    IF NEW.tipo_no = 'CHEFE' THEN
        SELECT id_personagem_chefe INTO v_chefe FROM area_jogo WHERE id = NEW.id_area;
        IF NEW.id_personagem IS NULL OR NEW.id_personagem <> v_chefe THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O no CHEFE deve usar o chefe cadastrado para a area.';
        END IF;
    END IF;
    IF NEW.id_personagem IS NOT NULL THEN
        SELECT jogavel INTO v_jogavel FROM personagem WHERE id = NEW.id_personagem;
        IF v_jogavel = TRUE THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Encontros do mapa devem apontar para um personagem adversario.';
        END IF;
    END IF;
END//

-- Impede capturar especies marcadas como exclusivas da historia.
CREATE TRIGGER trg_pokemon_capturado_verifica_capturavel
BEFORE INSERT ON pokemon_capturado
FOR EACH ROW
BEGIN
    DECLARE v_capturavel BOOLEAN;
    SELECT capturavel INTO v_capturavel FROM pokemon WHERE id = NEW.id_pokemon;
    IF v_capturavel = FALSE THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie nao pode ser capturada pelo jogador.';
    END IF;
END//

-- Garante que sexo e HP inicial sejam compativeis com a especie.
CREATE TRIGGER trg_pokemon_capturado_validar_insert
BEFORE INSERT ON pokemon_capturado
FOR EACH ROW
BEGIN
    DECLARE v_possui_sexo BOOLEAN;
    DECLARE v_hp_max INT;
    DECLARE v_capturavel BOOLEAN;

    SELECT possui_sexo, hp, capturavel
      INTO v_possui_sexo, v_hp_max, v_capturavel
      FROM pokemon WHERE id = NEW.id_pokemon;

    IF v_capturavel = FALSE THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie e exclusiva da historia e nao pode ser capturada.';
    END IF;
    IF v_possui_sexo = TRUE AND NEW.sexo = 'SEM_GENERO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie possui sexo; informe M ou F para o exemplar capturado.';
    END IF;
    IF v_possui_sexo = FALSE AND NEW.sexo <> 'SEM_GENERO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie nao possui sexo; use SEM_GENERO.';
    END IF;
    IF NEW.hp_atual > v_hp_max THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HP atual nao pode exceder o HP maximo da especie.';
    END IF;
END//

CREATE TRIGGER trg_pokemon_capturado_validar_update
BEFORE UPDATE ON pokemon_capturado
FOR EACH ROW
BEGIN
    DECLARE v_possui_sexo BOOLEAN;
    DECLARE v_hp_max INT;
    SELECT possui_sexo, hp INTO v_possui_sexo, v_hp_max
    FROM pokemon WHERE id = NEW.id_pokemon;

    IF v_possui_sexo = TRUE AND NEW.sexo = 'SEM_GENERO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie possui sexo; informe M ou F.';
    END IF;
    IF v_possui_sexo = FALSE AND NEW.sexo <> 'SEM_GENERO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie nao possui sexo; use SEM_GENERO.';
    END IF;
    IF NEW.hp_atual > v_hp_max THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'HP atual nao pode exceder o HP maximo da especie.';
    END IF;
END//

-- O exemplar so pode mudar de especie por uma transicao cadastrada em evolution_rules.
-- Isso impede pular etapas e respeita as restricoes de sexo; a aplicacao PHP deve consumir a pedra.
CREATE TRIGGER trg_pokemon_capturado_validar_evolucao
BEFORE UPDATE ON pokemon_capturado
FOR EACH ROW
BEGIN
    IF NEW.id_pokemon <> OLD.id_pokemon AND NOT EXISTS (
        SELECT 1 FROM evolution_rules er
        WHERE er.id_pokemon_origem = OLD.id_pokemon
          AND er.id_pokemon_destino = NEW.id_pokemon
          AND (er.sexo_requerido IS NULL OR er.sexo_requerido = OLD.sexo)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Evolucao nao cadastrada ou sexo incompativel. Siga a linha oficial e a ordem correta.';
    END IF;
END//

-- NPCs so podem ter especies no time; times de jogador usam capturas do proprio perfil.
-- Tambem garante que o time da Matikanetannhauser tenha apenas cavalos.
CREATE TRIGGER trg_time_pokemon_validar_insert
BEFORE INSERT ON time_pokemon
FOR EACH ROW
BEGIN
    DECLARE v_id_perfil INT DEFAULT NULL;
    DECLARE v_id_personagem INT DEFAULT NULL;
    DECLARE v_dono_pokemon INT DEFAULT NULL;
    DECLARE v_nome_personagem VARCHAR(100) DEFAULT NULL;
    DECLARE v_cavalo BOOLEAN DEFAULT FALSE;

    SELECT id_perfil, id_personagem
      INTO v_id_perfil, v_id_personagem
      FROM times WHERE id = NEW.id_time;

    IF v_id_perfil IS NOT NULL THEN
        IF NEW.id_pokemon_capturado IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O time do jogador deve usar Pokemon capturados.';
        END IF;
        SELECT id_perfil INTO v_dono_pokemon
          FROM pokemon_capturado WHERE id = NEW.id_pokemon_capturado;
        IF v_dono_pokemon <> v_id_perfil THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O Pokemon capturado pertence a outro perfil.';
        END IF;
    ELSE
        IF NEW.id_pokemon IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O time de NPC deve referenciar especies do catalogo.';
        END IF;
        SELECT c.nome, p.cavalo INTO v_nome_personagem, v_cavalo
          FROM personagem c JOIN pokemon p ON p.id = NEW.id_pokemon
          WHERE c.id = v_id_personagem;
        IF v_nome_personagem = 'Matikanetannhauser' AND v_cavalo = FALSE THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O time da Matikanetannhauser so pode conter Pokemon-cavalo.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_time_pokemon_validar_update
BEFORE UPDATE ON time_pokemon
FOR EACH ROW
BEGIN
    DECLARE v_id_perfil INT DEFAULT NULL;
    DECLARE v_id_personagem INT DEFAULT NULL;
    DECLARE v_dono_pokemon INT DEFAULT NULL;
    DECLARE v_nome_personagem VARCHAR(100) DEFAULT NULL;
    DECLARE v_cavalo BOOLEAN DEFAULT FALSE;

    SELECT id_perfil, id_personagem
      INTO v_id_perfil, v_id_personagem
      FROM times WHERE id = NEW.id_time;

    IF v_id_perfil IS NOT NULL THEN
        IF NEW.id_pokemon_capturado IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O time do jogador deve usar Pokemon capturados.';
        END IF;
        SELECT id_perfil INTO v_dono_pokemon
          FROM pokemon_capturado WHERE id = NEW.id_pokemon_capturado;
        IF v_dono_pokemon <> v_id_perfil THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O Pokemon capturado pertence a outro perfil.';
        END IF;
    ELSE
        IF NEW.id_pokemon IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O time de NPC deve referenciar especies do catalogo.';
        END IF;
        SELECT c.nome, p.cavalo INTO v_nome_personagem, v_cavalo
          FROM personagem c JOIN pokemon p ON p.id = NEW.id_pokemon
          WHERE c.id = v_id_personagem;
        IF v_nome_personagem = 'Matikanetannhauser' AND v_cavalo = FALSE THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O time da Matikanetannhauser so pode conter Pokemon-cavalo.';
        END IF;
    END IF;
END//

-- Valida os lados da batalha e os times vinculados.
CREATE TRIGGER trg_batalha_validar_insert
BEFORE INSERT ON batalha
FOR EACH ROW
BEGIN
    DECLARE v_jogavel_jogador BOOLEAN;
    DECLARE v_jogavel_adversario BOOLEAN;
    DECLARE v_personagem_perfil INT;
    DECLARE v_perfil_time INT;
    DECLARE v_personagem_time INT;

    SELECT id_personagem INTO v_personagem_perfil
      FROM perfil WHERE id = NEW.id_perfil;
    IF v_personagem_perfil IS NULL OR v_personagem_perfil <> NEW.id_personagem_jogador THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'O personagem da batalha deve ser o selecionado no perfil.';
    END IF;

    SELECT jogavel INTO v_jogavel_jogador
      FROM personagem WHERE id = NEW.id_personagem_jogador;
    SELECT jogavel INTO v_jogavel_adversario
      FROM personagem WHERE id = NEW.id_personagem_adversario;
    IF v_jogavel_jogador = FALSE OR v_jogavel_adversario = TRUE THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Escolha um personagem jogavel e um adversario.';
    END IF;

    SELECT id_perfil INTO v_perfil_time FROM times WHERE id = NEW.id_time_jogador;
    IF v_perfil_time IS NULL OR v_perfil_time <> NEW.id_perfil THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'O time do jogador deve pertencer ao perfil da batalha.';
    END IF;

    SELECT id_personagem INTO v_personagem_time FROM times WHERE id = NEW.id_time_adversario;
    IF v_personagem_time IS NULL OR v_personagem_time <> NEW.id_personagem_adversario THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'O time adversario deve pertencer ao personagem adversario.';
    END IF;
END//

-- Uma tentativa de captura so pode ocorrer apos vitoria e para especie capturavel.
CREATE TRIGGER trg_capture_attempts_validar_insert
BEFORE INSERT ON capture_attempts
FOR EACH ROW
BEGIN
    DECLARE v_resultado VARCHAR(20);
    DECLARE v_perfil_batalha INT;
    DECLARE v_capturavel BOOLEAN;

    SELECT resultado, id_perfil INTO v_resultado, v_perfil_batalha
      FROM batalha WHERE id = NEW.id_batalha;
    IF v_resultado <> 'VITORIA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A roleta de captura so pode ser usada apos uma vitoria.';
    END IF;
    IF v_perfil_batalha <> NEW.id_perfil THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A batalha pertence a outro perfil.';
    END IF;
    SELECT capturavel INTO v_capturavel FROM pokemon WHERE id = NEW.id_pokemon;
    IF v_capturavel = FALSE THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Esta especie nao pode aparecer como captura.';
    END IF;

    IF NEW.sucesso = TRUE THEN
        IF NOT EXISTS (
            SELECT 1 FROM pokemon_capturado pc
            WHERE pc.id = NEW.id_pokemon_capturado
              AND pc.id_perfil = NEW.id_perfil
              AND pc.id_pokemon = NEW.id_pokemon
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O Pokemon capturado deve pertencer ao perfil e corresponder a especie sorteada.';
        END IF;
    END IF;
END//

-- Preco e saldo sao validados antes da compra ser registrada.
CREATE TRIGGER trg_compra_loja_validar
BEFORE INSERT ON compra_loja
FOR EACH ROW
BEGIN
    DECLARE v_preco INT;
    DECLARE v_ativo BOOLEAN;
    DECLARE v_saldo INT;

    IF NEW.quantidade <= 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A quantidade comprada deve ser maior que zero.';
    END IF;

    SELECT preco, ativo INTO v_preco, v_ativo
      FROM item WHERE id = NEW.id_item;
    SELECT dinheiro INTO v_saldo FROM perfil WHERE id = NEW.id_perfil FOR UPDATE;

    IF v_ativo = FALSE THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este item nao esta disponivel na loja.';
    END IF;
    IF v_saldo < v_preco * NEW.quantidade THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Saldo insuficiente para esta compra.';
    END IF;

    SET NEW.preco_unitario = v_preco;
END//

-- Ao confirmar compra: desconta dinheiro e soma os itens no inventario.
CREATE TRIGGER trg_compra_loja_aplicar
AFTER INSERT ON compra_loja
FOR EACH ROW
BEGIN
    DECLARE v_id_inventario INT;

    UPDATE perfil
       SET dinheiro = dinheiro - (NEW.preco_unitario * NEW.quantidade)
     WHERE id = NEW.id_perfil;

    SELECT id INTO v_id_inventario
      FROM inventario WHERE id_perfil = NEW.id_perfil;

    INSERT INTO inventario_item (id_inventario, id_item, quantidade)
    VALUES (v_id_inventario, NEW.id_item, NEW.quantidade)
    ON DUPLICATE KEY UPDATE quantidade = quantidade + NEW.quantidade;
END//

CREATE TRIGGER trg_compra_loja_criar_log
AFTER INSERT ON compra_loja
FOR EACH ROW
BEGIN
    INSERT INTO log_loja
        (id_compra, id_perfil, id_item, acao, quantidade, valor_total, descricao)
    SELECT NEW.id, NEW.id_perfil, NEW.id_item, 'COMPRA', NEW.quantidade,
           NEW.preco_unitario * NEW.quantidade,
           CONCAT('Compra de ', NEW.quantidade, ' unidade(s) de ', i.nome, '.')
    FROM item i WHERE i.id = NEW.id_item;
END//

-- Garante que uma regra de evolucao use um item cadastrado como pedra.
CREATE TRIGGER trg_evolution_rules_validar
BEFORE INSERT ON evolution_rules
FOR EACH ROW
BEGIN
    DECLARE v_tipo_efeito VARCHAR(30);
    DECLARE v_nome_item VARCHAR(100);
    SELECT tipo_efeito, nome INTO v_tipo_efeito, v_nome_item FROM item WHERE id = NEW.id_item;
    IF v_tipo_efeito <> 'PEDRA_EVOLUCAO' OR LOWER(v_nome_item) <> 'pedra universal' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'As regras de evolucao devem usar a Pedra Universal.';
    END IF;
END//

-- O registro de shiny no log so pode apontar para Rayquaza.
CREATE TRIGGER trg_log_batalha_so_rayquaza_shiny
BEFORE INSERT ON log_batalha
FOR EACH ROW
BEGIN
    DECLARE v_nome_pokemon VARCHAR(40);
    IF NEW.atacante_shiny = TRUE THEN
        IF NEW.id_pokemon_atacante IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'O log shiny precisa identificar o Pokemon atacante.';
        END IF;
        SELECT nome INTO v_nome_pokemon
          FROM pokemon WHERE id = NEW.id_pokemon_atacante;
        IF v_nome_pokemon IS NULL OR LOWER(v_nome_pokemon) <> 'rayquaza' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Neste jogo, somente Rayquaza pode aparecer como shiny.';
        END IF;
    END IF;
END//

-- Uma area deve ter como chefe um personagem adversario e a recompensa deve
-- ser especificamente a Pedra Universal.
CREATE TRIGGER trg_area_jogo_validar_insert
BEFORE INSERT ON area_jogo
FOR EACH ROW
BEGIN
    DECLARE v_jogavel BOOLEAN;
    DECLARE v_nome_item VARCHAR(100);
    DECLARE v_tipo_efeito VARCHAR(30);

    SELECT jogavel INTO v_jogavel
      FROM personagem WHERE id = NEW.id_personagem_chefe;
    IF v_jogavel = TRUE THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'O chefe final da area precisa ser um personagem adversario.';
    END IF;

    SELECT nome, tipo_efeito INTO v_nome_item, v_tipo_efeito
      FROM item WHERE id = NEW.id_item_recompensa;
    IF LOWER(v_nome_item) <> 'pedra universal' OR v_tipo_efeito <> 'PEDRA_EVOLUCAO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A recompensa final da area precisa ser a Pedra Universal.';
    END IF;
END//

-- Ao cadastrar o no INICIO da primeira area, libera-a para perfis ja existentes.
CREATE TRIGGER trg_area_no_liberar_inicio
AFTER INSERT ON area_no
FOR EACH ROW
BEGIN
    DECLARE v_ordem TINYINT UNSIGNED DEFAULT NULL;
    SELECT ordem INTO v_ordem FROM area_jogo WHERE id = NEW.id_area;
    IF NEW.tipo_no = 'INICIO' AND v_ordem = (SELECT MIN(ordem) FROM area_jogo) THEN
        INSERT INTO progresso_area (id_perfil, id_area, id_no_atual, estado, data_inicio)
        SELECT p.id, NEW.id_area, NEW.id, 'DISPONIVEL', CURRENT_TIMESTAMP
        FROM perfil p
        WHERE TRUE
        ON DUPLICATE KEY UPDATE
            id_no_atual = VALUES(id_no_atual),
            estado = IF(estado = 'BLOQUEADA', 'DISPONIVEL', estado);
    END IF;
END//

-- Uma batalha so pode ser finalizada uma vez; evita creditar recompensas duplicadas.
CREATE TRIGGER trg_batalha_impedir_reabrir
BEFORE UPDATE ON batalha
FOR EACH ROW
BEGIN
    IF OLD.resultado <> 'EM_ANDAMENTO' AND NEW.resultado <> OLD.resultado THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Uma batalha finalizada nao pode ter seu resultado alterado.';
    END IF;
    IF OLD.resultado = 'EM_ANDAMENTO' AND NEW.resultado <> 'EM_ANDAMENTO'
       AND NEW.data_fim IS NULL THEN
        SET NEW.data_fim = CURRENT_TIMESTAMP;
    END IF;
END//

-- Cada vitoria credita dinheiro uma unica vez, ao sair de EM_ANDAMENTO para VITORIA.
CREATE TRIGGER trg_batalha_recompensa_dinheiro
AFTER UPDATE ON batalha
FOR EACH ROW
BEGIN
    IF NEW.resultado = 'VITORIA' AND OLD.resultado = 'EM_ANDAMENTO' THEN
        UPDATE perfil
           SET dinheiro = dinheiro + NEW.dinheiro_ganho
         WHERE id = NEW.id_perfil;
    END IF;
END//

-- Ao vencer o chefe final de uma area pela primeira vez, concede 2 Pedras Universais.
-- A tabela area_concluida evita recompensas duplicadas.
CREATE TRIGGER trg_batalha_recompensa_area
AFTER UPDATE ON batalha
FOR EACH ROW
BEGIN
    DECLARE v_chefe INT;
    DECLARE v_item INT;
    DECLARE v_quantidade INT;
    DECLARE v_inventario INT;
    DECLARE v_ordem TINYINT UNSIGNED;
    DECLARE v_nova_conclusao INT DEFAULT 0;

    IF NEW.resultado = 'VITORIA'
       AND OLD.resultado = 'EM_ANDAMENTO'
       AND NEW.tipo_batalha = 'CHEFE'
       AND NEW.id_area IS NOT NULL THEN

        SELECT id_personagem_chefe, id_item_recompensa, quantidade_recompensa, ordem
          INTO v_chefe, v_item, v_quantidade, v_ordem
          FROM area_jogo WHERE id = NEW.id_area;

        IF NEW.id_personagem_adversario <> v_chefe THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'So o chefe final cadastrado pode concluir a area e entregar a recompensa.';
        END IF;

        INSERT IGNORE INTO area_concluida (id_perfil, id_area, id_batalha)
        VALUES (NEW.id_perfil, NEW.id_area, NEW.id);
        SET v_nova_conclusao = ROW_COUNT();

        IF v_nova_conclusao = 1 THEN
            SELECT id INTO v_inventario
              FROM inventario WHERE id_perfil = NEW.id_perfil;

            INSERT INTO inventario_item (id_inventario, id_item, quantidade)
            VALUES (v_inventario, v_item, v_quantidade)
            ON DUPLICATE KEY UPDATE quantidade = quantidade + v_quantidade;

            INSERT INTO log_batalha (id_batalha, turno, tipo_evento, mensagem)
            VALUES (NEW.id, 0, 'RECOMPENSA_AREA',
                    'Area concluida: voce recebeu 2 Pedras Universais.');

            INSERT INTO progresso_area (id_perfil, id_area, id_no_atual, estado, data_inicio)
            VALUES (NEW.id_perfil, NEW.id_area, NULL, 'CONCLUIDA', CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE id_no_atual = NULL, estado = 'CONCLUIDA';

            -- Libera o proximo capitulo em ordem, se existir. As rotas dentro
            -- de cada capitulo continuam sendo ramificadas por area_conexao.
            INSERT INTO progresso_area (id_perfil, id_area, id_no_atual, estado)
            SELECT NEW.id_perfil, a.id, NULL, 'DISPONIVEL'
              FROM area_jogo a WHERE a.ordem = v_ordem + 1
            ON DUPLICATE KEY UPDATE
                estado = IF(estado = 'BLOQUEADA', 'DISPONIVEL', estado);
        END IF;
    END IF;
END//

-- O fim do jogo e acionado pelo evento em que Rayquaza Shiny derrota Deoxys.
-- A batalha precisa estar marcada como FINAL. O frontend consulta jogo_concluido
-- no perfil e exibe os creditos estilo Star Wars.
CREATE TRIGGER trg_log_rayquaza_derrota_deoxys_concluir_jogo
AFTER INSERT ON log_batalha
FOR EACH ROW
BEGIN
    DECLARE v_nome_alvo VARCHAR(40);
    DECLARE v_tipo_batalha VARCHAR(20);
    DECLARE v_id_perfil INT;

    IF NEW.tipo_evento = 'RAYQUAZA_HITKILL'
       AND NEW.atacante_shiny = TRUE
       AND NEW.id_pokemon_alvo IS NOT NULL THEN

        SELECT nome INTO v_nome_alvo
          FROM pokemon WHERE id = NEW.id_pokemon_alvo;

        IF LOWER(v_nome_alvo) = 'deoxys' THEN
            SELECT tipo_batalha, id_perfil
              INTO v_tipo_batalha, v_id_perfil
              FROM batalha WHERE id = NEW.id_batalha;

            IF v_tipo_batalha = 'FINAL' THEN
                UPDATE perfil
                   SET jogo_concluido = TRUE,
                       data_conclusao = COALESCE(data_conclusao, CURRENT_TIMESTAMP)
                 WHERE id = v_id_perfil;
            END IF;
        END IF;
    END IF;
END//

DELIMITER ;

-- ================================================================
-- DADOS INICIAIS: tipos
-- IDs usados pelo grafico de efetividade abaixo.
-- ================================================================
INSERT INTO tipo_pokemon (id, nome) VALUES
(1,'NORMAL'), (2,'FOGO'), (3,'AGUA'), (4,'ELETRICO'),
(5,'GRAMA'), (6,'GELO'), (7,'LUTADOR'), (8,'VENENO'),
(9,'TERRA'), (10,'VOADOR'), (11,'PSIQUICO'), (12,'INSETO'),
(13,'PEDRA'), (14,'FANTASMA'), (15,'DRAGAO'), (16,'SOMBRIO'),
(17,'ACO'), (18,'FADA'), (19,'ALIENX'), (20,'TUDO');

-- Combate de tipos moderno com um unico tipo por especie.
-- Pares ausentes significam dano neutro (x1).
-- ALIENX e TUDO sao tipos personalizados; suas interacoes ficam neutras ate serem definidas pelo grupo.
INSERT INTO tipo_efetividade (tipo_atacante_id, tipo_defensor_id, multiplicador) VALUES
-- NORMAL
(1,13,0.50),(1,14,0.00),(1,17,0.50),
-- FOGO
(2,5,2.00),(2,6,2.00),(2,12,2.00),(2,17,2.00),(2,2,0.50),(2,3,0.50),(2,13,0.50),(2,15,0.50),
-- AGUA
(3,2,2.00),(3,9,2.00),(3,13,2.00),(3,3,0.50),(3,5,0.50),(3,15,0.50),
-- ELETRICO
(4,3,2.00),(4,10,2.00),(4,4,0.50),(4,5,0.50),(4,15,0.50),(4,9,0.00),
-- GRAMA
(5,3,2.00),(5,9,2.00),(5,13,2.00),(5,2,0.50),(5,5,0.50),(5,8,0.50),(5,10,0.50),(5,12,0.50),(5,15,0.50),(5,17,0.50),
-- GELO
(6,5,2.00),(6,9,2.00),(6,10,2.00),(6,15,2.00),(6,2,0.50),(6,3,0.50),(6,6,0.50),(6,17,0.50),
-- LUTADOR
(7,1,2.00),(7,6,2.00),(7,13,2.00),(7,16,2.00),(7,17,2.00),(7,8,0.50),(7,10,0.50),(7,11,0.50),(7,12,0.50),(7,18,0.50),(7,14,0.00),
-- VENENO
(8,5,2.00),(8,18,2.00),(8,8,0.50),(8,9,0.50),(8,13,0.50),(8,14,0.50),(8,17,0.00),
-- TERRA
(9,2,2.00),(9,4,2.00),(9,8,2.00),(9,13,2.00),(9,17,2.00),(9,5,0.50),(9,12,0.50),(9,10,0.00),
-- VOADOR
(10,5,2.00),(10,7,2.00),(10,12,2.00),(10,4,0.50),(10,13,0.50),(10,17,0.50),
-- PSIQUICO
(11,7,2.00),(11,8,2.00),(11,11,0.50),(11,17,0.50),(11,16,0.00),
-- INSETO
(12,5,2.00),(12,11,2.00),(12,16,2.00),(12,2,0.50),(12,7,0.50),(12,8,0.50),(12,10,0.50),(12,14,0.50),(12,17,0.50),(12,18,0.50),
-- PEDRA
(13,2,2.00),(13,6,2.00),(13,10,2.00),(13,12,2.00),(13,7,0.50),(13,9,0.50),(13,17,0.50),
-- FANTASMA
(14,11,2.00),(14,14,2.00),(14,16,0.50),(14,1,0.00),
-- DRAGAO
(15,15,2.00),(15,17,0.50),(15,18,0.00),
-- SOMBRIO
(16,11,2.00),(16,14,2.00),(16,7,0.50),(16,16,0.50),(16,18,0.50),
-- ACO
(17,6,2.00),(17,13,2.00),(17,18,2.00),(17,2,0.50),(17,3,0.50),(17,4,0.50),(17,17,0.50),
-- FADA
(18,7,2.00),(18,15,2.00),(18,16,2.00),(18,2,0.50),(18,8,0.50),(18,17,0.50);

-- Itens minimos exigidos pelo trabalho. Precos sao valores iniciais sugeridos.
INSERT INTO item (nome, descricao, preco, tipo_efeito, valor_efeito, status_curado) VALUES
('Potion','Recupera 20 HP.',300,'CURA_HP',20,NULL),
('Super Potion','Recupera 50 HP.',700,'CURA_HP',50,NULL),
('Hyper Potion','Recupera 200 HP.',1200,'CURA_HP',200,NULL),
('Max Potion','Recupera 100% do HP.',2500,'CURA_TOTAL',100,NULL),
('Full Restore','Recupera 100% do HP e cura todos os status.',3000,'CURA_TOTAL',100,'TODOS'),
('Antidote','Cura envenenamento.',200,'CURA_STATUS',NULL,'VENENO'),
('Burn Heal','Cura queimadura.',200,'CURA_STATUS',NULL,'QUEIMADURA'),
('Ice Heal','Cura congelamento.',200,'CURA_STATUS',NULL,'CONGELAMENTO'),
('Awakening','Cura sono.',200,'CURA_STATUS',NULL,'SONO'),
('Paralyze Heal','Cura paralisia.',200,'CURA_STATUS',NULL,'PARALISIA'),
('Full Heal','Cura todas as condicoes de status.',600,'CURA_STATUS',NULL,'TODOS'),
('Revive','Revive um Pokemon desmaiado com 50% do HP.',1500,'REVIVER',50,NULL),
('Pedra Universal','Pode evoluir qualquer especie que tenha uma regra de evolucao cadastrada.',2000,'PEDRA_EVOLUCAO',NULL,NULL);


-- Exemplos de DQL para demonstracao:
-- SELECT * FROM vw_resumo_perfil;
-- SELECT * FROM vw_historico_batalhas ORDER BY data_inicio DESC;
-- SELECT * FROM vw_catalogo_loja;
--
-- Depois de importar as especies de Hoenn, marquem Rayquaza e Deoxys como nao capturaveis:
-- UPDATE pokemon SET capturavel = FALSE WHERE LOWER(nome) IN ('rayquaza','deoxys');
--
-- Depois de cadastrar os personagens e o item Pedra Universal, cadastrem as areas.
-- Exemplo (ajustem os nomes conforme as areas reais do jogo):
-- INSERT INTO area_jogo (nome, ordem, id_personagem_chefe, id_item_recompensa, quantidade_recompensa)
-- SELECT 'Area 1', 1, p.id, i.id, 2 FROM personagem p CROSS JOIN item i
-- WHERE p.nome = 'Yamamoto Genryuusai' AND i.nome = 'Pedra Universal';
-- Quando a batalha contra esse chefe tiver id_area preenchido e resultar em VITORIA,
-- o trigger concede 2 Pedras Universais apenas na primeira conclusao da area.
-- Na batalha final (tipo_batalha='FINAL'), registre no log o evento
-- tipo_evento='RAYQUAZA_HITKILL', id_pokemon_atacante=Rayquaza,
-- id_pokemon_alvo=Deoxys e atacante_shiny=TRUE. Isso marca jogo_concluido=TRUE.
-- Exemplo, substituindo os IDs pelos reais do banco:
-- INSERT INTO log_batalha
-- (id_batalha, turno, tipo_evento, mensagem, id_pokemon_atacante, id_pokemon_alvo, atacante_shiny)
-- VALUES (1, 0, 'RAYQUAZA_HITKILL',
-- 'Rayquaza Shiny 100% derrota Deoxys com um unico ataque. Os creditos comecam.',
-- 1, 2, TRUE);
-- Exemplo DTL na aplicacao: agrupe a atualizacao do resultado da batalha e
-- outras recompensas dentro de START TRANSACTION ... COMMIT; use ROLLBACK em caso de erro.
-- Exemplo ilustrativo (somente apos existir uma batalha e um perfil):
-- START TRANSACTION;
-- UPDATE perfil SET dinheiro = dinheiro + 500 WHERE id = 1;
-- UPDATE batalha SET resultado = 'VITORIA', dinheiro_ganho = 500,
--        data_fim = CURRENT_TIMESTAMP WHERE id = 1;
-- COMMIT;


-- Exemplo de DTL exigido no trabalho (executar na aplicacao/PDO ou adaptar ao MySQL):
-- START TRANSACTION;
-- UPDATE perfil SET dinheiro = dinheiro - 2000 WHERE id = 1 AND dinheiro >= 2000;
-- -- verificar ROW_COUNT(); se for 0, executar ROLLBACK.
-- INSERT INTO compra_loja (id_perfil, id_item, quantidade) VALUES (1, 13, 1);
-- COMMIT;
-- Em PHP, prefiram PDO::beginTransaction(), commit() e rollBack() para agrupar a operacao.

