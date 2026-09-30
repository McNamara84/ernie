/** Public content contract; independent of the repository or future admin storage. */
export interface DataCentre {
    slug: string;
    datacenterName: string;
    displayName: string;
    shortName: string;
    logo: { src: string; width: number; height: number } | null;
    description: string[];
    links: { label: string; href: string }[];
}

export interface DataCentresPageProps {
    dataCentres: DataCentre[];
}
