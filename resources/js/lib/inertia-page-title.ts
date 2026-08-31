interface InertiaTitlePage {
    component: string;
    props: Record<string, unknown>;
}

const LANDING_PAGE_COMPONENT_PREFIX = 'LandingPages/';

/**
 * Keep public landing-page document titles independent from the internal ERNIE
 * application name while preserving the established format everywhere else.
 */
export function formatInertiaPageTitle(title: string, page: InertiaTitlePage, appName: string): string {
    if (page.component.startsWith(LANDING_PAGE_COMPONENT_PREFIX)) {
        const documentTitle = page.props.documentTitle;

        if (typeof documentTitle === 'string' && documentTitle.trim() !== '') {
            return documentTitle;
        }
    }

    return title ? `${title} - ${appName}` : appName;
}
