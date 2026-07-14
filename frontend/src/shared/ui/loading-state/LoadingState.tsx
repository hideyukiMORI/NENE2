/**
 * 判別ユニオンの loading 腕を消費する共有 View 部品（02 UI-5 — 状態の出所にはしない）。
 * ユーザ知覚文字列は required prop（R1② — デフォルト値 MUST NOT・i18n 解決は呼び出し側）。
 */
type LoadingStateProps = {
  label: string;
};

export function LoadingState({ label }: LoadingStateProps) {
  return (
    <p role="status" className="p-4 text-sm text-text-muted">
      {label}
    </p>
  );
}
