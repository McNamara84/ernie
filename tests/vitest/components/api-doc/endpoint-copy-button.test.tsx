import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { SVGProps } from 'react';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { EndpointCopyButton, endpointCopyFeedbackPlugin } from '@/components/api-doc/endpoint-copy-button';

vi.mock('sonner', () => ({
    toast: {
        error: vi.fn(),
        success: vi.fn(),
    },
}));

const originalClipboardDescriptor = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
const CopyIcon = (props: SVGProps<SVGSVGElement>) => <svg data-testid="swagger-copy-icon" {...props} />;
const getComponent = vi.fn(() => CopyIcon);

function setClipboard(writeText: ReturnType<typeof vi.fn>) {
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText },
    });
}

function renderEndpointCopyButton(textToCopy: string) {
    return render(<EndpointCopyButton getComponent={getComponent} textToCopy={textToCopy} />);
}

describe('EndpointCopyButton', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        if (originalClipboardDescriptor) {
            Object.defineProperty(navigator, 'clipboard', originalClipboardDescriptor);
        } else {
            Reflect.deleteProperty(navigator, 'clipboard');
        }
    });

    it('renders an accessible button with Swagger UI style hooks and its visible copy icon', () => {
        renderEndpointCopyButton('/api/v1/licenses');

        const button = screen.getByRole('button', { name: 'Copy endpoint path to clipboard' });

        expect(button).toHaveAttribute('type', 'button');
        expect(button).toContainElement(screen.getByTestId('swagger-copy-icon'));
        expect(screen.getByTestId('swagger-copy-icon')).toHaveAttribute('aria-hidden', 'true');
        expect(screen.getByTestId('swagger-copy-icon')).toHaveAttribute('focusable', 'false');
        expect(button.parentElement).toHaveClass('view-line-link', 'copy-to-clipboard');
        expect(button.parentElement).toHaveAttribute('title', 'Copy to clipboard');
        expect(getComponent).toHaveBeenCalledWith('CopyIcon');
    });

    it('copies the exact endpoint path and reports success after the write resolves', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        setClipboard(writeText);

        renderEndpointCopyButton('/api/v1/resource-types/{type}');
        fireEvent.click(screen.getByRole('button', { name: 'Copy endpoint path to clipboard' }));

        await waitFor(() => {
            expect(writeText).toHaveBeenCalledWith('/api/v1/resource-types/{type}');
            expect(toast.success).toHaveBeenCalledWith('Copied to clipboard');
        });
        expect(toast.error).not.toHaveBeenCalled();
    });

    it('reports a failed clipboard write without showing false success', async () => {
        const writeText = vi.fn().mockRejectedValue(new Error('Clipboard permission denied'));
        setClipboard(writeText);

        renderEndpointCopyButton('/api/v1/licenses');
        fireEvent.click(screen.getByRole('button', { name: 'Copy endpoint path to clipboard' }));

        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('Could not copy endpoint to clipboard');
        });
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('reports an unavailable Clipboard API without throwing or showing success', async () => {
        Reflect.deleteProperty(navigator, 'clipboard');

        renderEndpointCopyButton('/api/v1/languages');
        fireEvent.click(screen.getByRole('button', { name: 'Copy endpoint path to clipboard' }));

        expect(toast.error).toHaveBeenCalledWith('Could not copy endpoint to clipboard');
        expect(toast.success).not.toHaveBeenCalled();
    });

    it("only replaces Swagger UI's endpoint copy component plug point", () => {
        const plugin = endpointCopyFeedbackPlugin();

        expect(Object.keys(plugin.wrapComponents)).toEqual(['CopyToClipboardBtn']);
        expect(plugin.wrapComponents.CopyToClipboardBtn()).toBe(EndpointCopyButton);
    });
});
