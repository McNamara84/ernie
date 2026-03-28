# ERNIE Database ER Diagram (PlantUML)

```plantuml
@startuml ERNIE_ER_Diagram

!theme plain
skinparam linetype ortho
skinparam roundcorner 5
skinparam classFontSize 11
skinparam classAttributeFontSize 10

hide circle
hide methods

' ==========================================================================
' LOOKUP TABLES (DataCite Controlled Vocabularies)
' ==========================================================================

entity "resource_types" as resource_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    description : TEXT
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "title_types" as title_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "date_types" as date_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * is_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "description_types" as description_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "contributor_types" as contributor_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * category : VARCHAR(20) = 'both'
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "identifier_types" as identifier_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "identifier_type_patterns" as identifier_type_patterns {
    * **id** : BIGINT <<PK>>
    --
    * identifier_type_id : BIGINT <<FK>>
    * type : ENUM //validation|detection//
    * pattern : VARCHAR(500)
    * is_active : BOOLEAN
    * priority : SMALLINT
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "relation_types" as relation_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "funder_identifier_types" as funder_identifier_types {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * slug : VARCHAR <<UK>>
    * is_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "languages" as languages {
    * **id** : BIGINT <<PK>>
    --
    * code : VARCHAR(10) <<UK>> //ISO 639-1//
    * name : VARCHAR
    * active : BOOLEAN
    * elmo_active : BOOLEAN
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "rights" as rights {
    * **id** : BIGINT <<PK>>
    --
    * identifier : VARCHAR <<UK>> //SPDX//
    * name : VARCHAR
    uri : VARCHAR
    scheme_uri : VARCHAR
    * is_active : BOOLEAN
    * is_elmo_active : BOOLEAN
    * usage_count : INT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "publishers" as publishers {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR <<UK>>
    identifier : VARCHAR
    identifier_scheme : VARCHAR
    scheme_uri : VARCHAR
    * language : VARCHAR = 'en'
    * is_default : BOOLEAN = false
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' ENTITY TABLES
' ==========================================================================

entity "persons" as persons {
    * **id** : BIGINT <<PK>>
    --
    * family_name : VARCHAR
    given_name : VARCHAR
    name_identifier : VARCHAR(512) <<UK>> //ORCID URL//
    name_identifier_scheme : VARCHAR(50)
    scheme_uri : VARCHAR(512)
    orcid_verified_at : TIMESTAMP
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "institutions" as institutions {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    name_identifier : VARCHAR(512) //ROR URL//
    name_identifier_scheme : VARCHAR(50)
    scheme_uri : VARCHAR(512)
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "users" as users {
    * **id** : BIGINT <<PK>>
    --
    * name : VARCHAR
    * email : VARCHAR <<UK>>
    email_verified_at : TIMESTAMP
    * password : VARCHAR
    password_set_at : TIMESTAMP
    remember_token : VARCHAR
    * font_size_preference : VARCHAR = 'regular'
    * role : VARCHAR = 'curator'
    * is_active : BOOLEAN = true
    deactivated_at : TIMESTAMP
    deactivated_by : BIGINT <<FK>>
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "password_reset_tokens" as password_reset_tokens {
    * **email** : VARCHAR <<PK>>
    --
    * token : VARCHAR
    created_at : TIMESTAMP
}

entity "sessions" as sessions {
    * **id** : VARCHAR <<PK>>
    --
    user_id : BIGINT //indexed, no FK//
    ip_address : VARCHAR(45)
    user_agent : TEXT
    * payload : LONGTEXT
    * last_activity : INT
}

' ==========================================================================
' MAIN RESOURCE TABLE
' ==========================================================================

entity "resources" as resources {
    * **id** : BIGINT <<PK>>
    --
    doi : VARCHAR <<UK>> //DataCite #1//
    * identifier_type : VARCHAR(50) = 'DOI'
    publisher_id : BIGINT <<FK>>
    publication_year : SMALLINT //DataCite #5//
    resource_type_id : BIGINT <<FK>> //nullable//
    version : VARCHAR(50) //DataCite #15//
    language_id : BIGINT <<FK>>
    created_by_user_id : BIGINT <<FK>>
    updated_by_user_id : BIGINT <<FK>>
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' RESOURCE RELATIONSHIP TABLES
' ==========================================================================

entity "titles" as titles {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * title_type_id : BIGINT <<FK>>
    * value : VARCHAR(1000)
    language : VARCHAR(10) //xml:lang//
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "resource_creators" as resource_creators {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * creatorable_type : VARCHAR //Person|Institution//
    * creatorable_id : BIGINT
    * position : INT = 0
    * is_contact : BOOLEAN = false
    email : VARCHAR
    website : VARCHAR
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "resource_contributors" as resource_contributors {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * contributorable_type : VARCHAR //Person|Institution//
    * contributorable_id : BIGINT
    * position : INT = 0
    email : VARCHAR //nullable, Contact Person only//
    website : VARCHAR //nullable, Contact Person only//
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "resource_contributor_contributor_type" as rc_ct {
    * **id** : BIGINT <<PK>>
    --
    * resource_contributor_id : BIGINT <<FK>>
    * contributor_type_id : BIGINT <<FK>>
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "affiliations" as affiliations {
    * **id** : BIGINT <<PK>>
    --
    * affiliatable_type : VARCHAR //Creator|Contributor//
    * affiliatable_id : BIGINT
    * name : TEXT
    identifier : VARCHAR(512) //ROR URL//
    identifier_scheme : VARCHAR(50)
    scheme_uri : VARCHAR(512)
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "subjects" as subjects {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * value : VARCHAR
    * language : VARCHAR(10) = 'en'
    subject_scheme : VARCHAR //GCMD, MSL, etc.//
    scheme_uri : VARCHAR(512)
    value_uri : VARCHAR(512)
    classification_code : VARCHAR
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "dates" as dates {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * date_type_id : BIGINT <<FK>>
    date_value : VARCHAR(35) //ISO 8601 datetime//
    start_date : VARCHAR(35)
    end_date : VARCHAR(35)
    date_information : VARCHAR
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "descriptions" as descriptions {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * description_type_id : BIGINT <<FK>>
    * value : TEXT
    language : VARCHAR(10)
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "geo_locations" as geo_locations {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    geo_type : VARCHAR(10) //point/box/polygon//
    place : TEXT
    point_longitude : DECIMAL(11,8)
    point_latitude : DECIMAL(10,8)
    elevation : DECIMAL(10,2)
    elevation_unit : VARCHAR(50)
    west_bound_longitude : DECIMAL(11,8)
    east_bound_longitude : DECIMAL(11,8)
    south_bound_latitude : DECIMAL(10,8)
    north_bound_latitude : DECIMAL(10,8)
    polygon_points : JSON
    in_polygon_point_longitude : DECIMAL(11,8)
    in_polygon_point_latitude : DECIMAL(10,8)
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "related_identifiers" as related_identifiers {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * identifier : VARCHAR(2183)
    * identifier_type_id : BIGINT <<FK>>
    * relation_type_id : BIGINT <<FK>>
    relation_type_information : VARCHAR //DataCite 4.7//
    related_metadata_scheme : VARCHAR
    scheme_uri : VARCHAR(512)
    scheme_type : VARCHAR(100)
    * position : INT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "funding_references" as funding_references {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * funder_name : VARCHAR
    funder_identifier : VARCHAR(512) //ROR URL//
    funder_identifier_type_id : BIGINT <<FK>>
    scheme_uri : VARCHAR(512)
    award_number : VARCHAR
    award_uri : VARCHAR(512)
    award_title : TEXT
    * position : INT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "resource_rights" as resource_rights {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * rights_id : BIGINT <<FK>>
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "sizes" as sizes {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    numeric_value : DECIMAL(12,4)
    unit : VARCHAR(50)
    type : VARCHAR(100)
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "formats" as formats {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * value : VARCHAR //MIME type//
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "alternate_identifiers" as alternate_identifiers {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * value : VARCHAR
    * type : VARCHAR
    * position : INT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' APPLICATION-SPECIFIC TABLES
' ==========================================================================

entity "landing_pages" as landing_pages {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>> <<UK>> //1:1//
    doi_prefix : VARCHAR
    * slug : VARCHAR
    * template : VARCHAR(50) = 'default_gfz'
    ftp_url : VARCHAR(2048)
    external_domain_id : BIGINT <<FK>>
    external_path : VARCHAR(2048)
    * is_published : BOOLEAN = false
    preview_token : VARCHAR(64) <<UK>>
    published_at : TIMESTAMP
    * view_count : INT = 0
    last_viewed_at : TIMESTAMP
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "landing_page_files" as landing_page_files {
    * **id** : BIGINT <<PK>>
    --
    * landing_page_id : BIGINT <<FK>>
    * url : VARCHAR(2048)
    * position : SMALLINT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "landing_page_domains" as landing_page_domains {
    * **id** : BIGINT <<PK>>
    --
    * domain : VARCHAR(768) <<UK>>
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "settings" as settings {
    * **id** : BIGINT <<PK>>
    --
    * key : VARCHAR <<UK>>
    value : TEXT
}

entity "contact_messages" as contact_messages {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    resource_creator_id : BIGINT <<FK>>
    * send_to_all : BOOLEAN = false
    * sender_name : VARCHAR
    * sender_email : VARCHAR
    * message : TEXT
    * copy_to_sender : BOOLEAN = false
    ip_address : VARCHAR(45)
    sent_at : TIMESTAMP
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "thesaurus_settings" as thesaurus_settings {
    * **id** : BIGINT <<PK>>
    --
    * type : VARCHAR <<UK>>
    * display_name : VARCHAR
    * is_active : BOOLEAN = true
    * is_elmo_active : BOOLEAN = true
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "right_resource_type_exclusions" as right_resource_type_exclusions {
    * **id** : BIGINT <<PK>>
    --
    * right_id : BIGINT <<FK>>
    * resource_type_id : BIGINT <<FK>>
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' PID SETTINGS & INSTRUMENTS (PID4INST)
' ==========================================================================

entity "pid_settings" as pid_settings {
    * **id** : BIGINT <<PK>>
    --
    * type : VARCHAR <<UK>>
    * display_name : VARCHAR
    * is_active : BOOLEAN = true
    * is_elmo_active : BOOLEAN = true
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "resource_instruments" as resource_instruments {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * instrument_pid : VARCHAR(512)
    * instrument_pid_type : VARCHAR(50) = 'Handle'
    * instrument_name : VARCHAR(1024)
    * position : INT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' LARAVEL FRAMEWORK TABLES
' ==========================================================================

entity "cache" as cache {
    * **key** : VARCHAR <<PK>>
    --
    * value : MEDIUMTEXT
    * expiration : INT
}

entity "cache_locks" as cache_locks {
    * **key** : VARCHAR <<PK>>
    --
    * owner : VARCHAR
    * expiration : INT
}

entity "jobs" as jobs {
    * **id** : BIGINT <<PK>>
    --
    * queue : VARCHAR
    * payload : LONGTEXT
    * attempts : TINYINT
    reserved_at : INT
    * available_at : INT
    * created_at : INT
}

entity "job_batches" as job_batches {
    * **id** : VARCHAR <<PK>>
    --
    * name : VARCHAR
    * total_jobs : INT
    * pending_jobs : INT
    * failed_jobs : INT
    * failed_job_ids : LONGTEXT
    options : MEDIUMTEXT
    cancelled_at : INT
    * created_at : INT
    finished_at : INT
}

entity "failed_jobs" as failed_jobs {
    * **id** : BIGINT <<PK>>
    --
    * uuid : VARCHAR <<UK>>
    * connection : TEXT
    * queue : TEXT
    * payload : LONGTEXT
    * exception : LONGTEXT
    * failed_at : TIMESTAMP
}

' ==========================================================================
' IGSN TABLES (Physical Sample Management)
' ==========================================================================

entity "igsn_metadata" as igsn_metadata {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>> <<UK>> //1:1//
    parent_resource_id : BIGINT <<FK>> //hierarchy//
    sample_type : VARCHAR(100)
    material : VARCHAR(255)
    * is_private : BOOLEAN = false
    depth_min : DECIMAL(10,2)
    depth_max : DECIMAL(10,2)
    depth_scale : VARCHAR(100)
    sample_purpose : TEXT
    collection_method : VARCHAR(255)
    collection_method_description : TEXT
    collection_date_precision : VARCHAR(20)
    cruise_field_program : VARCHAR(255)
    platform_type : VARCHAR(100)
    platform_name : VARCHAR(100)
    platform_description : VARCHAR(255)
    current_archive : VARCHAR(255)
    current_archive_contact : VARCHAR(255)
    sample_access : VARCHAR(50)
    operator : VARCHAR(255)
    coordinate_system : VARCHAR(50)
    user_code : VARCHAR(50)
    description_json : JSON
    * upload_status : VARCHAR(50) = 'pending'
    upload_error_message : TEXT
    csv_filename : VARCHAR(255)
    csv_row_number : INT
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "igsn_classifications" as igsn_classifications {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * value : VARCHAR(255)
    * position : SMALLINT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "igsn_geological_ages" as igsn_geological_ages {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * value : VARCHAR(255)
    * position : SMALLINT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "igsn_geological_units" as igsn_geological_units {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * value : VARCHAR(255)
    * position : SMALLINT = 0
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' ASSISTANCE TABLES (Relation Discovery)
' ==========================================================================

entity "suggested_relations" as suggested_relations {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * identifier : VARCHAR(2183)
    * identifier_type_id : BIGINT <<FK>>
    * relation_type_id : BIGINT <<FK>>
    * source : VARCHAR(255)
    source_title : VARCHAR(255)
    source_type : VARCHAR(255)
    source_publisher : VARCHAR(255)
    source_publication_date : VARCHAR(255)
    * discovered_at : TIMESTAMP
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

entity "dismissed_relations" as dismissed_relations {
    * **id** : BIGINT <<PK>>
    --
    * resource_id : BIGINT <<FK>>
    * identifier : VARCHAR(2183)
    * relation_type_id : BIGINT <<FK>>
    dismissed_by : BIGINT <<FK>>
    reason : VARCHAR(255)
    created_at : TIMESTAMP
    updated_at : TIMESTAMP
}

' ==========================================================================
' RELATIONSHIPS
' ==========================================================================

' Resource core relationships
resources ||--o{ titles
resources ||--o{ resource_creators
resources ||--o{ resource_contributors
resources ||--o{ subjects
resources ||--o{ dates
resources ||--o{ descriptions
resources ||--o{ geo_locations
resources ||--o{ related_identifiers
resources ||--o{ funding_references
resources ||--o{ resource_rights
resources ||--o{ sizes
resources ||--o{ formats
resources ||--o| landing_pages
resources ||--o{ alternate_identifiers
resources ||--o{ resource_instruments

' Lookup table relationships
resources }o--o| resource_types
resources }o--o| publishers
resources }o--o| languages
resources }o--o| users : "created_by"
resources }o--o| users : "updated_by"

titles }o--|| title_types
dates }o--|| date_types
descriptions }o--|| description_types
resource_contributors ||--o{ rc_ct
rc_ct }o--|| contributor_types
related_identifiers }o--|| identifier_types
related_identifiers }o--|| relation_types
identifier_type_patterns }o--|| identifier_types
funding_references }o--o| funder_identifier_types

' Rights pivot
resource_rights }o--|| rights

' Polymorphic creator/contributor relationships
resource_creators }o--o| persons
resource_creators }o--o| institutions
resource_contributors }o--o| persons
resource_contributors }o--o| institutions

' Affiliations (polymorphic)
affiliations }o--o| resource_creators
affiliations }o--o| resource_contributors

' Contact messages
contact_messages }o--|| resources
contact_messages }o--o| resource_creators

' License exclusions
right_resource_type_exclusions }o--|| rights
right_resource_type_exclusions }o--|| resource_types

' User self-reference
users }o--o| users : "deactivated_by"

' Landing page domains
landing_pages }o--o| landing_page_domains
landing_pages ||--o{ landing_page_files

' IGSN relationships
igsn_metadata ||--|| resources
igsn_metadata }o--o| resources : "parent"
igsn_classifications }o--|| resources
igsn_geological_ages }o--|| resources
igsn_geological_units }o--|| resources

' Suggested/Dismissed relations (Assistance feature)
suggested_relations }o--|| resources
suggested_relations }o--|| identifier_types
suggested_relations }o--|| relation_types
dismissed_relations }o--|| resources
dismissed_relations }o--|| relation_types
dismissed_relations }o--o| users

@enduml
```
