import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import {
    Breadcrumb,
    BreadcrumbList,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbSeparator,
    BreadcrumbPage,
    BreadcrumbEllipsis,
} from '@/components/ui/breadcrumb';

describe('Breadcrumb', () => {
    it('renders navigation with items and separator', () => {
        const { container } = render(
            <Breadcrumb>
                <BreadcrumbList>
                    <BreadcrumbItem>
                        <BreadcrumbLink href="/">Home</BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbPage>Profile</BreadcrumbPage>
                    </BreadcrumbItem>
                </BreadcrumbList>
            </Breadcrumb>,
        );

        const nav = screen.getByRole('navigation');
        expect(nav).toHaveAttribute('data-slot', 'breadcrumb');
        expect(screen.getByText('Home')).toHaveAttribute('data-slot', 'breadcrumb-link');
        expect(screen.getByText('Profile')).toHaveAttribute('data-slot', 'breadcrumb-page');
        const separator = container.querySelector('[data-slot="breadcrumb-separator"]');
        expect(separator).toBeInTheDocument();
    });

    it('renders ellipsis with accessible text', () => {
        render(<BreadcrumbEllipsis />);
        expect(screen.getByText('More')).toBeInTheDocument();
    });
});

