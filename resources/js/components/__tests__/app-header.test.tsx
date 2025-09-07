import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppHeader } from '../app-header';

const usePageMock = vi.fn();
const getInitialsMock = vi.fn(() => 'JD');

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    usePage: () => usePageMock(),
}));

vi.mock('@/components/breadcrumbs', () => ({
    Breadcrumbs: ({ breadcrumbs }: { breadcrumbs: Array<any> }) => (
        <nav data-testid="breadcrumbs">{breadcrumbs.length}</nav>
    ),
}));
vi.mock('@/components/icon', () => ({ Icon: () => <span data-testid="icon" /> }));
vi.mock('@/components/ui/avatar', () => ({
    Avatar: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    AvatarImage: (props: any) => <img data-testid="avatar-image" {...props} />,
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
vi.mock('../app-logo', () => ({
    default: () => <div>Logo</div>,
}));
vi.mock('../app-logo-icon', () => ({
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
}));
vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
}));

describe('AppHeader', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({
            props: { auth: { user: { name: 'John Doe', avatar: '/avatar.png' } } },
            url: '/dashboard',
        });
    });

    it('renders navigation links, breadcrumbs and user initials', () => {
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
            .forEach((link) => expect(link).toHaveAttribute('href', '/dashboard'));
        screen
            .getAllByRole('link', { name: /documentation/i })
            .forEach((link) => expect(link).toHaveAttribute('href', '/docs'));
        expect(getInitialsMock).toHaveBeenCalledWith('John Doe');
        expect(screen.getByText('JD')).toBeInTheDocument();
        expect(screen.getByTestId('breadcrumbs')).toBeInTheDocument();
    });
});
