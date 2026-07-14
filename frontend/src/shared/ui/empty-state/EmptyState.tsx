/**
 * 空状態の共有 View 部品（02 UI-4 — 空状態は success のデータから導出して描画する）。
 */
type EmptyStateProps = {
  message: string;
};

export function EmptyState({ message }: EmptyStateProps) {
  return (
    <p className="rounded-md border border-border bg-surface-sunken p-4 text-sm text-text-muted">
      {message}
    </p>
  );
}
