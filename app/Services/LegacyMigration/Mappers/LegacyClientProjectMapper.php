<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Projects\CreateMigratedProjectAction;
use App\Enums\ClientStatus;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\LegacyMigration\ProjectDirectory;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use InvalidArgumentException;

/**
 * "Projects" sheet: one row per SEO engagement (website) under its legacy
 * client/account. Enforces the corrected hierarchy: several rows may share
 * one legacy client, and a client is never treated as a website.
 *
 * Client identity:  ledger → explicit "clients" mapping → CREATE.
 *                   An existing client with the same name but no mapping
 *                   is a CONFLICT (never merged on a name alone).
 * Project identity: ledger → explicit "projects" mapping → normalised
 *                   website URL within the resolved client → CREATE via
 *                   CreateMigratedProjectAction (no current cycle, no onboarding tasks). The same URL under another client
 *                   is a CONFLICT. Existing records are reused, never
 *                   overwritten.
 */
class LegacyClientProjectMapper extends LegacySheetMapper
{
    public function __construct(
        protected CreateMigratedProjectAction $createProject,
    ) {}

    public function sheet(): string
    {
        return 'Projects';
    }

    public function fields(): array
    {
        return [
            'legacy_client' => ['aliases' => ['client', 'account', 'legacy account', 'client key'], 'required' => true],
            'client_name' => ['aliases' => ['client display name', 'company']],
            'project_name' => ['aliases' => ['project', 'engagement', 'site name'], 'required' => true],
            'website_url' => ['aliases' => ['website', 'url', 'domain'], 'required' => true],
            'target_location' => ['aliases' => ['location', 'target market']],
            'owner' => ['aliases' => ['primary seo', 'seo owner', 'owner email', 'assigned to']],
            'team' => ['aliases' => ['team members', 'members']],
            'package' => ['aliases' => ['plan', 'package label']],
            'start_date' => ['aliases' => ['started', 'start']],
            'status' => ['aliases' => ['project status']],
            'notes' => ['aliases' => ['note', 'comments']],
        ];
    }

    public function groupField(): string
    {
        return 'legacy_client';
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        $client = null;
        $clientResolved = false;

        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'legacy_client', 'project_name', 'website_url');

                if (! $clientResolved) {
                    $client = $this->resolveClient($context, $sheet, $rowNumber, $row);
                    $clientResolved = true;
                }

                if ($client === null) {
                    $context->tally('projects', MigrationOutcome::CONFLICT);

                    continue;
                }

                $project = $this->resolveProject($context, $sheet, $rowNumber, $row, $client);

