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

        <label for="arquivo"><strong>Arquivo TXT:</strong></label>
        <input type="file" id="arquivo" accept=".txt">

        <button id="btnProcessar" type="button" onclick="enviarArquivo()">
            Enviar para API
        </button>

        <div class="resultado" id="resultado"></div>
    </div>

<script>
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
            body: formData
        });

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
        botao.disabled = false;
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
