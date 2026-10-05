import { expect, type Page, test } from '@playwright/test';

// Exercise the real application UI with isolated API fixtures. PHP feature tests
// separately verify persistence, authorization and locking on SQLite and MySQL 9.7.
const assistantId = 'subject-hierarchy-correction';
const root = 'https://example.org/root';
const leaves = ['https://example.org/alpha', 'https://example.org/beta'];
const fingerprint = 'a'.repeat(64);
const manifest = {
    id: assistantId,
    name: 'Subject Hierarchy Correction',
    description: 'Review broad subject terms.',
    icon: 'Tags',
    version: '1.0.0',
    routePrefix: assistantId,
    sortOrder: 55,
    statusLabels: {},
    emptyState: { title: 'No pending subjects', description: 'All reviewed.' },
    cardComponent: 'subject-hierarchy-correction-card',
};

test.afterEach(async ({ page }) => {
    // Inertia reloads can still be fetching fixture HTML when assertions finish.
    await page.unrouteAll({ behavior: 'wait' });
});

async function openReview(page: Page) {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    // Establish the browser's own TLS connection before fulfilling the HTML route.
    await page.goto('/robots.txt', { waitUntil: 'domcontentloaded' });
    let pending = true;
    let statusChecks = 0;
    const accepted: Record<string, unknown>[] = [];
    const declined: Record<string, unknown>[] = [];
    const proposal = {
        id: 11,
        assistant_id: assistantId,
        resource_id: 10,
        resource_doi: '10.5880/browser-fixture',
        resource_title: 'Browser fixture',
        discovered_at: '2026-10-01T10:00:00Z',
        suggested_label: 'Review Root',
        metadata: {
            scheme: 'Platforms',
            broader_id: root,
            broader_label: 'Root',
            fingerprint,
            existing_leaf_ids: [],
            leaf_ids: leaves,
            nodes: [
                { id: root, label: 'Root', path: 'Root', description: '', children: leaves, selectable: true },
                ...leaves.map((id, index) => ({
                    id,
                    label: index === 0 ? 'Alpha' : 'Beta',
                    path: `Root > ${index === 0 ? 'Alpha' : 'Beta'}`,
                    description: '',
                    children: [],
                    selectable: true,
                })),
            ],
        },
        review: {
            assistant_id: assistantId,
            assistant_name: manifest.name,
            route_prefix: assistantId,
            can_accept: true,
            can_decline: true,
            exclusive_target_key: `${assistantId}:10:Platforms`,
            label: 'Review Root',
        },
    };
    await page.route(/\/assistance(?:\?.*)?$/, async (route) => {
        const response = await route.fetch({ url: new URL('/login', route.request().url()).href });
        const body = await response.text();
        const paging = {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: pending ? 1 : 0,
            from: pending ? 1 : null,
            to: pending ? 1 : null,
            links: [],
        };
        const groups = {
            ...paging,
            data: pending
                ? [
                      {
                          resource_id: 10,
                          resource_doi: proposal.resource_doi,
                          resource_title: proposal.resource_title,
                          suggestion_count: 1,
                          suggestions: [proposal],
                      },
                  ]
                : [],
        };
        const replace = (json: string) => {
            const payload = JSON.parse(json);
            payload.component = 'assistance';
            payload.url = '/assistance';
            payload.props = {
                ...payload.props,
                auth: {
                    user: {
                        id: 1,
                        name: 'Browser fixture',
                        email: 'browser@example.org',
                        role: 'admin',
                        email_verified_at: '2026-10-01',
                        is_active: true,
                    },
                },
                manifests: [manifest],
                sections: { [assistantId]: groups },
                allAssistantResources: groups,
                datacenterOptions: [],
                pendingAssistanceTotalCount: pending ? 1 : 0,
            };
            return JSON.stringify(payload).replaceAll('<', '\\u003c');
        };
        const script = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
        if (response.headers()['content-type']?.includes('application/json')) {
            await route.fulfill({ response, body: replace(body) });
        } else {
            expect(body).toMatch(script);
            await route.fulfill({ response, body: body.replace(script, (_match, start, json, end) => start + replace(json) + end) });
        }
    });
    await page.route(/\/assistance\/suggestions\/batch\/(accept|decline)$/, async (route) => {
        const action = route.request().url().endsWith('/accept') ? 'accept' : 'decline';
        (action === 'accept' ? accepted : declined).push(route.request().postDataJSON());
        pending = false;
        await route.fulfill({
            json: {
                success: true,
                success_count: 1,
                failure_count: 0,
                message: 'Saved.',
                follow_ups: [],
                results: [
                    {
                        assistant_id: assistantId,
                        assistant_name: manifest.name,
                        suggestion_id: 11,
                        label: 'Review Root',
                        success: true,
                        message: 'Saved.',
                    },
                ],
            },
        });
    });
    await page.route(/\/assistance\/check\/subject-hierarchy-correction(?:\/[^/]+\/status)?$/, async (route) => {
        if (route.request().method() === 'GET') statusChecks++;
        await route.fulfill({
            json:
                route.request().method() === 'POST'
                    ? { jobId: '00000000-0000-4000-8000-000000000001' }
                    : { status: 'completed', newSuggestionsFound: 0, details: { checked_resources: 1, subjects_unresolved: 0 } },
        });
    });
    await page.route('**/assistance/check-all', (route) =>
        route.fulfill({ json: { [`${assistantId}JobId`]: '00000000-0000-4000-8000-000000000001' } }),
    );
    await page.goto('/assistance');
    await expect(page.getByRole('heading', { name: 'Assistance', exact: true })).toBeVisible();
    return { accepted, declined, statusChecks: () => statusChecks };
}

