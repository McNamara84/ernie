import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '../card';

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
        expect(screen.getByText('Title')).toHaveAttribute('data-slot', 'card-title');
        expect(screen.getByText('Description')).toHaveAttribute('data-slot', 'card-description');
        expect(screen.getByText('Content')).toHaveAttribute('data-slot', 'card-content');
        expect(screen.getByText('Footer')).toHaveAttribute('data-slot', 'card-footer');
    });
});

