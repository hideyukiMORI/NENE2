/**
 * parity CI ゲート（I18N-20/21）。
 *
 * 【経過措置】正本は @hideyukimori/nene2-i18n/testing の expectCatalogParity だが未公開
 * （W0b 成果物）のため、同検査の中核（shape 100%・キー文法・プレースホルダ一致・
 * ICU 禁止・空値禁止・同値率）を vitest 直書きで実装する（payout locales.test.ts の
 * 一般化 — 04 I18N-20 の cited exemplar 形）。公開後に expectCatalogParity 1 呼び出しへ置換する。
 */
import { describe, expect, it } from 'vitest';

import { catalogs, SUPPORTED_LOCALES } from './locales';

// I18N-10 のキー文法（2〜5 セグメント・lowerCamelCase ASCII）
const KEY_GRAMMAR = /^[a-z][a-zA-Z0-9]*(\.[a-z][a-zA-Z0-9]*){1,4}$/;
// I18N-11: 補間は {{lowerCamelCase}} の 1 方式のみ
const PLACEHOLDER = /\{\{([a-z][a-zA-Z0-9]*)\}\}/g;
// ICU 構文（一重括弧 {name, ...}）の検出（I18N-12）
const ICU_PATTERN = /(?<!\{)\{[a-zA-Z0-9]+\s*,/;

// I18N-20/21: minKeys 床（50）未満のカタログは同値率検査せず allowlist の列挙のみで運用
const IDENTICAL_ALLOWLIST = new Set(['app.name']);
const MIN_KEYS_FOR_RATIO = 50;
const MAX_IDENTICAL_RATIO = 0.2;

const authority = catalogs.ja;
const authorityKeys = Object.keys(authority).sort();

function placeholdersOf(value: string): string[] {
  return [...value.matchAll(PLACEHOLDER)].map((m) => m[1] ?? '').sort();
}

describe('catalog parity', () => {
  it.each(SUPPORTED_LOCALES)(
    '%s のキー集合は権威 ja と完全一致する',
    (locale) => {
      expect(Object.keys(catalogs[locale]).sort()).toEqual(authorityKeys);
    },
  );

  it('全キーがキー文法（I18N-10）に適合する', () => {
    const violations = authorityKeys.filter((key) => !KEY_GRAMMAR.test(key));
    expect(violations).toEqual([]);
  });

  it.each(SUPPORTED_LOCALES)('%s に空文字列値・ICU 構文がない', (locale) => {
    for (const [key, value] of Object.entries(catalogs[locale])) {
      expect(value, `${locale}:${key} が空`).not.toBe('');
      expect(ICU_PATTERN.test(value), `${locale}:${key} に ICU 構文`).toBe(
        false,
      );
    }
  });

  it('同一キーのプレースホルダ集合は全ロケールで一致する（I18N-11）', () => {
    for (const key of authorityKeys) {
      const expected = placeholdersOf(authority[key as keyof typeof authority]);
      for (const locale of SUPPORTED_LOCALES) {
        const actual = placeholdersOf(
          catalogs[locale][key as keyof typeof authority],
        );
        expect(actual, `${locale}:${key} のプレースホルダ`).toEqual(expected);
      }
    }
  });

  it('lazy copy 検出（I18N-21 — 床未満は allowlist の列挙のみ）', () => {
    // 全ロケール対（ここでは ja/en の 1 対）で同一値キーを数える
    const identical = authorityKeys.filter(
      (key) =>
        catalogs.ja[key as keyof typeof authority] ===
        catalogs.en[key as keyof typeof authority],
    );
    if (authorityKeys.length < MIN_KEYS_FOR_RATIO) {
      const unlisted = identical.filter((key) => !IDENTICAL_ALLOWLIST.has(key));
      expect(unlisted, '同一値キーは identicalAllowlist に列挙する').toEqual(
        [],
      );
    } else {
      expect(identical.length / authorityKeys.length).toBeLessThanOrEqual(
        MAX_IDENTICAL_RATIO,
      );
    }
  });
});
