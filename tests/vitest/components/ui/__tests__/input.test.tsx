import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Input } from '@/components/ui/input';

describe('Input', () => {
    it('forwards props and className', () => {
        render(<Input type="email" className="custom" placeholder="Email" />);
        const input = screen.getByPlaceholderText('Email');
        expect(input).toHaveAttribute('type', 'email');
        expect(input).toHaveAttribute('data-slot', 'input');
        expect(input).toHaveClass('custom');
    });
});
