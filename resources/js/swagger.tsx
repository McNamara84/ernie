import React from 'react';
import { createRoot } from 'react-dom/client';
import SwaggerUI from 'swagger-ui-react';
import 'swagger-ui-react/swagger-ui.css';

declare global {
  interface Window {
    __spec__?: unknown;
  }
}

export function renderSwagger(spec: unknown, element: HTMLElement) {
  createRoot(element).render(<SwaggerUI spec={spec} />);
}

window.addEventListener('load', () => {
  const el = document.getElementById('swagger-ui');
  if (el) {
    const spec = window.__spec__ ?? {};
    renderSwagger(spec, el);
  }
});
