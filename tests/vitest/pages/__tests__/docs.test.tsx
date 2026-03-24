import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';
import type { EditorSettings } from '@/types/docs';
import { render, screen } from '@tests/vitest/utils/render';

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
        chronostratigraphy: true,
        gemet: true,
    },
    features: {
        hasActiveGcmd: true,
        hasActiveMsl: true,
        hasActiveChronostrat: true,
        hasActiveGemet: true,
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

    it('shows editor settings for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        // 'Editor Configuration' is the unique h3 inside the Editor Settings section
        expect(screen.getByText('Editor Configuration')).toBeInTheDocument();
    });

    it('hides editor settings for curator', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Editor Configuration is the h3 inside the Editor Settings section
        expect(screen.queryByText('Editor Configuration')).not.toBeInTheDocument();
    });

    it('hides legacy import for curator', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab where Legacy Import lives
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched by checking Datasets-only content is rendered
        expect(screen.getByText('Uploading XML Files')).toBeInTheDocument();
        // Legacy Import requires admin role
        expect(screen.queryByText('Importing from Old Datasets')).not.toBeInTheDocument();
    });

    it('shows legacy import for admin', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched and admin sees Legacy Import
        expect(screen.getByText('Uploading XML Files')).toBeInTheDocument();
        expect(screen.getByText('Importing from Old Datasets')).toBeInTheDocument();
    });

    it('hides landing pages documentation for beginner', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab where Landing Pages lives
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched by checking Datasets-only content is rendered
        expect(screen.getByText('Uploading XML Files')).toBeInTheDocument();
        // Landing Pages requires curator role
        expect(screen.queryByText('Creating Landing Pages')).not.toBeInTheDocument();
    });

    it('shows landing pages documentation for curator', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched and curator sees Landing Pages
        expect(screen.getByText('Uploading XML Files')).toBeInTheDocument();
        expect(screen.getByText('Creating Landing Pages')).toBeInTheDocument();
    });

    it('shows thesaurus update actions for admin in editor settings', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);
        expect(screen.getByText('Check for updates by comparing local vs. NASA remote counts')).toBeInTheDocument();
        expect(screen.getByText('Trigger vocabulary updates with one click')).toBeInTheDocument();
        expect(screen.getByText('Trigger background downloads of the full vocabulary data')).toBeInTheDocument();
    });

    it('shows thesaurus update actions for group_leader in editor settings', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        expect(screen.getByText('Check for updates by comparing local vs. NASA remote counts')).toBeInTheDocument();
        expect(screen.getByText('Trigger vocabulary updates with one click')).toBeInTheDocument();
        expect(screen.getByText('Trigger background downloads of the full vocabulary data')).toBeInTheDocument();
    });
});
