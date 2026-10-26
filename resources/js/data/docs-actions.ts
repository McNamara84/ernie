import type { DocsCapabilities } from '@/types/docs';

export interface DocsAction {
    id: string;
    label: string;
    area: 'editor' | 'resources';
    requirements: string;
    onClick: string;
    afterConfirmation: string;
    localEffect: string;
    externalEffect: string;
    publicEffect: string;
    failure: string;
    capability?: keyof DocsCapabilities;
}

export const DOCS_ACTIONS: DocsAction[] = [
    {
        id: 'editor-curation',
        label: 'Curation',
        area: 'editor',
        requirements:
            'Saved DOI resource or draft. Beginners can read; active Curators, Group Leaders and Admins can write. IGSNs are excluded and tombstones are read-only.',
        onClick: 'Opens the checklist, responsible curators, working state, data locations and history without saving metadata.',
        afterConfirmation:
            'Each curation action saves separately. Start curation explicitly includes a published record; a handover keeps the old lead as a member.',
        localEffect:
            'Stores checklist decisions and an activity history. Authors can edit or delete their notes within 24 hours. Not applicable requires a reason.',
        externalEffect:
            'New assignments to other colleagues and selected mentions queue notifications. Review evidence confirms queuing, not delivery. Explicit start may verify an existing DOI at DataCite.',
        publicEffect: 'Curation data is internal and does not block or trigger publication. It is excluded from public metadata exports.',
        failure:
            'A concurrent change returns a conflict: reload curation, reopen the form and apply your changes. Unsubmitted metadata remains in the editor.',
    },
    {
        id: 'editor-save-draft',
        label: 'Save Draft',
        area: 'editor',
        requirements: 'A Main Title is required. This action is unavailable for published resources.',
        onClick: 'Saves the current editor values as a draft; there is no confirmation dialog.',
        afterConfirmation: 'No confirmation is required.',
        localEffect:
            'Creates or updates a draft in the ERNIE database and keeps the editor open. A newly saved draft gets a resource URL that can be reopened. Autosave can also save a draft while you work; check its visible status before leaving.',
        externalEffect: 'No request is sent to DataCite.',
        publicEffect: 'No DOI is registered and the draft is not published.',
        failure: 'If saving fails, keep the editor open and retry. Do not assume unsaved changes were stored.',
    },
    {
        id: 'editor-validate',
        label: 'Validate',
        area: 'editor',
        requirements: 'Required metadata must pass validation; legacy MSL keywords must be replaced.',
        onClick: 'Checks the metadata and saves the validated record in ERNIE when the checks pass.',
        afterConfirmation: 'No separate confirmation is required.',
        localEffect: 'The validated resource is stored in the ERNIE database.',
        externalEffect: 'No DOI registration or metadata update is requested from DataCite.',
        publicEffect: 'Validation alone does not publish a DOI.',
        failure: 'Validation errors identify fields to correct. A failed save does not confirm that the current edits were stored.',
    },
    {
        id: 'editor-register',
        label: 'Register',
        area: 'editor',
        capability: 'registerDoi',
        requirements:
            'The record has no DOI, required metadata is valid, and a configured prefix is available. A landing page is required before submission. An embargoed record can be registered only from its Available date.',
        onClick: 'Validates the form and opens a confirmation dialog. This click does not save for this action or contact DataCite.',
        afterConfirmation:
            'Saves the validated resource in ERNIE, prompts for landing-page setup if needed, then sends metadata to DataCite to create and publish a DOI in the effective test or production environment.',
        localEffect: 'The record may already be saved if landing-page setup or the later DataCite step fails.',
        externalEffect:
            'After confirmation and preparation, DataCite receives a DOI creation request with event=publish. ORCID preflight can block or warn before the request.',
        publicEffect: 'A successful production registration publishes a resolvable DOI. Test registrations remain in DataCite test mode.',
        failure:
            'A successful local save remains stored if DataCite is temporarily busy or another DOI update is in progress (503). The external update is unconfirmed; wait briefly and retry. If a newer update supersedes yours (409), reload the resource, review its latest metadata, then confirm again. Check the DOI status before retrying registration.',
    },
    {
        id: 'editor-update-metadata',
        label: 'Update Metadata',
        area: 'editor',
        capability: 'registerDoi',
        requirements:
            'A DOI is already stored and the matching record must exist in the selected DataCite environment; metadata and landing-page requirements still apply.',
        onClick: 'Validates the form and opens a confirmation dialog without submitting the update.',
        afterConfirmation:
            'Saves validated editor changes in ERNIE, prepares a landing page if necessary, and sends an update for the existing DOI to DataCite.',
        localEffect: 'The local record can be saved even if the subsequent DataCite update fails.',
        externalEffect: 'Updates the existing DataCite record; it does not mint a new DOI. ORCID preflight runs before the remote write.',
        publicEffect: 'Metadata for the existing DOI changes at DataCite after a successful request.',
        failure:
            'A successful local save remains stored if DataCite is temporarily busy or another DOI update is in progress (503). The external update is unconfirmed; wait briefly and retry. If a newer update supersedes yours (409), reload the resource, review its latest metadata, then confirm again. A locally stored DOI does not prove that it exists in this DataCite environment.',
    },
    {
        id: 'editor-preview-lp',
        label: 'Preview LP',
        area: 'editor',
        requirements: 'A Main Title is required; this button is shown for resources that are not published.',
        onClick: 'Saves the current draft and opens a preview landing page.',
        afterConfirmation: 'No DOI confirmation is involved.',
        localEffect: 'The draft is stored in ERNIE for the preview.',
        externalEffect: 'No DataCite DOI request is made.',
        publicEffect: 'The preview is not a published DOI landing page.',
        failure: 'If preparation fails, correct the reported fields and retry.',
    },
    {
        id: 'editor-show-lp',
        label: 'Show LP',
        area: 'editor',
        requirements: 'A published resource with a landing page is open.',
        onClick: 'Opens the existing landing page.',
        afterConfirmation: 'No confirmation is required.',
        localEffect: 'Opening the page does not save editor changes.',
        externalEffect: 'No DataCite request is made.',
        publicEffect: 'The existing published landing page is shown; no publication state changes.',
        failure: 'If the page cannot be opened, verify its URL and landing-page status.',
    },
    {
        id: 'resources-edit',
        label: 'Edit',
        area: 'resources',
        requirements: 'Select at least one resource.',
        onClick: 'Opens the selected resource in the editor; multiple selections open separate editor tabs.',
        afterConfirmation: 'No confirmation is required.',
        localEffect: 'The click itself saves nothing.',
        externalEffect: 'No DataCite request is made.',
        publicEffect: 'Nothing becomes public from opening an editor.',
        failure: 'If the browser blocks a tab, use the links in the blocked-tabs dialog.',
    },
    {
        id: 'resources-setup-landing-page',
        label: 'Set up landing page',
        area: 'resources',
        capability: 'manageLandingPages',
        requirements: 'Select exactly one resource.',
        onClick: 'Opens landing-page setup and checks DOI registration with DataCite before offering tombstone activation.',
        afterConfirmation:
            'Saving normal setup stores landing-page settings in ERNIE. Curators and higher roles can expand Tombstone page at the bottom to activate a tombstone for a verified registered DOI, or confirm restoration. Active tombstones open expanded.',
        localEffect: 'Updates the landing-page configuration for the selected resource.',
        externalEffect:
            'Opening setup may read the existing DOI from DataCite to verify registration. Normal setup does not register a DOI. Tombstone activation automatically sets the existing DataCite DOI to Registered and updates its URL; restoration returns its previous state and URL.',
        publicEffect:
            'A tombstone remains publicly accessible with its DOI, citation and metadata, but has no data access, is marked Dead and is hidden from the portal.',
        failure:
            'A failed setup must be corrected before DOI registration can continue. Unavailable registration verification hides new tombstone activation and can be retried; active tombstones remain accessible. A failed DataCite tombstone sync leaves the local change active, displays the pending or failed status and supports retry.',
    },
    {
        id: 'resources-related-items',
        label: 'Manage Related Items',
        area: 'resources',
        requirements: 'Select exactly one resource.',
        onClick: 'Opens the related-item manager.',
        afterConfirmation: 'Saving changes updates the relationships in ERNIE.',
        localEffect: 'Stores related-item metadata locally.',
        externalEffect: 'This action does not submit a DataCite metadata update.',
        publicEffect: 'Existing DataCite metadata remains unchanged until a separate update succeeds.',
        failure: 'If the save fails, reopen the manager and check the stored relationships.',
    },
    ...(
        [
            ['resources-export-datacite-json', 'Export DataCite JSON'],
            ['resources-export-datacite-xml', 'Export DataCite XML'],
            ['resources-export-jsonld', 'Export JSON-LD'],
        ] as const
    ).map(([id, label]): DocsAction => ({
        id,
        label,
        area: 'resources',
        requirements: 'Select one or more resources; export limits may apply.',
        onClick: 'Downloads metadata in the selected format.',
        afterConfirmation: 'No confirmation is required.',
        localEffect: 'Does not change stored resources.',
        externalEffect: 'Does not register or update a DOI.',
        publicEffect: 'Does not change publication status.',
        failure: 'If the download fails, reduce the selection or retry.',
    })),
    {
        id: 'resources-register-doi',
        label: 'Register DOI',
        area: 'resources',
        capability: 'registerDoi',
        requirements: 'Select exactly one resource that has no DOI and already has a landing page.',
        onClick: 'Opens the prefix and registration dialog; opening it does not contact DataCite.',
        afterConfirmation: 'Uses saved ERNIE metadata to request a new DOI from DataCite in the effective environment.',
        localEffect: 'Stores the resulting DOI and status in ERNIE after successful registration.',
        externalEffect: 'Sends metadata to DataCite and publishes the newly created DOI after required checks.',
        publicEffect: 'A successful production DOI becomes publicly resolvable. Beginner accounts use the test environment.',
        failure: 'Correct any validation, ORCID, embargo, or remote error and inspect the current DOI state before retrying.',
    },
    {
        id: 'resources-update-metadata',
        label: 'Update metadata',
        area: 'resources',
        capability: 'registerDoi',
        requirements: 'Select one or more resources, each with an existing DOI and a landing page. Up to 25 resources can be updated per batch.',
        onClick: 'Opens a confirmation dialog; no update is sent yet.',
        afterConfirmation: 'Processes the selected saved records one by one and sends updates for their existing DOIs to DataCite.',
        localEffect: 'Does not save unsaved editor values or create new local resources.',
        externalEffect: 'Updates existing DataCite metadata. It does not mint new DOIs.',
        publicEffect: 'Successfully updated DOI metadata changes at DataCite; other selected records may remain unchanged.',
        failure: 'The batch may report partial success. Review each failed record, correct it, then retry only the affected records.',
    },
    {
        id: 'resources-send-review-link',
        label: 'Send review link',
        area: 'resources',
        capability: 'sendReviewLinks',
        requirements: 'Every selected resource must be in review and have a usable preview link and valid ContactPerson email address.',
        onClick: 'Opens a confirmation dialog.',
        afterConfirmation: 'Queues a separate review invitation per eligible contact and resource.',
        localEffect: 'Records the queued email result; does not register a DOI.',
        externalEffect: 'Email is queued for delivery to contacts; DataCite metadata is unchanged.',
        publicEffect: 'The private review link is sent to recipients; the resource is not published by this action.',
        failure: 'Some recipients or resources may be skipped. Review the batch result before resending.',
    },
    {
        id: 'resources-review-migration',
        label: 'Notify changed review link',
        area: 'resources',
        capability: 'sendReviewLinks',
        requirements: 'Use only for review resources whose previously sent link stopped working after a server move.',
        onClick: 'Opens a migration-notice confirmation dialog.',
        afterConfirmation: 'Queues notices with the replacement review link for eligible ContactPerson recipients.',
        localEffect: 'Records the queued result; does not edit resource metadata.',
        externalEffect: 'Sends email notifications; DataCite is unchanged.',
        publicEffect: 'The replacement private review link reaches recipients; no DOI is published.',
        failure: 'Review skipped contacts and partial results before retrying.',
    },
    {
        id: 'resources-delete',
        label: 'Delete',
        area: 'resources',
        capability: 'deleteResources',
        requirements: 'Select records and choose deletable status groups. Only Admins and Group Leaders may delete published resources.',
        onClick: 'Opens a dialog showing the selected status groups; published records require a separate opt-in.',
        afterConfirmation: 'Deletes the chosen ERNIE resources and their ERNIE landing pages.',
        localEffect: 'Removes the selected local records and associated landing pages.',
        externalEffect: 'Existing DataCite records are not deleted or redirected.',
        publicEffect: 'A published DOI may continue to exist at DataCite while its former ERNIE landing page returns 404.',
        failure: 'Check the reported result and remaining rows before retrying; deletion cannot be undone in this interface.',
    },
    {
        id: 'resources-datacite-url-migration',
        label: 'Update DataCite landing-page URLs',
        area: 'resources',
        capability: 'updateDataCiteUrls',
        requirements: 'Admin access; review the eligible count, target base URL, environment, and sample changes.',
        onClick: 'Opens a preview without changing DataCite.',
        afterConfirmation: 'Starts a background run that changes eligible existing DOI target URLs at DataCite.',
        localEffect: 'Stores run progress and results for review.',
        externalEffect: 'Changes only the landing-page URL of existing DataCite records; no new DOI is minted.',
        publicEffect: 'Successful updates change where existing DOIs resolve.',
        failure: 'Review failed or skipped items; paused runs can be resumed and failed items retried after correcting the cause.',
    },
];

export const docsActionHref = (id: string): string => `/docs?tab=datasets&section=${encodeURIComponent(id)}`;
