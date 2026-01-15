import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach,describe, expect, it, vi } from 'vitest';

import { DocsCodeBlock } from '@/components/docs/docs-code-block';

describe('DocsCodeBlock', () => {
    beforeEach(() => {
        // Mock clipboard API
        Object.assign(navigator, {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });
    });

    it('renders the code content', () => {
        render(<DocsCodeBlock code="npm install package" />);

        expect(screen.getByText('npm install package')).toBeInTheDocument();
    });

    it('renders with default bash language', () => {
        const { container } = render(<DocsCodeBlock code="echo hello" />);

        const codeElement = container.querySelector('code');
        expect(codeElement).toHaveClass('language-bash');
    });

    it('renders with specified language', () => {
        const { container } = render(<DocsCodeBlock code="console.log('hi')" language="javascript" />);

        const codeElement = container.querySelector('code');
        expect(codeElement).toHaveClass('language-javascript');
    });

    it('shows copy button on hover', () => {
        render(<DocsCodeBlock code="some code" />);

        const copyButton = screen.getByRole('button', { name: 'Copy code' });
        expect(copyButton).toBeInTheDocument();
    });

    it('copies code to clipboard when button is clicked', async () => {
        render(<DocsCodeBlock code="npm run build" />);

        const copyButton = screen.getByRole('button', { name: 'Copy code' });
        fireEvent.click(copyButton);

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('npm run build');
    });

    it('shows "Copied" state after clicking', async () => {
        render(<DocsCodeBlock code="test code" />);

        const copyButton = screen.getByRole('button', { name: 'Copy code' });
        fireEvent.click(copyButton);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
        });
    });

    it('applies custom className', () => {
        const { container } = render(<DocsCodeBlock code="code" className="my-custom-class" />);

        const wrapper = container.firstChild;
        expect(wrapper).toHaveClass('my-custom-class');
    });

    it('renders in a pre element', () => {
        const { container } = render(<DocsCodeBlock code="multiline\ncode" />);

        const preElement = container.querySelector('pre');
        expect(preElement).toBeInTheDocument();
    });
});
