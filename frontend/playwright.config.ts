import { defineConfig, devices } from '@playwright/test';

// E2E 置き場は frontend/e2e/（invoice 現物形 — tests/** は vitest/testing-library lint の
// 管轄 glob のため、Playwright spec は e2e/ に分離する）。
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    // 初期ロケール解決（I18N-24: 保存値 → navigator.languages → DEFAULT_LOCALE）を
    // 決定的にするため、ブラウザロケールを権威 ja に固定する
    locale: 'ja-JP',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    // 事前に `npm run build` が必要（CI は build 後に test:e2e を実行する）
    command: 'npm run preview -- --host 127.0.0.1 --port 4173 --strictPort',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: !process.env.CI,
  },
});
