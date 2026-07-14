// eslint.config.js — 製品リポの lint 正本（合成形・規約 05 §2.1）。
// raw rule の直書き・後置き override による severity 緩和は gate-integrity FAIL（AM-11(iii)）。
import nene2 from '@hideyukimori/nene2-standards';

export default [
  {
    ignores: [
      'dist',
      'node_modules',
      'playwright-report',
      'test-results',
      // 生成物（openapi-typescript 出力 — 手編集しない・regen で上書き）
      'src/shared/api/schema.gen.ts',
      // generator 実体と設定ファイル（型情報プロジェクト外の JS/MJS）
      'plopfile.mjs',
      'gen/**',
      'eslint.config.js',
      'stylelint.config.js',
    ],
  },
  ...nene2.base,
  ...nene2.fsd,
  ...nene2.api,
  ...nene2.styling,
  ...nene2.i18n,
  ...nene2.testing,
];
