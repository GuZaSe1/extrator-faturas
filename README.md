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
