import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { describe, expect, it } from 'vitest';

import { PasswordInput } from '@/components/ui/password-input';

describe('PasswordInput', () => {
    const renderPasswordInput = (props: Partial<ComponentProps<typeof PasswordInput>> = {}) => {
        return render(<PasswordInput {...props} />);
    };

    it('renders as a password input by default', () => {
        renderPasswordInput({ placeholder: 'Enter password' });
        const input = screen.getByPlaceholderText('Enter password');
        expect(input).toHaveAttribute('type', 'password');
    });

    it('renders a toggle button', () => {
        renderPasswordInput();
        const button = screen.getByRole('button', { name: /show text/i });
        expect(button).toBeInTheDocument();
    });

    it('toggles password visibility when button is clicked', () => {
        renderPasswordInput({ placeholder: 'Enter password' });
        const input = screen.getByPlaceholderText('Enter password');
        const toggleButton = screen.getByRole('button', { name: /show text/i });

        // Initially password type
        expect(input).toHaveAttribute('type', 'password');

        // Click to show password
        fireEvent.click(toggleButton);
        expect(input).toHaveAttribute('type', 'text');
        expect(screen.getByRole('button', { name: /hide text/i })).toBeInTheDocument();

        // Click again to hide password
        fireEvent.click(toggleButton);
        expect(input).toHaveAttribute('type', 'password');
        expect(screen.getByRole('button', { name: /show text/i })).toBeInTheDocument();
    });

    it('applies custom className', () => {
        renderPasswordInput({ className: 'custom-class', placeholder: 'test' });
        const input = screen.getByPlaceholderText('test');
        expect(input).toHaveClass('custom-class');
    });

    it('forwards all input props', () => {
        renderPasswordInput({
            id: 'test-password',
            name: 'password',
            required: true,
            placeholder: 'test',
            autoComplete: 'current-password',
        });
        const input = screen.getByPlaceholderText('test');
        expect(input).toHaveAttribute('id', 'test-password');
        expect(input).toHaveAttribute('name', 'password');
        expect(input).toBeRequired();
        expect(input).toHaveAttribute('autocomplete', 'current-password');
    });

    it('toggle button is keyboard accessible', () => {
        renderPasswordInput();
        const toggleButton = screen.getByRole('button', { name: /show text/i });
        expect(toggleButton).not.toHaveAttribute('tabindex', '-1');
    });

    it('disables autocomplete when password is visible', () => {
        renderPasswordInput({ placeholder: 'Enter password', autoComplete: 'current-password' });
        const input = screen.getByPlaceholderText('Enter password');
        const toggleButton = screen.getByRole('button', { name: /show text/i });

        // Initially autocomplete is set
        expect(input).toHaveAttribute('autocomplete', 'current-password');

        // Click to show password
        fireEvent.click(toggleButton);
        expect(input).toHaveAttribute('autocomplete', 'off');

        // Click again to hide password
        fireEvent.click(toggleButton);
        expect(input).toHaveAttribute('autocomplete', 'current-password');
    });

    it('accepts custom show/hide labels', () => {
        renderPasswordInput({
            showPasswordLabel: 'Reveal password',
            hidePasswordLabel: 'Conceal password',
        });
        
        const toggleButton = screen.getByRole('button', { name: 'Reveal password' });
        expect(toggleButton).toBeInTheDocument();

        fireEvent.click(toggleButton);
        expect(screen.getByRole('button', { name: 'Conceal password' })).toBeInTheDocument();
    });

    it('can be used with a label', () => {
        render(
            <div>
                <label htmlFor="pwd">Password</label>
                <PasswordInput id="pwd" name="password" />
            </div>
        );
        
        const input = screen.getByLabelText('Password');
        expect(input).toBeInTheDocument();
        expect(input).toHaveAttribute('type', 'password');
    });
});
