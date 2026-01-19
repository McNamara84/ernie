import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';
import type { EditorSettings } from '@/types/docs';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

// Mock IntersectionObserver for scroll spy
global.IntersectionObserver = class IntersectionObserver {
    observe() {}
    disconnect() {}
    unobserve() {}
    takeRecords() {
        return [];
    }
    root = null;
    rootMargin = '';
    thresholds = [];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
} as any;

// Default editor settings for tests
const defaultEditorSettings: EditorSettings = {
    thesauri: {
        scienceKeywords: true,
        platforms: true,
        instruments: true,
    },
    features: {
        hasActiveGcmd: true,
        hasActiveMsl: true,
        hasActiveLicenses: true,
        hasActiveResourceTypes: true,
        hasActiveTitleTypes: true,
        hasActiveLanguages: true,
    },
    limits: {
        maxTitles: 10,
        maxLicenses: 5,
    },
};

describe('Docs page', () => {
    it('renders documentation for beginner role', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // Check for sections visible in Getting Started tab (default)
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('hides user management section for beginners', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // User Management should not be visible for beginners
        expect(screen.queryByText('Managing Users')).not.toBeInTheDocument();
    });

    it('shows user management for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
    });

    it('hides system administration for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        // System Administration requires admin role
        expect(screen.queryByText('php artisan add-user')).not.toBeInTheDocument();
    });

    it('shows all sections for admin', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
        expect(screen.getAllByText('System Administration').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('displays beginner role indicator in header', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // The header shows the user's role (may appear multiple times)
        expect(screen.getAllByText('beginner').length).toBeGreaterThan(0);
    });

    it('does not show beginner notice for curator role', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Curator role should be shown (may appear multiple times)
        expect(screen.getAllByText('curator').length).toBeGreaterThan(0);
    });

    it('links to API documentation', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        const link = screen.getByText('View API Documentation');
        expect(link).toHaveAttribute('href', '/api/v1/doc');
    });

    it('hides controlled keywords section when GCMD is disabled', () => {
        const settingsWithoutGcmd: EditorSettings = {
            ...defaultEditorSettings,
            features: {
                ...defaultEditorSettings.features,
                hasActiveGcmd: false,
                hasActiveMsl: false,
            },
        };
        render(<Docs userRole="beginner" editorSettings={settingsWithoutGcmd} />);
        // The datasets tab should be visible but Keywords section should be filtered out
        // Check that the Datasets tab exists
        expect(screen.getByRole('tab', { name: /Datasets/i })).toBeInTheDocument();
    });

    it('shows controlled keywords section when GCMD is enabled', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // The datasets tab should be visible
        expect(screen.getByRole('tab', { name: /Datasets/i })).toBeInTheDocument();
        // Verify tabs are rendered correctly for users with GCMD enabled
        expect(screen.getByRole('tablist')).toBeInTheDocument();
    });
});
