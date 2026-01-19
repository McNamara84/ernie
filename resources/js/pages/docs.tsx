import { Head } from '@inertiajs/react';
import {
    BookOpen,
    Database,
    Edit3,
    FileText,
    FolderOpen,
    Globe,
    HelpCircle,
    Link2,
    MapPin,
    Palette,
    Rocket,
    Settings,
    Tags,
    Upload,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { DocsCodeBlock } from '@/components/docs/docs-code-block';
import { DocsSection } from '@/components/docs/docs-section';
import { DocsSidebar, DocsSidebarMobile } from '@/components/docs/docs-sidebar';
import { type DocsTabId, DocsTabs } from '@/components/docs/docs-tabs';
import { WorkflowSteps, WorkflowSuccess } from '@/components/docs/workflow-steps';
import { useScrollSpy } from '@/hooks/use-scroll-spy';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, UserRole } from '@/types';
import type { DocSection, DocsSidebarItem, EditorSettings } from '@/types/docs';

interface DocsProps {
    userRole: UserRole;
    editorSettings: EditorSettings;
}

/**
 * Role hierarchy for permission checking
 */
const roleHierarchy: Record<UserRole, number> = {
    beginner: 1,
    curator: 2,
    group_leader: 3,
    admin: 4,
};

export default function Docs({ userRole, editorSettings }: DocsProps) {
    const [activeTab, setActiveTab] = useState<DocsTabId>('getting-started');

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Documentation',
            href: '/docs',
        },
    ];

    const userRoleLevel = roleHierarchy[userRole] ?? 1;

    // ===========================================
    // TAB 1: Getting Started Sections
    // ===========================================
    const gettingStartedSections: DocSection[] = useMemo(
        () => [
            {
                id: 'welcome',
                title: 'Welcome',
                icon: Rocket,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Welcome to ERNIE</h3>
                        <p>
                            ERNIE is a DataCite v4.6 metadata editor for research data curation at GFZ Helmholtz Centre. This documentation will help
                            you navigate the platform and make the most of its features.
                        </p>

                        <h4>Your Role: {userRole}</h4>
                        <p>
                            Your current role is <strong>{userRole}</strong>. This documentation shows only the sections and features available to
                            your role.
                        </p>

                        <h4>Login & Navigation</h4>
                        <p>
                            After logging in at <code>/login</code>, you will be redirected to the Dashboard. The Dashboard is your central hub for:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Uploading XML files from ELMO</li>
                            <li>Uploading IGSN CSV files for physical samples</li>
                            <li>Viewing resource statistics</li>
                            <li>Quick access to recent resources</li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'profile-settings',
                title: 'Profile & Settings',
                icon: Palette,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Personal Settings</h3>
                        <p>
                            Navigate to <code>/settings</code> to customize your ERNIE experience.
                        </p>

                        <h4>Profile Settings</h4>
                        <p>
                            Update your name and email address under <code>/settings/profile</code>.
                        </p>

                        <h4>Password</h4>
                        <p>
                            Change your password securely at <code>/settings/password</code>.
                        </p>

                        <h4>Appearance</h4>
                        <p>
                            Customize the visual appearance at <code>/settings/appearance</code>:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Theme:</strong> Choose between Light, Dark, or System theme
                            </li>
                            <li>
                                <strong>Font Size:</strong> Adjust to Regular or Large for better readability
                            </li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'completeness-badges',
                title: 'Completeness Badges',
                icon: Tags,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Understanding Completeness Badges</h3>
                        <p>
                            On the <code>/resources</code> page, each resource displays a completeness badge indicating the metadata quality:
                        </p>

                        <div className="mt-4 space-y-3">
                            <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                                <div className="rounded-full bg-green-500/20 px-3 py-1 text-sm font-medium text-green-700 dark:text-green-400">
                                    Complete
                                </div>
                                <span className="text-sm text-muted-foreground">All mandatory and recommended fields are filled</span>
                            </div>
                            <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                                <div className="rounded-full bg-yellow-500/20 px-3 py-1 text-sm font-medium text-yellow-700 dark:text-yellow-400">
                                    Partial
                                </div>
                                <span className="text-sm text-muted-foreground">Mandatory fields complete, some recommended fields missing</span>
                            </div>
                            <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                                <div className="rounded-full bg-red-500/20 px-3 py-1 text-sm font-medium text-red-700 dark:text-red-400">
                                    Incomplete
                                </div>
                                <span className="text-sm text-muted-foreground">Some mandatory fields are still missing</span>
                            </div>
                        </div>

                        <p className="mt-4">
                            Click on a badge to see a detailed breakdown of which fields are complete and which need attention.
                        </p>
                    </>
                ),
            },
            {
                id: 'user-management',
                title: 'User Management',
                icon: Users,
                minRole: 'group_leader',
                content: (
                    <>
                        <h3>Managing Users</h3>
                        <p>
                            As a <strong>{userRole}</strong>, you have permission to manage users in the system.
                        </p>

                        <h4>Creating New Users</h4>
                        <p>
                            Navigate to <code>/users</code> and click <strong>"Create User"</strong>. Enter the new user's name and email. The system
                            will:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                Create the account with <strong>Beginner</strong> role
                            </li>
                            <li>Send a personalized welcome email</li>
                            <li>Include a secure link (valid for 72 hours) to set their password</li>
                        </ul>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Link Expired?</strong> Users can request a new welcome link from the expired page by entering their email.
                            </p>
                        </div>

                        <h4>User Roles</h4>
                        <div className="mt-2 space-y-2">
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Admin</h5>
                                <p className="text-sm text-muted-foreground">Full system access, manage all users, register production DOIs</p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Group Leader</h5>
                                <p className="text-sm text-muted-foreground">Manage curator/beginner users, register production DOIs</p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Curator</h5>
                                <p className="text-sm text-muted-foreground">Standard curation features, test DOI registration only</p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Beginner</h5>
                                <p className="text-sm text-muted-foreground">Limited curation, test DOI only (forced)</p>
                            </div>
                        </div>

                        {userRole === 'group_leader' && (
                            <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                                <p className="text-sm text-amber-900 dark:text-amber-100">
                                    <strong>Restriction:</strong> Group Leaders cannot promote users to group_leader or admin roles.
                                </p>
                            </div>
                        )}
                    </>
                ),
            },
            {
                id: 'editor-settings',
                title: 'Editor Settings',
                icon: Settings,
                minRole: 'admin',
                content: (
                    <>
                        <h3>Editor Configuration</h3>
                        <p>
                            As an admin, you can configure the Data Editor at <code>/settings</code> (Editor Settings):
                        </p>

                        <h4>Configurable Options</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Resource Types:</strong> Enable/disable resource types for ERNIE and ELMO
                            </li>
                            <li>
                                <strong>Title Types:</strong> Configure available title types
                            </li>
                            <li>
                                <strong>Licenses:</strong> Enable/disable specific licenses
                            </li>
                            <li>
                                <strong>Languages:</strong> Configure available languages
                            </li>
                            <li>
                                <strong>Date Types:</strong> Enable/disable date type options
                            </li>
                            <li>
                                <strong>Thesauri:</strong> Manage GCMD vocabularies (Science Keywords, Platforms, Instruments)
                            </li>
                            <li>
                                <strong>Limits:</strong> Set maximum titles and licenses per resource
                            </li>
                        </ul>

                        <h4>Thesaurus Management</h4>
                        <p>The Thesauri card allows you to:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Enable/disable individual thesauri for ERNIE and/or ELMO</li>
                            <li>Check for updates by comparing local vs. NASA remote counts</li>
                            <li>Trigger vocabulary updates with one click</li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'system-administration',
                title: 'System Administration',
                icon: Settings,
                minRole: 'admin',
                content: (
                    <>
                        <h3>System Administration</h3>
                        <p>Command-line tools for system maintenance:</p>

                        <h4>First User Creation</h4>
                        <DocsCodeBlock code="php artisan add-user <name> <email> <password>" />
                        <p className="text-sm text-muted-foreground">The first user automatically becomes admin.</p>

                        <h4>Update SPDX Licenses</h4>
                        <DocsCodeBlock code="php artisan spdx:sync-licenses" />
                        <p className="text-sm text-muted-foreground">Run monthly to keep the license database current.</p>

                        <h4>Update GCMD Vocabularies (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-gcmd-science-keywords" />
                        <DocsCodeBlock code="php artisan get-gcmd-platforms" />
                        <DocsCodeBlock code="php artisan get-gcmd-instruments" />

                        <h4>Update MSL Keywords</h4>
                        <DocsCodeBlock code="php artisan get-msl-keywords" />

                        <h4>DataCite Configuration</h4>
                        <p>
                            Configure DataCite API credentials in your <code>.env</code> file:
                        </p>
                        <DocsCodeBlock
                            code={`DATACITE_TEST_MODE=true
DATACITE_TEST_USERNAME=your_test_username
DATACITE_TEST_PASSWORD=your_test_password`}
                            language="bash"
                        />
                    </>
                ),
            },
            {
                id: 'api-documentation',
                title: 'API Documentation',
                icon: FileText,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>REST API</h3>
                        <p>ERNIE provides a comprehensive REST API following OpenAPI 3.1 specifications.</p>

                        <h4>Interactive Documentation</h4>
                        <p className="my-4">
                            <a
                                href="/api/v1/doc"
                                className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <BookOpen className="size-4" />
                                View API Documentation
                            </a>
                        </p>

                        <h4>Key Endpoints</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <code>/api/v1/resource-types</code> – Resource type definitions
                            </li>
                            <li>
                                <code>/api/v1/licenses</code> – License information
                            </li>
                            <li>
                                <code>/api/v1/ror-affiliations</code> – ROR organization affiliations
                            </li>
                            <li>
                                <code>/api/v1/orcid/search</code> – ORCID researcher search
                            </li>
                            <li>
                                <code>/api/v1/gcmd/*</code> – NASA GCMD controlled vocabularies
                            </li>
                        </ul>
                    </>
                ),
            },
        ],
        [userRole],
    );

    // ===========================================
    // TAB 2: Datasets (DOI Workflow) Sections
    // ===========================================
    const datasetsSections: DocSection[] = useMemo(
        () => [
            {
                id: 'xml-upload',
                title: 'XML Upload',
                icon: Upload,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Uploading XML Files</h3>
                        <p>
                            The Dashboard (<code>/dashboard</code>) features a unified dropzone for file uploads.
                        </p>

                        <h4>Supported Formats</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>DataCite XML v4.x</li>
                            <li>ELMO's DataCite 4.6 + ISO envelope format</li>
                        </ul>

                        <h4>Upload Process</h4>
                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Navigate to Dashboard">
                                <p>
                                    Go to <code>/dashboard</code> after logging in.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Upload XML">
                                <p>Drag and drop your XML file or click the dropzone to select a file.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Automatic Redirect">
                                <p>
                                    After successful upload, you'll be redirected to the <strong>Data Editor</strong> with metadata pre-populated.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>
                    </>
                ),
            },
            {
                id: 'curation-workflow',
                title: 'Curation Workflow',
                icon: Edit3,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Complete Curation Process</h3>
                        <p>Follow these steps to curate research data metadata:</p>

                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Review Metadata">
                                <p>Check all pre-populated fields from the XML upload for accuracy.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Validate Authors">
                                <p>Verify author information, add ORCID iDs, and confirm ROR affiliations.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Add Keywords">
                                <p>Add controlled keywords (GCMD, MSL) and free-form keywords as needed.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Complete Coverage">
                                <p>Fill in spatial and temporal coverage using the interactive tools.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={5} title="Save to Database">
                                <p>
                                    Click <strong>"Save to database"</strong> to persist the curated dataset.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <WorkflowSuccess>
                            Your curated dataset is now saved and available under <code>/resources</code> for further processing.
                        </WorkflowSuccess>
                    </>
                ),
            },
            {
                id: 'authors-contributors',
                title: 'Authors & Contributors',
                icon: Users,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Managing Authors & Contributors</h3>

                        <h4>ORCID Integration</h4>
                        <p>ERNIE integrates with the ORCID Public API for researcher identification:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Search for researchers by name</li>
                            <li>Auto-fill author details from ORCID profiles</li>
                            <li>Validate ORCID iDs in real-time</li>
                        </ul>

                        <h4>ROR Affiliations</h4>
                        <p>Institution affiliations use the Research Organization Registry (ROR):</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Search institutions by name</li>
                            <li>Auto-complete with official ROR data</li>
                            <li>Multiple affiliations per author supported</li>
                        </ul>

                        <h4>Drag & Drop Reordering</h4>
                        <p>Reorder authors and contributors by dragging them to the desired position.</p>
                    </>
                ),
            },
            {
                id: 'controlled-keywords',
                title: 'Controlled Keywords',
                icon: Tags,
                minRole: 'beginner',
                showIf: (settings) => settings.features.hasActiveGcmd || settings.features.hasActiveMsl,
                content: (
                    <>
                        <h3>Controlled Vocabularies</h3>
                        <p>ERNIE supports multiple controlled vocabulary systems for standardized keywords:</p>

                        {editorSettings.features.hasActiveGcmd && (
                            <>
                                <h4>NASA GCMD Keywords</h4>
                                <p>Global Change Master Directory vocabularies:</p>
                                <ul className="list-inside list-disc space-y-1">
                                    {editorSettings.thesauri.scienceKeywords && <li>Science Keywords – Categorize research topics</li>}
                                    {editorSettings.thesauri.platforms && <li>Platforms – Data collection platforms</li>}
                                    {editorSettings.thesauri.instruments && <li>Instruments – Measurement instruments</li>}
                                </ul>
                            </>
                        )}

                        {editorSettings.features.hasActiveMsl && (
                            <>
                                <h4>MSL Keywords</h4>
                                <p>Materials Science and Engineering vocabulary from TIB for specialized research domains.</p>
                            </>
                        )}

                        <h4>Free Keywords</h4>
                        <p>In addition to controlled vocabularies, you can add free-form keywords for flexible tagging.</p>
                    </>
                ),
            },
            {
                id: 'spatial-coverage',
                title: 'Spatial Coverage',
                icon: MapPin,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Geographic Coverage</h3>
                        <p>Define the spatial extent of your dataset using the interactive map:</p>

                        <h4>Google Maps Integration</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Point:</strong> Click on the map to set a single location
                            </li>
                            <li>
                                <strong>Bounding Box:</strong> Draw a rectangle to define an area
                            </li>
                            <li>
                                <strong>Polygon:</strong> Draw custom shapes for complex regions
                            </li>
                        </ul>

                        <h4>Manual Entry</h4>
                        <p>You can also enter coordinates manually:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Latitude/Longitude in decimal degrees</li>
                            <li>North/South/East/West bounds for bounding boxes</li>
                        </ul>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Tip:</strong> Use the search box to quickly navigate to a location by name.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'related-identifiers',
                title: 'Related Identifiers',
                icon: Link2,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Linking Related Resources</h3>
                        <p>Connect your dataset to related publications, datasets, and other resources.</p>

                        <h4>Adding Related Identifiers</h4>
                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Select Identifier Type">
                                <p>Choose the type: DOI, URL, Handle, IGSN, URN, etc.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Enter Identifier">
                                <p>Enter the identifier value (e.g., 10.1234/example for DOI).</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Select Relation Type">
                                <p>Choose how this resource relates: Cites, IsSupplementTo, IsPartOf, etc.</p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <h4>Common Relation Types</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Cites / IsCitedBy:</strong> Citation relationships
                            </li>
                            <li>
                                <strong>IsSupplementTo:</strong> Supplementary material
                            </li>
                            <li>
                                <strong>IsPartOf / HasPart:</strong> Hierarchical relationships
                            </li>
                            <li>
                                <strong>IsNewVersionOf:</strong> Version relationships
                            </li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'licenses',
                title: 'Licenses',
                icon: FileText,
                minRole: 'beginner',
                showIf: (settings) => settings.features.hasActiveLicenses,
                content: (
                    <>
                        <h3>Assigning Licenses</h3>
                        <p>Select appropriate licenses for your dataset from the SPDX license list.</p>

                        <h4>Selecting a License</h4>
                        <p>
                            The license dropdown shows all active licenses. Common choices for research data include:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>CC-BY-4.0:</strong> Attribution required, commercial use allowed
                            </li>
                            <li>
                                <strong>CC-BY-SA-4.0:</strong> Attribution + ShareAlike
                            </li>
                            <li>
                                <strong>CC0-1.0:</strong> Public domain dedication
                            </li>
                        </ul>

                        <p className="mt-4 text-sm text-muted-foreground">
                            You can assign up to {editorSettings.limits.maxLicenses} license(s) per resource.
                        </p>
                    </>
                ),
            },
            {
                id: 'landing-pages',
                title: 'Landing Pages',
                icon: Globe,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Creating Landing Pages</h3>
                        <p>
                            Landing pages are public-facing pages for your datasets. A published landing page is <strong>required</strong> before DOI
                            registration.
                        </p>

                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Navigate to Resources">
                                <p>
                                    Go to <code>/resources</code> and find your dataset.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Create Landing Page">
                                <p>Click the landing page icon button to generate a draft.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Preview">
                                <p>Review how the landing page will appear before publishing.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Share Preview (Optional)">
                                <p>Generate a preview URL to share with authors before publication.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={5} title="Publish">
                                <p>
                                    Set status to <strong>"Public"</strong> to enable DOI registration.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950">
                            <p className="text-sm text-red-900 dark:text-red-100">
                                <strong>Important:</strong> Once published, landing pages cannot be unpublished. DOIs are persistent identifiers that
                                must always resolve.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'doi-registration',
                title: 'DOI Registration',
                icon: Link2,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Registering DOIs</h3>
                        <p>Once your landing page is public, you can register a DOI through DataCite.</p>

                        <h4>DOI Duplicate Detection</h4>
                        <p>The system automatically validates DOIs when you enter them:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Shows conflicting resource if DOI exists</li>
                            <li>Suggests the next available DOI</li>
                            <li>One-click copy for suggested DOI</li>
                        </ul>

                        <h4>Test vs Production</h4>
                        <div className="mt-2 space-y-2">
                            <div className="rounded-lg border bg-card p-4">
                                <h5 className="font-semibold">Test Mode</h5>
                                <p className="text-sm text-muted-foreground">For practice – DOIs are not publicly resolvable.</p>
                            </div>
                            <div className="rounded-lg border bg-card p-4">
                                <h5 className="font-semibold">Production Mode</h5>
                                <p className="text-sm text-muted-foreground">For real publications – DOIs are permanent and public.</p>
                                {userRole === 'beginner' && (
                                    <p className="mt-2 text-sm text-amber-600 dark:text-amber-400">
                                        <strong>Note:</strong> Beginners can only register test DOIs.
                                    </p>
                                )}
                            </div>
                        </div>
                    </>
                ),
            },
            {
                id: 'legacy-import',
                title: 'Legacy Dataset Import',
                icon: FolderOpen,
                minRole: 'curator',
                content: (
                    <>
                        <h3>Importing from Old Datasets</h3>
                        <p>
                            The Legacy Dataset Browser at <code>/old-datasets</code> allows you to import metadata from the previous database.
                        </p>

                        <h4>How to Import</h4>
                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Browse Old Datasets">
                                <p>
                                    Navigate to <code>/old-datasets</code> and search for the dataset.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Select Dataset">
                                <p>Click on a dataset to view its metadata preview.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Import to Editor">
                                <p>
                                    Click <strong>"Import"</strong> to load the metadata into the Data Editor.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Review & Save">
                                <p>Review the imported data, make corrections, and save to the new database.</p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <p className="text-sm text-amber-900 dark:text-amber-100">
                                <strong>Note:</strong> Some fields may require manual mapping or verification during import.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'json-export',
                title: 'JSON Export',
                icon: FileText,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Exporting Metadata</h3>
                        <p>
                            From <code>/resources</code>, export any dataset's metadata as DataCite JSON.
                        </p>

                        <h4>Export Process</h4>
                        <p>Click the JSON export button (file icon) on any resource row.</p>

                        <h4>Automatic Validation</h4>
                        <p>All exports are validated against DataCite Metadata Schema 4.6:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Schema violations are shown in an error modal</li>
                            <li>Each issue includes JSON path and description</li>
                            <li>Fix issues before submitting to DataCite</li>
                        </ul>
                    </>
                ),
            },
        ],
        [userRole, editorSettings],
    );

    // ===========================================
    // TAB 3: Physical Samples (IGSN) Sections
    // ===========================================
    const physicalSamplesSections: DocSection[] = useMemo(
        () => [
            {
                id: 'igsn-overview',
                title: 'Overview',
                icon: HelpCircle,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>What is IGSN?</h3>
                        <p>
                            IGSN (International Generic Sample Number) is a unique, persistent identifier for physical samples in research – such as
                            rock cores, sediment samples, water samples, and biological specimens.
                        </p>

                        <h4>Key Differences from DOIs</h4>
                        <div className="mt-2 space-y-2">
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">DOI (Datasets)</h5>
                                <p className="text-sm text-muted-foreground">For digital research data and publications</p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">IGSN (Physical Samples)</h5>
                                <p className="text-sm text-muted-foreground">For physical objects that can be sampled or collected</p>
                            </div>
                        </div>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Note:</strong> IGSNs are managed separately from datasets. They appear only on the <code>/igsns</code> page.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'igsn-csv-format',
                title: 'CSV File Format',
                icon: FileText,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>IGSN CSV Format</h3>
                        <p>
                            IGSN data must be provided in a <strong>pipe-delimited CSV file</strong> (using <code>|</code> as separator).
                        </p>

                        <h4>Required Columns</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <code>igsn</code> – Unique IGSN identifier
                            </li>
                            <li>
                                <code>name</code> – Sample name
                            </li>
                            <li>
                                <code>title</code> – Full title for the record
                            </li>
                        </ul>

                        <h4>Optional Columns</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <code>sample_type</code> – Type (e.g., Borehole, Core)
                            </li>
                            <li>
                                <code>material</code> – Material type (e.g., Sediment, Rock)
                            </li>
                            <li>
                                <code>collection_start_date</code> / <code>collection_end_date</code>
                            </li>
                            <li>
                                <code>latitude</code> / <code>longitude</code>
                            </li>
                            <li>
                                <code>collector</code> – Person who collected the sample
                            </li>
                            <li>
                                <code>parent_igsn</code> – For hierarchical relationships
                            </li>
                        </ul>

                        <h4>Multi-Value Fields</h4>
                        <p>
                            Use semicolon (<code>;</code>) to separate multiple values within a field.
                        </p>
                    </>
                ),
            },
            {
                id: 'igsn-upload',
                title: 'Upload Process',
                icon: Upload,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Uploading IGSN Data</h3>

                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Prepare CSV File">
                                <p>Ensure your file follows the pipe-delimited format with required columns.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Go to Dashboard">
                                <p>
                                    Navigate to <code>/dashboard</code>.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Upload CSV">
                                <p>Drop your CSV file onto the dropzone. The system auto-detects the file type.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Review Results">
                                <p>A confirmation shows the number of IGSNs imported. You'll be redirected to the IGSN list.</p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <WorkflowSuccess>Your IGSN data has been imported and is available in the IGSN list.</WorkflowSuccess>

                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <p className="text-sm text-amber-900 dark:text-amber-100">
                                <strong>Validation:</strong> IGSNs must be globally unique. Duplicate IGSNs in the upload will be rejected.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'igsn-list',
                title: 'IGSN List',
                icon: Database,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Viewing Uploaded IGSNs</h3>
                        <p>
                            All uploaded IGSNs are displayed at <code>/igsns</code>:
                        </p>

                        <h4>Table Columns</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>IGSN identifier (links to IGSN resolver)</li>
                            <li>Title</li>
                            <li>Sample Type and Material</li>
                            <li>Collection Date range</li>
                            <li>Upload Status</li>
                        </ul>

                        <h4>Features</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Click column headers to sort</li>
                            <li>Search and filter IGSNs</li>
                            <li>Export individual IGSNs as JSON</li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'igsn-map',
                title: 'Map View',
                icon: MapPin,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Geographic Visualization</h3>
                        <p>
                            The IGSNs Map at <code>/igsns-map</code> displays all samples with coordinate data.
                        </p>

                        <h4>Map Features</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Markers for each IGSN with coordinates</li>
                            <li>Automatic viewport adjustment</li>
                            <li>Clickable markers with popup info (title, creator, year)</li>
                        </ul>

                        <p className="mt-4">
                            Access via sidebar: <strong>IGSN Curation → IGSNs Map</strong>
                        </p>
                    </>
                ),
            },
            {
                id: 'igsn-export',
                title: 'JSON Export',
                icon: FileText,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Exporting IGSN Metadata</h3>
                        <p>Each IGSN row has a JSON export button for downloading DataCite JSON format.</p>

                        <h4>Use Cases</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Archiving metadata locally</li>
                            <li>Manual submission to DataCite</li>
                            <li>Integration with other systems</li>
                        </ul>

                        <div className="mt-4 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950">
                            <p className="text-sm text-green-900 dark:text-green-100">
                                <strong>Validation:</strong> All exports are validated against DataCite Schema 4.6 before download.
                            </p>
                        </div>
                    </>
                ),
            },
        ],
        [],
    );

    // ===========================================
    // Filter sections based on role and settings
    // ===========================================
    const filterSections = useCallback(
        (sections: DocSection[]): DocSection[] => {
            return sections.filter((section) => {
                const sectionRoleLevel = roleHierarchy[section.minRole] ?? 1;
                if (sectionRoleLevel > userRoleLevel) return false;
                if (section.showIf && !section.showIf(editorSettings)) return false;
                return true;
            });
        },
        [userRoleLevel, editorSettings],
    );

    const filteredGettingStarted = useMemo(() => filterSections(gettingStartedSections), [filterSections, gettingStartedSections]);
    const filteredDatasets = useMemo(() => filterSections(datasetsSections), [filterSections, datasetsSections]);
    const filteredPhysicalSamples = useMemo(() => filterSections(physicalSamplesSections), [filterSections, physicalSamplesSections]);

    // Get current tab's sections
    const currentSections = useMemo(() => {
        switch (activeTab) {
            case 'getting-started':
                return filteredGettingStarted;
            case 'datasets':
                return filteredDatasets;
            case 'physical-samples':
                return filteredPhysicalSamples;
            default:
                return [];
        }
    }, [activeTab, filteredGettingStarted, filteredDatasets, filteredPhysicalSamples]);

    // Sidebar items
    const sidebarItems: DocsSidebarItem[] = useMemo(
        () =>
            currentSections.map((section) => ({
                id: section.id,
                label: section.title,
                icon: section.icon,
            })),
        [currentSections],
    );

    // Scroll-spy
    const sectionIds = useMemo(() => currentSections.map((s) => s.id), [currentSections]);
    const activeId = useScrollSpy(sectionIds);

    // Scroll to section
    const scrollToSection = useCallback((id: string) => {
        const element = document.getElementById(id);
        if (element) {
            const offset = 100;
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.scrollY - offset;
            window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
        }
    }, []);

    // Tab change handler
    const handleTabChange = useCallback((tab: DocsTabId) => {
        setActiveTab(tab);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentation" />
            <div className="mx-auto max-w-7xl p-6">
                {/* Header */}
                <div className="mb-8">
                    <h1 className="text-3xl font-bold">Documentation</h1>
                    <p className="mt-2 text-muted-foreground">
                        Welcome to the ERNIE documentation. Your role: <strong className="text-foreground">{userRole}</strong>
                    </p>
                </div>

                {/* Tabs */}
                <DocsTabs
                    activeTab={activeTab}
                    onTabChange={handleTabChange}
                    gettingStartedContent={null}
                    datasetsContent={null}
                    physicalSamplesContent={null}
                />

                {/* Mobile Sidebar */}
                <DocsSidebarMobile items={sidebarItems} activeId={activeId} onSectionClick={scrollToSection} />

                {/* Main Content with Sidebar */}
                <div className="flex gap-8">
                    {/* Desktop Sidebar */}
                    <DocsSidebar items={sidebarItems} activeId={activeId} onSectionClick={scrollToSection} />

                    {/* Content */}
                    <main className="min-w-0 flex-1">
                        <div className="space-y-12">
                            {currentSections.map((section) => (
                                <DocsSection key={section.id} id={section.id} title={section.title} icon={section.icon!}>
                                    {section.content}
                                </DocsSection>
                            ))}
                        </div>
                    </main>
                </div>
            </div>
        </AppLayout>
    );
}
