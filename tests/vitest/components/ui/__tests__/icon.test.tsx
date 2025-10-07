import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { XIcon } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { Icon } from '@/components/ui/icon';

describe('UI Icon', () => {
    it('renders nothing when no iconNode is provided', () => {
        const { container } = render(<Icon />);
        expect(container.firstChild).toBeNull();
    });

    it('renders provided icon component', () => {
        const { container } = render(<Icon iconNode={XIcon} className="w-4" />);
        const svg = container.querySelector('svg');
        expect(svg).toBeInTheDocument();
        expect(svg).toHaveClass('w-4');
    });
});

