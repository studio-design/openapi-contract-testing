import { defineConfig } from 'vitepress'

const version = process.env.DOCS_VERSION ?? 'next'

export default defineConfig({
  title: 'OpenAPI Contract Testing',
  description: 'Framework-agnostic OpenAPI contract testing for PHPUnit',
  base: '/openapi-contract-testing/',
  cleanUrls: true,
  lastUpdated: true,
  sitemap: { hostname: 'https://studio-design.github.io/openapi-contract-testing/' },
  vite: { define: { __DOCS_VERSION__: JSON.stringify(version) } },
  markdown: { lineNumbers: true },
  themeConfig: {
    logo: '/logo-light.png',
    search: { provider: 'local' },
    nav: [
      { text: 'Quickstarts', link: '/quickstarts/core' },
      { text: 'Guides', link: '/setup' },
      { text: `Version: ${version}`, link: '/versioning' }
    ],
    sidebar: [
      {
        text: 'Get started',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Core / PHPUnit', link: '/quickstarts/core' },
          { text: 'Laravel', link: '/quickstarts/laravel' },
          { text: 'Symfony', link: '/quickstarts/symfony' },
          { text: 'Pest', link: '/quickstarts/pest' }
        ]
      },
      {
        text: 'Recipes',
        items: [
          { text: 'GitHub Actions', link: '/recipes/github-actions' },
          { text: 'Fuzzing and drift checks', link: '/recipes/advanced-validation' },
          { text: 'Parallel test runners', link: '/parallel' }
        ]
      },
      {
        text: 'Migration',
        items: [{ text: 'From other validators', link: '/migration/from-other-validators' }]
      },
      {
        text: 'Reference',
        items: [
          { text: 'Setup', link: '/setup' },
          { text: 'Coverage', link: '/coverage' },
          { text: 'Supported features', link: '/supported-features' },
          { text: 'API reference', link: '/api-reference' }
        ]
      }
    ],
    socialLinks: [{ icon: 'github', link: 'https://github.com/studio-design/openapi-contract-testing' }],
    editLink: {
      pattern: 'https://github.com/studio-design/openapi-contract-testing/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    },
    footer: { message: 'Released under the MIT License.' }
  }
})
