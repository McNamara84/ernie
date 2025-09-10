import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuthSplitLayout from '../auth-split-layout';

const page: { props: { name: string; quote?: { message: string; author: string } } } = {
    props: { name: 'Ernie' },
};

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => page,
}));

vi.mock('@/routes', () => ({
    home: () => '/',
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

describe('AuthSplitLayout', () => {
    beforeEach(() => {
        page.props = { name: 'Ernie' };
    });

    it('renders quote when provided', () => {
        page.props.quote = { message: 'Be yourself', author: 'You' };
        render(
            <AuthSplitLayout title="Hello" description="World">
                <div>Child</div>
            </AuthSplitLayout>,
        );
        expect(screen.getByText(/be yourself/i)).toBeInTheDocument();
        expect(screen.getByText(/^You$/i)).toBeInTheDocument();
    });

    it('does not render quote when missing', () => {
        render(
            <AuthSplitLayout title="Hello" description="World">
                <div>Child</div>
            </AuthSplitLayout>,
        );
        expect(screen.queryByText(/be yourself/i)).toBeNull();
    });
});

