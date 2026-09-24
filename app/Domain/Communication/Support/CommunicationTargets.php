<?php

namespace App\Domain\Communication\Support;

use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Audience targeting for Notices and Circulars.
 *
 * `target_type` is an extensible string column, not a database enum. Two kinds
 * of target exist:
 *
 *  - audience-wide targets (all / students / staff) that need no record;
 *  - entity targets that point at an EXISTING Platform master through
 *    `target_id` (departments, programs, sections). No master is duplicated —
 *    the Communication module only references them.
 *
 * Adding a new target is a one-line change to AUDIENCES or ENTITIES. Every
 * entity lookup is tenant-safe: it filters on the explicit college id (never
 * trusting the ambient scope alone) and ignores archived (soft-deleted) rows.
 */
final class CommunicationTargets
{
    public const ALL = 'all';

    public const STUDENTS = 'students';

    public const STAFF = 'staff';

    public const DEPARTMENT = 'department';

    public const PROGRAM = 'program';

    public const SECTION = 'section';

    /** @var array<string, string> */
    public const AUDIENCES = [
        self::ALL => 'Everyone',
        self::STUDENTS => 'All students',
        self::STAFF => 'All staff',
    ];

    /**
     * Entity targets reuse the existing Platform masters.
     *
     * @var array<string, array{label: string, model: class-string<Model>}>
     */
    public const ENTITIES = [
        self::DEPARTMENT => ['label' => 'Department', 'model' => Department::class],
        self::PROGRAM => ['label' => 'Program', 'model' => Program::class],
        self::SECTION => ['label' => 'Section / Batch', 'model' => Section::class],
    ];

    /**
     * Target types offered for notices (audiences + entity targets).
     *
     * @return array<string, string>
     */
    public static function forNotices(): array
    {
        $types = self::AUDIENCES;

        foreach (self::ENTITIES as $type => $definition) {
            $types[$type] = $definition['label'];
        }

        return $types;
    }

    /**
     * Target types offered for circulars (audience-wide only: a circular has
     * no target_id column).
     *
     * @return array<string, string>
     */
    public static function forCirculars(): array
    {
        return self::AUDIENCES;
    }

    public static function requiresEntity(mixed $type): bool
    {
        return is_string($type) && array_key_exists($type, self::ENTITIES);
    }

    public static function label(?string $type): string
    {
        return self::forNotices()[$type] ?? ($type ? ucfirst(str_replace('_', ' ', $type)) : '—');
    }

    /**
     * Tenant-safe lookup of an entity target in the given college.
     */
    public static function findEntity(string $type, int $id, int $collegeId): ?Model
    {
        if (! self::requiresEntity($type) || $id <= 0 || $collegeId <= 0) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = self::ENTITIES[$type]['model'];

        return $model::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->whereKey($id)
            ->first();
    }

    /** Human label of one entity record, e.g. "Computer Science (CSE)". */
    public static function entityLabel(?Model $entity): string
    {
        if (! $entity) {
            return 'Unavailable record';
        }

        $name = (string) ($entity->getAttribute('name') ?? '');
        $code = (string) ($entity->getAttribute('code') ?? '');

        return trim($name.($code !== '' ? ' ('.$code.')' : '')) ?: '#'.$entity->getKey();
    }

    /**
     * Human label for a record's target, e.g. "Program: B.Sc Physics (BSC-P)".
     */
    public static function describe(?string $type, ?int $targetId, int $collegeId): string
    {
        if (! self::requiresEntity($type)) {
            return self::label($type);
        }

        $entity = $targetId ? self::findEntity((string) $type, $targetId, $collegeId) : null;

        return self::ENTITIES[$type]['label'].': '.self::entityLabel($entity);
    }

    /**
     * Options for the target record picker, grouped by entity type. Uses the
     * tenant-scoped models, so only the active college's records appear.
     *
     * @return array<string, Collection<int, Model>>
     */
    public static function entityOptions(): array
    {
        $options = [];

        foreach (self::ENTITIES as $type => $definition) {
            /** @var class-string<Model> $model */
            $model = $definition['model'];
            $options[$type] = $model::query()->orderBy('name')->orderBy('id')->get(['id', 'college_id', 'name', 'code']);
        }

        return $options;
    }
}
