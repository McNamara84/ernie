import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DataCentreDescription from '@/pages/data-centres/description';
import DataCentres from '@/pages/data-centres/index';
import type { DataCentre } from '@/types/data-centres';

import catalogue from '../../../../resources/data/data-centres.json';

const navigation = vi.hoisted(() => ({ callback: undefined as (() => void) | undefined, unsubscribe: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: ({ children, title }: { children?: ReactNode; title: string }) => (
        <>
            <title>{title}</title>
            {children}
        </>
    ),
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a data-testid="inertia-link" {...props} />,
    router: {
        on: vi.fn((_event: string, callback: () => void) => {
            navigation.callback = callback;
            return navigation.unsubscribe;
        }),
    },
}));
vi.mock('@/hooks/use-nprogress', () => ({ useNProgress: vi.fn() }));

const centres: DataCentre[] = catalogue;
const fid = centres.find((centre) => centre.slug === 'fid-geo')!;
const gfz = centres.find((centre) => centre.slug === 'gfz')!;
const pair = [fid, gfz];

beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/data-centres/description');
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => vi.restoreAllMocks());

describe('Data Centres overview', () => {
    it('links every production centre to a fresh DOI search using its exact stored name', () => {
        render(<DataCentres dataCentres={centres} />);
        const items = within(screen.getByRole('list', { name: 'Data centres' })).getAllByRole('listitem');
        expect(items).toHaveLength(centres.length);
        for (const [index, centre] of centres.entries()) {
            const item = within(items[index]);
            const link = item.getByRole('link', { name: `View publications: ${centre.shortName} — ${centre.displayName}` });
            const url = new URL(link.getAttribute('href')!, window.location.origin);
            expect(url.pathname).toBe('/doi-search');
            expect([...url.searchParams.entries()]).toEqual([['datacenter[]', centre.datacenterName]]);
            expect(link).toHaveAttribute('data-testid', 'inertia-link');
            expect(link).not.toHaveAttribute('target');
            expect(link).toHaveTextContent(centre.shortName);
            expect(item.getByRole('link', { name: `About this data centre: ${centre.displayName}` })).toHaveAttribute(
                'href',
                `/data-centres/description#${centre.slug}`,
            );
            expect(items[index].querySelector('a a')).toBeNull();
            if (centre.logo) {
                const image = items[index].querySelector('img');
                expect(image).toHaveAttribute('src', centre.logo.src);
                expect(image).toHaveAttribute('alt', '');
                expect(image).toHaveAttribute('width', String(centre.logo.width));
                expect(image).toHaveAttribute('height', String(centre.logo.height));
            } else {
                expect(items[index].querySelector('img')).toBeNull();
                expect(items[index].querySelector('.data-centre-monogram')).toHaveTextContent(centre.shortName);
            }
        }
    });

    it('renders supplied unknown centres as text without interpreting their names as markup', () => {
        const unknown = {
            ...fid,
            slug: 'new-centre',
            datacenterName: 'A+B & <rocks> / 地球',
            displayName: 'A+B & <rocks> / 地球',
            shortName: 'A+B & <rocks> / 地球',
            logo: null,
        };
        render(<DataCentres dataCentres={[unknown]} />);
        const list = screen.getByRole('list', { name: 'Data centres' });
        expect(list).toHaveTextContent(unknown.shortName);
        expect(list.querySelector('rocks')).toBeNull();
        expect(new URL(within(list).getAllByRole('link')[0].getAttribute('href')!, window.location.origin).searchParams.get('datacenter[]')).toBe(
            unknown.datacenterName,
        );
    });
});

