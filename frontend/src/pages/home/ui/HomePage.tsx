import { useTranslation } from '@/shared/i18n';

export function HomePage() {
  const { t } = useTranslation();
  return (
    <main className="min-h-screen bg-surface">
      <div className="mx-auto max-w-2xl p-8">
        <h1 className="text-2xl font-semibold text-text-primary">
          {t('home.title')}
        </h1>
        <p className="mt-2 text-sm text-text-muted">{t('home.description')}</p>
      </div>
    </main>
  );
}
