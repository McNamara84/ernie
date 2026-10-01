<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Datacenter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keeps imported names bound to one datacenter when its display name changes. */
final class DatacenterNameService
{
    public function find(string $name): ?Datacenter
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $key = self::key($name);
        $id = DB::table('datacenter_name_aliases')->where('name_key', $key)->value('datacenter_id');
        if ($id !== null) {
            return Datacenter::query()->find((int) $id);
        }

        // Direct factory/model creation can predate the first resolver call.
        return Datacenter::query()
            ->where('name', $name)
            ->orWhereRaw('LOWER(name) = ?', [$key])
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    public function idsForNames(array $names): array
    {
        $keysByName = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $keysByName[$name] = self::key($name);
            }
        }
        if ($keysByName === []) {
            return [];
        }

        $idsByKey = DB::table('datacenter_name_aliases')
            ->whereIn('name_key', array_values($keysByName))
            ->pluck('datacenter_id', 'name_key')
            ->all();
        $unresolved = array_values(array_unique(array_filter(
            $keysByName,
            static fn (string $key): bool => ! isset($idsByKey[$key]),
        )));
        if ($unresolved !== []) {
            foreach (Datacenter::query()->whereIn(DB::raw('LOWER(name)'), $unresolved)->orderBy('id')->get(['id', 'name']) as $datacenter) {
                $idsByKey[self::key($datacenter->name)] = $datacenter->id;
            }
        }

        $idsByName = [];
        foreach ($keysByName as $name => $key) {
            if (isset($idsByKey[$key])) {
                $idsByName[$name] = (int) $idsByKey[$key];
            }
        }

        return $idsByName;
    }

    public function findOrCreate(string $name): Datacenter
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'The datacenter name is required.']);
        }

        try {
            return DB::transaction(function () use ($name): Datacenter {
                $datacenter = $this->find($name);
                if ($datacenter === null) {
                    $datacenter = Datacenter::query()->create(['name' => $name]);
                }

                $this->reserve($datacenter, $name);

                return $datacenter;
            });
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->find($name) ?? throw $exception;
        }
    }

    public function create(string $name): Datacenter
    {
        $name = trim($name);

        try {
            return DB::transaction(function () use ($name): Datacenter {
                if ($this->find($name) !== null) {
                    throw ValidationException::withMessages(['name' => 'This datacenter name is already in use.']);
                }

                $datacenter = Datacenter::query()->create(['name' => $name]);
                $this->reserve($datacenter, $name);

                return $datacenter;
            });
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages(['name' => 'This datacenter name is already in use.']);
        }
    }

    public function rename(Datacenter $datacenter, string $name): Datacenter
    {
        $name = trim($name);

        try {
            return DB::transaction(function () use ($datacenter, $name): Datacenter {
                $locked = Datacenter::query()->lockForUpdate()->findOrFail($datacenter->id);
                $other = $this->find($name);
                if ($other !== null && $other->id !== $locked->id) {
                    throw ValidationException::withMessages(['name' => 'This datacenter name is already in use.']);
                }

                $this->reserve($locked, $locked->name);
                $this->reserve($locked, $name);

                if ($locked->name !== $name) {
                    $locked->update(['name' => $name]);
                }

                return $locked;
            });
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages(['name' => 'This datacenter name is already in use.']);
        }
    }

    public function matches(?Datacenter $datacenter, string $name): bool
    {
        return $datacenter !== null && $this->find($name)?->id === $datacenter->id;
    }

    private function reserve(Datacenter $datacenter, string $name): void
    {
        $key = self::key($name);
        $owner = DB::table('datacenter_name_aliases')->where('name_key', $key)->value('datacenter_id');
        if ($owner !== null) {
            if ((int) $owner !== $datacenter->id) {
                throw ValidationException::withMessages(['name' => 'This datacenter name is already in use.']);
            }

            return;
        }

        DB::table('datacenter_name_aliases')->insert([
            'datacenter_id' => $datacenter->id,
            'name' => $name,
            'name_key' => $key,
        ]);
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    private static function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505', '19'], true);
    }
}
