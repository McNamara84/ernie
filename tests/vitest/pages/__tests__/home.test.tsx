import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@tests/vitest/utils/render';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Home from '@/pages/home';

const router = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router,
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
vi.mock('@/hooks/use-nprogress', () => ({ useNProgress: vi.fn() }));

const topics = [
    { slug: 'atmosphere', label: 'Atmosphere', image: '/images/home/topics/HEx_buttons_atmosphere.png', href: '/doi-search?topic=atmosphere' },
    {
        slug: 'gravity',
        label: 'Gravity/ Gravitational Field',
        image: '/images/home/topics/HEx_buttons_gravimetry.png',
        href: '/doi-search?topic=gravity',
    },
    {
        slug: 'scientific-drilling',
        label: 'Scientific Drilling',
        image: '/images/home/topics/HEx_buttons_scientific_drilling.png',
        href: '/doi-search?topic=scientific-drilling',
    },
];

describe('GFZ Data Services homepage', () => {
    beforeEach(() => vi.clearAllMocks());

    it('preserves the welcome references and the complete ELMO announcement', () => {
        render(<Home topics={topics} />);
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('GFZ Data Services');
        expect(screen.getByRole('heading', { name: 'Welcome to GFZ Data Services' })).toBeVisible();
        expect(screen.getByRole('link', { name: /research data repository/ })).toHaveAttribute('href', 'https://dataservices.gfz.de/portal');
        expect(screen.getByRole('link', { name: 'GFZ Catalogue' })).toHaveAttribute('href', 'https://dataservices.gfz.de/igsn-new');
        expect(screen.getByRole('link', { name: 'IGSN International Generic Sample Number' })).toHaveAttribute('href', 'https://www.igsn.org/');
        expect(screen.getByRole('link', { name: 'GFZ Research Infrastructures' })).toHaveAttribute(
            'href',
            'https://research-infrastructure.gfz.de/en/',
        );
        const news = screen.getByRole('article', { name: 'Welcome ELMO - our new Metadata Editor!' });
        expect(news).toHaveTextContent(
            'In November 2025, the GFZ Data Services team proudly launched the new, fully revised and modernised version of our new metadata editor ELMO. ELMO is not only a new web interface, but also contains many revised functionalities that increase the quality of metadata and the FAIRness of the data describing it, while at the same time simplifying the entry of information for researchers.',
        );
    });

    it('opens ELMO in a new tab and exposes all supplied topics as accessible internal links', () => {
        render(<Home topics={topics} />);
        const submit = screen.getByRole('link', { name: /SUBMIT METADATA/ });
        expect(submit).toHaveAttribute('href', 'https://dataservices.gfz.de/elmo');
        expect(submit).toHaveAttribute('target', '_blank');
        expect(submit).toHaveAttribute('rel', 'noopener noreferrer');
        const topicLinks = within(screen.getByRole('list', { name: 'Science topics' })).getAllByRole('link');
        expect(topicLinks).toHaveLength(3);
        for (const [index, link] of topicLinks.entries()) {
            expect(link).toHaveAccessibleName(topics[index].label);
            expect(link).toHaveAttribute('href', topics[index].href);
            expect(link.querySelector('img')).toHaveAttribute('src', topics[index].image);
            expect(link.querySelector('img')).toHaveAttribute('alt', '');
            expect(link).not.toHaveAttribute('target');
        }
    });

    it.each(['enter', 'button'])('submits trimmed text and special characters using %s', async (submit) => {
        const user = userEvent.setup();
        render(<Home topics={topics} />);
        await user.type(screen.getByRole('searchbox'), '  Höhle & CO2 + ice  ');
        if (submit === 'enter') await user.keyboard('{Enter}');
        else await user.click(screen.getByRole('button', { name: 'Search' }));
        expect(router.get).toHaveBeenCalledWith('/doi-search', { q: 'Höhle & CO2 + ice' });
    });

    it('opens the DOI search without a query for blank input', async () => {
        const user = userEvent.setup();
        render(<Home topics={topics} />);
        await user.type(screen.getByRole('searchbox'), '   {Enter}');
        expect(router.get).toHaveBeenCalledWith('/doi-search', {});
    });

    it('includes all three legacy link groups and the public footer', () => {
        render(<Home topics={topics} />);
        expect(within(screen.getByRole('region', { name: 'Services' })).getAllByRole('link')).toHaveLength(5);
        expect(within(screen.getByRole('region', { name: 'Guides' })).getAllByRole('link')).toHaveLength(6);
        expect(within(screen.getByRole('region', { name: 'External Links to our data' })).getAllByRole('link')).toHaveLength(5);
        expect(screen.getByRole('link', { name: 'Publication Instructions' })).toHaveAttribute(
            'href',
            'https://dataservices.gfz-potsdam.de/web/publish-data/publication-instructions',
        );
        expect(screen.getByRole('link', { name: 'Copyrights' })).toHaveAttribute(
            'href',
            'https://dataservices.gfz-potsdam.de/web/about-us/copyrights',
        );
        expect(screen.getByRole('link', { name: 'Skip to content' })).toHaveAttribute('href', '#home-content');
        expect(screen.getByRole('main')).toHaveAttribute('tabindex', '-1');
    });
});
