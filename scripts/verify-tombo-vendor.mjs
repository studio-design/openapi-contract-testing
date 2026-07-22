import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'

const files = [
  {
    path: new URL('../docs/public/styles/tombo.css', import.meta.url),
    source: 'wadakatu/tombo v0.4.0 / tombo.css',
    sha256: '2e21dee3720d21a6dfecb16c1d4e5443d72d21189cab11fba1349d4f3f0a5782'
  },
  {
    path: new URL('../docs/public/styles/tombo.LICENSE', import.meta.url),
    source: 'wadakatu/tombo v0.4.0 / LICENSE',
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
