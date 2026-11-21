// The smoke test, run by make smoke in the container of Playwright, beside the game in the smoke
// environment (compose.yaml). Two widths: a laptop, and a tablet held upright, where a round is one column.
// One worker: an agent over MCP follows the game opened last, so two games at once would mix.
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    timeout: 90_000,
    expect: { timeout: 10_000 },
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,
    reporter: [['list'], ['html', { open: 'never', outputFolder: '../../var/smoke/report' }]],
    outputDir: '../../var/smoke/results',
    use: {
        baseURL: 'http://127.0.0.1:8000',
        locale: 'en-US',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'wide', use: { browserName: 'chromium', viewport: { width: 1280, height: 800 } } },
        { name: 'narrow', use: { browserName: 'chromium', viewport: { width: 760, height: 1024 } } },
    ],
});
