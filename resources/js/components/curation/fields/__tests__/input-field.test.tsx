import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { InputField } from '../input-field';

describe('InputField', () => {
    it('renders label and input', () => {
        render(<InputField id="test" label="Test" type="number" />);
        const input = screen.getByLabelText('Test');
        expect(input).toHaveAttribute('id', 'test');
        expect(input).toHaveAttribute('type', 'number');
    });
});
