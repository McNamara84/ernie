import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { Textarea } from '@/components/ui/textarea';

describe('Textarea', () => {
    it('renders a textarea element', () => {
        render(<Textarea />);

        expect(screen.getByRole('textbox')).toBeInTheDocument();
    });

    it('renders with placeholder text', () => {
        render(<Textarea placeholder="Enter your message" />);

        expect(screen.getByPlaceholderText('Enter your message')).toBeInTheDocument();
    });

    it('applies custom className', () => {
        render(<Textarea className="custom-textarea" data-testid="textarea" />);

        expect(screen.getByTestId('textarea')).toHaveClass('custom-textarea');
    });

    it('handles value changes', async () => {
        const user = userEvent.setup();
        const handleChange = vi.fn();
        render(<Textarea onChange={handleChange} />);

        const textarea = screen.getByRole('textbox');
        await user.type(textarea, 'Hello World');

        expect(handleChange).toHaveBeenCalled();
        expect(textarea).toHaveValue('Hello World');
    });

    it('renders with initial value', () => {
        render(<Textarea defaultValue="Initial content" />);

        expect(screen.getByRole('textbox')).toHaveValue('Initial content');
    });

    it('supports disabled state', () => {
        render(<Textarea disabled />);

        expect(screen.getByRole('textbox')).toBeDisabled();
    });

    it('supports rows attribute', () => {
        render(<Textarea rows={10} data-testid="textarea" />);

        expect(screen.getByTestId('textarea')).toHaveAttribute('rows', '10');
    });

    it('renders with name attribute', () => {
        render(<Textarea name="description" data-testid="textarea" />);

        expect(screen.getByTestId('textarea')).toHaveAttribute('name', 'description');
    });

    it('supports required attribute', () => {
        render(<Textarea required />);

        expect(screen.getByRole('textbox')).toBeRequired();
    });

    it('supports maxLength attribute', () => {
        render(<Textarea maxLength={500} data-testid="textarea" />);

        expect(screen.getByTestId('textarea')).toHaveAttribute('maxLength', '500');
    });

    it('forwards ref correctly', () => {
        const ref = { current: null as HTMLTextAreaElement | null };
        render(<Textarea ref={ref} />);

        expect(ref.current).toBeInstanceOf(HTMLTextAreaElement);
    });
});
