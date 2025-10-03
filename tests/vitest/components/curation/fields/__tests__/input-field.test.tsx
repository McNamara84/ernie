import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { InputField } from '@/components/curation/fields/input-field';

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

    it('renders required indicator', () => {
        render(<InputField id="req" label="Required" required />);
        const input = screen.getByLabelText('Required', { exact: false });
        expect(input).toBeRequired();
        expect(screen.getByText('Required')).toHaveTextContent('*');
    });
});
