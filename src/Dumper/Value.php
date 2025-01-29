<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Northrook\Dumper;

use JsonSerializable;
use stdClass;

/**
 * @internal
 */
final class Value implements JsonSerializable
{
    public const string
        TypeArray      = 'array',
        TypeBinaryHtml = 'bin',
        TypeNumber     = 'number',
        TypeObject     = 'object',
        TypeRef        = 'ref',
        TypeResource   = 'resource',
        TypeStringHtml = 'string',
        TypeText       = 'text';

    public const int
        PropertyPublic    = 0,
        PropertyProtected = 1,
        PropertyPrivate   = 2,
        PropertyDynamic   = 3,
        PropertyVirtual   = 4;

    public string $type;

    public string|int|null $value;

    public ?int $length;

    public ?int $depth = null;

    public int|string|null $id = null;

    public object $holder;

    public ?array $items = null;

    public ?stdClass $editor = null;

    public ?bool $collapsed = null;

    public function __construct( string $type, string|int|null $value = null, ?int $length = null )
    {
        $this->type   = $type;
        $this->value  = $value;
        $this->length = $length;
    }

    public function jsonSerialize() : array
    {
        $res = [$this->type => $this->value];

        foreach ( ['length', 'editor', 'items', 'collapsed'] as $k ) {
            if ( null !== $this->{$k} ) {
                $res[$k] = $this->{$k};
            }
        }

        return $res;
    }
}
