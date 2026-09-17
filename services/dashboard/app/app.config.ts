export default defineAppConfig({
  ui: {
    colors: {
      primary: 'green',
      neutral: 'zinc'
    },
    tabs: {
      slots: {
        // text-muted (neutral-500) on bg-elevated is 4.39:1; text-toned reaches 7:1 in both modes.
        trigger: 'data-[state=inactive]:text-toned'
      },
      defaultVariants: {
        // The neutral pill pairs bg-inverted with text-inverted (17.7:1); the primary
        // pill would put white on green, which fails WCAG AA in light mode.
        color: 'neutral'
      }
    }
  }
})
