---
layout: home

hero:
  name: "NENE2"
  text: "Minimal PHP API Framework"
  tagline: Build JSON APIs fast. OpenAPI and MCP built in. AI-ready from day one.
  actions:
    - theme: brand
      text: Get Started →
      link: /tutorial/first-api
    - theme: alt
      text: View on GitHub
      link: https://github.com/hideyukiMORI/NENE2
    - theme: alt
      text: Packagist
      link: https://packagist.org/packages/hideyukimori/nene2

features:
  - icon: 🚀
    title: Up in minutes
    details: composer require hideyukimori/nene2 and you have a running JSON API with health checks, request IDs, and Problem Details errors — before you write a single route.

  - icon: 📄
    title: OpenAPI first
    details: Every endpoint you ship comes with an OpenAPI contract. Swagger UI is included. The contract is what you hand to your client, not an afterthought.

  - icon: 🤖
    title: MCP ready
    details: A local MCP server exposes your API as tools that AI agents (Claude, Cursor) can call directly. No special integration — it reads from your OpenAPI catalog.

  - icon: 🛡️
    title: RFC 9457 errors
    details: Every error response is a Problem Details object — a machine-readable JSON structure with type, title, status, and detail. No raw exceptions in production.

  - icon: 🧱
    title: Clean architecture
    details: UseCase → RepositoryInterface → PDO adapter. Each layer is testable in isolation. No magic, no hidden wiring, no framework bleeding into your domain.

  - icon: 🔬
    title: PHPStan level 8
    details: Static analysis at the strictest level. If it passes PHPStan, it won't surprise you at runtime. Works alongside PHPUnit and PHP-CS-Fixer out of the box.
---
