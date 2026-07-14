/**
 * 判別ユニオンの error 腕を消費する共有 View 部品（02 UI-5）。
 * retry は error 腕直載せの関数を受ける（02 UI-2 — View がリカバリ手段を発明しない）。
 */
type ErrorStateProps = {
  message: string;
  retryLabel: string;
  onRetry: () => void;
};

export function ErrorState({ message, retryLabel, onRetry }: ErrorStateProps) {
  return (
    <div
      role="alert"
      className="rounded-md border border-danger bg-danger-soft p-4"
    >
      <p className="text-sm text-text-primary">{message}</p>
      <button
        type="button"
        onClick={onRetry}
        className="mt-3 rounded-md bg-accent px-3 py-2 text-sm font-medium text-on-accent outline-offset-2 hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-focus-ring"
      >
        {retryLabel}
      </button>
    </div>
  );
}
