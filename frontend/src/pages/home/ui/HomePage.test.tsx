// pages の lazy 境界 smoke（01 6-2 SHOULD）
import { screen } from '@testing-library/react';
import { expect, it } from 'vitest';

import { renderWithI18n } from '@tests/render';

import { HomePage } from './HomePage';

it('renders the home heading (ja)', () => {
  renderWithI18n(<HomePage />, { locale: 'ja' });
  expect(
    screen.getByRole('heading', {
      level: 1,
      name: 'NENE2 フロントエンドスターター',
    }),
  ).toBeInTheDocument();
});
