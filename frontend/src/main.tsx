import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

// theme CSS を import する唯一の場所。エントリは src/index.css（配布物が正 —
// nene2-standards の lint entryPoint / scan-coverage / depcruise がこのパスを指す）
import './index.css';

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
