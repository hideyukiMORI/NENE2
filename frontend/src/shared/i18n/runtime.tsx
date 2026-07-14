/**
 * 【経過措置 — W0b で削除して @hideyukimori/nene2-i18n に置き換える】
 *
 * i18n ランタイムの正本は `@hideyukimori/nene2-i18n`（I18N-2）だが、同パッケージは
 * 2026-07-15 実測で未公開（0.1.0-rc.1・private・react/translate 未実装 — W0b 成果物）。
 * スターターの t() 化は W0.starter の完了条件（AM-19/I18N-19）のため、公開までの間だけ
 * パッケージ v1 仕様（04 §1・I18N-22/23/24）と同一の呼び出し形・解決規律を最小実装で
 * 提供する。publish 後の置換 PR で本ファイルを削除し import 元を差し替える。
 *
 * v1 仕様への一致点（swap 時に挙動が変わらないこと）:
 * - `t(key)` / `t(key, params)`・params は `{{placeholder}}` 名のオブジェクト（ICU なし）
 * - React ツリー外は `translate(key, params?)`（`t` と同一の解決実装）
 * - 沈黙フォールバック禁止: DEV は `∅` ＋キー名を描画し console.error（1 キー 1 回）、
 *   本番のみ権威 ja へフォールバック（I18N-22）
 * - locale は module store（useSyncExternalStore）・永続化キーは localStorage 'nene2-locale'・
 *   初期解決順は 保存値 → navigator.languages（完全一致 → 言語サブタグ）→ DEFAULT_LOCALE（I18N-24）
 * - Provider が scope 要素（document.documentElement）へ lang を同期（I18N-23）
 */
import { useSyncExternalStore, type ReactNode } from 'react';

import type { MessageKey } from './messages/ja';
import {
  catalogs,
  DEFAULT_LOCALE,
  SUPPORTED_LOCALES,
  type SupportedLocale,
} from './locales';

const STORAGE_KEY = 'nene2-locale';
const listeners = new Set<() => void>();
const reportedMissing = new Set<string>();

function notify(): void {
  for (const listener of listeners) listener();
}

function isSupported(value: string): value is SupportedLocale {
  return (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

function resolveInitialLocale(): SupportedLocale {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored !== null && isSupported(stored)) return stored;
  } catch {
    // 読めない環境では次の解決段へ
  }
  if (typeof navigator !== 'undefined') {
    for (const tag of navigator.languages) {
      if (isSupported(tag)) return tag;
    }
    for (const tag of navigator.languages) {
      const subtag = tag.split('-')[0];
      if (subtag !== undefined && isSupported(subtag)) return subtag;
    }
  }
  return DEFAULT_LOCALE;
}

let currentLocale: SupportedLocale = resolveInitialLocale();

function syncScopeLang(): void {
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('lang', currentLocale);
  }
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function getLocale(): SupportedLocale {
  return currentLocale;
}

function setLocale(locale: SupportedLocale): void {
  currentLocale = locale;
  try {
    localStorage.setItem(STORAGE_KEY, locale);
  } catch {
    // 保存不能でも表示は切り替える
  }
  syncScopeLang();
  notify();
}

// module store 標準形（02 CS-2）
export const localeStore = { subscribe, getLocale, setLocale };

function interpolate(
  template: string,
  params: Readonly<Record<string, string | number>> | undefined,
): string {
  if (params === undefined) return template;
  return template.replaceAll(
    /\{\{([a-z][a-zA-Z0-9]*)\}\}/g,
    (match, name: string) => {
      const value = params[name];
      return value === undefined ? match : String(value);
    },
  );
}

/** `t` と同一の解決実装（React ツリー外用 — I18N-2 呼び出し形）。 */
export function translate(
  key: MessageKey,
  params?: Readonly<Record<string, string | number>>,
): string {
  const value = catalogs[currentLocale][key] as string | undefined;
  if (value === undefined) {
    if (import.meta.env.DEV) {
      if (!reportedMissing.has(key)) {
        reportedMissing.add(key);
        console.error(
          `[i18n] missing key in locale '${currentLocale}': ${key}`,
        );
      }
      return `∅${key}`;
    }
    return interpolate(catalogs[DEFAULT_LOCALE][key], params);
  }
  return interpolate(value, params);
}

export interface UseTranslationResult {
  t: (
    key: MessageKey,
    params?: Readonly<Record<string, string | number>>,
  ) => string;
  locale: SupportedLocale;
}

export function useTranslation(): UseTranslationResult {
  const locale = useSyncExternalStore(subscribe, getLocale);
  return { t: translate, locale };
}

export interface I18nProviderProps {
  catalogs: Record<SupportedLocale, Record<MessageKey, string>>;
  defaultLocale: SupportedLocale;
  /** テスト用のロケール固定（renderWithI18n が使用 — I18N テスト規約 §11） */
  locale?: SupportedLocale;
  children: ReactNode;
}

export function I18nProvider(props: I18nProviderProps): ReactNode {
  // カタログはモジュール静的（locales.ts が唯一の組み立て場所）のため props は登録の宣言のみ。
  // ロケール固定が指定されたら描画前に反映する（テスト経路）。
  if (props.locale !== undefined && props.locale !== currentLocale) {
    currentLocale = props.locale;
  }
  syncScopeLang();
  return props.children;
}
