import { Head } from '@inertiajs/react';
import {
    BookOpen,
    Braces,
    Calendar,
    Coins,
    Database,
    Edit3,
    FileText,
    FolderOpen,
    Globe,
    HelpCircle,
    Layers,
    LayoutTemplate,
    Link2,
    MapPin,
    Palette,
    Rocket,
    Settings,
    Sparkles,
    Tags,
    Type,
    Upload,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { DocsCodeBlock } from '@/components/docs/docs-code-block';
import { DocsSection } from '@/components/docs/docs-section';
import { DocsSidebar, DocsSidebarMobile } from '@/components/docs/docs-sidebar';
import { type DocsTabId, DocsTabs } from '@/components/docs/docs-tabs';
import { WorkflowSteps, WorkflowSuccess } from '@/components/docs/workflow-steps';
import { SCROLL_TO_SECTION_OFFSET, useScrollSpy } from '@/hooks/use-scroll-spy';
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
                            <li>Uploading XML files from ELMO or DataCite JSON/JSON-LD files</li>
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

                        <h4>Quick Font Size Toggle</h4>
                        <p>
                            For quick access, use the font size toggle button in the page header (top right). Click the icon to instantly switch
                            between regular and large font sizes without navigating to settings. Your preference is automatically saved.
                        </p>
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

                        <p className="mt-4">Click on a badge to see a detailed breakdown of which fields are complete and which need attention.</p>
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
                                <p className="text-sm text-muted-foreground">Manage users, view statistics, editor settings, landing page management, production DOI registration</p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Curator</h5>
                                <p className="text-sm text-muted-foreground">Standard curation features, landing page management, production DOI registration</p>
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
                minRole: 'group_leader',
                content: (
                    <>
                        <h3>Editor Configuration</h3>
                        <p>
                            You can configure the Data Editor at <code>/settings</code> (Editor Settings):
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
                                <strong>Thesauri:</strong> Manage GCMD vocabularies (Science Keywords, Platforms, Instruments), ICS Chronostratigraphy, GEMET,
                                Analytical Methods for Geochemistry and Cosmochemistry, and European Science Vocabulary (EuroSciVoc)
                            </li>
                            <li>
                                <strong>Persistent Identifiers:</strong> Manage PID registries like PID4INST (b2inst) for
                                linking research instruments and ROR for research organizations
                            </li>
                            <li>
                                <strong>Limits:</strong> Set maximum titles and licenses per resource
                            </li>
                            <li>
                                <strong>Datacenters:</strong> Manage the list of available datacenters for resource assignment
                            </li>
                        </ul>

                        <h4>Datacenter Management</h4>
                        <p>The Datacenters card allows you to:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Add new datacenters by entering a name and clicking &quot;Add&quot;</li>
                            <li>View how many resources are assigned to each datacenter</li>
                            <li>Delete datacenters that have no assigned resources (the delete button is disabled otherwise)</li>
                        </ul>

                        <h4>Bulk Selection</h4>
                        <p>
                            The settings tables for Resource Types, Title Types, Licenses, and Languages provide a{' '}
                            <strong>header checkbox</strong> in both the &quot;ERNIE active&quot; and &quot;ELMO active&quot;
                            columns. The Date Types table only has an ERNIE header checkbox (ELMO is not supported for
                            Date Types). Use these checkboxes to select or deselect all options in a column at once. When
                            some options are selected and others are not, the checkbox shows an indeterminate state (—).
                            The Thesauri card provides an &quot;All ERNIE&quot; / &quot;All ELMO&quot; row at the top for
                            the same purpose.
                        </p>

                        <h4>Thesaurus Management</h4>
                        <p>The Thesauri card allows you to:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Enable/disable individual thesauri for ERNIE and/or ELMO</li>
                            <li>Check for updates by comparing local vs. remote counts</li>
                            <li>Trigger vocabulary updates with one click</li>
                            <li>Configure the vocabulary version for versioned thesauri (e.g. Analytical Methods)</li>
                        </ul>

                        <h4>Persistent Identifiers Management</h4>
                        <p>The Persistent Identifiers card allows you to manage PID registries:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>PID4INST (b2inst):</strong> Instruments from the EUDAT b2inst registry can be
                                linked to datasets as DataCite relatedIdentifiers with relationType
                                &quot;IsCollectedBy&quot;
                            </li>
                            <li>
                                <strong>ROR (Research Organization Registry):</strong> Organization data used for
                                affiliation lookups. The ROR dataset is fetched from the Zenodo data dump and can be
                                provided to ELMO via the API.
                            </li>
                            <li>Enable/disable PID registries for ERNIE and/or ELMO independently</li>
                            <li>Check for updates by comparing local item count with the remote registry</li>
                            <li>Trigger background downloads of the full vocabulary data</li>
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

                        <h4>Update ICS Chronostratigraphy (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-chronostrat-timescale" />
                        <p className="text-sm text-muted-foreground">
                            Downloads the International Chronostratigraphic Chart from the ARDC Linked Data API.
                            Can also be triggered from Editor Settings.
                        </p>

                        <h4>Update GEMET Thesaurus (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-gemet-thesaurus" />
                        <p className="text-sm text-muted-foreground">
                            Downloads the GEMET vocabulary from the EIONET REST API.
                            Can also be triggered from Editor Settings.
                        </p>

                        <h4>Update Analytical Methods (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-analytical-methods" />
                        <p className="text-sm text-muted-foreground">
                            Downloads the Analytical Methods for Geochemistry and Cosmochemistry vocabulary from the
                            ARDC Linked Data API (EarthChem/GEOROC). The vocabulary version can be configured in
                            Editor Settings. Can also be triggered from Editor Settings.
                        </p>

                        <h4>Update European Science Vocabulary (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-euroscivoc" />
                        <p className="text-sm text-muted-foreground">
                            Downloads the European Science Vocabulary (EuroSciVoc) from the Publications Office of the
                            European Union. EuroSciVoc is a taxonomy of fields of science based on the OECD Frascati
                            Manual. Can also be triggered from Editor Settings.
                        </p>

                        <h4>Update PID4INST Instruments (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-pid4inst-instruments" />
                        <p className="text-sm text-muted-foreground">
                            Downloads all instruments from the b2inst registry. Can also be triggered from Editor
                            Settings.
                        </p>

                        <h4>Update ROR Affiliations (CLI)</h4>
                        <DocsCodeBlock code="php artisan get-ror-ids" />
                        <p className="text-sm text-muted-foreground">
                            Downloads the full ROR data dump from Zenodo. Can also be triggered from Editor
                            Settings.
                        </p>

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
                id: 'assistance',
                title: 'Assistance',
                icon: Sparkles,
                minRole: 'group_leader',
                content: (
                    <>
                        <h3>Metadata Enrichment Assistance</h3>
                        <p>
                            The <strong>Assistance</strong> page (<code>/assistance</code>) helps admins and group leaders discover and fix
                            missing metadata across all resources. Each assistant module focuses on a specific type of metadata enrichment.
                        </p>

                        <h4>Available Assistants</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Suggested Relations</strong> – Discovers missing related identifiers between resources using the
                                ScholExplorer API
                            </li>
                            <li>
                                <strong>Suggested ORCIDs</strong> – Finds ORCID identifiers for authors and contributors without one by
                                searching the ORCID API
                            </li>
                            <li>
                                <strong>Suggested ROR-IDs</strong> – Detects missing ROR identifiers for affiliations, institutions, and
                                funders via the ROR API v2
                            </li>
                        </ul>

                        <h4>Workflow</h4>
                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Check for suggestions">
                                <p>
                                    Click &quot;Check all&quot; to scan all resources at once, or use the individual &quot;Check&quot; button
                                    on each card.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Review suggestions">
                                <p>
                                    Each suggestion shows the affected resource, the current value, and the proposed match with a confidence
                                    score.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Accept or decline">
                                <p>
                                    Accept to update the resource (and auto-sync to DataCite if a DOI is registered), or decline to
                                    permanently dismiss that suggestion.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Sidebar Badge:</strong> The Assistance entry in the sidebar shows the total number of pending
                                suggestions across all assistants.
                            </p>
                        </div>
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
                                <code>/api/v1/vocabularies/gcmd-*</code> – NASA GCMD controlled vocabularies
                            </li>
                            <li>
                                <code>/api/v1/vocabularies/chronostrat-timescale</code> – ICS Chronostratigraphic Chart
                            </li>
                            <li>
                                <code>/api/v1/vocabularies/gemet</code> – GEMET environmental thesaurus
                            </li>
                            <li>
                                <code>/api/v1/elmo/vocabularies/thesauri-availability</code> – ELMO-specific thesaurus availability (returns is_elmo_active)
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
                title: 'File Upload (XML / JSON)',
                icon: Upload,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Uploading DataCite Files</h3>
                        <p>
                            The Dashboard (<code>/dashboard</code>) features a unified dropzone for file uploads.
                        </p>

                        <h4>Supported Formats</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>DataCite XML v4.x</li>
                            <li>DataCite JSON (standard API format)</li>
                            <li>DataCite JSON-LD (linked data format with <code>@context</code>)</li>
                            <li>ELMO's DataCite 4.6 + ISO envelope format</li>
                        </ul>

                        <h4>Upload Process</h4>
                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Navigate to Dashboard">
                                <p>
                                    Go to <code>/dashboard</code> after logging in.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Upload File">
                                <p>
                                    Drag and drop your XML, JSON, or JSON-LD file or click the dropzone to select a file.
                                    The system routes files by extension (XML vs JSON) and then detects the JSON
                                    sub-format (standard JSON vs JSON-LD) based on the file content.
                                </p>
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
                                <p>Check all pre-populated fields from the file upload for accuracy.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Validate Authors">
                                <p>Verify author information, add ORCID iDs, and confirm ROR affiliations.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Add Keywords">
                                <p>Add controlled keywords (GCMD, MSL, GEMET, Analytical Methods, EuroSciVoc) and free-form keywords as needed.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Complete Coverage">
                                <p>Fill in spatial and temporal coverage using the interactive tools.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={5} title="Save">
                                <p>
                                    Choose one of two options:
                                </p>
                                <ul>
                                    <li>
                                        <strong>"Save Draft"</strong> – Save an incomplete dataset with just a Main Title. You can return later to
                                        complete it. Drafts are shown with an amber badge in the resource list and on the dashboard.
                                    </li>
                                    <li>
                                        <strong>"Save &amp; Validate"</strong> – Save and validate the complete dataset. All mandatory fields
                                        (title, year, resource type, datacenter, language, license, authors, abstract) must be filled.
                                    </li>
                                </ul>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <WorkflowSuccess>
                            Your curated dataset is now saved and available under <code>/resources</code> for further processing.
                        </WorkflowSuccess>
                    </>
                ),
            },
            {
                id: 'resource-types',
                title: 'Resource Types',
                icon: Layers,
                minRole: 'beginner',
                showIf: (settings) => settings.features.hasActiveResourceTypes,
                content: (
                    <>
                        <h3>Selecting Resource Types</h3>
                        <p>Each dataset must be assigned a resource type from the DataCite controlled vocabulary:</p>

                        <h4>Common Resource Types</h4>
                        <div className="mt-2 space-y-2">
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Dataset</h5>
                                <p className="text-sm text-muted-foreground">
                                    Data encoded in a defined structure. Use for tabular data, measurements, or processed results.
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Collection</h5>
                                <p className="text-sm text-muted-foreground">
                                    Aggregation of resources. Use when grouping multiple related datasets.
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Software</h5>
                                <p className="text-sm text-muted-foreground">
                                    Computer program or code. Use for research software, scripts, or algorithms.
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">PhysicalObject</h5>
                                <p className="text-sm text-muted-foreground">
                                    Physical samples like rock cores or specimens. Typically used with IGSN identifiers.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Tip:</strong> Administrators can configure which resource types are available in Editor Settings.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'datacenters',
                title: 'Datacenters',
                icon: Database,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Assigning Datacenters</h3>
                        <p>
                            When saving a validated (non-draft) resource, at least one datacenter must be selected. Datacenters indicate
                            which GFZ data center or project database is responsible for storing or managing the dataset. Drafts can be
                            saved without a datacenter assignment.
                        </p>

                        <h4>How to Select Datacenters</h4>
                        <p>
                            In the <strong>Resource Information</strong> section of the Data Editor, click the
                            &quot;Select datacenters...&quot; button next to Resource Type. A searchable dropdown appears where you can:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Search for datacenters by typing in the search field</li>
                            <li>Select multiple datacenters by clicking checkboxes</li>
                            <li>Remove a selection by clicking the × on its badge or unchecking in the dropdown</li>
                        </ul>

                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <p className="text-sm text-amber-900 dark:text-amber-100">
                                <strong>Required:</strong> At least one datacenter must be selected before saving a validated resource.
                                Drafts can be saved without a datacenter.
                            </p>
                        </div>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Note:</strong> Datacenters are for internal categorization only and are not included in DataCite metadata exports.
                                Administrators and Group Leaders can manage the list of available datacenters in Editor Settings.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'titles-descriptions',
                title: 'Titles & Descriptions',
                icon: Type,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Titles</h3>
                        <p>Every resource requires at least one title. You can add up to {editorSettings.limits.maxTitles} titles per resource.</p>

                        <h4>Title Types</h4>
                        <div className="mt-2 space-y-2">
                            <div className="rounded-lg border bg-card p-3">
                                <h5 className="text-sm font-semibold">Main Title</h5>
                                <p className="text-sm text-muted-foreground">The primary title of the resource (required).</p>
                            </div>
                            {editorSettings.features.hasActiveTitleTypes && (
                                <>
                                    <div className="rounded-lg border bg-card p-3">
                                        <h5 className="text-sm font-semibold">Alternative Title</h5>
                                        <p className="text-sm text-muted-foreground">Another name by which the resource is known.</p>
                                    </div>
                                    <div className="rounded-lg border bg-card p-3">
                                        <h5 className="text-sm font-semibold">Subtitle</h5>
                                        <p className="text-sm text-muted-foreground">Secondary or explanatory title.</p>
                                    </div>
                                    <div className="rounded-lg border bg-card p-3">
                                        <h5 className="text-sm font-semibold">Translated Title</h5>
                                        <p className="text-sm text-muted-foreground">Title in another language.</p>
                                    </div>
                                </>
                            )}
                        </div>

                        <h3 className="mt-8">Descriptions</h3>
                        <p>Provide detailed information about your dataset with different description types:</p>

                        <h4>Description Types</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Abstract:</strong> Brief summary of the resource content
                            </li>
                            <li>
                                <strong>Methods:</strong> Methodology used to create or collect the data
                            </li>
                            <li>
                                <strong>Technical Info:</strong> Technical details about data format, structure, or processing
                            </li>
                            <li>
                                <strong>Table of Contents:</strong> Structure overview for complex datasets
                            </li>
                            <li>
                                <strong>Other:</strong> Any additional descriptive information
                            </li>
                        </ul>
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
                showIf: (settings) => settings.features.hasActiveGcmd || settings.features.hasActiveMsl || settings.features.hasActiveChronostrat || settings.features.hasActiveGemet || settings.features.hasActiveAnalyticalMethods || settings.features.hasActiveEuroSciVoc,
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

                        {editorSettings.features.hasActiveChronostrat && (
                            <>
                                <h4>ICS Chronostratigraphy</h4>
                                <p>
                                    The International Chronostratigraphic Chart provides standardized geologic time intervals
                                    organized in five hierarchy levels: Eon, Era, Period, Epoch, and Age. Sourced from the
                                    ARDC Linked Data API (GeoSciML Geologic Time Scale 2020).
                                </p>
                            </>
                        )}

                        {editorSettings.features.hasActiveGemet && (
                            <>
                                <h4>GEMET Keywords</h4>
                                <p>
                                    The GEneral Multilingual Environmental Thesaurus (GEMET) provides standardized
                                    environmental terminology. Concepts are organized in a three-level hierarchy:
                                    Super Groups, Groups, and Concepts (~5,500 terms). Sourced from the
                                    European Environment Information and Observation Network (EIONET).
                                </p>
                            </>
                        )}

                        {editorSettings.features.hasActiveAnalyticalMethods && (
                            <>
                                <h4>Analytical Methods</h4>
                                <p>
                                    The Analytical Methods for Geochemistry and Cosmochemistry vocabulary provides
                                    standardized terms for analytical techniques used in geochemical and cosmochemical
                                    research (e.g. mass spectrometry, X-ray diffraction). Concepts include optional
                                    notation codes. Sourced from the ARDC Linked Data API (EarthChem/GEOROC).
                                    The vocabulary version is configurable by administrators and group leaders.
                                </p>
                            </>
                        )}

                        {editorSettings.features.hasActiveEuroSciVoc && (
                            <>
                                <h4>European Science Vocabulary (EuroSciVoc)</h4>
                                <p>
                                    The European Science Vocabulary (EuroSciVoc) is a taxonomy of fields of science
                                    published by the Publications Office of the European Union. It is based on the
                                    OECD&apos;s 2015 Frascati Manual taxonomy and extended with categories extracted
                                    from CORDIS content. The vocabulary covers six top-level domains: Natural Sciences,
                                    Engineering and Technology, Medical and Health Sciences, Agricultural Sciences,
                                    Social Sciences, and Humanities. Keywords are mapped to DataCite with
                                    subjectScheme &quot;European Science Vocabulary (EuroSciVoc)&quot;.
                                </p>
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
                            <li>
                                <strong>Line:</strong> Draw a polyline (e.g. transects, routes, profiles) by clicking successive points on the map. Requires at least 2 points. For DataCite export, lines are automatically converted to thin polygons.
                            </li>
                        </ul>

                        <h4>Manual Entry</h4>
                        <p>You can also enter coordinates manually:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Latitude/Longitude in decimal degrees</li>
                            <li>North/South/East/West bounds for bounding boxes</li>
                        </ul>

                        <h4>CSV Import for Polygons and Lines</h4>
                        <p>
                            For polygons or lines with many coordinate pairs, you can import coordinates from a CSV file
                            instead of entering them manually:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                Click the <strong>CSV Import</strong> button next to &quot;Add Point&quot; in a polygon or line entry
                            </li>
                            <li>
                                Upload a CSV file with <code>latitude,longitude</code> or <code>lon,lat</code> column headers
                            </li>
                            <li>Both column orders are auto-detected from the header row</li>
                            <li>Coordinates are validated (latitude: -90 to +90, longitude: -180 to +180)</li>
                            <li>Up to 10,000 coordinate pairs per upload</li>
                            <li>
                                When existing points are present, choose to <strong>replace</strong> or <strong>append</strong>
                            </li>
                            <li>Download an example CSV template from within the import dialog</li>
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
                id: 'temporal-coverage',
                title: 'Temporal Coverage',
                icon: Calendar,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Time Period of Data</h3>
                        <p>Specify when the data was collected or the time period it represents:</p>

                        <h4>Date Types</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Created:</strong> When the dataset was created
                            </li>
                            <li>
                                <strong>Collected:</strong> When data collection occurred (supports date ranges)
                            </li>
                            <li>
                                <strong>Valid:</strong> Time period for which the data is valid
                            </li>
                            <li>
                                <strong>Available:</strong> When the resource became publicly available
                            </li>
                            <li>
                                <strong>Submitted:</strong> When the resource was submitted
                            </li>
                        </ul>

                        <h4>Date Formats</h4>
                        <p>ERNIE supports various date formats following ISO 8601:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <code>YYYY</code> – Year only (e.g., 2024)
                            </li>
                            <li>
                                <code>YYYY-MM</code> – Year and month (e.g., 2024-06)
                            </li>
                            <li>
                                <code>YYYY-MM-DD</code> – Full date (e.g., 2024-06-15)
                            </li>
                            <li>
                                <code>YYYY-MM-DD/YYYY-MM-DD</code> – Date range (e.g., 2020-01-01/2024-12-31)
                            </li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'funding-references',
                title: 'Funding References',
                icon: Coins,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Acknowledging Funding Sources</h3>
                        <p>Document the funding sources that supported your research:</p>

                        <h4>Required Information</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Funder Name:</strong> Official name of the funding organization
                            </li>
                            <li>
                                <strong>Funder Identifier:</strong> Crossref Funder ID or ROR ID (optional but recommended)
                            </li>
                            <li>
                                <strong>Award Number:</strong> Grant or project number
                            </li>
                            <li>
                                <strong>Award Title:</strong> Title of the funded project (optional)
                            </li>
                        </ul>

                        <h4>Common Funders</h4>
                        <p>Search for your funder by name – the system will suggest matching organizations with their official identifiers.</p>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Tip:</strong> Including funder information improves discoverability and helps funders track research outputs.
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
                        <p>The license dropdown shows all active licenses. Common choices for research data include:</p>
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
                minRole: 'curator',
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

                        <h3>Additional Download Links</h3>
                        <p>
                            You can add up to <strong>10 additional download links</strong> to a landing page. These appear below the primary download
                            button and are useful for linking to supplementary data, documentation, or related files hosted on external servers.
                        </p>

                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Open Landing Page Setup">
                                <p>Click the landing page icon for your resource and open the setup modal.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Add Links">
                                <p>
                                    In the <strong>"Additional Links"</strong> section, click <strong>"Add Link"</strong> and enter a URL and label for
                                    each link.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Reorder via Drag &amp; Drop">
                                <p>Drag the handle on each link to rearrange the display order on the landing page.</p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Save">
                                <p>
                                    Click Save to persist the links. They will appear on the preview immediately and on the public
                                    landing page once it is published.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Note:</strong> Additional links are only available for GFZ-hosted landing pages, not for external or IGSN
                                templates.
                            </p>
                        </div>

                        <h3>External Landing Pages</h3>
                        <p>
                            Instead of hosting a generated landing page, you can redirect the DOI to an external URL. This is useful when the dataset
                            already has a landing page on another platform.
                        </p>

                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Select External Template">
                                <p>
                                    In the landing page setup modal, choose <strong>"External Landing Page"</strong> from the template dropdown.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Choose Domain & Path">
                                <p>
                                    Select a preconfigured domain and enter the path to the external landing page. A preview of the resulting URL is
                                    shown below the inputs.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Publish">
                                <p>
                                    Follow the same publish workflow as for regular landing pages. The DOI will resolve via a 301 redirect to the
                                    external URL.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Note:</strong> Available domains are managed by administrators in Editor Settings. If you need a domain that
                                is not listed, contact your administrator.
                            </p>
                        </div>
                    </>
                ),
            },
            {
                id: 'landing-page-templates',
                title: 'Landing Page Templates',
                icon: LayoutTemplate,
                minRole: 'group_leader',
                content: (
                    <>
                        <h3>Custom Landing Page Templates</h3>
                        <p>
                            Admins and Group Leaders can create custom landing page templates to control the layout and branding of landing pages.
                            Custom templates are cloned from the immutable <strong>Default GFZ</strong> template and allow customization of section
                            order and header logo.
                        </p>

                        <WorkflowSteps>
                            <WorkflowSteps.Step number={1} title="Open Template Management">
                                <p>
                                    Navigate to <strong>Landing Pages</strong> in the sidebar under Administration.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={2} title="Clone Default Template">
                                <p>
                                    Click <strong>"New Template"</strong> and enter a unique name. The new template starts as an
                                    exact copy of the Default GFZ template.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={3} title="Reorder Sections">
                                <p>
                                    Click the edit icon on a template card. Use <strong>drag &amp; drop</strong> to rearrange the
                                    right column sections (e.g., Descriptions, Creators, Dates) and left column sections (e.g.,
                                    Files, Details, Map) independently.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={4} title="Upload Custom Logo (Optional)">
                                <p>
                                    Click the image icon on a template card to upload a custom header logo (PNG, JPG, SVG, or
                                    WebP, max 2 MB). The logo replaces the default GFZ logo on landing pages using this template.
                                </p>
                            </WorkflowSteps.Step>
                            <WorkflowSteps.Step number={5} title="Use in Landing Pages">
                                <p>
                                    When setting up a landing page for a resource, custom templates appear in the template dropdown
                                    alongside the built-in templates. Select a custom template to apply its layout and branding.
                                </p>
                            </WorkflowSteps.Step>
                        </WorkflowSteps>

                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <p className="text-sm text-amber-900 dark:text-amber-100">
                                <strong>Note:</strong> The Default GFZ template cannot be modified or deleted. Custom templates that
                                are currently in use by published landing pages are also protected from deletion.
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
                        <p>The system automatically validates DOIs when you enter them and when you save:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Shows conflicting resource if DOI exists</li>
                            <li>Suggests the next available DOI</li>
                            <li>One-click copy or accept suggested DOI</li>
                            <li>Save is blocked until the DOI conflict is resolved</li>
                        </ul>

                        <h4>ORCID Pre-flight Validation</h4>
                        <p>
                            Immediately before a DOI is registered, ERNIE performs a final ORCID pre-flight
                            check for every author and contributor attached to the resource:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>
                                <strong>Hard block</strong> – If an ORCID is malformed, has an invalid checksum,
                                or is reported as "not found" by orcid.org, registration is refused with a
                                422 response listing each offending author. You must correct the identifier in
                                the editor before retrying.
                            </li>
                            <li>
                                <strong>Warning (override possible)</strong> – If the ORCID service is
                                temporarily unreachable (network error, timeout, API error), the registration
                                modal shows a warning with two options: <strong>"Retry verification"</strong>{' '}
                                re-runs the pre-flight against orcid.org (use this when the service may have
                                recovered), and <strong>"Register anyway"</strong> submits with an override
                                flag. The pre-flight still contacts orcid.org on the next attempt, but any
                                transient warnings are ignored and registration proceeds regardless of the
                                verification outcome. Hard blockers (malformed / checksum / not found) are
                                never overridden this way.
                            </li>
                            <li>
                                <strong>Success</strong> – On the first successful pre-flight, the person
                                record is stamped with an internal <code>orcid_verified_at</code> timestamp
                                for auditing purposes. The timestamp records the <em>first</em> confirmation
                                by orcid.org and is intentionally not refreshed on subsequent registrations,
                                so the audit trail of the original verification is preserved. The editor
                                itself still marks identifiers as verified via an offline format + checksum
                                check when a resource is loaded; the stored timestamp is not yet consumed
                                to skip future preflight checks.
                            </li>
                        </ul>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Note: Opening an existing resource in the editor no longer triggers ORCID
                            validation for stored authors. The network check only runs when you actively edit
                            an ORCID / name field or when you press <strong>Register DOI</strong>.
                        </p>

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
                minRole: 'admin',
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
                id: 'bulk-actions',
                title: 'Bulk Actions on Resources',
                icon: FileText,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>Selecting and Acting on Multiple Resources</h3>
                        <p>
                            The <code>/resources</code> page supports multi-selection so you can act on several
                            resources at once. Select rows individually with the checkbox in the leftmost column,
                            or use the header checkbox to select all currently visible resources. The bulk
                            actions toolbar sits directly below the filter row and shows how many resources are
                            selected.
                        </p>

                        <h4>Bulk Export (all roles)</h4>
                        <p>
                            Click <strong>Export Selected</strong> to download a single ZIP archive containing
                            the metadata of every selected resource in your chosen format:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>DataCite JSON</li>
                            <li>DataCite XML</li>
                            <li>DataCite JSON-LD (Linked Data)</li>
                        </ul>
                        <p className="text-sm text-muted-foreground">
                            Limit: up to 100 resources per ZIP. Bulk exports stream the generated payload
                            directly to the browser without running the DataCite Schema 4.7 validator —
                            use the single-resource export in the editor if you need schema-validated output.
                        </p>

                        <h4>Bulk Register / Update DOI (Curator and above)</h4>
                        <p>
                            Click <strong>Register Selected</strong> to push every selected resource to DataCite
                            in one batch. The bulk flow <strong>only updates resources that already have a
                            DOI</strong>; the button is disabled when the selection contains any DOI-less resource
                            so you never accidentally mint a DOI without picking a prefix. To mint a new DOI,
                            open the resource in the editor and use the single-resource register action there.
                            Resources without a landing page or that are physical samples (IGSNs) are skipped
                            and reported in the response toast.
                        </p>
                        <p className="text-sm text-muted-foreground">Limit: up to 25 resources per batch.</p>

                        <h4>Responsive Layout</h4>
                        <p>
                            On smaller screens, less critical sub-rows (Resource Type, Curator) and the
                            Created/Updated column are hidden so the essential columns (Title, Author/Year,
                            Status, Actions) remain readable without horizontal scrolling.
                        </p>
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
            {
                id: 'jsonld-export',
                title: 'JSON-LD Export',
                icon: Braces,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>JSON-LD (Linked Data) Export</h3>
                        <p>
                            Export metadata as <strong>DataCite Linked Data JSON-LD</strong> from the
                            <code>/resources</code> page or from GFZ landing pages.
                        </p>

                        <h4>From the Resources List</h4>
                        <p>
                            Click the JSON-LD export button (braces icon) on any resource row to download the
                            metadata in DataCite Linked Data format (<code>.jsonld</code>).
                        </p>

                        <h4>From Landing Pages</h4>
                        <p>
                            Published landing pages include a JSON-LD download button in the &ldquo;Download
                            Metadata&rdquo; section. Additionally, Schema.org Dataset metadata is automatically
                            embedded in the page for search engine discoverability.
                        </p>

                        <h4>What is JSON-LD?</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>A W3C standard for linked data on the web</li>
                            <li>Uses the official DataCite Linked Data vocabulary</li>
                            <li>Machine-readable semantic metadata for interoperability</li>
                            <li>Embedded Schema.org follows ESIP Science-on-Schema.org v1.3</li>
                        </ul>

                        <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p className="text-sm text-blue-900 dark:text-blue-100">
                                <strong>Tip:</strong> JSON-LD exports do not require validation — they are generated
                                directly from the stored metadata.
                            </p>
                        </div>
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
                            <li>Search by IGSN identifier or title</li>
                            <li>Filter by IGSN prefix or upload status using the dropdown menus — active filters are shown as badges that can be individually removed</li>
                            <li>Export individual IGSNs as DataCite JSON</li>
                            <li>Setup landing pages for IGSNs</li>
                        </ul>

                        <h4>Bulk Selection</h4>
                        <p>You can select multiple IGSNs at once using the checkboxes:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Click the checkbox in each row to select individual IGSNs</li>
                            <li>Use the checkbox in the table header to select all IGSNs on the current page</li>
                            <li>When items are selected, a toolbar appears showing the selection count and available actions</li>
                            <li>The table header remains fixed while scrolling, so actions are always visible</li>
                        </ul>
                    </>
                ),
            },
            {
                id: 'igsn-admin',
                title: 'IGSN Administration',
                icon: Settings,
                minRole: 'admin',
                content: (
                    <>
                        <h3>Bulk Delete</h3>
                        <p>As an administrator, you can delete multiple IGSNs at once:</p>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Select the IGSNs you want to delete using the checkboxes in the list</li>
                            <li>Click the "Delete" button in the bulk actions toolbar</li>
                            <li>Confirm the deletion in the dialog</li>
                        </ul>
                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <p className="text-sm text-amber-900 dark:text-amber-100">
                                <strong>Warning:</strong> Deleting IGSNs is permanent and cannot be undone.
                            </p>
                        </div>
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
            {
                id: 'igsn-jsonld-export',
                title: 'JSON-LD Export',
                icon: Braces,
                minRole: 'beginner',
                content: (
                    <>
                        <h3>JSON-LD (Linked Data) Export for IGSNs</h3>
                        <p>
                            Export IGSN metadata as <strong>DataCite Linked Data JSON-LD</strong> by clicking the
                            JSON-LD button (braces icon) on any IGSN row.
                        </p>

                        <h4>Format Details</h4>
                        <ul className="list-inside list-disc space-y-1">
                            <li>Uses the official DataCite Linked Data vocabulary</li>
                            <li>Downloaded as <code>.jsonld</code> file</li>
                            <li>Includes all IGSN-specific metadata fields</li>
                            <li>No validation required — generated directly from stored data</li>
                        </ul>
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
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.scrollY - SCROLL_TO_SECTION_OFFSET;
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
                <DocsTabs activeTab={activeTab} onTabChange={handleTabChange} />

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
                                <DocsSection key={section.id} id={section.id} title={section.title} icon={section.icon}>
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
