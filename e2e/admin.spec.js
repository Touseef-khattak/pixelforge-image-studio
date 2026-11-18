import { test, expect } from '@playwright/test';
import { login, newUser, register } from './helpers.js';

// The report is for operators. The first test needs no setup. The second needs an existing admin:
//   E2E_ADMIN_EMAIL=... E2E_ADMIN_PASSWORD=... npm run e2e
// (it is skipped when they are not set). No generation is submitted, so no provider quota is used.

test('regular users cannot open the report and never see its link', async ({ page }) => {
    await register(page, newUser('plain'));

    await expect(page.getByRole('link', { name: 'Report' })).toHaveCount(0);

    const response = await page.goto('/admin/report');
    expect(response.status()).toBe(403);
});

test('signed-out visitors are sent to login', async ({ page }) => {
    await page.goto('/admin/report');
    await expect(page).toHaveURL(/\/login/);
});

test.describe('as an admin', () => {
    const email = process.env.E2E_ADMIN_EMAIL;
    const password = process.env.E2E_ADMIN_PASSWORD;

    test.skip(!email || !password, 'set E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD to run');

    test.beforeEach(async ({ page }) => {
        await login(page, { email, password });
    });

    test('shows summary cards and the day and user tables', async ({ page }) => {
        await page.getByRole('link', { name: 'Report' }).first().click();
        await expect(page).toHaveURL(/\/admin\/report/);

        for (const label of ['Credits charged', 'Provider cost', 'Profit', 'Generations']) {
            // Scoped to the summary cards: the same words are also table column headers.
            await expect(page.locator('dt', { hasText: label })).toBeVisible();
        }
        await expect(page.getByRole('heading', { name: 'By day' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'By user' })).toBeVisible();
        await expect(page.getByText(/same rule Pixelforge uses/)).toBeVisible();

        // Money is shown as dollars, never as raw nano-dollar integers.
        const charged = page.locator('dt', { hasText: 'Credits charged' }).locator('xpath=following-sibling::dd');
        await expect(charged).toHaveText(/^\$\d+\.\d{2,}$/);
    });

    test('the date filter narrows the report and can be cleared', async ({ page }) => {
        await page.goto('/admin/report');

        await page.getByLabel('From (UTC)').fill('2001-01-01');
        await page.getByLabel('To (UTC)').fill('2001-01-02');
        await page.getByRole('button', { name: 'Apply' }).click();

        await expect(page).toHaveURL(/from=2001-01-01/);
        await expect(page.getByText('No charged generations in this range.').first()).toBeVisible();

        await page.getByRole('button', { name: 'Clear' }).click();
        await expect(page).not.toHaveURL(/from=/);
    });

    test('a day row expands to show its users', async ({ page }) => {
        await page.goto('/admin/report');
        const firstDay = page.locator('tbody tr', { hasText: /^\s*[▸▾]?\s*\d{4}-\d{2}-\d{2}/ }).first();

        test.skip((await firstDay.count()) === 0, 'no charged generations yet');

        await expect(firstDay.getByText('▸')).toBeVisible();
        await firstDay.click();
        await expect(firstDay.getByText('▾')).toBeVisible();
    });
});
