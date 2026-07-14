/**
 * ロケール定義（I18N-6 の定型）。catalogs マップの組み立てはこのファイル・ここだけ。
 * 初期ロケール解決・永続化は本来 @hideyukimori/nene2-i18n の責務（I18N-24）—
 * 公開までの経過措置実装は runtime.tsx（W0b で削除）。
 */
import { en } from './messages/en';
import { ja, type MessageKey } from './messages/ja';

export const SUPPORTED_LOCALES = ['ja', 'en'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: SupportedLocale = 'ja';

export const catalogs: Record<SupportedLocale, Record<MessageKey, string>> = {
  ja,
  en,
};
