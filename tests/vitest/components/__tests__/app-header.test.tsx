import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import { normalizeTestUrl } from '@tests/vitest/utils/test-utils';
import type { ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { BreadcrumbItem } from '@/types';

const usePageMock = vi.fn();
const getInitialsMock = vi.fn(() => 'JD');

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    usePage: () => usePageMock(),
}));

vi.mock('@/components/breadcrumbs', () => ({
    Breadcrumbs: ({ breadcrumbs }: { breadcrumbs: Array<BreadcrumbItem> }) => (
        <nav data-testid="breadcrumbs">{breadcrumbs.length}</nav>
    ),
}));
vi.mock('@/components/icon', () => ({ Icon: () => <span data-testid="icon" /> }));
vi.mock('@/components/ui/avatar', () => ({
    Avatar: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    AvatarImage: (props: ComponentProps<'img'>) => (
        <img data-testid="avatar-image" {...props} />
    ),
    AvatarFallback: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="avatar-fallback">{children}</div>
    ),
}));
vi.mock('@/components/ui/button', () => ({
    Button: ({ children, className }: { children?: React.ReactNode; className?: string }) => (
        <button className={className}>{children}</button>
    ),
}));
vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    DropdownMenuTrigger: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    DropdownMenuContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/components/ui/navigation-menu', () => ({
    NavigationMenu: ({ children }: { children?: React.ReactNode }) => <nav>{children}</nav>,
    NavigationMenuItem: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    NavigationMenuList: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    navigationMenuTriggerStyle: () => '',
}));
vi.mock('@/components/ui/sheet', () => ({
    Sheet: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SheetContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SheetHeader: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SheetTitle: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SheetTrigger: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/components/ui/tooltip', () => ({
    TooltipProvider: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    Tooltip: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    TooltipTrigger: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    TooltipContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/components/user-menu-content', () => ({
    UserMenuContent: () => <div data-testid="user-menu-content" />,
}));
vi.mock('@/components/app-logo', () => ({
    default: () => <div>Logo</div>,
}));
vi.mock('@/components/app-logo-icon', () => ({
    default: () => <div>LogoIcon</div>,
}));
vi.mock('@/hooks/use-initials', () => ({
    useInitials: () => getInitialsMock,
}));
vi.mock('lucide-react', () => ({
    BookOpen: () => <svg />,
    LayoutGrid: () => <svg />,
    Menu: () => <svg />,
    Search: () => <svg />,
    Database: () => <svg />,
    History: () => <svg />,
    Settings: () => <svg />,
    FileText: () => <svg />,
}));
describe('AppHeader', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({
            props: { auth: { user: { name: 'John Doe', avatar: '/avatar.png' } } },
            url: '/dashboard',
        });
    });

    it('renders navigation links, breadcrumbs and user initials', async () => {
        const { AppHeader } = await import('@/components/app-header');

        render(
            <AppHeader
                breadcrumbs={[
                    { title: 'Home', href: '/' },
                    { title: 'Dashboard', href: '/dashboard' },
                ]}
            />
        );
        screen
            .getAllByRole('link', { name: /dashboard/i })
            .forEach((link) => expect(normalizeTestUrl(link.getAttribute('href') || '')).toBe('/dashboard'));
        const changelogLinks = screen.getAllByRole('link', { name: /changelog/i });
        changelogLinks.forEach((link) => expect(normalizeTestUrl(link.getAttribute('href') || '')).toBe('/changelog'));
        const docLinks = screen.getAllByRole('link', { name: /^documentation$/i });
        docLinks.forEach((link) => expect(normalizeTestUrl(link.getAttribute('href') || '')).toBe('/docs'));
        const apiDocLinks = screen.getAllByRole('link', { name: /api documentation/i });
        apiDocLinks.forEach((link) => expect(link).toHaveAttribute('href', '/api/v1/doc'));
        const editorLinks = screen.getAllByRole('link', { name: /^editor$/i });
        editorLinks.forEach((link) => expect(link).toHaveAttribute('href', '/editor'));
        const settingsLinks = screen.getAllByRole('link', { name: /editor settings/i });
        settingsLinks.forEach((link) => expect(link).toHaveAttribute('href', '/settings'));
        const navs = screen.getAllByRole('navigation');
        expect(
            within(navs[0]).queryByRole('link', { name: /editor settings/i })
        ).not.toBeInTheDocument();
        const changelogTexts = screen.getAllByText(/changelog/i);
        const editorTexts = screen.getAllByText(/editor settings/i);
        expect(
            editorTexts[1].compareDocumentPosition(changelogTexts[1]) &
                Node.DOCUMENT_POSITION_FOLLOWING
        ).toBeTruthy();
        expect(getInitialsMock).toHaveBeenCalledWith('John Doe');
        expect(screen.getByText('JD')).toBeInTheDocument();
        expect(screen.getByTestId('breadcrumbs')).toBeInTheDocument();
    });
});
