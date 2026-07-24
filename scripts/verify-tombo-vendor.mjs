import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'

const files = [
  {
    path: new URL('../docs/public/styles/tombo.css', import.meta.url),
    source: 'wadakatu/tombo v0.12.0 / tombo.css',
    sha256: 'c885f17db82a64066a5034230f6865ca41c6171eb74435e6c7d7c15d122e86d8'
  },
  {
    path: new URL('../docs/public/styles/tombo.LICENSE', import.meta.url),
    source: 'wadakatu/tombo v0.12.0 / LICENSE',
    sha256: '72a8d3918494449985a5544ae70e35e9ad0ff5f6da8711969abd64952a9e3bd5'
  }
]

for (const file of files) {
  const contents = await readFile(file.path)
  const actual = createHash('sha256').update(contents).digest('hex')

  if (actual !== file.sha256) {
    throw new Error(`${file.source} does not match the pinned vendored bytes`)
  }

  console.log(`Verified ${file.source}: ${actual}`)
}
