<script setup lang="ts">
import DefaultTheme from 'vitepress/theme'

declare const __DOCS_VERSION__: string

const { Layout } = DefaultTheme
</script>

<template>
  <Layout>
    <template #layout-top>
      <div class="version-banner">
        Documentation version: <strong>{{ __DOCS_VERSION__ }}</strong>
        <span v-if="__DOCS_VERSION__ === 'next'"> — development documentation</span>
      </div>
    </template>
  </Layout>
</template>
