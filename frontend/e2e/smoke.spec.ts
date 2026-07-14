// Playwright smoke（05 §5.2 #16 — 新規製品はスターター同梱 smoke で生まれつき E2E 保有側。
// console.error 検知で fail — I18N-22 と共有の条文）
import { expect, test } from '@playwright/test';

function collectErrors(page: import('@playwright/test').Page): string[] {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => {
    errors.push(String(error));
  });
  return errors;
}

test('home renders without console errors', async ({ page }) => {
  const errors = collectErrors(page);
  await page.goto('/');
  await expect(
    page.getByRole('heading', {
      level: 1,
      name: 'NENE2 フロントエンドスターター',
    }),
  ).toBeVisible();
  expect(errors).toEqual([]);
});

test('dark mode attribute is applied before paint (DM-03)', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});
