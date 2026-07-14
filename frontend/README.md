# NENE2 Frontend Starter

React + TypeScript + Vite starter compliant with the NeNe fleet frontend standards v1
(the standards live as versioned executables: `@hideyukimori/nene2-standards`,
`@hideyukimori/nene2-tokens`, `@hideyukimori/nene2-client`).
New products generated from this starter are compliant by construction.

The PHP framework core does not depend on these packages or build output.

## Commands

```bash
npm install --prefix frontend
npm run dev --prefix frontend        # Vite dev server (proxies /api/* to :8200)
npm run check --prefix frontend      # type-check + eslint + stylelint + validate:themes + prettier + vitest
npm run build --prefix frontend
npm run test:e2e --prefix frontend   # Playwright smoke (build first)
```

## Structure (Feature-Sliced Design, 5 layers)

```
src/
├── main.tsx            # the only entry; imports src/index.css (canonical cascade header)
├── index.css           # @layer order + tailwindcss theme(static) + active.css
├── app/                # providers.tsx (QueryClient, I18nProvider) / router.tsx (lazy routes)
├── pages/              # one route = one slice (ui/ + index.ts)
├── features/           # user actions (container hook returns a 3-state discriminated union)
├── entities/           # server-state home (gen:entity output: 8 files incl. MSW handlers)
└── shared/
    ├── api/            # client.ts (the only place HTTP lives) / errors.ts / schema.gen.ts
    ├── config/env.ts   # the only import.meta.env reader
    ├── i18n/           # messages/ja.ts (authority) + en.ts + parity test
    └── ui/             # pure presentational components + theme/ (active.css, themes/)
```

## Generators (do not hand-write entities/features/pages)

```bash
npm run gen:entity  -- <noun> [y]         # 8 files (+mutations.ts with y)
npm run gen:feature -- <verb-noun> <noun> # union hook + view + transition test
npm run gen:page    -- <name>             # page + lazy route registration
npm run gen:api-schema                    # OpenAPI -> src/shared/api/schema.gen.ts
```

Generator output is formatted with the pinned Prettier config and is deterministic
(`tests/gen.test.ts` verifies same input -> same output).

## Theming

Replace the whole design by changing **one line**: the `@import` in
`src/shared/ui/theme/active.css`. Theme files satisfy the Core Token Contract v1
(28 color + 4 shadow keys) and are validated by `npm run check:themes`
(closed value grammar, WCAG AA contrast). Dark mode is a `[data-theme='dark']`
override block; the `data-theme` attribute is written only by
`src/shared/ui/theme/theme-controller.ts`.

## i18n

`src/shared/i18n/messages/ja.ts` is the authority catalog; `en.ts` must satisfy
`Record<MessageKey, string>` (missing keys fail the type-check). Hardcoded Japanese
in components fails lint. The runtime currently ships as an interim implementation
(`shared/i18n/runtime.tsx`) matching the `@hideyukimori/nene2-i18n` v1 API and will be
replaced by the package once it is published (W0b).

## Backend API

The starter renders the NENE2 example Notes API (`/examples/notes`) on `/notes`
through the canonical stack (entity -> feature -> page). During local development,
Vite proxies `/api/*` to the Docker backend at `http://localhost:8200`.
Set `VITE_API_BASE_URL` for a different API base URL.
