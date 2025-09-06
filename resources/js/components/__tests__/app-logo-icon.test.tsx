import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import AppLogoIcon from '../app-logo-icon';
import { describe, expect, it } from 'vitest';

describe('AppLogoIcon', () => {
    it('renders image with default attributes', () => {
        render(<AppLogoIcon />);
        const img = screen.getByAltText('App logo');
        expect(img).toHaveAttribute('src', '/favicon.svg');
        expect(img).toHaveClass('dark:invert');
    });

    it('merges custom class names', () => {
        render(<AppLogoIcon className="custom" />);
        const img = screen.getByAltText('App logo');
        expect(img).toHaveClass('dark:invert');
        expect(img).toHaveClass('custom');
    });
});
