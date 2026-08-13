# Guia de instalação e execução — PHP

Este guia cobre a aplicação web PHP que recebe uma fatura NF3-e em PDF, extrai
o texto com `pdftotext` e processa os dados com regras determinísticas e, quando
necessário, com um modelo local no Ollama.

## 1. Como o fluxo funciona

1. `index.php` apresenta o formulário de upload.
2. `processar.php` valida o arquivo e usa `spatie/pdf-to-text` para chamar o
   executável `pdftotext`.
3. `processador_ia.php` tenta extrair os campos por regras determinísticas.
4. Se ainda faltarem campos, o PHP consulta o Ollama.
5. O resultado é exibido no navegador como JSON.

## 2. Pré-requisitos

- PHP 8.2 ou superior;
- Composer 2;
- extensões PHP `curl`, `fileinfo` e `json` habilitadas;
- função PHP `proc_open` habilitada, pois o Symfony Process a utiliza;
- Poppler, que fornece o executável `pdftotext`;
- Ollama instalado e em execução;
- modelo `qwen2.5:7b` instalado no Ollama.

## 3. Instalar as dependências PHP

Na raiz do projeto:

```bash
composer install
```

Esse comando recria `vendor/`, que não é versionado. Não use `composer update`
para uma instalação normal, pois ele pode trocar as versões registradas no
`composer.lock`.

Para verificar a instalação:

```bash
composer validate
php -r "require 'vendor/autoload.php'; echo 'Composer OK', PHP_EOL;"
```

## 4. Preparar o Ollama

Instale o modelo utilizado pelo PHP:

```bash
ollama pull qwen2.5:7b
```

Inicie o servidor caso ele não esteja rodando:

```bash
ollama serve
```

Em outro terminal, valide a comunicação:

```bash
curl http://localhost:11434/api/tags
```

O PHP usa atualmente estes valores padrão, definidos em
`src/Ollama/OllamaClient.php`:

```text
URL:    http://localhost:11434/api/generate
Modelo: qwen2.5:7b
```

O fluxo PHP não lê `OLLAMA_URL` ou `OLLAMA_MODEL` de um arquivo `.env`. Para
usar outro endereço ou modelo, é necessário alterar a construção de
`OllamaClient` no código ou adaptar a classe para ler variáveis de ambiente.

## 5. Iniciar a aplicação

O servidor embutido do PHP é apropriado para desenvolvimento local. Execute na
raiz do projeto:

```bash
php \
  -d upload_max_filesize=4000K \
  -d post_max_size=4500K \
  -S 0.0.0.0:8000
```

Depois acesse:

```text
http://localhost:8000
```

Selecione uma fatura PDF e clique em **Processar**.

## 6. Debug e opções de execução

O formulário possui a opção **Debug**. Quando habilitada, a aplicação grava
eventos em:

```text
logs/app.log
```

A pasta é criada automaticamente e os logs não são versionados. Também é
possível ativar o debug ao iniciar o servidor:

```bash
EXTRATOR_DEBUG=1 php \
  -d upload_max_filesize=4000K \
  -d post_max_size=4500K \
  -S 0.0.0.0:8000
```

Para forçar o uso da IA mesmo quando a extração determinística seria
suficiente:

```bash
EXTRATOR_FORCAR_IA=1 php \
  -d upload_max_filesize=4000K \
  -d post_max_size=4500K \
  -S 0.0.0.0:8000
```

## 7. Limitações dos PDFs

- O limite da aplicação é 4000 KiB por arquivo.
- O MIME real precisa ser `application/pdf`.
- O PDF precisa possuir uma camada de texto.
- PDFs que contêm apenas imagens precisam passar por OCR antes deste fluxo.
- O PHP usa `pdftotext` com a opção `layout`, preservando aproximadamente o
  posicionamento das colunas.
