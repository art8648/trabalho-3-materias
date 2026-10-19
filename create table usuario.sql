create table usuario(
    id int auto_increment primary key,
    nome varchar(100) not null,
    senha varchar(25) not null,
    data_criacao date default CURRENT_TIMESTAMP()
)

create table perfil(
    id int auto_increment primary key,
    id_avatar varchar(200),
    nome_perfil varchar(100) not null,
    personagem int,
    dinheiro int,
    data_criacao date default CURRENT_TIMESTAMP()
)

create table personagem(
    id int auto_increment primary key,
    id_time int,
    nome varchar(50) not null unique,
    jogavel boolean,
    imagem varchar(200) not null unique
)

create table pokemon(
    id int auto_increment primary key, 
    nome varchar(20),
    hp int not null check(hp>0),
    atk int not null check(atk>0),
    def int not null check(def>0),
    sp_def int not null check(sp_def>0),
    vel int not null check(vel>0),
    tipo int not null check(tipo>=0 and tipo <=18),
    sexo boolean default (rand() > 0.5),
    imagem varchar(200) not null unique
)

create table pokemon_capturado(
    id int auto_increment primary key,
    id_perfil int,
    id_pokemon int,
    status boolean not null default true,
    hp_atual int not null
)

create table time_pokemon(
    id int auto_increment primary key,
    id_time int,
    id_pokemon int,
    posicao int not null check(posicao>=1 and  posicao<=6) unique(id_pokemon, posicao)
)

create table item(
    id int auto_increment primary key,
    nome varchar(100) not null,
    descricao varchar(200) not null,
    valor int not null check(valor>0)
)

create table times(
    id int auto_increment primary key,
)

create table ataques{
    id int auto_increment primary key,
    nome varchar(100) not null,
    dano int not null,
    chance_status int not null check(chance_status>=0 and chance_status<=100) 

}

create table batalha(
id int auto_increment primary key,

)