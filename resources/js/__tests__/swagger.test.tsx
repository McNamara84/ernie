import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { act } from 'react';

vi.mock('swagger-ui-react', () => ({
  default: ({ spec }: { spec: any }) => <div>{spec.info.title}</div>,
}));

import { renderSwagger } from '../swagger';

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
