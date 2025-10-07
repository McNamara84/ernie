const normalizeKey = (value: string): string => {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]/g, '');
};

const CONTRIBUTOR_ROLE_LABELS: Record<string, string> = {
    contactperson: 'Contact Person',
    datacollector: 'Data Collector',
    datacurator: 'Data Curator',
    datamanager: 'Data Manager',
    distributor: 'Distributor',
    editor: 'Editor',
    hostinginstitution: 'Hosting Institution',
    producer: 'Producer',
    projectleader: 'Project Leader',
    projectmanager: 'Project Manager',
    projectmember: 'Project Member',
    registrationagency: 'Registration Agency',
    registrationauthority: 'Registration Authority',
    relatedperson: 'Related Person',
    researcher: 'Researcher',
    researchgroup: 'Research Group',
    rightsholder: 'Rights Holder',
    sponsor: 'Sponsor',
    supervisor: 'Supervisor',
    translator: 'Translator',
    workpackageleader: 'WorkPackage Leader',
    other: 'Other',
};

const INSTITUTION_ONLY_ROLE_KEYS = new Set([
    'distributor',
    'hostinginstitution',
    'registrationagency',
    'registrationauthority',
    'researchgroup',
    'sponsor',
]);

export const normaliseContributorRoleLabel = (value: string): string => {
    const trimmed = value.trim();

    if (!trimmed) {
        return '';
    }

    const key = normalizeKey(trimmed);

    return key && CONTRIBUTOR_ROLE_LABELS[key] ? CONTRIBUTOR_ROLE_LABELS[key] : trimmed;
};

const normaliseRoleKey = (role: string): string | null => {
    const key = normalizeKey(role);
    return key.length > 0 ? key : null;
};

const rolesRequireInstitution = (roles: readonly string[]): boolean => {
    const keys = roles
        .map((role) => (typeof role === 'string' ? normaliseRoleKey(role) : null))
        .filter((role): role is string => Boolean(role));

    if (keys.length === 0) {
        return false;
    }

    return keys.every((key) => INSTITUTION_ONLY_ROLE_KEYS.has(key));
};

export const inferContributorTypeFromRoles = (
    rawType: string | null | undefined,
    roles: readonly string[],
): 'person' | 'institution' => {
    if (typeof rawType === 'string' && rawType.trim().toLowerCase() === 'institution') {
        return 'institution';
    }

    if (rolesRequireInstitution(roles)) {
        return 'institution';
    }

    return 'person';
};
