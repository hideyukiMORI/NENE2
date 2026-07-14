/**
 * 環境変数の型付き読み出し座席（05 D-i — client.ts が import する唯一の env 座席）。
 * `import.meta.env` の raw 参照はこのファイルに閉じる。
 */
export const env = {
  /** API のオリジン/プレフィックス。空文字 = same-origin 相対（開発時は vite proxy 経由）。 */
  apiBaseUrl:
    (import.meta.env['VITE_API_BASE_URL'] as string | undefined) ?? '',
} as const;
