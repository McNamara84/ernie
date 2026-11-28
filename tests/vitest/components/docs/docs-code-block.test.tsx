import { act, fireEvent, render as rawRender, screen, waitFor } from '@testing-library/react';
import { settleRender } from '@tests/vitest/helpers/settled-render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DocsCodeBlock } from '@/components/docs/docs-code-block';

const render = settleRender(rawRender);

describe('DocsCodeBlock', () => {
    beforeEach(() => {
        // Mock clipboard API
        Object.assign(navigator, {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });
    });

    it('renders the code content', async () => {
        await render(<DocsCodeBlock code="npm install package" />);

        expect(screen.getByText('npm install package')).toBeInTheDocument();
    });

    it('renders with default bash language', async () => {
        const { container } = await render(<DocsCodeBlock code="echo hello" />);

        const codeElement = container.querySelector('code');
        expect(codeElement).toHaveClass('language-bash');
    });

    it('renders with specified language', async () => {
        const { container } = await render(<DocsCodeBlock code="console.log('hi')" language="javascript" />);

        const codeElement = container.querySelector('code');
        expect(codeElement).toHaveClass('language-javascript');
    });

    it('shows copy button on hover', async () => {
        await render(<DocsCodeBlock code="some code" />);

        const copyButton = screen.getByRole('button', { name: 'Copy code' });
        expect(copyButton).toBeInTheDocument();
    });

    it('copies code to clipboard when button is clicked', async () => {
        await render(<DocsCodeBlock code="npm run build" />);

        const copyButton = screen.getByRole('button', { name: 'Copy code' });
        await act(async () => {
            fireEvent.click(copyButton);
        });

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('npm run build');
    });

    it('shows "Copied" state after clicking', async () => {
        await render(<DocsCodeBlock code="test code" />);

        const copyButton = screen.getByRole('button', { name: 'Copy code' });
        await act(async () => {
            fireEvent.click(copyButton);
        });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
        });
    });

    it('applies custom className', async () => {
        const { container } = await render(<DocsCodeBlock code="code" className="my-custom-class" />);

        const wrapper = container.firstChild;
        expect(wrapper).toHaveClass('my-custom-class');
    });

    it('renders in a pre element', async () => {
        const { container } = await render(<DocsCodeBlock code="multiline\ncode" />);

        const preElement = container.querySelector('pre');
        expect(preElement).toBeInTheDocument();
    });

    it('constrains long code lines to the available content width', async () => {
        const { container } = await render(<DocsCodeBlock code={'x'.repeat(300)} />);

        expect(container.firstChild).toHaveClass('min-w-0', 'max-w-full');
        expect(container.querySelector('pre')).toHaveClass('max-w-full', 'overflow-x-auto');
    });
});
