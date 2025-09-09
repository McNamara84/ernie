import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

let DataCiteFormMock: any;
vi.mock('@/components/curation/datacite-form', () => {
  DataCiteFormMock = vi.fn(() => <div>DataCiteForm</div>);
  return {
    default: DataCiteFormMock,
  };
});

describe('Curation page', () => {
  it('renders DataCiteForm with provided resource types', async () => {
    const { default: Curation } = await import('../curation');
    const resourceTypes = [{ id: 1, name: 'Dataset' }];
    render(<Curation resourceTypes={resourceTypes} />);
    expect(screen.getByText('DataCiteForm')).toBeInTheDocument();
    expect(DataCiteFormMock).toHaveBeenCalledWith({ resourceTypes }, undefined);
  });
});

