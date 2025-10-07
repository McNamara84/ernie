import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Card, CardContent, CardDescription, CardFooter,CardHeader, CardTitle } from '@/components/ui/card';

describe('Card', () => {
    it('renders card structure with slots', () => {
        const { container } = render(
            <Card>
                <CardHeader>
                    <CardTitle>Title</CardTitle>
                    <CardDescription>Description</CardDescription>
                </CardHeader>
                <CardContent>Content</CardContent>
                <CardFooter>Footer</CardFooter>
            </Card>,
        );

        const card = container.querySelector('[data-slot="card"]');
        expect(card).toBeInTheDocument();
        const cardTitle = screen.getByText('Title');
        expect(cardTitle).toHaveAttribute('data-slot', 'card-title');
        expect(cardTitle.tagName).toBe('H3');
        expect(screen.getByText('Description')).toHaveAttribute('data-slot', 'card-description');
        expect(screen.getByText('Content')).toHaveAttribute('data-slot', 'card-content');
        expect(screen.getByText('Footer')).toHaveAttribute('data-slot', 'card-footer');
    });

    it('allows rendering the title as a custom heading level for accessibility', () => {
        render(
            <Card>
                <CardHeader>
                    <CardTitle asChild>
                        <h2>Accessible Title</h2>
                    </CardTitle>
                </CardHeader>
            </Card>,
        );

        const heading = screen.getByRole('heading', { name: 'Accessible Title', level: 2 });
        expect(heading).toHaveAttribute('data-slot', 'card-title');
    });
});

