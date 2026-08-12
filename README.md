EXTRATOR DE FATURAS NF3-E COM IA LOCAL

SOBRE O PROJETO:

Este projeto é um sistema desenvolvido em PHP, desenhado para extrair dados de faturas de energia elétrica (NF3-e) em formato PDF.

A grande vantagem desta arquitetura é a utilização de Inteligência Artificial (Ollama) funcionar 100% localmente. Garantindo total privacidade de dados e eliminando custos recorrentes com APIs externas

PRINCIPAIS FUNCIONALIDADES

- Leitura de PDFs: Extração de texto bruto utilizando a biblioteca Smalot/PdfParser
- Processamento com IA Local: Integração com a API do Ollama, suportando modelos como o Qwen 2.5 (7B ou 14B)
- Saídas Estruturadas (JSON): O sistema "obriga" a IA a devolver os dados num formato JSON estrito e tipado, evitando alucinações e facilitando 
  a integração com a base de dados
- Split Inference: Divide o processamento entre a Placa Gráfica (VRAM) e a RAM do sistema

COMO INSTALAR E EXECUTAR

1. Clone o repositório para o seu servidor
2. Instale as dependências do PHP utilizando o Composer executando o comando: composer install
3. Instale o modelo pelo terminal, ex: ollama pull qwen2.5:7b
4. Inicie o Servidor Web. Comando: php -S 0.0.0.0:8000
5. Acesse o navegador via URL através do IP do seu servidor (porta 8000)

## FLUXO PYTHON COM TRÊS AGENTES OLLAMA

O arquivo `python/agentes_nf3e.py` executa um fluxo separado da interface PHP:

1. Lê diretamente o arquivo `texto_aws.txt` com o texto gerado pela AWS.
2. Lê o texto diretamente; arquivos JSON com `texto_por_pagina` também são aceitos.
3. O agente extrator geral processa os dados cadastrais, fiscais, de leitura e o histórico, sem processar produtos.
4. O agente de produtos processa exclusivamente os itens de cobrança e retorna o schema `ProdutosFatura`.
5. O orquestrador reúne as duas extrações em um objeto `DadosFatura`.
6. O agente validador recebe o texto original e o JSON reunido, e apenas aprova ou rejeita o resultado.
7. O script imprime um envelope JSON com metadados, dados extraídos e validação.

Esse fluxo não consulta o MySQL e não chama novamente o Textract. Ele usa somente o texto que já está no arquivo da AWS.

### COMO OS AGENTES SÃO CRIADOS

Neste projeto, um agente é uma instância de `AgenteOllama` com quatro definições principais:

- `modelo`: qual modelo local executa aquele papel;
- `instrucoes`: o prompt de sistema que limita a responsabilidade do agente;
- `tipo_saida`: um modelo Pydantic que gera o JSON Schema enviado ao Ollama;
- limites de contexto e resposta: `num_ctx` e `num_predict`.

O extrator geral usa `prompts/dados_fatura_nf3e.txt` e o schema `DadosFaturaSemProdutos`. O agente de produtos usa `prompts/produtos_nf3e.txt` e o schema `ProdutosFatura`, cuja única chave é `produtos`. O validador usa `prompts/validacao_nf3e.txt` e o schema `ResultadoValidacao`.

Os três agentes são chamados em sequência pelo Python. Eles não conversam diretamente: o orquestrador controla o fluxo, reúne os resultados dos dois extratores e passa o objeto completo ao validador. O schema do validador não possui um campo para devolver dados corrigidos, portanto ele só consegue emitir `aprovado`, `erros` e `observacoes`.

### INSTALAÇÃO

Na raiz do projeto, instale as dependências no ambiente virtual:

```bash
venv/bin/python -m pip install -r python/requirements-agentes.txt
```

O arquivo `python/.env` precisa conter somente a configuração do Ollama. Use `python/.env.example` como referência e inclua:

```dotenv
OLLAMA_EXTRACTOR_MODEL=qwen2.5:7b
OLLAMA_PRODUCTS_MODEL=qwen2.5:7b
OLLAMA_VALIDATOR_MODEL=qwen2.5:7b
```

`OLLAMA_PRODUCTS_MODEL` é opcional e, quando ausente, reutiliza o modelo do extrator geral. Os três papéis usam `qwen2.5:7b` por padrão. Garanta que o modelo esteja instalado:

```bash
ollama pull qwen2.5:7b
```

### EXECUÇÃO

```bash
venv/bin/python python/agentes_nf3e.py
```

Por padrão, o script lê `texto_aws.txt` na raiz. Para usar outro arquivo e, opcionalmente, identificar a origem nos metadados:

```bash
venv/bin/python python/agentes_nf3e.py \
  --texto-aws caminho/outro_texto_aws.txt \
  --cod-empresa EMPRESA \
  --cod-extracao 123
```

O JSON final é enviado para `stdout`. Falhas técnicas são enviadas para `stderr`, o que permite salvar somente a resposta válida:

```bash
venv/bin/python python/agentes_nf3e.py > resultado.json
```

O bloco `metadados.metricas_ollama` registra, separadamente para extrator, produtos e validador, os tempos informados pelo Ollama, tokens processados, velocidade de geração e motivo de término. `metadados.tempos_segundos` continua mostrando o tempo observado pelo processo Python.

Códigos de saída:

- `0`: o validador aprovou a extração;
- `2`: o fluxo terminou, mas o validador rejeitou a extração;
- `1`: ocorreu uma falha de configuração, leitura do arquivo da AWS, Ollama ou schema.

### LOG DE CAMPOS REJEITADOS

Quando o validador rejeita uma extração, o script acrescenta uma linha JSON em:

```text
logs/agentes_nf3e_validacao.log
```

Cada registro contém data e hora em UTC, empresa e extração opcionais, arquivo de origem, modelos utilizados, métricas do Ollama, `campos_errados`, detalhes dos erros e observações. O texto completo do arquivo da AWS e o bloco completo de `dados_extraidos` não são gravados nesse arquivo.
