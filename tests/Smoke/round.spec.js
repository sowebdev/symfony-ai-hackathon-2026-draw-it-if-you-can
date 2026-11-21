// The smoke test: what make ci cannot see, the screen that moves. A real browser plays against the
// scripted model of tests/Smoke (ScriptedPlatforms): the word is "cat", the AI guesses "dog", "kitten",
// then "cat", and the judge finds "kitten" very close (warm) and the rest unrelated.
import { test, expect } from '@playwright/test';

const OVER = '[data-guesses-target="result"]:not([hidden]), [data-ai-drawing-target="result"]:not([hidden])';

// Starts a game from the home page, as a player does, and waits for its first round.
// Clicks what leaves the page, and waits for the next one: a round follows a round on the same kind of address.
async function leaveFor(page, button) {
    const from = page.url();
    await button.click();
    await page.waitForURL((url) => url.href !== from);
}

async function startGame(page, mode) {
    await page.goto('/en');
    await page.locator(`label.mode-${mode}`).click();
    // Who draws first is dealt; the form also takes it, so that the test knows.
    await page.locator('form.new-game').evaluate((form) => form.append(Object.assign(document.createElement('input'), { type: 'hidden', name: 'drawnBy', value: 'ai' })));
    await page.locator('form.new-game button[type="submit"]').click();
}

async function waitForRound(page) {
    await page.waitForURL(/\/rounds\/[0-9a-f-]{36}$/);
    await expect(page.locator('.board')).toBeVisible();
}

// A stroke on the canvas, in its coordinates from 0 to 1000.
async function stroke(page, points) {
    await page.locator('.canvas').evaluate((canvas) => canvas.scrollIntoView({ block: 'center' }));
    const box = await page.locator('.canvas').boundingBox();
    const at = ([x, y]) => [box.x + (x / 1000) * box.width, box.y + (y / 1000) * box.height];
    await page.mouse.move(...at(points[0]));
    await page.mouse.down();
    for (const point of points.slice(1)) {
        await page.mouse.move(...at(point), { steps: 8 });
    }
    await page.mouse.up();
}

async function answer(page, word) {
    await page.locator('form.answer input[name="word"]').fill(word);
    await page.locator('form.answer button[type="submit"]').click();
}

async function box(locator) {
    await expect(locator).toBeVisible();
    return locator.boundingBox();
}

// What every round must show, whatever the width: nothing wider than the window, a sheet of a usable
// size, Olaf in sight and on top (nothing covers his head), the three parts side by side when wide; when
// narrow, one under the other, the sheet first and whole in the window.
async function checkLayout(page, { referee }) {
    const narrow = test.info().project.name === 'narrow';
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, 'the page is wider than the window').toBeLessThanOrEqual(0);

    // The zones of the bar never overlap: the round and its theme, the scores, the clock.
    const zones = await Promise.all(['.topbar-left', '.topbar-scores', '.topbar-time'].map((zone) => page.locator(zone).boundingBox()));
    for (const [i, a] of zones.entries()) {
        for (const b of zones.slice(i + 1)) {
            const apart = a.x + a.width <= b.x + 1 || b.x + b.width <= a.x + 1 || a.y + a.height <= b.y + 1 || b.y + b.height <= a.y + 1;
            expect(apart, 'two zones of the bar overlap').toBe(true);
        }
    }

    const team = await box(page.locator('.camp-team'));
    const stage = await box(page.locator('.stage'));
    const third = await box(page.locator('.board > :nth-child(3)'));
    expect(stage.width, 'the sheet is too small to draw on').toBeGreaterThanOrEqual(narrow ? 500 : 450);
    if (narrow) {
        await page.evaluate(() => window.scrollTo(0, 0));
        const top = await box(page.locator('.stage'));
        expect(top.y + top.height, 'the sheet is not whole in the window').toBeLessThanOrEqual(page.viewportSize().height);
        expect(stage.y + stage.height, 'our side is not under the sheet').toBeLessThanOrEqual(team.y);
        expect(team.y + team.height, 'the third part is not under our side').toBeLessThanOrEqual(third.y);
    } else {
        expect(team.x + team.width, 'our side is not left of the sheet').toBeLessThanOrEqual(stage.x);
        expect(stage.x + stage.width, 'the third part is not right of the sheet').toBeLessThanOrEqual(third.x);
    }

    const olaf = page.locator('.camp-team .olaf').first();
    const head = await box(olaf);
    expect(head.width).toBeGreaterThan(40);
    // Scrolled to by hand: Olaf moves all the time, Playwright would wait for him to keep still.
    const onTop = await olaf.evaluate((svg) => {
        svg.scrollIntoView({ block: 'center' });
        const rect = svg.getBoundingClientRect();
        const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 3);
        return hit !== null && svg.contains(hit);
    });
    expect(onTop, 'something covers the head of Olaf').toBe(true);

    // The walrus: on his chair in the third column when alone, under the sheet when an agent plays.
    const walrus = await box(page.locator('.referee-walrus'));
    // Measured again: the page may have scrolled since.
    const sheet = await box(page.locator('.stage'));
    if ('chair' === referee) {
        await expect(page.locator('.umpire .referee.on-chair')).toBeVisible();
        expect(walrus.y, 'the walrus is not beside or under the sheet').toBeGreaterThanOrEqual(narrow ? sheet.y + sheet.height : sheet.y);
        if (!narrow) {
            expect(walrus.x).toBeGreaterThanOrEqual(sheet.x + sheet.width);
        }
    } else {
        await expect(page.locator('.pitch .referee')).toBeVisible();
        expect(walrus.y, 'the walrus is not under the sheet').toBeGreaterThanOrEqual(sheet.y + sheet.height - 5);
        expect(walrus.x).toBeGreaterThanOrEqual(sheet.x - 5);
        expect(walrus.x + walrus.width).toBeLessThanOrEqual(sheet.x + sheet.width + 5);
    }
}

