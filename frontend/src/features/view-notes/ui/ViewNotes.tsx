// 網羅 switch の View（02 UI-3: default 節 MUST NOT・空状態は success から導出 — UI-4）
import { EmptyState } from '@/shared/ui/empty-state';
import { ErrorState } from '@/shared/ui/error-state';
import { LoadingState } from '@/shared/ui/loading-state';
import { useTranslation } from '@/shared/i18n';

import { useViewNotes } from '../model/use-view-notes';

export function ViewNotes() {
  const { t } = useTranslation();
  const state = useViewNotes();
  switch (state.status) {
    case 'loading':
      return <LoadingState label={t('common.state.loading')} />;
    case 'error':
      return (
        <ErrorState
          message={t('error.unknown')}
          retryLabel={t('common.actions.retry')}
          onRetry={state.retry}
        />
      );
    case 'success':
      return state.notes.length === 0 ? (
        <EmptyState message={t('note.list.empty')} />
      ) : (
        <ul className="flex flex-col gap-3">
          {state.notes.map((note) => (
            <li
              key={note.id}
              className="rounded-md border border-border bg-surface-raised p-4 shadow-sm"
            >
              <h2 className="text-sm font-semibold text-text-primary">
                {note.title}
              </h2>
              <p className="mt-1 text-sm text-text-muted">{note.body}</p>
            </li>
          ))}
        </ul>
      );
  }
}
