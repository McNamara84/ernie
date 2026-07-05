export type GuidedTourAutostartPayload = {
    assignmentId: number;
    key: string;
    version: number;
    startRoute: string;
    status: string;
    autostart: boolean;
};

export type GuidedTourStepDefinition = {
    id: string;
    element: string;
    title: string;
    description: string;
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
};

export type GuidedTourDefinition = {
    key: string;
    version: number;
    steps: GuidedTourStepDefinition[];
};

const guidedTourDefinitions: Record<string, GuidedTourDefinition> = {
    'beginner-dashboard-main-menu:1': {
        key: 'beginner-dashboard-main-menu',
        version: 1,
        steps: [
            {
                id: 'dashboard-welcome',
                element: '[data-tour="dashboard-welcome"]',
                title: 'Welcome to ERNIE',
                description: 'This dashboard is your starting point after login. It gives you a quick overview of your current work.',
                side: 'bottom',
                align: 'start',
            },
            {
                id: 'sidebar-root',
                element: '[data-tour="sidebar-root"]',
                title: 'Main Menu',
                description: 'The main menu stays available while you work. It is the fastest way to move between the core areas of ERNIE.',
                side: 'right',
                align: 'start',
            },
            {
                id: 'dashboard-upload',
                element: '[data-tour="dashboard-upload"]',
                title: 'Upload Area',
                description:
                    'Use this area to upload DataCite XML, JSON, JSON-LD, or IGSN CSV files, then review the result before opening the editor or IGSN list.',
                side: 'top',
                align: 'center',
            },
            {
                id: 'sidebar-data-editor',
                element: '[data-tour="sidebar-data-editor"]',
                title: 'Data Editor',
                description: 'Open the editor when you want to create or continue a metadata record manually.',
                side: 'right',
                align: 'center',
            },
            {
                id: 'sidebar-resources',
                element: '[data-tour="sidebar-resources"]',
                title: 'Resources',
                description: 'The Resources list shows your dataset records, including drafts that still need to be completed.',
                side: 'right',
                align: 'center',
            },
            {
                id: 'sidebar-igsns-list',
                element: '[data-tour="sidebar-igsns-list"]',
                title: 'IGSNs List',
                description: 'Open this list to review physical sample records that use IGSNs.',
                side: 'right',
                align: 'center',
            },
            {
                id: 'sidebar-igsns-map',
                element: '[data-tour="sidebar-igsns-map"]',
                title: 'IGSNs Map',
                description: 'The map helps you explore sample locations visually when geographic data is available.',
                side: 'right',
                align: 'center',
            },
            {
                id: 'sidebar-documentation',
                element: '[data-tour="sidebar-documentation"]',
                title: 'Documentation',
                description: 'Use the documentation whenever you need a refresher on the ERNIE workflow or form requirements.',
                side: 'right',
                align: 'end',
            },
        ],
    },
};

export function getGuidedTourDefinition(key: string, version: number): GuidedTourDefinition | null {
    return guidedTourDefinitions[`${key}:${version}`] ?? null;
}
