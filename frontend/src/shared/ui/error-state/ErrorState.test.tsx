// RTL render smoke（R2⑧）
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';

import { ErrorState } from './ErrorState';

it('renders message and calls onRetry', async () => {
  const onRetry = vi.fn();
  render(
    <ErrorState
      message="Something failed"
      retryLabel="Retry"
      onRetry={onRetry}
    />,
  );

  expect(screen.getByRole('alert')).toHaveTextContent('Something failed');
  await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
  expect(onRetry).toHaveBeenCalledTimes(1);
});
