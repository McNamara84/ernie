import 'swagger-ui-react/swagger-ui.css';

import { createRoot } from 'react-dom/client';
import SwaggerUI from 'swagger-ui-react';

declare global {
    interface Window {
        __spec__?: object;
    }
}

export function renderSwagger(spec: object, element: HTMLElement) {
    createRoot(element).render(<SwaggerUI spec={spec} />);
}

window.addEventListener('load', () => {
    const el = document.getElementById('swagger-ui');
    if (el) {
        const spec = window.__spec__ ?? {};
        renderSwagger(spec, el);
    }
});
