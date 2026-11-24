import { expect, it, vi } from 'vitest';

import { gfzDataServicesUrls } from '@/data/gfz-data-services';

vi.mock('@data/gfz-data-services.json', () => ({
    default: { host: 'data.example.test', dataProtectionPath: '/privacy' },
}));

it('builds every GFZ website link from the configured host and uses the configured privacy path', () => {
    for (const url of Object.values(gfzDataServicesUrls)) {
        expect(new URL(url).origin).toBe('https://data.example.test');
    }
    expect(gfzDataServicesUrls.dataProtection).toBe('https://data.example.test/privacy');
});
