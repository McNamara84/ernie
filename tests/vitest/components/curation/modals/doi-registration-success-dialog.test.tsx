import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, render, screen } from '@tests/vitest/utils/render';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DoiRegistrationSuccessDialog } from '@/components/curation/modals/doi-registration-success-dialog';

describe('DoiRegistrationSuccessDialog', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('shows the published Resource + IGSN total and continues automatically', () => {
        vi.useFakeTimers();
        const onContinue = vi.fn();
        const localeStringSpy = vi.spyOn(Number.prototype, 'toLocaleString');

        render(
            <DoiRegistrationSuccessDialog open doi="10.83279/example" counts={{ resources: 1200, igsns: 34, total: 1234 }} onContinue={onContinue} />,
        );

        expect(screen.getByText('Canonically published records in ERNIE')).toBeInTheDocument();
        expect(screen.getByTestId('published-record-total')).toHaveTextContent('1,234');
        expect(screen.getByText('1,200 Resources + 34 IGSNs')).toBeInTheDocument();
        expect(localeStringSpy.mock.calls).toEqual([['en-US'], ['en-US'], ['en-US']]);
        expect(screen.getByTestId('doi-success-confetti')).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(4_999));
        expect(onContinue).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(1));
        expect(onContinue).toHaveBeenCalledOnce();
    });

    it('lets the user continue immediately and only invokes the callback once', async () => {
        const user = userEvent.setup();
        const onContinue = vi.fn();

        render(<DoiRegistrationSuccessDialog open doi="10.83279/example" counts={{ resources: 7, igsns: 2, total: 9 }} onContinue={onContinue} />);

        await user.click(screen.getByRole('button', { name: 'Continue to Resources' }));
        expect(onContinue).toHaveBeenCalledOnce();
    });

    it('suppresses confetti when reduced motion is requested', () => {
        vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: true } as MediaQueryList);

        render(<DoiRegistrationSuccessDialog open doi="10.83279/example" counts={{ resources: 1, igsns: 1, total: 2 }} onContinue={vi.fn()} />);

        expect(screen.queryByTestId('doi-success-confetti')).not.toBeInTheDocument();
    });
});
