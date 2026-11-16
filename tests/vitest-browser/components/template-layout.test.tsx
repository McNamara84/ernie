import { vi } from 'vitest';

import LandingPageTemplatesPage from '@/pages/landing-page-templates';
import type { LandingPageTemplateConfig } from '@/types/landing-page';

import { expect, page, render, test } from '../helpers/test';

const put = vi.hoisted(() => vi.fn().mockResolvedValue({ data: {} }));
const reload = vi.hoisted(() => vi.fn());

vi.mock('axios', () => ({ default: { put }, isAxiosError: () => false }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children?: React.ReactNode }) => children }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload },
    usePage: () => ({
        props: {
            auth: { user: { can_manage_landing_page_templates: true } },
            templates: [template],
            datacenters: [],
            dateTypes: [],
            relationTypes: [],
            logoUploadConstraints: {
                minWidth: 1080,
                minHeight: 120,
                recommendedWidth: 1800,
                recommendedHeight: 200,
                maxWidth: 2700,
                maxHeight: 300,
                aspectRatio: '9:1',
                aspectRatioValue: 9,
                maxSizeKb: 2048,
                formats: ['PNG'],
            },
        },
    }),
}));

const template: LandingPageTemplateConfig = {
    id: 2,
    name: 'Browser layout',
    slug: 'browser-layout',
    is_default: false,
    template_type: 'resource',
    logo_path: null,
    logo_filename: null,
    logo_url: null,
    right_column_order: [
        'abstract',
        'methods',
        'technical_info',
        'series_information',
        'table_of_contents',
        'other',
        'creators',
        'contributors',
        'funders',
        'keywords',
        'metadata_download',
        'location',
        'usage',
    ],
    left_column_order: ['files', 'citation', 'dates', 'contact', 'related_work'],
    hidden_sections: [],
    creator_display_limit: 50,
    contributor_display_limit: 50,
    citation_author_display_limit: 50,
    excluded_date_type_ids: [],
    excluded_relation_type_ids: [],
    created_by: null,
    creator: null,
    landing_pages_count: 0,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

test('persists module ordering after actual keyboard dragging in the template editor', async ({ user }) => {
    await render(<LandingPageTemplatesPage />);
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    const handle = page.getByRole('button', { name: 'Reorder Creators / Authors', exact: true });
    handle.element().focus();
    await user.keyboard('[Space]');
    await expect.element(handle).toHaveAttribute('aria-pressed', 'true');
    await user.keyboard('{ArrowDown}');
    await user.keyboard('[Space]');
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expect.poll(() => put.mock.calls.length).toBe(1);
    expect(put.mock.calls[0][0]).toBe('/landing-pages/2');
    const order = put.mock.calls[0][1].right_column_order as string[];
    expect(order.indexOf('contributors')).toBeLessThan(order.indexOf('creators'));
    expect(reload).toHaveBeenCalledExactlyOnceWith({ only: ['templates', 'datacenters'] });
});

test('hides and restores a module through its real column controls before saving', async () => {
    await render(<LandingPageTemplatesPage />);
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await page.getByRole('button', { name: 'Hide Keywords / Subjects', exact: true }).click();
    await expect.element(page.getByRole('button', { name: 'Show Keywords / Subjects in right column', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Show Keywords / Subjects in right column', exact: true }).click();
    await expect.element(page.getByRole('button', { name: 'Hide Keywords / Subjects', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expect.poll(() => put.mock.calls.length).toBe(1);
    expect(put.mock.calls[0][1].hidden_sections).not.toContain('keywords');
    expect(put.mock.calls[0][1].right_column_order).toContain('keywords');
});
