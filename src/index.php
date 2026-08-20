<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importador Fiscal - ISS</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .box { border: 1px solid #ddd; border-radius: 10px; padding: 24px; }
        textarea { width: 100%; min-height: 220px; margin-top: 10px; }
        .progresso { width: 100%; background: #ddd; margin-top: 15px; }
        .progresso-barra { width: 0; height: 20px; background: #4caf50; text-align: center; color: white; }
        button { padding: 10px 16px; margin-right: 8px; cursor: pointer; }
        code { background: #f2f2f2; padding: 2px 5px; }
    </style>
</head>
<body>
    <h1>Importador Fiscal — ISS</h1>

    <div class="box">
        <p>Envie um TXT com 10 campos separados por <code>;</code>:</p>
        <pre>numero;serie;data_emissao;cnpj_prestador;razao_social_prestador;documento_tomador;razao_social_tomador;codigo_lc116;municipio_ibge;valor_servico</pre>

        <p>Exemplo:</p>
        <pre>1001;A;2026-08-20;12345678000190;Empresa Exemplo Ltda;98765432000110;Cliente Exemplo Ltda;01.01;3550407;10000,00</pre>

        <form method="POST" enctype="multipart/form-data" action="processar.php">
            <label for="arquivo">Arquivo TXT:</label><br>
            <input type="file" name="arquivo" id="arquivo" accept=".txt" required>

            <br><br>
            <label for="conteudo">Conteúdo:</label>
            <textarea id="conteudo" readonly></textarea>

            <div class="progresso"><div class="progresso-barra" id="barra"></div></div>
            <br>
            <button type="button" onclick="lerArquivo()">Carregar conteúdo</button>
            <button type="submit">Processar e calcular ISS</button>
        </form>
    </div>

<script>
function lerArquivo() {
    const input = document.getElementById('arquivo');
    const textarea = document.getElementById('conteudo');
    const file = input.files[0];
    if (!file) return alert('Selecione um arquivo TXT.');

    const reader = new FileReader();
    reader.onload = e => textarea.value = e.target.result;
    reader.readAsText(file, 'UTF-8');
}

document.querySelector('form').addEventListener('submit', function () {
    const barra = document.getElementById('barra');
    let width = 0;
    const timer = setInterval(() => {
        width = Math.min(width + 2, 95);
        barra.style.width = width + '%';
        barra.textContent = width + '%';
    }, 50);
    setTimeout(() => clearInterval(timer), 2500);
});
</script>
</body>
</html>
