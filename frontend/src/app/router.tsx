// 全ルート定義（01 6-2: ルート表はこの 1 ファイル・pages は lazy import MUST）
import { lazy, Suspense } from 'react';
import { BrowserRouter, Route, Routes } from 'react-router';

// named export と React.lazy の橋渡しは次の 1 形に固定（01 6-2）
// [nene2-gen:lazy-imports] — gen:page がこの行の上に挿入する
const HomePage = lazy(() =>
  import('@/pages/home').then((m) => ({ default: m.HomePage })),
);
const NotesPage = lazy(() =>
  import('@/pages/notes').then((m) => ({ default: m.NotesPage })),
);

export function AppRouter() {
  return (
    <BrowserRouter>
      <Suspense fallback={null}>
        <Routes>
          {/* [nene2-gen:routes] — gen:page がこの行の上に挿入する */}
          <Route path="/" element={<HomePage />} />
          <Route path="/notes" element={<NotesPage />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  );
}
