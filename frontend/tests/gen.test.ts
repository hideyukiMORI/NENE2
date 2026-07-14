/**
 * generator の決定性テスト（05 §6・W0.starter 完了条件）:
 * 同一入力で 2 回生成した結果（ファイル集合＋全バイト）が一致すること。
 * 生成物の lint / type-check / test 通過は素振り（scaffold 検証）と CI が担保する。
 */
import { execFileSync } from 'node:child_process';
import {
  cpSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterAll, expect, it } from 'vitest';

const FRONTEND = path.resolve(import.meta.dirname, '..');
const PLOP_BIN = path.join(FRONTEND, 'node_modules/plop/bin/plop.js');
const SEED_FILES = [
  'src/shared/i18n/messages/ja.ts',
  'src/shared/i18n/messages/en.ts',
  'src/app/router.tsx',
  'tests/msw/server.ts',
];

const tempDirs: string[] = [];

function seedDir(): string {
  const dir = mkdtempSync(path.join(tmpdir(), 'nene2-gen-'));
  tempDirs.push(dir);
  for (const rel of SEED_FILES) {
    mkdirSync(path.join(dir, path.dirname(rel)), { recursive: true });
    cpSync(path.join(FRONTEND, rel), path.join(dir, rel));
  }
  return dir;
}

function runGen(dest: string, generator: string, args: string[]): void {
  execFileSync(
    process.execPath,
    [
      PLOP_BIN,
      '--plopfile',
      path.join(FRONTEND, 'plopfile.mjs'),
      generator,
      ...args,
    ],
    {
      cwd: FRONTEND,
      env: { ...process.env, NENE2_GEN_DEST: dest },
      stdio: 'pipe',
      timeout: 60_000,
    },
  );
}

function snapshot(dir: string): Map<string, string> {
  const files = new Map<string, string>();
  const walk = (current: string): void => {
    for (const name of readdirSync(current, { withFileTypes: true })) {
      const full = path.join(current, name.name);
      if (name.isDirectory()) {
        walk(full);
      } else {
        files.set(
          path.relative(dir, full).replaceAll('\\', '/'),
          readFileSync(full, 'utf8'),
        );
      }
    }
  };
  walk(dir);
  return files;
}

function generateAll(dest: string): void {
  runGen(dest, 'entity', ['order', 'n']);
  runGen(dest, 'feature', ['view-orders', 'order']);
  runGen(dest, 'page', ['dashboard']);
}

afterAll(() => {
  for (const dir of tempDirs) rmSync(dir, { recursive: true, force: true });
});

it('gen 3種は決定的（同入力 → 同出力）で、期待ファイル集合を生成する', () => {
  const a = seedDir();
  const b = seedDir();
  generateAll(a);
  generateAll(b);

  const snapA = snapshot(a);
  const snapB = snapshot(b);

  // 決定性: ファイル集合＋内容の完全一致
  expect([...snapA.keys()].sort()).toEqual([...snapB.keys()].sort());
  for (const [file, content] of snapA) {
    expect(snapB.get(file), file).toBe(content);
  }

  // 期待出力: entity 8 ファイル（--write なし）
  for (const file of [
    'api-types.ts',
    'model.ts',
    'mapper.ts',
    'mapper.test.ts',
    'queries.ts',
    'query-keys.ts',
    'handlers.ts',
    'index.ts',
  ]) {
    expect(snapA.has(`src/entities/order/${file}`), file).toBe(true);
  }
  expect(snapA.has('src/entities/order/mutations.ts')).toBe(false);

  // 期待出力: feature 4 ファイル
  for (const file of [
    'model/use-view-orders.ts',
    'model/use-view-orders.test.tsx',
    'ui/ViewOrders.tsx',
    'index.ts',
  ]) {
    expect(snapA.has(`src/features/view-orders/${file}`), file).toBe(true);
  }

  // 期待出力: page 2 ファイル＋router 登録＋カタログ追記
  expect(snapA.has('src/pages/dashboard/ui/DashboardPage.tsx')).toBe(true);
  expect(snapA.has('src/pages/dashboard/index.ts')).toBe(true);
  expect(snapA.get('src/app/router.tsx')).toContain(
    '<Route path="/dashboard" element={<DashboardPage />} />',
  );
  expect(snapA.get('src/shared/i18n/messages/ja.ts')).toContain(
    "'dashboard.pageTitle'",
  );
  expect(snapA.get('src/shared/i18n/messages/en.ts')).toContain(
    "'order.list.empty'",
  );
  expect(snapA.get('tests/msw/server.ts')).toContain('...orderHandlers,');
}, 120_000);
