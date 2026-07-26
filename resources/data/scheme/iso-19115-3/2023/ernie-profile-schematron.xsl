<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:svrl="http://purl.oclc.org/dsdl/svrl"
    xmlns:mdb="https://schemas.isotc211.org/19115/-1/mdb/1.3"
    xmlns:mcc="https://schemas.isotc211.org/19115/-1/mcc/1.3"
    xmlns:cit="https://schemas.isotc211.org/19115/-1/cit/1.3"
    xmlns:mri="https://schemas.isotc211.org/19115/-1/mri/1.3"
    xmlns:gex="https://schemas.isotc211.org/19115/-1/gex/1.3"
    xmlns:lan="https://schemas.isotc211.org/19115/-1/lan/1.3"
    xmlns:gco="https://schemas.isotc211.org/19103/-/gco/1.2"
    exclude-result-prefixes="mdb mcc cit mri gex lan gco">
    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <!--
        Reproducible XSLT form of the official mdb/mri/cit assertions that
        apply to the ERNIE profile. The official mdb.sch uses an obsolete gco
        namespace; this compiled profile intentionally uses the namespace of
        the official 1.3.0 XSD and instance examples.
    -->
    <xsl:template match="/">
        <svrl:schematron-output title="ERNIE ISO 19115-3:2023 profile">
            <xsl:if test="not(/mdb:MD_Metadata)">
                <svrl:failed-assert id="rule.mdb.root-element" role="error"
                    test="/mdb:MD_Metadata" location="/">
                    <svrl:text>There MUST be an mdb:MD_Metadata root element.</svrl:text>
                </svrl:failed-assert>
            </xsl:if>

            <xsl:for-each select="/mdb:MD_Metadata/mdb:defaultLocale | /mdb:MD_Metadata/mdb:identificationInfo/*/mri:defaultLocale">
                <xsl:if test="normalize-space(lan:PT_Locale/lan:characterEncoding/lan:MD_CharacterSetCode/@codeListValue) = ''">
                    <svrl:failed-assert id="rule.mdb.defaultlocale" role="error"
                        test="normalize-space(lan:PT_Locale/lan:characterEncoding/lan:MD_CharacterSetCode/@codeListValue) != ''"
                        location="defaultLocale">
                        <svrl:text>The default locale character encoding must be documented.</svrl:text>
                    </svrl:failed-assert>
                </xsl:if>
            </xsl:for-each>

            <xsl:for-each select="/mdb:MD_Metadata/mdb:metadataScope/mdb:MD_MetadataScope[not(mdb:resourceScope/mcc:MD_ScopeCode/@codeListValue = 'dataset')]">
                <xsl:if test="not(normalize-space(mdb:name) != '' or mdb:name/@gco:nilReason != '')">
                    <svrl:failed-assert id="rule.mdb.scope-name" role="error"
                        test="normalize-space(mdb:name) != '' or mdb:name/@gco:nilReason != ''"
                        location="mdb:metadataScope">
                        <svrl:text>A non-dataset metadata scope must have a name or nil reason.</svrl:text>
                    </svrl:failed-assert>
                </xsl:if>
            </xsl:for-each>

            <xsl:if test="not(/mdb:MD_Metadata/mdb:dateInfo/cit:CI_Date[cit:dateType/cit:CI_DateTypeCode/@codeListValue = 'creation']/cit:date/*[normalize-space(.) != ''])">
                <svrl:failed-assert id="rule.mdb.create-date" role="error"
                    test="count(mdb:dateInfo/cit:CI_Date[cit:dateType/cit:CI_DateTypeCode/@codeListValue = 'creation']) &gt; 0"
                    location="/mdb:MD_Metadata/mdb:dateInfo">
                    <svrl:text>Specify a creation date for the metadata record.</svrl:text>
                </svrl:failed-assert>
            </xsl:if>

            <xsl:if test="not(/mdb:MD_Metadata/mdb:contact/cit:CI_Responsibility)">
                <svrl:failed-assert id="rule.mdb.contact" role="error"
                    test="count(mdb:contact/cit:CI_Responsibility) &gt; 0"
                    location="/mdb:MD_Metadata/mdb:contact">
                    <svrl:text>At least one metadata contact is required.</svrl:text>
                </svrl:failed-assert>
            </xsl:if>

            <xsl:for-each select="//cit:CI_Individual">
                <xsl:if test="not(normalize-space(cit:name) != '' or normalize-space(cit:positionName) != '')">
                    <svrl:failed-assert id="rule.cit.individualnameandposition" role="error"
                        test="normalize-space(cit:name) != '' or normalize-space(cit:positionName) != ''"
                        location="cit:CI_Individual">
                        <svrl:text>An individual must have a name or position.</svrl:text>
                    </svrl:failed-assert>
                </xsl:if>
            </xsl:for-each>

            <xsl:for-each select="//cit:CI_Organisation">
                <xsl:if test="not(normalize-space(cit:name) != '' or cit:logo)">
                    <svrl:failed-assert id="rule.cit.organisationnameandlogo" role="error"
                        test="normalize-space(cit:name) != '' or cit:logo"
                        location="cit:CI_Organisation">
                        <svrl:text>An organisation must have a name or logo.</svrl:text>
                    </svrl:failed-assert>
                </xsl:if>
            </xsl:for-each>

            <xsl:for-each select="/mdb:MD_Metadata[mdb:metadataScope/mdb:MD_MetadataScope/mdb:resourceScope/mcc:MD_ScopeCode/@codeListValue = 'dataset']/mdb:identificationInfo/mri:MD_DataIdentification">
                <xsl:if test="not(mri:extent/gex:EX_Extent/gex:geographicElement/gex:EX_GeographicBoundingBox or mri:extent/gex:EX_Extent/gex:geographicElement/gex:EX_GeographicDescription)">
                    <svrl:failed-assert id="rule.mri.datasetextent" role="warning"
                        test="count(mri:extent/gex:EX_Extent/gex:geographicElement/*) &gt; 0"
                        location="mri:MD_DataIdentification/mri:extent">
                        <svrl:text>The dataset has no geographic description or bounding box.</svrl:text>
                    </svrl:failed-assert>
                </xsl:if>
            </xsl:for-each>
        </svrl:schematron-output>
    </xsl:template>
</xsl:stylesheet>
