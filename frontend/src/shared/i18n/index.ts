/**
 * slice barrel（I18N-4 の公開面列挙）。
 * runtime 系 export は経過措置 — @hideyukimori/nene2-i18n 公開（W0b）後に
 * パッケージ import へ差し替えて runtime.tsx ごと削除する（詳細は runtime.tsx 冒頭）。
 */
export { catalogs, SUPPORTED_LOCALES, DEFAULT_LOCALE } from './locales';
export type { SupportedLocale } from './locales';
export type { MessageCatalog, MessageKey } from './messages/ja';
// 経過措置（W0b で @hideyukimori/nene2-i18n へ）
export {
  I18nProvider,
  useTranslation,
  translate,
  localeStore,
} from './runtime';
