import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

describe('Tabs', () => {
    it('renders the tabs with all components', () => {
        render(
            <Tabs defaultValue="tab1">
                <TabsList>
                    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
                    <TabsTrigger value="tab2">Tab 2</TabsTrigger>
                </TabsList>
                <TabsContent value="tab1">Content 1</TabsContent>
                <TabsContent value="tab2">Content 2</TabsContent>
            </Tabs>,
        );

        expect(screen.getByRole('tablist')).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Tab 1' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Tab 2' })).toBeInTheDocument();
    });

    it('shows the correct content for the default tab', () => {
        render(
            <Tabs defaultValue="tab1">
                <TabsList>
                    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
                    <TabsTrigger value="tab2">Tab 2</TabsTrigger>
                </TabsList>
                <TabsContent value="tab1">Content 1</TabsContent>
                <TabsContent value="tab2">Content 2</TabsContent>
            </Tabs>,
        );

        expect(screen.getByText('Content 1')).toBeInTheDocument();
    });

    it('has the correct initial active tab state', () => {
        render(
            <Tabs defaultValue="tab1">
                <TabsList>
                    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
                    <TabsTrigger value="tab2">Tab 2</TabsTrigger>
                </TabsList>
                <TabsContent value="tab1">Content 1</TabsContent>
                <TabsContent value="tab2">Content 2</TabsContent>
            </Tabs>,
        );

        expect(screen.getByRole('tab', { name: 'Tab 1' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Tab 2' })).toHaveAttribute('aria-selected', 'false');
    });

    it('applies custom className to TabsList', () => {
        render(
            <Tabs defaultValue="tab1">
                <TabsList className="custom-list-class">
                    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
                </TabsList>
                <TabsContent value="tab1">Content</TabsContent>
            </Tabs>,
        );

        expect(screen.getByRole('tablist')).toHaveClass('custom-list-class');
    });

    it('applies custom className to TabsTrigger', () => {
        render(
            <Tabs defaultValue="tab1">
                <TabsList>
                    <TabsTrigger value="tab1" className="custom-trigger-class">
                        Tab 1
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="tab1">Content</TabsContent>
            </Tabs>,
        );

        expect(screen.getByRole('tab', { name: 'Tab 1' })).toHaveClass('custom-trigger-class');
    });

    it('applies custom className to TabsContent', () => {
        const { container } = render(
            <Tabs defaultValue="tab1">
                <TabsList>
                    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
                </TabsList>
                <TabsContent value="tab1" className="custom-content-class">
                    Content
                </TabsContent>
            </Tabs>,
        );

        const contentPanel = container.querySelector('[role="tabpanel"]');
        expect(contentPanel).toHaveClass('custom-content-class');
    });

    it('marks the active tab correctly', () => {
        render(
            <Tabs defaultValue="tab1">
                <TabsList>
                    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
                    <TabsTrigger value="tab2">Tab 2</TabsTrigger>
                </TabsList>
                <TabsContent value="tab1">Content 1</TabsContent>
                <TabsContent value="tab2">Content 2</TabsContent>
            </Tabs>,
        );

        expect(screen.getByRole('tab', { name: 'Tab 1' })).toHaveAttribute('data-state', 'active');
        expect(screen.getByRole('tab', { name: 'Tab 2' })).toHaveAttribute('data-state', 'inactive');
    });
});
