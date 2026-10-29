# IGSN Core Documentation

# 1. `<identifier>` *mandatory Element*
``` xml 
<igsn:identifier identifierType="DOI">GFBNO7002EXH4001</igsn:identifier>

```
- **Obligation**: mandatory
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
-  **Definition**: The Identifier is a unique string that identifies a resource. IGSN (International GeoSample Number) registered by an IGSN member.
- **Purpose and meaning**: This element is used to store the identifier of the resource, thereby ensuring that the resource can be uniquely identified


## 1.1 `@identifierType` *mandatory Attribute*
- **Data type**: daci:relatedIdentifierType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: The type of Identifier.
- **Purpose and meaning**: Mandatory for registration with DataCite. This attribute ensures that the type of the identifier can be specified correctly. This is necessary to ensure error-free machine processing


---
---



# 2. `<name>` *mandatory Element*
``` xml 
<igsn:name titleType="AlternativeTitle" lang="en">WR_12-15</igsn:name>
```
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..n
- **Definition**: This element contains the common, humanreadable name of the resource described.
- **Purpose and meaning**: This element is used to name and identify a resource in a technical and linguistic context. It refers to the resource by its common name and is designed to be readable by humans. The name element should be used when the common name of the resource must be specified. The primary name of the resource should be entered. Variant spellings, abbreviated forms or alternative names should only be used if they correspond to the officially used or technically authoritative name. The element does not need to be unique and may occur multiple times.
- **Additional information**: In order to clearly distinguish between `<title>`, `<name>` and `<alternateIdentifier>` within the schema, the decision was made not to align the element identifier `<name>` with DataCite's `<title>`, even though the `<name>` element is mapped to `<daci:title>` for registration purposes. The term ‘title’ summarises the content, attracts attention and serves as a structural guide for the reader. Here, however, `<name>` is used to capture all human-readable designations for the sample and to generate the title that corresponds to the registered resource.

