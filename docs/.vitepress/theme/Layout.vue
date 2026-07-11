<script setup lang="ts">
import DefaultTheme from 'vitepress/theme'

declare const __DOCS_VERSION__: string

const { Layout } = DefaultTheme
const docsVersion = __DOCS_VERSION__
</script>

<template>
  <Layout>
    <template #layout-top>
      <div class="version-banner">
        Documentation version: <strong>{{ docsVersion }}</strong>
        <span v-if="docsVersion === 'next'"> — development documentation</span>
      </div>
    </template>
  </Layout>
</template>
