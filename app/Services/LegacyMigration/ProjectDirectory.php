<?php

namespace App\Services\LegacyMigration;

use App\Models\Project;
use InvalidArgumentException;

/**
 * Resolves the "project" reference every data sheet carries to ONE
 * Project, deterministically:
 *
 *   1. a project the Projects sheet resolved or created in this run
 *      (matched by its project name or website as written there)
 *   2. an explicit "projects" mapping entry (legacy key => project id)
 *   3. an existing project whose normalised website URL equals the
 *      reference (the URL is a deterministic identity)
 *
 * Anything else is unknown and reported; names are never fuzzy-matched.
 */
class ProjectDirectory
{
    /**
     * @var array<string, Project> normalised reference => project
     */
    protected array $references = [];

    /**
     * @var array<string, Project>
     */
    protected array $cache = [];

    public function __construct(
        protected LegacyMapping $mapping,
    ) {}

    public function register(Project $project, string ...$references): void
    {
        foreach ($references as $reference) {
            $key = self::normaliseReference($reference);

            if ($key !== '') {
                $this->references[$key] = $project;
            }
        }
    }

    public function forget(Project $project): void
    {
        $this->references = array_filter($this->references, fn (Project $p): bool => $p->getKey() !== $project->getKey());
        $this->cache = array_filter($this->cache, fn (Project $p): bool => $p->getKey() !== $project->getKey());
    }

    public function resolve(string $reference): Project
    {
        $key = self::normaliseReference($reference);

        if ($key === '') {
            throw new InvalidArgumentException('The project reference is blank.');
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $project = $this->references[$key] ?? null;

        if ($project === null && ($id = $this->mapping->projectId($reference)) !== null) {
            $project = Project::query()->find($id)
                ?? throw new InvalidArgumentException("Mapped project id [{$id}] for \"{$reference}\" does not exist.");
        }

        if ($project === null && self::looksLikeUrl($reference)) {
            $matches = Project::query()->get()->filter(fn (Project $p): bool => self::normaliseUrl($p->website_url) === self::normaliseUrl($reference))->values();

            if ($matches->count() > 1) {
                throw new InvalidArgumentException("Several projects share the website \"{$reference}\"; add an explicit \"projects\" mapping entry.");
            }

            $project = $matches->first();
        }

        if ($project === null) {
            throw new InvalidArgumentException("Unknown project reference \"{$reference}\": list it on the Projects sheet or add it to the \"projects\" mapping.");
        }

        return $this->cache[$key] = $project;
    }

    public static function normaliseReference(string $reference): string
    {
        $reference = trim($reference);

        return self::looksLikeUrl($reference) ? self::normaliseUrl($reference) : LegacyMapping::normaliseKey($reference);
    }

    public static function looksLikeUrl(string $value): bool
    {
        return (bool) preg_match('/^(https?:\/\/|www\.)|\.[a-z]{2,}(\/|$)/i', trim($value));
    }

    /**
     * host + path, lower-cased, without scheme, "www." or a trailing slash.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^https?://#i', '', $url) ?? $url;
        $url = preg_replace('#^www\.#i', '', $url) ?? $url;

        return rtrim(mb_strtolower($url), '/');
    }
}
