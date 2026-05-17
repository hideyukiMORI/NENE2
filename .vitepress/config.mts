import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'NENE2',
  description: 'Minimal PHP 8.4 JSON API Framework — with MCP and OpenAPI built in.',
  srcDir: './docs',
  outDir: './.vitepress/dist',
  cleanUrls: true,
  ignoreDeadLinks: true,

  head: [
    ['meta', { name: 'theme-color', content: '#3b82f6' }],
  ],

  themeConfig: {
    siteTitle: 'NENE2',

    nav: [
      { text: 'Tutorial',  link: '/tutorial/first-api',        activeMatch: '/tutorial/' },
      { text: 'HOWTO',     link: '/howto/add-custom-route',    activeMatch: '/howto/' },
      { text: 'Reference', link: '/development/endpoint-scaffold', activeMatch: '/development/' },
      {
        text: 'v0.4.0',
        items: [
          { text: 'Changelog',  link: 'https://github.com/hideyukiMORI/NENE2/blob/main/CHANGELOG.md' },
          { text: 'Releases',   link: 'https://github.com/hideyukiMORI/NENE2/releases' },
          { text: 'Packagist',  link: 'https://packagist.org/packages/hideyukimori/nene2' },
        ],
      },
    ],

    sidebar: {
      '/tutorial/': [
        {
          text: 'Tutorial',
          items: [
            { text: 'Your first API', link: '/tutorial/first-api' },
          ],
        },
      ],
      '/howto/': [
        {
          text: 'HOWTO',
          items: [
            { text: 'Add a custom route',            link: '/howto/add-custom-route' },
            { text: 'Add a database-backed endpoint', link: '/howto/add-database-endpoint' },
          ],
        },
      ],
      '/development/': [
        {
          text: 'Development',
          items: [
            { text: 'Setup',                  link: '/development/setup' },
            { text: 'Endpoint scaffold',      link: '/development/endpoint-scaffold' },
            { text: 'Domain layer',           link: '/development/domain-layer' },
            { text: 'Authentication',         link: '/development/authentication-boundary' },
            { text: 'Test database strategy', link: '/development/test-database-strategy' },
            { text: 'Client project start',   link: '/development/client-project-start' },
          ],
        },
        {
          text: 'Integrations',
          items: [
            { text: 'Local MCP server',       link: '/integrations/local-mcp-server' },
            { text: 'MCP client config',      link: '/integrations/local-mcp-client-configuration' },
            { text: 'MCP tools policy',       link: '/integrations/mcp-tools' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/hideyukiMORI/NENE2' },
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 hideyukiMORI',
    },

    editLink: {
      pattern: 'https://github.com/hideyukiMORI/NENE2/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: {
      provider: 'local',
    },

    outline: { level: [2, 3] },
  },

  markdown: {
    theme: {
      light: 'github-light',
      dark:  'github-dark',
    },
    lineNumbers: true,
  },
})
