EXTRATOR DE FATURAS NF3-E COM IA LOCAL

SOBRE O PROJETO:

Este projeto é um sistema desenvolvido em PHP, desenhado para extrair dados de faturas de energia elétrica (NF3-e) em formato PDF.

A grande vantagem desta arquitetura é a utilização de Inteligência Artificial (Ollama) funcionar 100% localmente. Garantindo total privacidade de dados e eliminando custos recorrentes com APIs externas

PRINCIPAIS FUNCIONALIDADES

- Leitura de PDFs: Extração de texto bruto utilizando `spatie/pdf-to-text`
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

## FLUXO PYTHON COM DOIS AGENTES OLLAMA

O arquivo `python/agentes_nf3e.py` executa um fluxo separado da interface PHP:

1. No modo banco, busca `cod_job_extracao` no MySQL por empresa e extração.
2. Recupera todas as páginas desse Job ID no AWS Textract e ordena as linhas.
3. O agente extrator transforma o texto em um objeto `DadosFatura`.
4. O agente validador recebe o texto original e o JSON extraído, e apenas aprova ou rejeita o resultado.
5. O script imprime um envelope JSON com metadados, dados extraídos e validação.

O modo arquivo anterior continua disponível para testes locais. Ele aceita texto simples
ou JSON com `texto_por_pagina`, sem consultar MySQL ou Textract.

### COMO OS AGENTES SÃO CRIADOS

Neste projeto, um agente é uma instância de `AgenteOllama` com quatro definições principais:

- `modelo`: qual modelo local executa aquele papel;
- `instrucoes`: o prompt de sistema que limita a responsabilidade do agente;
- `tipo_saida`: um modelo Pydantic que gera o JSON Schema enviado ao Ollama;
- limites de contexto e resposta: `num_ctx` e `num_predict`.

O agente extrator usa o prompt existente `prompts/fatura_nf3e.txt`, o modelo `qwen2.5:14b` e o schema `DadosFatura`. O agente validador usa `prompts/validacao_nf3e.txt`, o modelo `qwen2.5:7b` e o schema `ResultadoValidacao`.

Os dois agentes são chamados em sequência pelo Python. Eles não conversam diretamente: o orquestrador controla o fluxo e passa explicitamente o resultado do primeiro para o segundo. O schema do validador não possui um campo para devolver dados corrigidos, portanto ele só consegue emitir `aprovado`, `erros` e `observacoes`.

### INSTALAÇÃO

Na raiz do projeto, instale as dependências no ambiente virtual:

```bash
venv/bin/python -m pip install -r python/requirements-agentes.txt
```

Use `python/.env.example` como referência. Para o modo banco, configure MySQL, região/credenciais AWS e Ollama. Os modelos podem ser definidos com:

```dotenv
OLLAMA_EXTRACTOR_MODEL=qwen2.5:14b
OLLAMA_VALIDATOR_MODEL=qwen2.5:7b
```

Se essas duas variáveis não forem informadas, o script já usa esses mesmos modelos como padrão. Garanta que ambos estejam instalados:

```bash
ollama pull qwen2.5:14b
ollama pull qwen2.5:7b
```

### EXECUÇÃO

```bash
venv/bin/python python/agentes_nf3e.py
```

Por padrão, o script lê `texto_aws.txt` na raiz. Para usar outro arquivo e,
opcionalmente, identificar a origem nos metadados:

```bash
venv/bin/python python/agentes_nf3e.py \
  --texto-aws caminho/outro_texto_aws.txt \
  --cod-empresa EMPRESA \
  --cod-extracao 123
```

Para consultar o registro no MySQL, recuperar o texto no Textract e executar os
dois agentes:

```bash
venv/bin/python python/agentes_nf3e.py \
  --banco \
  --cod-empresa EMPRESA \
  --cod-extracao 123
```

A consulta ao MySQL usa uma transação somente leitura. O resultado é impresso em
JSON, mas não é gravado no banco.

O JSON final é enviado para `stdout`. Falhas técnicas são enviadas para `stderr`, o que permite salvar somente a resposta válida:

```bash
venv/bin/python python/agentes_nf3e.py > resultado.json
```

Códigos de saída:

- `0`: o validador aprovou a extração;
- `2`: o fluxo terminou, mas o validador rejeitou a extração;
- `1`: ocorreu uma falha de configuração, leitura do arquivo da AWS, Ollama ou schema.

### LOG DE CAMPOS REJEITADOS

Quando o validador rejeita uma extração, o script acrescenta uma linha JSON em:

```text
logs/agentes_nf3e_validacao.log
```

Cada registro contém data e hora em UTC, empresa e extração opcionais, arquivo de origem, modelos utilizados, `campos_errados`, detalhes dos erros e observações. O texto completo do arquivo da AWS e o bloco completo de `dados_extraidos` não são gravados nesse arquivo.