test('confirms a subset using the keyboard and sends the same selection through resource review', async ({ page }) => {
    const { accepted } = await openReview(page);
    await page.getByRole('checkbox', { name: 'Select Subject Hierarchy Correction: Review Root' }).check();
    await expect(page.getByRole('button', { name: 'Accept', exact: true })).toBeDisabled();
    const alpha = page.getByRole('checkbox', { name: 'Choose Root > Alpha', exact: true });
    await alpha.focus();
    await page.keyboard.press('Space');
    await expect(page.getByText('The broader term will be removed.')).toBeVisible();
    await page.getByRole('button', { name: 'Accept', exact: true }).click();
    await expect
        .poll(() => accepted)
        .toEqual([
            {
                resource_id: 10,
                suggestions: [
                    { assistant_id: assistantId, suggestion_id: 11, selected_leaf_ids: [leaves[0]], subject_hierarchy_fingerprint: fingerprint },
                ],
            },
        ]);
    await expect(page.getByTestId('subject-hierarchy-11')).toHaveCount(0);
});

test('selects the complete subtree even under search and previews retaining the broader term', async ({ page }) => {
    const { accepted } = await openReview(page);
    await page.getByRole('textbox', { name: 'Search narrower terms for Root' }).fill('Alpha');
    await expect(page.getByRole('checkbox', { name: 'Choose Root > Beta' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Select all 2 narrower terms' }).click();
    await expect(page.getByText('The broader term will be retained.')).toBeVisible();
    await page.getByRole('checkbox', { name: 'Select Subject Hierarchy Correction: Review Root' }).check();
    await page.getByRole('button', { name: 'Accept', exact: true }).click();
    await expect
        .poll(() => accepted[0]?.suggestions)
        .toEqual([{ assistant_id: assistantId, suggestion_id: 11, selected_leaf_ids: leaves, subject_hierarchy_fingerprint: fingerprint }]);
});

test('requires a reason before declining and completes another check', async ({ page }) => {
    const { declined, statusChecks } = await openReview(page);
    await page.getByRole('checkbox', { name: 'Select Subject Hierarchy Correction: Review Root' }).check();
    await page.getByRole('button', { name: 'Decline', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Keep with reason' })).toBeDisabled();
    await page.getByRole('textbox', { name: 'Reason for retaining broader terms' }).fill('The resource describes the wider scope.');
    await page.getByRole('button', { name: 'Keep with reason' }).click();
    await expect
        .poll(() => declined)
        .toEqual([
            { resource_id: 10, reason: 'The resource describes the wider scope.', suggestions: [{ assistant_id: assistantId, suggestion_id: 11 }] },
        ]);
    await page.getByRole('button', { name: 'Check all', exact: true }).click();
    await expect.poll(statusChecks).toBeGreaterThan(0);
    await expect(page.getByRole('button', { name: 'Check all', exact: true })).toBeEnabled();
    await expect(page.getByTestId('subject-hierarchy-11')).toHaveCount(0);
});
