import { defineConfig } from 'vitepress'

function nav(t: {
  tutorial: string; howto: string; explanation: string; reference: string
}) {
  return [
    { text: t.tutorial,    link: 'tutorial/first-api',            activeMatch: 'tutorial/' },
    { text: t.howto,       link: 'howto/add-custom-route',        activeMatch: 'howto/' },
    { text: t.explanation, link: 'explanation/why-psr',           activeMatch: 'explanation/' },
    { text: t.reference,   link: 'development/endpoint-scaffold', activeMatch: 'development/' },
    {
      text: 'v0.7.0',
      items: [
        { text: 'Changelog',  link: 'https://github.com/hideyukiMORI/NENE2/blob/main/CHANGELOG.md' },
        { text: 'Releases',   link: 'https://github.com/hideyukiMORI/NENE2/releases' },
        { text: 'Packagist',  link: 'https://packagist.org/packages/hideyukimori/nene2' },
      ],
    },
  ]
}

function sidebar(t: {
  tutorialGroup: string; firstApi: string
  howtoGroup: string; addRoute: string; addDb: string; addEntity: string; deploy: string
  explGroup: string; whyPsr: string; whyDi: string; whyPd: string; whyMcp: string
  devGroup: string; intGroup: string
}) {
  return {
    '/tutorial/': [{ text: t.tutorialGroup, items: [{ text: t.firstApi, link: 'tutorial/first-api' }] }],
    '/howto/': [{
      text: t.howtoGroup,
      items: [
        { text: t.addRoute,  link: 'howto/add-custom-route' },
        { text: t.addDb,     link: 'howto/add-database-endpoint' },
        { text: t.addEntity, link: 'howto/add-second-entity' },
        { text: t.deploy,    link: 'howto/deploy-production' },
      ],
    }],
    '/explanation/': [{
      text: t.explGroup,
      items: [
        { text: t.whyPsr,  link: 'explanation/why-psr' },
        { text: t.whyDi,   link: 'explanation/why-explicit-wiring' },
        { text: t.whyPd,   link: 'explanation/why-problem-details' },
        { text: t.whyMcp,  link: 'explanation/why-mcp' },
      ],
    }],
    '/development/': [
      {
        text: t.devGroup,
        items: [
          { text: 'Setup',                  link: 'development/setup' },
          { text: 'Endpoint scaffold',      link: 'development/endpoint-scaffold' },
          { text: 'Domain layer',           link: 'development/domain-layer' },
          { text: 'Authentication',         link: 'development/authentication-boundary' },
          { text: 'Test database strategy', link: 'development/test-database-strategy' },
          { text: 'Client project start',   link: 'development/client-project-start' },
        ],
      },
      {
        text: t.intGroup,
        items: [
          { text: 'Local MCP server',       link: 'integrations/local-mcp-server' },
          { text: 'MCP client config',      link: 'integrations/local-mcp-client-configuration' },
          { text: 'MCP tools policy',       link: 'integrations/mcp-tools' },
        ],
      },
    ],
  }
}

