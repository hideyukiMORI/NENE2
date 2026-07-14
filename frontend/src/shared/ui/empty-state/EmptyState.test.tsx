// RTL render smoke（R2⑧）
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

import { EmptyState } from './EmptyState';

it('renders the message without providers', () => {
  render(<EmptyState message="Nothing here" />);
  expect(screen.getByText('Nothing here')).toBeInTheDocument();
});
