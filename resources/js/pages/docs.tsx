import { Head } from '@inertiajs/react';
import { BookOpen, Edit3, FileText, Globe, Link2, Rocket, Settings, Users } from 'lucide-react';

import { DocsCodeBlock } from '@/components/docs/docs-code-block';
import { DocsSection } from '@/components/docs/docs-section';
import { WorkflowSteps, WorkflowSuccess } from '@/components/docs/workflow-steps';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type UserRole } from '@/types';

interface DocsProps {
    userRole: UserRole;
}

interface DocSection {
    id: string;
    title: string;
    icon: React.ComponentType<{ className?: string }>;
    minRole: UserRole;
    content: React.ReactNode;
}

export default function Docs({ userRole }: DocsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Documentation',
            href: '/docs',
        },
    ];

    // Define all documentation sections with role requirements
    const allSections: DocSection[] = [
        {
            id: 'quick-start',
            title: 'Quick Start Guide',
            icon: Rocket,
            minRole: 'beginner',
            content: (
                <>
                    <h3>Welcome to ERNIE</h3>
                    <p>
                        ERNIE is a DataCite v4.5+ metadata editor for research data curation at GFZ Helmholtz Centre. This guide will help you get
                        started with the platform.
                    </p>

                    <h4>Login & Navigation</h4>
                    <p>
                        After logging in at <code>/login</code>, you will be automatically redirected to the Dashboard. The Dashboard is your central
                        hub for uploading XML files and managing your curation workflow.
                    </p>

                    <h4>Your Role: {userRole}</h4>
                    <p>
                        Your current role determines which features and actions are available to you. This documentation shows only the sections
                        relevant to your role.
                    </p>
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
                    <p>Follow these steps to curate research data metadata from upload to saving in the database:</p>

                    <WorkflowSteps>
                        <WorkflowSteps.Step number={1} title="Login">
                            <p>
                                Navigate to <code>/login</code> and authenticate with your credentials. You will be automatically redirected to the
                                Dashboard.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={2} title="Upload XML File">
                            <p>
                                In the Dashboard, use the <strong>dropzone</strong> to upload the XML file you received via email from ELMO (the
                                metadata editor for researchers).
                            </p>
                            <p className="mt-2 text-sm">
                                <strong>Accepted formats:</strong> DataCite XML v4.x or ELMO's DataCite 4.6 + ISO envelope format.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={3} title="Review in Editor">
                            <p>
                                After successful upload, you will be automatically redirected to the <strong>Data Editor</strong> (
                                <code>/editor</code>) with the metadata pre-populated from the XML file.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={4} title="Curate & Enrich">
                            <p>Review the submitted metadata carefully. Correct any errors and enrich the data with additional metadata as needed:</p>
                            <ul className="mt-2 list-inside list-disc space-y-1 text-sm">
                                <li>Validate author/contributor information (ORCID, ROR affiliations)</li>
                                <li>Add controlled keywords (GCMD Science Keywords, MSL)</li>
                                <li>Complete spatial-temporal coverage (Google Maps integration available)</li>
                                <li>Add funding references and related identifiers</li>
                                <li>Ensure mandatory fields are complete</li>
                            </ul>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={5} title="Save to Database">
                            <p>
                                Once you have reviewed and enriched the metadata, click the <strong>"Save to database"</strong> button to persist the
                                curated dataset.
                            </p>
                        </WorkflowSteps.Step>
                    </WorkflowSteps>

                    <WorkflowSuccess>
                        <strong>Success!</strong> Your curated dataset is now saved and available under <code>/resources</code> for further
                        processing.
                    </WorkflowSuccess>

                    <h4>Next Steps</h4>
                    <p>
                        After saving, you can view the dataset under <code>/resources</code>, edit it again if needed, create a landing page, and
                        eventually register a DOI.
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
                    <h3>Creating and Managing Landing Pages</h3>
                    <p>
                        Landing pages are public-facing pages for your datasets. A published landing page is <strong>required</strong> before you can
                        register a DOI.
                    </p>

                    <WorkflowSteps>
                        <WorkflowSteps.Step number={1} title="Navigate to Resources">
                            <p>
                                Go to <code>/resources</code> and find the dataset you want to create a landing page for.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={2} title="Create Landing Page">
                            <p>
                                Click the <strong>landing page icon button</strong> for your dataset. This will generate a draft landing page with all
                                metadata from your curated resource.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={3} title="Preview Landing Page">
                            <p>
                                Use the <strong>Preview</strong> feature to review how the landing page will look before publishing.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={4} title="Create Preview URL (Optional)">
                            <p>
                                If you need to share the landing page with authors or peer reviewers before publication, generate a{' '}
                                <strong>preview URL</strong>. This allows external users to view the landing page without it being publicly
                                accessible.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={5} title="Publish Landing Page">
                            <p>
                                When ready to publish, change the landing page status to <strong>"Public"</strong>. Only after this step will the{' '}
                                <strong>DOI registration icon button</strong> appear under <code>/resources</code>.
                            </p>
                        </WorkflowSteps.Step>
                    </WorkflowSteps>

                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                        <p className="text-sm text-amber-900 dark:text-amber-100">
                            <strong>Important:</strong> The landing page must be set to "Public" before you can register a DOI. This ensures that the
                            DOI resolves to an accessible landing page.
                        </p>
                    </div>

                    <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950">
                        <p className="text-sm text-red-900 dark:text-red-100">
                            <strong>DOI Persistence:</strong> Once a landing page is published, it cannot be unpublished or deleted. This is because
                            DOIs are persistent identifiers that must always resolve to a valid landing page. You can still update the template,
                            FTP download URL, and other metadata after publication.
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
                    <h3>Registering DOIs for Datasets</h3>
                    <p>Once your dataset has a published landing page, you can register a Digital Object Identifier (DOI) through DataCite.</p>

                    <h4>Prerequisites</h4>
                    <ul className="list-inside list-disc space-y-1">
                        <li>
                            Dataset must be saved to the database (<code>/resources</code>)
                        </li>
                        <li>
                            Landing page must be created and set to <strong>"Public"</strong> status
                        </li>
                        <li>All mandatory metadata fields must be complete</li>
                    </ul>

                    <h4>Registration Process</h4>
                    <WorkflowSteps>
                        <WorkflowSteps.Step number={1} title="Navigate to Resources">
                            <p>
                                Go to <code>/resources</code> and locate the dataset with a public landing page.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={2} title="Click DOI Registration Button">
                            <p>
                                The <strong>DOI registration icon button</strong> will be visible only for datasets with public landing pages. Click
                                this button to open the DOI registration modal.
                            </p>
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={3} title="Select DOI Prefix">
                            <p>
                                Choose the appropriate DOI prefix for your dataset. Available prefixes depend on your institution and repository
                                configuration.
                            </p>
                            {userRole === 'beginner' && (
                                <p className="mt-2 rounded-md bg-blue-50 p-2 text-sm text-blue-900 dark:bg-blue-950 dark:text-blue-100">
                                    <strong>Note for Beginners:</strong> You can only register DOIs in <strong>test mode</strong>. Test DOIs are for
                                    practice and will not be publicly resolvable.
                                </p>
                            )}
                        </WorkflowSteps.Step>

                        <WorkflowSteps.Step number={4} title="Confirm Registration">
                            <p>
                                Review the metadata and click <strong>"Register DOI"</strong>. The system will submit the metadata to DataCite and
                                assign a permanent DOI.
                            </p>
                        </WorkflowSteps.Step>
                    </WorkflowSteps>

                    <WorkflowSuccess>
                        <strong>Success!</strong> Your dataset is now published with a registered DOI. The landing page is publicly accessible via the
                        DOI URL.
                    </WorkflowSuccess>

                    <h4>Test vs Production DOIs</h4>
                    <div className="space-y-2">
                        <div className="rounded-lg border bg-card p-4">
                            <h5 className="font-semibold">Test Mode (api.test.datacite.org)</h5>
                            <p className="text-sm text-muted-foreground">
                                Test prefixes: 10.83279, 10.83186, 10.83114
                                <br />
                                Use for practice and training. DOIs are not publicly resolvable.
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-4">
                            <h5 className="font-semibold">Production Mode (api.datacite.org)</h5>
                            <p className="text-sm text-muted-foreground">
                                Production prefixes: 10.5880, 10.26026, 10.14470
                                <br />
                                For real publications. DOIs are permanent and publicly resolvable.
                                <br />
                                {userRole === 'beginner' && (
                                    <span className="text-amber-600 dark:text-amber-400">
                                        <strong>Restricted:</strong> Beginners cannot register production DOIs.
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>

                    <h4>Updating DOI Metadata</h4>
                    <p>
                        After registration, you can update the DOI metadata if you make changes to the dataset. The system will synchronize the latest
                        metadata with DataCite via the REST API v2.
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
                        As a <strong>{userRole}</strong>, you have permission to manage users in the system. ERNIE uses a closed application model
                        where all users must be created via the command line.
                    </p>

                    <h4>Creating New Users</h4>
                    <p>To create a new user, run the following command in the terminal:</p>

                    <DocsCodeBlock code="php artisan add-user <name> <email> <password>" />

                    <p className="text-sm text-muted-foreground">
                        Replace <code>&lt;name&gt;</code>, <code>&lt;email&gt;</code>, and <code>&lt;password&gt;</code> with the new user's
                        information.
                    </p>

                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                        <p className="text-sm text-blue-900 dark:text-blue-100">
                            <strong>First User:</strong> The first user created in the system automatically becomes an <strong>admin</strong>.
                        </p>
                    </div>

                    <h4>User Roles & Permissions</h4>
                    <div className="space-y-2">
                        <div className="rounded-lg border bg-card p-3">
                            <h5 className="text-sm font-semibold">Admin</h5>
                            <p className="text-sm text-muted-foreground">Full system access, manage all users, register production DOIs</p>
                        </div>
                        <div className="rounded-lg border bg-card p-3">
                            <h5 className="text-sm font-semibold">Group Leader</h5>
                            <p className="text-sm text-muted-foreground">
                                Manage curator/beginner users, register production DOIs, full curation access
                            </p>
                        </div>
                        <div className="rounded-lg border bg-card p-3">
                            <h5 className="text-sm font-semibold">Curator</h5>
                            <p className="text-sm text-muted-foreground">Standard curation features, test DOI registration only</p>
                        </div>
                        <div className="rounded-lg border bg-card p-3">
                            <h5 className="text-sm font-semibold">Beginner</h5>
                            <p className="text-sm text-muted-foreground">Limited curation, test DOI only (forced), no user management</p>
                        </div>
                    </div>

                    <h4>User Management Interface</h4>
                    <p>
                        Navigate to <code>/users</code> to view and manage existing users. You can:
                    </p>
                    <ul className="list-inside list-disc space-y-1">
                        <li>Change user roles (within your permission level)</li>
                        <li>Activate/deactivate user accounts</li>
                        <li>Reset user passwords</li>
                    </ul>

                    {userRole === 'group_leader' && (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <p className="text-sm text-amber-900 dark:text-amber-100">
                                <strong>Group Leader Restrictions:</strong> You cannot promote users to <strong>group_leader</strong> or{' '}
                                <strong>admin</strong> roles. Only admins can create other group leaders or admins.
                            </p>
                        </div>
                    )}

                    <h4>System Protection</h4>
                    <ul className="list-inside list-disc space-y-1 text-sm">
                        <li>
                            <strong>User ID 1:</strong> Cannot be modified, deactivated, or deleted (system-critical)
                        </li>
                        <li>
                            <strong>Self-modification:</strong> Users cannot change their own role, deactivate themselves, or reset their own password
                        </li>
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
                    <p>
                        As an <strong>admin</strong>, you have access to system-level configuration and maintenance commands.
                    </p>

                    <h4>External Service Synchronization</h4>

                    <h5 className="mt-4">Update SPDX Licenses</h5>
                    <p>Fetch the latest license identifiers and names from the SPDX License List:</p>
                    <DocsCodeBlock code="php artisan spdx:sync-licenses" />
                    <p className="text-sm text-muted-foreground">Run monthly to keep the license database current.</p>

                    <h5 className="mt-4">Update GCMD Vocabularies</h5>
                    <p>Fetch controlled vocabularies from NASA's Global Change Master Directory (GCMD):</p>
                    <DocsCodeBlock code="php artisan get-gcmd-science-keywords" />
                    <DocsCodeBlock code="php artisan get-gcmd-platforms" />
                    <DocsCodeBlock code="php artisan get-gcmd-instruments" />
                    <p className="text-sm text-muted-foreground">
                        These commands download the latest hierarchical JSON files from NASA KMS API. Recommended to run monthly.
                    </p>

                    <h5 className="mt-4">Update ROR Organizations</h5>
                    <p>
                        The ROR (Research Organization Registry) affiliations are synced automatically via the <code>GetRorIds</code> command.
                        Configure the schedule in <code>app/Console/Kernel.php</code>.
                    </p>

                    <h5 className="mt-4">Update MSL Keywords</h5>
                    <p>Materials Science keywords from TIB can be updated via:</p>
                    <DocsCodeBlock code="php artisan get-msl-keywords" />

                    <h4>DataCite Configuration</h4>
                    <p>
                        Configure DataCite API credentials in your <code>.env</code> file:
                    </p>
                    <DocsCodeBlock
                        code={`# Test Mode
DATACITE_TEST_MODE=true
DATACITE_TEST_USERNAME=your_test_username
DATACITE_TEST_PASSWORD=your_test_password
DATACITE_TEST_ENDPOINT=https://api.test.datacite.org

# Production Mode
DATACITE_PRODUCTION_USERNAME=your_production_username
DATACITE_PRODUCTION_PASSWORD=your_production_password
DATACITE_PRODUCTION_ENDPOINT=https://api.datacite.org`}
                        language="bash"
                    />

                    <h4>Queue Workers</h4>
                    <p>ERNIE uses database-backed queues for background jobs (ORCID validation, ROR sync). Ensure the queue worker is running:</p>
                    <DocsCodeBlock code="php artisan queue:listen" />
                    <p className="text-sm text-muted-foreground">
                        This is automatically started with <code>composer run dev</code>.
                    </p>

                    <h4>Google Maps API</h4>
                    <p>For the spatial coverage editor with Google Maps integration, set your API key:</p>
                    <DocsCodeBlock code="GM_API_KEY=your_google_maps_api_key" language="bash" />
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
                    <p>ERNIE provides a comprehensive REST API for integrating with external systems. The API follows OpenAPI 3.1 specifications.</p>

                    <h4>Interactive API Documentation</h4>
                    <p>Explore the full API documentation with interactive Swagger UI:</p>
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

                    <h4>Key API Endpoints</h4>
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

                    <h4>ELMO Integration</h4>
                    <p>
                        The <code>/elmo</code> endpoints are protected by API key authentication. Include the <code>X-API-Key</code> header in your
                        requests.
                    </p>

                    <DocsCodeBlock
                        code={`curl -H "X-API-Key: your_api_key" \\
  https://your-domain.com/elmo/endpoint`}
                        language="bash"
                    />
                </>
            ),
        },
    ];

    // Filter sections based on user role hierarchy
    const roleHierarchy: Record<UserRole, number> = {
        beginner: 1,
        curator: 2,
        group_leader: 3,
        admin: 4,
    };

    // Use beginner level (1) as safe fallback to ensure basic documentation is always visible
    const userRoleLevel = roleHierarchy[userRole] ?? 1;
    const visibleSections = allSections.filter((section) => roleHierarchy[section.minRole] <= userRoleLevel);

    const scrollToSection = (id: string) => {
        const element = document.getElementById(id);
        if (element) {
            const offset = 80;
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.scrollY - offset;

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth',
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentation" />
            <div className="mx-auto max-w-5xl space-y-8 p-6">
                {/* Table of Contents */}
                <div className="rounded-lg border bg-card p-6">
                    <h1 className="mb-4 text-3xl font-bold">Documentation</h1>
                    <p className="mb-6 text-muted-foreground">
                        Welcome to the ERNIE documentation. Your role: <strong className="text-foreground">{userRole}</strong>
                    </p>

                    <div className="space-y-2">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">Quick Navigation</h2>
                        <nav className="grid gap-2 sm:grid-cols-2">
                            {visibleSections.map(({ id, title, icon: Icon }) => (
                                <button
                                    key={id}
                                    onClick={() => scrollToSection(id)}
                                    className="flex items-center gap-3 rounded-lg border bg-background px-4 py-3 text-left transition-colors hover:border-primary hover:bg-muted"
                                >
                                    <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10">
                                        <Icon className="size-4 text-primary" />
                                    </div>
                                    <span className="font-medium">{title}</span>
                                </button>
                            ))}
                        </nav>
                    </div>
                </div>

                {/* Content Sections */}
                <div className="space-y-12">
                    {visibleSections.map(({ id, title, icon, content }) => (
                        <DocsSection key={id} id={id} title={title} icon={icon}>
                            {content}
                        </DocsSection>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
