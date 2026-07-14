export function HomePage() {
  // 文字列の t() 化は i18n カタログ導入 PR（#1561）で行う（AM-19 — W0.starter 完了条件）
  return (
    <main className="min-h-screen bg-surface">
      <div className="mx-auto max-w-2xl p-8">
        <h1 className="text-2xl font-semibold text-text-primary">
          NENE2 Frontend Starter
        </h1>
        <p className="mt-2 text-sm text-text-muted">
          A starter template for API-first products, compliant with the NeNe
          frontend standards.
        </p>
      </div>
    </main>
  );
}
