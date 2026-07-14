// ユニオン遷移テスト（R2⑧ — 状態が型なら、テストは型の遷移表）
import { waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { expect, it } from 'vitest';

import { renderHookWithProviders } from '@tests/render';
import { server } from '@tests/msw/server';

import { useViewNotes } from './use-view-notes';

it('loading → success へ遷移し、payload はドメイン名フィールドで返る', async () => {
  const { result } = renderHookWithProviders(() => useViewNotes(), {
    locale: 'ja',
  });

  expect(result.current.status).toBe('loading');
  await waitFor(() => {
    expect(result.current.status).toBe('success');
  });
  if (result.current.status !== 'success') throw new Error('unreachable');
  expect(result.current.notes).toHaveLength(1);
  expect(result.current.notes[0]?.title).toBe('Example Note');
});

it('error 状態は retry を持つ', async () => {
  server.use(
    http.get('*/api/examples/notes', () =>
      HttpResponse.json(
        {
          type: 'https://nene2.dev/problems/server-error',
          title: 'x',
          status: 500,
        },
        { status: 500 },
      ),
    ),
  );
  const { result } = renderHookWithProviders(() => useViewNotes(), {
    locale: 'ja',
  });

  await waitFor(() => {
    expect(result.current.status).toBe('error');
  });
  if (result.current.status !== 'error') throw new Error('unreachable');
  expect(typeof result.current.retry).toBe('function');
});
