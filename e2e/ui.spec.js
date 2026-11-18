import { test, expect } from '@playwright/test';
import { login, logout, newUser, readEstimate, register } from './helpers.js';

// These tests never submit a generation, so they use no Pixelforge quota.

test.describe('authentication', () => {
    test('a user can register, log out and log back in', async ({ page }) => {
        const user = newUser('auth');

        await register(page, user);
        await expect(page.getByRole('heading', { name: 'New image' })).toBeVisible();

        await logout(page, user);
        await login(page, user);
    });

    test('wrong password is rejected', async ({ page }) => {
        const user = newUser('badpw');
        await register(page, user);
        await logout(page, user);

        await page.goto('/login');
        await page.getByLabel('Email').fill(user.email);
        await page.getByLabel('Password', { exact: true }).fill('not-the-password');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page.getByText(/credentials do not match/i)).toBeVisible();
        await expect(page).toHaveURL(/\/login/);
    });

    test('signed-out visitors are sent to the login page', async ({ page }) => {
        for (const path of ['/generations', '/generations/create']) {
            await page.goto(path);
            await expect(page).toHaveURL(/\/login/);
        }
    });
});

test.describe('generate form', () => {
    test.beforeEach(async ({ page }) => {
        await register(page, newUser('form'));
    });

    test('shows a labelled estimate range before anything is typed', async ({ page }) => {
        await expect(page.getByText('Estimated cost')).toBeVisible();
        await expect(page.locator('p.text-2xl')).toHaveText(/\$\d+\.\d+ – \$\d+\.\d+/);
        await expect(page.getByText(/This is an estimate/)).toBeVisible();
        await expect(page.getByText(/you pay nothing/i)).toBeVisible();

        const { min, max } = await readEstimate(page);
        expect(max).toBeGreaterThan(min);
    });

    test('the estimate rises with quality and with reference images', async ({ page }) => {
        await page.getByLabel('Quality').selectOption('low');
        await expect.poll(async () => (await readEstimate(page)).max).toBeGreaterThan(0);
        const low = (await readEstimate(page)).max;

        await page.getByLabel('Quality').selectOption('high');
        await expect.poll(async () => (await readEstimate(page)).max).toBeGreaterThan(low);
        const high = (await readEstimate(page)).max;

        for (let i = 0; i < 2; i++) {
            await page.getByRole('button', { name: '+ Add reference image URL' }).click();
            await page.getByPlaceholder('https://example.com/photo.png').last().fill(`https://example.com/${i}.png`);
        }
        await expect.poll(async () => (await readEstimate(page)).max).toBeGreaterThan(high);
    });

    test('a larger size costs more than the default size', async ({ page }) => {
        await page.getByLabel('Size').selectOption('1024x1024');
        await expect.poll(async () => (await readEstimate(page)).max).toBeGreaterThan(0);
        const square = (await readEstimate(page)).max;

        await page.getByLabel('Size').selectOption('1536x1024');
        await expect.poll(async () => (await readEstimate(page)).max).toBeGreaterThan(square);
    });

    test('cannot generate without a prompt', async ({ page }) => {
        const generate = page.getByRole('button', { name: 'Generate' });
        await expect(generate).toBeDisabled();

        await page.getByLabel('Prompt').fill('a lighthouse at dawn');
        await expect(generate).toBeEnabled();

        await page.getByLabel('Prompt').fill('   ');
        await expect(generate).toBeDisabled();
    });

    test('allows at most four reference images, and they can be removed', async ({ page }) => {
        const add = page.getByRole('button', { name: '+ Add reference image URL' });
        const inputs = page.getByPlaceholder('https://example.com/photo.png');

        for (let i = 0; i < 4; i++) await add.click();
        await expect(inputs).toHaveCount(4);
        await expect(add).toBeHidden();

        await page.getByRole('button', { name: 'Remove' }).first().click();
        await expect(inputs).toHaveCount(3);
        await expect(add).toBeVisible();
    });

    test('an empty history explains what to do', async ({ page }) => {
        await page.goto('/generations');
        await expect(page.getByText('No images yet')).toBeVisible();
    });
});
