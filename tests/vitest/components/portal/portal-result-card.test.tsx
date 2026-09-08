import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalResultCard } from '@/components/portal/PortalResultCard';
import type { PortalBasePath, PortalCreator, PortalResource, PortalResourcePreview } from '@/types/portal';

import { http, HttpResponse, server } from '../../helpers/msw-server';

function createMockResource(overrides: Partial<PortalResource> = {}): PortalResource {
    return {
        id: 1,
        title: 'Test Resource Title',
        doi: '10.5880/GFZ.TEST.2024.001',
        resourceType: 'Dataset',
        resourceTypeSlug: 'dataset',
        isIgsn: false,
        year: 2024,
        landingPageUrl: '/landing/test-slug',
        creators: [{ name: 'Smith' }],
        geoLocations: [],
        ...overrides,
    };
}

const preview: PortalResourcePreview = {
    resourceId: 1,
    citation: {
        styleId: 'apa-7',
        label: 'APA 7',
        text: 'Smith, J. (2024). Test Resource Title. https://doi.org/10.5880/GFZ.TEST.2024.001',
    },
    abstract: 'This abstract gives searchers more context without opening the landing page.',
};

function renderCard(resource = createMockResource(), basePath: PortalBasePath = '/doi-search') {
    return render(<PortalResultCard resource={resource} basePath={basePath} />);
}

