<?php

declare(strict_types=1);

namespace Fedale\TemplateFormat\FieldTypes;

enum ReferenceKind: string
{
    case Object = 'object';
    case Field = 'field';
}
