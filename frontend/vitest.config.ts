import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

const alias = {
  '@': fileURLToPath(new URL('./src', import.meta.url)),
  '@tests': fileURLToPath(new URL('./tests', import.meta.url)),
};

// 拡張子 → テスト環境の機械決定（会議R2⑧決定・規約 05 §5.1）:
//   *.test.ts  = node / *.test.tsx = jsdom。グローバル jsdom は MUST NOT。
export default defineConfig({
  test: {
    // スケルトン段階（node 側テスト 0 件）でも check を通す
    passWithNoTests: true,
    projects: [
      {
        resolve: { alias },
        test: {
          name: 'node',
          environment: 'node',
          include: ['src/**/*.test.ts', 'tests/**/*.test.ts'],
        },
      },
      {
        plugins: [react()],
        resolve: { alias },
        test: {
          name: 'jsdom',
          environment: 'jsdom',
          include: ['src/**/*.test.tsx', 'tests/**/*.test.tsx'],
          setupFiles: ['./tests/setup.ts'],
        },
      },
    ],
  },
});
