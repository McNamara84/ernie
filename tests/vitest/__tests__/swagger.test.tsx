import { screen } from '@testing-library/react';
import { act } from 'react';
import type { Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

interface SwaggerMockProps {
    spec: { info: { title: string } };
    plugins?: unknown[];
}

const swaggerMockState = vi.hoisted(() => ({ current: undefined as SwaggerMockProps | undefined }));

vi.mock('swagger-ui-react', () => ({
    default: (props: SwaggerMockProps) => {
        swaggerMockState.current = props;

        return <div>{props.spec.info.title}</div>;
    },
}));

vi.mock('@/components/ui/sonner', () => ({
    Toaster: ({ position, richColors }: { position?: string; richColors?: boolean }) => (
        <div data-testid="swagger-toaster" data-position={position} data-rich-colors={richColors ? 'true' : 'false'} />
    ),
}));

import { endpointCopyFeedbackPlugin } from '@/components/api-doc/endpoint-copy-button';
import { renderSwagger } from '@/swagger';

describe('renderSwagger', () => {
    let container: HTMLDivElement | undefined;
    let root: Root | undefined;

    afterEach(async () => {
        await act(async () => {
            root?.unmount();
        });
        container?.remove();
        swaggerMockState.current = undefined;
        root = undefined;
        container = undefined;
    });

    it('renders the API title with endpoint copy feedback and the configured toaster', async () => {
        const el = document.createElement('div');
        document.body.appendChild(el);
        container = el;
        const spec = {
            openapi: '3.2.0',
            info: { title: 'Example API', summary: 'OpenAPI 3.2 test document', version: '1.0.0' },
            security: [],
        };

        await act(async () => {
            root = renderSwagger(spec, el);
        });

        expect(screen.getByText('Example API')).toBeInTheDocument();
        expect(swaggerMockState.current?.spec).toBe(spec);
        expect(swaggerMockState.current?.plugins).toEqual([endpointCopyFeedbackPlugin]);
        expect(screen.getByTestId('swagger-toaster')).toHaveAttribute('data-position', 'bottom-right');
        expect(screen.getByTestId('swagger-toaster')).toHaveAttribute('data-rich-colors', 'true');
    });
});
