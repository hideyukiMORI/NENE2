// RTL render smoke（R2⑧ — Provider 不要でレンダー可能なこと自体が検査）
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

import { LoadingState } from './LoadingState';

it('renders the label without providers', () => {
  render(<LoadingState label="Loading…" />);
  expect(screen.getByRole('status')).toHaveTextContent('Loading…');
});