export default defineConfig({
  title: 'NENE2',
  description: 'Minimal PHP 8.4 JSON API Framework — with MCP and OpenAPI built in.',
  base: process.env.GITHUB_ACTIONS ? '/NENE2/' : '/',
  srcDir: './docs',
  outDir: './.vitepress/dist',
  cleanUrls: true,
  ignoreDeadLinks: true,

  head: [['meta', { name: 'theme-color', content: '#3b82f6' }]],

  locales: {
    root: {
      label: 'English',
      lang: 'en',
      themeConfig: {
        nav: nav({ tutorial: 'Tutorial', howto: 'HOWTO', explanation: 'Explanation', reference: 'Reference' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutorial', firstApi: 'Your first API',
          howtoGroup: 'HOWTO', addRoute: 'Add a custom route', addDb: 'Add a database-backed endpoint', addEntity: 'Add a second entity', deploy: 'Deploy to production',
          explGroup: 'Explanation', whyPsr: 'Why PSR standards?', whyDi: 'Why explicit wiring?', whyPd: 'Why Problem Details?', whyMcp: 'Why MCP?',
          devGroup: 'Development', intGroup: 'Integrations',
        }),
        editLink: { pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path', text: 'Edit this page on GitHub' },
        footer: { message: 'Released under the MIT License.', copyright: 'Copyright © 2026 hideyukiMORI' },
      },
    },

    ja: {
      label: '日本語',
      lang: 'ja',
      themeConfig: {
        nav: nav({ tutorial: 'チュートリアル', howto: 'HOWTO', explanation: '解説', reference: 'リファレンス' }),
        sidebar: sidebar({
          tutorialGroup: 'チュートリアル', firstApi: '最初の API を動かす',
          howtoGroup: 'HOWTO', addRoute: 'カスタムルートを追加する', addDb: 'DB 付きエンドポイントを追加する', addEntity: '2 つ目のエンティティを追加する', deploy: '本番環境へデプロイする',
          explGroup: '解説', whyPsr: 'なぜ PSR 標準？', whyDi: 'なぜ明示的 DI？', whyPd: 'なぜ Problem Details？', whyMcp: 'なぜ MCP？',
          devGroup: '開発ガイド', intGroup: 'インテグレーション',
        }),
        editLink: { pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path', text: 'GitHub でこのページを編集' },
        footer: { message: 'MIT ライセンスの下で公開されています。', copyright: 'Copyright © 2026 hideyukiMORI' },
        docFooter: { prev: '前のページ', next: '次のページ' },
        outlineTitle: 'このページの目次',
        returnToTopLabel: 'トップへ戻る',
        sidebarMenuLabel: 'メニュー',
        darkModeSwitchLabel: 'ダークモード',
      },
    },

    fr: {
      label: 'Français',
      lang: 'fr',
      themeConfig: {
        nav: nav({ tutorial: 'Tutoriel', howto: 'Guides', explanation: 'Explication', reference: 'Référence' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutoriel', firstApi: 'Votre première API',
          howtoGroup: 'Guides pratiques', addRoute: 'Ajouter une route', addDb: 'Ajouter un endpoint avec BDD', addEntity: 'Ajouter une deuxième entité', deploy: 'Déployer en production',
          explGroup: 'Explication', whyPsr: 'Pourquoi PSR ?', whyDi: 'Pourquoi le câblage explicite ?', whyPd: 'Pourquoi Problem Details ?', whyMcp: 'Pourquoi MCP ?',
          devGroup: 'Développement', intGroup: 'Intégrations',
        }),
        editLink: { pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path', text: 'Modifier cette page sur GitHub' },
        footer: { message: 'Publié sous licence MIT.', copyright: 'Copyright © 2026 hideyukiMORI' },
        docFooter: { prev: 'Page précédente', next: 'Page suivante' },
        outlineTitle: 'Sur cette page',
        returnToTopLabel: 'Retour en haut',
      },
    },

    zh: {
      label: '中文',
      lang: 'zh-Hans',
      themeConfig: {
        nav: nav({ tutorial: '教程', howto: '操作指南', explanation: '说明', reference: '参考' }),
        sidebar: sidebar({
          tutorialGroup: '教程', firstApi: '创建您的第一个 API',
          howtoGroup: '操作指南', addRoute: '添加自定义路由', addDb: '添加数据库端点', addEntity: '添加第二个实体', deploy: '部署到生产环境',
          explGroup: '说明', whyPsr: '为什么选择 PSR？', whyDi: '为什么显式依赖注入？', whyPd: '为什么使用 Problem Details？', whyMcp: '为什么选择 MCP？',
          devGroup: '开发指南', intGroup: '集成',
        }),
        editLink: { pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path', text: '在 GitHub 上编辑此页' },
        footer: { message: '基于 MIT 许可证发布。', copyright: 'Copyright © 2026 hideyukiMORI' },
        docFooter: { prev: '上一页', next: '下一页' },
        outlineTitle: '本页目录',
        returnToTopLabel: '返回顶部',
        sidebarMenuLabel: '菜单',
        darkModeSwitchLabel: '深色模式',
      },
    },

    'pt-br': {
      label: 'Português (Brasil)',
      lang: 'pt-BR',
      themeConfig: {
        nav: nav({ tutorial: 'Tutorial', howto: 'Guias', explanation: 'Explicação', reference: 'Referência' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutorial', firstApi: 'Sua primeira API',
          howtoGroup: 'Guias práticos', addRoute: 'Adicionar uma rota', addDb: 'Adicionar endpoint com banco de dados', addEntity: 'Adicionar segunda entidade', deploy: 'Implantar em produção',
          explGroup: 'Explicação', whyPsr: 'Por que PSR?', whyDi: 'Por que injeção explícita?', whyPd: 'Por que Problem Details?', whyMcp: 'Por que MCP?',
          devGroup: 'Desenvolvimento', intGroup: 'Integrações',
        }),
        editLink: { pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path', text: 'Editar esta página no GitHub' },
        footer: { message: 'Publicado sob a licença MIT.', copyright: 'Copyright © 2026 hideyukiMORI' },
        docFooter: { prev: 'Página anterior', next: 'Próxima página' },
        outlineTitle: 'Nesta página',
        returnToTopLabel: 'Voltar ao topo',
      },
    },

    de: {
      label: 'Deutsch',
      lang: 'de',
      themeConfig: {
        nav: nav({ tutorial: 'Tutorial', howto: 'Anleitungen', explanation: 'Erklärung', reference: 'Referenz' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutorial', firstApi: 'Ihre erste API',
          howtoGroup: 'Anleitungen', addRoute: 'Route hinzufügen', addDb: 'Datenbankendpunkt hinzufügen', addEntity: 'Zweite Entität hinzufügen', deploy: 'In Produktion deployen',
          explGroup: 'Erklärung', whyPsr: 'Warum PSR?', whyDi: 'Warum explizites Wiring?', whyPd: 'Warum Problem Details?', whyMcp: 'Warum MCP?',
          devGroup: 'Entwicklung', intGroup: 'Integrationen',
        }),
        editLink: { pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path', text: 'Diese Seite auf GitHub bearbeiten' },
        footer: { message: 'Veröffentlicht unter der MIT-Lizenz.', copyright: 'Copyright © 2026 hideyukiMORI' },
        docFooter: { prev: 'Vorherige Seite', next: 'Nächste Seite' },
        outlineTitle: 'Auf dieser Seite',
        returnToTopLabel: 'Nach oben',
        sidebarMenuLabel: 'Menü',
        darkModeSwitchLabel: 'Dunkelmodus',
      },
    },
  },

  themeConfig: {
    siteTitle: 'NENE2',
    socialLinks: [{ icon: 'github', link: 'https://github.com/hideyukiMORI/NENE2' }],
    search: { provider: 'local' },
    outline: { level: [2, 3] },
  },

  markdown: {
    theme: { light: 'github-light', dark: 'github-dark' },
    lineNumbers: true,
  },
})
