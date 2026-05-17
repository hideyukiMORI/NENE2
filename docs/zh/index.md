---
layout: home

hero:
  name: "NENE2"
  text: "极简 PHP API 框架"
  tagline: 快速构建 JSON API。内置 OpenAPI 与 MCP。从第一天起就为 AI 准备好。
  actions:
    - theme: brand
      text: 快速开始 →
      link: /zh/tutorial/first-api
    - theme: alt
      text: 在 GitHub 上查看
      link: https://github.com/hideyukiMORI/NENE2
    - theme: alt
      text: Packagist
      link: https://packagist.org/packages/hideyukimori/nene2

features:
  - icon: 🚀
    title: 分钟内启动
    details: 只需 composer require hideyukimori/nene2，您就拥有了一个带有健康检查、请求 ID 和 Problem Details 错误的 JSON API——无需编写任何路由。

  - icon: 📄
    title: OpenAPI 优先
    details: 您发布的每个端点都附带 OpenAPI 契约。内置 Swagger UI。契约是交给客户端的内容，而非事后的补充。

  - icon: 🤖
    title: MCP 就绪
    details: 本地 MCP 服务器将您的 API 作为工具暴露出来，AI 代理（Claude、Cursor）可以直接调用。无需特殊集成——它从您的 OpenAPI 目录中读取。

  - icon: 🛡️
    title: RFC 9457 错误
    details: 每个错误响应都是 Problem Details 对象——包含 type、title、status 和 detail 的机器可读 JSON 结构。生产环境不会返回原始异常。

  - icon: 🧱
    title: 整洁架构
    details: UseCase → RepositoryInterface → PDO 适配器。每层可独立测试。无魔法、无隐式装配、框架不侵入您的领域。

  - icon: 🔬
    title: PHPStan 8 级
    details: 最严格级别的静态分析。通过 PHPStan 就不会在运行时出现意外。与 PHPUnit 和 PHP-CS-Fixer 开箱即用。
---
