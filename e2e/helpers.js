import { expect } from '@playwright/test';

/** A throwaway account, unique per call so runs never collide. */
export function newUser(label = 'user') {
    const id = `${Date.now()}${Math.floor(Math.random() * 1000)}`;

    return {
        name: `E2E ${label} ${id}`,
        email: `e2e+${id}@example.test`,
        password: `Pw-${id}-Aa1!`,
    };
}

export async function register(page, user) {
    await page.goto('/register');
    await page.getByLabel('Name').fill(user.name);
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Password', { exact: true }).fill(user.password);
    await page.getByLabel('Confirm Password').fill(user.password);
    await page.getByRole('button', { name: 'Register' }).click();
    await expect(page).toHaveURL(/\/generations\/create/);
}

export async function login(page, user) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Password', { exact: true }).fill(user.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/generations\/create/);
}

export async function logout(page, user) {
    await page.getByRole('button', { name: user.name }).click();
    await page.getByText('Log Out').locator('visible=true').click();
    await expect(page).toHaveURL(/\/$|\/login/);
}

/** Fill and submit the generate form. `references` is a list of URLs. */
export async function submitGeneration(page, { prompt, size, quality, references = [] }) {
    await page.goto('/generations/create');
    await page.getByLabel('Prompt').fill(prompt);
    if (size) await page.getByLabel('Size').selectOption(size);
    if (quality) await page.getByLabel('Quality').selectOption(quality);

    for (const url of references) {
        await page.getByRole('button', { name: '+ Add reference image URL' }).click();
        await page.getByPlaceholder('https://example.com/photo.png').last().fill(url);
    }

    await page.getByRole('button', { name: 'Generate' }).click();
    await expect(page).toHaveURL(/\/generations$/);
}

/** "$0.373875" -> 0.373875. Only for assertions; the app never does money math in JS. */
export function usd(text) {
    const match = text.match(/\$(\d+(?:\.\d+)?)/);
    if (!match) throw new Error(`No dollar amount in "${text}"`);

    return Number(match[1]);
}

/** Reads the "$min – $max" estimate shown on the generate form. */
export async function readEstimate(page) {
    const text = await page.locator('p.text-2xl').innerText();
    const [min, max] = text.split('–').map((part) => usd(part));

    return { min, max };
}
