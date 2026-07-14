// pages の lazy 境界 smoke（01 6-2 SHOULD）
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

import { HomePage } from './HomePage';

it('renders the home heading', () => {
  render(<HomePage />);
  expect(
    screen.getByRole('heading', { level: 1, name: 'NENE2 Frontend Starter' }),
  ).toBeInTheDocument();
});