                if ($project !== null) {
                    $context->projects->register($project, $this->value($row, 'project_name'), $this->value($row, 'website_url'));
                }
            } catch (InvalidArgumentException $exception) {
                $context->tally('projects', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'project', $exception->getMessage(), $row);
            }
        }
    }

    protected function resolveClient(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row): ?Client
    {
        $legacyKey = $this->value($row, 'legacy_client');
        $name = $this->optional($row, 'client_name') ?? $legacyKey;
        $fingerprint = $context->ledger::fingerprint(['client', $legacyKey]);

        if (($record = $context->ledger->find($sheet->name, $fingerprint)) !== null) {
            $client = Client::query()->find($record->entity_id);

            if ($client !== null) {
                $context->tally('clients', MigrationOutcome::SKIP);

                return $client;
            }
        }

        if (($id = $context->mapping->clientId($legacyKey)) !== null) {
            $client = Client::query()->find($id)
                ?? throw new InvalidArgumentException("Mapped client id [{$id}] for \"{$legacyKey}\" does not exist.");

            $context->tally('clients', MigrationOutcome::SKIP);
            $context->info($sheet->name, $rowNumber, 'client', sprintf('Legacy client "%s" reused as existing client "%s" [%d] (explicit mapping).', $legacyKey, $client->name, $client->getKey()));
            $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'client', (int) $client->getKey());

            return $client;
        }

        $sameName = Client::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($sameName !== null) {
            $context->tally('clients', MigrationOutcome::CONFLICT);
            $context->error($sheet->name, $rowNumber, 'client', sprintf('A client named "%s" already exists [%d]; add "%s" to the "clients" mapping to reuse it (clients are never merged on a name alone). Its projects were not migrated.', $sameName->name, $sameName->getKey(), $legacyKey), $row);

            return null;
        }

        $client = Client::query()->create([
            'name' => $name,
            'status' => ClientStatus::Active,
            'notes' => "Migrated from legacy workbook (account \"{$legacyKey}\").",
        ]);

        $context->tally('clients', MigrationOutcome::CREATE);
        $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'client', (int) $client->getKey());

        return $client;
    }

    protected function resolveProject(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row, Client $client): ?Project
    {
        $name = $this->value($row, 'project_name');
        $url = $this->value($row, 'website_url');
        $fingerprint = $context->ledger::fingerprint(['project', $this->value($row, 'legacy_client'), ProjectDirectory::normaliseUrl($url)]);

        if (($record = $context->ledger->find($sheet->name, $fingerprint)) !== null && ($project = Project::withTrashed()->find($record->entity_id)) !== null) {
            $context->tally('projects', MigrationOutcome::SKIP);

            return $project;
        }

        foreach ([$name, $url] as $reference) {
            if (($id = $context->mapping->projectId($reference)) !== null) {
                $project = Project::withTrashed()->find($id)
                    ?? throw new InvalidArgumentException("Mapped project id [{$id}] for \"{$reference}\" does not exist.");

                $context->tally('projects', MigrationOutcome::SKIP);
                $context->info($sheet->name, $rowNumber, 'project', sprintf('Legacy project "%s" reused as existing project "%s" [%d] (explicit mapping).', $name, $project->name, $project->getKey()));
                $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'project', (int) $project->getKey());

                return $project;
            }
        }

        $sameUrl = Project::withTrashed()->get()->filter(fn (Project $p): bool => ProjectDirectory::normaliseUrl($p->website_url) === ProjectDirectory::normaliseUrl($url))->values();

        if ($sameUrl->count() > 1) {
            $context->tally('projects', MigrationOutcome::CONFLICT);
            $context->error($sheet->name, $rowNumber, 'project', sprintf('Several projects already use the website "%s"; add an explicit "projects" mapping entry.', $url), $row);

            return null;
        }

        if (($existing = $sameUrl->first()) !== null) {
            if ((int) $existing->client_id !== (int) $client->getKey()) {
                $context->tally('projects', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'project', sprintf('Website "%s" already belongs to project "%s" [%d] under client "%s", not "%s"; resolve with an explicit mapping.', $url, $existing->name, $existing->getKey(), $existing->client?->name, $client->name), $row);

                return null;
            }

            $context->tally('projects', MigrationOutcome::SKIP);
            $context->info($sheet->name, $rowNumber, 'project', sprintf('Website "%s" matched existing project "%s" [%d]; reused, attributes left unchanged.', $url, $existing->name, $existing->getKey()));
            $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'project', (int) $existing->getKey());

            return $existing;
        }

        $project = $this->createProject->handle(
            $this->attributes($context, $sheet, $rowNumber, $row, $client),
            $this->teamIds($context, $sheet, $rowNumber, $row),
        );

        $context->tally('projects', MigrationOutcome::CREATE);
        $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'project', (int) $project->getKey());

        return $project;
    }

    protected function attributes(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row, Client $client): array
    {
        $status = $this->blank($row, 'status') ? ProjectStatus::Active : $context->values->enum('project_status', $this->value($row, 'status'), ProjectStatus::class);

        $owner = null;

        if (! $this->blank($row, 'owner')) {
            $owner = $context->values->user($this->value($row, 'owner'));

            if ($owner === null) {
                $context->warning($sheet->name, $rowNumber, 'project', sprintf('Owner "%s" does not resolve to a user (use an email or a "users" mapping entry); project created without a primary SEO owner.', $this->value($row, 'owner')));
            }
        }

        $packageId = null;

        if (! $this->blank($row, 'package')) {
            $label = $this->value($row, 'package');
            $packageId = $context->mapping->packageId($label) ?? Package::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($label)])->value('id');

            if ($packageId === null) {
                $context->warning($sheet->name, $rowNumber, 'project', sprintf('Package "%s" does not match any package (add a "packages" mapping entry); project created without a package.', $label));
            }
        }

        return [
            'client_id' => $client->getKey(),
            'name' => $this->value($row, 'project_name'),
            'website_url' => $this->value($row, 'website_url'),
            'target_location' => $this->optional($row, 'target_location'),
            'status' => $status,
            'start_date' => $this->blank($row, 'start_date') ? null : $context->values->date($this->value($row, 'start_date')),
            'primary_seo_user_id' => $owner?->getKey(),
            'package_id' => $packageId,
            'notes' => $this->optional($row, 'notes'),
        ];
    }

    /**
     * @return list<int>
     */
    protected function teamIds(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row): array
    {
        $ids = [];

        foreach (array_filter(array_map('trim', explode(',', $this->value($row, 'team')))) as $member) {
            $user = $context->values->user($member);

            if ($user instanceof User) {
                $ids[] = (int) $user->getKey();
            } else {
                $context->warning($sheet->name, $rowNumber, 'project', sprintf('Team member "%s" does not resolve to a user; not added to the team.', $member));
            }
        }

        return array_values(array_unique($ids));
    }
}
