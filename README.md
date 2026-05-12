 EXTRATOR DE FATURAS NF3-E COM INTELIGENCIA ARTIFICIAL LOCAL

SOBRE O PROJETO
Este projeto é um sistema automatizado desenvolvido em PHP, desenhado para extrair dados estruturados (JSON) de faturas de energia elétrica (NF3-e) em formato PDF

A grande vantagem desta arquitetura é a utilização de Inteligência Artificial (Ollama) correr 100% localmente. Isto garante total privacidade de dados sensíveis dos clientes 
e elimina custos recorrentes com APIs externas

PRINCIPAIS FUNCIONALIDADES
- Leitura de PDFs: Extração de texto bruto diretamente dos ficheiros PDF utilizando a biblioteca Smalot/PdfParser
- Processamento com IA Local: Integração robusta com a API do Ollama, suportando modelos avançados como o Qwen 2.5 (7B ou 14B)
- Saídas Estruturadas (JSON Schema): O sistema "obriga" a IA a devolver os dados num formato JSON estrito e tipado, evitando alucinações e facilitando 
  a integração com a base de dados
- Inferência Híbrida (Split Inference): Arquitetura otimizada para HomeLabs, dividindo a carga de processamento entre a Placa Gráfica (VRAM) e a RAM do sistema

COMO INSTALAR E EXECUTAR
1. Clone o repositório para o seu servidor
2. Instale as dependências do PHP utilizando o Composer executando o comando: composer install
3. Instale o modelo desejado pelo terminal, ex: ollama pull qwen2.5:7b
4. Inicie o Servidor Web. Pode utilizar o servidor embutido do PHP na raiz do projeto:
   Comando: php -S 0.0.0.0:8000
5. Acesse o navegador  via URL através do IP do seu servidor (porta 8000)
