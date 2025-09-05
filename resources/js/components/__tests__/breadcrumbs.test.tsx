import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { Breadcrumbs } from '../breadcrumbs';
import { describe, expect, it } from 'vitest';

describe('Breadcrumbs', () => {
    it('renders nothing when no breadcrumbs provided', () => {
        const { container } = render(<Breadcrumbs breadcrumbs={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('renders links for all but the last breadcrumb', () => {
        const { container } = render(
            <Breadcrumbs
                breadcrumbs={[
                    { title: 'Home', href: '/' },
                    { title: 'Settings', href: '/settings' },
                ]}
            />,
        );

        const anchors = container.querySelectorAll('a');
        expect(anchors).toHaveLength(1);
        expect(anchors[0]).toHaveAttribute('href', '/');

        const last = screen.getByText('Settings');
        expect(last.tagName.toLowerCase()).toBe('span');
        expect(last).toHaveAttribute('aria-current', 'page');
    });
});
