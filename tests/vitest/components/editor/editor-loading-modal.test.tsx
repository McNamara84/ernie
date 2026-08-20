import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { EditorLoadingModal } from '@/components/editor/editor-loading-modal';

describe('EditorLoadingModal', () => {
    it('renders accessible real progress and the current message', () => {
        render(<EditorLoadingModal progress={42.4} message="Loading actual metadata" />);

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByRole('progressbar', { name: /data editor loading progress/i })).toHaveAttribute('aria-valuenow', '42');
        expect(screen.getByTestId('editor-loading-percentage')).toHaveTextContent('42%');
        expect(screen.getByRole('status')).toHaveTextContent('Loading actual metadata');
        expect(screen.queryByRole('button', { name: /close/i })).not.toBeInTheDocument();
    });

    it('cannot be dismissed with Escape while loading', () => {
        render(<EditorLoadingModal progress={10} message="Still loading" />);

        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('shows retry and back actions after a failure', async () => {
        const onRetry = vi.fn();
        const onGoBack = vi.fn();
        render(
            <EditorLoadingModal
                progress={55}
                message="Ignored for errors"
                error="The resource could not be transformed."
                onRetry={onRetry}
                onGoBack={onGoBack}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /try again/i }));
        fireEvent.click(screen.getByRole('button', { name: /go back/i }));

        expect(onRetry).toHaveBeenCalledOnce();
        expect(onGoBack).toHaveBeenCalledOnce();
        expect(screen.getByRole('alert')).toHaveTextContent('The resource could not be transformed.');
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });
});
