import { Link } from 'react-router';

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
        <nav className="mt-6">
          <Link
            to="/notes"
            className="text-sm font-medium text-accent underline outline-offset-2 hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-focus-ring"
          >
            {t('home.notesLinkLabel')}
          </Link>
        </nav>
      </div>
    </main>
  );
}