test('a solo game, the AI draws then the player draws, each answer judged', async ({ page }) => {
    await startGame(page, 'solo');
    await waitForRound(page);

    // The AI draws: its strokes come, the thinking pencil goes away.
    await expect(page.locator('[data-ai-drawing-target="thinking"]')).toBeHidden({ timeout: 15_000 });
    await checkLayout(page, { referee: 'chair' });

    const ours = page.locator('[data-ai-drawing-target="list"] li');
    await answer(page, 'dog');
    await expect(ours.nth(0)).toHaveText('dog');
    await expect(ours.nth(0)).not.toHaveClass(/warm|accepted/);
    await answer(page, 'kitten');
    await expect(ours.nth(1)).toHaveClass(/warm/);
    await expect(page.locator('.referee-bubble.referee-warm')).toBeVisible();
    await answer(page, 'cat');
    await expect(ours.nth(2)).toHaveClass(/accepted/);
    await expect(page.locator(OVER).first()).toBeVisible();
    await expect(page.locator('.referee-bubble.referee-accepted')).toBeVisible();

    // The end of the round, then the round where the player draws.
    await page.reload();
    await expect(page.locator('.round-end')).toBeVisible();
    await leaveFor(page, page.locator('.round-end button[type="submit"]'));
    await waitForRound(page);
    await expect(page.locator('.target strong')).toContainText('cat');
    await checkLayout(page, { referee: 'chair' });

    const said = page.locator('[data-guesses-target="list"] li');
    await stroke(page, [[300, 300], [700, 300], [700, 700], [300, 700], [300, 300]]);
    await expect(said.nth(0)).toHaveText('dog');
    await expect(said.nth(1)).toHaveText('kitten');
    await expect(said.nth(1)).toHaveClass(/warm/);
    await expect(page.locator('.camp-team .olaf-bubble')).toHaveText('kitten');
    // Two guesses per drawing: a new stroke for the third.
    await stroke(page, [[350, 250], [400, 150], [450, 250]]);
    await expect(said.nth(2)).toHaveText('cat');
    await expect(said.nth(2)).toHaveClass(/accepted/);
    await expect(page.locator(OVER).first()).toBeVisible();
    await checkLayout(page, { referee: 'chair' });
});

// An agent over MCP, as recent clients talk to the game: stateless, the client says who it is each time.
async function callTool(request, tool, args = {}) {
    const meta = {
        'io.modelcontextprotocol/protocolVersion': '2026-07-28',
        'io.modelcontextprotocol/clientInfo': { name: 'smoke', version: '1' },
        'io.modelcontextprotocol/clientCapabilities': {},
    };
    const response = await request.post('/mcp', {
        headers: {
            Accept: 'application/json, text/event-stream',
            'Mcp-Protocol-Version': '2026-07-28',
            'Mcp-Method': 'tools/call',
            'Mcp-Name': tool,
        },
        data: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: tool, arguments: args, _meta: meta } },
    });
    expect(response.ok()).toBe(true);
    const body = await response.json();
    expect(body.result, JSON.stringify(body)).toBeDefined();

    return body.result.content[0].text;
}

