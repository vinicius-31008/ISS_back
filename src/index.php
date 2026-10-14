<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frontend — Importador Fiscal ISS</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .box { border: 1px solid #ddd; border-radius: 10px; padding: 24px; }
        input[type="text"], input[type="file"] { width: 100%; box-sizing: border-box; padding: 10px; margin: 8px 0 15px; }
        button { padding: 10px 16px; cursor: pointer; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        pre { background: #f4f4f4; padding: 15px; overflow: auto; border-radius: 6px; white-space: pre-wrap; }
        .resultado { margin-top: 20px; }
        .sucesso { border-left: 4px solid #2e7d32; padding: 12px; background: #edf7ed; }
        .erro { border-left: 4px solid #c62828; padding: 12px; background: #fdecec; }
        .info { border-left: 4px solid #1976d2; padding: 12px; background: #edf5ff; }
    </style>
</head>
<body>
    <h1>Frontend — Importador Fiscal ISS</h1>

    <div class="box">
        <p>O frontend somente envia o TXT para a API. O processamento fiscal acontece no backend.</p>

        <label for="apiUrl"><strong>URL da API:</strong></label>
        <input
            type="text"
            id="apiUrl"
            value="/ImportadorFiscalISS/src/api/importar.php"
        >

        <button type="button" onclick="testarApi()">Testar API</button>

        <hr><br>

        <div id="areaLogin">
            <label for="email"><strong>E-mail:</strong></label>
            <input type="text" id="email" autocomplete="username">

            <label for="senha"><strong>Senha:</strong></label>
            <input type="password" id="senha" autocomplete="current-password">

            <button type="button" onclick="entrar()">Entrar</button>
        </div>

        <div id="areaSessao" style="display: none">
            <p id="sessaoInfo"></p>
            <button type="button" onclick="sair()">Sair</button>
        </div>

        <div id="areaTrocarSenha" style="display: none">
            <div class="info">Senha provisória: defina uma nova senha para continuar.</div>

            <label for="senhaAtual"><strong>Senha atual:</strong></label>
            <input type="password" id="senhaAtual" autocomplete="current-password">

            <label for="novaSenha"><strong>Nova senha (8 a 72 caracteres):</strong></label>
            <input type="password" id="novaSenha" autocomplete="new-password">

            <button type="button" onclick="trocarSenha()">Trocar senha</button>
        </div>

        <hr><br>

        <label for="arquivo"><strong>Arquivo TXT:</strong></label>
        <input type="file" id="arquivo" accept=".txt">

        <button id="btnProcessar" type="button" onclick="enviarArquivo()">
            Enviar para API
        </button>

        <div class="resultado" id="resultado"></div>
    </div>

<script>
// Sessão do login (token + usuário/empresa), guardada só enquanto a aba estiver aberta.
let sessao = lerSessaoSalva();
mostrarSessao();

function urlIrma(arquivoPhp) {
    const apiUrl = document.getElementById('apiUrl').value.trim();
    return apiUrl.replace(/\/importar\.php(\?.*)?$/i, '/' + arquivoPhp);
}

function lerSessaoSalva() {
    try {
        return JSON.parse(sessionStorage.getItem('sessaoIss')) || null;
    } catch {
        return null;
    }
}

function salvarSessao(novaSessao) {
    sessao = novaSessao;
    try {
        if (sessao) {
            sessionStorage.setItem('sessaoIss', JSON.stringify(sessao));
        } else {
            sessionStorage.removeItem('sessaoIss');
        }
    } catch {
        // Sem sessionStorage a sessão vale só até recarregar a página.
    }
    mostrarSessao();
}

function mostrarSessao() {
    document.getElementById('areaLogin').style.display = sessao ? 'none' : '';
    document.getElementById('areaSessao').style.display = sessao ? '' : 'none';
    // Com senha provisória a API recusa tudo além da troca de senha.
    const trocarSenhaPendente = Boolean(sessao && sessao.usuario.trocar_senha);
    document.getElementById('areaTrocarSenha').style.display = trocarSenhaPendente ? '' : 'none';
    document.getElementById('btnProcessar').disabled = !sessao || trocarSenhaPendente;

    if (sessao) {
        document.getElementById('sessaoInfo').innerHTML =
            `Conectado como <strong>${escapeHtml(sessao.usuario.nome)}</strong> — ` +
            `${escapeHtml(sessao.empresa.razao_social)} (CNPJ ${escapeHtml(sessao.empresa.cnpj)})`;
    }
}

async function entrar() {
    const resultado = document.getElementById('resultado');
    const email = document.getElementById('email').value.trim();
    const senha = document.getElementById('senha').value;

    try {
        const resposta = await fetch(urlIrma('login.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, senha })
        });
        const dados = await lerJson(resposta);

        if (!resposta.ok || !dados.sucesso) {
            throw new Error(dados.mensagem || 'Falha no login.');
        }

        document.getElementById('senha').value = '';
        salvarSessao(dados.dados);
        resultado.innerHTML = '';
    } catch (erro) {
        resultado.innerHTML = `<div class="erro"><strong>Login:</strong> ${escapeHtml(erro.message)}</div>`;
    }
}

async function trocarSenha() {
    const resultado = document.getElementById('resultado');

    try {
        const resposta = await fetch(urlIrma('trocar-senha.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + sessao.token
            },
            body: JSON.stringify({
                senha_atual: document.getElementById('senhaAtual').value,
                nova_senha: document.getElementById('novaSenha').value
            })
        });
        const dados = await lerJson(resposta);

        if (!resposta.ok || !dados.sucesso) {
            throw new Error(dados.mensagem || 'Falha ao trocar a senha.');
        }

        document.getElementById('senhaAtual').value = '';
        document.getElementById('novaSenha').value = '';
        salvarSessao({ ...sessao, usuario: { ...sessao.usuario, trocar_senha: false } });
        resultado.innerHTML = '<div class="sucesso">Senha alterada.</div>';
    } catch (erro) {
        resultado.innerHTML = `<div class="erro"><strong>Troca de senha:</strong> ${escapeHtml(erro.message)}</div>`;
    }
}

async function sair() {
    try {
        await fetch(urlIrma('logout.php'), {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + sessao.token }
        });
    } catch {
        // Mesmo sem resposta da API, a sessão local é descartada.
    }
    salvarSessao(null);
}

async function lerJson(resposta) {
    const texto = await resposta.text();
    try {
        return JSON.parse(texto);
    } catch {
        console.error('Resposta bruta recebida da API:', texto);
        throw new Error(`A API não retornou JSON (HTTP ${resposta.status}). Verifique a URL do endpoint.`);
    }
}

async function testarApi() {
    const apiUrl = document.getElementById('apiUrl').value.trim();
    const healthUrl = apiUrl.replace(/\/importar\.php(\?.*)?$/i, '/health.php');
    const resultado = document.getElementById('resultado');

    resultado.innerHTML = '<div class="info">Testando conexão com a API...</div>';

    try {
        const resposta = await fetch(healthUrl, { method: 'GET' });
        const texto = await resposta.text();

        let dados;
        try {
            dados = JSON.parse(texto);
        } catch {
            console.error('Resposta da API:', texto);
            throw new Error(
                `A URL retornou conteúdo que não é JSON (HTTP ${resposta.status}). Verifique o caminho da API.`
            );
        }

        if (!resposta.ok || !dados.sucesso) {
            throw new Error(dados.mensagem || 'API indisponível.');
        }

        resultado.innerHTML = `
            <div class="sucesso">
                <strong>API online!</strong>
                <pre>${escapeHtml(JSON.stringify(dados, null, 2))}</pre>
            </div>
        `;
    } catch (erro) {
        resultado.innerHTML = `
            <div class="erro">
                <strong>Falha ao acessar a API:</strong><br>
                ${escapeHtml(erro.message)}
            </div>
        `;
    }
}

async function enviarArquivo() {
    const input = document.getElementById('arquivo');
    const resultado = document.getElementById('resultado');
    const botao = document.getElementById('btnProcessar');
    const apiUrl = document.getElementById('apiUrl').value.trim();

    if (!apiUrl) {
        resultado.innerHTML = '<div class="erro">Informe a URL da API.</div>';
        return;
    }

    if (!sessao) {
        resultado.innerHTML = '<div class="erro">Faça login antes de enviar o arquivo.</div>';
        return;
    }

    if (!input.files.length) {
        resultado.innerHTML = '<div class="erro">Selecione um arquivo TXT.</div>';
        return;
    }

    const arquivo = input.files[0];

    if (!arquivo.name.toLowerCase().endsWith('.txt')) {
        resultado.innerHTML = '<div class="erro">Selecione um arquivo .txt.</div>';
        return;
    }

    const formData = new FormData();
    formData.append('arquivo', arquivo);

    botao.disabled = true;
    resultado.innerHTML = '<div class="info">Enviando arquivo para a API...</div>';

    try {
        const resposta = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + sessao.token },
            body: formData
        });

        if (resposta.status === 401) {
            // Token expirado ou inválido: volta para a tela de login.
            salvarSessao(null);
        }

        // Primeiro lemos como texto.
        // Assim, se Apache/PHP retornar HTML, não teremos
        // "Unexpected token '<'" e conseguimos identificar o problema.
        const texto = await resposta.text();

        let dados;

        try {
            dados = JSON.parse(texto);
        } catch {
            console.error('Resposta bruta recebida da API:', texto);

            throw new Error(
                `A API não retornou JSON. HTTP ${resposta.status}. ` +
                `Verifique a URL do endpoint e os erros do PHP.`
            );
        }

        if (!resposta.ok || !dados.sucesso) {
            throw new Error(
                dados.mensagem || dados.erro || 'Falha ao processar o arquivo.'
            );
        }

        const iss = Number(dados.dados.iss_total || 0);

        resultado.innerHTML = `
            <div class="sucesso">
                <h2>Importação concluída</h2>

                <p><strong>Importação:</strong>
                    ${dados.dados.importacao_id}
                </p>

                <p><strong>Arquivo:</strong>
                    ${escapeHtml(dados.dados.arquivo)}
                </p>

                <p><strong>Linhas:</strong>
                    ${dados.dados.total_linhas}
                </p>

                <p><strong>Processadas:</strong>
                    ${dados.dados.total_processadas}
                </p>

                <p><strong>Erros:</strong>
                    ${dados.dados.total_erros}
                </p>

                <p><strong>ISS total:</strong>
                    R$ ${formatarMoeda(iss)}
                </p>

                <h3>Resposta da API</h3>
                <pre>${escapeHtml(JSON.stringify(dados, null, 2))}</pre>
            </div>
        `;
    } catch (erro) {
        resultado.innerHTML = `
            <div class="erro">
                <h3>Erro ao enviar arquivo</h3>
                <p>${escapeHtml(erro.message)}</p>
                <p>
                    Abra o Console do navegador (F12) para visualizar
                    detalhes adicionais.
                </p>
            </div>
        `;
    } finally {
        botao.disabled = !sessao || Boolean(sessao.usuario.trocar_senha);
    }
}

function formatarMoeda(valor) {
    return valor.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escapeHtml(valor) {
    return String(valor)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
</script>
</body>
</html>
