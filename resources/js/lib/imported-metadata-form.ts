import type { AuthorEntry } from '@/components/curation/fields/author';
import type { ContributorEntry } from '@/components/curation/fields/contributor';
import type { DescriptionEntry } from '@/components/curation/fields/description-field';
import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference';
import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';
import type { TagInputItem } from '@/components/curation/fields/tag-input-field';
import type { DateEntry, LicenseEntry, TitleEntry } from '@/components/curation/types/datacite-form-types';
import { isDateRangeCapable, isEditableDateType, normalizeDateTypeSlug } from '@/components/curation/utils/date-rules';
import { mapInitialAuthorToEntry, mapInitialContributorToEntry, normalizeTitleTypeSlug } from '@/components/curation/utils/form-helpers';
import { parseDateTime } from '@/lib/date-utils';
import type { ImportedMetadata } from '@/lib/imported-metadata';
import { identityPart } from '@/lib/imported-metadata';
import type { InstrumentSelection, Language, License, MSLLaboratory, RelatedIdentifier } from '@/types';
import type { SelectedKeyword } from '@/types/vocabulary';

export interface ImportedFormParts {
    scalar: Pick<ImportedMetadata, 'doi' | 'year' | 'resourceType' | 'version'> & { language: string };
    titles: TitleEntry[];
    licenses: LicenseEntry[];
    authors: AuthorEntry[];
    contributors: ContributorEntry[];
    descriptions: DescriptionEntry[];
    dates: DateEntry[];
    keywords: SelectedKeyword[];
    freeKeywords: TagInputItem[];
    coverages: SpatialTemporalCoverageEntry[];
    relatedWorks: RelatedIdentifier[];
    relatedItems: Array<Record<string, unknown>>;
    fundingReferences: FundingReferenceEntry[];
    mslLaboratories: MSLLaboratory[];
    instruments: InstrumentSelection[];
}

export function toImportedFormParts(metadata: ImportedMetadata, languages: Language[], catalogLicenses: License[]): ImportedFormParts {
    const language = metadata.language ? (languages.find((item) => identityPart(item.code) === identityPart(metadata.language))?.code ?? '') : '';
    const knownLicenses = new Map(catalogLicenses.map((item) => [item.identifier, item]));
    const knownImportedLicenses = (metadata.licenses ?? []).filter((identifier) => knownLicenses.has(identifier));
    const licenseEntries: LicenseEntry[] = knownImportedLicenses.map((identifier) => {
        const known = knownLicenses.get(identifier)!;
        if (known.scheme_uri === null || identifier.startsWith('CUSTOM-')) {
            return { id: crypto.randomUUID(), mode: 'custom', name: known.name, uri: known.uri ?? '' };
        }
        return { id: crypto.randomUUID(), mode: 'catalog', license: identifier };
    });
    const importedIdentifiers = new Set(knownImportedLicenses.map(identityPart));
    for (const right of metadata.rawRights ?? []) {
        if (right.rightsIdentifier && importedIdentifiers.has(identityPart(right.rightsIdentifier))) continue;
        const name = (right.rights ?? right.rightsIdentifier ?? '').trim();
        const uri = (right.rightsUri ?? '').trim();
        if (name || uri) licenseEntries.push({ id: crypto.randomUUID(), mode: 'custom', name, uri, rawRight: right });
    }

    const keywords = metadata.controlledKeywords ?? [
        ...(metadata.gcmdKeywords ?? []),
        ...(metadata.mslKeywords ?? []),
        ...(metadata.gemetKeywords ?? []),
    ];

    return {
        scalar: { doi: metadata.doi, year: metadata.year, resourceType: metadata.resourceType, version: metadata.version, language },
        titles: (metadata.titles ?? [])
            .filter((item) => typeof item.title === 'string' && item.title.trim() !== '')
            .map((item) => ({
                id: crypto.randomUUID(),
                title: item.title,
                titleType: normalizeTitleTypeSlug(item.titleType) || 'main-title',
                language: item.language ?? null,
            })),
        licenses: licenseEntries,
        authors: (metadata.authors ?? []).map(mapInitialAuthorToEntry).filter((item): item is AuthorEntry => item !== null),
        contributors: (metadata.contributors ?? []).map(mapInitialContributorToEntry).filter((item): item is ContributorEntry => item !== null),
        descriptions: (metadata.descriptions ?? [])
            .filter((item) => item.description?.trim())
            .map((item) => ({
                id: crypto.randomUUID(),
                type: item.type as DescriptionEntry['type'],
                value: item.description,
                language: item.language ?? null,
            })),
        dates: (metadata.dates ?? [])
            .filter((item) => isEditableDateType(item.dateType))
            .map((item) => {
                const start = parseDateTime(item.startDate);
                const end = parseDateTime(item.endDate);
                const range = item.dateMode === 'range' || (isDateRangeCapable(item.dateType) && end.date !== '');
                return {
                    id: crypto.randomUUID(),
                    dateType: normalizeDateTypeSlug(item.dateType),
                    dateMode: range ? 'range' : 'single',
                    startDate: start.date || null,
                    endDate: range ? end.date || null : null,
                    startTime: start.time,
                    endTime: range ? end.time : null,
                    startTimezone: start.timezone,
                    endTimezone: range ? end.timezone : null,
                    dateInformation: item.dateInformation ?? null,
                };
            }),
        keywords: keywords
            .filter((item) => item.id && item.scheme)
            .map((item) => ({
                id: item.id,
                text: item.text,
                path: item.path,
                language: item.language ?? 'en',
                scheme: item.scheme,
                schemeURI: item.schemeURI ?? '',
                classificationCode: item.classificationCode,
                isLegacy: item.isLegacy === true,
            })),
        freeKeywords: (metadata.freeKeywords ?? []).filter((item) => item.trim() !== '').map((value) => ({ value })),
        coverages: (metadata.coverages ?? []).map((item) => ({
            ...item,
            id: item.id || crypto.randomUUID(),
            temporalMode: item.temporalMode ?? 'interval',
        })),
        relatedWorks: metadata.relatedWorks ?? [],
        relatedItems: metadata.relatedItems ?? [],
        fundingReferences: (metadata.fundingReferences ?? []).map((item) => ({ ...item, id: crypto.randomUUID(), isExpanded: true })),
        mslLaboratories: metadata.mslLaboratories ?? [],
        instruments: metadata.instruments ?? [],
    };
}
