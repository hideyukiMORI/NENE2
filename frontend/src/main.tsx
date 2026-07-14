import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { AppProviders } from '@/app/providers';
import { AppRouter } from '@/app/router';

const container = document.getElementById('root');
if (container === null) {
  throw new Error('#root element not found');
}

createRoot(container).render(
  <StrictMode>
    <AppProviders>
      <AppRouter />
    </AppProviders>
  </StrictMode>,
);
