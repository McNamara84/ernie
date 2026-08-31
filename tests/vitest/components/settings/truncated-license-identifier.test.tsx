import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import {
    abbreviateLicenseIdentifier,
    LICENSE_IDENTIFIER_VISIBLE_LENGTH,
    TruncatedLicenseIdentifier,
} from '@/components/settings/truncated-license-identifier';
import { TooltipProvider } from '@/components/ui/tooltip';

function renderIdentifier(identifier: string) {
    return render(
        <TooltipProvider>
            <TruncatedLicenseIdentifier identifier={identifier} />
        </TooltipProvider>,
    );
}

describe('TruncatedLicenseIdentifier', () => {
    it('leaves identifiers at or below the 50-character boundary unchanged', () => {
        const identifier = 'A'.repeat(LICENSE_IDENTIFIER_VISIBLE_LENGTH);

        expect(abbreviateLicenseIdentifier(identifier)).toBe(identifier);
        renderIdentifier(identifier);

        expect(screen.getByText(identifier)).not.toHaveAttribute('tabindex');
        expect(screen.queryByLabelText(/Full license identifier/)).not.toBeInTheDocument();
    });

    it('shows the first 50 characters plus an ellipsis for longer identifiers', () => {
        const identifier = `${'B'.repeat(LICENSE_IDENTIFIER_VISIBLE_LENGTH)}C`;

        expect(abbreviateLicenseIdentifier(identifier)).toBe(`${'B'.repeat(LICENSE_IDENTIFIER_VISIBLE_LENGTH)}…`);
        renderIdentifier(identifier);

        expect(screen.getByText(`${'B'.repeat(LICENSE_IDENTIFIER_VISIBLE_LENGTH)}…`)).toBeInTheDocument();
    });

    it('exposes the complete identifier on hover and keyboard focus', async () => {
        const user = userEvent.setup();
        const identifier = `CUSTOM-${'LONG-'.repeat(20)}HASH`;
        renderIdentifier(identifier);

        const trigger = screen.getByLabelText(`Full license identifier: ${identifier}`);
        expect(trigger).toHaveAttribute('tabindex', '0');

        await user.hover(trigger);
        expect(await screen.findByRole('tooltip')).toHaveTextContent(identifier);

        await user.unhover(trigger);
        trigger.focus();
        expect(await screen.findByRole('tooltip')).toHaveTextContent(identifier);
    });
});
