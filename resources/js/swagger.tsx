import 'swagger-ui-react/swagger-ui.css';
import '../css/swagger-overrides.css';

import { createRoot } from 'react-dom/client';
import SwaggerUI from 'swagger-ui-react';

import { endpointCopyFeedbackPlugin } from '@/components/api-doc/endpoint-copy-button';
import { Toaster } from '@/components/ui/sonner';

declare global {
    interface Window {
        __spec__?: object;
    }
}

export function renderSwagger(spec: object, element: HTMLElement) {
    const root = createRoot(element);

    root.render(
        <>
            <SwaggerUI spec={spec} plugins={[endpointCopyFeedbackPlugin]} />
            <Toaster position="bottom-right" richColors />
        </>,
    );

    return root;
}

window.addEventListener('load', () => {
    const el = document.getElementById('swagger-ui');
    if (el) {
        const spec = window.__spec__ ?? {};
        renderSwagger(spec, el);
    }
});
