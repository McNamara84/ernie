<?php

declare(strict_types=1);

namespace App\Enums\Igsn;

enum IgsnMetadataValueType: string
{
    case FieldName = 'field_name';
    case ClassificationComment = 'classification_comment';
    case SampleRequest = 'sample_request';
    case SampledBy = 'sampled_by';
    case LaunchPlatformName = 'launch_platform_name';
    case LaunchTypeName = 'launch_type_name';
    case NavigationType = 'navigation_type';
}
