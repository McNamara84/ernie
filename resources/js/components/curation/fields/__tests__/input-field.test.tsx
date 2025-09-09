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

    it('applies custom className', () => {
        render(<InputField id="cls" label="Class" className="col-span-2" />);
        const wrapper = screen.getByLabelText('Class').parentElement;
        expect(wrapper).toHaveClass('col-span-2');
    });
});
