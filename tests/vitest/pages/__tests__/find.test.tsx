import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@tests/vitest/utils/render';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import Find from '@/pages/find';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children, title }: { children?: ReactNode; title: string }) => (
        <>
            <title>{title}</title>
            {children}
        </>
    ),
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a data-testid="inertia-link" {...props} />,
}));
vi.mock('@/hooks/use-nprogress', () => ({ useNProgress: vi.fn() }));

describe('Find overview', () => {
    it('renders one main heading and the four named sections in their original order', () => {
        render(<Find />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Find');
        const sections = within(screen.getByRole('main')).getAllByRole('region');
        expect(sections.map((section) => within(section).getByRole('heading', { level: 2 }).textContent)).toEqual([
            'Data Portal',
            'Data Centres',
            'Research Infrastructures at GFZ',
            'IGSN Portal',
        ]);
    });

    it.each([
        ['Data Portal', '/doi-search', /research data and scientific software/, /data centre, resource type, location or time period/],
        ['IGSN Portal', '/igsn-search', /physical samples/, /sample type, material or geological classification/],
    ] as const)('describes %s and links its heading and call to action to the local portal', (name, href, content, filters) => {
        render(<Find />);
        const section = within(screen.getByRole('region', { name }));
        expect(section.getByRole('link', { name })).toHaveAttribute('href', href);
        expect(section.getByRole('link', { name: `Explore the ${name}` })).toHaveAttribute('href', href);
        for (const link of section.getAllByRole('link')) {
            expect(link).toHaveAttribute('data-testid', 'inertia-link');
            expect(link).not.toHaveAttribute('target');
        }
        expect(section.getByText(content)).toBeVisible();
        expect(section.getByText(filters)).toBeVisible();
    });

    it('preserves the complete Data Centres paragraph and its original link destinations', () => {
        render(<Find />);
        const region = screen.getByRole('region', { name: 'Data Centres' });
        // Source: https://dataservices.gfz-potsdam.de/web/find, captured 2026-09-28.
        expect(region.querySelector('p')).toHaveTextContent(
            'During the past years we have developed several DOI minting services for larger projects and international services and networks. Our Data Centres organise data publications according to their projects, networks or services affinity and may have project-specific landing page designs.',
        );
        expect(within(region).getByRole('link', { name: 'Data Centres' })).toHaveAttribute(
            'href',
            'https://dataservices.gfz-potsdam.de/web/find/data-centres',
        );
        expect(within(region).getByRole('link', { name: 'DOI' })).toHaveAttribute('href', 'https://dataservices.gfz-potsdam.de/web/support/glossary');
    });

    it('preserves both infrastructure paragraphs and links the discovery portal name to its website', () => {
        render(<Find />);
        const region = screen.getByRole('region', { name: 'Research Infrastructures at GFZ' });
        const paragraphs = region.querySelectorAll('p');
        // Preserve the source paragraphs while correcting the legacy mailto target to the discovery portal.
        expect(paragraphs).toHaveLength(2);
        expect(paragraphs[0]).toHaveTextContent(
            'The research infrastructure at GFZ comprises satellite systems, global station networks, and regional observatories, as well as instrument networks, laboratories, instrument pools and data systems. As a Helmholtz centre, GFZ fulfils an important role in providing infrastructure, data, information, instrument systems and networks. We classify the instruments and services of our research infrastructure that are available to the entire international scientific community and which are subject to special terms of use as Modular Earth Science Infrastructure (MESI).',
        );
        expect(paragraphs[1]).toHaveTextContent(
            'ResearchInfrastructure@GFZ is the central discovery portal for research infrastructures and data at GFZ. It enables structured searches across disciplines, product categories (data, services and software), persons and GFZ sections, and provides basic information with links to further information on the GFZ websites and data access.',
        );
        const section = within(region);
        expect(section.getByRole('link', { name: 'Research Infrastructures at GFZ' })).toHaveAttribute(
            'href',
            'https://research-infrastructure.gfz.de/en/',
        );
        expect(section.getByRole('link', { name: 'MESI' })).toHaveAttribute(
            'href',
            'https://www.gfz.de/en/research/topics/our-research-program/research-infrastructures/mesi',
        );
        expect(section.getByRole('link', { name: 'ResearchInfrastructure@GFZ' })).toHaveAttribute(
            'href',
            'https://research-infrastructure.gfz.de/en/',
        );
        for (const link of section.getAllByRole('link')) {
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        }
    });

    it('uses the public footer and a skip link pointing at the focusable Find content', () => {
        render(<Find />);
        expect(screen.getByRole('link', { name: 'Skip to content' })).toHaveAttribute('href', '#find-content');
        expect(screen.getByRole('main')).toHaveAttribute('id', 'find-content');
        expect(screen.getByRole('main')).toHaveAttribute('tabindex', '-1');
        const footer = within(screen.getByRole('navigation', { name: 'Footer navigation' }));
        expect(footer.getByRole('link', { name: 'Legal Notice' })).toHaveAttribute('href', '/legal-notice');
        expect(footer.getByRole('link', { name: 'Data Protection' })).toBeVisible();
        expect(footer.getByRole('link', { name: 'Copyrights' })).toBeVisible();
    });

    it('sets Find metadata and its canonical production URL', () => {
        render(<Find />);
        expect(document.title).toBe('Find');
        expect(document.querySelector('meta[name="description"]')).toHaveAttribute('content', expect.stringContaining('Data Portal and IGSN Portal'));
        expect(document.querySelector('link[rel="canonical"]')).toHaveAttribute('href', 'https://dataservices.gfz.de/find');
    });

    it('provides four local previews with descriptive alternatives, reserved dimensions and matching destination links', () => {
        render(<Find />);
        const previews = [
            ['Data Portal', 'data-portal.png', '/doi-search', 1440, 900, /search interface with filters/],
            [
                'Data Centres',
                'data-centres.png',
                'https://dataservices.gfz-potsdam.de/web/find/data-centres',
                620,
                321,
                /logos of participating projects/,
            ],
            [
                'Research Infrastructures at GFZ',
                'research-infrastructures.png',
                'https://research-infrastructure.gfz.de/en/',
                620,
                318,
                /instruments, laboratories and data services/,
            ],
            ['IGSN Portal', 'igsn-portal.png', '/igsn-search', 1440, 900, /sample filters, registered samples/],
        ] as const;

        expect(within(screen.getByRole('main')).getAllByRole('img')).toHaveLength(4);
        for (const [index, [name, filename, href, width, height, alternative]] of previews.entries()) {
            const image = within(screen.getByRole('region', { name })).getByRole('img', { name: alternative });
            expect(image).toHaveAttribute('src', `/images/find/${filename}`);
            expect(image).toHaveAttribute('width', String(width));
            expect(image).toHaveAttribute('height', String(height));
            expect(image.closest('a')).toHaveAttribute('href', href);
            if (index === 0) {
                expect(image).toHaveAttribute('fetchpriority', 'high');
                expect(image).not.toHaveAttribute('loading', 'lazy');
            } else {
                expect(image).toHaveAttribute('loading', 'lazy');
            }
        }
    });
});
