const SistemaPerfis = {

    perfilAtual() {
        return localStorage.getItem("perfil_ativo");
    },

    selecionarPerfil(id) {
        localStorage.setItem("perfil_ativo", id);
    },

    carregarProgresso() {
        const perfil = this.perfilAtual();

        if (!perfil) return {};

        return JSON.parse(
            localStorage.getItem("save_" + perfil)
        ) || {};
    },

    salvarProgresso(dados) {
        const perfil = this.perfilAtual();

        if (!perfil) {
            console.error("Nenhum perfil selecionado!");
            return;
        }

        localStorage.setItem(
            "save_" + perfil,
            JSON.stringify(dados)
        );
    }
};