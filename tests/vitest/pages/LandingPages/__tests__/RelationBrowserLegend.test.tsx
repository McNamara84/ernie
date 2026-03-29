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

    it('renders Creator/Author legend entry when Creator is in identifier types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'Creator']}
                activeRelationTypes={['References', 'Created']}
            />,
        );

        expect(screen.getByText('Creator / Author')).toBeInTheDocument();
        expect(screen.getByTestId('legend-node-Creator')).toBeInTheDocument();
        expect(screen.getByText('Creators')).toBeInTheDocument();
    });

    it('separates Creator from Identifier Types section', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'Creator']}
                activeRelationTypes={['References']}
            />,
        );

        // DOI should be in Identifier Types, Creator should be in its own Creators section
        expect(screen.getByText('DOI')).toBeInTheDocument();
        expect(screen.getByText('Creator / Author')).toBeInTheDocument();
        expect(screen.getByText('Identifier Types')).toBeInTheDocument();
        expect(screen.getByText('Creators')).toBeInTheDocument();
    });

    it('does not render Creator section when Creator is not in identifier types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'URL']}
                activeRelationTypes={['References']}
            />,
        );

        expect(screen.queryByText('Creator / Author')).not.toBeInTheDocument();
        expect(screen.queryByText('Creators')).not.toBeInTheDocument();
    });

    it('renders Creator edge category in Relation Types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['Creator']}
                activeRelationTypes={['Created']}
            />,
        );

        expect(screen.getByTestId('legend-edge-Creator')).toBeInTheDocument();
    });

    it('renders Contributor legend entry when Contributor is in identifier types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'Contributor']}
                activeRelationTypes={['References', 'Editor']}
            />,
        );

        expect(screen.getByTestId('legend-node-Contributor')).toBeInTheDocument();
        expect(screen.getByText('Contributors')).toBeInTheDocument();
        // "Contributor" text appears in both node label and edge category
        expect(screen.getAllByText('Contributor', { exact: true })).toHaveLength(2);
    });

    it('separates Contributor from Identifier Types section', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'Contributor']}
                activeRelationTypes={['References']}
            />,
        );

        expect(screen.getByText('DOI')).toBeInTheDocument();
        expect(screen.getByText('Identifier Types')).toBeInTheDocument();
        expect(screen.getByText('Contributors')).toBeInTheDocument();
    });

    it('does not render Contributor section when Contributor is not in identifier types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'URL']}
                activeRelationTypes={['References']}
            />,
        );

        expect(screen.queryByText('Contributors')).not.toBeInTheDocument();
        expect(screen.queryByTestId('legend-node-Contributor')).not.toBeInTheDocument();
    });

    it('renders both Creator and Contributor sections together', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['DOI', 'Creator', 'Contributor']}
                activeRelationTypes={['Created', 'Editor']}
            />,
        );

        expect(screen.getByText('Creators')).toBeInTheDocument();
        expect(screen.getByText('Creator / Author')).toBeInTheDocument();
        expect(screen.getByText('Contributors')).toBeInTheDocument();
        expect(screen.getByTestId('legend-node-Creator')).toBeInTheDocument();
        expect(screen.getByTestId('legend-node-Contributor')).toBeInTheDocument();
    });

    it('renders Contributor edge category in Relation Types', () => {
        render(
            <RelationBrowserLegend
                activeIdentifierTypes={['Contributor']}
                activeRelationTypes={['Editor']}
            />,
        );

        expect(screen.getByTestId('legend-edge-Contributor')).toBeInTheDocument();
    });
});
