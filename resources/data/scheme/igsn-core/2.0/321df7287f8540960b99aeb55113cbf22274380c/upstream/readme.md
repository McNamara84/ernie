# IGSN Metadata Schema 2.0 by GFZ Data Services

**The schema, and therefore this repository, is currently still in development.**

## Overview

This repository contains:
- IGSN Metadata Schema V1.3 for reference purposes
- a slightly modified version of the DataCite Metadata Schema V4.7 (modification needed to allow reuse of globally defined types in the Core Schema)
- the IGSN Core Schema by GFZ Data Services V2
- brief documentation of the elements and attributes

The repository is intended as a technical and documentary resource for working with IGSN-related sample metadata, schema validation, and metadata transformation workflows. It is particularly relevant for communities dealing with geoscientific and environmental samples, where consistent metadata are needed for registration, discovery, and reuse.

## Background 
### IGSN e.V.
IGSN e.V. is a non-profit organisation, founded in 2011 to manage the allocation and registration of International Generic Sample Numbers (IGSNs).

The IGSN e.V. works with DataCite to provide a globally recognised persistent identifier for sample identification and tracking that facilitates the efficient sharing of samples and their associated metadata among researchers, institutions, and databases.

Additionally, IGSN e.V. works to promote the use of IGSNs in research and to establish partnerships with other data infrastructures to enhance the integration and interoperability of sample data and metadata. The organisation also provides a range of services related to IGSNs, such as training, support, and software tools for managing and sharing sample data and metadata.

### IGSN 
The application and long-term usefulness of sample-based data depend critically on the availability of information (metadata) about the samples, links to other datasets derived from individual samples, and access to the samples themselves. Achieving this is hindered by several issues, including incomplete sample documentation in publications, the use of ambiguous sample names, and the lack of web-accessible catalogues that provide information on existing samples and their archiving location. Using persistent identifiers for physical samples, such as the International Generic Sample Number (IGSN), can solve these problems.

The IGSN ID is a globally unique, persistent, domain-agnostic identifier for material samples. Samples can be any material from anywhere in the universe, not just from Earth. Although the IGSN originated in the geosciences, it is now applicable to all sample types and disciplines.

### GFZ DataServices
GFZ Data Services has long been involved in IGSN registration and sample metadata management. The IGSN Core Schema V2 by GFZ DataServices harmonises previous schema versions from an information science perspective and updates the elements and attributes required for registration with DataCite to ensure that registration can be accomplished effortlessly.

## Repository Contents

This repository provides the schema files and supporting documentation needed to describe samples in a structured way for IGSN-related workflows. Depending on the version of the repository, this may include XML Schema definitions, example metadata structures and explanatory documentation for implementation and use.

The schema is designed to support standardised sample descriptions, enabling the validation and further processing of metadata in downstream systems. The schema can serve as a reference for developers, data managers and infrastructure teams working on sample registration workflows or metadata interoperability.

## Relation to FAIR AIMS

This repository is closely related to the broader development context of FAIR WISH and FAIR AIMS. Within FAIR WISH, standardised, discipline-specific IGSN metadata schemas and templates were developed for various sample types within the Helmholtz research field of Earth and Environment. Workflows were also developed for generating machine-readable metadata from these templates and databases.

FAIR AIMS builds on these results and aims to provide an online template generator, automated IGSN registration workflows, integration of linked-data vocabularies and metadata quality checks during upload. Structured metadata schemas, such as the one documented here, form an important technical basis for template generation, XML creation, landing pages and quality-controlled submission workflows in this context.

