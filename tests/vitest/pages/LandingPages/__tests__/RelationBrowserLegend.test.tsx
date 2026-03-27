import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { RelationBrowserLegend } from '@/pages/LandingPages/components/relation-browser/RelationBrowserLegend';

describe('RelationBrowserLegend', () => {
    it('renders legend with identifier types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'URL', 'ISBN']}
                activeRelationTypes={['References']}
            />,
        );

        expect(screen.getByTestId('relation-browser-legend')).toBeInTheDocument();
        expect(screen.getByText('DOI')).toBeInTheDocument();
        expect(screen.getByText('URL')).toBeInTheDocument();
        expect(screen.getByText('ISBN')).toBeInTheDocument();
    });

    it('renders legend with relation type categories', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI']}
                activeRelationTypes={['References', 'IsDerivedFrom', 'IsDocumentedBy']}
            />,
        );

        expect(screen.getByText('Citation')).toBeInTheDocument();
        expect(screen.getByText('Derivation')).toBeInTheDocument();
        expect(screen.getByText('Documentation')).toBeInTheDocument();
    });

    it('shows "This Resource" entry with GFZ blue', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI']}
                activeRelationTypes={['References']}
            />,
        );

        expect(screen.getByText('This Resource')).toBeInTheDocument();
    });

    it('renders node color indicators with correct test IDs', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'Handle']}
                activeRelationTypes={['References']}
            />,
        );

        expect(screen.getByTestId('legend-node-DOI')).toBeInTheDocument();
        expect(screen.getByTestId('legend-node-Handle')).toBeInTheDocument();
    });

    it('renders edge color indicators with correct test IDs', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI']}
                activeRelationTypes={['Cites', 'IsDerivedFrom']}
            />,
        );

        expect(screen.getByTestId('legend-edge-Citation')).toBeInTheDocument();
        expect(screen.getByTestId('legend-edge-Derivation')).toBeInTheDocument();
    });

    it('deduplicates relation type categories', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI']}
                activeRelationTypes={['Cites', 'IsCitedBy', 'References']}
            />,
        );

        // All three are "Citation" category, should only appear once
        const citationElements = screen.getAllByText('Citation');
        expect(citationElements).toHaveLength(1);
    });

    it('returns null when no active types', () => {
        const { container } = render(
            <RelationBrowserLegend
                activeIdentifierTypes={[]}
                activeRelationTypes={[]}
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders only identifier types section when no relation types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI']}
                activeRelationTypes={[]}
            />,
        );

        expect(screen.getByText('Identifier Types')).toBeInTheDocument();
        expect(screen.queryByText('Relation Types')).not.toBeInTheDocument();
    });

    it('handles "Other" category for unknown relation types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI']}
                activeRelationTypes={['Collects']}
            />,
        );

        expect(screen.getByText('Other')).toBeInTheDocument();
    });
});
