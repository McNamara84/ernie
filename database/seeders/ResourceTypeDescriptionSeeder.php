<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ResourceType;
use Illuminate\Database\Seeder;

/**
 * Seeder to populate resource types with official DataCite descriptions.
 *
 * Descriptions are taken from the DataCite Metadata Schema 4.6:
 * https://datacite-metadata-schema.readthedocs.io/en/4.6/appendices/appendix-1/resourceTypeGeneral/
 */
class ResourceTypeDescriptionSeeder extends Seeder
{
    /**
     * Official DataCite descriptions for each resource type.
     *
     * Keys must match the 'name' column in the resource_types table exactly.
     *
     * Descriptions are taken from the DataCite Metadata Schema 4.6:
     * https://datacite-metadata-schema.readthedocs.io/en/4.6/appendices/appendix-1/resourceTypeGeneral/
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'Audiovisual' => 'A series of visual representations imparting an impression of motion when shown in succession. May or may not include sound.',
        'Award' => 'An umbrella term for resources provided to individual(s) or organization(s) in support of research, academic output, or training, such as a specific instance of funding, grant, investment, sponsorship, scholarship, recognition, or non-monetary materials.',
        'Book' => 'A medium for recording information in the form of writing or images, typically composed of many pages bound together and protected by a cover.',
        'Book Chapter' => 'One of the main divisions of a book.',
        'Collection' => 'An aggregation of resources, which may encompass collections of one resourceType as well as those of mixed types. A collection is described as a group; its parts may also be separately described.',
        'Computational Notebook' => 'A virtual notebook environment used for literate programming.',
        'Conference Paper' => 'Article that is written with the goal of being accepted to a conference.',
        'Conference Proceeding' => 'Collection of academic papers published in the context of an academic conference.',
        'Data Paper' => 'A factual and objective publication with a focused intent to identify and describe specific data, sets of data, or data collections to facilitate discoverability.',
        'Dataset' => 'Data encoded in a defined structure.',
        'Dissertation' => 'A written essay, treatise, or thesis, especially one written by a candidate for the degree of Doctor of Philosophy.',
        'Event' => 'A non-persistent, time-based occurrence.',
        'Image' => 'A visual representation other than text.',
        'Instrument' => 'A device, tool or apparatus used to obtain, measure and/or analyze data.',
        'Interactive Resource' => 'A resource requiring interaction from the user to be understood, executed, or experienced.',
        'Journal' => 'A scholarly publication consisting of articles that is published regularly throughout the year.',
        'Journal Article' => 'A written composition on a topic of interest, which forms a separate part of a journal.',
        'Model' => 'An abstract, conceptual, graphical, mathematical or visualization model that represents empirical objects, phenomena, or physical processes.',
        'Output Management Plan' => 'A formal document that outlines how research outputs are to be handled both during a research project and after the project is completed.',
        'Peer Review' => 'Evaluation of scientific, academic, or professional work by others working in the same field.',
        'Physical Object' => 'A physical object or substance.',
        'Preprint' => 'A version of a scholarly or scientific paper that precedes formal peer review and publication in a peer-reviewed scholarly or scientific journal.',
        'Project' => 'A planned endeavor or activity, frequently collaborative, intended to achieve a particular aim using allocated resources such as budget, time, and expertise.',
        'Report' => 'A document that presents information in an organized format for a specific audience and purpose.',
        'Service' => 'An organized system of apparatus, appliances, staff, etc., for supplying some function(s) required by end users.',
        'Software' => 'A computer program other than a computational notebook, in either source code (text) or compiled form. Use this type for general software components supporting scholarly research.',
        'Sound' => 'A resource primarily intended to be heard.',
        'Standard' => 'Something established by authority, custom, or general consent as a model, example, or point of reference.',
        'Study Registration' => 'A detailed, time-stamped description of a research plan, often openly shared in a registry or published in a journal before the study is conducted to lend accountability and transparency in the hypothesis generating and testing process.',
        'Text' => 'A resource consisting primarily of words for reading that is not covered by any other textual resource type in this list.',
        'Workflow' => 'A structured series of steps which can be executed to produce a final outcome, allowing users a means to specify and enact their work in a more reproducible manner.',
        'Other' => 'If selected, supply a value for ResourceType.',
    ];

    /**
     * Get the resource type names that have descriptions defined.
     *
     * @return array<string>
     */
    public static function getDescriptionKeys(): array
    {
        return array_keys(self::DESCRIPTIONS);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::DESCRIPTIONS as $name => $description) {
            ResourceType::query()
                ->where('name', $name)
                ->update(['description' => $description]);
        }

        $this->command->info('Updated '.count(self::DESCRIPTIONS).' resource type descriptions.');
    }
}
