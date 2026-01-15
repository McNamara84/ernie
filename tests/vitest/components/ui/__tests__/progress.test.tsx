import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Progress } from '@/components/ui/progress';

describe('Progress', () => {
    it('renders the progress bar', () => {
        render(<Progress value={50} />);
        const progressBar = screen.getByRole('progressbar');
        expect(progressBar).toBeInTheDocument();
    });

    it('applies custom className', () => {
        render(<Progress value={50} className="custom-class" />);
        const progressBar = screen.getByRole('progressbar');
        expect(progressBar).toHaveClass('custom-class');
    });

    it('handles 0% value', () => {
        render(<Progress value={0} />);
        const progressBar = screen.getByRole('progressbar');
        expect(progressBar).toBeInTheDocument();
    });

    it('handles 100% value', () => {
        render(<Progress value={100} />);
        const progressBar = screen.getByRole('progressbar');
        expect(progressBar).toBeInTheDocument();
    });

    it('handles undefined value', () => {
        render(<Progress />);
        const progressBar = screen.getByRole('progressbar');
        expect(progressBar).toBeInTheDocument();
    });
});
