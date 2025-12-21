import { Head } from '@inertiajs/react';

import PublicLayout from '@/layouts/public-layout';

export default function LegalNotice() {
    return (
        <PublicLayout>
            <Head title="Legal Notice" />
            <h1 className="mb-4 text-2xl font-semibold">Legal information / Provider Identification</h1>
            <p className="mb-4">
                Imprint according to § 5 DDG (German Digital Services Act) and § 18 (2) of the State Media Treaty (Medienstaatsvertrag, MstV).
            </p>
            <p className="mb-4">
                <strong>Provider</strong>
                <br />
                The provider of this internet presence is the GFZ Helmholtz Centre for Geosciences.
            </p>
            <p className="mb-4">
                <strong>Address</strong>
                <br />
                GFZ Helmholtz Centre for Geosciences
                <br />
                Telegrafenberg
                <br />
                14473 Potsdam, Germany
                <br />
                Tel.: +49 331 6264 0
                <br />
                Website:{' '}
                <a href="https://www.gfz.de/en/" target="_blank" rel="noreferrer" className="text-primary underline">
                    www.gfz.de
                </a>
            </p>
            <p className="mb-4">
                <strong>Legal form</strong>
                <br />
                The GFZ Helmholtz Centre for Geosciences is a foundation under public law of the State of Brandenburg. The GFZ is a member of the{' '}
                <a href="https://www.helmholtz.de/en/" target="_blank" rel="noreferrer" className="text-primary underline">
                    Helmholtz Association of German Research Centres
                </a>
                .
            </p>
            <p className="mb-4">
                <strong>Representatives</strong>
                <br />
                The GFZ is legally represented by Prof. Dr. Susanne Buiter (Chairperson of the Board and Scientific Executive Director) and Dr. Marco
                Kupzig [Administrative Executive Director (ad interim)].
            </p>
            <p className="mb-4">
                <strong>VAT ID</strong>
                <br />
                VAT Identification Number according to § 27a VAT Tax Act: DE138407750
            </p>
            <p className="mb-4">
                <strong>Editorial responsibility</strong>
                <br />
                Editorial responsibility for this website in accordance with § 18 (2) of the State Media Treaty:
                <br />
                Kirsten Elger (GFZ Data and Information Services)
                <br />
                Phone: +49 331 6264-2822
                <br />
                E-Mail:{' '}
                <a href="mailto:kirsten.elger@gfz.de" className="text-primary underline">
                    kirsten.elger@gfz.de
                </a>
            </p>
            <p className="mb-4">
                <strong>Web concept and Screendesign</strong>
                <br />
                Dr. Kirsten Elger and Alexander Brauser (GFZ Data and Information Services)
            </p>
            <p className="mb-4">
                <strong>Technical Realization and support</strong>
                <br />
                Responsible for the technical implementation is the unit{' '}
                <a
                    href="https://www.gfz.de/en/section/it-services-and-operation/overview"
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline"
                >
                    IT Services and Operations
                </a>
                <br />
                E-Mail:{' '}
                <a href="mailto:webmaster@gfz.de" className="text-primary underline">
                    webmaster@gfz.de
                </a>
            </p>
            <p className="mb-4">
                <strong>Copyright</strong>
                <br />
                Texts and photos on the GFZ website are protected by copyright. Copying these files or printing these files is only permitted for
                private use.
            </p>
            <p className="mb-4">
                All other forms of use, such as the reproduction, modification or use of graphics, audio documents, video sequences and texts on the
                websites and in other electronic or printed publications for non-commercial or commercial purposes — even if they are not expressly
                marked as copyright-protected documents — are not permitted without the prior written consent of the copyright holder.
            </p>
            <p className="mb-4">
                Content published under special licences is identified as such. It may be used in accordance with the terms of the specific licence.
            </p>
            <p className="mb-4">
                Some of the data on this data portal originates from other sources. If you wish to use this data, please contact the owner of the data
                for the terms of use/see the respective terms of use on the websites.
            </p>
            <p className="mb-4">
                <strong>Disclaimer</strong>
                <br />
                The editorial staff controls and updates the available information on these websites at regular intervals. Despite all care,
                information, data or links may have changed in the meantime. There is no liability or guarantee for the currentness, accuracy and
                completeness of the information provided.
            </p>
            <p className="mb-4">
                The same applies to all other websites, which are referred to by hyperlink. There is not responsibility for the content of the
                websites that are reached as a result of such a connection. Instead the relevant provider is always responsible for the contents of
                the linked pages.
            </p>
            <p className="mb-4">
                In establishing the initial link, the editorial staff has reviewed the relevant external content in order to determine that they were
                free of illegal content at the time of linking.
            </p>
            <p className="mb-4">If you detect errors in content or technology, please let us know.</p>
            <hr className="my-6" />
            <p>
                <a
                    href="https://dataservices.gfz-potsdam.de/web/about-us/data-protection"
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline"
                >
                    Data Privacy Protection
                </a>
            </p>
        </PublicLayout>
    );
}
