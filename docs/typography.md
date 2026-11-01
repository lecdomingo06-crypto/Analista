# Typography

The shared layout loads `public/assets/fonts.css` before the component styles.
All active pages, navigation, form controls, cards, and chat use the shared
`--font-sans` token (Figtree). The homepage's italic heading accent uses
`--font-serif` (Newsreader italic). Page-specific styles use these tokens so
they do not restore the previous Pixel or Mono fonts.

The font files and fallback metrics match the public stylesheets at
https://course.marwieang.com/, inspected on October 26, 2026. Figtree uses
variable weights; Newsreader is used at weight 400 in italic, as in the
reference. Existing layout dimensions and responsive breakpoints are retained.

Fonts are hosted locally under `public/assets/fonts/`; no font CDN is required
at runtime. The shared layout preloads only the main Latin Figtree file, while
the browser loads other language subsets and Newsreader when needed. Both
families use `font-display: swap` and metric-adjusted local fallbacks.

Both families are distributed under the SIL Open Font License 1.1. Their
license and attribution files are included alongside the fonts:

- `Figtree-OFL.txt` — https://github.com/google/fonts/tree/main/ofl/figtree
- `Newsreader-OFL.txt` — https://github.com/google/fonts/tree/main/ofl/newsreader
