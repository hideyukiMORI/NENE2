# NENE2 Frontend Starter

This directory contains the first NENE2-maintained React + TypeScript starter.

The PHP framework core does not depend on these packages or build output. Applications may replace this starter with another frontend stack.

## Commands

```bash
npm install --prefix frontend
npm run dev --prefix frontend
npm run build --prefix frontend
npm run check --prefix frontend
```

`npm run check --prefix frontend` runs TypeScript, ESLint, and Prettier checks.

## Backend API

The starter calls the NENE2 `/health` endpoint through `src/api/health.ts`.

During local development, Vite proxies `/api/*` to the Docker backend at `http://localhost:8080`.
Set `VITE_NENE2_API_BASE_URL` when an application needs a different API base URL.
