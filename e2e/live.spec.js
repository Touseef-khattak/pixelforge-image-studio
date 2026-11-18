import { test, expect } from '@playwright/test';
import { newUser, register, submitGeneration, usd } from './helpers.js';

// @live: these call the real Pixelforge API through the app. They cost nothing but use provider
// quota (500 generations per day, 3 at once). Run with `npm run e2e:live`.
// The app needs its queue worker and scheduler running (see docs/testing-guide.md); without
// webhooks, a generation can take 2 to 3 minutes to finish via the reconcile command.

test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);

// Locally there are no webhooks, so a generation finishes via `generations:reconcile`, which only
// looks at rows idle for 2 minutes and runs once a minute: allow a little over 3 minutes.
const FINISH = { timeout: 240_000 };

/** The history card for one generation, found by a unique token in its prompt. */
function card(page, token) {
    return page.locator('article', { hasText: token });
}

function uniquePrompt(text) {
    const token = `e2e${Date.now()}${Math.floor(Math.random() * 1000)}`;

    return { token, prompt: `${token} ${text}` };
}

/** "Estimated" and "Final cost" values from a card. */
async function costs(article) {
    const [estimated, final] = await article.locator('dd').allInnerTexts();

    return { estimated, final };
}

test.describe('generation lifecycle @live', () => {
    test('a successful generation shows an exact final cost inside its estimate', async ({ page }) => {
        await register(page, newUser('success'));
        const { token, prompt } = uniquePrompt('a red fox in the snow [succeed]');

        await submitGeneration(page, { prompt, size: '1024x1024', quality: 'low' });

        const article = card(page, token);
        await expect(article).toBeVisible();
        // Before it finishes, the final cost must not be invented.
        await expect(article.getByText(/Queued|Processing|Succeeded/)).toBeVisible();

        await expect(article.getByText('Succeeded', { exact: true })).toBeVisible(FINISH);
        await expect(article.locator('img')).toBeVisible();

        const { estimated, final } = await costs(article);
        const [min, max] = estimated.split('–').map(usd);
        const charged = usd(final);

        expect(charged).toBeGreaterThanOrEqual(min);
        expect(charged).toBeLessThanOrEqual(max);

        // The charge is stable: reloading must not change it.
        await page.reload();
        expect((await costs(card(page, token))).final).toBe(final);
    });

    test('reference images are priced into the final cost', async ({ page }) => {
        await register(page, newUser('refs'));
        const { token, prompt } = uniquePrompt('a cat [succeed]');

        await submitGeneration(page, {
            prompt,
            quality: 'low',
            references: ['https://example.com/a.png', 'https://example.com/b.png'],
        });

        const article = card(page, token);
        await expect(article.getByText('Succeeded', { exact: true })).toBeVisible(FINISH);

        // Two images add 1,530 tokens at $10/M, so at least $0.0153 plus the 25% markup.
        expect(usd((await costs(article)).final)).toBeGreaterThan(0.0191);
    });

    test('a failed generation is free and says so', async ({ page }) => {
        await register(page, newUser('fail'));
        const { token, prompt } = uniquePrompt('a broken render [fail]');

        await submitGeneration(page, { prompt, quality: 'low' });

        const article = card(page, token);
        await expect(article.getByText('Failed', { exact: true })).toBeVisible(FINISH);
        await expect(article.getByText('You were not charged')).toBeVisible();
        expect((await costs(article)).final).toBe('$0.00');
    });

    test('a blocked prompt is rejected clearly and not charged', async ({ page }) => {
        await register(page, newUser('blocked'));
        const { token, prompt } = uniquePrompt('something forbidden [blocked]');

        await submitGeneration(page, { prompt, quality: 'low' });

        const article = card(page, token);
        await expect(article.getByText('Failed', { exact: true })).toBeVisible(FINISH);
        await expect(article.getByText(/content policy/i)).toBeVisible();
        await expect(article.getByText('You were not charged')).toBeVisible();
        expect((await costs(article)).final).toBe('$0.00');
    });

    test('duplicate webhook deliveries do not change the charge', async ({ page }) => {
        await register(page, newUser('dup'));
        const { token, prompt } = uniquePrompt('a duplicated event [duplicate]');

        await submitGeneration(page, { prompt, quality: 'low' });

        const article = card(page, token);
        await expect(article.getByText('Succeeded', { exact: true })).toBeVisible(FINISH);
        const { final } = await costs(article);

        // Give the second delivery time to arrive, then confirm nothing moved.
        await page.waitForTimeout(15_000);
        await page.reload();
        expect((await costs(card(page, token))).final).toBe(final);
    });
});

test.describe('data isolation @live', () => {
    test('one user never sees another user\'s generations', async ({ browser }) => {
        const first = await (await browser.newContext()).newPage();
        const second = await (await browser.newContext()).newPage();
        const { token, prompt } = uniquePrompt('private prompt [succeed]');

        await register(first, newUser('owner'));
        await submitGeneration(first, { prompt, quality: 'low' });
        await expect(card(first, token)).toBeVisible();

        await register(second, newUser('other'));
        await second.goto('/generations');
        await expect(second.getByText('No images yet')).toBeVisible();
        await expect(card(second, token)).toHaveCount(0);
    });
});
