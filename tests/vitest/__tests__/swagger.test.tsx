import { screen } from '@testing-library/react';
import React from 'react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('swagger-ui-react', () => ({
  default: ({ spec }: { spec: { info: { title: string } } }) => (
    <div>{spec.info.title}</div>
  ),
}), { virtual: true });

import { renderSwagger } from '@/swagger';

describe('renderSwagger', () => {
  it('renders the API title', async () => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const spec = { openapi: '3.1.0', info: { title: 'Example API', version: '1.0.0' } };

    await act(async () => {
      renderSwagger(spec, el);
    });

    expect(screen.getByText('Example API')).toBeInTheDocument();
  });
});
