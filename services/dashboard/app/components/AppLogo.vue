<script setup lang="ts">
/**
 * The project mark: three pills on a √2 grid, the tallest capped by a detached dot, standing on a
 * rule that is itself a pill. The source of truth is the Sketch document in docs/assets/brand,
 * exported to mark.svg; it is inlined here so the interface never waits for a request to show its
 * own logo.
 */
withDefaults(defineProps<{ class?: string }>(), { class: 'size-6' })
</script>

<template>
  <svg
    :class="$props.class"
    viewBox="0 0 388.97 388.97"
    xmlns="http://www.w3.org/2000/svg"
    role="img"
    aria-hidden="true"
  >
    <defs>
      <linearGradient
        id="app-logo-chart"
        x1="0.15"
        y1="0"
        x2="0.85"
        y2="1"
      >
        <stop offset="0" stop-color="#00E884" />
        <stop offset="0.5" stop-color="#00B268" />
        <stop offset="1" stop-color="#00663A" />
      </linearGradient>
    </defs>
    <g fill="url(#app-logo-chart)">
      <rect
        x="53.23"
        y="210.45"
        width="64"
        height="90.5"
        rx="32"
      />
      <rect
        x="162.49"
        y="172.99"
        width="64"
        height="127.96"
        rx="32"
      />
      <rect
        x="271.75"
        y="120.01"
        width="64"
        height="180.94"
        rx="32"
      />
      <circle cx="303.75" cy="56" r="32" />
    </g>
    <g fill="url(#app-logo-chart)" opacity="0.55">
      <rect
        x="53.23"
        y="332.96"
        width="282.52"
        height="32.01"
        rx="16.01"
      />
    </g>
  </svg>
</template>
