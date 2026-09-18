export type PartySearchRole = 'contact_person' | 'author' | 'contributor';

export interface PartySearchMatch {
    display_value: string;
    matched_field: 'name' | 'email';
    roles: PartySearchRole[];
}
