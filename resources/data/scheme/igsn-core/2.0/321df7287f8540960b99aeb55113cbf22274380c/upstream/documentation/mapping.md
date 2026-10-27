| core position | IGSN Core by GFZ Dataservices| DataCite | IGSN Descriptive V1.3| IGSN Supplemental V1.3 | IGSN Registration V1.3 | template |
| :--: | :--: | :--: | :--: | :--: | :--: | :--: |
| 1 | `<identifier>` |[`<identifier>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/identifier/#id1) | `<identifier>` (ID 1) | `<igsn>` (ID 4) | `<sampleNumber>` (ID 1) | IGSN |
| 1.1 | `@identifierType` | [@identifierType](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/identifier/#a-identifiertype) | `@type` (ID 1.1)` | -  | `<identifierType>` (ID 1.1) | - |
| 2 | `<name>` | [`<title>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/title/)| `<name>` (ID 2) | `<name>`(ID 3), `<sample_other_names>`(ID 77), `<sample_other_name>`(ID 77.1) | -  | Sample Name, Other Names |
| 2.1 | `@titleType` | [@titleType](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/title/#a-titletype)  | -  | -  | -  | - |
| 2.2 | `@lang` | [ `xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/title/#lang-xml-lang) | - | - | - |
| 3 | `<agents>` | - |  `<contributers>` (ID 10) | - | - | - |
| 3.1 | `<creator>` | [`<creator>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/) | `<collector>` (ID 9) | - | - | - |
| 3.1.1 | `@creatorType` | - | - | - | - | - |
| 3.1.2 | `<creatorName>` | [`<creatorName>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#creatorname) | `<collector>` (ID 9), `<name>` (ID 9.2) | `<sampled_by>` (ID 8), `<collector>` (ID 59) | - | PI |
| 3.1.2.1 | `@nameType` | [`@nameType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#a-nametype) | - | - | - | - |
| 3.1.2.2 | `@lang` | [`xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#lang-xml-lang) | - | - | - | - |
| 3.1.3 | `<givenName>` | [`<givenName>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#givenname) | `<name>` (ID 9.2) | - | - | PI - Last name |
| 3.1.4 | `<familyName>`  | [`<familyName>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#familyname) | `<name>` (ID 9.2) | - | - | PI - First name |
| 3.1.5 | `<nameIdentifier>`  | [`<nameIdentifier>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#nameidentifier) | `<identifier>` (ID 9.1) | - | - | ORCID |
| 3.1.5.1 |  `@nameIdentifierScheme` | [`@nameIdentifierScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#a-nameidentifierscheme) | `@type` (ID 9.1.1) | - | `@nameIdentifierScheme` (ID 2.2.1) | - |
| 3.1.5.2 | `@schemeURI`  | [`<schemeURI>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#b-schemeuri) | - | - | - | - |
| 3.1.6 | `<affiliation>` | [`<affiliation>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#affiliation) | `<affilation>` (ID 9.3), `<name>` (ID 9.3.2) | `<collector_detail>` (ID 60) | -  | Institute |
| 3.1.6.1 | `@affiliationIdentifier`  | [`@affiliationIdentifier`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#a-affiliationidentifier) | `<identifier>` (ID 9.3.1) | - | - | - |
| 3.1.6.2 | `@affiliationIdentifierScheme`  |[`@affiliationIdentifierScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#b-affiliationidentifierscheme) | `@type` (ID 9.3.1.1) | - | - | - |
| 3.1.6.3 | `@schemeURI`  | [`<schemeURI>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/#c-schemeuri) | - | - | - | - |
| 3.2 | `<contributor>` | [`<contributor>` ](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#contributor)  | `<contributor>` (ID 10.1)  | `<operators>` (ID 57)  | - | Contributor, Drilling operator |
| 3.2.1 | `@contributorType`  |[`@contributorType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#a-contributortype)  | `@type` (ID 10.1.1)  | -  | - | Contributor type |
| 3.2.2 | `<contributorName>`  | [`<contributorName>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#contributorname) | `<name>` (ID 10.1.2.2) | `<operator>` (ID 57.1) | - | - |
| 3.2.2.1 | `@nameType` | [`@nameType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#a-nametype) | - | - | - | - |
| 3.2.2.2 | `@lang` |  [`xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#lang-xml-lang) | -  | - | - | - |
| 3.2.3 | `<givenName>`  | [`<givenName>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#givenname) | `<name>`(ID 10.1.2.2)  | - | - | - |
| 3.2.4 | `<familyName>` | [`<familyName>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#familyname) | `<name>` (ID 10.1.2.2) | - | - | - |
| 3.2.5 | `<nameIdentifier>`  | [`<nameIdentifier>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#nameidentifier) | `<identifier>` (ID 10.1.2) | - | - | - |
| 3.2.5.1 |  `@nameIdentifierScheme` | [`@nameIdentifierScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#a-nameidentifierscheme) | `@type` (ID 10.1.2.1) | - |  `@nameIdentifierScheme` (ID 2.2.1) | - |
| 3.2.5.2 | `@schemeURI`  | [`<schemeURI>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#a-nameidentifierscheme) | - | - | - | - |
| 3.2.6 | `<affiliation>` | [`<affiliation>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#affiliation) | - | - | - | - |
| 3.2.6.1 | `@affiliationIdentifier` | [`@affiliationIdentifier`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#a-affiliationidentifier) | - | - | - | - |
| 3.2.6.2 | `@affiliationIdentifierScheme`  | [`@affiliationIdentifierScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#b-affiliationidentifierscheme) | - | - | - | - |
| 3.2.6.3 | `@schemeURI`  | [`<schemeURI>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/#c-schemeuri) | - | - | - | - |
| 3.3 | `<publisher>`   | [`<publisher>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/publisher/#publisher)   | `<registrant>` (ID 8), `<name>` (ID 8.2) | -  | `<registrantName>` (ID 2)  | - |
| 3.3.1 | `@publisherIdentifier` | [`@publisherIdentifier`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/publisher/#a-publisheridentifier) | `<identifier>` (ID 8.1)  | -  | `<nameIdentifier>` (ID 2.2) | - |
| 3.3.2 | `@publisherIdentifierScheme`  | [`@publisherIdentifierScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/publisher/#b-publisheridentifierscheme)  | `@type` (ID 8.1.1) | -  |  `@nameIdentifierScheme` (ID 2.2.1) | - |
| 3.3.3 | `@schemeURI`  | [`<schemeURI>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/publisher/#c-schemeuri) | - | - | - | - |
| 3.3.4 | `@lang` |  [`xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/publisher/#lang-xml-lang)  | - | - | - | - |
| 4 | `<entityTypeClassifications>`   | - | `<resourceTypes>` (ID 12), `<alternateResourceTypes>` (ID 12.2), `<materials>` (ID 13), `<alternateMaterials>` (ID 13.2) | `<descriptions>` (ID 44) | -  | - |
| 4.1 | `<resourceType>`  | [`<resourceType>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/resourcetype/)  | `@type` (ID resource) | - | - | - |
| 4.1.1 | `@resourceTypeGeneral`  | [`@resourceTypeGeneral`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/resourcetype/#a-resourcetypegeneral)  | - | - | - | - |
| 4.2 | `<sampleTypeClassificationPlaceHolder>` |[ `<subjects>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#subject)   | - | - | - | - |
| 4.3 | `<material>` | [`<subject>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#subject) | `<material>` (ID 13.1) | `<sample_type>` (ID 2), `<material>` (ID 30), `<field_name>` (ID 33) | - | Sample type, Material
 |
| 4.3.1 | `@subjectScheme`  | [`@subjectScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#a-subjectscheme) | - | - | - | - |
| 4.3.2 | `@schemeURI`  | [`@schemeURI`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#b-schemeuri)  | - | - | - | - |
| 4.3.3 | `@valueURI` | [`@valueURI`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#c-valueuri) | - | - | - | - |
| 4.3.4 | `@classificationCode` | [`@classificationCode`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#d-classificationcode) | - | - | - | - |
| 4.3.5 | `@lang` |  [`xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#lang-xml-lang)  | - | - | - | - |
| 4.4 | `<alternateMaterial>`   | [`<subject>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#subject) | `<alternateMaterial>` (ID 13.2.1) | - | - | Alternate material |
| 4.4.1 | `@subjectScheme`  | [`@subjectScheme`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#a-subjectscheme) | - | - | - | - |
| 4.4.2 | `@schemeURI`  | [`@schemeURI`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#a-subjectscheme) | - | - | - | - |
| 4.4.3 | `@valueURI` | [`@valueURI`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#c-valueuri) | - | - | - | - |
| 4.4.4 | `@classificationCode` | [`@classificationCode`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#d-classificationcode) | -  | -  | -  | - |
| 4.4.5 | `@lang` |  [`xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/subject/#lang-xml-lang) | - | - | - | - |
| 4.5 | `<description>` | [`<description>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/description/#description)   | `<description>` (ID 7) | `<location_description>` (ID 23), `<locality_description>` (ID 25), `<classification_comment>` (ID 32), `<description>` (ID 44.1), `<collection_method_descr>` (ID 49), `<platform_descr>` (ID 56), `<sample_comment>` (ID 52) | -  | Sample description, Collection method description, Locality description |
| 4.5.1 | `@descriptionType`  | [`@descriptionType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/description/#a-descriptiontype)  | - | `@descriptionScheme` (ID 44.1.1) | -  | - |
| 4.5.2 | `@lang` |  [`xml:lang`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/description/#lang-xml-lang)  | -  | -  | -  | - |
| 5 | `<dates>`   | [`<dates>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/date/#date) | -  | -  | -  | - |
| 5.1 | `<date>`  | [`<date>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/date/#date)  | `<collectionTime>` (ID 15) | `<sampling_date>` (ID 20), `<collection_start_date>` (ID 61), `<collection_end_date>` (ID 62)  | -  | Sampling date start, Sampling date end |
| 5.1.1 | `@dateType` | [`@dateType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/date/#a-datetype) | -  | -  | -  | - |
| 5.1.2 | `@dateInformation`  | [`@dateInformation`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/date/#b-dateinformation)  | -  | -  | -  | Time zone, Sampling date precision |
| 5.2 | `<publicationYear>`   | [`<publicationYear>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/publicationyear/#publicationyear)   | -  | `<publish_date>` (ID 10) | `@timeStamp` (ID 4.1.2)  | - |
| 6 | `<access>`  | -  | `<sampleAccess>` (ID 16) | `<is_privat>` (ID 6) | -  | Private/public IGSN status, Sample access |
| 6.1 | `@accessType` | - | `<sampleAccess>` (ID 16) | `<is_privat>` (ID 6) | -  | Private/public IGSN status |
| 7 | `<archives>`  | - | -  | `<current_archive>` (ID 64), `<original_archive>` (ID 66)  | -  | - |
| 7.1 | `<archiveName>` | - | -  | `<current_archive>` (ID 64), `<original_archive>` (ID 66)  | -  | Current archive, Original archive |
| 7.1.1 | `@lang` |  -  | -  | -  | -  | - |
| 7.1.2 | `<nameIdentifier>`  | -  | - | - | -  | - |
| 7.1.2.1 |  `@nameIdentifierScheme` | - | - | - | - | - |
| 7.1.2.2 | `@schemeURI`  | - | - | - | -  | - |
| 7.2 | `<geoLocationPlace>`  | - | -  | -  | -  | - |
| 7.2.1 | `@lang` |  `xml:lang`  | -  | -  | -  | - |
| 7.2.2 | `<nameIdentifier>`  | - | - | - | -  | - |
| 7.2.2.1 | `@nameIdentifierScheme` | - | - | - | - | - |
| 7.2.2.2 | `@schemeURI`  | - | - | - | - | - |
| 7.3 | `<geoLocationPoint>`  | - | - | - | - | - |
| 7.3.1 | `@coordinateSystem` | - | - | - | - | - |
| 7.3.2 | `<pointLongitude>`  | - | -  | - | - | - |
| 7.3.3 | `<pointLatitude>`   | - | -  | - | - | - |
| 7.4 | `<contributor>` | - | - | - | - | Current archive contact, Original archive contact |
| 7.4.1 | `@contributorType`  | - | - | -  | - | - |
| 7.4.2 | `<contributorName>`   | - | - | - | - | - |
| 7.4.2.1 | `@nameType` | - | - | - | -  | - |
| 7.4.2.2 | `@lang` | - | -  | -  | - | - |
| 7.4.3 | `<givenName>`   | -  | - | - | - | - |
| 7.4.4 | `<familyName>`  | - | - | - | - | - |
| 7.4.5 | `<nameIdentifier>`  | - | - | - | - | - |
| 7.4.5.1 |  `@nameIdentifierScheme` | - | - | - | - | - |
| 7.4.5.2 | `@schemeURI`  | - | - | - | - | - |
| 7.4.6 | `<affiliation>` | - | - | - | - | - |
| 7.4.6.1 | `@affiliationIdentifier`  | - | - | - | - | - |
| 7.4.6.2 | `@affiliationIdentifierScheme`  | - | - | - | -  | - |
| 7.4.6.3 | `@schemeURI`  | - | - | - | - | - |
| 8 | `<relationshipIdentifiers>`   | [`<relatedIdentifiers>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#relatedidentifier) | `<alternateIdentifiers>` (ID 3), `<relatedIdentifiers>` (ID 6) | `<relatedIdentifiers>` (ID 78) | -  | - |
| 8.1 | `<alternateIdentifier>` | [`<alternateIdentifier>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/alternateidentifier/) | `<alternateIdentifier>` (ID 3.1) | -  | -  | - |
| 8.1.1 | `@alternateIdentifierType`  | [`@alternateIdentifierType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/alternateidentifier/#a-alternateidentifiertype) | `@type` (ID 3.1.1) | -  | -  | - |
| 8.2 | `<parentIdentifier>`  | [`<relatedIdentifier>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#relatedidentifier) | `<parentIdentifier>` (ID 4)  | `<parent_igsn>` (ID 5) | `<relatedResourceIdentifier>` (ID 3) | Parent IGSN |
| 8.2.1 | `@relationType` | [`@relationType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#b-relationtype) | - | - | `@relationType` (ID 3.2) | - |
| 8.2.2 | `@identifierType` | [`@relatedIdentifierType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#a-relatedidentifiertype) | `@type` (ID 4.1) | - | `<identifierType>` (ID 1.1), `@relatedIdentifierType` (ID 3.1) | - |
| 8.3 | `<relatedIdentifier>`   | [`<relatedIdentifier>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#relatedidentifier) | `<relatedIdentifier>` (ID 6.1) | `<relatedIdentifier>` (ID 78.1)  | `<relatedResourceIdentifier>` (ID 3) | Related Publication, Related dataset, Related Sample |
| 8.3.1 | `@relationType` | [`@relationType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#b-relationtype) | `@relationType` (ID 6.1.2) | `@relationType (ID 78.3)  | `@relationType (ID 3.2) | Relation type |
| 8.3.2 | `@identifierType` | [`@relatedIdentifierType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#a-relatedidentifiertype) | `@type` (ID 6.1.1) | `@relatedIdentifierType` (ID 78.2) | `<identifierType>` (ID 1.1), `@relatedIdentifierType` (ID 3.1) | Related  identifier type  |
| 9 | `<geoLocations>`  | [`<geoLocations>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/)  | `<geoLocations>` (ID 11) | -  | -  | - |
| 9.1 | `<geoLocationPlace>`  | [`<geoLocationPlace>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#geolocationplace)  | `<geoLocation>` (ID 11.1), `<toponym>` (ID 11.1.2), `<identifier>` (ID 11.1.2.1), `<name>` (ID 11.1.2.2) | `<primary_location_name>` (ID 22), `<locality>` (ID 24), `<country>` (ID 26), `<province>` (ID 27), `<county>` (ID 28), `<city>` (ID 29) | -  | Country, Administrative division, District, City |
| 9.1.1 | `@lang` | - | -  | -  | -  | - |
| 9.2 | `<geoLocationPoint>` | [`<geoLocationPoint>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#geolocationpoint)  | `<geometry>` (ID 11.1.1), `@type` (ID 11.1.1.1)  | -  | -  | - |
| 9.2.1 | `@coordinateSystem` | - | `@sridType` (ID 11.1.1.2)  | `<coordinate_system>` (ID 15)  | -  | Coordinate system |
| 9.2.2 | `<pointLongitude>` | [`<pointLongitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#pointlongitude) | -  | `<longitude>` (ID 12), `<longitude_end>` (ID 14) | -  | E Longitude, E Longitude end |
| 9.2.3 | `<pointLatitude>` | [`<pointLatitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#pointlatitude) | -  | `<latitude>` (ID 11), `<latitude_end>` (ID 13) | -  | N Latitude, N Latitude end |
| 9.3 | `<geoLocationBox>`  | [`<geoLocationBox>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#geolocationbox)  | `<geometry>` (ID 11.1.1), `@type` (ID 11.1.1.1)  | -  | -  | - |
| 9.3.1 | `@coordinateSystem` | - | `@sridType` (ID 11.1.1.2)  | -  | -  | - |
| 9.3.2 | `<westBoundLongitude>`  | [`<westBoundLongitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#westboundlongitude)  | -  | -  | -  | - |
| 9.3.3 | `<eastBoundLongitude>`  | [`<eastBoundLongitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#eastboundlongitude)  | -  | -  | -  | - |
| 9.3.4 | `<southBoundLatitude>`  | [`<southBoundLatitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#southboundlatitude)  | -  | -  | -  | - |
| 9.3.5 | `<northBoundLatitude>`  | [`<northBoundLatitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#northboundlatitude)  | -  | -  | -  | - |
| 9.4 | `<geoLocationPolygon>`  | [`<geoLocationPolygon>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#geolocationpolygon)  | `<geometry>` (ID 11.1.1), `@type` (ID 11.1.1.1)  | -  | -  | - |
| 9.4.1 | `<polygonPoint>`  | [`<polygonPoint>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#polygonpoint)  | -  | -  | -  | - |
| 9.4.1.1 | `@coordinateSystem` | - | `@sridType` (ID 11.1.1.2)  | `<coordinate_system>` (ID 15)  | -  | - |
| 9.4.1.2 | `<pointLongitude>`  | [`<pointLongitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#id13)  | -  | - | -  | - |
| 9.4.1.3 | `<pointLatitude>`   | [`<pointLatitude>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/geolocation/#id15) | -  | - | - | - |
| 10  | `<fundingReferences>`   | [`<fundingReferences>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#fundingreference)   | -  | - | - | - |
| 10.1  | `<fundingReference>`  | [`<fundingReference>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#fundingreference)  | -  | - | -  | - |
| 10.1.1  | `<funderName>`  | [`<funderName>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#fundername) | -  | `<funding_agency>` (ID 58) | - | Funder name |
| 10.1.2  | `<funderIdentifier>`  | [`<funderIdentifier>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#funderidentifier) | -  | `<funding_agency>` (ID 58) | -  | Funder identifier |
| 10.1.2.1  | `@funderIdentifierType` |[ `@funderIdentifierType`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#a-funderidentifiertype) | -  | `<funding_agency>` (ID 58) | -  | - |
| 10.1.2.2  | `@schemeURI`  | [`<schemeURI>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#b-schemeuri) | - | - | -  | - |
| 10.1.3  | `<awardNumber>` | [`<awardNumber>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#awardnumber) | -  | `<funding_agency>` (ID 58) | -  | Award number |
| 10.1.3.1  | `@awardURI` | [`@awardURI`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#a-awarduri) | -  | `<funding_agency>` (ID 58) | -  | - |
| 10.1.4  | `<awardTitle>`  | [`@awardTitle`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/#awardtitle) | -  | -  | -  | Award title |
| 11  | `<language>`  | [`<language>`](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/language/#language) | -  | -  | -  | - |