test('a duel, the AI draws: the rival answers beside us, the walrus judges under the sheet', async ({ page }) => {
    await startGame(page, 'duel');
    await page.waitForURL(/\/lobby$/);
    await callTool(page.request, 'choose_nickname', { nickname: 'Smokey' });
    await expect(page.locator('.agent-status.connected')).toContainText('Smokey');
    await leaveFor(page, page.getByRole('button', { name: 'Start the game' }));
    await waitForRound(page);
    await expect(page.locator('.rival-score')).toContainText('Smokey');
    await expect(page.locator('[data-ai-drawing-target="thinking"]')).toBeHidden({ timeout: 15_000 });

    expect(await callTool(page.request, 'submit_answer', { word: 'dog' })).toContain('wrong');
    await expect(page.locator('.rival-answers li').first()).toHaveText('dog');
    await expect(page.locator('.agent-bubble')).toHaveText('dog');
    await checkLayout(page, { referee: 'field' });

    await answer(page, 'cat');
    await expect(page.locator(OVER).first()).toBeVisible();
    await expect(page.locator('.pitch .referee-bubble.referee-accepted')).toBeVisible();
    await page.reload();
    await expect(page.locator('.round-end h2.side-team')).toBeVisible();
    await checkLayout(page, { referee: 'field' });
});

test('a duel race: the word said first wins, even when its judge is slower', async ({ page }) => {
    await startGame(page, 'duel');
    await page.waitForURL(/\/lobby$/);
    await callTool(page.request, 'choose_nickname', { nickname: 'Smokey' });
    await expect(page.locator('.agent-status.connected')).toContainText('Smokey');
    await leaveFor(page, page.getByRole('button', { name: 'Start the game' }));
    await waitForRound(page);
    await expect(page.locator('[data-ai-drawing-target="thinking"]')).toBeHidden({ timeout: 15_000 });

    // The player says "kitty …": the judge takes 3 s, and the walrus thinks aloud meanwhile. One word per
    // width: the judge keeps the pairs it judged, and would answer at once the second time.
    const kitty = `kitty ${test.info().project.name}`;
    await answer(page, kitty);
    await expect(page.locator('.referee-bubble.referee-thinking')).toContainText(kitty);
    // The rival says the exact word after it: known at once, but it waits for "kitty".
    const rival = await callTool(page.request, 'submit_answer', { word: 'cat' });
    expect(rival).toContain('too late');

    await expect(page.locator('[data-ai-drawing-target="list"] li.accepted')).toHaveText(kitty);
    await expect(page.locator('.referee-bubble.referee-accepted')).toBeVisible();
    await page.reload();
    await expect(page.locator('.round-end h2.side-team')).toBeVisible();
    await checkLayout(page, { referee: 'field' });
});

// A phone held upright, once: the screens the rounds above do not reach at this width. The end of a round,
// the walrus's bubble beside him, and the final table, which in a long language scrolls in its own box.
test.describe('on a phone', () => {
    test.use({ viewport: { width: 420, height: 900 } });

    test('a duel to its final table, nothing wider than the window', async ({ page }) => {
        test.skip(test.info().project.name !== 'narrow', 'one width is enough');
        // Measured at rest: a screen that comes in may lean out of the window for a moment. Olaf never stops moving.
        const fits = async (screen) => {
            await page.waitForFunction(() => document.getAnimations().every((animation) => 'running' !== animation.playState || Infinity === animation.effect?.getComputedTiming().iterations));
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            expect(overflow, `${screen}: wider than the window`).toBeLessThanOrEqual(0);
        };

        await startGame(page, 'duel');
        await page.waitForURL(/\/lobby$/);
        await callTool(page.request, 'choose_nickname', { nickname: 'Smokey' });
        await expect(page.locator('.agent-status.connected')).toContainText('Smokey');
        await leaveFor(page, page.getByRole('button', { name: 'Start the game' }));
        await waitForRound(page);
        await expect(page.locator('[data-ai-drawing-target="thinking"]')).toBeHidden({ timeout: 15_000 });

        await answer(page, 'cat');
        await expect(page.locator('.pitch .referee-bubble.referee-accepted')).toBeVisible();
        await fits('the walrus and his bubble');
        await page.reload();
        await expect(page.locator('.round-end')).toBeVisible();
        await fits('the end of a round');

        await leaveFor(page, page.locator('.round-end button[type="submit"]'));
        await waitForRound(page);
        await stroke(page, [[300, 300], [700, 300], [700, 700], [300, 700], [300, 300]]);
        await expect(page.locator('[data-guesses-target="list"] li').nth(1)).toHaveText('kitten');
        await stroke(page, [[350, 250], [400, 150], [450, 250]]);
        await expect(page.locator(OVER).first()).toBeVisible();
        await page.reload();
        await leaveFor(page, page.locator('.round-end a.button'));
        await expect(page.locator('.game-over table')).toBeVisible();
        await fits('the final table');
    });
});
