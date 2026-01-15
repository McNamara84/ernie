import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

describe('RadioGroup', () => {
    it('renders radiogroup role', () => {
        render(
            <RadioGroup>
                <RadioGroupItem value="option1" id="option1" />
            </RadioGroup>
        );

        expect(screen.getByRole('radiogroup')).toBeInTheDocument();
    });

    it('renders multiple radio items', () => {
        render(
            <RadioGroup>
                <RadioGroupItem value="a" id="a" />
                <RadioGroupItem value="b" id="b" />
                <RadioGroupItem value="c" id="c" />
            </RadioGroup>
        );

        const radios = screen.getAllByRole('radio');
        expect(radios).toHaveLength(3);
    });

    it('applies custom className to RadioGroup', () => {
        render(
            <RadioGroup className="custom-group" data-testid="radio-group">
                <RadioGroupItem value="test" id="test" />
            </RadioGroup>
        );

        expect(screen.getByTestId('radio-group')).toHaveClass('custom-group');
    });

    it('applies custom className to RadioGroupItem', () => {
        render(
            <RadioGroup>
                <RadioGroupItem value="test" id="test" className="custom-item" />
            </RadioGroup>
        );

        expect(screen.getByRole('radio')).toHaveClass('custom-item');
    });

    it('marks the item with defaultValue as checked', () => {
        render(
            <RadioGroup defaultValue="b">
                <RadioGroupItem value="a" id="a" />
                <RadioGroupItem value="b" id="b" />
            </RadioGroup>
        );

        const radios = screen.getAllByRole('radio');
        expect(radios[0]).not.toBeChecked();
        expect(radios[1]).toBeChecked();
    });

    it('disables items when disabled prop is set', () => {
        render(
            <RadioGroup disabled>
                <RadioGroupItem value="disabled" id="disabled" />
            </RadioGroup>
        );

        expect(screen.getByRole('radio')).toBeDisabled();
    });

    it('supports horizontal orientation', () => {
        render(
            <RadioGroup orientation="horizontal" data-testid="radio-group">
                <RadioGroupItem value="x" id="x" />
                <RadioGroupItem value="y" id="y" />
            </RadioGroup>
        );

        expect(screen.getByTestId('radio-group')).toHaveAttribute('aria-orientation', 'horizontal');
    });
});
