// エントリ合成・プロバイダ（01 §1-1 app 層 — ビジネスロジック MUST NOT）
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { catalogs, DEFAULT_LOCALE, I18nProvider } from '@/shared/i18n';

// ST-6: QueryClient は app 層 1 箇所・新規リポはオプション無指定（02 ST-6 暫定固定値）
const queryClient = new QueryClient();

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <I18nProvider catalogs={catalogs} defaultLocale={DEFAULT_LOCALE}>
        {children}
      </I18nProvider>
    </QueryClientProvider>
  );
}
