<?php

declare(strict_types=1);

namespace PumlSplitter\Puml\Model;

/**
 * The kind of a PlantUML type declaration.
 *
 * The backing value is the exact keyword used in the source, so it can be
 * re-emitted byte-identically by the Writer.
 */
enum ClassKind: string
{
    case Clazz = 'class';
    case AbstractClass = 'abstract class';
    case Interface_ = 'interface';
    case Enum = 'enum';

    public static function fromKeyword(string $keyword): self
    {
        return self::from($keyword);
    }
}
