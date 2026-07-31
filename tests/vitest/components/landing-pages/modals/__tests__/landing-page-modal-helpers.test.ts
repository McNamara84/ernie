import { describe, expect, it } from 'vitest';

import {
    buildLandingPagePreviewPayload,
    buildLandingPageSetupPayload,
    getPreviewableExternalUrl,
} from '@/components/landing-pages/modals/landing-page-modal-helpers';

describe('landing-page-modal-helpers', () => {
    it('builds resource setup payloads with download URL and complete links', () => {
        expect(
            buildLandingPageSetupPayload({
                template: 'default_gfz',
                landingPageTemplateId: 42,
                isPublished: true,
                supportsFtpUrl: true,
                ftpUrl: '',
                supportsLinks: true,
                links: [
                    { url: 'https://example.org/a', label: 'A', position: 3 },
                    { url: '', label: 'Incomplete', position: 4 },
                    { url: 'https://example.org/b', label: 'B', position: 5 },
                ],
                isExternal: false,
            }),
        ).toEqual({
            template: 'default_gfz',
            landing_page_template_id: 42,
            status: 'published',
            ftp_url: null,
            ftp_format_id: null,
            ftp_size_id: null,
            links: [
                { url: 'https://example.org/a', label: 'A', kind: 'related', format_id: null, size_id: null, position: 0 },
                { url: 'https://example.org/b', label: 'B', kind: 'related', format_id: null, size_id: null, position: 1 },
            ],
        });
    });

    it('includes downloads unavailable for generated resource setup payloads', () => {
        expect(
            buildLandingPageSetupPayload({
                template: 'default_gfz',
                landingPageTemplateId: null,
                isPublished: false,
                supportsFtpUrl: true,
                ftpUrl: 'https://datapub.example.org/download',
                supportsDownloadsUnavailable: true,
                downloadsUnavailable: true,
                supportsLinks: true,
                links: [{ url: 'https://example.org/repository', label: 'Repository', position: 0 }],
                isExternal: false,
            }),
        ).toEqual({
            template: 'default_gfz',
            landing_page_template_id: null,
            status: 'draft',
            ftp_url: 'https://datapub.example.org/download',
            ftp_format_id: null,
            ftp_size_id: null,
            downloads_unavailable: true,
            links: [
                {
                    url: 'https://example.org/repository',
                    label: 'Repository',
                    kind: 'related',
                    format_id: null,
                    size_id: null,
                    position: 0,
                },
            ],
        });
    });

    it('preserves explicit download and repository roles in setup payloads', () => {
        expect(
            buildLandingPageSetupPayload({
                template: 'default_gfz',
                landingPageTemplateId: null,
                isPublished: false,
                supportsFtpUrl: true,
                supportsLinks: true,
                links: [
                    { url: 'https://example.org/data.zip', label: 'Data', kind: 'download', format_id: 3, size_id: 4, position: 0 },
                    { url: 'https://git.example.org/source', label: 'Source', kind: 'repository', format_id: 8, size_id: 9, position: 1 },
                ],
                isExternal: false,
            }).links,
        ).toEqual([
            { url: 'https://example.org/data.zip', label: 'Data', kind: 'download', format_id: 3, size_id: 4, position: 0 },
            { url: 'https://git.example.org/source', label: 'Source', kind: 'repository', format_id: null, size_id: null, position: 1 },
        ]);
    });

    it('builds IGSN setup payloads without resource-only fields', () => {
        expect(
            buildLandingPageSetupPayload({
                template: 'default_gfz_igsn',
                landingPageTemplateId: 7,
                isPublished: false,
                supportsFtpUrl: false,
                ftpUrl: 'https://ignored.example.org',
                supportsLinks: false,
                links: [{ url: 'https://ignored.example.org', label: 'Ignored', position: 0 }],
                isExternal: false,
            }),
        ).toEqual({
            template: 'default_gfz_igsn',
            landing_page_template_id: 7,
            status: 'draft',
        });
    });

    it('normalizes external setup payloads', () => {
        expect(
            buildLandingPageSetupPayload({
                template: 'external',
                landingPageTemplateId: 99,
                isPublished: true,
                supportsFtpUrl: false,
                supportsLinks: false,
                isExternal: true,
                externalDomainId: '12',
                externalPath: ' /dataset/123 ',
            }),
        ).toEqual({
            template: 'external',
            landing_page_template_id: null,
            status: 'published',
            external_domain_id: 12,
            external_path: '/dataset/123',
        });
    });

    it('builds preview payloads without status or empty links', () => {
        expect(
            buildLandingPagePreviewPayload({
                template: 'default_gfz',
                landingPageTemplateId: null,
                supportsFtpUrl: true,
                ftpUrl: 'https://datapub.example.org/download',
                supportsLinks: true,
                links: [{ url: '', label: '', position: 0 }],
                isExternal: false,
            }),
        ).toEqual({
            template: 'default_gfz',
            landing_page_template_id: null,
            ftp_url: 'https://datapub.example.org/download',
            ftp_format_id: null,
            ftp_size_id: null,
        });
    });

    it('includes downloads unavailable in generated resource preview payloads', () => {
        expect(
            buildLandingPagePreviewPayload({
                template: 'default_gfz',
                landingPageTemplateId: null,
                supportsFtpUrl: true,
                ftpUrl: 'https://datapub.example.org/download',
                supportsDownloadsUnavailable: true,
                downloadsUnavailable: true,
                supportsLinks: true,
                links: [],
                isExternal: false,
            }),
        ).toEqual({
            template: 'default_gfz',
            landing_page_template_id: null,
            ftp_url: 'https://datapub.example.org/download',
            ftp_format_id: null,
            ftp_size_id: null,
            downloads_unavailable: true,
        });
    });

    it('includes exact primary and imported-file descriptors in setup and preview payloads', () => {
        const options = {
            template: 'default_gfz',
            landingPageTemplateId: null,
            supportsFtpUrl: true,
            ftpUrl: 'https://datapub.example.org/data.nc',
            ftpFormatId: 11,
            ftpSizeId: 12,
            supportsLinks: true,
            links: [],
            files: [{ id: 21, format_id: 22, size_id: 23 }],
            isExternal: false,
        };

        expect(buildLandingPageSetupPayload({ ...options, isPublished: false })).toMatchObject({
            ftp_format_id: 11,
            ftp_size_id: 12,
            files: [{ id: 21, format_id: 22, size_id: 23 }],
        });
        expect(buildLandingPagePreviewPayload(options)).toMatchObject({
            ftp_format_id: 11,
            ftp_size_id: 12,
            files: [{ id: 21, format_id: 22, size_id: 23 }],
        });
    });

    it('resolves previewable external URLs from selected domain and path', () => {
        expect(
            getPreviewableExternalUrl({
                availableDomains: [{ id: 1, domain: 'https://example.org/' }],
                externalDomainId: '1',
                externalPath: '/landing/page',
                isExternal: true,
            }),
        ).toBe('https://example.org/landing/page');
    });
});
