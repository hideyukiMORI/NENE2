// renderWithI18n / renderHookWithProviders（R2⑧ — locale 明示必須）
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, renderHook } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { MemoryRouter } from 'react-router';

import { catalogs, DEFAULT_LOCALE, I18nProvider } from '@/shared/i18n';
import type { SupportedLocale } from '@/shared/i18n';

export interface RenderOptions {
  locale: SupportedLocale;
}

function createWrapper(locale: SupportedLocale) {
  // テストは retry 無効（error 遷移テストがリトライ待ちでタイムアウトしないため）。
  // アプリ本体の QueryClient 既定は app/providers.tsx（02 ST-6）が正。
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <I18nProvider
          catalogs={catalogs}
          defaultLocale={DEFAULT_LOCALE}
          locale={locale}
        >
          <MemoryRouter>{children}</MemoryRouter>
        </I18nProvider>
      </QueryClientProvider>
    );
  };
}

export function renderWithI18n(ui: ReactElement, options: RenderOptions) {
  return render(ui, { wrapper: createWrapper(options.locale) });
}

export function renderHookWithProviders<T>(
  hook: () => T,
  options: RenderOptions,
) {
  return renderHook(hook, { wrapper: createWrapper(options.locale) });
}
