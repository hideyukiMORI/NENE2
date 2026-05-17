---
layout: home

hero:
  name: "NENE2"
  text: "Framework PHP API Minimalista"
  tagline: Construa APIs JSON rapidamente. OpenAPI e MCP embutidos. Pronto para IA desde o primeiro dia.
  actions:
    - theme: brand
      text: Começar →
      link: /pt-br/tutorial/first-api
    - theme: alt
      text: Ver no GitHub
      link: https://github.com/hideyukiMORI/NENE2
    - theme: alt
      text: Packagist
      link: https://packagist.org/packages/hideyukimori/nene2

features:
  - icon: 🚀
    title: Em funcionamento em minutos
    details: Um simples composer require hideyukimori/nene2 e você tem uma API JSON funcionando com health checks, request IDs e erros Problem Details — antes de escrever uma única rota.

  - icon: 📄
    title: OpenAPI em primeiro lugar
    details: Cada endpoint que você cria vem com um contrato OpenAPI. Swagger UI está incluído. O contrato é o que você entrega ao seu cliente, não uma reflexão posterior.

  - icon: 🤖
    title: Pronto para MCP
    details: Um servidor MCP local expõe sua API como ferramentas que agentes de IA (Claude, Cursor) podem chamar diretamente. Sem integração especial — lê do seu catálogo OpenAPI.

  - icon: 🛡️
    title: Erros RFC 9457
    details: Cada resposta de erro é um objeto Problem Details — uma estrutura JSON legível por máquinas com type, title, status e detail. Sem exceções brutas em produção.

  - icon: 🧱
    title: Arquitetura limpa
    details: UseCase → RepositoryInterface → adaptador PDO. Cada camada testável isoladamente. Sem mágica, sem fiação oculta, sem framework invadindo seu domínio.

  - icon: 🔬
    title: PHPStan nível 8
    details: Análise estática no nível mais estrito. Se passar no PHPStan, não vai te surpreender em produção. Funciona com PHPUnit e PHP-CS-Fixer logo na instalação.
---