## 2.1 `@titleType` *optional Attribute*
- **Data type**: daci:titleType
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: This attribute is used to specify the type of entry contained within the [`<name>`](#2-name-mandatory-element) element.
- **Purpose and meaning**: It is used to distinguish the title of the registered sample from the sample’s common names.

## 2.2 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.

---
---


# 3 `<agents>` *mandatory Element*
``` xml 
<igsn:agents>
		<igsn:creator creatorType="creator">
			<igsn:creatorName nameType="Personal" lang="en">Antipanova, Tatjana</igsn:creatorName>
			<igsn:givenName>Tatjana</igsn:givenName>
			<igsn:familyName>Antipanova</igsn:familyName>
			<igsn:nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">0000-0001-5140-8602</igsn:nameIdentifier>
			<igsn:affiliation affiliationIdentifier="https://ror.org/04z8jg394" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org">GFZ Helmholtz Centre for Geosciences</igsn:affiliation>
		</igsn:creator>
		<igsn:creator creatorType="collector">
			<igsn:creatorName nameType="Organizational" lang="en">GFZ Dataservices</igsn:creatorName>
			<igsn:nameIdentifier nameIdentifierScheme="ROR" schemeURI="https://ror.org">https://ror.org/04z8jg394</igsn:nameIdentifier>
		</igsn:creator>
		<igsn:contributor contributorType="ContactPerson">
			<igsn:contributorName nameType="Personal" lang="en">Antipanova, Tatjana</igsn:contributorName>
			<igsn:givenName>Tatjana</igsn:givenName>
			<igsn:familyName>Antipanova</igsn:familyName>
            <igsn:nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">0000-0001-5140-8602</igsn:nameIdentifier>
			<igsn:affiliation affiliationIdentifier="https://ror.org/04z8jg394" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org">GFZ Helmholtz Centre for Geosciences</igsn:affiliation>
		</igsn:contributor>
		<igsn:contributor contributorType="RegistrationAuthority">
			<igsn:contributorName nameType="Organizational" lang="en">GFZ Dataservices</igsn:contributorName>
            <igsn:nameIdentifier nameIdentifierScheme="ROR" schemeURI="https://ror.org">https://ror.org/04z8jg394</igsn:nameIdentifier>
		</igsn:contributor>
		<publisher publisherIdentifier="https://doi.org/10.17616/R3VQ0S" publisherIdentifierScheme="re3data" schemeURI="https://re3data.org/" xml:lang="en">GFZ Data Services</publisher>
	</igsn:agents>
```
- **Data type**: xs:complexType
- **Content**: No entries expected
- **Occurrence**: 1..1
-  **Definition**: A wrapper element that bundles all elements relating to specific persons and organizations
- **Purpose and meaning**: This wrapper is designed to group together all the agents (people and organizations) involved in the resource and make them available in a single location within the schema
- **Additional information**: As part of the harmonisation process, the elements relating to the people involved in the publication were grouped together to improve readability. This has made the schema easier to reuse and understand.

## 3.1 `<creator>` *mandatory Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 1..n
- **Definition**: The creator wrapper contains a list of the main researcher(s) involved in priority order. For IGSN IDs, this could be the sample collector/creator, chief scientist, curator, or even the person who deposited the sample into a repository.
- **Purpose and meaning**: This element is required for registering the sample with DataCite; it contains all the information needed to uniquely identify the collectors and/or creators of the resource
- **Additional information**: Even though it may seem counterintuitive that the person who collected a sample should be considered as its creator, this must be seen within the context of different reference framework. Since the person collecting the sample is the first to put it within the reference framework of a “scientifically analysable sample,” that person is therefore the creator of the sample.

### 3.1.1 `@creatorType` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Function of the creator regarding the project.
- **Purpose and meaning**: This type attribute is used to build the landing pages and credit the persons according to their function. The type options are `creator` and `collector`.
- **Additional information**: This attribute does not exist in DataCite or in previous versions of the schema. It was designed to allow the `<collector>` and `<sample_by>` elements to be grouped within the `<creator>` element, whilst still permitting differentiation so that landing pages can be structured accordingly. Enumeration list: collector, creator


### 3.1.2 `<creatorName>` *mandatory Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: Full Name of the creator
- **Purpose and meaning**: This element stores the full name of the relevant creator. Full name in the format "family name, given name".


#### 3.1.2.1 `@nameType` *mandatory Attribute*
- **Data type**: daci:nameType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: This attribute is used to specify the type of entry contained within the [`<name>`](#2-name-mandatory-element) element.
- **Purpose and meaning**: The type of name. Enumeration list values: `organizational`,`personal`



#### 3.1.2.2 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language. Use only if [`@nameType`](#3121-nametype-mandatory-attribute) is `organizational`



### 3.1.3 `<givenName>` *optional Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Given name of the Person
- **Purpose and meaning**: Use only if [`@nameType`](#3121-nametype-mandatory-attribute) is `personal`


### 3.1.4 `<familyName>` *optional Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Family Name of the person
- **Purpose and meaning**: Use only if [`@nameType`](#3121-nametype-mandatory-attribute) is `personal`


### 3.1.5 `<nameIdentifier>` *optional Element*
- **Data type**: daci:nameIdentifier
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies an individual or legal entity, according to various schemes. ORCID is pref for Person ROR is pref for Organizations
- **Purpose and meaning**: This element contains the identifier, which is used to uniquely identify the person or organization


#### 3.1.5.1 `@nameIdentifierScheme` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the name identifier scheme.
- **Purpose and meaning**: This attribute enables the `<nameIdentifier>` to be interpreted unambiguously and associated with a standardised identification system, such as ORCID, ISNI or ROR.

#### 3.1.5.2 `@schemeURI`*optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


### 3.1.6 `<affiliation>` *optional Element*
- **Data type**: daci:affiliation
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: The organizational or institutional affiliation of the creator.
- **Purpose and meaning**: This element is used to indicate the organization or institution the agent is affiliated with

#### 3.1.6.1 `@affiliationIdentifier` *optional Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies the organizational affiliation of the creator or contributor
- **Purpose and meaning**: This attribute is used to uniquely identify the affiliation

#### 3.1.6.1 `@affiliationIdentifierScheme` *optional Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: The name of the affiliation identifier scheme.
- **Purpose and meaning**: This attribute enables the `@affilationIdentifier` attribute to be interpreted unambiguously and associated with a standardised identification system.

#### 3.1.6.3 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the affilation identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


## 3.2 `<contributor>` *optional Element*
- **Data type**: xs:complexType
- **Occurrence**: 0..n
- **Definition**: A wrapper element that bundles all elements and attributes relating to specific persons with the function contributor
- **Purpose and meaning**: A bundle of all the elements and attributes required to uniquely identify a contributor

### 3.2.1 `@contributorType` *mandatory Attribute*
- **Data type**: daci:contributorType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: The type of contributor of the resource.
- **Purpose and meaning**: Select a suitable item from the enumeration list; if a person contributes in more than one way and is to be represented, a further contributor must be created with the appropriate @contributorType
- **Additional information**: Enumeration List: ContactPerson, DataCollector, DataCurator, DataManager, Distributor, Editor, HostingInstitution, Producer, ProjectLeader, ProjectManager, ProjectMember, RegistrationAgency, RegistrationAuthority, RelatedPerson, Researcher, ResearchGroup, RightsHolder, Sponsor, Supervisor, Translator, WorkPackageLeader, Other


### 3.2.2 `<contributorName>` *mandatory Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: Full name of the contributor
- **Purpose and meaning**: The institution or person responsible for collecting, managing, distributing, or otherwise contributing to the development of the resource. To supply multiple contributors, repeat this property.


#### 3.2.2.1 `@nameType` *mandatory Attribute*
- **Data type**: daci:nameType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: This attribute is used to specify the type of entry contained within the [`<contributorName>`](#322-contributorname-mandatory-element) element.
- **Purpose and meaning**: The type of name. Enumeration list values: `organizational`,`personal`


#### 3.2.2.2 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language. This is listed as a required element only for the “Description” field, as a large amount of free text is entered there and it is therefore essential to specify the language used


### 3.2.3 `<givenName>` *optional Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Given Name of the Person
- **Purpose and meaning**: This element is used to retrieve the first name of the person from the `<contributor>` element in normalised format.


### 3.2.4 `<familyName>` *optional Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Family Name of the Person
- **Purpose and meaning**: This element is used to retrieve the person’s surname from `<contributor>` element in normalised form


### 3.2.5 `<nameIdentifier>` *optional Element*
- **Data type**: daci:nameIdentifier
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies an individual or legal entity, according to various schemes. ORCID is pref for Person ROR is pref for Organizations
- **Purpose and meaning**: This element contains the identifier, which is used to uniquely identify the person or organization given in the `<contributor>` Element. Only the identifier should be entered in this field; the full URL is taken from the `@schemeURI` attribute and processed, so only the identifier needs to be entered here


#### 3.2.5.1 `@nameIdentifierScheme` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the name identifier scheme. Only Mandatory if [`<nameIdentifier>`](#315-nameidentifier-optional-element) is used.
- **Purpose and meaning**: This attribute enables the [`<nameIdentifier>`](#315-nameidentifier-optional-element) to be interpreted unambiguously and associated with a standardised identification system, such as ORCID, ISNI or ROR.

#### 3.2.5.2 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


### 3.2.6 `<affiliation>` *optional Element*
- **Data type**: daci:affiliation
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: The organizational or institutional affiliation of the person.
- **Purpose and meaning**: This element is used to indicate the organization or institution the agent is affiliated with

#### 3.2.6.1 `@affiliationIdentifier` *optional Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies the organizational affiliation of the person
- **Purpose and meaning**: This attribute is used to uniquely identify the affiliation

#### 3.2.6.2 `@affiliationIdentifierScheme` *optional Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the affiliation identifier scheme.
- **Purpose and meaning**: This attribute enables the `@affilationIdentifier` attribute to be interpreted unambiguously and associated with a standardised identification system.

#### 3.2.6.3 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the affilation identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


## 3.3 `<publisher>` *mandatory Element*
- **Data type**: xs:complexType
- **Occurrence**: 1..1
- **Definition**: This is the organization that registered the IGSN for this resource. It is also the entity that minted and manages this IGSN record.
- **Purpose and meaning**: Necessary to provide a complete and DataCite-compliant statement identifying the publishing institution

### 3.3.1 `@publisherIdentifier` *mandatory Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 1..1
- **Definition**: Uniquely identifies the publisher, according to various schemes.
- **Purpose and meaning**: Similar to `<nameIdentifier>` or `@affiliationIdentifier`, this attribute is used to store the persistent identifier.  Although [`<nameIdentifier>`](#315-nameidentifier-optional-element) is modelled as an element and this attribute corresponds to the same concept in terms of content, it has been defined here as an attribute because the DataCite `<publisher>` element is being reused. This does result in a conceptual inconsistency, but compliance with DataCite is maintained.

### 3.3.2 `@publisherIdentifierScheme` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: The name of the publisher identifier scheme.
- **Purpose and meaning**: Unambiguous, machine-processable mapping of the identifier schema to enable further processing.
- **Additional information**: Enumeration List: ROR, re3data, VIAF, Wikidata, Crossref Funder ID, ISNI, OpenDOAR, FAIRsharing, ISSN,


### 3.3.3 `@schemeURI` *mandatory Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 1..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI

### 3.3.3 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.


---
---



# 4 `<entityTypeClassifications>` *mandatory Element*
``` xml 
<igsn:entityTypeClassifications>
		<igsn:resourceType resourceTypeGeneral="PhysicalObject">SamplingFeature</igsn:resourceType>
		<igsn:material subjectScheme="Medium Vocabulary" schemeURI="http://vocabulary.odm2.org/medium" valueURI="http://vocabulary.odm2.org/medium/liquidAqueous" lang="en">Liquid aqueous</igsn:material>
		<igsn:alternateMaterial subjectScheme="Classification (Rock)" schemeURI="https://www.geosamples.org/vocabularies/classification-rock" lang="en">Igneous</igsn:alternateMaterial>
		<igsn:description descriptionType="Methods" lang="de">Drill>Electric>Holesaw
        </igsn:description>
	</igsn:entityTypeClassifications>
```
- **Data type**: xs:complexType
- **Content**: No entries expected
- **Occurrence**: 1..1
-  **Definition**: Contains elements that describe the basic form of the registered sample.
- **Purpose and meaning**: Wrapper to hold all classification information about the resource


## 4.1 `<resourceType>` *mandatory Element*
- **Data type**: xs:complexType
- **Occurrence**: 1..1
- **Definition**: Describe the basic form of the object that is registered.
- **Purpose and meaning**: An element declared as mandatory by DataCite.  A description of the resource. The resourceType property may be populated with resource types from external ontologies or shared vocabularies. In the absence of an agreed vocabulary, the use of the terms **material sample** or **feature-of-interest** are strongly recommended to at least distinguish between these sampling concepts. A material sample is a specialization of a larger feature-of-interest, which is typically the collection site. For example, in the Geosciences, a feature-of-interest might be a lake, tree, cross-section, transect, or borehole.

## 4.1.1 `@resourceTypeGeneral` *mandatory Attribute*
- **Data type**: daci:resourceType 
- **Occurrence**: 1..1
- **Definition**: The general type of a resource.
- **Purpose and meaning**: This attribute is required for DataCite-compliant registration. Value is fixed to "PhysicalObject"


## 4.2 `<material>` *optional Element*
- **Data type**: xs:sting
- **Occurrence**: 0..n
- **Definition**:Subject or keyword describing the resource.
- **Content**: enumeration list
- **Purpose and meaning**: If none of the materials available via the VOC are suitable, you can select ‘Other’ here to use the free-text field in ‘alternateMaterial’ for your entry
- **Additional information**: The elements `<material>`, `<classification>` and `<alternateMaterial>` are all mapped to the `<subject>` element in DataCite; however, to ensure that the elements can be correctly addressed for the creation of landing pages, they have been modelled in this schema version consistently with the element names of the previous versions. 


### 4.2.1 `@subjectScheme` *optional Attribute*
- **Data type**: xs:sting
- **Occurrence**: 0..1
- **Definition**: The name of the subject scheme or authority if one is used.
- **Content**: free text
- **Additional information**: To simplify reuse and mapping to DataCite, the attributes were not renamed; instead, they were reused in the form in which they appear in DataCite.

### 4.2.2 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI

### 4.2.3 `@valueURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the subject term.
- **Purpose and meaning**: To unambiguously identify the term used in the element, the URI of the term can be stored in this attribute. If applicable, provide the direct URI or URL for the term used. If the vocabulary does not provide separate URIs for individual terms but instead uses IDs to identify them uniquely, the `@classificationCode` attribute can be used to provide this information.

### 4.2.4 `@classificationCode` *optional Attribute*
- **Data type**: sx:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: The classification code used for the subject term in the subject scheme.
- **Purpose and meaning**: If applicable, provide the ID for the term used. If the vocabulary does not provide IDs for individual terms but they are presented on persistent URIs, use the @valueURI attribute to provide this information.
- **Additional information**: Different vocabularies use different identifiers for their classification codes. The information you are looking for may therefore be hidden behind the following terms: UUID, termID, vocab-term-identifier, itemNumber, conceptID, key

### 4.2.5 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.


## 4.3 `<alternateMaterial>` *optional Element*
- **Data type**: xs:sting
- **Occurrence**: 0..n
- **Content**: enumeration list
- **Definition**: Subject, keyword, classification code, or key phrase describing the resource.
- **Purpose and meaning**: Element to alow material classification for resources that do not match the available material vocabularies.


### 4.3.1 `@subjectScheme` *optional Attribute*
- **Data type**: xs:sting
- **Occurrence**: 0..1
- **Definition**:The name of the subject scheme or authority if one is used.
- **Content**: free text
- **Additional information**: To simplify reuse and mapping to DataCite, the attributes were not renamed; instead, they were reused in the form in which they appear in DataCite.

### 4.3.2 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI

### 4.3.3 `@valueURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the subject term.
- **Purpose and meaning**: To unambiguously identify the term used in the element, the URI of the term can be stored in this attribute. If applicable, provide the direct URI or URL for the term used. If the vocabulary does not provide separate URIs for individual terms but instead uses IDs to identify them uniquely, the `@classificationCode` attribute can be used to provide this information.

### 4.3.4 `@classificationCode` *optional Attribute*
- **Data type**: sx:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: The classification code used for the subject term in the subject scheme.
- **Purpose and meaning**: If applicable, provide the ID for the term used. If the vocabulary does not provide IDs for individual terms but they are presented on persistent URIs, use the @valueURI attribute to provide this information.
- **Additional information**: Different vocabularies use different identifiers for their classification codes. The information you are looking for may therefore be hidden behind the following terms: UUID, termID, vocab-term-identifier, itemNumber, conceptID, key

### 4.3.5 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.


## 4.4 `<description>` *optional Element*
- **Data type**: xs:sting
- **Occurrence**: 0..n
- **Content**: free text
- **Definition**: Descriptive text about the sample. Free text, anything else that might be useful to know about the sample at its 'birth'. 
- **Purpose and meaning**: This element is used to provide further details about the resource to be registered. All Additional information that does not fit in any of the other categories. May be used for technical information or detailed information associated with the sample. Use a diffrent description element for every description you like to add, eg abstract, thethods, technical information, locality, collection. It is a best practice to supply a description.


### 4.4.1 `@descriptionType` *mandatory Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: The type of the Description.
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.
- **Additional information**: If **other** is selected, the description must clearly state what it refers to, to ensure that it is processed correctly and appears in the right place on the landing page.

### 4.4.2 `@lang` *manatory Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.
- **Additional information**: As the [`<description>`](#45-description-optional-element) can be repeated and contains the largest amount of natural language text this attribute is mandatory.


---
---



## 5 `<dates>` *mandatory Element*
``` xml
    <igsn:dates>
		<igsn:date dateType="Collected">2026-10-31</igsn:date>
		<igsn:date dateType="Other" dateInformation="Destroyed">2026-11-11</igsn:date>
		<igsn:date dateType="Available">2026-12-01</igsn:date>
		<igsn:publicationYear>2026</igsn:publicationYear>
	</igsn:dates>
```
- **Data type**: xs:complexType
- **Occurrence**: 1..1
- **Definition**: Contains all the dates, in regads to the sampel and the metadata
- **Purpose and meaning**: The wrapper does not contain any content of its own, but simply bundles all elements which are necessary to describe dates regarding to the sample

### 5.1 `<date>` *optional Element*
- **Data type**: xs:sting
- **Content**: date in the format: YYYY, YYYY-MM-DD, YYYY-MM-DDThh:mm:ssTZD
- **Occurrence**: 0..n
- **Definition**: Sny date relevant for the samle or the metadata entry


#### 5.1.1 `@dateType` *mandatory Attribute*
- **Data type**: daci:dateType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Enumeration list of possible dateTypes
- **Purpose and meaning**: This attribute is used to provide a more detailed description of the `<date>` element in order to unambiguously identify what the `<date>` element refers to

#### 5.1.1 `@dateInformation` *optional Attribute*
- **Data type**: xs:sting
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Specific information about the date
- **Purpose and meaning**: If you select **Other** for [`@dateType`](##511-datetype-mandatory-attribute), please use this attribute specify the context of the date


### 5.2 `<publicationYear>` *mandatory Element*
- **Data type**: daci:yearType
- **Content**: Year of publikation in the format YYYY
- **Occurrence**: 1..1
- **Definition**: Year when the data was or will be made publicly available, required for registration with Datatcite


---
---




## 6 `<archives>` *optional Element*
``` xml
	<igsn:archives>
		<igsn:archive>
			<igsn:archiveName lang="en">String</igsn:archiveName>
			<igsn:geoLocationPlace lang="en">String</igsn:geoLocationPlace>
			<igsn:geoLocationPlace lang="en">String</igsn:geoLocationPlace>
			<igsn:geoLocationPoint coordinateSystem="WGS84">
				<daci:pointLongitude>-180</daci:pointLongitude>
				<daci:pointLatitude>-90</daci:pointLatitude>
			</igsn:geoLocationPoint>
			<igsn:contactPerson contributorType="ContactPerson">
				<igsn:contributorName nameType="Organizational" lang="en">String</igsn:contributorName>
				<igsn:givenName>String</igsn:givenName>
				<igsn:familyName>String</igsn:familyName>
				<igsn:nameIdentifier nameIdentifierScheme="String" schemeURI="http://www.altova.com/">a</igsn:nameIdentifier>
				<igsn:affiliation affiliationIdentifier="String" affiliationIdentifierScheme="ROR" schemeURI="http://www.altova.com/">a</igsn:affiliation>
				<igsn:email>String</igsn:email>
			</igsn:contactPerson>
			<igsn:sampleCondition>destroid</igsn:sampleCondition>
		</igsn:archive>
	</igsn:archives>
```

### 6.1 `<archive>` *optional  Element*
- **Data type**: xs:complexType
- **Occurrence**: 0..n
- **Definition**: option to specify the archive in which the sample is stored. shows where the sample is currently located.

#### 6.1.1 `<archiveName>` *mandatory Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: name of the archive
- **Purpose and meaning**: The name of the archive is a mandatory field; it is recommended that you also include an identifier in the child element `<nameIdentifier>` so that the archive can be uniquely identified

##### 6.1.1.1 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.



##### 6.1.1.2 `<nameIdentifier>` *optional Element*
- **Data type**: daci:nameIdentifier
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies an individual or legal entity, according to various schemes. ORCID is pref for Person ROR is pref for Organizations
- **Purpose and meaning**: This element contains the identifier, which is used to uniquely identify the person or organization given in the `<contributor>` Element. Only the identifier should be entered in this field; the full URL is taken from the `@schemeURI` attribute and processed, so only the identifier needs to be entered here

###### 6.1.1.2.1 `@nameIdentifierScheme>` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the name identifier scheme. Only Mandatory if [`<nameIdentifier>`](#6112-nameidentifier-optional-element) is used.
- **Purpose and meaning**: This attribute enables the [`<nameIdentifier>`](#6112-nameidentifier-optional-element) to be interpreted unambiguously and associated with a standardised identification system, such as ORCID, ISNI or ROR.

###### 6.1.1.2.2`@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


#### 6.1.2 `<geoLocationPlace>` *optional Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: Where was the sample acquired relative to the Earth (or another celestial body...). Some samples might be 'non-geographic': mineral specimen, synthetic material.
Named place where the data was gathered or about which the data is focused.
- **Purpose and meaning**: This element allows to enter a location without specifying coordinates, whilst still establishing a geographical reference
- **Additional information**: Use to name or describe a geographic location. For a detailed description of the location where the samples were collected, use the [`<description>`](#44-description-optional-element) element

##### 6.1.2.1 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.

##### 6.1.2.2 `<nameIdentifier>` *optional Element*
- **Data type**: daci:nameIdentifier
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies an individual or legal entity, according to various schemes. ORCID is pref for Person ROR is pref for Organizations
- **Purpose and meaning**: This element contains the identifier, which is used to uniquely identify the person or organization given in the `<contributor>` Element. Only the identifier should be entered in this field; the full URL is taken from the `@schemeURI` attribute and processed, so only the identifier needs to be entered here

###### 6.1.2.2.1 `@nameIdentifierScheme>` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the name identifier scheme. Only Mandatory if [`<nameIdentifier>`](#6122-nameidentifier-optional-element) is used.
- **Purpose and meaning**: This attribute enables the [`<nameIdentifier>`](#6122-nameidentifier-optional-element) to be interpreted unambiguously and associated with a standardised identification system, such as ORCID, ISNI or ROR.

###### 6.1.2.2.2`@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


#### 6.1.3 `<geoLocationPoint>` *optional Element*
- **Data type**: daci:point
- **Occurrence**: 1..1
- **Definition**: A geographical point described by a single pair of latitude and longitude coordinates
- **Purpose and meaning**: This wrapper is used to group the coordinate elements

##### 6.1.3.1 `@coordinateSystem` *mandatory Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Attribute for specifying the coordinate system to which the specified coordinates belong
- **Purpose and meaning**: This attribute is necessary to ensure, regardless of the system used, that the coordinates can be assigned to the correct coordinate system. 
- **Additional information**: fixed to **WGS84**, as GFZ DataServices does not currently accept any other coordinate systems

##### 6.1.3.2 `<pointLongitude>` *mandatory Element*
- **Data type**: daci:longitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Longitudinal dimension of point
- **Purpose and meaning**: Longitude of the geographic point expressed in decimal degrees (positive east) 
- **Additional information**: Mandatory only if the optional element [`<geoLocationPoint>`](#613-geolocationpoint-optional-element) is used

##### 6.1.3.3 `<pointLatitude>` *mandatory Element*
- **Data type**: daci:latitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Latitudinal dimension of point.
- **Purpose and meaning**: Latitude of the geographic point expressed in decimal degrees (positive north) 
- **Additional information**: Mandatory only if the optional element [`<geoLocationPoint>`](#613-geolocationpoint-optional-element) is used



#### 6.1.4 `<contactPerson>` *optional Element*
- **Data type**: xs:complexType
- **Occurrence**: 0..n
- **Definition**: A wrapper element that bundles all elements and attributes relating to the contact persons for the archive

##### 6.1.4.1 `@contributorType` *mandatory Attribute*
- **Data type**: daci:contributorType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: The type of contributor of the resource. fixed to ContactPerson

##### 6.1.4.2 `<contributorName>` *mandatory Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: Full name of the contact person
- **Purpose and meaning**: may be a person or an organization


##### 6.1.2.1 `@nameType` *mandatory Attribute*
- **Data type**: daci:nameType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: This attribute is used to specify the type of entry contained within the [`<contributorName>`](#6142-contributorname-mandatory-element) element.
- **Purpose and meaning**: The type of name. Enumeration list values: `organizational`,`personal`


###### 6.1.4.2.2 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language. This is listed as a required element only for the “Description” field, as a large amount of free text is entered there and it is therefore essential to specify the language used


##### 6.1.4.3 `<givenName>` *optional Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Given Name of the Person
- **Purpose and meaning**: This element is used to retrieve the first name of the person from the `<contributor>` element in normalised format.


##### 6.1.4.4 `<familyName>` *optional Element*
- **Data type**: xs:complexType
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Family Name of the Person
- **Purpose and meaning**: This element is used to retrieve the person’s surname from `<contributor>` element in normalised form


##### 6.1.4.5 `<nameIdentifier>` *optional Element*
- **Data type**: daci:nameIdentifier
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies an individual or legal entity, according to various schemes. ORCID is pref for Person ROR is pref for organizations
- **Purpose and meaning**: This element contains the identifier, which is used to uniquely identify the person or organization given in the `<contributor>` Element. Only the identifier should be entered in this field; the full URL is taken from the `@schemeURI` attribute and processed.


###### 6.1.4.5.1 `@nameIdentifierScheme` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the name identifier scheme. Only Mandatory if [`<nameIdentifier>`](#6145-nameidentifier-optional-element) is used.
- **Purpose and meaning**: This attribute enables the [`<nameIdentifier>`](#6145-nameidentifier-optional-element) to be interpreted unambiguously and associated with a standardised identification system, such as ORCID, ISNI or ROR.

###### 6.1.4.5.2 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


##### 6.1.4.6 `<affiliation>` *optional Element*
- **Data type**: daci:affiliation
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: The organizational or institutional affiliation of the person.
- **Purpose and meaning**: This element is used to indicate the organization or institution the agent is affiliated with

###### 6.1.4.6.1 `@affiliationIdentifier` *optional Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies the organizational affiliation of the person
- **Purpose and meaning**: This attribute is used to uniquely identify the affiliation

###### 6.1.4.6.2 `@affiliationIdentifierScheme` *optional Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: The name of the affiliation identifier scheme.
- **Purpose and meaning**: This attribute enables the `@affilationIdentifier` attribute to be interpreted unambiguously and associated with a standardised identification system.

###### 6.1.4.6.3 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the affilation identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI

##### 6.1.4.3 `<email>` *mandatory Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 1..1
- **Definition**: Allows you to enter an email address so that the person responsible for the archive can be contacted
- **Purpose and meaning**: A central email address is preferable in order to ensure availability.


#### 6.1.5 `<sampleCondition>` *optional Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: conditon of the sample
- **Purpose and meaning**: Incicates whether the Sample may be reused by others or has been destroid or discarded


---
---

## 7 `<relationshipIdentifiers>` *optional Element*
``` xml
    <igsn:relationshipIdentifiers>
		<igsn:alternateIdentifier alternateIdentifierType="LocalSampleName">E-GEOD-34814</igsn:alternateIdentifier>
		<igsn:parentIdentifier identifierType="IGSN" relationType="IsPartOf">GFBNO7002EHG0001</igsn:parentIdentifier>
		<igsn:relatedIdentifier identifierType="DOI" relationType="IsSourceOf">10.5880/gfz.khag.2025.006</igsn:relatedIdentifier>
	</igsn:relationshipIdentifiers>
```
- **Data type**: xs:complexType
- **Occurrence**: 0..1
- **Definition**: Wrapper to sort all identifiers representing resources having a relation to the registered resource
- **Purpose and meaning**: This wrapper is designed to collect and consolidate all information relating to resources associated with this resource, as well as the resource’s provenance
- **Additional information**: The term `relationships` was chosen to make it clear that this wrapper encompasses all elements that serve to group together resources related to the registered resource or that represent the properties of the resource.

### 7.1 `<alternateIdentifier>` *optional Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: Identifiers other than the primary Identifier applied to the resource being registered. This may be any alphanumeric string which is unique within its domain of use. May be used for local identifiers. AlternateIdentifier should be used for another identifier of the same instance (same location, same file).
- **Purpose and meaning**: [`<alternateIdentifier>`](#81-alternateidentifier-optional-element) is used to provide supplementary referencing of a resource. It enables the resource to be identified using alternative identifiers that exist alongside the actual name or a primary identification number.

#### 7.1.1 `<alternateIdentifierType>` *mandatory Attribute*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: The type of the  [`<alternateIdentifier>`](#81-alternateidentifier-optional-element) 
- **Purpose and meaning**: Mandatory only if  [`<alternateIdentifier>`](#81-alternateidentifier-optional-element) is used. This attribute is necessary to better understand the context in which the alternative identifier is used and to understand where the identifier comes from. Unlike [`@identifierType`](@identifierType), this attribute can accept free-form text to allow users to specify the exact context in which this alternative identifier is used.

### 7.2 `<parentIdentifier>` *optional Element*
- **Data type**: xs:string
- **Content**: IGSN of the parent resource
- **Occurrence**: 0..1
- **Definition**: IGSN of a resource from which this resource derives
- **Purpose and meaning**: Enables the unambiguous hierarchical assignment of relationships for samples.For the sake of readability and to remain consistent with previous schemas, this element has not been renamed to ‘relatedIdentifier’ as in DataCite, even though it is mapped to it.

#### 7.2.1 `<relationType>` *mandatory Attribute*
- **Data type**: daci:relationType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: This attribute is used to specify, in a machine-readable and standardised manner, the relationship.
- **Purpose and meaning**: When used in the  [`<parentIdentifier>`](#82-parentidentifier-optional-element) element, this attribute is fixed to `IsPartOf`, as this element only permits this relationship type.

#### 7.2.2 `<identifierType>` *mandatory Attribute*
- **Data type**: daci:relatedIdentifierType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Type of identifier

### 7.3 `<relatedIdentifier>` *optional Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: Identifiers of related resources. These must be globally unique identifiers.
- **Purpose and meaning**: This element is used to retrieve the ID so that resources related to this sample can be linked.

#### 7.3.1 `<relationType>` *mandatory Attribute*
- **Data type**: daci:relationType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: This attribute is used to specify, in a machine-readable and standardised manner, the relationship between the resource specified in the element and the resource described here.

#### 7.3.2 `<identifierType>` *mandatory Attribute*
- **Data type**: daci:relatedIdentifierType
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Type of identifier


---
---




## 8 `<geoLocations>` *optional Element*
```xml
	<igsn:geoLocations>
		<igsn:geoLocationPlace lang="en">Potsdam</igsn:geoLocationPlace>
		<igsn:geoLocationPoint coordinateSystem="WGS84">
			<daci:pointLongitude>52.3786120</daci:pointLongitude>
			<daci:pointLatitude>13.06609315120018</daci:pointLatitude>
		</igsn:geoLocationPoint>
		<igsn:geoLocationBox coordinateSystem="WGS84">
			<daci:westBoundLongitude>5</daci:westBoundLongitude>
			<daci:eastBoundLongitude>13.060326321314756</daci:eastBoundLongitude>
			<daci:southBoundLatitude>52.38339986112071</daci:southBoundLatitude>
			<daci:northBoundLatitude>13.067579014491026</daci:northBoundLatitude>
		</igsn:geoLocationBox>
		<igsn:geoLocationPolygon>
			<igsn:polygonPoint coordinateSystem="WGS84">
				<daci:pointLongitude>13.066838</daci:pointLongitude>
				<daci:pointLatitude>52.380524</daci:pointLatitude>
			</igsn:polygonPoint>
			<igsn:polygonPoint coordinateSystem="WGS84">
				<daci:pointLongitude>13.060102</daci:pointLongitude>
				<daci:pointLatitude>52.379664</daci:pointLatitude>
			</igsn:polygonPoint>
			<igsn:polygonPoint coordinateSystem="WGS84">
				<daci:pointLongitude>13.06032</daci:pointLongitude>
				<daci:pointLatitude>52.382243</daci:pointLatitude>
			</igsn:polygonPoint>
			<igsn:polygonPoint coordinateSystem="WGS84">
				<daci:pointLongitude>13.066838</daci:pointLongitude>
				<daci:pointLatitude>52.380524</daci:pointLatitude>
			</igsn:polygonPoint>
		</igsn:geoLocationPolygon>
	</igsn:geoLocations>

```
- **Data type**: xs:complexType
- **Occurrence**: 0..1
- **Definition**: Wrapper for geographical area or named location where the sample was taken
- **Purpose and meaning**: Aggregation of all information relating to the geographical resolution or extent of the registered sample

### 8.1 `<geoLocationPlace>` *optional Element*
- **Data type**: xs:string
- **Content**: free text
- **Occurrence**: 0..n
- **Definition**: Where was the sample acquired relative to the Earth (or another celestial body...). Some samples might be 'non-geographic': mineral specimen, synthetic material.
Named place where the data was gathered or about which the data is focused.
- **Purpose and meaning**: This element allows to enter a location without specifying coordinates, whilst still establishing a geographical reference
- **Additional information**: Use to name or describe a geographic location. For a detailed description of the location where the samples were collected, use the [`<description>`](#45-description-optional-element) element

#### 8.1.1 `@lang` *optional Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 0..1
- **Definition**: Distinguishes the language
- **Purpose and meaning**: Ensures that machines can unambiguously identify the language.
- **Additional information**: As the [`<description>`](#description) can be repeated and contains the largest amount of natural language text this attribute is mandotory.

### 8.2 `<geoLocationPoint>` *optional Element*
- **Data type**: daci:point
- **Occurrence**: 1..1
- **Definition**: A geographical point described by a single pair of latitude and longitude coordinates
- **Purpose and meaning**: This wrapper is used to group the coordinate elements


#### 8.2.1 `@coordinateSystem` *mandatory Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Attribute for specifying the coordinate system to which the specified coordinates belong
- **Purpose and meaning**: This attribute is necessary to ensure, regardless of the system used, that the coordinates can be assigned to the correct coordinate system. 
- **Additional information**: fixed to **WGS84**, as GFZ DataServices does not currently accept any other coordinate systems

#### 8.2.2 `<pointLongitude>` *mandatory Element*
- **Data type**: daci:longitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Longitudinal dimension of point
- **Purpose and meaning**: Longitude of the geographic point expressed in decimal degrees (positive east) 
- **Additional information**: Mandatory only if the optional element [`<geoLocationPoint>`](#92-geolocationpoint-optional-element) is used


#### 8.2.3 `<pointLatitude>` *mandatory Element*
- **Data type**: daci:latitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Latitudinal dimension of point.
- **Purpose and meaning**: Latitude of the geographic point expressed in decimal degrees (positive north) 
- **Additional information**: Mandatory only if the optional element [`<geoLocationPoint>`](#92-geolocationpoint-optional-element) is used

### 8.3 `<geoLocationBox>` *optional Element*
- **Data type**: daci:box
- **Occurrence**: 0..1
- **Definition**: A box is defined by two geographic points. Left low corner and right upper corner. Each point is defined by its longitude and latitude.
- **Purpose and meaning**: This element is used to define a geographical area as a rectangle. It is useful if you want to capture the location where the sample was collected, or to which it relates, as a general area rather than an exact position. This allows geographical references in metadata to be represented more precisely, whilst also making them easier to search, display and filter.

#### 8.3.1 `@coordinateSystem` *mandatory Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Attribute for specifying the coordinate system to which the specified coordinates belong
- **Purpose and meaning**: This attribute is necessary to ensure, regardless of the system used, that the coordinates can be assigned to the correct coordinate system. 
- **Additional information**: Fixed to **WGS84**, as GFZ DataServices does not currently accept any other coordinate systems


#### 8.3.2 `<westBoundLongitude>` *mandatory Element*
- **Data type**: daci:longitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Determines the western boundary of the geographical area and thus specifies the easternmost longitude of the rectangle.
- **Purpose and meaning**: This is necessary so that systems and search engines can clearly identify how far west the data extends.
Together with <eastBoundLongitude>, it defines the width of the area in an east-west direction.
- **Additional information**: Mandatory only if the optional element [`<geoLocationBox>`](#93-geolocationbox-optional-element) is used


#### 8.3.3 `<eastBoundLongitude>` *mandatory Element*
- **Data type**: daci:longitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**:Specifies the eastern boundary of the rectangle and defines the greatest longitude of the geographical area.
- **Purpose and meaning**: This is necessary so that systems and search engines can clearly identify how far east the data extends.
Together with `<westBoundLongitude>`, it defines the width of the area in an east-west direction.
- **Additional information**: Mandatory only if the optional element [`<geoLocationBox>`](#93-geolocationbox-optional-element) is used

#### 8.3.4 `<southBoundLatitude>` *mandatory Element*
- **Data type**: xs:language
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Defines the southern boundary of the rectangle and specifies the southernmost latitude of the area.
- **Purpose and meaning**: Required to describe the southern extent of a data record.
Together with `<northBoundLatitude>`, it determines the extent of the area in the north-south direction.
- **Additional information**: mMandatory only if the optional element [`<geoLocationBox>`](#93-geolocationbox-optional-element) is used

#### 8.3.5 `<northBoundLatitude>` *mandatory Element*
- **Data type**: xs:language
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Specifies the northern boundary of the rectangle and defines the highest latitude of the area.
- **Purpose and meaning**: Required to describe the northern extent of a data record.
Together with `<southBoundLatitude>`, it determines the extent of the area in the north-south direction. 
- **Additional information**: Mandatory only if the optional element [`<geoLocationBox>`](#93-geolocationbox-optional-element) is used

### 8.4 `<geoLocationPolygon>` *optional Element*
- **Data type**: xs:complexType
- **Occurrence**: 0..1
- **Definition**: Is used to describe a geographical area as a polygon (an irregular shape). A polygon consists of a sequence of coordinate points (<polygonePoint>) that form a closed area on the map
- **Purpose and meaning**: Enables the precise representation of administrative boundaries, natural areas, research areas or other non-rectangular areas. Whilst a [`<geoLocationBox>`](#93-geolocationbox-optional-element) always describes a square area, a polygon can, for example, accurately represent the boundaries of a national park, a river catchment area or an administrative unit.

#### 8.4.1 `<polygonPoint>` *mandatory Element*
- **Data type**: xs:language
- **Content**: decimal
- **Occurrence**: 4..n
- **Definition**: A point within a polygon.
- **Purpose and meaning**: Each `<polygonPoint>` contains a pair of longitude and latitude values (`<pointLongitude>` and `<pointLatitude>`) and defines a corner of the polygon
- **Additional information**: The wrapper does not contain any content of its own, but simply bundles the longitude und latitude elements to form a point.
If [`<geoLocationPolygon>`](#94-geolocationpolygon-optional-element) is used, `<polygonPoint>` is mandatory. There must be at least 4 non-aligned points to make a closed curve, with the last point described the same as the first point.

#### 8.4.1.1 `@coordinateSystem` *mandatory Attribute*
- **Data type**: xs:language
- **Content**: enumeration list
- **Occurrence**: 1..1
- **Definition**: Attribute for specifying the coordinate system to which the specified coordinates belong
- **Purpose and meaning**: This attribute is necessary to ensure, regardless of the system used, that the coordinates can be assigned to the correct coordinate system. 
- **Additional information**: fixed to **WGS84**, as GFZ DataServices does not currently accept any other coordinate systems

#### 8.4.1.2 `<pointLongitude>` *mandatory Element*
- **Data type**: daci:longitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Longitudinal dimension of point
- **Purpose and meaning**: Longitude of the geographic point expressed in decimal degrees (positive east) 
- **Additional information**: Mandatory only if the optional element [`<polygonPoint>`](#941-polygonpoint-mandatory-element) is used


#### 8.4.1.3 `<pointLatitude>` *mandatory Element*
- **Data type**: daci:latitudeType
- **Content**: decimal
- **Occurrence**: 1..1
- **Definition**: Latitudinal dimension of point.
- **Purpose and meaning**: Latitude of the geographic point expressed in decimal degrees (positive north) 
- **Additional information**: Mandatory only if the optional element [`<polygonPoint>`](#941-polygonpoint-mandatory-element) is used


---
---



# 9 `<fundingReferences>` *optional Element*
```xml
	<igsn:fundingReferences>
		<igsn:fundingReference>
			<igsn:funderName>Helmholtz-Gemeinschaft</igsn:funderName>
			<igsn:funderIdentifier funderIdentifierType="Crossref Funder ID" schemeURI="https://doi.org/">https://doi.org/10.13039/501100001656</igsn:funderIdentifier>
			<igsn:awardTitle>Impuls- und Vernetzungsfonds (IVF)</igsn:awardTitle>
		</igsn:fundingReference>
		<igsn:fundingReference>
			<funderName>European Commission</funderName>
			<funderIdentifier funderIdentifierType="Crossref Funder ID">https://doi.org/10.13039/501100000780</funderIdentifier>
			<awardNumber awardURI="https://cordis.europa.eu/project/rcn/100603_en.html">284382</awardNumber>
			<awardTitle>Institutionalizing global genetic-resource commons. Global Strategies for accessing and using essential public knowledge assets in the life sciences</awardTitle>
		</igsn:fundingReference>
	</igsn:fundingReferences>

```
- **Data type**: xs:complexType
- **Occurrence**: 0..1
- **Definition**: Information about financial support (funding) for the resource being registered.
- **Purpose and meaning**: The wrapper does not contain any content of its own, but simply bundles all elements which are necessary to describe the funding of the sample

### 9.1 `<fundingReference>` *optional Element*
- **Data type**: xs:complexType
- **Occurrence**: 0..n
- **Definition**: Information about financial support (funding) for the resource being registered.
- **Purpose and meaning**: The wrapper does not contain any content of its own, but simply bundles all elements which are necessary to describe the funding of the sample


#### 9.1.1 `<funderName>` *mandatory Element*
- **Data type**: xs:string
- **Occurrence**: 1..1
- **Definition**: Name of the funding provider
- **Purpose and meaning**: If [`<fundingReference>`](#101-fundingreference-optional-element) is used, then `<funderName>` is mandatory


#### 9.1.2 `<funderIdentifier>` *optional Element*
- **Data type**: xs:string
- **Occurrence**: 0..1
- **Definition**: Uniquely identifies a funding entity, according to various types.


##### 9.1.2.1 `@funderIdentifierType` *madatory Attribute*
- **Data type**: xs:string
- **Occurrence**: 1..1
- **Definition**: The type of the funderIdentifier.
- **Additional information**: Select a value from the enumeration list (ISNI, GRID, ROR, Crossref Funder ID, Other)


##### 9.1.2.2 `@schemeURI` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI of the name identifier scheme.
- **Purpose and meaning**: This attribute is used to store the identifier's URI


#### 9.1.3 `<awardNumber>` *optional Element*
- **Data type**: xs:string
- **Occurrence**: 0..1
- **Definition**: The code assigned by the funder to a sponsored award (grant).


##### 9.1.2.2 `<awardURI>` *optional Attribute*
- **Data type**: xs:anyURI
- **Content**: URI
- **Occurrence**: 0..1
- **Definition**: The URI leading to a page provided by the funder for more information about the award (grant).
- **Purpose and meaning**: In case the award or grant has an ID or DOI, the full URL of the grant DOI can be included here


#### 9.1.3 `<awardTitle>` *optional Element*
- **Data type**: xs:string
- **Occurrence**: 0..1
- **Definition**: The human readable title or name of the award (grant).


---
---



# 10 `<language>` *optional Element*
```xml
<igsn:language>en</igsn:language>

```
- **Data type**: xs:complexType
- **Occurrence**: 0..1
- **Definition**: The primary language of the resource.
- **Purpose and meaning**: Will continue to enable the language of the metadata record to be stored and processed in a unambiguous and machine-readable format. 
- **Additonal Informations**: Even though some elements (particularly those that accept free text) already have ‘lang’ attributes, it is recommended that you also populate this language element. This will ensure that the metadata can continue to be displayed and interpreted correctly in the future.
