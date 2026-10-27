<?xml version="1.0" encoding="UTF-8"?>
<!-- Mapping reference: docs/igsn-2.0-core-export.md. Core snapshot 321df7287f8540960b99aeb55113cbf22274380c. -->
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:s="urn:ernie:igsn-core-source:1" xmlns:igsn="urn:FAIR-AIMS:Core:1"
  xmlns:daci="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  exclude-result-prefixes="s">
  <xsl:output method="xml" encoding="UTF-8" indent="yes"/>
  <xsl:param name="schema-url"/>
  <xsl:template match="/s:resource">
    <igsn:resource xsi:schemaLocation="urn:FAIR-AIMS:Core:1 {$schema-url}">
      <igsn:identifier igsn:identifierType="DOI"><xsl:value-of select="s:doi"/></igsn:identifier>
      <xsl:for-each select="s:names/s:name">
        <igsn:name>
          <xsl:if test="@type"><xsl:attribute name="igsn:titleType"><xsl:value-of select="@type"/></xsl:attribute></xsl:if>
          <xsl:if test="@language"><xsl:attribute name="igsn:lang"><xsl:value-of select="@language"/></xsl:attribute></xsl:if>
          <xsl:value-of select="."/>
        </igsn:name>
      </xsl:for-each>
      <igsn:agents>
        <xsl:for-each select="s:creators/s:party">
          <igsn:creator igsn:creatorType="{@role}">
            <igsn:creatorName igsn:nameType="{@type}"><xsl:value-of select="@name"/></igsn:creatorName>
            <xsl:call-template name="party-details"/>
          </igsn:creator>
        </xsl:for-each>
        <xsl:for-each select="s:contributors/s:party">
          <igsn:contributor>
            <xsl:if test="@role"><xsl:attribute name="igsn:contributorType"><xsl:value-of select="@role"/></xsl:attribute></xsl:if>
            <igsn:contributorName igsn:nameType="{@type}"><xsl:value-of select="@name"/></igsn:contributorName>
            <xsl:call-template name="party-details"/>
          </igsn:contributor>
        </xsl:for-each>
        <igsn:publisher igsn:publisherIdentifier="{s:publisher/@identifier}" igsn:publisherIdentifierScheme="{s:publisher/@scheme}" igsn:schemeURI="{s:publisher/@uri}" igsn:lang="en"><xsl:value-of select="s:publisher"/></igsn:publisher>
      </igsn:agents>
      <igsn:entityTypeClassifications>
        <igsn:resourceType igsn:resourceTypeGeneral="PhysicalObject"><xsl:value-of select="s:sample/s:type"/></igsn:resourceType>
        <xsl:for-each select="s:sample/s:material">
          <igsn:material>
            <xsl:if test="@scheme"><xsl:attribute name="igsn:subjectScheme"><xsl:value-of select="@scheme"/></xsl:attribute></xsl:if>
            <xsl:if test="@uri"><xsl:attribute name="igsn:schemeURI"><xsl:value-of select="@uri"/></xsl:attribute></xsl:if>
            <xsl:if test="@valueUri"><xsl:attribute name="igsn:valueURI"><xsl:value-of select="@valueUri"/></xsl:attribute></xsl:if>
            <xsl:if test="@code"><xsl:attribute name="igsn:classificationCode"><xsl:value-of select="@code"/></xsl:attribute></xsl:if>
            <xsl:value-of select="."/>
          </igsn:material>
        </xsl:for-each>
        <xsl:for-each select="s:sample/s:description">
          <igsn:description igsn:descriptionType="{@type}" igsn:lang="{@language}"><xsl:value-of select="."/></igsn:description>
        </xsl:for-each>
      </igsn:entityTypeClassifications>
      <igsn:dates>
        <xsl:for-each select="s:dates/s:date">
          <igsn:date igsn:dateType="{@type}">
            <xsl:if test="@information"><xsl:attribute name="igsn:dateInformation"><xsl:value-of select="@information"/></xsl:attribute></xsl:if>
            <xsl:value-of select="."/>
          </igsn:date>
        </xsl:for-each>
        <igsn:publicationYear><xsl:value-of select="s:publicationYear"/></igsn:publicationYear>
      </igsn:dates>
      <xsl:if test="s:sample/s:archive">
        <igsn:archives><igsn:archive><igsn:archiveName><xsl:value-of select="s:sample/s:archive"/></igsn:archiveName></igsn:archive></igsn:archives>
      </xsl:if>
      <xsl:if test="s:identifiers/*">
        <igsn:relationshipIdentifiers>
          <xsl:for-each select="s:identifiers/s:alternate"><igsn:alternateIdentifier igsn:alternateIdentifierType="{@type}"><xsl:value-of select="."/></igsn:alternateIdentifier></xsl:for-each>
          <xsl:for-each select="s:identifiers/s:parent"><igsn:parentIdentifier igsn:identifierType="IGSN" igsn:relationType="IsPartOf"><xsl:value-of select="."/></igsn:parentIdentifier></xsl:for-each>
          <xsl:for-each select="s:identifiers/s:related"><igsn:relatedIdentifier igsn:identifierType="{@type}" igsn:relationType="{@relation}"><xsl:value-of select="."/></igsn:relatedIdentifier></xsl:for-each>
        </igsn:relationshipIdentifiers>
      </xsl:if>
      <xsl:if test="s:locations/*">
        <igsn:geoLocations>
          <xsl:for-each select="s:locations/s:place"><igsn:geoLocationPlace><xsl:value-of select="."/></igsn:geoLocationPlace></xsl:for-each>
          <xsl:for-each select="s:locations/s:point/s:coordinates"><igsn:geoLocationPoint igsn:coordinateSystem="WGS84"><xsl:call-template name="point"/></igsn:geoLocationPoint></xsl:for-each>
          <xsl:for-each select="s:locations/s:box/s:coordinates">
            <igsn:geoLocationBox igsn:coordinateSystem="WGS84">
              <daci:westBoundLongitude><xsl:value-of select="@west"/></daci:westBoundLongitude>
              <daci:eastBoundLongitude><xsl:value-of select="@east"/></daci:eastBoundLongitude>
              <daci:southBoundLatitude><xsl:value-of select="@south"/></daci:southBoundLatitude>
              <daci:northBoundLatitude><xsl:value-of select="@north"/></daci:northBoundLatitude>
            </igsn:geoLocationBox>
          </xsl:for-each>
          <xsl:if test="s:locations/s:polygon">
            <igsn:geoLocationPolygon><xsl:for-each select="s:locations/s:polygon/s:coordinates"><igsn:polygonPoint igsn:coordinateSystem="WGS84"><xsl:call-template name="point"/></igsn:polygonPoint></xsl:for-each></igsn:geoLocationPolygon>
          </xsl:if>
        </igsn:geoLocations>
      </xsl:if>
      <xsl:if test="s:funding/s:funder">
        <igsn:fundingReferences>
          <xsl:for-each select="s:funding/s:funder">
            <igsn:fundingReference>
              <igsn:funderName><xsl:value-of select="@name"/></igsn:funderName>
              <xsl:for-each select="s:identifier">
                <igsn:funderIdentifier igsn:funderIdentifierType="{@type}">
                  <xsl:if test="@uri"><xsl:attribute name="igsn:schemeURI"><xsl:value-of select="@uri"/></xsl:attribute></xsl:if>
                  <xsl:value-of select="."/>
                </igsn:funderIdentifier>
              </xsl:for-each>
              <xsl:for-each select="s:award">
                <igsn:awardNumber>
                  <xsl:if test="@uri"><xsl:attribute name="igsn:awardURI"><xsl:value-of select="@uri"/></xsl:attribute></xsl:if>
                  <xsl:value-of select="."/>
                </igsn:awardNumber>
              </xsl:for-each>
              <xsl:for-each select="s:title"><igsn:awardTitle><xsl:value-of select="."/></igsn:awardTitle></xsl:for-each>
            </igsn:fundingReference>
          </xsl:for-each>
        </igsn:fundingReferences>
      </xsl:if>
      <xsl:if test="s:language"><igsn:language><xsl:value-of select="s:language"/></igsn:language></xsl:if>
    </igsn:resource>
  </xsl:template>
  <xsl:template name="party-details">
    <xsl:if test="@given"><igsn:givenName><xsl:value-of select="@given"/></igsn:givenName></xsl:if>
    <xsl:if test="@family"><igsn:familyName><xsl:value-of select="@family"/></igsn:familyName></xsl:if>
    <xsl:for-each select="s:identifier">
      <igsn:nameIdentifier nameIdentifierScheme="{@scheme}">
        <xsl:if test="@uri"><xsl:attribute name="schemeURI"><xsl:value-of select="@uri"/></xsl:attribute></xsl:if>
        <xsl:value-of select="."/>
      </igsn:nameIdentifier>
    </xsl:for-each>
    <xsl:for-each select="s:affiliation">
      <igsn:affiliation>
        <xsl:if test="@identifier"><xsl:attribute name="affiliationIdentifier"><xsl:value-of select="@identifier"/></xsl:attribute><xsl:attribute name="affiliationIdentifierScheme"><xsl:value-of select="@scheme"/></xsl:attribute></xsl:if>
        <xsl:if test="@uri"><xsl:attribute name="schemeURI"><xsl:value-of select="@uri"/></xsl:attribute></xsl:if>
        <xsl:value-of select="."/>
      </igsn:affiliation>
    </xsl:for-each>
  </xsl:template>
  <xsl:template name="point">
    <daci:pointLongitude><xsl:value-of select="@longitude"/></daci:pointLongitude>
    <daci:pointLatitude><xsl:value-of select="@latitude"/></daci:pointLatitude>
  </xsl:template>
</xsl:stylesheet>
