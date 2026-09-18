<script setup lang="ts">
/**
 * The project mark: three ascending bars with the detached cap over the tallest one. The source of
 * truth is docs/assets/brand/mark.svg, which also produces the favicon and the README banner; it is
 * inlined here so the interface never waits for a network request to show its own logo.
 */
withDefaults(defineProps<{ class?: string }>(), { class: 'size-6' })
</script>

<template>
  <svg
    :class="$props.class"
    viewBox="0 0 64 64"
    xmlns="http://www.w3.org/2000/svg"
    role="img"
    aria-hidden="true"
  >
    <defs>
      <linearGradient
        id="app-logo-bar"
        x1="0"
        y1="0"
        x2="0.35"
        y2="1"
      >
        <stop offset="0" stop-color="#00D877" />
        <stop offset="0.5" stop-color="#009253" />
        <stop offset="1" stop-color="#00663A" />
      </linearGradient>
      <linearGradient
        id="app-logo-glass"
        x1="0"
        y1="0"
        x2="0.6"
        y2="1"
      >
        <stop offset="0" stop-color="#ffffff" stop-opacity="0.45" />
        <stop offset="0.55" stop-color="#ffffff" stop-opacity="0.06" />
        <stop offset="1" stop-color="#ffffff" stop-opacity="0" />
      </linearGradient>
    </defs>
    <g fill="url(#app-logo-bar)">
      <rect
        x="6"
        y="52"
        width="52"
        height="6"
        rx="3"
        opacity="0.5"
      />
      <rect
        x="8"
        y="32"
        width="12"
        height="14"
        rx="5"
      />
      <rect
        x="26"
        y="20"
        width="12"
        height="26"
        rx="5"
      />
      <rect
        x="44"
        y="18"
        width="12"
        height="28"
        rx="5"
      />
      <circle cx="50" cy="7" r="6" />
    </g>
    <g fill="url(#app-logo-glass)">
      <rect
        x="8"
        y="32"
        width="12"
        height="14"
        rx="5"
      />
      <rect
        x="26"
        y="20"
        width="12"
        height="26"
        rx="5"
      />
      <rect
        x="44"
        y="18"
        width="12"
        height="28"
        rx="5"
      />
      <circle cx="50" cy="7" r="6" />
    </g>
  </svg>
</template>