describe('PortalResultCard', () => {
    beforeEach(() => {
        server.use(http.get('/doi-search/resources/:resourceId/preview', () => HttpResponse.json(preview)));
    });

    describe('compact result content', () => {
        it('renders title, DOI, type, year, and the landing-page link', () => {
            renderCard(createMockResource({ title: 'Climate Data for Europe 2024' }));

            expect(screen.getByText('Climate Data for Europe 2024')).toBeInTheDocument();
            expect(screen.getByText('10.5880/GFZ.TEST.2024.001')).toBeInTheDocument();
            expect(screen.getByText('Dataset')).toBeInTheDocument();
            expect(screen.getByText('2024')).toBeInTheDocument();
            expect(screen.getByRole('link')).toHaveAttribute('href', '/landing/test-slug');
            expect(screen.getByRole('link')).toHaveAttribute('target', '_blank');
            expect(screen.getByRole('link')).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('handles missing DOI, year, and landing-page URL', () => {
            renderCard(createMockResource({ doi: null, year: null, landingPageUrl: null }));

            expect(screen.queryByText(/10\.5880/)).not.toBeInTheDocument();
            expect(screen.queryByText('2024')).not.toBeInTheDocument();
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            expect(screen.getByRole('button', { name: /show citation and abstract/i })).toBeInTheDocument();
        });

        it('keeps the title flexible, metadata fixed, and info action non-shrinking', () => {
            renderCard(
                createMockResource({
                    title: 'A very long dataset title that must truncate before pushing fixed metadata out of a narrow result panel',
                }),
            );

            expect(screen.getByTestId('portal-result-title')).toHaveClass('min-w-0', 'flex-1', 'truncate');
            expect(screen.getByTestId('portal-result-meta')).toHaveClass('shrink-0');
            expect(screen.getByRole('button', { name: /show citation and abstract/i })).toHaveClass('shrink-0');
        });

        it.each([
            { creators: [{ name: 'Johnson' }], expected: 'Johnson' },
            { creators: [{ name: 'Smith' }, { name: 'Jones' }], expected: 'Smith & Jones' },
            { creators: [{ name: 'Miller' }, { name: 'Brown' }, { name: 'Wilson' }], expected: 'Miller et al.' },
            { creators: [], expected: 'Unknown' },
            { creators: [{ name: '' }], expected: 'Unknown' },
        ] satisfies Array<{ creators: PortalCreator[]; expected: string }>)('formats creator summary as $expected', ({ creators, expected }) => {
            renderCard(createMockResource({ creators, year: null }));

            expect(screen.getByText(expected)).toBeInTheDocument();
        });

        it('uses the IGSN badge for physical samples', () => {
            renderCard(createMockResource({ isIgsn: true, resourceType: 'PhysicalObject' }), '/igsn-search');

            expect(screen.getByText('IGSN')).toBeInTheDocument();
        });
    });

    describe('separate interactions', () => {
        it('places the info button before and outside the landing-page link', () => {
            renderCard();

            const button = screen.getByRole('button', { name: /show citation and abstract/i });
            const link = screen.getByRole('link');
            const card = button.closest('[data-slot="portal-result-card"]');

            expect(button.closest('a')).toBeNull();
            expect(card).not.toBeNull();
            expect(card?.firstElementChild).toBe(button);
            expect(button.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('does not open or request details on row hover or keyboard focus', async () => {
            let requests = 0;
            server.use(
                http.get('/doi-search/resources/:resourceId/preview', () => {
                    requests++;
                    return HttpResponse.json(preview);
                }),
            );
            const user = userEvent.setup();
            renderCard();

            await user.hover(screen.getByRole('link'));
            await user.tab();
            await user.tab();

            expect(screen.queryByTestId('portal-result-preview')).not.toBeInTheDocument();
            expect(requests).toBe(0);
        });

        it('opens only from the info button and loads the DOI preview on demand', async () => {
            let requestedId = '';
            server.use(
                http.get('/doi-search/resources/:resourceId/preview', ({ params }) => {
                    requestedId = String(params.resourceId);
                    return HttpResponse.json(preview);
                }),
            );
            const user = userEvent.setup();
            renderCard();

            expect(screen.queryByTestId('portal-result-preview')).not.toBeInTheDocument();
            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));

            const popover = await screen.findByTestId('portal-result-preview');
            expect(requestedId).toBe('1');
            expect(within(popover).getByText('Citation (APA 7)')).toBeInTheDocument();
            expect(within(popover).getByTestId('portal-preview-citation')).toHaveTextContent(preview.citation.text);
            expect(within(popover).getByText(preview.abstract!)).toBeInTheDocument();
        });

        it.each(['{Enter}', ' '] as const)('supports the %s keyboard activation', async (key) => {
            const user = userEvent.setup();
            renderCard();

            await user.tab();
            expect(screen.getByRole('button', { name: /show citation and abstract/i })).toHaveFocus();
            await user.keyboard(key);

            expect(await screen.findByTestId('portal-result-preview')).toBeInTheDocument();
        });

        it('closes with Escape and returns focus to the info button', async () => {
            const user = userEvent.setup();
            renderCard();
            const button = screen.getByRole('button', { name: /show citation and abstract/i });

            await user.click(button);
            expect(await screen.findByTestId('portal-result-preview')).toBeInTheDocument();
            await user.keyboard('{Escape}');

            await waitFor(() => expect(screen.queryByTestId('portal-result-preview')).not.toBeInTheDocument());
            expect(button).toHaveFocus();
        });

        it('requests the scoped IGSN endpoint for a physical sample', async () => {
            let requests = 0;
            server.use(
                http.get('/igsn-search/resources/:resourceId/preview', () => {
                    requests++;
                    return HttpResponse.json({ ...preview, citation: { ...preview.citation, text: 'IGSN citation' } });
                }),
            );
            const user = userEvent.setup();
            renderCard(createMockResource({ isIgsn: true, resourceType: 'PhysicalObject' }), '/igsn-search');

            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));

            expect(await screen.findByText('IGSN citation')).toBeInTheDocument();
            expect(requests).toBe(1);
        });
    });

    describe('preview states and actions', () => {
        it('shows a loading state while the request is pending', async () => {
            server.use(
                http.get(
                    '/doi-search/resources/:resourceId/preview',
                    async ({ request }) =>
                        new Promise<HttpResponse<{ message: string }>>((resolve) => {
                            request.signal.addEventListener('abort', () => resolve(HttpResponse.json({ message: 'Aborted' }, { status: 499 })));
                        }),
                ),
            );
            const user = userEvent.setup();
            renderCard();

            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));

            expect(await screen.findByLabelText('Loading citation and abstract')).toBeInTheDocument();
        });

        it('shows a defined empty state when no abstract exists', async () => {
            server.use(http.get('/doi-search/resources/:resourceId/preview', () => HttpResponse.json({ ...preview, abstract: null })));
            const user = userEvent.setup();
            renderCard();

            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));

            expect(await screen.findByText('No abstract is available for this resource.')).toBeInTheDocument();
        });

        it('renders metadata as text rather than executable markup', async () => {
            server.use(
                http.get('/doi-search/resources/:resourceId/preview', () =>
                    HttpResponse.json({
                        ...preview,
                        citation: { ...preview.citation, text: '<img src=x onerror=alert(1)> Citation' },
                        abstract: '<script>window.hacked = true</script> Abstract',
                    }),
                ),
            );
            const user = userEvent.setup();
            renderCard();

            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));
            const popover = await screen.findByTestId('portal-result-preview');

            expect(within(popover).getByText('<img src=x onerror=alert(1)> Citation')).toBeInTheDocument();
            expect(within(popover).getByText('<script>window.hacked = true</script> Abstract')).toBeInTheDocument();
            expect(popover.querySelector('img')).toBeNull();
            expect(popover.querySelector('script')).toBeNull();
        });

        it('offers retry after a non-retriable request failure', async () => {
            let attempts = 0;
            server.use(
                http.get('/doi-search/resources/:resourceId/preview', () => {
                    attempts++;
                    return attempts === 1 ? HttpResponse.json({ message: 'Missing' }, { status: 404 }) : HttpResponse.json(preview);
                }),
            );
            const user = userEvent.setup();
            renderCard();

            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));
            expect(await screen.findByRole('alert')).toHaveTextContent('Citation and abstract could not be loaded.');
            await user.click(screen.getByRole('button', { name: 'Retry' }));

            expect(await screen.findByTestId('portal-preview-citation')).toHaveTextContent(preview.citation.text);
            expect(attempts).toBe(2);
        });

        it('copies exactly the delivered citation text', async () => {
            const user = userEvent.setup();
            const clipboardSpy = vi.spyOn(navigator.clipboard, 'writeText');
            renderCard();

            await user.click(screen.getByRole('button', { name: /show citation and abstract/i }));
            await user.click(await screen.findByRole('button', { name: 'Copy citation to clipboard' }));

            expect(clipboardSpy).toHaveBeenCalledWith(preview.citation.text);
            expect(await screen.findByRole('status')).toHaveTextContent('Citation copied to clipboard');
        });
    });
});
