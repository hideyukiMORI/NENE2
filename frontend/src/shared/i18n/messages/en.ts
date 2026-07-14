/**
 * 非権威ロケール（I18N-9: `satisfies Record<MessageKey, string>` — 欠落も余剰も型エラー）。
 */
import type { MessageKey } from './ja';

export const en = {
  'app.name': 'NENE2 Starter',
  'common.actions.retry': 'Retry',
  'common.state.loading': 'Loading…',
  'error.conflict': 'A conflict occurred. Please check the latest state.',
  'error.forbidden': 'You do not have permission to perform this action.',
  'error.notFound': 'The requested resource was not found.',
  'error.rateLimit': 'Too many requests. Please wait a moment and retry.',
  'error.serverError': 'A server error occurred. Please try again later.',
  'error.unauthorized': 'Sign-in is required.',
  'error.unknown': 'An unexpected error occurred.',
  'error.validation': 'Please review your input.',
  'home.description':
    'A starter template for API-first products, compliant with the NeNe frontend standards.',
  'home.notesLinkLabel': 'Note list (live API example)',
  'home.title': 'NENE2 Frontend Starter',
  'note.list.empty': 'No notes yet.',
  'note.list.title': 'Notes',
  // [nene2-gen:messages] — generator がこの行の上にキーを追記する
} satisfies Record<MessageKey, string>;