describe('Data Centre descriptions', () => {
    it('starts collapsed and lets multiple real accordion panels open and close independently by keyboard', async () => {
        const user = userEvent.setup();
        render(<DataCentreDescription dataCentres={pair} />);
        const buttons = pair.map((centre) => screen.getByRole('button', { name: centre.displayName }));
        for (const button of buttons) expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(2);
        buttons[0].focus();
        await user.keyboard('{Enter}');
        buttons[1].focus();
        await user.keyboard(' ');
        for (const button of buttons) expect(button).toHaveAttribute('aria-expanded', 'true');
        for (const centre of pair) {
            for (const paragraph of centre.description) expect(screen.getByText(paragraph)).toBeVisible();
            const link = screen.getByRole('link', { name: `View publications: ${centre.displayName}` });
            expect(new URL(link.getAttribute('href')!, window.location.origin).searchParams.get('datacenter[]')).toBe(centre.datacenterName);
            for (const external of centre.links) expect(screen.getByRole('link', { name: external.label })).toHaveAttribute('href', external.href);
            expect(screen.getByRole('link', { name: `Direct link: ${centre.displayName}` })).toHaveAttribute('href', `#${centre.slug}`);
        }
        await user.click(buttons[0]);
        expect(buttons[0]).toHaveAttribute('aria-expanded', 'false');
        expect(buttons[1]).toHaveAttribute('aria-expanded', 'true');
    });

    it('opens and focuses an encoded fragment, then allows the visitor to close it', async () => {
        window.history.replaceState(null, '', '#fid%2Dgeo');
        const user = userEvent.setup();
        render(<DataCentreDescription dataCentres={pair} />);
        const trigger = screen.getByRole('button', { name: fid.displayName });
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ block: 'start', behavior: 'instant' });
        await user.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await user.click(screen.getByRole('button', { name: gfz.displayName }));
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });

    it.each(['hashchange', 'popstate', 'pageshow', 'inertia'])(
        'reveals new fragments following %s navigation without closing other panels',
        async (event) => {
            render(<DataCentreDescription dataCentres={pair} />);
            fireEvent.click(screen.getByRole('button', { name: fid.displayName }));
            act(() => {
                window.history.replaceState(null, '', '#gfz');
                if (event === 'inertia') navigation.callback?.();
                else window.dispatchEvent(new Event(event));
            });
            await waitFor(() => expect(screen.getByRole('button', { name: gfz.displayName })).toHaveFocus());
            expect(screen.getByRole('button', { name: fid.displayName })).toHaveAttribute('aria-expanded', 'true');
            expect(screen.getByRole('button', { name: gfz.displayName })).toHaveAttribute('aria-expanded', 'true');
        },
    );

    it.each(['#missing', '#%zz', ''])('ignores an unknown or malformed fragment %s', (hash) => {
        window.history.replaceState(null, '', `/data-centres/description${hash}`);
        render(<DataCentreDescription dataCentres={pair} />);
        for (const centre of pair) expect(screen.getByRole('button', { name: centre.displayName })).toHaveAttribute('aria-expanded', 'false');
        expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
    });

    it('cleans up navigation listeners and pending scrolling when leaving the page', () => {
        const removeListener = vi.spyOn(window, 'removeEventListener');
        const cancelFrame = vi.spyOn(window, 'cancelAnimationFrame');
        window.history.replaceState(null, '', '#fid-geo');
        const { unmount } = render(<DataCentreDescription dataCentres={pair} />);
        unmount();
        expect(navigation.unsubscribe).toHaveBeenCalledOnce();
        expect(removeListener).toHaveBeenCalledWith('hashchange', expect.any(Function));
        expect(removeListener).toHaveBeenCalledWith('popstate', expect.any(Function));
        expect(removeListener).toHaveBeenCalledWith('pageshow', expect.any(Function));
        expect(cancelFrame).toHaveBeenCalled();
    });
});

describe.each([
    ['overview', DataCentres, 'Data Centres', '/data-centres', 'data-centres-content'],
    ['description', DataCentreDescription, 'Data Centre Descriptions', '/data-centres/description', 'data-centres-description-content'],
] as const)('%s page', (_name, Page, title, path, contentId) => {
    it('has one main heading, metadata, a working skip link and the public footer', () => {
        render(<Page dataCentres={pair} />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(title);
        expect(document.title).toBe(title);
        expect(document.querySelector('link[rel="canonical"]')).toHaveAttribute('href', `https://dataservices.gfz.de${path}`);
        expect(document.querySelector('meta[name="description"]')?.getAttribute('content')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Skip to content' })).toHaveAttribute('href', `#${contentId}`);
        expect(screen.getByRole('main')).toHaveAttribute('id', contentId);
        expect(screen.getByRole('main')).toHaveAttribute('tabindex', '-1');
        expect(screen.getByRole('navigation', { name: 'Footer navigation' })).toBeVisible();
    });

    it('provides a useful empty state without inventing data centres', () => {
        render(<Page dataCentres={[]} />);
        expect(screen.getByText('No data centres with published DOI resources are currently available.')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Explore the Data Portal' })).toHaveAttribute('href', '/doi-search');
        expect(screen.queryByRole('list', { name: 'Data centres' })).not.toBeInTheDocument();
    });
});
