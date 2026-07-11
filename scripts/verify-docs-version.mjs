import { readFile } from 'node:fs/promises'

const expectedVersion = process.env.DOCS_VERSION ?? 'next'
const html = await readFile('docs/.vitepress/dist/index.html', 'utf8')

if (!html.includes(`Documentation version: <strong>${expectedVersion}</strong>`)) {
  throw new Error(`Generated documentation does not contain version ${expectedVersion}`)
}

if (expectedVersion === 'next' && !html.includes('development documentation')) {
  throw new Error('Generated development documentation does not contain its warning')
}
