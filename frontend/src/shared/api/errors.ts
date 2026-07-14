// shared/api/errors.ts — AppError → MessageKey 写像の唯一の場所（02 ER-2）。
import type { MessageKey } from '@/shared/i18n';

import { isAppError } from './client';

/** ドメイン固有 problem type slug → キー。製品ごとの追記はこの表にだけ行う。 */
const SLUG_MESSAGE_KEYS: Readonly<Partial<Record<string, MessageKey>>> = {
  // 例: 'vendor-duplicate': 'vendors.error.duplicate',
};

export function toMessageKey(error: unknown): MessageKey {
  if (!isAppError(error)) {
    return 'error.unknown';
  }
  const slug = error.problem?.type.split('/').at(-1);
  if (slug !== undefined) {
    const mapped = SLUG_MESSAGE_KEYS[slug];
    if (mapped !== undefined) {
      return mapped;
    }
  }
  switch (error.status) {
    case 401:
      return 'error.unauthorized';
    case 403:
      return 'error.forbidden';
    case 404:
      return 'error.notFound';
    case 409:
      return 'error.conflict';
    case 422:
      return 'error.validation';
    case 429:
      return 'error.rateLimit';
    default:
      return error.status >= 500 ? 'error.serverError' : 'error.unknown';
  }
}
