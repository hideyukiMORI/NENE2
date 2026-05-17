import { defineConfig } from 'vitepress'

function nav(t: {
  tutorial: string; howto: string; reference: string
}) {
  return [
    { text: t.tutorial,  link: 'tutorial/first-api',        activeMatch: 'tutorial/' },
    { text: t.howto,     link: 'howto/add-custom-route',    activeMatch: 'howto/' },
    { text: t.reference, link: 'development/endpoint-scaffold', activeMatch: 'development/' },
    {
      text: 'v0.4.0',
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
  howtoGroup: string; addRoute: string; addDb: string
  devGroup: string; intGroup: string
}) {
  return {
    '/tutorial/': [{ text: t.tutorialGroup, items: [{ text: t.firstApi, link: 'tutorial/first-api' }] }],
    '/howto/': [{
      text: t.howtoGroup,
      items: [
        { text: t.addRoute, link: 'howto/add-custom-route' },
        { text: t.addDb,    link: 'howto/add-database-endpoint' },
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
        nav: nav({ tutorial: 'Tutorial', howto: 'HOWTO', reference: 'Reference' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutorial', firstApi: 'Your first API',
          howtoGroup: 'HOWTO', addRoute: 'Add a custom route', addDb: 'Add a database-backed endpoint',
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
        nav: nav({ tutorial: 'チュートリアル', howto: 'HOWTO', reference: 'リファレンス' }),
        sidebar: sidebar({
          tutorialGroup: 'チュートリアル', firstApi: '最初の API を動かす',
          howtoGroup: 'HOWTO', addRoute: 'カスタムルートを追加する', addDb: 'DB 付きエンドポイントを追加する',
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
        nav: nav({ tutorial: 'Tutoriel', howto: 'Guides', reference: 'Référence' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutoriel', firstApi: 'Votre première API',
          howtoGroup: 'Guides pratiques', addRoute: 'Ajouter une route', addDb: 'Ajouter un endpoint avec BDD',
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
        nav: nav({ tutorial: '教程', howto: '操作指南', reference: '参考' }),
        sidebar: sidebar({
          tutorialGroup: '教程', firstApi: '创建您的第一个 API',
          howtoGroup: '操作指南', addRoute: '添加自定义路由', addDb: '添加数据库端点',
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
        nav: nav({ tutorial: 'Tutorial', howto: 'Guias', reference: 'Referência' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutorial', firstApi: 'Sua primeira API',
          howtoGroup: 'Guias práticos', addRoute: 'Adicionar uma rota', addDb: 'Adicionar endpoint com banco de dados',
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
        nav: nav({ tutorial: 'Tutorial', howto: 'Anleitungen', reference: 'Referenz' }),
        sidebar: sidebar({
          tutorialGroup: 'Tutorial', firstApi: 'Ihre erste API',
          howtoGroup: 'Anleitungen', addRoute: 'Route hinzufügen', addDb: 'Datenbankendpunkt hinzufügen',
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
