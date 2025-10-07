import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Alert, AlertDescription,AlertTitle } from '@/components/ui/alert';

const ALERT_TEXT = 'Something happened';

describe('Alert', () => {
    it('renders default variant with title and description', () => {
        render(
            <Alert>
                <AlertTitle>Warning</AlertTitle>
                <AlertDescription>{ALERT_TEXT}</AlertDescription>
            </Alert>,
        );
        const alert = screen.getByRole('alert');
        expect(alert).toHaveAttribute('data-slot', 'alert');
        expect(alert).not.toHaveClass('text-destructive-foreground');
        expect(screen.getByText('Warning')).toHaveAttribute('data-slot', 'alert-title');
        expect(screen.getByText(ALERT_TEXT)).toHaveAttribute('data-slot', 'alert-description');
    });

    it('applies destructive variant styles', () => {
        render(<Alert variant="destructive">{ALERT_TEXT}</Alert>);
        const alert = screen.getByRole('alert');
        expect(alert).toHaveClass('text-destructive-foreground');
    });
});

