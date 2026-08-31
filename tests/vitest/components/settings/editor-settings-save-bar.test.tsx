import '@testing-library/jest-dom/vitest';

import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { EditorSettingsSaveBar } from '@/components/settings/editor-settings-save-bar';

describe('EditorSettingsSaveBar', () => {
    it('shows a disabled clean state and explains immediate saves', () => {
        render(<EditorSettingsSaveBar isDirty={false} processing={false} recentlySuccessful={false} />);

        expect(screen.getByRole('heading', { name: 'Editor Settings', level: 1 })).toBeInTheDocument();
        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('No unsaved changes');
        expect(screen.getByText('Domains and datacenters save immediately.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    });

    it('enables saving and announces unsaved changes when dirty', () => {
        render(<EditorSettingsSaveBar isDirty processing={false} recentlySuccessful={false} />);

        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('Unsaved changes');
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled();
    });

    it('locks the action and reports progress while processing', () => {
        render(<EditorSettingsSaveBar isDirty processing recentlySuccessful={false} />);

        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('Saving changes…');
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Saving…' })).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByRole('button', { name: 'Saving…' }).querySelector('[data-slot="spinner"]')).toBeInTheDocument();
    });

    it('announces a recently successful save', () => {
        render(<EditorSettingsSaveBar isDirty={false} processing={false} recentlySuccessful />);

        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('Changes saved');
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    });

    it('prioritizes a new unsaved edit over the short-lived success message', () => {
        render(<EditorSettingsSaveBar isDirty processing={false} recentlySuccessful />);

        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('Unsaved changes');
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled();
    });
});
