# Frontend Integration Policy

NENE2 provides a React + TypeScript starter direction without making the framework depend on React.

## Position

Frontend integration should make the first application experience smooth while keeping the PHP framework core independent.

The standard direction is:

- Use React + TypeScript + Vite for NENE2-maintained starter work.
- Use npm as the official package manager for the starter.
- Use the active Node.js LTS line as the baseline instead of chasing every current release.
- Commit `package-lock.json` for reproducible frontend installs.
- Adopt current major versions when adding frontend dependencies, then keep them updated through automation.
- Keep frontend source outside `public_html/`.
- Write built assets to `public_html/assets/` only when they are safe to serve.

The initial starter implementation lives in `frontend/`.

## Framework Boundary

React is the official starter direction for framework-maintained examples and validation.

Applications built with NENE2 may still use:

- Vue
- Nuxt
- Next
- plain TypeScript
- server-rendered PHP templates only
- another frontend stack

NENE2 core must not depend on frontend packages or frontend build output.

## Package Manager

npm is the standard package manager for the official frontend starter.

Reasons:

- it ships with Node.js
- it has the lowest setup burden
- `package-lock.json` is widely understood
- it keeps starter documentation simple
- AI tools can infer commands without extra package-manager context

Yarn and pnpm are acceptable for user applications, but NENE2-maintained docs, examples, and CI should use npm unless the project explicitly changes this policy.

## Node.js and npm Versions

NENE2 should target the active Node.js LTS line for frontend starter development.

Policy:

- Do not require the latest current Node.js release just because it exists.
- Use active LTS as the minimum modern baseline.
- Allow the next major when it is compatible and useful.
- Define `engines` in `frontend/package.json`.
- Define `packageManager` in `frontend/package.json`.

Recommended initial shape:

```json
{
  "engines": {
    "node": ">=22 <25",
    "npm": ">=10 <12"
  },
  "packageManager": "npm@11"
}
```

The exact values should be checked against the current Node.js LTS and npm releases when the starter is implemented.

## Dependency Versions

When adding frontend dependencies:

- use the latest stable major available at implementation time
- avoid pinning old majors for convenience
- commit the lockfile
- document any intentional lag behind current stable releases

After initial adoption, keep dependencies modern through Dependabot or Renovate instead of manually chasing every release in feature work.

Recommended baseline dependency groups:

- React and React DOM
- TypeScript
- Vite
- ESLint with TypeScript and React support
- Prettier

## Lockfile Policy

Commit `frontend/package-lock.json`.

The lockfile is part of the starter's reproducibility story, like `composer.lock` is for framework development tooling.

Policy:

- use `npm ci` in CI once frontend CI exists
- use `npm install` for local dependency updates
- review lockfile changes as dependency changes
- do not commit `node_modules/`

## Build Output

Frontend source belongs in `frontend/`.

Build output may be written to:

```text
public_html/assets/
```

Build output should be treated as generated output unless a future release process requires committed assets for a specific distribution target.

The default repository policy is:

- commit source and config
- commit lockfiles
- ignore `node_modules/`
- ignore generated `public_html/assets/*` except placeholder files

## Commands

Frontend commands:

```bash
npm install --prefix frontend
npm run dev --prefix frontend
npm run build --prefix frontend
npm run check --prefix frontend
```

`frontend/package.json` exposes:

```json
{
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "type-check": "tsc --noEmit",
    "lint": "eslint .",
    "format": "prettier --check .",
    "format:fix": "prettier --write .",
    "check": "npm run type-check && npm run lint && npm run format"
  }
}
```

## API Client Direction

Generated API clients should be considered after OpenAPI schemas are stable enough to be useful.

The first starter uses a small typed fetch wrapper for the `/health` API under `frontend/src/api/`.
Vite proxies `/api/*` to the local Docker backend during development, while applications can set `VITE_NENE2_API_BASE_URL` when they need a different API base URL.

Client generation should be introduced only when it reduces maintenance and keeps API contracts clearer.

## SPA Shell and Server HTML

React SPA shell behavior and thin server-rendered HTML should stay separate:

- React owns rich interactive UI.
- Native PHP templates remain available for thin pages and operational screens.
- `public_html/index.php` remains the PHP front controller.
- frontend asset serving should not bypass the HTTP runtime policy for API routes.

## Non-Goals

- Forcing React on applications built with NENE2.
- Supporting multiple official starters before the first one is stable.
- Requiring yarn or pnpm for framework development.
- Requiring latest current Node.js releases outside the LTS line.
- Committing `node_modules/` or generated frontend build output by default.
