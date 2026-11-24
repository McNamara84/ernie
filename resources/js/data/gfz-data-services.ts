import site from '@data/gfz-data-services.json';

const website = `https://${site.host}`;

export const gfzDataServicesUrls = {
    home: website,
    aboutUs: `${website}/about-us`,
    copyrights: `${website}/about-us/copyrights`,
    dataCentres: `${website}/find/data-centres`,
    dataProtection: `${website}${site.dataProtectionPath}`,
    fairSamplesTemplate: `${website}/samples/fair-samples-template`,
    fileInstructions: `${website}/publish-data/data-file-instructions`,
    glossary: `${website}/support/glossary`,
    igsnService: `${website}/samples/introduction`,
    publicationInstructions: `${website}/publish-data/publication-instructions`,
    quickStartGuide: `${website}/publish-data/quick-start-guide`,
};
