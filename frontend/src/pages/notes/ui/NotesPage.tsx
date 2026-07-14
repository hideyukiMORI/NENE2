import { ViewNotes } from '@/features/view-notes';
import { useTranslation } from '@/shared/i18n';

export function NotesPage() {
  const { t } = useTranslation();
  return (
    <main className="min-h-screen bg-surface">
      <div className="mx-auto max-w-2xl p-8">
        <h1 className="text-2xl font-semibold text-text-primary">
          {t('note.list.title')}
        </h1>
        <div className="mt-6">
          <ViewNotes />
        </div>
      </div>
    </main>
  );
}
