# The icon

Analytics for WordPress's icon and banners. The sources are in [`design/`](../design), and
`node bin/export-assets.mjs` exports them to:
- `assets/` (the icon WordPress and the admin header show);
- `.github-assets/` (what the README shows).

## Brief

The workspace's plugins each have an icon that says what they do, drawn on the same tile:
- Voci a quoted voice;
- Agenda a calendar;
- Postino an envelope.

This plugin is not a product of its own. It is the WordPress side of the analytics
service, so it **takes the service's mark rather than inventing one**:
- three rounded bars rising;
- the dot of a value above the last one;
- a baseline ([`docs/assets/brand/mark.svg`](../../../../docs/assets/brand/mark.svg)).

## Construction

- The family's tile: a 512 canvas with corners at 22 % (112.6).
- The mark at 66 % of the tile, centred, in white. The baseline is at 55 %, as in the
  service's mark.
- A soft light from the top left, as on the siblings.

## Colour

- **Blue**, `#3b82f6` → `#1e3a8a`, diagonal. `#1d4ed8` in between is the consent banner's
  default accent and the admin screens' brand colour.
- The service's own mark is green. On the family's tile, green is Agenda's (`15803d`),
  so the plugin takes the colour it already uses in the banner.
- **The badge colour is `1d4ed8`**, distinct from the siblings:
  - Voci `c2412d`
  - Chiaro `0e7490`
  - Agenda `15803d`
  - Postino `4f3bd6`
  - Bottega `b45309`

## The banner

2560 × 640, in the family's layout:
- **On the left**, the tile, the name, an accent bar, the tagline and a line of features.
- **On the right**, a field of small charts, one in colour.
- **Light and dark**, shown by the README according to the reader's theme.

## Files

| File | What |
|---|---|
| `design/icon.svg` | the icon's source; copied to `assets/icon.svg` |
| `design/banner-light.svg`, `banner-dark.svg` | the banners' sources |
| `assets/icon-128x128.png`, `icon-256x256.png` | the icon for WordPress's screens |
| `assets/menu-icon.svg` | the admin menu's icon: the mark alone, in grey |
| `.github-assets/icon-512.png`, `banner-{light,dark}.png` | for the README |
